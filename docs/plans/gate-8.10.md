# Gate 8.10 — Cross-tier integration + architecture invariant sweep

Internal sub-plan for the builder. Parent: [`docs/plans/gate-8.md`](./gate-8.md) §8.10 (lines 399–426). Closer for Gate 8 — no new tool behaviour, only test additions that lock the uniform contract surface across the Pro write roster that landed in 8.1–8.9b. After this sub-gate Gate 8 ships green and the locked architectural invariants are mechanically enforced for every future Pro tool.

Baseline at HEAD `2d0d2d7` (Gate 8.9b shipped, branch `gate-8` at `74ec4cd`): **919 passing / 0 skipped / 10 834 assertions**. PHPStan + ECS clean.

Source files consulted (file:line):

- `tests/Architecture/EditionGatingTest.php:50-63` — current `_cortex_pro_tool_classes()` list. Already covers `Entry, Category, Tag, GlobalSet, Address, Users, Skill, BulkEntries, ScaffoldEntries`. Five invariants (ProToolTrait use, `shouldRegister()` Free/Pro split, `getByName()` Free absence, `asListPayload()` Free absence). **This list is current; the 8.10 sweep extends it with the streaming-tool axis, not the Pro-tool axis** (every Pro tool is already enumerated).
- `tests/Mcp/ToolInterfaceInvariantTest.php:31-103` — `filterFor(null) === true` + `inputSchemaFor(null) === static::getInputSchema()` are locked. 8.10 builds on top, doesn't duplicate.
- `tests/Tools/Dev/CraftCommandAdminChangesTest.php:42-253` — every assertion `gate-8.md` §8.10's third bullet would otherwise need to express for `craft_command` is **already done here** for that single tool (admit/reject in both states, audit-log `kind=tool_error`, `Allowlist::getEffective()` lockstep). 8.10's `AllowAdminChangesBoundaryTest` does NOT re-test `craft_command`; it asserts the cross-cutting bleed-through invariant (locked decision 14 wording) over `Tools::asListPayload()` enum walks.
- `tests/Tools/System/UsersTest.php:378-427` — three cases for the custom-field allowlist. The empty-default path is solid; the "handle on the allowlist appears" case is weak — it only proves the serializer doesn't crash on a non-existent handle, not that a real field is conditionally returned. 8.10's `UserCustomFieldAllowlistTest` upgrades coverage to a real custom field (seeded via the playground's user field layout, or a per-test factory), proves the cross-edition invariant (allowlist behaviour is independent of `editUsers`/`administrateUsers`), and locks the permission-independence wording from decision 5.
- `src/mcp/Server.php:1327-1335` — `_toolErrorEnvelope()` wire shape. **There is NO `code: -32002` field on response envelopes**. The shape is `{content: [{type: 'text', text: string}], isError: true}`. `gate-8.md`'s `ModeErrorShapeTest` spec at line 420 saying `{code: -32002, message, data: {required, tool, mode}}` is **wrong for the wire envelope** — the 8.8 planner already documented this in decision 6 update. The invariant is **message-text prefix uniformity**.
- `src/mcp/Server.php:734-745` — dispatch path. `ToolException` from `execute()` → `_toolErrorEnvelope($e->getMessage())` returned as the JSON-RPC result (not as an error). `InvocationLogger::logCall($name, $args, $e, ..., $context)` writes `kind=tool_error` with `errorClass=ToolException`.
- `src/mcp/Server.php:909-913, 945` — streaming dispatch path. Same logCall pattern. `kind=tool_error` for `ToolException` thrown before first yield (mode-level reject). Cancellation token mid-stream writes `kind=cancelled` — separate gate path, NOT in scope here.
- `src/tools/PermissionedToolTrait.php:54-127` — `_assertPermission()` + `_buildPermissionDeniedMessage()` contract. Stdio (`getIdentity() === null`) returns early without check; admin returns early; per-permission iteration throws `ToolException` on first miss.
- `src/tools/ProToolTrait.php` — single trait, `shouldRegister()` returns `Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')`.
- `src/tools/content/Entry.php:666-677` — `permission denied — mode `<mode>` on section `<uid>` requires `<perm>`.`
- `src/tools/content/Category.php:342` — `permission denied — mode `<mode>` on group `<uid>` requires `<perm>`.`
- `src/tools/content/Tag.php:251` — `permission denied — tag operations require admin status. ...` (whole-tool admin gate; no mode-level message)
- `src/tools/content/GlobalSet.php:231` — `permission denied — global_set update on set `<uid>` requires `<perm>`.`
- `src/tools/content/Address.php:338` — `permission denied — mode `<mode>` requires `<perm>`.`
- `src/tools/content/BulkEntries.php:470` — `permission denied — mode `<mode>` requires `<perm>`.`
- `src/tools/content/ScaffoldEntries.php:277` — `permission denied — scaffold_entries requires `<perm>`.`
- `src/tools/system/Users.php:427` — `permission denied — mode `<mode>` requires `<perm>`.`
- `src/tools/system/Skill.php:300` — `permission denied — mode `<mode>` requires `<perm>`.`
- `src/tools/system/Diagnostics.php:267-275` — `permission denied — type `<type>` requires `<perm>`.` (note: `type` not `mode` — Diagnostics's schema field is named `type`)
- `src/tools/workflow/DraftsAndRevisions.php:280` — `permission denied — mode `<mode>` on section `<uid>` requires `<perm>`.`
- `src/tools/workflow/Audit.php:471-477` — `permission denied — mode `<mode>` on <resourceType> `<uid>` requires `<perm>`.` (resourceType ∈ {section, volume, group})
- `src/tools/workflow/ImportExport.php:378` — `permission denied — mode `import` on section `<uid>` requires `<perm>`.`
- `src/tools/workflow/Audit.php:305, 368` / `DraftsAndRevisions.php:213` / `ImportExport.php:273, 323` / `Diagnostics.php:216` — edition denial template: ``<tool>: mode `<mode>` is unavailable on this edition.`` (Diagnostics uses `type` not `mode`).
- `src/tools/support/InvocationLogger.php:81-85, 318-321` — `KIND_SUCCESS = 'success'`, `KIND_TOOL_ERROR = 'tool_error'`, `KIND_CANCELLED = 'cancelled'`. `_resolveKind()` returns `KIND_SUCCESS` on null error, `KIND_TOOL_ERROR` on `ToolException`.
- `src/records/Invocation.php:37, 86` — `errorClass` column, populated by the listener wired in `Cortex::init()`.
- `src/services/Allowlist.php:70-94` — `getEffective()` produces the union under `allowAdminChanges=true`, content-only under false.
- `tests/Pest.php:110-126, 144-155` — `cortex_with_edition()`, `cortex_with_admin_changes()` helpers. Both restore in `finally`.

---

## Locked decisions

### Carried from gate-8.md

1. **Wire shape is `{content: [{type: 'text', text}], isError: true}` per `Server.php:1327-1335`.** The `gate-8.md` §8.10 spec text claiming `{code: -32002, message, data: {required, tool, mode}}` is wrong about the on-the-wire envelope. The numeric `-32002` is a JSON-RPC **error response code** which is what `_errorResponse()` builds at `Server.php:1361-1371` — but `ToolException` from `execute()` does **not** route through `_errorResponse()`. It routes through `_toolErrorEnvelope()` (line 1327) wrapped in a `_successResponse()` (line 1345) per MCP spec ("tool-level errors come back as a successful JSON-RPC response with `isError: true`" — `Server.php:705-708`). 8.8's locked decision 6 update already fixed this in the wording; 8.10's `ModeErrorShapeTest` asserts on **message-text prefix uniformity**, not on a numeric code field.

2. **Two canonical message-prefix templates locked. Every Pro mode (across non-streaming Pro tools, Pro-only modes on Free tools, and streaming Pro tools) emits one of these two on the denial path.**

   **Template A — edition denial** (locked across Free tools with Pro modes):
   ```
   <tool>: mode `<mode>` is unavailable on this edition.
   ```
   Diagnostics variant (schema field is `type`, not `mode`):
   ```
   system_diagnostics: type `<type>` is unavailable on this edition.
   ```

   **Template B — permission denial** (Pro tools + Pro-mode Free tools). Two stable shapes — the trait at `PermissionedToolTrait::_buildPermissionDeniedMessage()` emits the bare-form, and every consumer overrides for a per-tool enrichment. Both start with the literal:
   ```
   permission denied — 
   ```
   followed by tool-specific context. The 8.10 `ModeErrorShapeTest` asserts on this **9-character literal prefix + the trailing ` requires \`...\`` token + a closing period**. Three accepted enrichments documented in this plan (mode-on-resource, mode-only, scaffold-only), each enumerated below.

3. **Permission-denial enrichment table — the canonical prefix-match catalogue 8.10's `ModeErrorShapeTest` walks.** Each Pro mode has exactly ONE acceptable shape:

   | Tool | Schema field | Message format |
   |---|---|---|
   | `entry` | `mode` | ``permission denied — mode `<mode>` on section `<uid>` requires `<perm>`.`` |
   | `category` | `mode` | ``permission denied — mode `<mode>` on group `<uid>` requires `<perm>`.`` |
   | `tag` | — | ``permission denied — tag operations require admin status. ...`` (whole-tool admin gate) |
   | `global_set` | — | ``permission denied — global_set update on set `<uid>` requires `<perm>`.`` |
   | `address` | `mode` | ``permission denied — mode `<mode>` requires `<perm>`.`` |
   | `users` | `mode` | ``permission denied — mode `<mode>` requires `<perm>`.`` |
   | `skill` | `mode` | ``permission denied — mode `<mode>` requires `<perm>`.`` |
   | `bulk_entries` | `mode` | ``permission denied — mode `<mode>` requires `<perm>`.`` (whole-call; per-row failures land in `results[].skipped`, not as ToolException) |
   | `scaffold_entries` | — | ``permission denied — scaffold_entries requires `<perm>`.`` |
   | `drafts_and_revisions` | `mode` | ``permission denied — mode `<mode>` on section `<uid>` requires `<perm>`.`` |
   | `content_audit` (fix modes) | `mode` | ``permission denied — mode `<mode>` on <section\|volume\|group> `<uid>` requires `<perm>`.`` |
   | `import_export` (import) | `mode` (fixed `import`) | ``permission denied — mode `import` on section `<uid>` requires `<perm>`.`` |
   | `system_diagnostics` (manage_queue) | `type` | ``permission denied — type `<type>` requires `<perm>`.`` |

   `Tag` and `scaffold_entries` are the documented exceptions to the mode-keyed format because their permission gate isn't mode-keyed (Tag = whole-tool admin; ScaffoldEntries = single-mode tool). The invariant test acknowledges both as locked deviations.

4. **`ModeErrorShapeTest` is an architecture test, not an integration test.** It does NOT invoke tools end-to-end; it walks the message-builder methods via reflection (or via a constructed empty `ToolException` from each tool's `_buildPermissionDeniedMessage()` call where the API permits) and asserts the prefix shape against the canonical table. The boundary integration test (`PermissionBoundaryTest`) exercises the **actual dispatch path** for the same denials and asserts both the wire envelope and the audit row — but it's not the prefix-shape lock; this test is.

5. **`PermissionBoundaryTest` is a uniform invariant sweep, NOT a duplicate of per-tool tests.** Per-tool tests (`EntryTest`, `CategoryTest`, etc.) already exercise mode-by-mode happy paths plus permission denial for that tool's specific modes. The boundary test:
   - **Dataset-driven**: one row per `{tool, mode, requiredPermission, expectedPrefix}` tuple (locked decision 4 of gate-8.md as the canonical source).
   - **Three scenarios per row**: permitted user → success + audit `kind=success`; non-permitted user → ToolException + envelope shape match + audit `kind=tool_error` with `errorClass=ToolException`; stdio (`null` user) → success (trusted local). The three scenarios are the assertions — the test does NOT re-implement per-tool happy-path coverage.
   - **No per-row routing on `bulk_entries`**: the dataset row for `bulk_entries` is whole-call mode-level (e.g. `mode=set_status` against a query the caller has zero matching `saveEntries` for). The per-row skip semantics are per-tool test territory (`BulkEntriesTest`); 8.10's boundary exercises the whole-tool denial path only.
   - **Pin against per-tool test duplication**: the boundary test's success case **does not assert on tool output shape** — just that `execute()` returned without throwing AND the audit row exists with `kind=success`. The output shape is per-tool test responsibility. This keeps the boundary test focused on the cross-cutting contract.

6. **`AllowAdminChangesBoundaryTest` is the bleed-through invariant — NOT a re-test of `craft_command`.** `tests/Tools/Dev/CraftCommandAdminChangesTest.php` already covers every `gate-8.md` decision 14 behaviour for the only tool that surfaces admin-level state today. 8.10's contribution is the **`Tools::asListPayload()` walk** that asserts the bleed-through invariant for the **whole registry** under `allowAdminChanges=false`. Implementation: walk every tool's `getInputSchema()` recursively, find every `enum` field, assert no enum value matches the admin-bleed predicate (locked decision 7 below). One synthetic invocation matrix; the test fails on a single combo getting through.

7. **Admin-bleed predicate locked.** Under `allowAdminChanges=false`, an enum value is an admin-bleed iff it would mutate project config or trigger a schema migration. Today the only such surface is `craft_command`'s implicit command argument — which is a **free-form string field**, not an `enum`, so the enum walk is a structural future-proof guard rather than a today-state catch. The test ALSO walks the runtime-effective allowlist via `Allowlist::getEffective()` and asserts every pattern in it is content-level (i.e. not a member of `Settings::$adminLevelCommands`). That assertion is the today-state catch. The enum walk is the catch for future tools that might surface admin-level state via an enum (e.g. a `system_diagnostics.type` that grew a `migrate` value, or a `content_audit.mode` that grew a `rebuild_schema` value). The synthetic invocation matrix attempts each enum value against each tool with `allowAdminChanges=false` and asserts no admin-bleed value succeeds.

8. **`Diagnostics.manage_queue` is content-level, NOT admin-level.** Verified against `src/tools/system/Diagnostics.php:215-216` — `manage_queue` is gated by `Cortex::EDITION_PRO` (edition gate) + `utility:queue-manager` permission (per-user gate). Neither is `allowAdminChanges`-coupled. Queue management is operator-level mutability, not schema mutability — failed jobs don't sit in project config. **Locked: `Diagnostics` does not need an `allowAdminChanges` gate.** The 8.10 bleed-through walk crosses Diagnostics's enum (`type: [logs, last_error, deprecations, queue, project_config_diff, manage_queue]`) and confirms none of those values touches project config OR runs a migration. `project_config_diff` reads but doesn't mutate — out of bleed-through scope (the predicate is mutation, not read).

9. **`UserCustomFieldAllowlistTest` covers the cross-edition + permission-independence invariant the locked decision 5 amendment requires.** The existing `UsersTest.php:378-427` proves the empty-default behaviour but not the non-empty path with a real field. The 8.10 test seeds a real custom user field via a per-test field-layout mutation (uses `Craft::$app->getFields()->saveField()` + project-config-mute helper), sets the allowlist to that handle, and asserts:
   - Default empty allowlist → custom fields absent regardless of caller permission tier (admin, `editUsers`, `viewUsers`, stdio).
   - Set allowlist to `['fooField']` → only `fooField` appears regardless of caller permission tier.
   - Other custom fields on the user are NOT serialized even when present.
   - Native attribute serialization is unaffected by the allowlist (allowlist gates custom fields only, not native PII).
   - **The serializer drops handles on the allowlist that don't exist on the user's field layout** — proven separately by the existing `UsersTest::a handle on the allowlist appears...` case. Don't duplicate; reference it.

   **Test scope clarification**: this test is **service-level** (`tests/Services/UserCustomFieldAllowlistTest.php` per gate-8.md §8.10 line 416), not tool-level. The location matches: 8.10's spec puts it under `tests/Services/`. Field-layout mutation cleanup MUST run in `afterEach()` — leak would poison every subsequent UsersTest case.

10. **Streaming-tool boundary semantics — mode-level only.** Per the brief's open question H, the boundary test covers the mode-level reject (denial fires inside `stream()` BEFORE the first yield) for all five streaming tools (`bulk_entries`, `scaffold_entries`, `resave`, `content_audit` fix modes, `import_export.import`). Per-row routing (BulkEntries-style `onPermissionDenied=skip`/`fail`) is per-tool test territory and stays in `BulkEntriesTest`. The boundary assertion for streaming tools: invoke `execute()` with a permission-rejecting argument set; `ToolException` thrown synchronously; audit row `kind=tool_error`. Does NOT need to assert on intermediate yields because the throw happens pre-yield. **Resave is the edge case**: `resave` is a Free tool, not Pro. It doesn't appear in the Pro permission map. The boundary test covers `resave` ONLY through the streaming bleed-through dimension (the `AllowAdminChangesBoundaryTest`'s enum walk includes `resave` because it's in the registry) — not through the Pro permission matrix.

11. **Audit-row write happens via the dispatcher, not via direct invocation.** Per `Server.php:739-740`, the `InvocationLogger::logCall()` lines fire inside the dispatcher's `tools/call` handler. Direct `$tool->execute()` calls in tests skip this — they invoke the tool directly without going through `Server::dispatch()`. The boundary test MUST drive each invocation through `Server::dispatch()` to exercise the audit-row write. Pattern: build a JSON-RPC `tools/call` request envelope, call `$server->dispatch($request)`, snapshot `cortex_invocations` row count + filter by `toolName`. **Reference pattern**: `CraftCommandAdminChangesTest.php:200-253` already does this — manual `InvocationLogger::logCall()` invocation with a synthetic `InvocationContext`. That works too, and avoids spinning the dispatcher. Pick one — **dispatcher path is recommended** because it exercises the actual production wire path (including `_resolveUser()` → `getByNameFor()` → ToolException catch → envelope build → logCall). Manual logCall is acceptable as a cheaper alternative when dispatcher setup is too noisy. Builder decides per-test-case.

12. **`EditionGatingTest.php` is mostly complete.** The 8.10 extension is small: append assertions for the **streaming-tool axis** (locked decision 12 below). The Pro-tool roster axis is already covered — `_cortex_pro_tool_classes()` enumerates all 9 Pro tools (Entry, Category, Tag, GlobalSet, Address, Users, Skill, BulkEntries, ScaffoldEntries) and the 5 invariants iterate them. Don't duplicate.

13. **Streaming-tool invariants — new in 8.10.** The Pro tool roster axis is settled; the streaming dimension isn't. Append a new set of assertions to `EditionGatingTest.php` (or a sibling architecture test) over the canonical streaming-tool list:
    - `BulkEntries, ScaffoldEntries, Resave, Audit, ImportExport, StreamingFixtureTool` — every class implementing `StreamableToolInterface`.
    - Each `stream()` method declares `: Generator` return type and accepts `(array $arguments, InvocationContext $ctx)`.
    - The `execute()` method on streaming tools collapses `stream()` via `getReturn()` for non-streaming dispatch (per the locked `BulkEntries::execute()` reference pattern at `BulkEntries.php:339-351`). Reflection-level assertion: every streaming tool's `execute()` body references `->stream(` AND `->getReturn()` OR the method delegates via `iterator_to_array($this->stream(...))->getReturn()`. **Verify against source before locking the reflection pattern** — the 8.9b builder may have shipped a slightly different collapse helper. Suggested implementation: invoke `execute()` directly with a minimal happy-path argument set per tool, assert the response shape matches what `stream()->getReturn()` carries. This is integration-flavoured and may be over-scope for an Architecture test; if it bloats, move to `PermissionBoundaryTest` or split into a dedicated `tests/Architecture/StreamingTest.php`.
    - On a Free install, `BulkEntries` and `ScaffoldEntries` are absent from `Tools::getByName()` (Pro tools — already covered by the existing Pro-tool invariants); `Resave`, `Audit`, `ImportExport`, `StreamingFixtureTool` remain registered (Free-registered with optional Pro modes).

14. **PHPStan + ECS gate.** Every new test file passes PHPStan level 8 and ECS clean. No new exemptions. Tests reference `Cortex::EDITION_PRO` / `EDITION_FREE` constants, never string literals. Dataset rows use typed arrays with `@phpstan-template`-style PHPDoc for the dataset shape.

---

## File map

**New:**
- `tests/Integration/PermissionBoundaryTest.php` — dataset-driven, ~30 rows × 3 scenarios. ~350 LoC + ~50 LoC of test helpers / factories. Located under `tests/Integration/` per gate-8.md §8.10.
- `tests/Integration/AllowAdminChangesBoundaryTest.php` — bleed-through invariant via `Tools::asListPayload()` walk. ~150 LoC.
- `tests/Services/UserCustomFieldAllowlistTest.php` — service-level invariant with a seeded custom field. ~150 LoC.
- `tests/Architecture/ModeErrorShapeTest.php` — message-prefix uniformity sweep over the locked table. ~120 LoC.

**Modified:**
- `tests/Architecture/EditionGatingTest.php` — append the streaming-tool axis from locked decision 13. ~80 LoC delta.

**Untouched (verify, do not modify):**
- Every `src/` file. Gate 8.10 is test-only.
- `tests/Mcp/ToolInterfaceInvariantTest.php` — sister invariant, not extended here.
- `tests/Tools/Dev/CraftCommandAdminChangesTest.php` — covers `craft_command`-specific allowAdminChanges; 8.10 doesn't duplicate.
- `tests/Tools/System/UsersTest.php` — custom-field allowlist coverage stays; 8.10's service test extends to the cross-permission invariant.
- Every per-tool test (`tests/Tools/Content/*Test.php`, `tests/Tools/System/*Test.php`, `tests/Tools/Workflow/*Test.php`) — boundary test does NOT duplicate them.

**Estimated total LoC**: ~850 across 4 new files + 1 modified. Single gate; no split recommended (well under the ~1200 LoC threshold; tests share a common shape, the build-verify cadence is uniform).

---

## Split recommendation

**Ship as a single gate 8.10.** Estimated ~850 LoC total. Considered split candidates:

| Option | Pro | Con |
|---|---|---|
| **Single 8.10** (recommended) | All four tests share the cross-tier flavour; one builder pass, one verify cadence. ~850 LoC fits comfortably. | None given the scope. |
| **8.10a (architecture) + 8.10b (integration)** | Cleaner conceptual split — architecture tests vs end-to-end dispatch tests. | The architecture tests are tiny (~200 LoC) and depend on no fixture setup; the integration tests dominate. Splitting adds branch/PR overhead without LoC justification. |
| **8.10a (Pro tools) + 8.10b (Free tools w/ Pro modes)** | Independent surfaces, no cross-deps. | Forces two passes over the same `Tools::asListPayload()` walk and the same dataset infrastructure. Worse cohesion. |

Single gate locked.

---

## Locked decisions — addendum

15. **Pest dataset shape**. Locked row format for `PermissionBoundaryTest`:
    ```php
    /**
     * @return array<string, array{tool: string, mode: string|null, requiredPermission: string|null, expectedPrefix: string}>
     */
    function _cortex_permission_boundary_dataset(): array
    {
        return [
            'entry.create' => [
                'tool' => 'entry',
                'mode' => 'create',
                'requiredPermission' => 'saveEntries', // resolved per-section by the test setup
                'expectedPrefix' => 'permission denied — mode `create` on section `',
            ],
            // ... one row per Pro mode from gate-8.md decision 4
        ];
    }
    ```
    Pest invocation:
    ```php
    it('Pro tool mode denies + audits under non-permitted caller', function (array $row) {
        // ... scenario 1: permitted user
        // ... scenario 2: non-permitted user (assert envelope + audit row)
        // ... scenario 3: stdio (null user)
    })->with(_cortex_permission_boundary_dataset());
    ```
    Required-permission resolution: the dataset row carries the bare permission family (`saveEntries`, `saveCategories`, etc.); the test setup resolves the per-section/group/volume UID at fixture time. Use the playground's Marvel seed for fixture entities (`posts` section, `factions` category group, `infinityStones` tag group, `shieldDirective` global set) — already seeded per memory `Marvel playground fixtures seeded`.

16. **User factories for boundary scenarios**. The `UserFactory` doesn't exist yet (per gate-8.md "Cross-cutting test strategy" suggestion, line 447 — but it was never built; per-test factories are inlined throughout the Pro-tool test suite). 8.10 introduces a single lightweight factory `tests/Factories/UserFactory.php` (~80 LoC) with three preset builders:
    - `UserFactory::admin()` — admin user, returns a fresh `User` element via `Craft::$app->getElements()->saveElement()`.
    - `UserFactory::withPermissions(array $permissions)` — non-admin user, assigns the supplied permission strings via `Craft::$app->getUserPermissions()->saveUserPermissions($userId, $permissions)`.
    - `UserFactory::withoutPermissions()` — non-admin user with zero permissions.
    Each preset returns a `User` instance the test asserts against. Cleanup via `afterEach()` snapshotting + hard-delete. **Decision flagged**: builder may decide instead to inline factories per-test if the factory's complexity exceeds its reuse value. Recommendation: ship the factory if the boundary test uses >= 8 distinct scoped users (it will — one per mode × scenario, deduplicated by required permission).

17. **`ModeErrorShapeTest` invocation strategy — reflection over `_buildPermissionDeniedMessage`**. Each Pro tool's `_buildPermissionDeniedMessage()` is `protected`, so direct invocation requires either reflection or a per-tool test subclass. **Locked: reflection** — `new ReflectionMethod($tool, '_buildPermissionDeniedMessage')->setAccessible(true)->invoke($tool, $missingPermission, $arguments)`. The test passes a fabricated permission string and arguments and asserts the returned message matches the canonical prefix per the locked decision 3 table. For `Tag` and `ScaffoldEntries` (no mode-keyed message), the test asserts on the literal whole-message format from the source.

    **Edition denial path** (`<tool>: mode `<mode>` is unavailable on this edition.`) is asserted by invoking the tool's `execute()` with a Pro-mode argument set on a Free edition (via `cortex_with_edition(EDITION_FREE, ...)`) and matching the thrown `ToolException::getMessage()` against the template. One assertion per Free-tool-with-Pro-modes (4 tools: `DraftsAndRevisions`, `Audit`, `ImportExport`, `Diagnostics`).

18. **Audit row assertion pattern**. The boundary test snapshots `InvocationRecord::find()->where(['toolName' => $name])->count()` before and after each dispatch; asserts delta is `+1` AND the most-recent row's `kind` matches the scenario. Cleanup after each row to keep tests independent. Mirrors `CraftCommandAdminChangesTest.php:221-252`.

    **HTTP context required**: `InvocationLogger::logCall()` only writes to the DB when `InvocationContext::$transport === 'http'`. The dispatcher's `_invocationContext()` builder picks transport from `$this->_transport` (stdio = 'stdio'; HTTP = 'http'). The boundary test for the **non-permitted user scenario** MUST drive an HTTP-context dispatch (either set the server's transport to 'http' before `dispatch()` OR call `logCall()` manually with a constructed HTTP context). For the **permitted user scenario** ditto — to assert the success row. For the **stdio scenario** the audit row is NOT written (stdio is trusted; no DB log per gate-7 locked decision); the assertion in that scenario is the absence of a new row + successful return value.

19. **No flake-prone async surface in 8.10.** Every test is synchronous. No streaming yields are exercised; no SSE; no cancellation tokens flipping mid-iteration. The streaming-tool boundary cases test only the pre-yield throw path. This keeps 8.10 deterministic and fast.

---

## Implementation phases (layered build-verify) — 8.10

### Phase 8.10-1 — `ModeErrorShapeTest` (architecture invariant)

- **Write** `tests/Architecture/ModeErrorShapeTest.php`.
- Cases:
  - Walk every Pro tool from `_cortex_pro_tool_classes()` + the four Free tools with Pro modes (`DraftsAndRevisions`, `Audit`, `ImportExport`, `Diagnostics`). Per-tool: invoke `_buildPermissionDeniedMessage()` via reflection with a fabricated `$missingPermission='testperm'` + `$arguments` carrying the mode/uid the tool needs. Assert the returned string matches the canonical prefix per locked decision 3.
  - Walk every Free tool with Pro modes (`DraftsAndRevisions`, `Audit`, `ImportExport`, `Diagnostics`). Invoke `execute()` on a Free install (`cortex_with_edition(EDITION_FREE, ...)`) with a Pro-mode argument set; assert the thrown `ToolException::getMessage()` matches the edition-denial template.
  - Walk every Pro tool + every Free-tool-with-Pro-modes. Verify edition-denial template uniformity across them (same `<tool>: mode `<mode>` is unavailable on this edition.` shape; Diagnostics's `type` substitution noted).
- **Verify gate**: `ddev exec vendor/bin/pest --filter=ModeErrorShapeTest` green. Small / 20 min.

### Phase 8.10-2 — `EditionGatingTest` streaming-tool axis extension

- **Modify** `tests/Architecture/EditionGatingTest.php`. Append:
  - `_cortex_streaming_tool_classes()` helper enumerating `BulkEntries, ScaffoldEntries, Resave, Audit, ImportExport, StreamingFixtureTool`.
  - Invariant: every streaming tool class implements `StreamableToolInterface` (reflection check on `implementsInterface`).
  - Invariant: every streaming tool's `stream()` method declares `Generator` return type + accepts `(array, InvocationContext)` parameters (reflection check on parameter types + return type).
  - Invariant: every streaming tool's `execute()` returns the same terminal envelope shape `stream()->getReturn()` produces (integration-flavoured; pick one happy-path argument set per tool, dispatch both paths, assert structural equality). **If this proves too heavy for an architecture test, defer to PermissionBoundaryTest** — flag the call in the builder phase.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=EditionGatingTest` green. Small / 20 min.

### Phase 8.10-3 — `UserCustomFieldAllowlistTest`

- **Write** `tests/Services/UserCustomFieldAllowlistTest.php`.
- Setup: seed a custom field (e.g. `testCustomField`) on the user field layout via `Craft::$app->getFields()->saveField()` + project-config-mute helper. afterEach cleanup drops the field and restores the layout.
- Cases (per locked decision 9):
  - Default empty allowlist + admin caller → `fields` array is empty.
  - Default empty allowlist + `editUsers`-only caller → `fields` array is empty.
  - Default empty allowlist + `viewUsers`-only caller → `fields` array is empty.
  - Default empty allowlist + stdio (null user) → `fields` array is empty.
  - Allowlist = `['testCustomField']` + admin caller → `fields` array contains `testCustomField`.
  - Allowlist = `['testCustomField']` + `editUsers`-only caller → `fields` array contains `testCustomField`.
  - Allowlist = `['testCustomField']` + `viewUsers`-only caller → `fields` array contains `testCustomField`.
  - Allowlist = `['testCustomField']` + stdio → `fields` array contains `testCustomField`.
  - Allowlist = `['otherField']` (handle not on the user) → `fields` array is empty (regardless of caller).
  - Native PII (email, lastLoginDate) gating behaviour is unchanged — assert `email` present under `editUsers`, absent under `viewUsers`, regardless of allowlist state.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=UserCustomFieldAllowlistTest` green. Medium / 30 min.

### Phase 8.10-4 — `AllowAdminChangesBoundaryTest`

- **Write** `tests/Integration/AllowAdminChangesBoundaryTest.php`.
- Cases:
  - `allowAdminChanges=false`: walk `Tools::asListPayload()`, recursively find every `enum` field across every tool's input schema, assert no enum value would mutate project config OR run a migration. (Predicate: enum value matches `/^(migrate|project-config|sections|fields|entrify)\/.+/` OR equals `'up'`.) Today's registry has no such enum values; the test is a future-proof guard. **Locked**: the test asserts `0` admin-bleed enum values; if a future tool ever grows one, this fails.
  - `allowAdminChanges=false`: assert `Allowlist::getEffective()` contains zero patterns from `Settings::$adminLevelCommands`. Today's catch.
  - `allowAdminChanges=true`: same walk, asserts `Allowlist::getEffective()` contains every pattern from `Settings::$adminLevelCommands`. Sanity check on the union.
  - **Do NOT re-test `craft_command` admit/reject behaviour**: that's covered exhaustively by `CraftCommandAdminChangesTest.php`. The boundary test is cross-cutting; the single-tool test is per-tool.
  - **Do NOT add a Diagnostics gate**: locked decision 8 — `manage_queue` is content-level, not admin-level. The bleed-through walk crosses Diagnostics's `type` enum and confirms no admin-level value.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=AllowAdminChangesBoundaryTest` green. Small / 20 min.

### Phase 8.10-5 — `PermissionBoundaryTest`

- **Write** `tests/Integration/PermissionBoundaryTest.php`.
- **Optionally write** `tests/Factories/UserFactory.php` (~80 LoC) if not deferred per locked decision 16.
- Setup: dataset per locked decision 15. Fixture entities resolved via the Marvel playground seed.
- Cases per row (~30 rows):
  - **Scenario 1 — permitted user**: invoke `Server::dispatch()` (or call `execute()` directly + manual `logCall`) as the row's permitted user; assert no throw, response envelope `isError=false`, audit row inserted with `kind=success`.
  - **Scenario 2 — non-permitted user**: invoke as the row's non-permitted user; assert response envelope `isError=true`, response `content[0].text` starts with the row's `expectedPrefix`, audit row inserted with `kind=tool_error` + `errorClass=ToolException`.
  - **Scenario 3 — stdio (null user)**: invoke with stdio transport context; assert no throw (trusted local user) AND no new audit row.
- **Cleanup**: per-row hard-delete of any created entities; per-row audit-row cleanup.
- **Verify gate**: `ddev exec vendor/bin/pest --filter=PermissionBoundaryTest` green. Large / 40 min.

### Phase 8.10-6 — Full-suite regression + quality gates

- **Verify**: `ddev exec vendor/bin/pest` full suite green (baseline 919 + ~60 new cases ≈ 980).
- **Verify**: `ddev composer phpstan` level 8 clean.
- **Verify**: `ddev composer check-cs` clean.

---

## Verification gates

Layered build-verify cadence per phase above. Final gate sequence:

1. `ddev exec vendor/bin/pest --filter='ModeErrorShapeTest|EditionGatingTest|UserCustomFieldAllowlistTest|AllowAdminChangesBoundaryTest|PermissionBoundaryTest'` — focused suite green.
2. `ddev exec vendor/bin/pest --filter='ToolInterfaceInvariantTest|CraftCommandAdminChangesTest|UsersTest'` — sister invariants still green (8.10 must not regress them).
3. `ddev composer phpstan` — level 8 clean.
4. `ddev composer check-cs` — ECS clean.
5. `ddev exec vendor/bin/pest` — full suite green (≥ 980 cases).

---

## Manual verification

After the builder reports 8.10 done:

1. **Run the focused suite** — every new test passes.
2. **Verify the dataset coverage** — count Pro modes in `_cortex_permission_boundary_dataset()`, cross-reference against `gate-8.md` decision 4's table. Every row must be present. Document the count in the test file's docblock.
3. **Verify the prefix table coverage** — `ModeErrorShapeTest` walks every Pro tool. Cross-reference against `_cortex_pro_tool_classes()` from `EditionGatingTest.php` + the four Free-with-Pro-modes tools. Every class must be asserted.
4. **Inspect `cortex_invocations` after a full suite run** — count rows by `kind`. The boundary test should have introduced equal counts of `success` and `tool_error` rows (per dataset row × 2 scenarios that write rows). Stdio scenarios don't write. **Optional**.
5. **No new PHPStan / ECS exemptions** — diff `.phpstan-baseline.neon` and `ecs.php` against `HEAD~1`; should be unchanged.

---

## Risks / open follow-ups

- **Per-row dataset bloat in `PermissionBoundaryTest`.** ~30 Pro modes × 3 scenarios × 2 audit assertions = ~180 atomic assertions. Pest dataset feature handles this declaratively; the per-test wall time should stay under 10s on the playground (one DB-backed dispatch per row × ~30 rows × 3 scenarios ≈ ~90 dispatches; each ~50ms baseline). Mitigation: if the wall time exceeds 30s in CI, narrow the dataset to one representative mode per tool (locks the invariant just as well) and document the trim.
- **`UserFactory` introduction risk.** No existing factory in the test suite — per-test inline user creation is the current norm. Introducing a factory is a wider testing-architecture decision than 8.10 strictly needs. **Mitigation**: ship factory inside `PermissionBoundaryTest` first as a private helper; only promote to `tests/Factories/UserFactory.php` if it's reused. Defer the broader factory refactor to a follow-up commit.
- **Custom-field-allowlist field-layout cleanup flakiness.** Saving a field via `Craft::$app->getFields()->saveField()` mutates project config. The `afterEach()` cleanup must run regardless of test outcome. Pattern: `try { /* test body */ } finally { /* deleteField + muteEvents */ }`. Reference: `UsersTest.php:393-427` already does this.
- **Reflection on `_buildPermissionDeniedMessage()` may break under PHPStan.** Reflection access to protected methods is type-erased; PHPStan may complain about the return type. **Mitigation**: `/** @var string $message */` assertion before the regex match. Standard reflection-test idiom.
- **`ModeErrorShapeTest` reflection invocation requires fabricated arguments per tool.** Different tools require different argument shapes (`entry` needs `mode + sectionUid`; `tag` needs nothing; `category` needs `mode + groupUid`; etc.). Each row in the test's invocation table carries the arguments the tool's `_buildPermissionDeniedMessage()` expects. **Risk**: future tool refactor to change the expected arguments breaks the test silently because the message builder is forgiving (defaults to `?` when an argument is missing). **Mitigation**: assert on the literal prefix including a fabricated UID — e.g. `permission denied — mode `update` on section `xxxx-yyyy-zzzz` requires `testperm`.` not `permission denied — mode `update` on section `?` requires `testperm`.`. The non-`?` UID confirms the resolution path ran.
- **`gate-8.md` §8.10 spec says `tests/Architecture/ModeErrorShapeTest.php` asserts on a numeric `code: -32002` field that does NOT exist on the wire envelope.** Locked correction surfaced here. Builder must NOT freeze the spec wording as written; the prefix-shape invariant per locked decision 2 above is the actual contract. **Flag to user**: confirm this rewording is acceptable before commit.
- **Coverage gap: `users.transferContentTo` permission boundary**. The Pro permission map (decision 4) covers `users.delete` requiring `deleteUsers` but doesn't enumerate the `transferContentTo` recipient permission. `tests/Tools/System/UsersTest.php:967` already covers this. 8.10's boundary test does NOT extend to it — pinned as per-tool test territory.
- **Coverage gap: `entry.apply_draft` requires `saveEntries:{sectionUid}` on the canonical, not on the draft.** Per gate-8.md decision 4 row. Per-tool `EntryTest.php` already covers this. 8.10's boundary test treats `apply_draft` as one row in the dataset; the canonical-vs-draft distinction is not re-asserted here.
- **HTTP-context plumbing for audit-row writes**. The boundary test must drive HTTP-context dispatches to exercise the `cortex_invocations` write. Two options: (a) construct the JSON-RPC envelope and call `Server::dispatch()` with `$server->setTransport('http')`; (b) call `$tool->execute()` directly + manual `InvocationLogger::logCall()` with a synthetic HTTP `InvocationContext`. **Locked decision 11 above**: dispatcher path recommended; manual is acceptable per-case. Builder picks per row to balance test speed against fidelity.

---

## Out of scope

- **Per-row routing in `bulk_entries`** — `onPermissionDenied=skip`/`fail` semantics. Per-tool test territory (`BulkEntriesTest`).
- **Streaming cancellation mid-iteration on Pro tools.** Streaming-cancellation is `tests/Mcp/StreamingTest.php` territory; 8.10's streaming-tool boundary covers only the pre-yield throw path.
- **`tools/list_changed` on mid-session permission change.** Documented limitation per gate-8.md §"Out-of-scope clarifications" and gate-7 locked decision 12. 8.10 doesn't exercise it.
- **CP UI for token issuance, audit review, or any 8.10 surface.** Phase 3 / Gate 9.
- **Per-field-value permission on `users`.** Deferred per gate-8.md §"Out-of-scope clarifications". 8.10's `UserCustomFieldAllowlistTest` covers the allowlist gate (the only field-level filter in scope).
- **Commerce-tier address ownerType variants.** Deferred per PLANNING.md §4.8. The boundary test treats `address` as user-owner-only.
- **`Last-Event-ID` SSE resumability.** Phase 3.
- **License-validation network call.** Edition detection is local-only; 8.10 doesn't introduce any phone-home.
