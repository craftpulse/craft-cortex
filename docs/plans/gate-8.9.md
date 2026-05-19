# Gate 8.9 — Streaming enablement on `resave`, `content_audit` fix modes, `import_export.import`

Internal sub-plan for the builder. Parent: `docs/plans/gate-8.md` section 8.9 (lines 380–398), locked decisions 7 (per-tool streaming contract) and 8 (cancellation forwarding). Scope: wrap the three remaining streaming-capable tools in `StreamableToolInterface::stream()`. `bulk_entries` shipped streaming in 8.7 and is out of scope. After 8.9 the streaming surface matches `gate-8.md` §"Streaming enablement contract per tool" in full.

Baseline at HEAD `2d0d2d7` (Gate 8.6 shipped): 891 passing / 1 skipped / 10546 assertions, PHPStan + ECS clean.

## Split recommendation — TAKEN

`resave` streaming requires a new infrastructure seam in the tool (event-driven progress capture via PHP Fibers — see Q A resolution below). The audit and import_export bodies are pure array→generator conversions where the 8.8b builder already shaped the per-row helpers for the seam. Bundling the two would force the builder to context-switch between a non-trivial infrastructure piece and three mechanical refactors. **Split into two commits, single gate:**

| Sub-gate | Tools | Estimated LoC (prod + tests) | Rationale |
|---|---|---|---|
| **8.9a** | `resave` streaming | ~600 (350 prod + 250 tests) | New event-driven progress infrastructure + Fiber wrapper. The trickiest piece. Lands first because the Fiber pattern is the new vocabulary. |
| **8.9b** | `content_audit` fix modes + `import_export.import` | ~600 (300 prod + 300 tests) | Pure `array → Generator` conversions over already-isolated `_fixOne*()` / `_pruneOneAsset()` / `_repairOnePropagation()` / `_importOneEntry()` helpers from 8.8b. Mechanical. |

A single 8.9 gate is feasible (~1200 LoC, comparable to 8.7's ~1400) but the build-verify cadence is cleaner split. Each sub-gate ships `develop-v5` green independently.

Source files consulted (file:line):

- `src/tools/dev/Resave.php:126-150` — current `execute()`. Dispatches `resave/<type>` via `ConsoleRunner::run()` and returns `{type, route, options, exitCode, output, error}`.
- `src/tools/dev/Resave.php:170-244` — `_buildParams()`. Translates structured input to Yii controller-option keys; reused by streaming path.
- `src/tools/support/ConsoleRunner.php:53-90` — `run()` is blocking. Stdout/stderr are captured via a stream filter, returned post-hoc. No incremental observation hook today — adding one would force every caller (`craft_command`, `craft_exec`) into a callback shape. **Not modified by 8.9**; streaming path bypasses it.
- `src/tools/content/BulkEntries.php:339-392` — reference for `execute() → stream() → getReturn()` collapse pattern. `execute()` drains the generator and returns `$gen->getReturn()`.
- `src/tools/content/BulkEntries.php:833-949` — reference `_runLoop()` shape. Per-row try/catch, cancellation poll at top of each iteration, `$processed % $progressInterval === 0` yield gate. 8.9b mirrors this shape per tool.
- `src/tools/dev/StreamingFixtureTool.php:119-135` — minimal `stream()` reference shape.
- `src/tools/StreamableToolInterface.php:73-113` — contract. `stream()` returns `Generator<int,array,mixed,array>`. Sub-generator cancellation caveat noted (no `yield from` over generators that don't poll the token).
- `src/tools/support/CancellationToken.php` — `isCancelled()` poll surface.
- `src/tools/support/InvocationContext.php:117-128` — constructor; `getCancellationToken()` accessor.
- `src/tools/workflow/Audit.php:174-189` — current `getInputSchema()`. Edition-gated enum.
- `src/tools/workflow/Audit.php:267-290` — current `execute()` match; fix-mode arms call `$this->_fixRelations($arguments)` etc. and return arrays.
- `src/tools/workflow/Audit.php:734-784` — `_fixRelations()` body. Single `foreach` over fetched relation rows; per-row dispatch to `_fixOneRelation()`. 8.9b converts this to `Generator`.
- `src/tools/workflow/Audit.php:800-872` — `_fixOneRelation()`. Per-row mutation. Stays unchanged in 8.9b.
- `src/tools/workflow/Audit.php:887-933` — `_pruneUnusedAssets()` body. Same shape. 8.9b converts.
- `src/tools/workflow/Audit.php:948-1000` — `_pruneOneAsset()`. Unchanged.
- `src/tools/workflow/Audit.php:1016-1063` — `_repairPropagation()` body. Per-gap loop. 8.9b converts.
- `src/tools/workflow/Audit.php:1080-1099` — `_repairOnePropagation()`. Unchanged.
- `src/tools/workflow/ImportExport.php:467-533` — `_import()` body. Per-item loop. 8.9b converts.
- `src/tools/workflow/ImportExport.php:552-…` — `_importOneEntry()`. Unchanged.
- `src/mcp/Server.php:856-933` — `_streamToolCall()`. Drives the streaming dispatcher loop; polls `isCancelled()` between yields; writes the `kind=cancelled` audit row via `_logCancelled()` on the cancellation path.
- `src/controllers/McpController.php:673-742` — `_streamPost()`. Sets `ignore_user_abort(true)`; polls `connection_aborted()` after each emit; flips the in-flight token via `Server::getInFlightCancellationToken()->cancel('client disconnected')`; drains the generator after disconnect so the audit row still writes.
- `vendor/craftcms/cms/src/services/Elements.php:242, 247, 252, 257` — `EVENT_BEFORE_RESAVE_ELEMENTS` / `EVENT_AFTER_RESAVE_ELEMENTS` / `EVENT_BEFORE_RESAVE_ELEMENT` / `EVENT_AFTER_RESAVE_ELEMENT`. The seam for resave streaming.
- `vendor/craftcms/cms/src/services/Elements.php:1609-1688` — `resaveElements()` body. Triggers per-element events at lines 1638-1644 (before) and 1668-1675 (after). The before-event carries `position` (1-indexed); the after-event carries `position` + `exception`.
- `vendor/craftcms/cms/src/events/MultiElementActionEvent.php:19` — event class. Fields `query` (the ElementQuery), `element` (the in-flight element), `position` (int), `exception` (Throwable|null) — public properties from `ElementQueryEvent` + `MultiElementActionEvent`.
- `vendor/craftcms/cms/src/console/controllers/ResaveController.php:584-722` — `actionEntries()` / `actionAssets()` / etc. and `resaveElements()`. The `--queue` flag short-circuits to a `ResaveElements` queue job; the in-process path calls `Elements::resaveElements()` with the query, which is where the per-element events fire.
- `vendor/craftcms/cms/src/console/controllers/ResaveController.php:788-924` — `_resaveElements()`. Pre-flight `count()` at line 793 — same shape the streaming path needs for `total`. The `$beforeCallback` at lines 817-889 implements `--set`/`--to`/`--propagateTo`/`--toDefault`/`--setEnabledForSite` field-rewrite logic via the same events the streaming path will subscribe to.
- `tests/Tools/Dev/ResaveTest.php` (74 LoC) — existing test surface. Five cases covering option mapping, type rejection, and the captured-output round-trip.
- `tests/Tools/Workflow/AuditFixModesTest.php` — existing 8.8b fix-mode tests; baseline for the 8.9b generator-mode test extensions.
- `tests/Tools/Workflow/ImportExportImportTest.php` — same.
- `tests/Mcp/StreamingTest.php` — reference for streaming dispatcher tests (cancellation cache slot pre-arm pattern, terminal-envelope assertions).
- `tests/Tools/Content/BulkEntriesTest.php` — reference for `stream()`-direct-invocation tests (frame count, monotonicity, `getReturn()` equality with `execute()`).

---

## Locked decisions

### Carried from gate-8.md (resolved 2026-05-15) and lock-ins 7-8

1. **Streaming contract per tool (Decision 7).**
   - `resave`: `progress` is the 1-indexed element position; `total` is the pre-flight `count()`; one frame per element (default — see 5 below for the throttle); `message` is `"Resaving {element} ({id})"`. Cancellation: stops issuing further resaves; the in-flight element's save completes (Craft's `Elements::resaveElements()` is non-killable mid-element). Already-resaved elements persist.
   - `content_audit.fix_relations`: `progress` = rows processed; `total` = `count(brokenRelationsQuery)` resolved once at stream start; one frame per fixed record; `message` = `"Fixed relation src={sourceId} → target={targetId}"` or `"Skipped relation src={sourceId} (reason)"`. Cancellation: stops at next row. Already-fixed rows persist.
   - `content_audit.prune_unused_assets`: `progress` = assets processed; `total` = pre-flight count of the unused-assets query; one frame per asset; `message` = `"Pruned asset {id}"` or `"Skipped asset {id} (reason)"`. Cancellation: stops at next asset.
   - `content_audit.repair_propagation`: `progress` = gaps processed; `total` = `count(gaps)` post `_collectPropagationGaps()`; one frame per gap; `message` = `"Repaired canonical {canonicalId} ({sectionUid})"`. Cancellation: stops at next gap.
   - `import_export.import`: `progress` = items consumed; `total` = `count($items)`; one frame per item; `message` = `"Imported item index={index} uid={sourceUid}"`. Cancellation: stops at next item. Imported items persist; dry-run items leave no trace.

2. **Cancellation forwarding (Decision 8).** Each streaming tool polls `$ctx->getCancellationToken()->isCancelled()` between yields. Non-streaming Pro tools (`entry`, `category`, `tag`, `address`, `global_set`, `users`, `skill`) do not poll. `resave` polls **between elements**; the in-flight element's `Elements::saveElement()` is not interrupted (matches Craft's own non-killable model and the locked decision 7 wording).

3. **Degradation.** `execute()` collapses `stream()` to its terminal `getReturn()` for clients that don't request SSE. Mirrors `BulkEntries::execute()` at `src/tools/content/BulkEntries.php:339-351`. Locked for all three tools.

4. **CancellationToken on both surfaces — PASS post-7.7.5.** Synthetic `notifications/cancelled` and real HTTP TCP-disconnect both flip the same token. The dispatcher's drain-don't-break loop guarantees the `kind=cancelled` audit row writes. Reference verification block: `docs/plans/gate-8.7.md:40-58`. 8.9 inherits this surface unchanged.

### Open technical questions — resolved against source

5. **Q A — `ConsoleRunner` streaming seam, the trickiest piece.** Resolved: **bypass `ConsoleRunner` on the streaming path; subscribe to `Elements::EVENT_AFTER_RESAVE_ELEMENT` and drive the generator via a PHP Fiber.** The non-streaming path keeps using `ConsoleRunner::run()` unchanged.

    Three options were considered:
    - **Post-hoc capture** (run the whole resave to completion, then yield frames retroactively from a captured array). Rejected — defeats streaming entirely; the SSE client gets all frames in a burst at the end.
    - **`ConsoleRunner::runStreaming(callable $onProgress)` seam** that flushes output line-by-line. Rejected — Craft's `Console::startProgress` / `stdout()` output (`ResaveController._resaveElements():821`) is human-readable ANSI-coloured text with format like `"    - [12/847] Resaving Article (1234) ... "`. Parsing that for `progress`/`total`/`message` is fragile across Craft versions and forfeits the structured `MultiElementActionEvent` we already have. ~150-200 LoC of regex parsing plus per-element-type fragility. Not justified.
    - **Event-driven progress via Fiber wrapper.** Picked. `Elements::resaveElements()` fires `EVENT_AFTER_RESAVE_ELEMENT` with `position`, `query`, `element`, `exception` — exactly the data the progress frame needs. Listener calls `Fiber::suspend($frame)`; the parent generator resumes the Fiber, yields the frame, resumes the Fiber. PHP >=8.2 required (`composer.json:31` declares `>=8.2`; Fibers landed in 8.1). The Fiber wraps `Elements::resaveElements()` itself — no event-listener side-effects on calling code. ~80-100 LoC infrastructure in `src/tools/support/FiberProgressBridge.php` + ~150 LoC in `Resave::stream()`.

    `ConsoleRunner` itself is **untouched** — no `runStreaming()` method, no new buffer-flush hook. The streaming path is structurally different from the non-streaming path and doesn't fit `ConsoleRunner`'s "capture stdout for JSON-RPC isolation" mission. Documented in `Resave.php` PHPDoc.

    Risk read: **MEDIUM**. PHP Fibers + Craft events are both well-trodden, but the integration is new vocabulary for the codebase (no existing `Fiber::` usage per `grep -rn "Fiber::" src/`). The Fiber bridge is testable in isolation against a synthetic event emitter — recommend a `tests/Tools/Support/FiberProgressBridgeTest.php` before wiring `Resave::stream()`.

6. **Q B — Resave progress source + per-frame `message` shape.** Resolved against `vendor/craftcms/cms/src/services/Elements.php:1668-1675`. The `EVENT_AFTER_RESAVE_ELEMENT` event fires once per element with:
   - `$event->position` (int, 1-indexed)
   - `$event->element` (ElementInterface — has `->id`, `->title`, `->getUiLabel()`)
   - `$event->exception` (Throwable|null — non-null indicates the per-element save failed)
   - `$event->query` (the originating query; for identity filtering when multiple resaves run concurrently — Cortex's stdio is single-process and HTTP isolates per request, so the filter is defensive)

    Per-frame shape:
    ```
    {progress: $position, total: $preflightCount, message: "Resaving {Type} \"{title}\" ({id})"}
    ```
    Where `{Type}` is `$element::displayName()` and `{title}` is `$element->getUiLabel()`. On per-element failure, the message is prefixed `"Failed resaving "` and `exception->getMessage()` is appended after a separator. The frame's `progress` is always the position — no separate "failed" counter rides in progress frames; aggregate counts live in the terminal envelope.

7. **Q C — Audit fix-mode conversion safety.** Resolved: trivial. The 8.8b builder already shaped each fix-mode body as a single `foreach` over a pre-resolved iterable (rows from `_brokenRelationsQuery()`, `$query->all()` from `_unusedAssetsQuery()`, gaps from `_collectPropagationGaps()`). No open DB cursors crossing yield boundaries — each iterable is materialised before the loop runs. No closure captures. No intermediate state outside `$results[]`/`$processed`/etc. Conversion shape is **mechanical**:

    ```php
    // before (8.8b)
    foreach ($rows as $row) {
        $outcome = $this->_fixOneRelation($row);
        $results[] = $outcome;
        $processed++;
        // ... bump counters
    }
    return [/* envelope */];

    // after (8.9b)
    foreach ($rows as $row) {
        if ($token->isCancelled()) { $cancelled = true; break; }
        $outcome = $this->_fixOneRelation($row);
        $results[] = $outcome;
        $processed++;
        // ... bump counters
        if ($processed % $progressInterval === 0) {
            yield ['progress' => $processed, 'total' => $totalCount, 'message' => "..."];
        }
    }
    return [/* envelope + 'cancelled' => $cancelled */];
    ```

    No helper extraction needed. `_fixOneRelation()` / `_pruneOneAsset()` / `_repairOnePropagation()` stay as-is.

8. **Q D — `import_export.import` conversion safety.** Same as Q C. The 8.8b body at `ImportExport.php:496-519` is a `foreach ($items as $index => $item)` over an in-memory array. No cursors. `_importOneEntry()` stays unchanged. Conversion mechanical.

9. **Q E — Cancellation cooperation placement per tool.**
   - **Resave**: `$token->isCancelled()` polled inside the `EVENT_AFTER_RESAVE_ELEMENT` listener BEFORE calling `Fiber::suspend()`. If cancelled, the listener throws a `QueryAbortedException` (Craft's `Elements::resaveElements()` catches this at `:1677` and exits cleanly — "Fail silently" comment). The parent generator catches the resulting return from `Elements::resaveElements()` and yields the terminal cancellation envelope. Polled BETWEEN elements, not mid-`saveElement()`. Locked decision 7 wording: "in-flight batch completes" — here the "batch" is one element.
   - **Audit fix modes**: poll at the top of each foreach iteration, BEFORE calling the per-row helper. Cancellation between rows; in-flight row's mutation completes.
   - **ImportExport import**: same — poll at top of `foreach ($items)`, before `_importOneEntry()`.

    Audit-row write: the dispatcher's drain-don't-break loop (`src/mcp/Server.php:881-892`, `:920-933`) writes `kind=cancelled` regardless. The tool's terminal envelope carries `cancelled: true` and the partial `results[]`. The dispatcher's `_logCancelled()` is invoked unconditionally when `$cancellationToken->isCancelled()` is true at terminal time.

10. **Q F — `tools/list` invariants.** Adding `implements StreamableToolInterface` to a class does NOT change the `tools/list` payload. `Tools::asListPayload()` (`src/services/Tools.php`) emits `{name, description, inputSchema}` per tool — no streaming flag in the wire shape per MCP 2025-06-18 (streaming is an `Accept`-header upgrade, not a tool capability). The schema invariant at `tests/Mcp/ToolInterfaceInvariantTest.php` (`inputSchemaFor(null) === getInputSchema()`) is preserved because 8.9 doesn't touch `getInputSchema()` or `inputSchemaFor()` on any of the three tools. Locked.

11. **Q G — Test patterns per tool.** For each streaming tool:
    - Direct `stream($args, $ctx)` invocation: drain via `while ($gen->valid()) { $frames[] = $gen->current(); $gen->next(); } $terminal = $gen->getReturn();` — assert frame count, `progress` monotonicity, terminal envelope shape.
    - Mid-stream cancellation: pre-flip `$ctx->getCancellationToken()->cancel('test')` BEFORE invocation OR pre-arm the cache slot `cortex:cancel:{sessionId}:{requestId}` if testing via dispatcher path — assert partial `results`, `cancelled: true`, terminal envelope written.
    - Audit-row write on cancellation: assert `cortex_invocations` row exists with `kind=cancelled`.
    - Non-streaming dispatch (`execute()`): drain `stream()` internally, return final envelope — assert equality with the envelope `stream()->getReturn()` carries.
    - For `resave` specifically: spin up ~10 fixture entries (or use the playground's `minorHeroes` seed), call `stream()`, assert ~10 frames emitted (or ceil(10 / DEFAULT_PROGRESS_INTERVAL) if a per-N throttle is added — see locked decision 12 below).

12. **`progressInterval` knob on `resave`.** **Locked: NOT exposed in 8.9.** Default and only behaviour is one frame per element. Rationale: `resave` already pre-flights `count()`; the LLM has total visibility from frame 1; per-element granularity is exactly what an operator watching a streaming console wants. If high-throughput resaves overwhelm the wire (>1000 elements/sec), a future gate can add `progressInterval` mirroring the `bulk_entries` pattern. **Pre-emptive throttle: skip emitting a frame when `< 16ms` has elapsed since the last emit (60Hz cap).** This is a wire-level concern, not a contract feature — implemented in `_emitProgress()` inside `Resave.php`; not exposed in the schema. ~10 LoC. Justified because `Elements::resaveElements()` can fire 500+ events/sec on a fast disk and SSE flushing has measurable overhead per frame.

13. **`Resave::resolveCount()` — does it exist?** No. `gate-8.md:64` references it speculatively. The streaming path constructs the same `EntryQuery` / `AssetQuery` / etc. that `ConsoleRunner` would have built (via the existing `_buildParams()` output) and calls `count()` on it pre-flight. Implementation lives in a new private method `Resave::_buildQueryForStreaming(string $type, array $params): ElementQueryInterface` — mirrors `ResaveController::_baseCriteria()` logic at `vendor/craftcms/cms/src/console/controllers/ResaveController.php:744-783`. ~50 LoC of query-construction parity, well-tested in 8.9a's test suite.

14. **`--queue` flag interaction.** When `queue: true`, the non-streaming path dispatches a `ResaveElements` queue job via `Queue::push()` (`ResaveController.php:702-714`) and returns immediately. **Streaming `queue: true` is meaningless** — the queue job runs out-of-band; there's nothing to stream. Resolution: `Resave::stream()` rejects `queue: true` with `ToolException("resave: streaming is incompatible with queue: true — invoke without queue: true for streaming, or wait for the queued job in-process.")`. The streaming entry point is in-process only. Documented in PHPDoc.

15. **`propagateTo` + `--set`/`--to` field rewrites on the streaming path.** `Elements::EVENT_BEFORE_RESAVE_ELEMENT` is what `ResaveController._resaveElements()` uses to apply `--set`/`--to`/`--propagateTo`/`--toDefault`/`--setEnabledForSite` mutations (`ResaveController.php:817-889`). The streaming path must replicate this — registering a `beforeCallback` listener identical in semantics to ResaveController's. Alternatively (and locked): the streaming path **does not support `set`/`to`/`propagateTo`/`toDefault`/`setEnabledForSite` in 8.9a.** These are field-rewrite-heavy paths that benefit less from streaming (the operator usually wants to confirm the rewrite logic via dry-run first; full streaming visibility is overkill). Rejecting them at the streaming entry point keeps 8.9a's diff focused on the progress infrastructure. **Streaming-only restriction**: when `set`/`to`/`propagateTo`/`toDefault`/`setEnabledForSite`/`ifEmpty`/`ifInvalid` are present AND the client requested SSE, throw `ToolException("resave: streaming does not support field-rewrite options (set/to/propagateTo/toDefault/setEnabledForSite/ifEmpty/ifInvalid) — use non-streaming dispatch for those.")`. The non-streaming path keeps the full surface unchanged. Documented in PHPDoc. ~5 LoC validation in `stream()`.

16. **Resave terminal envelope shape (streaming path).** Differs from the non-streaming `execute()` shape because the streaming path has structured per-element data the non-streaming captured-stdout path doesn't. Locked shape for the streaming `getReturn()`:
    ```
    {
      success: bool,
      type: "entries" | "assets" | ...,
      route: "resave/entries" | ...,
      total: int,            // pre-flight count
      processed: int,        // elements visited
      succeeded: int,        // events with $exception === null
      failed: int,           // events with $exception !== null OR $element->hasErrors()
      cancelled: bool,
      results: [             // per-element outcomes (succeeded + failed only)
        {kind: "success" | "failure", id: int, type: string, title: string, error?: string},
      ]
    }
    ```
    The non-streaming `execute()` continues to return its original captured-stdout shape `{type, route, options, exitCode, output, error}`. **The two shapes do NOT match by design** — the streaming path has structured progress data; the non-streaming path doesn't run through the streaming infrastructure and keeps the legacy shape. This is a **deliberate divergence from the BulkEntries pattern** where `execute()` and `stream()->getReturn()` are bit-identical. Justification: BulkEntries owns the iteration; Resave delegates to Craft's resaveElements pipeline. The non-streaming path wraps the controller; the streaming path wraps the service. Different observability surfaces.

    **Alternative considered and rejected:** route `execute()` through `stream()` too, so both shapes match. Rejected because it forces every non-streaming `resave` caller (existing test surface, `craft_command` users, in-process scripts) onto the new Fiber path. Diff and risk surface grows substantially for marginal consistency benefit. Documented in `Resave.php` PHPDoc with explicit "shape divergence" callout.

    `results[]` cap: do NOT store every element. The succeeded/failed counts are aggregates; `results[]` should be a sliding window — the last 100 failures (the LLM rarely cares about every success). **Locked**: 8.9a only records `kind: "failure"` rows in `results[]`. Successes are counted but not enumerated. ~5 LoC change in the listener.

---

## File map

**New (8.9a):**
- `src/tools/support/FiberProgressBridge.php` — generic event-driven Fiber bridge. Subscribes to a Yii event, runs a blocking callable in a Fiber, `Fiber::suspend()`s on each event, returns the progress sequence as an iterable. ~120 LoC + PHPDoc. Tool-agnostic — future streaming-around-blocking-Craft-API tools can reuse.
- `tests/Tools/Support/FiberProgressBridgeTest.php` — direct tests against a synthetic event emitter. ~80 LoC, ~5 cases. Tests the bridge in isolation BEFORE `Resave::stream()` wires it.
- `tests/Tools/Dev/ResaveStreamingTest.php` — ~250 LoC, ~10 cases. Cases per Q11. Uses the `minorHeroes` playground seed; afterEach restores.

**New (8.9b):**
- `tests/Tools/Workflow/AuditFixModesStreamingTest.php` — ~150 LoC, ~9 cases (3 fix modes × {direct-stream-yields, cancellation, execute()-degradation}).
- `tests/Tools/Workflow/ImportExportImportStreamingTest.php` — ~100 LoC, ~5 cases.

**Modified (8.9a):**
- `src/tools/dev/Resave.php` — `implements StreamableToolInterface`; new `stream()` method; new private `_streamingResolve(string $type, array $params): array{query, elementType}`, `_emitProgress(int $position, int $total, ElementInterface $element, ?Throwable $exception): array{progress, total, message}`, `_streamingTerminalEnvelope(...)`. ~300 LoC delta. Keeps `execute()` unchanged.

**Modified (8.9b):**
- `src/tools/workflow/Audit.php` — `implements StreamableToolInterface` added; `stream()` method dispatches to per-mode generators; `_fixRelations()`, `_pruneUnusedAssets()`, `_repairPropagation()` change return type from `array` to `Generator` and gain a generator-style loop body with yield gates + cancellation poll; `execute()` collapses `stream()` for non-streaming clients (mirrors BulkEntries pattern). `_fixOneRelation()` / `_pruneOneAsset()` / `_repairOnePropagation()` are UNCHANGED. ~200 LoC delta.
- `src/tools/workflow/ImportExport.php` — same shape change for `_import()` body; `_importOneEntry()` unchanged. ~100 LoC delta.

**Untouched (verify, do not modify):**
- `src/tools/support/ConsoleRunner.php` — no streaming seam added. Non-streaming `Resave::execute()` continues to call `ConsoleRunner::run()` unchanged.
- `src/tools/StreamableToolInterface.php` — contract consumed as-is.
- `src/tools/support/InvocationContext.php`, `CancellationToken.php` — consumed as-is.
- `src/mcp/Server.php`, `src/controllers/McpController.php`, `src/mcp/transport/SseEmitter.php` — consumed as-is.
- `src/services/Tools.php` — no new tool registrations.
- `tests/Architecture/EditionGatingTest.php` — Pro-tool list unchanged. `Resave` is Free; `Audit` and `ImportExport` stay Free-registered with Pro-gated modes (the streaming infrastructure does NOT change the Free/Pro split).
- Existing `tests/Tools/Workflow/AuditFixModesTest.php` and `tests/Tools/Workflow/ImportExportImportTest.php` — their assertions on the terminal envelope's array shape continue to pass because the converted bodies return the same envelope; the difference is the body is now `return [...]` inside a `Generator` rather than from a plain `array`-returning method. The collapsed `execute()` produces the same final shape.

---

## Sub-gate 8.9a — `resave` streaming

### Tool spec — `resave` streaming entry

| Field | Type | Required | Streaming behaviour |
|---|---|---|---|
| (all existing fields from `Resave::getInputSchema()`) | (unchanged) | (unchanged) | unchanged |
| `queue` | bool | no | **Rejected when streaming.** ToolException thrown if true on the streaming path. |
| `set`, `to`, `ifEmpty`, `ifInvalid`, `propagateTo`, `toDefault`, `setEnabledForSite` | various | no | **Rejected when streaming** in 8.9a. ToolException thrown if any present on the streaming path. Non-streaming `execute()` still accepts them. |

Schema unchanged; the rejections happen at execution time, not via schema enum restriction. Rationale: the same tool name has to keep working non-streaming with the full surface; the streaming path is the narrower subset.

### `stream()` flow

1. Validate `type` via existing `Resave::TYPE_ROUTES` check (reuse `execute()`'s validation).
2. Reject `queue: true` and the seven field-rewrite options (locked decision 15).
3. Build the element query via new `_streamingResolve($type, $arguments)`. Mirrors `ResaveController._baseCriteria()` semantics.
4. Pre-flight `count()`. Yield zero-row envelope if `total === 0`.
5. Construct a `FiberProgressBridge` keyed on `Elements::EVENT_AFTER_RESAVE_ELEMENT`. The blocking callable inside the Fiber calls `Craft::$app->getElements()->resaveElements($query, continueOnError: true, skipRevisions: true, updateSearchIndex: $upd, touch: $touch)`.
6. Drive the bridge: each Fiber suspension yields a progress frame; the parent generator yields it onward (subject to the 60Hz throttle), polls `$token->isCancelled()` before resuming the Fiber.
7. On cancellation, the listener registered with the Fiber throws `QueryAbortedException` to stop further per-element iteration cleanly. The Fiber returns; the parent generator catches and emits the terminal envelope with `cancelled: true`.
8. Return the terminal envelope per locked decision 16.

### `FiberProgressBridge` shape

```php
final class FiberProgressBridge
{
    public function __construct(
        private object $sender,           // e.g. Craft::$app->getElements()
        private string $eventName,        // e.g. Elements::EVENT_AFTER_RESAVE_ELEMENT
        private \Closure $eventToFrame,   // maps the Event to a progress-frame array
        private \Closure $blockingCall,   // the callable that runs to completion inside the Fiber
    ) {}

    /**
     * @return \Generator<int, array<string,mixed>, mixed, mixed> Yields progress frames; return value is whatever $blockingCall returned.
     */
    public function run(\Closure $shouldCancel): \Generator
    {
        $fiber = new \Fiber(function() {
            $handler = function (object $event) {
                $frame = ($this->eventToFrame)($event);
                if ($frame !== null) {
                    \Fiber::suspend($frame);
                }
            };
            Event::on($this->sender::class, $this->eventName, $handler);
            try {
                return ($this->blockingCall)();
            } finally {
                Event::off($this->sender::class, $this->eventName, $handler);
            }
        });

        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            if ($shouldCancel()) {
                // The next event-handler invocation will throw, the Fiber unwinds.
                $fiber->throw(new QueryAbortedException('cortex: cancelled'));
                break;
            }
            if ($frame !== null) {
                yield $frame;
            }
            $frame = $fiber->resume();
        }

        return $fiber->getReturn();
    }
}
```

Note: `Event::on()` is Yii's static event subscription. The handler captures by reference into `$this` via the closure binding so the `eventToFrame` closure is invoked on each event. **Important caveat**: the handler is registered on `$this->sender::class` (the class name), not on the instance — Yii fires class-level events, not instance-level for `Elements`. Tested against `vendor/craftcms/cms/src/services/Elements.php:1668-1675`.

### Implementation phases (layered build-verify) — 8.9a

#### Phase 8.9a-1 — `FiberProgressBridge` infrastructure

- **Write** `src/tools/support/FiberProgressBridge.php`. Pure infrastructure, no Resave coupling.
- **Write** `tests/Tools/Support/FiberProgressBridgeTest.php`. Synthetic event emitter (a tiny `yii\base\Component` subclass that fires an event N times then returns). Cases:
  - Bridge yields one frame per event, returns the blocking call's return.
  - `$shouldCancel()` returning true mid-stream stops the iteration; the Fiber unwinds; the handler is deregistered.
  - `eventToFrame` returning `null` skips the yield for that event.
  - Event-listener errors propagate out of the parent generator.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=FiberProgressBridgeTest` green. Small / 20 min.

#### Phase 8.9a-2 — `Resave::stream()` happy path

- **Write** `stream()` + `_streamingResolve()` + `_emitProgress()` + `_streamingTerminalEnvelope()`. Bypass `ConsoleRunner`; use `FiberProgressBridge` with `Elements::resaveElements()` as the blocking call.
- **Write** rejection logic for `queue: true` and the seven field-rewrite options.
- **Write** 60Hz emit throttle in `_emitProgress()` (skip if `<16ms` since last emit; always emit the last frame).
- **Write** `tests/Tools/Dev/ResaveStreamingTest.php` cases for the happy path:
  - Stream-direct invocation against the `minorHeroes` seed (~150 entries with `section: minorHeroes`); ~150 frames; monotonic `progress`; terminal envelope shape per locked decision 16.
  - `total: 0` (no entries match) yields one zero-row frame.
  - `queue: true` rejected.
  - `set` / `to` rejected on streaming path; same call without those succeeds non-streaming.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ResaveStreamingTest::happy|ResaveStreamingTest::rejects'` green. Medium / 30 min.

#### Phase 8.9a-3 — `Resave::stream()` cancellation

- **Write** cancellation cooperation. The Fiber's event-handler closure observes `$shouldCancel()` (closed over `$token->isCancelled()`) before each `Fiber::suspend()`.
- **Write** cases:
  - Mid-stream cancellation via `$ctx->getCancellationToken()->cancel()` BEFORE the third event: terminal envelope has `cancelled: true`, `processed >= 2`, `processed < 150`.
  - Cancellation via dispatcher cache-slot pre-arm (synthetic `notifications/cancelled` path): same terminal shape, `cortex_invocations` row carries `kind=cancelled`.
  - HTTP TCP-disconnect path is covered by the existing `tests/Mcp/StreamingTest.php` infrastructure — no new test in 8.9a; the streaming surface is generic.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ResaveStreamingTest::cancellation'` green. Medium / 25 min.

#### Phase 8.9a-4 — `execute()` non-streaming path preserved

- **Verify** `Resave::execute()` is bit-identical to its pre-8.9a behaviour. No changes to that method. Existing `tests/Tools/Dev/ResaveTest.php` (7 cases) must pass without modification.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ResaveTest'` green; no regressions.

#### Phase 8.9a-5 — Full-suite regression + quality gates

- **Verify**: `ddev exec vendor/bin/pest` full suite green (baseline 891).
- **Verify**: `ddev composer phpstan` clean.
- **Verify**: `ddev composer check-cs` clean.

---

## Sub-gate 8.9b — `content_audit` + `import_export.import` streaming

### Audit tool changes

- `Audit` gains `implements StreamableToolInterface` (after the existing `extends AbstractTool` clause).
- New `public function stream(array $arguments, InvocationContext $ctx): Generator` method. Validates mode (must be one of `fix_relations / prune_unused_assets / repair_propagation` — the read modes don't stream because they're already returning a paged JSON envelope with no row-by-row mutation), dispatches to per-mode generator via `match`.
- `execute()` collapses `stream()` to its terminal `getReturn()` ONLY for streaming modes. Read modes (`relations / unused_assets / propagation`) keep their existing direct `array` return path through the existing `match` arms — no streaming for those.
- `_fixRelations()`, `_pruneUnusedAssets()`, `_repairPropagation()` — return type `array` → `Generator`. Body shape per Q7 above.

Match dispatch shape:
```php
public function stream(array $arguments, InvocationContext $ctx): Generator
{
    $mode = $arguments['mode'] ?? null;
    if (!in_array($mode, self::PRO_MODES, true)) {
        throw new ToolException("content_audit: mode `{$mode}` is not streamable.");
    }
    // Edition + permission re-check identical to execute()
    if (!Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
        throw new ToolException("content_audit: mode `{$mode}` is unavailable on this edition.");
    }
    return yield from match ($mode) {
        'fix_relations' => $this->_fixRelations($arguments, $ctx),
        'prune_unused_assets' => $this->_pruneUnusedAssets($arguments, $ctx),
        'repair_propagation' => $this->_repairPropagation($arguments, $ctx),
    };
}

public function execute(array $arguments): array
{
    $mode = $arguments['mode'] ?? null;
    if (in_array($mode, self::PRO_MODES, true)) {
        $ctx = new InvocationContext();
        $gen = $this->stream($arguments, $ctx);
        while ($gen->valid()) { $gen->next(); }
        $return = $gen->getReturn();
        return is_array($return) ? $return : [];
    }
    // Existing read-mode dispatch (unchanged)
    return match ($mode) {
        'relations' => $this->_relations($arguments),
        'unused_assets' => $this->_unusedAssets($arguments),
        'propagation' => $this->_propagation($arguments),
        default => throw new ToolException("Unknown mode: '{$mode}'."),
    };
}
```

The read-mode path stays direct (no Generator wrap) — they're paged-array tools, not row-by-row mutators. Streaming an array-return tool is over-engineering.

### ImportExport tool changes

- Same shape as `Audit` but only one Pro mode (`import`).
- `_import()` return type `array` → `Generator`. Receives `InvocationContext` as a second parameter.
- `execute()` collapses `stream()` for `import` mode; read modes (`export`) unchanged.

### Per-tool terminal envelope shape

Identical to the 8.8b shapes — the envelope is the generator's return value. 8.9b adds one new field:

```
{
  // ... existing 8.8b envelope ...
  cancelled: bool   // NEW in 8.9b
}
```

`cancelled` is `false` on terminal completion, `true` when the loop broke on `$token->isCancelled()`. The non-streaming `execute()` path always produces `cancelled: false` (there's no cancellation surface in the synchronous path).

### Implementation phases (layered build-verify) — 8.9b

#### Phase 8.9b-1 — Audit fix_relations streaming

- **Write** `Audit::stream()` dispatcher. Convert `_fixRelations()` to Generator. Wire `$processed % DEFAULT_PROGRESS_INTERVAL === 0` yields + cancellation poll.
- **Decision**: 8.9b reuses BulkEntries's `DEFAULT_PROGRESS_INTERVAL = 100` but doesn't expose `progressInterval` as a tool input — fix-mode result sets are bounded by the existing `MAX_LIMIT = 1000` or `PROPAGATION_GLOBAL_GAP_CAP = 5000` so the worst-case frame count is bounded. Operators wanting per-row visibility can lower the cap via `limit` and use a 1-frame-per-row mental model (or we add `progressInterval` in a future gate if friction emerges).
- **Convert `execute()`** to collapse for Pro modes only.
- **Write** test cases on `AuditFixModesStreamingTest`: 150-broken-relation fixture (extend the playground seed or create per-test via factory), stream yields ~2 frames, terminal envelope matches existing `AuditFixModesTest::fix_relations` shape plus `cancelled: false`; cancellation between rows produces partial `results`; `execute()` returns the same envelope as `stream()->getReturn()`.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesStreamingTest::fix_relations|AuditFixModesTest'` green. Medium / 25 min.

#### Phase 8.9b-2 — Audit prune_unused_assets + repair_propagation streaming

- **Convert** `_pruneUnusedAssets()` and `_repairPropagation()` to Generator. Same shape as Phase 8.9b-1.
- **Write** test cases: same shape per mode.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='AuditFixModesStreamingTest|AuditFixModesTest'` green. Medium / 25 min.

#### Phase 8.9b-3 — ImportExport import streaming

- **Write** `ImportExport::stream()`. Convert `_import()` to Generator.
- **Convert `execute()`** to collapse for `import` mode only.
- **Write** test cases on `ImportExportImportStreamingTest`: 50-item payload, stream yields 1 frame (50 < default 100), terminal envelope matches `ImportExportImportTest::dry_run` shape plus `cancelled: false`; 250-item payload yields 3 frames; mid-stream cancellation; `execute()`/`stream()` envelope equality.
- **Verify gate**: `ddev exec vendor/bin/pest --filter='ImportExportImportStreamingTest|ImportExportImportTest'` green. Medium / 25 min.

#### Phase 8.9b-4 — Full-suite regression + quality gates

- **Verify**: `ddev exec vendor/bin/pest` full suite green.
- **Verify**: `ddev composer phpstan` clean.
- **Verify**: `ddev composer check-cs` clean.

---

## Verification gates

Layered build-verify cadence per phase above. Final gate sequence:

1. `ddev exec vendor/bin/pest --filter='FiberProgressBridgeTest|ResaveStreamingTest|ResaveTest|AuditFixModesStreamingTest|AuditFixModesTest|ImportExportImportStreamingTest|ImportExportImportTest'` — focused suite green.
2. `ddev exec vendor/bin/pest --filter='StreamingTest|ToolInterfaceInvariantTest'` — existing streaming + tool-interface invariants still green.
3. `ddev composer phpstan` — level 8 clean.
4. `ddev composer check-cs` — ECS clean.
5. `ddev exec vendor/bin/pest` — full suite green (baseline 891 + ~24 new cases ≈ 915).

---

## Manual verification

After the builder reports 8.9a done:

1. **stdio**: `tools/call resave type=entries section=minorHeroes` — same non-streaming output shape as pre-8.9a.
2. **HTTP non-streaming** (admin, `Accept: application/json`): same envelope, no progress frames.
3. **HTTP streaming** (admin, `curl -N -H 'Accept: text/event-stream'`): progress frames stream in real time at ~60Hz cap; terminal envelope carries `total`, `processed`, `succeeded`, `failed`, `cancelled: false`, `results: [/* failures only */]`.
4. **HTTP streaming + `queue: true`**: ToolException with the locked message.
5. **HTTP streaming + `set` / `to`**: ToolException with the locked message; same call non-streaming succeeds.
6. **HTTP streaming, kill `curl` mid-stream**: `cortex_invocations` row carries `kind=cancelled`; partial `results` if any failures were captured; no further DB churn.
7. **HTTP streaming + `notifications/cancelled`** in a second terminal: same outcome as 6.

After 8.9b:

8. **Streaming `content_audit.fix_relations`** with a seeded broken-relation fixture: progress frames stream; terminal envelope's `results[]` matches the non-streaming `execute()` envelope; `cancelled: false`.
9. Same for `prune_unused_assets` and `repair_propagation`.
10. **Mid-stream cancellation** on any audit fix mode: partial `results`, `cancelled: true`, audit row.
11. **Streaming `import_export.import`** with a 250-item dry-run payload: 3 progress frames at the default 100-row interval; terminal envelope matches the non-streaming shape plus `cancelled: false`.

---

## Risks / open follow-ups

- **PHP Fiber unfamiliarity.** No existing `Fiber::` usage in `src/`. The `FiberProgressBridge` is new vocabulary. Mitigation: ship it as a separate isolated file with its own test suite BEFORE wiring into `Resave::stream()`. The bridge is generic and reusable — future "stream around a blocking Craft API" tools (export → CSV, search index rebuild, etc.) get the seam for free.
- **`Fiber::throw()` semantics on cancellation.** `Fiber::throw($exception)` causes the suspended Fiber to throw at the suspension point. Our event listener's `Fiber::suspend()` then throws into the listener, which lets it propagate up to `Elements::resaveElements()`. Craft catches `QueryAbortedException` at `vendor/craftcms/cms/src/services/Elements.php:1677` — verified against source. Other Throwable types would bubble out of `resaveElements()` and break the fail-silently contract — **must use QueryAbortedException specifically**. Documented in `FiberProgressBridge.php` PHPDoc.
- **Terminal envelope shape divergence between streaming and non-streaming `resave`.** Locked decision 16 — `execute()` returns the captured-stdout shape; `stream()->getReturn()` returns the structured shape. Documented in PHPDoc. The LLM-facing tool description should clarify that streaming clients get the structured envelope and non-streaming clients get the captured-output envelope.
- **60Hz emit throttle masking real progress on very fast resaves.** A 10k-entry resave at 1000/sec produces 10s of streaming time with ~600 frames at 60Hz (one frame per ~16 entries). The terminal envelope still carries the true `processed` count. Operators wanting per-element granularity can lower the throttle in a future gate or remove it (the 60Hz cap is a wire-level concern, not a contract guarantee).
- **`continueOnError: true` on `Elements::resaveElements()`.** Picked so per-element failures don't abort the whole streaming run — failures land in the terminal envelope's `failed` count + `results[]`. Matches Craft's own `ResaveController` semantics (line 916 passes `true`). Documented.
- **Audit fix-mode default `progressInterval` not exposed.** Locked decision in Phase 8.9b-1. If real-world LLM friction emerges (operator wants per-row events), add the knob in a future gate. Mirrors the gate-8.7 decision for `bulk_entries::progressInterval` but inverted (BulkEntries exposes it; Audit doesn't, because Audit's row caps are an order of magnitude smaller).
- **`tests/Mcp/ToolInterfaceInvariantTest.php` extension.** Existing test asserts `inputSchemaFor(null) === getInputSchema()` for every registered tool. Adding `StreamableToolInterface` to `Audit` and `ImportExport` does NOT touch their schema surface. The test passes unchanged. **Verify** in the gate — flag if it fails.
- **Test cancellation flakiness.** Cancelling AFTER N elements deterministically requires the cancel to fire between events. The Pest tests should use the `CancellationToken` direct API (`$ctx->getCancellationToken()->cancel()`) before the Nth event — done by pre-counting and arming the cancel inside the event listener via a test helper. The cache-slot path (synthetic `notifications/cancelled`) is exercised via the dispatcher-level test pattern from `tests/Mcp/StreamingTest.php:162-239`.

---

## Out of scope

- **`progressInterval` knob on `resave`.** Not exposed in 8.9a. One frame per element (plus the 60Hz throttle). Future gate.
- **`progressInterval` knob on audit fix modes.** Not exposed. Default 100. Future gate.
- **Streaming on `content_audit` read modes** (`relations`, `unused_assets`, `propagation`). Those return paged arrays — streaming a paged array is over-engineering. Future gate if real demand emerges.
- **Streaming on `import_export.export`.** Same reason — `export` builds the payload in memory; streaming an in-memory array isn't meaningful. Future gate if export payloads grow large enough to need chunking.
- **`set` / `to` / `propagateTo` / `toDefault` / `setEnabledForSite` / `ifEmpty` / `ifInvalid` on the resave streaming path.** Rejected at the entry. Future gate if operators ask for streaming field-rewrite previews.
- **`Resave::execute()` shape parity with `stream()->getReturn()`.** Deliberate divergence locked in 16. Future gate could route both through `stream()` and converge; not 8.9.
- **CP authoring UI for any of these tools.** Phase 3.
- **`Last-Event-ID` SSE resumability** — deferred to Phase 3 per Gate 7 locked decision 14.
