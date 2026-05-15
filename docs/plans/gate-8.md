# Gate 8 — Pro tier write tools, mode unlocks, streaming enablement

Internal planning document. **Not** user-facing reference — for that see [`docs/INSTALL.md`](../INSTALL.md), [`docs/SECURITY.md`](../SECURITY.md), etc.

This is the implementation plan for the Pro-tier write surface that sits on top of the Gate 7 HTTP transport. Each sub-gate is independently mergeable and leaves `develop-v5` green. Hand the brief for the next sub-gate to `craft-feature-builder` when ready to start.

**Source planner output**: produced by `craft-planner` agent on 2026-05-15 against PLANNING.md §4.12 Gate 8 (lines 909–934), §4.4 Edition Model (lines 335–365), §4.5 Free tier (lines 369–461), §4.7 Pro tier (lines 525–595). Locked architectural decisions reference [`.claude/rules/architecture.md`](../../.claude/rules/architecture.md) "Per-user tool visibility" and the Gate 7 contract surfaced in [`docs/plans/gate-7.md`](./gate-7.md).

## Locked decisions

These are settled. Don't relitigate without good reason.

1. **Edition detection mechanism**: Craft's native `Plugin::editions()` + `$edition` property (`vendor/craftcms/cms/src/base/Plugin.php:64`, `:294`). `Plugin::editions(): array` returns `['free', 'pro']` in ascending order; `Plugin::getInstance()->is(self::EDITION_PRO)` is the runtime check. Edition handle is stored in project config (`plugins.cortex.edition`) by Craft itself — no plugin-side license table, no phone-home call. The Plugin Store sets the edition handle on purchase; on a Free install the value is `'free'` and stays there. Rejected: a `Settings::$edition` flag (duplicates Craft's storage), a hardcoded constant gated by a license file (re-implements what Plugin Store already provides), composer-suggest-based detection (fragile and out of band).

2. **`ProToolTrait` carries the default `shouldRegister()` for every Pro tool.** Single trait at `src/tools/ProToolTrait.php` providing `public static function shouldRegister(): bool { return Plugin::getInstance()->is(Plugin::EDITION_PRO, '>='); }`. Mirrors how `CraftExec` already special-cases settings-gating at the handler layer (`src/tools/dev/CraftExec.php:44`) — but for whole-tool removal we want registration-time exclusion, not a friendly error, because Free-tier users should never see the tool in `tools/list` regardless of bearer scope. Settings-gated tools like `craft_exec` keep the at-call rejection; edition-gated tools never register.

3. **Permission boundary map is the per-tool contract.** Each Pro tool declares its required Craft permission(s) in a `_requiredPermissions(array $arguments): array` protected method (returns the list of permission strings the `execute()` arguments imply). `filterFor()` consults the same method with empty arguments to decide whole-tool visibility — a user with `saveEntries:*` on any section sees `entry`; a user with none doesn't. `execute()` re-checks against the resolved arguments before any element mutation, and throws `ToolException` with JSON-RPC code `-32002` ("permission denied", consistent with the existing not-permitted shape) when the permission is absent. Defense in depth per [`.claude/rules/architecture.md`](../../.claude/rules/architecture.md) "Per-user tool visibility" — `tools/list` filtering is for the LLM's tool-selection UX; `execute()` filtering is for security.

4. **Permission mapping per Pro tool**:

   | Pro tool | Mode | Permission(s) required |
   |---|---|---|
   | `entry` | `create` | `saveEntries:{sectionUid}` |
   | `entry` | `update` | `saveEntries:{sectionUid}` |
   | `entry` | `delete` | `deleteEntries:{sectionUid}` (or `deleteEntriesForSite:{sectionUid}` for multi-site delete) |
   | `entry` | `restore` | `saveEntries:{sectionUid}` |
   | `entry` | `apply_draft` | `saveEntries:{sectionUid}` on the canonical |
   | `category` | `create` / `update` | `saveCategories:{groupUid}` (+ `viewCategories:{groupUid}` implicit) |
   | `category` | `delete` | `deleteCategories:{groupUid}` |
   | `tag` | `create` / `update` / `delete` | admin only — Craft 5 has no per-tag-group permission (`vendor/craftcms/cms/src/elements/Tag.php:257-284`). `filterFor()` returns `$user->admin === true` |
   | `address` | `create` / `update` / `delete` on user-owned | `editUsers` on the owner + ownership match |
   | `address` | `list` / `get` | per-owner — same rule as the owning element |
   | `global_set` | update | `editGlobalSet:{globalSetUid}` |
   | `users` | `list` / `get` | `viewUsers` |
   | `users` | `create` | `registerUsers` |
   | `users` | `update` | `editUsers` (+ `administrateUsers` for status/email/password changes) |
   | `users` | `delete` | `deleteUsers` |
   | `bulk_entries` | every mode | per-row `saveEntries:{sectionUid}` (or `deleteEntries:{sectionUid}` for `set_status: deleted` semantics) |
   | `drafts_and_revisions` | `apply` | `saveEntries:{sectionUid}` on the canonical |
   | `drafts_and_revisions` | `discard` | `saveEntries:{sectionUid}` (Craft's own DraftController convention) |
   | `audit` | fix modes | per-element: `saveEntries:{sectionUid}` for relation fixes / propagation; `deleteAssets:{volumeUid}` for asset pruning |
   | `import_export` | `import` | `saveEntries:{sectionUid}` per target section |
   | `diagnostics` | `manage_queue` | `utility:queue-manager` (matches `vendor/craftcms/cms/src/utilities/QueueManager.php:36`) |

   This table is the canonical reference for sub-gate 8.9's permission-boundary test matrix. Builders implementing each sub-gate copy the relevant row into the tool's PHPDoc.

5. **PII gating on the `users` tool**: Free-tier exposure stays at zero PII — the current Free `permissions_and_groups` tool already returns "user groups (no PII)" per PLANNING.md §4.5 line 424, and Free has no `users` tool at all. The Pro `users` tool returns:
   - **Always (in any Pro response)**: `id, uid, username, fullName, active, suspended, pending, locked, admin, lastLoginDate, dateCreated, groupUids[]`.
   - **PII fields (`editUsers` permission required)**: `email, unverifiedEmail, lastLoginAttemptDate, invalidLoginCount`.
   - **Custom fields**: returned ONLY for handles listed in `Settings::$userCustomFieldAllowlist` (string[], default `[]` = no custom fields exposed). Mirrors the command-allowlist pattern: operators explicitly enumerate what's safe to expose, defaults are zero-trust, allowlist is project-config-synced. Native user attributes (the PII map above) are NOT subject to the allowlist — those gating decisions are per-permission. Rationale: Craft 5 has no native per-field-value permission (field-layout-designer hiding is UX-only; values are accessible via `getFieldValue()` regardless of layout config). Defense in depth requires Cortex providing its own gate.
   - **Address relations**: surfaced through the `address` tool, not inlined on the `users` tool.
   - **Deferred to Commerce edition (out of Gate 8 scope per PLANNING.md §4.8)**: order history, customer-lookup fields, stored payment metadata, shipping/billing address listings on the user envelope. The `users` tool returns address relations as `[{id, uid, ownerType}]` references only — full address bodies come through the `address` tool's own permission gate.

6. **Mode-unlock composition contract.** Every Free tool that gains Pro modes (`drafts_and_revisions`, `audit`, `import_export`, `diagnostics`) implements the `inputSchemaFor(?User $user = null): array` contract from Gate 7.4 to filter the `mode` enum based on the resolved user's permissions. For each tool the override:
   - Calls `parent::inputSchemaFor($user)` to get the base schema (a copy of `getInputSchema()`).
   - Walks the `mode` (or `type`) property's `enum` list and removes Pro modes the user can't reach. A user with zero matching permissions on any section sees only the Free modes; an admin sees the full enum.
   - The `stdio` `null`-user path always returns the full enum because PLANNING.md §4.4 and the locked Gate 7 decision 3 set stdio as trusted (local user, no per-request identity).
   - `execute()` re-validates the mode against the caller's permission set and throws `ToolException` with JSON-RPC code `-32002` ("permission denied — mode `apply` requires `saveEntries:{uid}` on section `posts`") when the caller invokes a Pro mode without authority. The error envelope matches Gate 7.4 conventions — consistent shape across whole-tool denial (`filterFor`) and per-mode denial (`inputSchemaFor` + `execute` re-check).

7. **Streaming enablement contract per tool**:

   | Tool | `progress` semantics | `total` known up front? | Frame frequency | Cancellation behaviour |
   |---|---|---|---|---|
   | `resave` | Elements processed | Yes — `Resave::resolveCount()` runs the same query the controller will iterate | After every batch (default 100 elements) | Stops issuing further `resave` actions; in-flight batch completes (matches Craft's `ResaveController` non-killable model). Partial work persists — already-resaved elements stay resaved. |
   | `bulk_entries` | Rows processed | Yes — passed in via `entryIds`/query result | After every row | Stops at the next row boundary. Already-processed rows stay mutated (no per-batch transaction; per-row writes match Craft's own bulk patterns). |
   | `import_export` (`import`) | Input items consumed | Yes — `count($input)` | After every input item | Stops at the next item. Imported items persist; unprocessed items don't. Mirrors `bulk_entries`. |
   | `audit` (fix modes) | Records fixed | Yes — the report from the matching read mode supplies the count | After every fixed record | Stops at the next record. Already-fixed records stay fixed. |

   **Degradation**: every streaming tool MUST work non-streaming. When the client doesn't send `Accept: text/event-stream`, the dispatcher eagerly consumes the generator (per `ToolInterface::execute()`'s `array|\Generator` contract — Gate 7.7) and returns the final value as JSON. Per the locked Gate 7.7 wire shape, streaming is an `Accept`-header upgrade, not a tool capability flag.

8. **Cancellation token forwarding to non-streaming Pro tools.** Pro tools that don't implement `StreamableToolInterface` (`entry`, `category`, `tag`, `address`, `global_set`, `users`) still receive the `InvocationContext` — but cancellation is irrelevant to a single-mutation tool (the operation either completes or doesn't). These tools do NOT need to poll `getCancellationToken()->isCancelled()`. Only the four streaming tools (8.6, 8.7 audit-fix, 8.7 import_export-import, 8.8 resave) cooperate with cancellation.

9. **`bulk_entries` mode shape — combined modes, single tool**: per PLANNING.md §4.7 line 544 — `set_status / update_fields / relate / migrate / scaffold`. Each mode takes a query specification (`{section, entryType, status, search, ids}`) plus mode-specific payload. Per-row permission check (see decision 4) means a user with `saveEntries:posts` only can run a query that includes `news` entries; the dispatcher SKIPS the disallowed rows with a `skipped` entry in the per-row result, never aborts the whole operation. Defense in depth: the per-row check is in `execute()`, not just `filterFor()` — `filterFor()` only requires that the user has `saveEntries:*` on AT LEAST ONE section.

10. **No new composer dependencies in Gate 8.** Everything composes against the Gate 7 surface. The OAuth library landed in 7.3; streaming infrastructure landed in 7.7; permission checking goes through Craft's native `User::can()` API. If a Pro tool genuinely needs a new library (e.g. a CSV parser for `import_export`), bubble it up as a Q for the user before locking — not in this plan.

11. **No CP UI in Gate 8.** Pro tools are exercisable end-to-end through the MCP protocol alone. Token issuance, audit review, and connection reference all stay on the console / curl until Gate 9. Console commands ship where the tool's setup would otherwise need a UI (e.g. `cortex/edition/show` for sanity-checking which edition is active).

12. **Project config sync surface for Gate 8.** No new project-config-stored entities — every Pro tool reads existing Craft project config (sections, entry types, category groups, volumes) and writes elements which live in the DB. The custom-skills element type (Gate 8.5) IS project-config-stored but is a separate gate; Gate 8 does not touch project config beyond the edition-handle field Craft already manages.

13. **Sub-gate sequencing**: 8.1 → 8.2 → 8.3 → 8.4 → 8.5 → 8.6 → 8.7 → 8.8 → 8.9. 8.1 is the foundation everything else depends on. 8.2 is the highest-complexity content tool and seeds the patterns 8.3 / 8.4 reuse. 8.6's streaming work is sequenced after the non-streaming Pro tools to let the streaming infrastructure settle. 8.9 is the cross-cutting test sweep — it runs against every previous sub-gate's surface.

14. **`allowAdminChanges` policy.** When `Craft::$app->getConfig()->getGeneral()->allowAdminChanges === false`, any tool that would mutate admin-only state (project config, schema migrations, plugin install, code-generator scaffolding) MUST refuse. Implementation:
    - **`craft_command` allowlist is split into two arrays.** Existing `Settings::$allowedCommands` shrinks to content-level only: `['resave/*', 'cache/*', 'invalidate-tags/*', 'index-assets/*', 'gc', 'users/create', 'utils/*', 'clear-deprecations', 'mailer/test']`. New `Settings::$adminLevelCommands` carries the admin-level patterns: `['project-config/*', 'migrate/*', 'up', 'make/*', 'entrify/*', 'sections/*', 'fields/*', 'fixture/*']`. The effective allowlist at dispatch time is `$allowedCommands ∪ $adminLevelCommands` when `allowAdminChanges === true`, else `$allowedCommands` only. Pre-ship, so no back-compat to preserve — the existing single-array shape is replaced cleanly.
    - **`craft_exec`** stays stdio-only (Gate 7 locked decision 4); HTTP transport never sees it. No additional gate needed.
    - **Gate 8 Pro tools** — verified content-level per the permission map in decision 4. None require an `allowAdminChanges` gate. The boundary test matrix in 8.9 covers both `true` and `false` states regardless to lock the contract.
    - **Architecture invariant test** in 8.9: under `allowAdminChanges = false`, no tool in `Tools::asListPayload()` admits a command/mode/argument shape that would mutate project config or run a migration. The test exercises both states and asserts the bleed-through is zero.

15. **HARD STOP before Gate 8.5 (custom skills element type).** User has flagged that storing skills in project config (per the current PLANNING.md §4.12 8.5 spec, line 939: "schema (element type config, depth limits) in project config") may not be the right choice — skills are elements, and elements normally live in the DB. **Do not plan or implement Gate 8.5 without first re-opening this storage-location question with the user.** The PLANNING.md spec is provisional on this point and the planner agent for 8.5 must surface it as the first thing to lock. Note in PLANNING.md §4.12 mirrors this flag.

## Sub-gate map

| # | Sub-gate | Complexity | Adds |
|---|---|---|---|
| 8.1 | Edition detection foundation + `ProToolTrait` | Medium | `Plugin::editions()` override, `EDITION_FREE`/`EDITION_PRO` constants, `ProToolTrait`, `cortex/edition/show` console action, edition-gating invariant test |
| 8.2 | `entry` Pro tool (create/update/delete/restore/apply_draft) | Large | `src/tools/content/Entry.php` with five modes, per-section permission gating, `setFieldValues()` integration, validation-error envelope |
| 8.3 | `category`, `tag`, `global_set` Pro tools | Medium | three tools sharing the per-group / per-set permission pattern from 8.2 |
| 8.4 | `address` Pro tool | Medium | `src/tools/content/Address.php` with five modes, per-owner gating, ISO 3166-1 country validation |
| 8.5 | `users` Pro tool + PII gating | Large | `src/tools/system/Users.php`, `editUsers` / `registerUsers` / `deleteUsers` gating, the PII map from locked decision 5 |
| 8.6 | `bulk_entries` Pro tool with streaming | Large | `src/tools/content/BulkEntries.php` implementing both `ToolInterface::execute()` and `StreamableToolInterface::stream()`; per-row permission check; first new Pro tool that uses Gate 7.7's streaming infrastructure |
| 8.7 | Mode unlocks on the four Free tools | Large | `drafts_and_revisions` apply/discard, `audit` fix modes, `import_export` import, `diagnostics` manage_queue. Each gets an `inputSchemaFor()` override + new `execute()` dispatch branches |
| 8.8 | Streaming enablement on `resave` + the new streaming-capable Pro tools | Medium | `Resave` implements `StreamableToolInterface::stream()`; `audit` fix modes and `import_export` import gain the same; per-tool progress contract from locked decision 7 |
| 8.9 | Cross-tier integration tests | Medium | `tests/Architecture/EditionGatingTest.php`, `tests/Integration/PermissionBoundaryTest.php` (every Pro write mode tested for refuses-without-and-succeeds-with), mode-not-permitted error-shape invariant |

## Sub-gate 8.1 — Edition detection foundation

**Goal**: `Plugin::editions()` returns `['free', 'pro']`; `ProToolTrait` gives every Pro tool a one-line `shouldRegister()` override; an architecture invariant test asserts the binding. Everything else in Gate 8 depends on this seam existing.

**New**:
- `src/tools/ProToolTrait.php` — single trait:
  ```php
  trait ProToolTrait
  {
      public static function shouldRegister(): bool
      {
          return Plugin::getInstance()->is(Plugin::EDITION_PRO, '>=');
      }
  }
  ```
  Tools use it via `use ProToolTrait;` after `extends AbstractTool`.
- `src/console/controllers/EditionController.php` — `cortex/edition/show` prints the active edition + the `editions()` array. Useful for sanity-checking misconfigured installs without booting a CP session.
- `tests/Architecture/EditionGatingTest.php` (skeleton — grown by 8.9) — invariant: every class under `src/tools/` using `ProToolTrait` resolves `shouldRegister` to a value that depends on the plugin edition.

**Modified**:
- `src/Plugin.php` — add edition constants and `editions()` override:
  ```php
  public const EDITION_FREE = 'free';
  public const EDITION_PRO = 'pro';

  public static function editions(): array
  {
      return [self::EDITION_FREE, self::EDITION_PRO];
  }
  ```
  Placed after the existing `config()` method.
- `src/models/Settings.php` — split the existing `$allowedCommands` per locked decision 14:
  - `$allowedCommands` (content-level) defaults to `['resave/*', 'cache/*', 'invalidate-tags/*', 'index-assets/*', 'gc', 'users/create', 'utils/*', 'clear-deprecations', 'mailer/test']`.
  - New `$adminLevelCommands` defaults to `['project-config/*', 'migrate/*', 'up', 'make/*', 'entrify/*', 'sections/*', 'fields/*', 'fixture/*']`.
  - New `$userCustomFieldAllowlist = []` (string[]; zero-trust default per decision 5 amendment).
  - Validators on both array settings: `each` is a non-empty string.
- `src/tools/dev/CraftCommand.php` — at dispatch time, compute the effective allowlist as `$settings->allowedCommands` plus `$settings->adminLevelCommands` if `Craft::$app->getConfig()->getGeneral()->allowAdminChanges === true`. Match the incoming command against the effective set; reject with `-32002` otherwise.
- `src/config/cortex.php` reference template — document both new arrays and the `allowAdminChanges` interaction.

**Untouched**: `Tools.php` (the existing `shouldRegister()` loop at line 107 already honours the per-tool override).

**Tests**:
- `tests/Plugin/EditionTest.php` — `Plugin::editions() === ['free', 'pro']`; `Plugin::getInstance()->is(Plugin::EDITION_FREE)` is true on a default install; flipping `$plugin->edition` to `'pro'` makes `is(EDITION_PRO)` true and a `ProToolTrait`-using fixture's `shouldRegister()` return true.
- `tests/Console/EditionControllerTest.php` — `cortex/edition/show` exit code 0; stdout contains the current edition handle.
- `tests/Tools/Dev/CraftCommandAdminChangesTest.php` — per locked decision 14:
  - `allowAdminChanges = true`: a `migrate/up` invocation succeeds (or is at least admitted by the allowlist — actual execution may dry-run); a `make/section` invocation is admitted.
  - `allowAdminChanges = false`: same invocations rejected with `-32002`, error message names the admin-changes config as the reason.
  - `allowAdminChanges = false`: content-level invocations (`resave/entries`, `cache/flush`) still succeed.
  - The effective allowlist exposed via `$tool->getEffectiveAllowlist()` (or equivalent introspection) reflects the union/intersection per the config state.

**Verification gate**: Pest filter `EditionTest|EditionControllerTest|CraftCommandAdminChangesTest` green. `ddev craft cortex/edition/show` prints `free` on the playground.

**Risks**:
- Edition flipping in tests requires `$plugin->edition` assignment plus a project-config-mute helper (see `Craft::$app->getProjectConfig()->muteEvents = true` per [`.claude/rules/testing.md`](../../.claude/rules/testing.md)). Document the helper in the test file's docblock for the next sub-gate's reuse.

## Sub-gate 8.2 — `entry` Pro tool

**Goal**: ship the single `entry` tool with modes `create / update / delete / restore / apply_draft`, gated by per-section permissions per the table in locked decision 4. Acts as the pattern reference for 8.3 / 8.4 / 8.5.

**New**:
- `src/tools/content/Entry.php` — extends `AbstractTool`, uses `ProToolTrait`. Schema: `mode` (enum of five), `id|uid` (for update/delete/restore/apply_draft), `sectionId|sectionUid|sectionHandle`, `entryTypeId|entryTypeUid|entryTypeHandle`, `title`, `slug`, `authorId`, `postDate`, `expiryDate`, `enabled`, `siteId|siteHandle`, `parentId` (structured sections), `fields: {handle: value}` (mapped via `setFieldValues()` per PLANNING.md §4.7 line 538), `propagateTo`, `idempotencyKey` (server-side deduplication via cache for 24h).
- Mode dispatch via `match()` on the validated mode:
  - `create` — `new Entry()`, set attributes + fields, `Craft::$app->getElements()->saveElement()`.
  - `update` — fetch via `Entry::find()->id($id)->one()`, mutate, save.
  - `delete` — `Craft::$app->getElements()->deleteElement()`. Supports `hardDelete: true` toggle.
  - `restore` — `Craft::$app->getElements()->restoreElement()`.
  - `apply_draft` — `Craft::$app->getDrafts()->applyDraft()` on the referenced draft.
- Structured validation errors: when `$element->validate()` fails, return `{success: false, errors: {handle: [messages]}, mode, id}` instead of throwing. `ToolException` is for permission denial / mode misuse, not for content validation failures the LLM should reason about.

**Modified**:
- `src/services/Tools.php` — register `Entry` in `_buildRegistry()`. The `shouldRegister()` boot loop already at line 107 will exclude it on Free installs.

**Tests** (`tests/Tools/EntryTest.php`):
- Free install: `Tools::getByName('entry')` returns `null`; `tools/list` has no `entry` entry.
- Pro install, admin: every mode round-trips against a test section.
- Pro install, restricted user: `filterFor()` returns false when the user has zero `saveEntries:*` permissions; returns true with at least one. `execute(create, sectionUid=X)` throws JSON-RPC -32002 when the user lacks `saveEntries:X`.
- Idempotency key: same key issued twice in a 24h window returns the cached envelope without re-saving.
- Validation envelope: missing required field → `{success: false, errors: {...}}`, exit code 0 (this is not a tool error, it's a payload-shape outcome the LLM can iterate on).
- Multi-site: `propagateTo` propagates per Craft's normal rules; the tool's response carries each site's per-site element id.

**Verification gate**: Pest filter `EntryTest` green. Manual: in the playground (Pro edition), issue a token, `curl` a `tools/call` for `entry` with `mode=create` — entry appears in CP.

**Risks**:
- Field-value coercion: passing `{handle: "matrix-block-id"}` for a Matrix field vs a Lightswitch boolean. Lean on Craft's `setFieldValues()` rather than re-implementing coercion. Document the "we pass through; Craft validates" contract in the tool's PHPDoc.
- `apply_draft` permission semantics: applying requires permission on the canonical, not the draft. Test both paths.

## Sub-gate 8.3 — `category`, `tag`, `global_set` Pro tools

**Goal**: three sibling tools that reuse 8.2's permission-mapping + validation-envelope pattern. Each is small enough to ship together.

**New**:
- `src/tools/content/Category.php` — modes `create / update / delete`. Per-group permission `saveCategories:{groupUid}` / `deleteCategories:{groupUid}`. Supports `parentId` for structured nesting (PLANNING.md §4.7 line 539).
- `src/tools/content/Tag.php` — modes `create / update / delete`. **Admin-only** (Craft has no per-tag-group permission — see `vendor/craftcms/cms/src/elements/Tag.php:257`). `filterFor()` returns `$user->admin === true`.
- `src/tools/content/GlobalSet.php` — single mode (update field values). Per-set permission `editGlobalSet:{globalSetUid}`. Argument schema: `{handle | id | uid, fields: {handle: value}}`.

**Modified**:
- `src/services/Tools.php` — register the three.

**Tests** (`tests/Tools/CategoryTest.php`, `TagTest.php`, `GlobalSetTest.php`):
- Free: each tool absent.
- Pro admin: every mode round-trips.
- Pro non-admin without permissions: `Tag` always invisible; `Category` + `GlobalSet` invisible when the user has zero matching permissions; per-mode denials carry the `-32002` envelope.
- `Category::create` with `parentId` from a different group → validation error envelope (not a hard tool error).

**Verification gate**: Pest filter `CategoryTest|TagTest|GlobalSetTest` green.

**Risks**:
- Tag's admin-only gate is a deliberate compromise — surfacing tag management to non-admins requires a permission extension we won't ship in Gate 8. Documented in the tool's PHPDoc.

## Sub-gate 8.4 — `address` Pro tool

**Goal**: full address CRUD with per-owner permission gating (addresses belong to a User per PLANNING.md §4.5 line 411).

**New**:
- `src/tools/content/Address.php` — modes `list / get / create / update / delete`. Schema: `{mode, ownerId, ownerType (user|commerce-customer|...), countryCode (ISO 3166-1 alpha-2), administrativeArea, locality, postalCode, addressLine1, addressLine2, organization, fullName, firstName, lastName, latitude, longitude}`. `commerce-*` ownerType values are accepted but `filterFor()` rejects them unless Commerce edition is installed (deferred per PLANNING.md §4.8).
- Per-owner permission resolution: `_requiredPermissions()` resolves the owner element, then returns `['editUsers']` if the owner is a User, else the owner's element-class permission contract.

**Modified**:
- `src/services/Tools.php` — register.

**Tests**:
- Free: absent.
- Pro: list/get/create/update/delete against a test user's addresses; ISO country code validation; ownership change attempts (`update` with a different `ownerId`) rejected with `-32002`.

**Verification gate**: Pest filter `AddressTest` green.

**Risks**:
- The country list is large (~250 entries). Don't ship a hardcoded enum; rely on Craft's `Addresses::getCountryRepository()` for validation at execute-time and document `countryCode` as a free-form ISO 3166-1 string in the schema. Saves ~250 lines of schema.

## Sub-gate 8.5 — `users` Pro tool + PII gating

**Goal**: full user CRUD with the PII map from locked decision 5 baked in. Highest-sensitivity Pro tool.

**New**:
- `src/tools/system/Users.php` — extends `AbstractTool`, uses `ProToolTrait`. Modes `list / get / create / update / delete`. Schema: standard query params for `list` (search, status, groupHandle, limit, offset); `id|uid|email|username` for `get`; full user attribute schema for `create` / `update`; `id|uid` for `delete` + optional `transferContentTo`.
- PII redaction in the response builder: a dedicated `_serializeUser(User $user, User $caller): array` method that walks the locked decision 5 fieldmap, emitting only the fields the caller has permission to see.
- Address relations returned as `[{id, uid, ownerType}]` references — not inlined per locked decision 5.

**Modified**:
- `src/services/Tools.php` — register.

**Tests** (`tests/Tools/UsersTest.php`):
- Free: absent.
- Pro non-`editUsers` caller: `filterFor()` returns false; `list`/`get` on a `viewUsers`-only caller returns the non-PII envelope (matches the PLANNING.md §4.5 line 424 "no PII" guarantee that the existing `permissions_and_groups` tool already honours).
- Pro `editUsers` caller: PII fields present.
- `registerUsers`-only caller can `create` but not `update` non-self.
- `administrateUsers` required for `status` / `email` mutations (matches Craft's UserController convention, `vendor/craftcms/cms/src/services/UserPermissions.php:495`).
- `deleteUsers` caller can call `delete`; non-`deleteUsers` -32002.
- `delete` with `transferContentTo` reassigns authored entries (use Craft's `Users::transferContentTo()` API).
- The serializer omits `email` for a `viewUsers`-only caller even when the response shape would have included it for an `editUsers` caller — regression test against PII leak.

**Verification gate**: Pest filter `UsersTest` green.

**Risks**:
- Custom-field-level gating is deferred (Gate 8 returns all custom fields when `editUsers` is held). If a future field-level gate arrives, the `_serializeUser()` method is the seam — document this in the tool's PHPDoc.
- Admin-account creation: a non-admin caller MUST NOT be able to `create` an admin user. Test explicitly.

## Sub-gate 8.6 — `bulk_entries` Pro tool with streaming

**Goal**: first streaming Pro tool. Combines five batch modes from PLANNING.md §4.7 line 544 into a single tool, yields progress per row, gracefully degrades to JSON when the client doesn't ask for SSE.

**New**:
- `src/tools/content/BulkEntries.php` — extends `AbstractTool`, implements `StreamableToolInterface` (the Gate 7.7 interface at `src/tools/StreamableToolInterface.php`), uses `ProToolTrait`. Modes:
  - `set_status` — `{status: enabled|disabled|pending|expired}` per row.
  - `update_fields` — `{fields: {handle: value}}` applied to every match.
  - `relate` — `{targetField, targetIds}` adds relation per row.
  - `migrate` — `{toSectionUid, toEntryTypeUid}` re-saves rows into a different section / entry type (per PLANNING.md §4.7 line 544).
  - `scaffold` — bulk-create N entries from a template payload.
- Query input: `{section, entryType, status, search, ids[], limit}`. Streaming pre-flight: run the query under `count()` first to seed `total` per locked decision 7.
- `execute(array $arguments): array` — synchronous path used when the client doesn't request SSE. Internally delegates to `stream()` and collapses to the final return.
- `stream(array $arguments, InvocationContext $ctx): Generator` — yields `{progress: int, total: int, message: "Processing entry {id}"}` per row, checks `$ctx->getCancellationToken()->isCancelled()` between rows, returns `{success: true, processed: int, skipped: int, errors: []}`.

**Modified**:
- `src/services/Tools.php` — register.

**Tests** (`tests/Tools/BulkEntriesTest.php`):
- Free: absent.
- Pro admin: each mode round-trips against ~100 fixture entries.
- Per-row permission check: caller with `saveEntries:posts` only, query matches entries in both `posts` and `news` → the `news` rows return in the `skipped` list with reason `permission_denied`; the operation does not abort.
- Streaming: invoking `stream()` directly yields N progress frames + a final return. Frame `progress` is monotonically non-decreasing.
- Cancellation: flipping the `CancellationToken` mid-iteration returns early; the partial result includes the rows processed so far.
- Non-streaming degradation: `execute()` produces the same final envelope `stream()`'s return value carries.

**Verification gate**: Pest filter `BulkEntriesTest` green. Manual: `curl -N -H 'Accept: text/event-stream'` against the endpoint running `bulk_entries` against the playground; progress frames visible.

**Risks**:
- Without per-batch transactions, partial-failure recovery is on the caller. Document in the tool's PHPDoc.
- `migrate` mode is the most invasive — moving entries to a new entry type can drop fields. Lean on Craft's own resave semantics (`Entries::saveElement()` with the new entry type) and document the data-loss surface.

## Sub-gate 8.7 — Mode unlocks on the four Free tools

**Goal**: extend `drafts_and_revisions`, `audit`, `import_export`, `diagnostics` with Pro modes per PLANNING.md §4.12 lines 921–924. Each existing tool stays Free at registration time; the new modes are reachable only when the caller's `inputSchemaFor()` exposes them AND the user has the matching permission.

**Modified**:
- `src/tools/workflow/DraftsAndRevisions.php` — add `apply` and `discard` to the mode enum in `getInputSchema()`. New `inputSchemaFor()` override: if Free edition OR `Plugin::getInstance()->is(EDITION_PRO) === false`, strip `apply`/`discard` from the enum. If Pro, walk the user's permissions and strip modes the user can't reach (a user with no `saveEntries:*` sees only `list_drafts` / `list_revisions` / `compare`). New `execute()` branches: `apply` resolves the draft and applies via `Craft::$app->getDrafts()->applyDraft()`; `discard` deletes via `Craft::$app->getDrafts()->discardDraft()`. Permission re-check inside `execute()` — `-32002` on miss.
- `src/tools/workflow/Audit.php` — same pattern. Add fix modes per PLANNING.md §4.12 line 922:
  - `fix_relations` (delete broken outgoing relations on the affected element list).
  - `prune_unused_assets` (delete or hard-delete unreferenced assets in the volumes the caller can write).
  - `repair_propagation` (force-resave elements that lost a per-site copy).
  Each fix mode runs as a generator and is wired up in 8.8 for streaming. For 8.7, the fix-mode bodies land as plain `array` returns; 8.8 converts them to generators.
- `src/tools/workflow/ImportExport.php` — add `import` to the mode enum. `inputSchemaFor()` strips it for Free / non-permitted users. `execute()` branch: parses the import JSON, validates against the matching section's field layout, dispatches one `Craft::$app->getElements()->saveElement()` per item. Dry-run defaults to true per PLANNING.md §4.12 line 923 — explicit `dryRun: false` required to actually write.
- `src/tools/system/Diagnostics.php` — add `manage_queue` to the `type` enum. `inputSchemaFor()` strips it unless the user has `utility:queue-manager` permission. Sub-modes via a nested `action` field: `retry / cancel / release` against the job id.

**Tests**:
- For each tool: Free install, the new modes are absent from `tools/list`'s schema; calling them returns `-32002` via the `execute()` re-check.
- Pro install, restricted user: only the read modes show; calling a Pro mode returns `-32002`.
- Pro install, permitted user: full enum surfaces; new modes round-trip.
- The mode-not-permitted error envelope shape is the same across all four tools (uniform `-32002`, same message template).

**Verification gate**: per-tool Pest filters green. Manual: as a non-admin Pro user, `tools/list` over HTTP shows the Free modes only; flip user to admin, `tools/list` shows the full enum.

**Risks**:
- `repair_propagation` is the most fragile fix mode — propagation bugs are usually a symptom of bad multi-site config, not bad data. Document the "fix what's auditable; report what isn't" rule in the tool's PHPDoc.
- `import` schema discovery: the import payload references field handles. Validate against the target section's field layout before any write, return validation envelope on miss (same shape as 8.2 `entry` validation).
- **Size concern (judgment call for the 8.7 builder)**: mode unlocks on four separate tools may exceed the "single focused builder pass" target. If 8.7 ends up >~600 LoC including tests, split into `8.7a — drafts_and_revisions + diagnostics` (simpler unlocks) and `8.7b — audit + import_export` (the streaming-ready unlocks, paired with 8.8). The split is cheap because the four tools don't share state; the only reason to bundle is the shared `inputSchemaFor()` pattern, which is already documented in this plan once. Builder for 8.7 can make the call after scoping the diff.

## Sub-gate 8.8 — Streaming enablement on the four streaming-capable tools

**Goal**: `resave` (Free), `bulk_entries` (Pro, but 8.6 already implements `StreamableToolInterface`), `audit` fix modes, `import_export` import. After this sub-gate the streaming surface matches PLANNING.md §4.12 lines 927–928.

**Modified**:
- `src/tools/dev/Resave.php` — implements `StreamableToolInterface`. `stream()` runs Craft's `resave/<type>` action under `ConsoleRunner` and pumps progress from the console controller's progress callbacks via Craft's `Console::startProgress()` shim output. Yield frame: `{progress: int, total: int, message: "Resaving entry {id}"}`. Already-resaved elements persist on cancellation (locked decision 7).
- `src/tools/workflow/Audit.php` fix-mode methods — converted from `array` returns to `Generator` returns. Each yield is a fixed-record frame.
- `src/tools/workflow/ImportExport.php` import-mode method — same conversion. Yield frame per input item.

**Tests**:
- `ResaveStreamingTest` — `stream()` yields frames; cancellation short-circuits; non-streaming dispatch (`execute()`) collapses to the same final envelope.
- Per-tool: progress monotonicity; cancellation behaviour matches locked decision 7 (partial work persists).
- `tools/list` over HTTP: `resave` carries the same input schema whether streaming or non-streaming — the `Accept` header determines the wire shape, not the schema.

**Verification gate**: Pest filters green. Manual: `curl -N -H 'Accept: text/event-stream'` against `resave` on a section with ~500 entries, verify the SSE stream pushes ~5 frames at the default batch size of 100; send `notifications/cancelled`, verify the resave stops.

**Risks**:
- `ConsoleRunner` currently captures stdout/stderr post-hoc. The streaming wrap needs to pump output incrementally — likely a new `ConsoleRunner::runStreaming(callable $onProgress)` method or a buffer-flush hook. Audit the current implementation and grow it before wiring the streaming generator.

## Sub-gate 8.9 — Cross-tier integration tests

**Goal**: the test invariant sweep that proves Gate 8 ships green. Mirrors how `tests/Architecture/ConventionsTest.php` ships invariants across Gate 7.

**New**:
- `tests/Architecture/EditionGatingTest.php` (extended from the 8.1 skeleton):
  - Invariant: every class under `src/tools/content/Entry.php`, `Category.php`, `Tag.php`, `Address.php`, `GlobalSet.php`, `BulkEntries.php`, `src/tools/system/Users.php` uses `ProToolTrait`.
  - Invariant: on a Free install, none of the above tools resolve in `Tools::getByName()`.
  - Invariant: explicit `Tools::getByName('entry')` lookup on Free returns `null` — no silent fallthrough to a partial tool.
- `tests/Integration/PermissionBoundaryTest.php` — data-provider table driven from locked decision 4. For every Pro tool × mode:
  - Permitted user → `execute()` succeeds, audit log row records `kind=success`.
  - Non-permitted user → `execute()` raises `ToolException` with JSON-RPC code `-32002`, audit log row records `kind=tool_error` with `errorClass=ToolException`, response shape uniform across all tools.
- `tests/Integration/AllowAdminChangesBoundaryTest.php` — per locked decision 14, drive **every Pro tool AND every Free tool that touches admin-level state** through both states. For each tool:
  - `allowAdminChanges = true`: admin-level commands / modes / arguments are admitted; content-level same.
  - `allowAdminChanges = false`: admin-level invocations are rejected with `-32002` AND audit-logged with `kind=tool_error`; content-level invocations still succeed and audit-log `kind=success`.
  - Bleed-through check: under `allowAdminChanges = false`, walk every entry in `Tools::asListPayload()` and every documented command/mode combination, assert none can mutate project config or run a migration. Synthetic invocation matrix; the test fails if a single combo gets through.
  - Specific surfaces covered today: `craft_command` (admin patterns from `$adminLevelCommands`), and a placeholder slot for Gate 8.5 (deferred — flagged in locked decision 15).
- `tests/Services/UserCustomFieldAllowlistTest.php` — per locked decision 5 amendment, drive the `users` tool's `_serializeUser()`:
  - Default install (`$userCustomFieldAllowlist = []`): no custom field handles appear in the response, even for an admin caller.
  - Set the allowlist to `['phone', 'department']` via project config: only those handles appear; other custom fields are stripped.
  - The allowlist behaviour is independent of caller permission — a custom field NOT on the allowlist is never returned, regardless of `editUsers` / `administrateUsers`.
- `tests/Architecture/ModeErrorShapeTest.php` — invariant on the not-permitted error envelope shape. Every `-32002` response carries `{code: -32002, message: string, data: {required: [string], tool: string, mode: string}}`. The data field is unique to mode-level denial — whole-tool denial via `filterFor()` returns a generic `-32601` "method not found" from `tools/list` filtering.

**Verification gate**: full Pest suite green. PHPStan + ECS green.

**Risks**:
- Data-provider explosion: ~30 Pro modes × ~3 permission scenarios each = ~90 cases. Use Pest's dataset feature so the boundary matrix is declarative — not 90 hand-written cases.

## Per-feature manual verification

After each sub-gate, before moving on:

| After | Manual check |
|---|---|
| 8.1 | `ddev craft cortex/edition/show` returns `free`. Flip project config `plugins.cortex.edition` to `pro`, re-run — returns `pro`. Verify a `ProToolTrait`-using fixture appears/disappears from `tools/list`. **Admin-changes:** in `config/general.php` set `allowAdminChanges = false`; `curl` `tools/call` with `craft_command` + `command=migrate/up` → `-32002`. Flip back to `true`, same call → admitted. `command=resave/entries` admitted in both states. |
| 8.2 | Pro install, admin token: `curl` a `tools/call` for `entry` with `mode=create`, payload for a known section. Entry appears in CP. `mode=delete` with the returned id — entry disappears. As a non-permitted user — `-32002`. |
| 8.3 | Same flow for `category`, `tag`, `global_set`. Confirm `tag` is admin-only by attempting as a non-admin Pro user. |
| 8.4 | Create / update / delete an address tied to a test user; verify a different user's address is not visible without the right `editUsers` posture. |
| 8.5 | As `viewUsers` only: `users get` returns no `email`. As `editUsers`: `email` present. As `deleteUsers`: `users delete` works with `transferContentTo`. Confirm a non-admin caller cannot create an admin. |
| 8.6 | As admin: run `bulk_entries set_status` against ~100 entries with `Accept: text/event-stream`; progress frames stream. Send `notifications/cancelled` halfway — operation stops; processed rows persist. |
| 8.7 | As a `viewEntries` only user: `drafts_and_revisions` lists drafts but `apply` is absent from the schema and returns `-32002` if invoked. As a `saveEntries` user: `apply` works. Same flow for `audit` / `import_export` / `diagnostics`. |
| 8.8 | `resave` over SSE shows progress frames. Cancellation mid-resave stops further batches; already-resaved entries stay resaved. `audit` fix modes and `import_export` import expose progress the same way. |
| 8.9 | `ddev exec vendor/bin/pest` full suite green. `ddev composer phpstan` + `ddev composer check-cs` green. |

## Cross-cutting test strategy

- **Edition flipping in tests**: a single Pest helper sets `Craft::$app->getProjectConfig()->muteEvents = true`, mutates `Plugin::getInstance()->edition`, restores in `afterEach()`. Reused by every Gate 8 sub-gate. Lives in `tests/Pest.php` or a dedicated `EditionHelper` trait — builder for 8.1 decides which.
- **Permission scaffolding**: factory builders for users with scoped permissions per the locked decision 4 table. Reused across every Pro-tool test. Lives in `tests/Factories/UserFactory.php` (extend or add).
- **Streaming tests in Pest**: spin the generator directly (`iterator_to_array($tool->stream($args, $ctx))`) and assert frame count, monotonicity, and the `getReturn()` value. No real HTTP server. Pattern matches Gate 7.7's `StreamingFixtureTool` test.
- **Permission boundary as a data provider**: Pest dataset feeding the matrix from locked decision 4. Adding a new Pro tool means adding a row, not a test case.
- **Audit-log assertions**: every Pro-tool test that completes successfully OR fails with `-32002` asserts the corresponding `cortex_invocations` row exists with the right `kind` (`success` or `tool_error`). Re-uses the Gate 7.5 audit-table invariants.
- **Architecture invariants** (extend `tests/Architecture/ConventionsTest.php`): every class under `src/tools/content/`, `system/Users.php`, `workflow/*` (for mode-unlock tools) implementing or using the right `ProToolTrait` / `StreamableToolInterface` / `inputSchemaFor` contract per locked decisions 2 / 6 / 7.

## Out-of-scope clarifications

Confirmed against PLANNING.md §4.12 and the Gate 7 plan's out-of-scope section:

- **Gate 8 is Pro writes + mode unlocks + streaming enablement.** Nothing else.
- **Gate 8.5** — Custom skills element type. Independent gate, lands separately. The `users` PII story in this gate does NOT touch the skills element type; that integration lives in 8.5.
- **Gate 9** — Minimal CP UI (Tokens / Activity / Connection). Gate 8 ships zero new CP screens. The Pro tools are exercised end-to-end via the MCP protocol.
- **Commerce edition** — Deferred per PLANNING.md §4.8 to `craft-cortex-commerce`. Gate 8 leaves the `ownerType`-keyed branch in `address` open for future expansion but does NOT ship Commerce order / variant / customer tools.
- **License-validation network call** — explicitly NOT introduced. Edition detection is local-only; Plugin Store sets the edition handle at install time; the plugin trusts the resulting value.
- **Custom-field-level gating on the `users` tool** — deferred. Gate 8 returns all custom fields when `editUsers` is held; a future gate may layer per-field visibility.
- **`tools/list_changed` on mid-session permission change** — same documented limitation as Gate 7 (locked decision 12 there). Clients reconnect to refresh after a permission flip.
- **`Last-Event-ID` SSE resumability** — deferred to Phase 3 per Gate 7 locked decision 14.
- **Project-config schema additions** — none in Gate 8 beyond Craft's edition-handle storage. The runtime allowlist table from Gate 6.5 is unchanged; the Gate 7 OAuth + token + invocation tables are unchanged.

## File-path reference (existing code)

Builder agents start by reading:

- `src/Plugin.php` — extend with `EDITION_*` constants and `editions()` (8.1).
- `src/tools/ProToolTrait.php` — new in 8.1; every Pro tool uses it.
- `src/tools/ToolInterface.php` — read-only; the three-method contract is locked from Gate 7.4.
- `src/tools/AbstractTool.php` — read-only; the defaults are inherited.
- `src/tools/StreamableToolInterface.php` — opt-in surface used by 8.6 + 8.8.
- `src/tools/support/InvocationContext.php` — read-only; carries the `CancellationToken` streaming tools poll.
- `src/tools/support/CancellationToken.php` — read-only; `isCancelled()` is the cooperation point.
- `src/mcp/transport/SseEmitter.php` — read-only; the wire layer for streaming.
- `src/services/Tools.php` — register every new Pro tool in `_buildRegistry()`. The `shouldRegister()` boot loop at line 107 already excludes non-Pro tools on Free installs.
- `src/tools/workflow/DraftsAndRevisions.php` — extended in 8.7 with `apply` / `discard` modes.
- `src/tools/workflow/Audit.php` — extended in 8.7 with fix modes.
- `src/tools/workflow/ImportExport.php` — extended in 8.7 with `import` mode.
- `src/tools/system/Diagnostics.php` — extended in 8.7 with `manage_queue` type.
- `src/tools/dev/Resave.php` — extended in 8.8 to implement `StreamableToolInterface`.
- `src/models/Settings.php` — read-only; no new settings needed in Gate 8 (edition is project-config-stored by Craft).
- `tests/Architecture/ConventionsTest.php` — extend with the edition-gating + Pro-trait + streaming-tool invariants.
- `tests/Services/ToolsTest.php` — extend with edition-flipped `getByName()` / `asListPayloadFor()` cases.
- `/Users/michtio/dev/craft-plugin-playground/PLANNING.md` — read-only (source of truth, §4.12 Gate 8 lines 909–934; §4.4 Edition Model lines 335–365; §4.7 Pro Tier lines 525–595).
- `vendor/craftcms/cms/src/base/Plugin.php` — read-only (Craft's edition contract, `editions()` at line 64, `is()` at line 294).
- `vendor/craftcms/cms/src/services/UserPermissions.php` — read-only (canonical permission strings for the locked decision 4 mapping; `saveEntries:*` at line 602, `saveCategories:*` at line 719, user permissions at line 482, asset volume permissions at line 768, `utility:queue-manager` at `vendor/craftcms/cms/src/utilities/QueueManager.php:36`).
