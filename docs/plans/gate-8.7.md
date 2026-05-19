# Gate 8.7 - bulk_entries + scaffold_entries Pro tools (first streaming Pro tools)

Internal sub-plan for the builder. Parent: `docs/plans/gate-8.md` section 8.7 (lines 301-351). Scope: ship two Pro mutation tools wired through the Gate-7.7 streaming dispatcher - a query-driven 4-mode `bulk_entries` and a separate template-driven `scaffold_entries`. Both implement `StreamableToolInterface`, share `ProToolTrait + PermissionedToolTrait + IdempotencyTrait + AbstractTool`, and gracefully collapse to JSON when the client doesn't `Accept: text/event-stream`.

Pre-8.7 housekeeping (lock-in 1) shipped separately at commit `200a7e1`. Baseline at HEAD: 777 passing / 1 skipped / 9901 assertions, PHPStan + ECS clean.

Source files consulted (file:line):

- `src/mcp/transport/SseEmitter.php:209-235` - `emit()` writes via `echo` + `flush()`; no socket health check.
- `src/mcp/transport/SseEmitter.php:183-189` - `disableBuffering()` walks `ob_end_flush()`, then `flush()`. No `ignore_user_abort` call.
- `src/mcp/Server.php:402-472` - `dispatchStreaming()` generator; only cancellation surface is the cache-slot poll-callback.
- `src/mcp/Server.php:812-896` - `_streamToolCall()` while-loop: `isCancelled()` between yields.
- `src/mcp/Server.php:913-934` - `_invocationContextWithCancellation()` wires the cache-slot poll-callback. The callback reads `cortex:cancel:{sessionId}:{requestId}`.
- `src/mcp/Server.php:951-982` - `_recordCancellation()` only fires from a separate inbound `notifications/cancelled` POST. There is no other writer.
- `src/controllers/McpController.php:657-700` - `_streamPost()` loops `foreach ($server->dispatchStreaming(...) as $envelope) { $emitter->emit(...) }`. No `connection_aborted()` check between iterations.
- `src/tools/support/CancellationToken.php:97-107` - `isCancelled()` consults the poll-callback; truthy return flips the token permanently.
- `src/tools/support/InvocationContext.php:117-128` - context constructor; cancellation token defaults to a fresh, unfired one.
- `src/tools/StreamableToolInterface.php:71-110` - contract: yield progress frames, `return` the terminal payload; cancellation cooperative.
- `src/tools/dev/StreamingFixtureTool.php:119-135` - reference streaming tool shape (between-yield token check).
- `src/tools/content/Entry.php:315-332` - mode-dispatch via `match`.
- `src/tools/content/Entry.php:353-375` - `_requiredPermissions()` returns `saveEntries:{sectionUid}` / `deleteEntries:{sectionUid}` or the wildcard sentinel when the section can't be resolved.
- `src/tools/IdempotencyTrait.php:83-101, 114-125, 145-161` - whole-envelope cache, per-user keyed.
- `src/tools/PermissionedToolTrait.php` (whole) - `filterFor()`, `_assertPermission()` contracts.
- `src/tools/ProToolTrait.php` (whole) - edition gating.
- `src/tools/dev/Resave.php:126-150` - reference output shape from a bulk tool.
- `src/tools/support/InvocationLogger.php:194-275` - audit-log line + DB-row shape; `response_excerpt` is a single per-invocation column.
- `vendor/craftcms/cms/src/elements/db/ElementQuery.php:1861-1878` - `count()` is `SELECT COUNT(*)` (no hydration); safe pre-flight.
- `vendor/yiisoft/yii2/db/Query.php:198-235` - `batch($size)` / `each($size)` default `batchSize = 100`; both return `BatchQueryResult`.
- `vendor/yiisoft/yii2/db/BatchQueryResult.php:76, 121-159, 217-228` - holds an open DataReader across iterations; cursor is suspend-safe inside a generator.
- `vendor/craftcms/cms/src/elements/db/EntryQuery.php:324, 391, 432, 484, 523, 825` - `section()`, `sectionId()`, `type()`, `typeId()`, `authorId()`, `status()`. `search`, `dateCreated`, `dateUpdated`, `relatedTo`, `id` come from `ElementQuery` base.
- `vendor/craftcms/cms/src/services/UserPermissions.php:562, 602, 608, 614` - permission strings: `saveEntries:{uid}`, `deleteEntries:{uid}`, `deleteEntriesForSite:{uid}`.
- `vendor/craftcms/cms/src/services/Elements.php:1334, 4754, 4863, 4885` - `saveElement()`, `canSave()`, `canDelete()`, `canDeleteForSite()`.
- `vendor/craftcms/cms/src/console/controllers/ResaveController.php:584-722` - Craft's own bulk iteration pattern (`->all()` then loop save + `Console::startProgress`).
- `vendor/craftcms/cms/src/web/Response.php:292-303` - `ignore_user_abort(true)` set ONLY in `sendAndClose()`. The SSE streaming path bypasses this entirely.
- `tests/Mcp/StreamingTest.php:162-239` - existing cancellation tests cover the synthetic `notifications/cancelled` cache-slot path only; zero coverage of real TCP-disconnect.
- PHP manual: connection_aborted() requires an attempted write to detect disconnect; absent `ignore_user_abort(true)` PHP terminates the script when the next write hits a closed socket (https://www.php.net/manual/en/function.connection-aborted.php).

---

## CancellationToken HTTP SSE disconnect verification (PASS, lock-in 9)

**Verdict: PASS post-Gate-7.7.5 (commit `0021932`).** Real HTTP TCP-disconnect now propagates through `CancellationToken::isCancelled()` end-to-end. The streaming controller polls `connection_aborted()` after each emit and flips the in-flight token directly; the audit row writes cleanly; the terminal `_cancelledEnvelope()` is emitted best-effort.

Code path: `McpController::_streamPost()` at `src/controllers/McpController.php:673-742` (sets `ignore_user_abort(true)` at the top, then polls `connection_aborted()` post-emit and calls `$server->getInFlightCancellationToken()?->cancel('client disconnected')`); `Server::getInFlightCancellationToken()` at `src/mcp/Server.php:348-351` (with the slot populated in `_streamToolCall()` at `:871` and cleared in a `finally` at `:917`); `CancellationToken::cancel()` at `src/tools/support/CancellationToken.php:145-152` (idempotent, first-reason-wins, also exposes `getReason()` at `:167-170`).

The controller's drain-don't-break loop (`$clientGone` flag at `_streamPost():698-718`) keeps consuming the dispatcher generator after the disconnect so `_streamToolCall()`'s cancellation body runs to completion — the `kind=cancelled` audit row and terminal envelope are written cleanly. Abandoning the generator would orphan the audit row.

---

## Locked decisions

### Carried from gate-8.md lock-ins (resolved 2026-05-15)

1. **Two tools, not one.** `bulk_entries` (modes: `set_status / update_fields / relate / migrate`) and `scaffold_entries` (separate template-driven create-many tool). Shared trait stack, shared streaming infrastructure, shared idempotency contract. Per lock-in 2.
2. **`migrate` mode defaults to dry-run.** Explicit `dryRun: false` required to mutate. Two-call workflow (preview -> confirm). Per lock-in 3.
3. **Whole-operation idempotency.** Cache key `cortex:bulk_entries:idem:{userId}:{key}` and `cortex:scaffold_entries:idem:{userId}:{key}`, 24h TTL. Same query + same payload + same key returns the cached envelope without re-running. Documented caveat: stale result if the underlying data changed between calls. Per lock-in 4.
4. **Hard row cap 10 000 per call; `force: true` escape.** Beyond cap without force, `ToolException` naming the cap. Per lock-in 5.
5. **`onPermissionDenied` arg, default `'skip'`.** Optional `'fail'` aborts on first denied row with `-32002`. Per lock-in 6.
6. **`progressInterval` arg, default 100.** Yield one progress frame every N rows (success + skipped + failure all count). Per lock-in 7.
7. **Fixture extension via a marvel-seed companion migration.** ~150 "minor characters" hero entries seeded by a new playground migration `m260518_000000_marvel_minor_heroes` BEFORE 8.7 tests are written. Per lock-in 8.
8. **CancellationToken HTTP verification: PASS (Gate 7.7.5, commit `0021932`).** Both surfaces work — synthetic `notifications/cancelled` and real TCP-disconnect. Per lock-in 9.

### Open technical questions - resolved against source

9. **Per-row output envelope shape (Q B).** Pin the canonical envelope to:
    ```
    {
      kind: "success" | "failure" | "skipped",
      id: int,
      // success-only:
      newStatus?: string,    // set_status mode
      fieldsUpdated?: [string, ...],  // update_fields mode
      relationsAdded?: int,  // relate mode
      newEntryTypeUid?: string,  // migrate mode (committed only)
      // failure-only:
      reason: string,
      validationErrors?: {[handle: string]: [string, ...]},
      // skipped-only:
      reason: "permission_denied" | "trashed" | "missing" | "stale",
      requiredPermission?: string,
    }
    ```
    `id` is required on every kind so callers can correlate. `failure` is mutation-attempted-but-rejected (validation, save failed). `skipped` is mutation-not-attempted (permission, state). Mirrors the audit-log `kind` enum at `src/tools/support/InvocationLogger.php:194` (`success` / `tool_error` / `internal_error` / `rate_limited`) but tool-row-level rather than invocation-level - intentional shape divergence because the per-row outcome model is broader than the per-invocation one.

10. **Pre-flight `total` cost (Q C).** `ElementQuery::count()` resolves to `SELECT COUNT(*)` from the assembled query (`vendor/craftcms/cms/src/elements/db/ElementQuery.php:1861-1878` delegates to `parent::count()` which is `yii\db\Query::count` - pure aggregate, no element hydration). For a 10k-row channel on a playground DB the cost is sub-millisecond. **Decision: pre-flight `count()` runs once at stream start, seeds the `total` field in every progress frame.** No alternative needed.

11. **Chunked iteration (Q D).** `ElementQuery::each($batchSize = 100)` (default at `vendor/yiisoft/yii2/db/Query.php:226-235`) returns a `BatchQueryResult` holding an open DataReader (`vendor/yiisoft/yii2/db/BatchQueryResult.php:76`). The reader is consumed lazily one row at a time across `next()` / `valid()` (`:121-159`, `:228`). PHP generators suspend the entire stack frame including the open resource - yielding from inside the foreach is safe; the cursor stays open across the suspend. **Decision: tools use `Entry::find()->...->each(100)` and yield progress frames inside the loop.** Batch size 100 matches Yii's default; smaller for `progressInterval = 1` LLM debug workflows is unnecessary because `each()` returns one row at a time regardless.

12. **Query input extensions (Q E).** EntryQuery surface verified: `section`, `sectionId`, `type`, `typeId`, `authorId`, `status`, `postDate` (`vendor/craftcms/cms/src/elements/db/EntryQuery.php:663`), `expiryDate` (`:789`) are first-class EntryQuery methods. `search`, `dateCreated`, `dateUpdated`, `relatedTo`, `id` come from `ElementQuery` base. **Minimum surface for 8.7:**
    - `section: string` (handle) - resolved via `getEntries()->getSectionByHandle()`.
    - `entryType: string` (handle) - resolved via `getEntries()->getEntryTypeByHandle()`.
    - `status: string|string[]` - pass-through to `status()`.
    - `search: string` - pass-through to `search()`.
    - `ids: int[]` - pass-through to `id()`.
    - `authorId: int` - pass-through to `authorId()`.
    - `dateCreated: {gte?: string, lte?: string}` - parsed via `DateTimeHelper::toDateTime()` then `Db::parseDateParam()`.
    - `dateUpdated: {gte?: string, lte?: string}` - same.
    - `postDate: {gte?: string, lte?: string}` - parsed via `DateTimeHelper::toDateTime()` then `Db::parseDateParam()`. EntryQuery surface (`EntryQuery.php:663`).
    - `expiryDate: {gte?: string, lte?: string}` - same. EntryQuery surface (`EntryQuery.php:789`).
    `relatedTo` is intentionally out of scope - the surface is messy enough that exposing it to the LLM creates more confusion than it resolves. Custom-field queries are out of scope per the locked spec.

13. **Audit log granularity (Q F).** One `cortex_invocations` row per invocation. `response_excerpt` carries the JSON:
    ```
    {
      "mode": "set_status",
      "total": 247,
      "processed": 245,
      "succeeded": 240,
      "failed": 5,
      "skipped": 2,
      "cancelled": false,
      "dryRun": false,
      "force": false,
      "durationMs": 4823
    }
    ```
    Per-row outcomes are NOT logged to `cortex_invocations` - they ride in the tool's response envelope only. Aggregate counts in `response_excerpt` give the auditor the forensic shape they need; the full per-row breakdown is recoverable from the response payload the operator already captured. Excerpt truncation at `_excerptResponse()` (`src/tools/support/InvocationLogger.php:208-210`) handles overflow. Mirrors existing per-tool excerpt shape - no new column, no new table. Per lock-in's intent (audit row records the invocation, not the rows).

14. **Streaming yield shape with errors (Q G).** Yield-frame envelope is fixed-shape `{progress: int, total: int, message: string}` per MCP spec and `src/tools/StreamableToolInterface.php:38-42`. Per-row outcomes do NOT ride in the progress frame's `message` field - the message is a human-readable status (`"Processing entry 12345"`, `"Skipped entry 12346 (no permission)"`). All structured per-row outcomes (success / failure / skipped per Q9) ride in the `results: [...]` array on the terminal `return` value. This matches the StreamingFixtureTool reference shape (`src/tools/dev/StreamingFixtureTool.php:127`). Locked for both `bulk_entries` and `scaffold_entries`.

### Cross-cutting decisions

15. **Permission registration.** No new permission introduced - per-section `saveEntries:{uid}` / `deleteEntries:{uid}` from Craft core (`vendor/craftcms/cms/src/services/UserPermissions.php:562, 614`) is the gate. `filterFor()` walks the caller's section permissions; bulk tools are visible to any user with at least one `saveEntries:*` (bulk_entries) or section save permission (scaffold_entries). `_requiredPermissions()` per row resolves the section UID and asserts the per-section permission; mismatches go to the `skipped` list per Q9.
16. **No new `manageBulkOperations` permission.** Rejected: every authoring user already has section permissions; an extra "bulk" gate is administrative theatre. The 10 000-row cap + dry-run-default-on-migrate are the bulk-specific safety belts.
17. **Site context.** `bulk_entries` accepts an optional `siteId` / `site` argument. Default: current primary site, OR `'*'` when the operation is intended to span all sites. Resolved via `AbstractTool::_resolveSiteId()` (mirror Address). `scaffold_entries` defaults to primary site for created entries unless `site: '*'` is passed.
18. **Element fetch shape.** Tools resolve elements via `Entry::find()->id($rowId)->status(null)->site($siteId)->one()` inside the each-loop body so per-row state is current at mutation time (an entry deleted between `count()` and the loop hitting it gets a `skipped: missing` outcome rather than crashing). This is the same defensive pattern the Address tool uses.
19. **Transaction shape.** No outer transaction around the each-loop. Per-row failures persist - the response envelope is the source of truth for what got mutated, the audit log carries the aggregate counts. This is what locked decision 4 in gate-8.md's risks section ("Without per-batch transactions, partial-failure recovery is on the caller") refers to. Documented in tool PHPDocs.
20. **`migrate` mode dry-run output shape.** When `dryRun: true` (default), each row's outcome is `{kind: "success", id, newEntryTypeUid, dryRun: true}` and `_assertCanSave()` is invoked (so permission filtering still happens) but `Craft::$app->getElements()->saveElement()` is NOT called. The response envelope's top-level carries `dryRun: true` and `committed: 0`. Operator confirms by re-running with `dryRun: false`. The idempotency cache scopes BOTH `dryRun: true` and `dryRun: false` separately - same key + dry-run-true returns cached preview; same key + dry-run-false runs fresh. Idempotency key documented as scoped-per-dry-run-state in the schema description.

---

## File map

**New:**
- `src/tools/content/BulkEntries.php` - 4-mode streaming Pro tool. Query-resolver logic inlined as private methods (see Phase 1 below). ~900 LoC (with PHPDoc + section headers).
- `src/tools/content/ScaffoldEntries.php` - template-driven create-many streaming Pro tool. ~500 LoC.
- `tests/Tools/Content/BulkEntriesTest.php` - the suite. ~25 cases.
- `tests/Tools/Content/ScaffoldEntriesTest.php` - the suite. ~15 cases.
- Playground: `~/dev/craft-plugin-playground/cms_v5/cms/migrations/m260518_000000_marvel_minor_heroes.php` - seeds ~150 throwaway hero entries with deterministic title pattern `Minor Hero {1..150}` and varying sections/authors/dates. Idempotent (checks count before seeding).

**Modified:**
- `src/services/Tools.php` - register both new tools in the Pro-write block (after the `Users` registration at ~line 332). Add `use` imports.
- `tests/Architecture/EditionGatingTest.php` - append `BulkEntries::class` and `ScaffoldEntries::class` to `_cortex_pro_tool_classes()`. Add `use` imports. The existing five invariants then auto-apply.

**Untouched (verify, do not modify):**
- `src/tools/ProToolTrait.php`, `PermissionedToolTrait.php`, `IdempotencyTrait.php`, `AbstractTool.php` - consumed as-is.
- `src/tools/StreamableToolInterface.php` - consumed as-is.
- `src/tools/support/CancellationToken.php` - consumed as-is (Gate 7.7.5 already added `cancel()` / `getReason()`; 8.7 reads the existing surface).
- `src/mcp/Server.php`, `src/mcp/transport/SseEmitter.php`, `src/mcp/transport/Http.php`, `src/controllers/McpController.php` - consumed as-is.

---

## Tool spec - `bulk_entries`

### Modes

| Mode | Required args | Optional args | Permission (per-row) |
|---|---|---|---|
| `set_status` | mode, query, status | siteId, force, onPermissionDenied, progressInterval, idempotencyKey | `saveEntries:{sectionUid}` |
| `update_fields` | mode, query, fields | siteId, force, onPermissionDenied, progressInterval, idempotencyKey | `saveEntries:{sectionUid}` |
| `relate` | mode, query, targetField, targetIds | siteId, mergeStrategy ("replace"\|"add"\|"remove"), force, onPermissionDenied, progressInterval, idempotencyKey | `saveEntries:{sectionUid}` |
| `migrate` | mode, query, toSectionUid, toEntryTypeUid | siteId, dryRun (default true), force, onPermissionDenied, progressInterval, idempotencyKey | `saveEntries:{sourceSectionUid}` AND `saveEntries:{toSectionUid}` |

### Input schema (top-level)

| Property | Type | Required | Description |
|---|---|---|---|
| mode | string enum (set_status, update_fields, relate, migrate) | yes | Operation. |
| query | object | yes | `{section?, entryType?, status?, search?, ids?, authorId?, dateCreated?, dateUpdated?, postDate?, expiryDate?}` per Q12. |
| siteId | int\|"*" | no | Site context. Default primary. |
| status | string enum (enabled, disabled) | set_status only | Direct toggle of `$entry->enabled`. `live` / `pending` / `expired` are computed from `enabled + postDate + expiryDate`; drive those via `update_fields`. |
| fields | object | update_fields only | `{handle: value}`. |
| targetField | string | relate only | Field handle (relation field). |
| targetIds | int[] | relate only | Target element IDs. |
| mergeStrategy | string enum (replace, add, remove) | no | Default `replace`. relate only. |
| toSectionUid | string | migrate only | Target section UID. |
| toEntryTypeUid | string | migrate only | Target entry type UID. |
| dryRun | bool | no | migrate only. Default `true`. |
| force | bool | no | Override the 10 000 cap. Default `false`. |
| onPermissionDenied | string enum (skip, fail) | no | Default `skip`. |
| progressInterval | int >= 1 | no | Default 100. |
| idempotencyKey | string <= 64 | no | Whole-operation cache. |

### Output envelope (terminal `return` from `stream()`, also `execute()` return)

```
{
  success: true | false,
  mode: "set_status" | "update_fields" | "relate" | "migrate",
  dryRun: bool,
  total: int,            // pre-flight count
  processed: int,        // rows visited
  succeeded: int,
  failed: int,
  skipped: int,
  cancelled: bool,       // true when CancellationToken fired mid-stream
  results: [             // per-row outcomes per Q9
    {kind, id, ...}
  ],
  // failure-only top-level:
  errors?: object        // top-level validation (cap exceeded, missing section, etc.)
}
```

### Progress frame (yielded per `progressInterval` rows)

```
{progress: int, total: int, message: string}
```

`message` is `"Processing entry {id}"` for the latest row visited, or `"Skipped entry {id} (no permission)"` for the latest skip.

### Permission gating

- `filterFor(?User $user)`: stdio -> `true`. Caller-bound: walks `$user->can('saveEntries:*')` short-circuit; falls back to "any section has a save permission" check. Strict version of Entry's filterFor (mirror `src/tools/content/Entry.php:_filterFor` block).
- `_requiredPermissions(array $arguments)`: returns `["saveEntries:{sectionUid}"]` when the query resolves to exactly one section UID; returns `["saveEntries:*"]` wildcard sentinel otherwise (the per-row check inside the loop is the real gate - `filterFor()` only needs to coarse-gate at registration time).
- Inside the loop: `Craft::$app->getElements()->canSave($entry, $caller)` per row. Mismatch behaviour driven by `onPermissionDenied`.

### Idempotency

- Cache prefix constant: `IDEMPOTENCY_CACHE_PREFIX = 'cortex:bulk_entries:idem:'`.
- Pre-execute lookup at the top of `execute()` AND `stream()`. Hit -> early-return cached envelope (`stream()` yields one synthetic terminal frame). Comment cites the deliberate permission-check bypass per gate-8.md lock-in 1's housekeeping rule.
- Post-execute cache write only on terminal success path AND when not cancelled AND when `dryRun === false` AND when no terminal validation error. Dry-run results NOT cached because the operator's intent is to re-run with `dryRun: false` next.

---

## Tool spec - `scaffold_entries`

### Modes

Single-mode tool (`mode` not required, default semantically is `"create"`). Optionally accepts `mode: "create"` for symmetry.

### Input schema

| Property | Type | Required | Description |
|---|---|---|---|
| sectionUid | string | yes | Target section UID. |
| entryTypeUid | string | yes | Target entry type UID. |
| count | int 1..10000 | yes | Number of entries to create. |
| template | object | yes | `{title: string|template, slug?: string|template, status?: string, fields?: object, authorId?: int}`. |
| siteId | int\|"*" | no | Default primary. |
| force | bool | no | Override the 10 000 cap. |
| progressInterval | int >= 1 | no | Default 100. |
| idempotencyKey | string <= 64 | no | Whole-operation cache. |

### Template substitution

`title` and `slug` in `template` accept the special tokens `{n}` (1-indexed row counter) and `{n:04d}` (zero-padded, configurable width). No Twig - the tokens are simple regex substitution to keep the surface auditable and the wire shape predictable. Example: `template.title = "Imported Hero {n:04d}"` -> `"Imported Hero 0001"`, `"Imported Hero 0002"`, etc.

### Output envelope

```
{
  success: true | false,
  mode: "create",
  total: int,            // requested count
  processed: int,
  succeeded: int,
  failed: int,
  skipped: int,          // always 0 for scaffold - no per-row skips here
  cancelled: bool,
  results: [{kind: "success"|"failure", id?, reason?, validationErrors?}, ...],
  errors?: object
}
```

### Permission gating

- `filterFor()`: like bulk_entries.
- `_requiredPermissions()`: `["saveEntries:{sectionUid}"]` when section UID is in the arguments, wildcard otherwise.
- Inside the loop: `Craft::$app->getElements()->canSave($entry, $caller)` per row.

### Idempotency

- Cache prefix `cortex:scaffold_entries:idem:`.
- Same key + same `count` + same `template.title` template + same section/entryType returns the cached envelope. Documented caveat: idempotency on scaffold is brittle - if you change the template between calls with the same key, you get the OLD envelope. The LLM should generate a fresh key per intent.

---

## Implementation phases (layered build-verify)

### Phase 0 - playground seed (BEFORE everything else)

- **Write** `m260518_000000_marvel_minor_heroes` migration in the playground. ~150 entries spread across 3 sections, with authors rotated across 5 users, `dateCreated` jittered across 90 days. Idempotent.
- **Verify**: `ddev craft migrate/up` in the playground; `ddev craft entries --section minorHeroes` shows ~150 entries.

### Phase 1 - BulkEntries: skeleton + registration + inline query resolver

- **Write** `src/tools/content/BulkEntries.php` with `shouldRegister()`, `filterFor()`, `getName()`, `getDescription()`, `getInputSchema()`, mode-dispatch `execute()` stub returning `["mode" => $mode, "stub" => true]`, `stream()` stub yielding one frame, `_requiredPermissions()`.
- **Write** private query-resolver methods on BulkEntries: `_resolveQuery(array $query, ?int $siteId, int $cap, bool $force): EntryQuery`, plus per-field `_validate{Filter}(...)` helpers. Same semantics as the previously-planned `BulkQueryResolver` (validates Q12 surface; resolves `section`/`entryType` handles; pass-through for `status`/`search`/`ids`/`authorId`; parses `dateCreated`/`dateUpdated`/`postDate`/`expiryDate` via `DateTimeHelper::toDateTime()` + `Db::parseDateParam()`; applies `site()` / `site('*')`; applies `status(null)`; calls `count()` once and asserts `<= $cap` or `$force === true`, otherwise throws `ToolException` naming the cap; returns the configured `EntryQuery` ready for `each(100)`). Single consumer for now; can be extracted to `support/` when `ScaffoldEntries` grows a relate-post-create step in a future gate.
- **Modify** `src/services/Tools.php` to register.
- **Modify** `tests/Architecture/EditionGatingTest.php` to include the class.
- **Write** test cases on `BulkEntriesTest` covering the inline resolver behaviour: resolves section+entryType handles; rejects unknown handles; cap enforcement; date range parsing (all four date filters); `siteId: '*'`; force bypasses cap.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=EditionGatingTest` green - confirms BulkEntries registers on Pro, absent on Free, has the trait stack. `ddev exec vendor/bin/pest --filter='BulkEntriesTest::query'` green for the resolver cases.

### Phase 2 - BulkEntries: set_status mode

- **Write** `_setStatus()` method. Pre-flight `count()`, loop with `each(100)`, per-row `canSave()`, per-row `setStatus()` + `saveElement()`, results array, idempotency cache wrap.
- **Write** test cases: admin round-trip happy path (set 5 entries to disabled); permission-skipped rows go to the `skipped` array; trashed-entry skipped with reason `trashed`; idempotency cache hit returns same envelope; row cap enforced; force bypasses cap.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='BulkEntriesTest::set_status'` green.

### Phase 3 - BulkEntries: update_fields mode

- **Write** `_updateFields()` method. Per-row `setFieldValues()` + `saveElement()`.
- **Write** test cases: round-trip on a plain-text field; validation failure on a required field with empty value goes to `failed` array with `validationErrors`; field-not-in-entry-type goes to `failed` with reason; permission-skipped; idempotency.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='BulkEntriesTest::update_fields'` green.

### Phase 4 - BulkEntries: relate mode

- **Write** `_relate()` method. Resolves target field, validates it's a relation field, per-row reads current relations, applies merge strategy, saves.
- **Write** test cases: replace strategy round-trip; add strategy round-trip; remove strategy round-trip; non-relation field rejected at validation time; idempotency.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='BulkEntriesTest::relate'` green.

### Phase 5 - BulkEntries: migrate mode with dry-run

- **Write** `_migrate()` method. Validates target section + entry type. In dry-run mode, runs the per-row permission check and section-compatibility validation but skips `saveElement()`. In commit mode, runs `setSectionId()` + `setTypeId()` + `saveElement()` per row.
- **Write** test cases: dry-run default returns `dryRun: true` + `committed: 0`; explicit `dryRun: false` actually migrates; incompatible source/target rejected at validation; dry-run + commit with same idempotency key produces different cache entries; force bypasses cap; permission-skipped.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='BulkEntriesTest::migrate'` green.

### Phase 6 - BulkEntries: streaming integration

- **Convert** `execute()` to delegate to `stream()` and collapse the generator: `iterator_to_array($stream)` of progress frames discarded, `getReturn()` is the terminal envelope.
- **Write** `stream()` to actually yield progress frames (every `progressInterval` rows visited).
- **Write** test cases: `stream()` direct invocation yields N frames with monotonic `progress`; cancellation via cache slot mid-stream returns partial results with `cancelled: true`; degradation - `execute()` produces same envelope `stream()->getReturn()` carries; `progressInterval: 1` yields one frame per row.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='BulkEntriesTest::stream'` green.

### Phase 7 - ScaffoldEntries

- **Write** `src/tools/content/ScaffoldEntries.php` mirroring BulkEntries structure but single-mode.
- **Modify** `src/services/Tools.php` and `EditionGatingTest` to register.
- **Write** `tests/Tools/Content/ScaffoldEntriesTest.php`: registration; admin happy path creating N entries with `{n:04d}` substitution; validation failure on required field; cap enforcement; force bypass; per-row permission check; idempotency; streaming yields N/progressInterval frames; cancellation partial results.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ScaffoldEntriesTest|EditionGatingTest'` green.

### Phase 8 - Full-suite regression + quality gates

- **Verify**: `ddev exec vendor/bin/pest` full suite green (baseline 777 passing).
- **Verify**: `ddev composer phpstan` clean.
- **Verify**: `ddev composer check-cs` clean.

---

## Test scope

### tests/Tools/Content/BulkEntriesTest.php

Fixture prefix on any created entries: `__cortex_bulktest_{hex}_`. `afterEach()` hard-deletes by title LIKE. The 150 minor-hero seed entries are READ ONLY - tests never mutate them outside an outer transaction that rolls back, OR tests create their own fixture entries inside the suite.

Registration (in EditionGatingTest auto-applied invariants):
- NOT registered on Free.
- `shouldRegister()` returns true on Pro.
- Trait stack present.

`filterFor`:
- stdio (`null` user) -> true.
- Admin -> true.
- User with at least one `saveEntries:{any-uid}` -> true.
- User with zero save permissions -> false.

`set_status` (Phase 3):
- Admin enables 5 disabled entries; `succeeded === 5`, every row has `kind: "success"` with `newStatus: "enabled"`.
- Caller with `saveEntries:posts` only, query spans `posts` and `news`; `news` rows go to `skipped` with `reason: permission_denied`; the operation completes.
- `onPermissionDenied: "fail"` aborts on first denied row with `-32002`; the response carries the list of denied row IDs.
- Trashed entry in the result set -> `skipped` with `reason: trashed`.
- Idempotency: same `{userId, key, mode, query, status}` returns cached envelope; cache TTL respected.
- Row cap: query matching 10 001 rows without `force: true` -> `ToolException` naming the cap.
- `force: true` bypasses cap.

`update_fields` (Phase 4):
- Admin updates a plain-text field on 3 entries; `succeeded === 3`.
- Required field set to empty string -> `failed` with `validationErrors`.
- Field handle not on the entry type -> `failed` with `reason`.
- Permission-skipped + idempotency same as set_status.

`relate` (Phase 5):
- `mergeStrategy: replace` round-trip.
- `mergeStrategy: add` doesn't duplicate existing relations.
- `mergeStrategy: remove` removes only the listed targets.
- Non-relation field -> top-level `errors`.

`migrate` (Phase 6):
- Default dry-run returns `dryRun: true`, `committed: 0`, but `results[]` shows `kind: "success"` per row.
- Explicit `dryRun: false` actually moves entries to the new section/entry type.
- Source-target incompatibility -> top-level `errors`.
- Same idempotency key + dry-run-true + dry-run-false produces DIFFERENT cache entries.

Streaming (Phase 7):
- `stream()` direct invocation yields `ceil(total / progressInterval)` frames + terminal return; frame `progress` monotonically non-decreasing.
- Cancellation: pre-arm the cancel cache slot, invoke `stream()` via the dispatcher, assert partial `results[]` + `cancelled: true`.
- `execute()` returns the same envelope `stream()->getReturn()` carries.
- `progressInterval: 1` yields one frame per row.

### tests/Tools/Content/ScaffoldEntriesTest.php

Same fixture-prefix discipline. Cases per Phase 8.

### tests/Architecture/EditionGatingTest.php (extend)

Append `BulkEntries::class` and `ScaffoldEntries::class` to `_cortex_pro_tool_classes()`. The five existing invariants (trait stack, NOT-on-Free, Pro registration, `filterFor()`-honoured, `IdempotencyTrait::IDEMPOTENCY_CACHE_PREFIX` constant present) auto-apply.

---

## Verification gates

Layered build-verify cadence per phase above. Final gate sequence:

1. `ddev exec vendor/bin/pest --filter='BulkEntriesTest|ScaffoldEntriesTest|EditionGatingTest'` - focused suite green.
2. `ddev composer phpstan` - level 8 clean.
3. `ddev composer check-cs` - ECS clean.
4. `ddev exec vendor/bin/pest` - full suite green (baseline 777).

---

## Manual verification

After the builder reports done, the dispatcher (or operator) should run these against a Pro install in the playground:

1. **Free install**: `bulk_entries` and `scaffold_entries` absent from `tools/list`. The existing Free tools still work.
2. **Pro install, admin, stdio**: `tools/call bulk_entries mode=set_status query={section: "minorHeroes"} status=disabled` round-trips; affected rows visible in CP entry index.
3. **Pro install, admin, HTTP non-streaming** (`Accept: application/json`): same call returns the JSON envelope synchronously; same `results[]` shape.
4. **Pro install, admin, HTTP streaming** (`curl -N -H 'Accept: text/event-stream'`): progress frames visible at a `progressInterval: 25` cadence; terminal frame carries the final envelope.
5. **Pro install, admin, HTTP streaming with `notifications/cancelled`**: in a second terminal, `curl -X POST` a `notifications/cancelled` for the request id mid-stream; the original stream emits a `notifications/cancelled` envelope and closes with `cancelled: true` and partial `results[]`. Audit log row shows `kind=cancelled`.
6. **TCP disconnect cancellation works** (post-Gate-7.7.5): kill the curl mid-stream; the server polls `connection_aborted()`, flips the in-flight cancellation token, writes the `kind=cancelled` audit row, and the generator drains cleanly. Verify via the `cortex_invocations` table — the row carries `kind=cancelled` and the response_excerpt's aggregate counts reflect the partial mutation set.
7. **Pro install, non-admin user with `saveEntries:posts` only**: `bulk_entries` visible in `tools/list`. Call `mode=set_status query={section: "news"}` -> entire result set goes to `skipped`; operation succeeds with `succeeded: 0`.
8. **Pro install, non-admin user, `onPermissionDenied: "fail"`** same call: `-32002` with the denied row list.
9. **Pro install, admin, `mode=migrate dryRun=true`**: response shows `dryRun: true`, `committed: 0`, per-row `kind: success` previews. Re-run with `dryRun: false` and same `idempotencyKey` - DIFFERENT cache entry, actually mutates.
10. **Pro install, admin, `scaffold_entries`** with `count: 150, template.title: "Imported Hero {n:04d}"` - 150 entries created with sequential titles `Imported Hero 0001`..`Imported Hero 0150`.
11. **Pro install, admin, `scaffold_entries` with `count: 10001`, no force** -> `ToolException` naming the cap. With `force: true` -> proceeds.

---

## Risks / open follow-ups

- **Whole-operation idempotency staleness.** If the underlying entry set changes between two calls with the same idempotency key, the second call returns the OLD envelope (locked decision 4). Mitigation: 24h TTL bounds the staleness window; LLMs should generate fresh keys per intent.
- **No outer transaction on each-loop.** Partial-failure recovery is on the caller (locked from gate-8.md risks). The per-row `results[]` array is the recovery surface.
- **`migrate` mode field-loss risk.** Moving entries to a new entry type can drop field values when the source field doesn't exist on the target. The dry-run default mitigates accidental loss; the per-row response in dry-run mode names the fields that would be dropped. Documented in tool PHPDoc.
- **`relate` mode validation cost.** Verifying that `targetField` is a relation field on every visited entry type runs once per unique entry type encountered, not per row - memoize the resolver inside the loop.
- **`ScaffoldEntries` template substitution is regex-based.** No Twig - intentionally narrow surface. If operators ask for Twig-in-templates, that's a separate gate.
- **150-row playground seed bleeds into other tests.** The seed migration runs once at install time and lifts an existing 25 skips per memory note. If other gates' tests assume an empty `minorHeroes` section, they need updating - but per the architecture rule `->status(null)` + `->site('*')` test queries should be deterministic regardless.

---

## Out of scope

- **CP authoring UI for bulk operations** - Phase 3 (CP UI gate).
- **Outer transactions per batch** - locked architectural decision; recovery is on the caller via `results[]`.
- **`relatedTo` query filter** - the surface is too messy for LLM consumption; intentionally not exposed.
- **Custom-field query filtering** - locked spec from gate-8.md; not in 8.7.
- **Bulk operations on non-entry elements** (assets, categories, tags, users) - future gate. The pattern is repeatable; 8.7 is the proof-of-concept.
- **Pre-validation dry-run for non-migrate modes** - `migrate` defaults to dry-run; `set_status`/`update_fields`/`relate` execute immediately. Future gate could add a universal `dryRun: true` flag.
- **`ScaffoldEntries` Twig templates** - regex substitution is the locked surface.
- **Idempotency across distinct users** - the cache key is user-scoped (per IdempotencyTrait).
