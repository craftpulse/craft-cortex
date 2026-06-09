# Cortex — Engineering & Product Review

A pre-Plugin-Store engineering and product review of the Cortex Craft CMS 5 MCP-server plugin, covering the Gate 8 (Pro tier) and Gate 9 (CP UI) surface on branch `gate-9`. Findings below have been adversarially verified; refuted findings were dropped and several blocker/major claims were adjusted down where the evidence did not hold.

## Executive summary

**Status:** nothing in Cortex is released. There is no Plugin Store listing and no public installs — the Free tier is feature-complete on `develop-v5`, Pro is feature-complete on the Gate-7/8 lineage, and the CP UI is mid-build on `gate-9`. This review covers a *branch-resident, unreleased* plugin; "shipped" below means "exists in the codebase," never "available to customers."

**Scope caveat — this pass is strong on security/docs/tests/packaging, but it under-delivers on design quality.** The architecture-coherence dimension verifies the code honors its own *locked decisions* (registry pattern, transport-agnostic core, three-method gating) — it does **not** independently interrogate whether those decisions are the right ones, whether abstractions are over- or under-engineered, or whether the tool/transport/edition model has deeper structural problems. A dedicated architecture & design-quality review is still owed (see the standalone section below once added); treat the "architecturally sound" read as *internally consistent*, not *independently audited as good*.

Within that scope: the core MCP design — transport-agnostic dispatcher, class-per-tool registry, edition gating at registration time, the locked three-method per-user gating contract — is cleanly executed and well-tested against its own contracts. The blockers identified by the raw review largely dissolved under verification: there is no "any token can change a password" hole (authorization holds), Gate 9 being mid-build is the planned state of an in-progress branch, and the missing release tag/version/icon are pre-submission checklist items, not defects.

What remains real and worth fixing before submission is a cluster of **security hardening gaps on the untrusted HTTP/OAuth boundary** and a **documentation drift problem** where the primary extensibility doc teaches a broken interface contract and the operator config doc actively misstates the HTTP transport as unauthenticated.

**Verdict:** Not yet submittable. The first pass found no merge-blocking defects, but the **architecture second pass (`docs/review/ARCHITECTURE.md`) found four GA blockers** — all on the security-critical surfaces (an unredacted audit secret sink, two OAuth boundary gaps, a Skill data-integrity bug), none in the core design. The core architecture is sound; the blockers are implementation gaps on the adversarial/edge paths that the happy-path test suite doesn't cover.

> **Remediation status (updated 2026-06-09):** **all four blockers AND all majors are now fixed and verified** on branch `gate-9-hardening` (`pest` **1077 passed / 0 skipped**, PHPStan L8 + ECS clean). Blockers: `b9868d2` (audit-excerpt + `craft_command` redaction), `3c50ae7` (Skill trashed-handle), `368eff3` (OAuth/`.well-known` `httpEnabled` gate), `2d77cdd` (anonymous OAuth IP throttle), `a139646` (OAuth gc prune + docblock). Majors: OAuth consent CSRF (`8b60310`), Origin fail-closed (`9b0299d`), Skill handle format + immutability (`ca5ef99`, `09a48ea`), elevated-session refuse-over-HTTP via a transport-context seam (`939773b`, `59253cb`), stdio hardening — bounded read / testable framing / fatal-handler / signals+broken-pipe / log-stdout-vector / re-entrancy guard (`f0aa1ea`, `1da3d13`, `4899a94`, `74c00ec`, `5524694`), `FiberProgressBridge` cancel-drain (`bf13a33`), and the doc majors — EXTENDING/CONFIGURATION/tool-counts + doc-drift comments (`3cb5dce`, `43c7f1d`, `fabfb94`, `09f0e4d`). The findings below are preserved as the point-in-time review. **Remaining: the minor/nit tail only.**

**Finding counts:**

| Severity | This doc (first pass) | + Second pass (`ARCHITECTURE.md`) | Combined |
|----------|----------|----------|----------|
| Blocker  | 0 | +4 (1 escalated from this doc's majors) | **4** |
| Major    | 8 | +~10 | **~16** |
| Minor    | 13 | +~7 | **~20** |
| Nit      | 8 | +~2 | **~10** |

> The second-pass deep-dives (OAuth, Skill element, core services, stdio transport) live in `docs/review/ARCHITECTURE.md` with full `file:line` detail and a *Severity reconciliation* section. The four blockers — audit `response_excerpt` redaction, OAuth/`.well-known` `httpEnabled` gate, OAuth anonymous-DoS (unthrottled DCR + no prune), and Skill trashed-handle recreate — are submission blockers and join the punch-list below.

**Top 5 to fix before submission:**

1. **Redact secrets in the persisted audit `responseExcerpt`** (Server.php:751, :944) — durable secret leak on the HTTP path; `craft_command` stdout is the live vector. The naive `redactArray()` fix under-redacts; must also `redactString()` flat values.
2. **Gate the OAuth + .well-known controllers behind `httpEnabled` and throttle DCR/token endpoints** (OauthController, WellKnownController) — always-on unauthenticated surface that the kill switch doesn't cover; DCR row spam + bcrypt CPU amplification.
3. **Fix EXTENDING.md's `ToolInterface` contract** (EXTENDING.md:76-84, 311-324) — documented `shouldRegister()` is non-static and `filterFor()`/`inputSchemaFor()` are missing entirely; copy-paste authors get a fatal signature mismatch and a broken gating mental model.
4. **Fix CONFIGURATION.md's "HTTP transport is unauthenticated" claims** (CONFIGURATION.md:75, 99, 216) — directly contradicts the shipped auth and SECURITY.md; dangerous for an operator toggling `httpEnabled`.
5. **Add the Plugin-Store packaging assets** — `src/icon.svg`, root `LICENSE.md`, `extra.changelogUrl`, and a release tag at cut time; correct SECURITY.md's overstated PII-mutation claims and document the elevated-session known gap.

---

## Findings by severity

| Severity | Dimension | Title | File | Effort |
|----------|-----------|-------|------|--------|
| Major | Security | Audit `response_excerpt` persisted without secret redaction | src/mcp/Server.php:751 | S |
| Major | Security | OAuth endpoints unthrottled and not gated by `httpEnabled` | src/controllers/OauthController.php | M |
| Major | Security | HTTP transport accepts password/email/admin mutations without session elevation | src/tools/system/Users.php:279 | L |
| Major | Documentation / DX | EXTENDING.md documents the wrong `ToolInterface` contract | docs/EXTENDING.md:76-84 | S |
| Major | Documentation / DX | Per-user gating contract (`filterFor`/`inputSchemaFor`) absent from docs | docs/EXTENDING.md:76-87 | M |
| Major | Documentation | CONFIGURATION.md contradicts INSTALL/SECURITY on HTTP auth and audit state | docs/CONFIGURATION.md:75,99,216 | M |
| Major | Plugin Store | No `LICENSE` file despite `license: proprietary` | composer.json:5 | S |
| Major | Open questions | Elevated-session gap ships into Pro tier with contradictory security docs | docs/SECURITY.md:117 | L |
| Minor | Security | Empty Origin allowlist is permissive (warn-only) under `httpEnabled` | src/controllers/McpController.php:373 | S |
| Minor | Test coverage | CP mutation endpoints lack a runtime permission-gate test | tests/Controllers/SettingsControllerAllowlistTest.php:64 | M |
| Minor | Test coverage | No regression anchor for the Users HTTP-elevation gap | tests/Tools/System/UsersTest.php:672 | S |
| Minor | Code quality / Perf | N+1 user lookup in the table-data serializer | src/controllers/SettingsController.php:473 | S |
| Minor | Performance | Full-table load before in-PHP filter/sort/slice | src/controllers/SettingsController.php:166 | M |
| Minor | Performance | `strcmp` sort mishandles null `expiresAt` | src/controllers/SettingsController.php:190 | S |
| Minor | Documentation | Tool counts disagree across README, INSTALL, TOOLS.md | docs/TOOLS.md:6 | S |
| Minor | Documentation | Known elevated-session security gap undocumented in SECURITY.md | docs/SECURITY.md:22 | S |
| Minor | Documentation | EXTENDING.md prompt prefix / URI scheme namespaces not disambiguated | docs/EXTENDING.md:184,333 | S |
| Minor | Documentation | CP UI documented as two sections; tabbed layout shipped | docs/CONFIGURATION.md:114-130 | M |
| Minor | DX | Documented `-32602` rejection contradicts locked tool-error envelope | docs/EXTENDING.md:324 | S |
| Minor | Naming / UX | "fnmatch semantics" leaks PHP function name into admin instructions | src/templates/_cp/settings.twig:65 | S |
| Minor | Plugin Store | `minimum-stability: dev` ships to consumers | composer.json:28-29 | S |
| Minor | Plugin Store | No release git tag for 5.0.0 | n/a (git) | S |
| Minor | Plugin Store | `extra.changelogUrl` missing | composer.json:66-73 | S |
| Minor | Plugin Store | No plugin icon (`src/icon.svg`) | src/ | S |
| Minor | Open questions | Commerce edition referenced widely but never declared | src/Cortex.php:174 | M |
| Minor | Open questions | No release version cut; CHANGELOG missing Gate 8/9 entries | composer.json:2 | S |
| Minor | Open questions | Connection-tab client config blocks are hardcoded and dated | docs/plans/gate-9.md:206 | S |
| Minor | Open questions | Skill element type has no drafts/revisions and is non-localized | src/elements/Skill.php:34 | S |
| Nit | Test coverage | `createdBy` null branch untested | src/controllers/SettingsController.php:470 | S |
| Nit | Code quality | Stale sub-gate references after the 9.2/9.5 swap | src/templates/_cp/tokens.twig:7 | S |
| Nit | Code quality | Global document click listener not Garnish-managed | src/templates/_cp/allowlist.twig:156 | S |
| Nit | Code quality | Sort test stubs a flat-key param instead of nested shape | tests/Controllers/SettingsControllerAllowlistTest.php:275 | S |
| Nit | Performance | `filterFor` loops all sections per non-admin HTTP request | src/tools/content/BulkEntries.php:317 | S |
| Nit | Security | Stale `-32002` references in CraftCommand comments | src/tools/dev/CraftCommand.php:151 | S |
| Nit | DX | Generator stub `execute()` returns `[]` with no shape guidance | src/generator/Tool.php:165-168 | S |
| Nit | DX | Generator omits the `#[Title]` attribute the doc example uses | src/generator/Tool.php:138-139 | S |
| Nit | Naming / UX | "Runtime override TTL" exposes internal lifecycle vocabulary | src/templates/_cp/settings.twig:54 | S |
| Nit | Naming / UX | Slideout "surfaces in the audit history" uses passive internal phrasing | src/templates/_cp/_allowlist-override-slideout.twig:49 | S |

---

## Architecture coherence

The locked architecture holds. The MCP core is transport-agnostic (`src/mcp/Server.php` is the JSON-RPC dispatcher; stdio and HTTP transports translate to/from a common shape), every tool is a class behind a shared interface registered through `Tools`, and edition gating runs at registration time via static `shouldRegister()` rather than inside handlers. The three-method per-user gating contract (`shouldRegister` static boot-time, `filterFor` per-request hide, `inputSchemaFor` per-request schema rewrite) is implemented as designed in `ToolInterface`, `AbstractTool`, `ProToolTrait`, and `PermissionedToolTrait`, and the HTTP dispatcher consults the per-user variants on every `tools/list`/`tools/call`. `craft exec` is correctly stdio-only and console commands dispatch through Craft's internal runner — no `Process`/`exec()` anywhere.

The one coherence concern that surfaced is not in the code but in the doc surface that describes it (see Documentation and Developer experience): the public extensibility doc misrepresents the very interface contract the architecture rules call "locked," which means third parties cannot correctly implement against an otherwise-clean design.

**Strengths**

- Tool registry built once in `Tools::init` (services/Tools.php:104-135); no per-request HTTP rebuild.
- First-registration-wins collision handling logged via `RegistryLog` (services/Tools.php:123-130, log call at :129) rather than silent shadowing or hard failure — so a third-party tool can never shadow a bundled one, and the author sees why in Craft's log.
- Defense-in-depth on writes: `execute()` re-checks permission regardless of `tools/list` filtering; both fail closed.
- `editions()` returns `[EDITION_FREE, EDITION_PRO]` ascending with documented handles, honoring the `is($edition, '>=')` index-walk semantics (src/Cortex.php:172).

No architecture-level findings beyond those carried in other sections.

---

## Security posture

The per-permission and admin-protection gates on the tool layer are thorough and the transport boundary is treated as the security boundary. The remaining gaps are concentrated on the untrusted HTTP/OAuth surface: a persisted secret leak in the audit excerpt, an OAuth controller surface the kill switch doesn't cover, and a missing session-elevation layer for sensitive user mutations. All three are real, verified, and should-fix-before-submission — none is a blocker given `httpEnabled` defaults to false and token issuance is admin-gated.

**Strengths**

- Args are redacted before persistence: `InvocationLogger::buildEntry()` runs `SecretRedactor::redactArray($arguments)` before encoding.
- Admin-protection gate on the users tool is correctly implemented and narrowly scoped (Users.php:641-647); non-self password changes require `administrateUsers` (Users.php:1038-1075), so authorization holds even though session elevation does not.
- `httpEnabled` defaults to false and stdio invocations do not write to the audit table — edition/transport gating is honest.

### Major — Audit `response_excerpt` persisted without secret redaction (drift from locked plan)

`src/mcp/Server.php:751` (streaming twin at :944) — *Verified confirmed.*

The audit payload is built as `json_encode($result, ...)` with no `SecretRedactor` pass, while args on the same path *are* redacted. `InvocationLogger::_excerptResponse()` (InvocationLogger.php:297-309) only byte-truncates; `Invocations::record()` persists it verbatim into the `responseExcerpt` column. The Gate-7 plan (docs/plans/gate-7.md) specifies this column as the first ~2KB **redacted** — the implementation honored the qualifier on args and dropped it on the response. Live vector: `craft_command` returns raw `$result['output']` (console stdout) verbatim, carries no `IsStdioOnly` attribute, and is invocable over the untrusted HTTP transport, so a console route dumping env/config lands secrets in the persisted forensic DB. No test asserts excerpt redaction.

**Fix:** Redact `$result` before `json_encode` at both Server.php:751 and :944 (or inside `_excerptResponse()`). Note: `SecretRedactor::redactArray()` alone is insufficient — it redacts only secret-*keyed* entries, and `craft_command`'s value lives under the non-secret key `output`. Also run `SecretRedactor::redactString()` (SecretRedactor.php:104) over flat string values, or over the final encoded payload. Add a test asserting a secret-keyed result field and a secret-in-stdout case are both redacted. **Effort: S.**

### Major — OAuth endpoints are unthrottled and not gated by `httpEnabled`

`src/controllers/OauthController.php` (and `WellKnownController.php`) — *Verified confirmed.*

`RateLimiter::consume()` runs only in `McpController::beforeAction()`. `OauthController` has no `beforeAction()` override and no `httpEnabled` check; `WellKnownController` likewise. Routes are registered unconditionally (PluginTrait::_registerUrlRules), and the docblock claiming "the controller refuses every request when `httpEnabled` is false" is true only for `cortex/mcp`, false for the `oauth/*` and `.well-known/*` routes beside it. With `dcrEnabled = true` by default (Settings.php:200), `/oauth/register` is an always-on unauthenticated surface: `Oauth::registerClient()` inserts a row per valid anonymous POST with no cap and runs bcrypt (`password_hash`) per registration — row spam plus CPU amplification. `/oauth/token` and `/oauth/revoke` are unthrottled (PKCE/secret probing). Held at major (not blocker) because `httpEnabled` defaults off, so the live blast radius is DCR-row spam and CPU amplification, not a path into protected resources.

**Fix:** Add an `httpEnabled` 503 gate in `OauthController::beforeAction()` and `WellKnownController` mirroring `McpController`. Throttle `actionToken`/`actionRegister`/`actionRevoke`. Note the current `RateLimiter::consume(int $userId, ...)` is keyed strictly on an int Craft user id — these endpoints have no authenticated user, so a string-keyed (client_id/IP) path must be added; it is not a drop-in. Consider `dcrEnabled = false` as the safer default. Add `httpEnabled=503` and rate-limit tests for the OAuth surface (currently only `dcrEnabled=false` is covered). **Effort: M.**

### Major — HTTP transport accepts password/email/admin mutations without session elevation

`src/tools/system/Users.php:279` (anchor; vulnerable code at :1021-1022 and :655/681) — *Verified confirmed; known, documented gap.*

`McpController` resolves a userId from a bound bearer/OAuth token and sets it as the Craft identity, then dispatches to the Users tool. Craft's CP wraps self-email, self-password, and admin-toggle mutations in `requireElevatedSession()` (proof-of-presence re-auth); the MCP HTTP path has no equivalent — a grep for `elevat|getHasElevatedSession|requireElevatedSession` finds only docs. The permission gates remain intact (admin caller required for `admin`; `administrateUsers` for non-self password), so this is a single missing re-auth layer, not an authorization hole. The gap is explicitly documented in the class docblock (Users.php:122-129).

**Fix:** Before submission, either refuse `newPassword`/email-change/admin-toggle modes over HTTP until an elevation mechanism exists, or require a short-lived elevation claim/scope on the token for these modes. Track as a release-blocking follow-up gate, not an open-ended TODO. **Effort: L.**

### Minor — Empty Origin allowlist is permissive (warn-only) while `httpEnabled` is toggled independently

`src/controllers/McpController.php:373` — *Unverified (minor).*

When `allowedOrigins` is empty, `_passesOrigin()` logs a warning and returns true, relying on bearer auth as the backstop. The MCP-spec DNS-rebinding defense is then absent wherever an operator enabled `httpEnabled` without configuring origins, and the soft-landing is a log line nobody may read.

**Fix:** Require a non-empty `allowedOrigins` (or an explicit `allowAnyOrigin` opt-in) when `httpEnabled=true` outside devMode, failing closed. At minimum surface the misconfiguration in the CP Connection tab once it ships. **Effort: S.**

### Nit — Stale `-32002` references in CraftCommand comments

`src/tools/dev/CraftCommand.php:151,226` — *Unverified (nit).*

Comments describe returning a precise `-32002` rejection, but tool errors no longer carry a numeric code (locked decision: message-text envelopes). The code throws `ToolException` correctly; only the comments are stale and could mislead a future reader into re-introducing the code field `ModeErrorShapeTest` forbids.

**Fix:** Update the two comments to describe the message-text rejection. **Effort: S.**

---

## Test coverage

Coverage on the new Gate 9 branches is strong (persistence, 2D-to-1D flattening, locked row tuple, search/sort/pagination, expired/null-expiry, 400/404 paths). The gaps are all minor/nit: the controller harness no-ops the auth gates, so permission ordering is only checked by static regex, and there is no regression anchor for the Users elevation gap.

**Strengths**

- `actionSave` persistence and `allowedCommands` flattening tested (SettingsControllerScaffoldingTest.php:276,334).
- Search, sort, pagination, expired/null-expiry, and 400/404 paths covered for the allowlist table-data endpoint.
- `cortex_with_pro_registry()` helper correctly rebuilds the registry for Pro-tool dispatcher tests.

### Minor — CP mutation endpoints lack a runtime permission-gate test

`tests/Controllers/SettingsControllerAllowlistTest.php:64` — *Unverified (minor).*

The harness no-ops `requireAdmin`/`requirePostRequest`, so no test drives override actions as a real non-admin; gate ordering is verified only by static regex.

**Fix:** Add integration tests POSTing add/remove override as a non-admin asserting `ForbiddenHttpException`. **Effort: M.**

### Minor — No regression anchor for the Users HTTP-elevation gap

`tests/Tools/System/UsersTest.php:672` — *Unverified (minor).*

Write-mode tests never assert an elevation requirement, so the (intentional, documented) gap is silently untested and would not flag if the deferral is ever resolved or regressed.

**Fix:** Add a todo/skipped test naming the unbuilt elevated-session model so the gap is visible at the test boundary. **Effort: S.**

### Nit — `createdBy` null branch untested

`src/controllers/SettingsController.php:470` — *Unverified (nit).*

The `createdBy` null branch (line 474, deleted issuing user) is uncovered; tests cover null `userId` and a valid user only.

**Fix:** Add a test with a non-existent `createdByUserId` asserting `createdBy` is null. **Effort: S.**

---

## Code quality

The Gate 9 CP UI diff adheres closely to the project standards: no `switch`, no `declare(strict_types=1)`, underscore-prefixed privates, `match` for value-mapping, early returns, curly brackets always, thorough PHPDoc/`@throws` chains, section banners on every concrete class, and the correct `DateTimeHelper`-in-controller vs `Carbon`-in-service split. Findings are minor/nit; nothing blocks merge.

**Strengths**

- Total standards compliance with only four justified `@phpstan-ignore` directives.
- Rationale comments explaining the route prepend (redirect-loop avoidance) and the 2D `editableTable` flattening (SettingsController.php:314).
- `createdByUserId` serialization handles both int (asArray) and digit-string (toArray) forms (SettingsController.php:472).

### Minor — N+1 user lookup in the table-data serializer

`src/controllers/SettingsController.php:473` — *Unverified (minor).* See the Performance section for the consolidated treatment; harmless at documented dozens-scale but a per-row query in a paginated loop. **Fix:** batch-fetch distinct `createdByUserId` values in one query and map. **Effort: S.**

### Nit — Stale sub-gate references after the 9.2/9.5 swap

`src/templates/_cp/tokens.twig:7`, `CortexCpAsset.php:20` — *Unverified (nit).* `tokens.twig` and the asset docblock reference 9.2/9.3, but commit 2343b3e swapped Tokens to 9.5; `allowlist.twig:9` already reflects the swap. **Fix:** update the numbers to Tokens = 9.5. **Effort: S.**

### Nit — Global document click listener is not Garnish-managed

`src/templates/_cp/allowlist.twig:156` — *Unverified (nit).* A raw `document.addEventListener('click')` is never removed; no leak under full-page CP routes but diverges from Garnish auto-clean and would duplicate if included twice. **Fix:** scope to `adminTable.$container` or document the per-page-load assumption inline. **Effort: S.**

### Nit — Sort test stubs a flat-key param instead of VueAdminTable's nested shape

`tests/Controllers/SettingsControllerAllowlistTest.php:275` — *Unverified (nit).* The test injects `sort.0.field` flat; VueAdminTable sends `sort[0][field]`. Craft's dot-path `getParam` makes production correct, but the test would pass even with a wrong read. **Fix:** store params in the nested shape. **Effort: S.**

---

## Performance

Performance is in good shape. Streaming tools use pre-flight `COUNT`, row caps, `each(100)` cursor iteration, bounded failure windows, and SSE throttling; `ElementSerializer` guards N+1 by stubbing non-eager `ElementQuery` values; the tool registry builds once; gc prunes are batched and indexed. The only new surface is the Allowlist table-data endpoint, acceptable at dozens of overrides but worth fixing before the same pattern reaches the unbounded `cortex_invocations` table in the planned Activity/Tokens endpoints.

**Strengths**

- BulkEntries: pre-flight COUNT, 10k-row cap, `each(100)` cursor, per-row cancellation (BulkEntries.php:865-946).
- Resave: sliding 100-row failure window plus 60Hz SSE throttle (Resave.php:381-387).
- ElementSerializer stubs non-eager ElementQuery values to avoid N+1 (ElementSerializer.php:163-178).
- gc prunes bounded and indexed (Allowlist.php:215-239).

### Minor — N+1 user query per row in allowlist table-data

`src/controllers/SettingsController.php:473` — *Unverified (minor).* `_serializeOverrideRow` runs a `User` query per row across the page slice (up to ~50-100/page); the pattern will be copied into the Activity/Tokens endpoints against the unbounded `cortex_invocations` table. **Fix:** batch-fetch distinct `createdByUserId` once with `indexBy(id)` and look up from the map. **Effort: S.**

### Minor — Full-table load before in-PHP filter/sort/slice

`src/controllers/SettingsController.php:166` — *Unverified (minor).* `getAllOverrides(includeExpired: true)` loads all non-deleted rows (including unreaped expired ones) then filters/sorts/slices in memory per request with no guard. **Fix:** exclude expired rows by default or push pagination into the query before reusing the pattern for the unbounded Activity endpoint. **Effort: M.**

### Minor — `strcmp` sort mishandles null `expiresAt`

`src/controllers/SettingsController.php:190` — *Unverified (minor).* `usort` uses `strcmp` on stringified columns; null `expiresAt` sorts as empty string, so never-expiring overrides sort as if expired at the epoch. **Fix:** move ordering to SQL `ORDER BY` with NULLS-aware semantics when pagination moves to the query layer. **Effort: S.**

### Nit — `filterFor` loops all sections per non-admin HTTP request

`src/tools/content/BulkEntries.php:317` — *Unverified (nit).* `BulkEntries::filterFor` loops `getAllSections` checking `saveEntries` per request; cheap now but repeats per tool. **Fix:** if more section-scoped Pro tools land, memoize a single has-any-`saveEntries` check per request. **Effort: S.**

---

## Documentation

The docs are strong on breadth and craft — INSTALL.md is exhaustive and accurate for the Gate-8 HTTP/OAuth/bearer/SSE surface, EXTENDING.md is well-organized for a third-party author, and the auto-generated catalogs (TOOLS/PROMPTS/RESOURCES) are a smart sync pattern. The problem is drift between hand-written docs and the code as it advanced through Gates 8 and 9. Two of the original "operator can't find the audit log / five-tabs" findings were dropped or downgraded after verification: the Activity, Tokens, and Connection tabs are "coming soon" placeholders, not shipped functionality, so documenting them as operator workflows would advertise vaporware.

**Strengths**

- INSTALL.md accurately documents bearer-token issuance, the full OAuth 2.1 PKCE flow with audience binding (RFC 8707), SSE wire format and cancellation, and per-client config for all clients with strong troubleshooting.
- SECURITY.md audit-logging section is precise about redaction and includes a responsible-disclosure process.
- Auto-generated catalogs with a documented `cortex/docs/*` refresh command keep the reference surface mechanically in sync.

### Major — CONFIGURATION.md contradicts INSTALL.md and SECURITY.md on HTTP auth and audit state

`docs/CONFIGURATION.md:75,99,216` — *Verified confirmed.*

CONFIGURATION.md still describes the HTTP transport as pre-auth scaffolding: line 75 ("auth … land across sub-gates 7.2-7.6 before it is production-ready"), line 99 ("exposes the JSON-RPC dispatcher … with no authentication in front — the transport currently allows anonymous access"), and line 216 framing the `cortex_invocations` audit table and CP UI as a future "Phase 2 adds." All three shipped: INSTALL.md documents bearer + OAuth 2.1, the migrations create the auth/audit/oauth tables, and SECURITY.md states every request authenticates first. An operator reading CONFIGURATION.md while toggling `httpEnabled` would dangerously believe `/cortex/mcp` is anonymous. (Note: the auth/audit/oauth tables are created by the numbered `m260514_*` migrations, not `Install.php` as one supporting note claimed — conclusion unaffected.)

**Fix:** Rewrite the `httpEnabled` note and HTTP transport section to state bearer + OAuth 2.1 auth is enforced on every request (cross-link INSTALL.md and SECURITY.md:22). Reframe line 216 to document the shipped `cortex_invocations` table. **Effort: M.**

### Minor — CP UI documented as two sections; tabbed layout shipped

`docs/CONFIGURATION.md:114-130` — *Verified, adjusted major → minor.*

CONFIGURATION.md describes the CP page as "two sections" (Defaults, Runtime overrides), while `_layout.twig:32-37` renders five tabs. But three of the five (Connection, Tokens, Activity) are explicit "coming soon" placeholders, and the two with real behavior (Settings ↔ Defaults, Allowlist ↔ Runtime overrides) are already documented. The genuine gap is narrow: the page is now tabbed, not two-sectioned, and an operator will see placeholder tabs the docs don't mention.

**Fix:** Reframe "two sections" as a tabbed page and note the forthcoming placeholder tabs. Full per-tab docs belong with the gates that build each tab's real content (do not cross-link the Connection tab to per-client config that doesn't exist until Gate 9.4). **Effort: M.**

### Minor — Tool counts disagree across README, INSTALL, and TOOLS.md

`docs/TOOLS.md:6` — *Unverified (minor).* TOOLS.md reports "Total tools: 37" (auto-generated against a Pro-enabled environment), README says "33 tools", INSTALL says `tools/list` "returns 32 entries". The Free count is one number; the catalog ran against Pro while hand-written docs are stale, and 32-vs-33 is itself internally inconsistent. **Fix:** decide the canonical Free-tier count, reconcile README and INSTALL, and either filter TOOLS.md generation to the Free registry or label it as including Pro tools. **Effort: S.**

### Minor — Known elevated-session security gap is undocumented in SECURITY.md

`docs/SECURITY.md:22` — *Unverified (minor); corroborated by the Open-questions verdict that SECURITY.md lacks any `elevat` mention.* SECURITY.md asserts the HTTP transport is fully gated per-user but says nothing about the missing elevation step for sensitive user mutations. **Fix:** add a "Known limitations" note stating that password/email/admin mutations are not yet behind an elevated-session check on HTTP, with guidance to restrict token scope/issuance until it lands. **Effort: S.**

### Minor — EXTENDING.md prompt prefix / URI scheme namespaces not disambiguated

`docs/EXTENDING.md:184,333` — *Unverified (minor).* Bundled prompts use `craftcms_*` (underscore) while resource schemes use `craft-skills://` (hyphen); the doc never states the reserved Pro prompt prefix is `custom_` vs the reserved resource scheme `custom-skills://`, which a third-party author could conflate. **Fix:** add one sentence clarifying the two reserved namespaces are distinct. **Effort: S.**

---

## Plugin Store readiness

The `composer.json` is well-formed for a `craft-plugin` (correct `type`, PHP `>=8.2`, `craftcms/cms ^5.0`, complete `support` block, full `extra` block, descriptive keywords). `editions()` is correct. CHANGELOG follows Keep a Changelog with a dated `[5.0.0]` entry. The blockers in the raw review were all downgraded — none breaks build/install/runtime; they are submission-checklist conformance items. The one that holds at major is the missing LICENSE file (declared `proprietary` with no file is a real submission-review reject), though even that does not fail `composer validate`.

**Strengths**

- `composer.json` correctly typed `craft-plugin` with complete `support` and `extra` blocks.
- `editions()` returns Free-first/Pro-last with documented public constants (src/Cortex.php:172).
- CHANGELOG conforms to Keep a Changelog with a dated `[5.0.0] — 2026-05-14` entry.
- The `michtio/craftcms-claude-skills` runtime dependency is published on Packagist, so the tree resolves without a custom `repositories` block.

### Major — No LICENSE file despite `license: proprietary`

`composer.json:5` — *Verified, adjusted blocker → major.* `composer.json` declares `"license": "proprietary"` but there is no `LICENSE`/`LICENSE.md`/`COPYING` at the repo root. `"proprietary"` is a valid Composer keyword and `composer validate` does not error, so this is not a build/install gate — but the Plugin Store review process expects a license file matching the declared value, and Craft's own commercial plugins ship `LICENSE.md`. **Fix:** add a `LICENSE.md` at repo root with the Craftpulse commercial/proprietary terms (or the standard Craft commercial-license text if that was the intent). **Effort: S.**

### Minor — No plugin icon (`src/icon.svg`)

`src/` — *Verified, adjusted blocker → minor.* No `src/icon.svg` (nor any SVG in `src/`); `Plugins::getPluginIconSvg()` falls back to `@appicons/default-plugin.svg`. The plugin installs and runs identically — this is a listing-polish item, not a merge gate. Note: there is no `BasePlugin::getIcon()` to override; the store/Settings icon comes from `src/icon.svg`, and the CP nav icon is a separate `icon-mask.svg`. **Fix:** drop a square, single-color-friendly SVG at `src/icon.svg`. **Effort: S.**

### Minor — `extra.changelogUrl` missing

`composer.json:66-73` — *Verified, adjusted major → minor.* No `changelogUrl` key, so the CP updater and store listing show no release notes. Purely cosmetic; no runtime impact. **Fix:** add `"changelogUrl": "https://raw.githubusercontent.com/craftpulse/craft-cortex/<release-branch>/CHANGELOG.md"`. Verify the header format actually parses — the current `## [5.0.0] — 2026-05-14` uses bracketed notation and an em-dash, whereas Craft's parser canonically expects `## X.Y.Z - YYYY-MM-DD` with a plain hyphen; test rather than assume. **Effort: S.**

### Minor — No release git tag for 5.0.0

n/a (git) — *Verified, adjusted major → minor.* `git tag` is empty and `composer.json` has no `version` field (correctly — Craft plugins derive versions from tags). This is a release-time action, not a working-tree defect, and tagging now (mid-`develop-v5`) would be premature. **Fix:** tag the release on the commit that ships the tier, in lockstep with the CHANGELOG header, once the release branch is cut. **Effort: S.**

### Minor — `minimum-stability: dev` ships in the root composer.json

`composer.json:28-29` — *Unverified (minor).* Set to `dev` (with `prefer-stable: true`) for the dev-main dev-deps. It is a root-only key ignored downstream, but signals an unstable posture to reviewers. Runtime deps are all stable. **Fix:** consider pinning the dev-main dev-deps to tagged ranges and dropping to `stable` for the published release. **Effort: S.**

### Nit — No screenshots / feature image committed

n/a — *Unverified (nit).* Typically uploaded via the store dashboard, not committed, but the listing is materially stronger with them (the Gate 9 CP tabs, an MCP client connected). **Fix:** prepare 1-3 listing screenshots for upload at submission. **Effort: S.**

---

## Developer experience (custom tool authoring)

The infrastructure is well-built: a real generator wired via `EVENT_REGISTER_GENERATORS`, a genuinely ergonomic Schema DSL on the locked public surface, clean event-based registration with first-registration-wins collision logging, and an EXTENDING.md that covers tools, prompts, resources, and templates end-to-end. The serious friction is documentation drift against the real interface contract — the dangerous kind, because a dev copying the docs ships code that does not compile or is architecturally wrong.

**Strengths**

- Generator is real and correctly wired (PluginTrait.php:92-94), guards on `craftcms/generator` availability, prompts for class/namespace/tool-name, and prints the exact registration snippet on success (generator/Tool.php:173-195).
- Schema DSL is ergonomic: static entry points plus chainable setters, property-level `required()` bubbling, `additionalProperties:false` default, full `anyOf`/`oneOf`/`allOf`/`not` composition.
- `AbstractTool` carries reusable protected helpers (`_handle`, `_mode`, `_limit`, `_resolveSiteId`, `_applyFields`, `_validationEnvelope`) that meaningfully lower boilerplate for non-trivial write tools.

### Major — EXTENDING.md documents the wrong `ToolInterface` contract

`docs/EXTENDING.md:76-84, 311-324` — *Verified confirmed.*

The documented interface shows `public function shouldRegister(): bool` (non-static) and omits `filterFor(?User)` and `inputSchemaFor(?User)` entirely. The real interface declares `public static function shouldRegister()` (ToolInterface.php:93, invoked at boot as `$tool::shouldRegister()` in Tools.php:116) plus the two per-user methods (ToolInterface.php:120,145). A dev not extending `AbstractTool` who copies the listing gets a PHP fatal (non-static override of a static method) plus two unimplemented abstract methods. The example at 313-321 repeats the non-static signature and — worse — frames `shouldRegister()` as the per-user permission gate with a per-request `Craft::$app->getUser()` lookup and a `can('saveEntries:'.$uid)` check, which runs at boot where there is no request user. README.md:182 already states the contract correctly.

**Fix:** Rewrite the interface listing and example to: static `shouldRegister(): bool` (boot-time edition/license, mirror `ProToolTrait`), instance `filterFor(?User $user = null): bool` (per-request hide), instance `inputSchemaFor(?User $user = null): array` (per-request schema rewrite). Move the permission example into `filterFor()`. Note `AbstractTool`'s defaults and that `execute()` re-checks permission regardless. **Effort: S.**

### Major — Per-user gating contract (`filterFor` / `inputSchemaFor`) absent from the docs

`docs/EXTENDING.md:76-87` — *Verified confirmed.*

The documented interface omits both per-request user-aware methods that the architecture rules call the locked three-method contract and that the HTTP transport consults on every `tools/list`/`tools/call`. A third-party building a Pro/permissioned tool has no documented path to hide a tool per-user or rewrite a mode enum per-user, and is instead steered to the broken `shouldRegister` example. `PermissionedToolTrait` exists but is never surfaced in EXTENDING.md (only in `docs/plans/`).

**Fix:** Add a section documenting `filterFor()` and `inputSchemaFor()` with `PermissionedToolTrait` as the recommended per-user path, and clearly delineate boot-time (static `shouldRegister`, edition/license) vs per-request (`filterFor`/`inputSchemaFor`, user-aware) — the same split the interface docblocks already state. **Effort: M.**

### Minor — Documented `-32602` rejection contradicts the locked tool-error envelope shape

`docs/EXTENDING.md:324` — *Unverified (minor).* The doc says `tools/call` "rejects with -32602 if they try to invoke directly." Locked decision #2 forbids any numeric code field on tool-error envelopes; the canonical shape is `{content:[{type:'text',text:...}],isError:true}`. Teaching extenders to expect `-32602` sets a false contract expectation and invites codes `ModeErrorShapeTest` forbids. **Fix:** describe the `isError:true` text-envelope shape for tool-level/permission denials; reserve numeric JSON-RPC codes for protocol-level errors. **Effort: S.**

### Nit — Generator stub `execute()` returns `[]` with no shape guidance

`src/generator/Tool.php:165-168` — *Unverified (nit).* The scaffolded body is `// TODO: implement.\nreturn [];` with no hint of the expected envelope shape, the `array|\Generator` streaming option, or that `ToolException` is the structured-error path. **Fix:** return a representative associative array (e.g. `['result' => null]`) and add a one-line comment pointing to `ToolException` and `StreamableToolInterface`. **Effort: S.**

### Nit — Generator omits the `#[Title]` attribute the canonical doc example uses

`src/generator/Tool.php:138-139` — *Unverified (nit).* The generator emits only `#[IsReadOnly]` and `#[IsIdempotent]`, but EXTENDING.md's reference example leads with `#[Title('…')]` and documents it as the client-UI label. **Fix:** add a commented-out `// #[Title('…')]` line to the generated attribute block so the option is discoverable. **Effort: S.**

---

## Naming + UX language

Operator-facing copy is in good shape. Every CP Twig string in the Gate 9 templates routes through `|t('cortex')` (or `|t('app')` for shared labels), the OAuth consent screen and SettingsController flash/error messages are translatable and human-readable, and VueAdminTable headers are pre-registered via `view.registerTranslations` so the table is locale-proof. The "envelope"/"registry"/permission-handle vocabulary in `src/tools/**` and `Server.php` correctly targets the AI-agent caller and is not operator-facing. The jargon problem is narrow and concentrated in a couple of field-instruction strings.

**Strengths**

- VueAdminTable strings pre-registered via `view.registerTranslations('cortex', [...])`, and the "+ New override" trigger matches on `href` not label text precisely so the handler survives a non-English CP (allowlist.twig:152-163).
- Flash/JSON error messages are human-readable and translatable; raw exception detail is routed to `Craft::error()` logs, not the operator (SettingsController.php:327-431).
- OAuth consent screen translates scopes into plain language ("Read content, schema, and configuration. No writes.") instead of raw scope tokens.

### Minor — "fnmatch semantics" leaks the PHP function name into admin field instructions

`src/templates/_cp/settings.twig:65` (repeats in `_allowlist-override-slideout.twig:36`) — *Unverified (minor).* The Allowed-commands instructions read "Per-row patterns use `fnmatch` semantics." `fnmatch` is a libc/PHP function name an admin has no reason to know; the example already conveys the behavior. **Fix:** replace with plain language, e.g. "support glob wildcards: `resave/*` matches any `resave/<x>` route; `up` matches only the literal `up` command." Apply in both files. **Effort: S.**

### Nit — "Runtime override TTL" exposes internal lifecycle vocabulary

`src/templates/_cp/settings.twig:54` — *Unverified (nit).* "Runtime override TTL (seconds)" uses internal terms (TTL; the DB-vs-project-config "runtime override" distinction), inconsistent with the Allowlist tab that presents these as time-bound "overrides" that "auto-expire." **Fix:** soften to "Default override expiry" with mirror copy, keeping the seconds unit but leading with the human concept. **Effort: S.**

### Nit — Slideout "surfaces in the audit history" uses passive internal phrasing

`src/templates/_cp/_allowlist-override-slideout.twig:49` — *Unverified (nit).* The Note instruction reads "Optional — surfaces in the audit history alongside the row." Reads as developer shorthand. **Fix:** "Optional. Shown in the overrides table and recorded in the activity log." **Effort: S.**

---

## Open questions before submission

The team has managed open questions well — the gate-9.md resolved-questions section closes plan-time unknowns with recorded rationale, out-of-scope clarifications cite PLANNING.md line numbers, and edition gating is honest. The two raw "blocker" open questions both collapsed under verification: Gate 9 being half-built is the planned, independently-mergeable state of a feature branch (not a defect), and the "any token can change a password" claim is false (authorization holds; only session *elevation* is deferred). What remains is a real documentation-accuracy problem in the security docs plus a few roadmap-naming items.

**Strengths**

- gate-9.md resolved-open-questions section closes plan-time unknowns with recorded rationale.
- Edition gating is honest: `httpEnabled` defaults false and stdio invocations do not write to the audit table.
- The users-tool admin-protection gate (Users.php:641-647) is correctly implemented and narrowly scoped to elevation.

### Major — Elevated-session gap ships into the Pro tier with contradictory security docs

`docs/SECURITY.md:117` (and gate-9.md:342) — *Verified, adjusted blocker → major; core "any token" claim refuted, doc inaccuracies confirmed.*

The security-relevant defect here is documentation, not authorization. Two false statements ship in the security docs of a security plugin: (1) gate-9.md:342 says the elevated-session gap "is documented in SECURITY.md" — `grep -ni elevat docs/SECURITY.md` returns nothing; the decision to suppress an operator banner rests on a cross-reference that does not exist. (2) SECURITY.md:117 claims PII tools "default to read-only with explicit per-call confirmation for any mutation" and that "destructive write tools require dry-run + dangerous handshake" — the `users` tool has no confirmation/dry-run/dangerous parameter; the six-gate handshake belongs to `craft_exec`, not PII write tools. The actual authorization (`administrateUsers` for non-self password, admin caller for `admin`) holds; only Craft's *elevated-session* re-auth layer is deferred (a sanctioned locked decision).

**Fix:** Correct SECURITY.md:117 to describe the real protection (permission gates, not a per-call confirmation handshake) and gate-9.md:342 to not point at non-existent docs; add the known-limitation note (see Documentation). The "build a minimal elevation gate or restrict to stdio-only" work is a roadmap item, not a doc fix. **Effort: L** (doc correction is S; the elevation gate itself is L).

### Minor — Commerce edition is referenced widely but never declared

`src/Cortex.php:174` — *Verified, adjusted major → minor.* `editions()` returns only `EDITION_FREE`/`EDITION_PRO`; no `EDITION_COMMERCE` constant. Commerce is referenced in CLAUDE.md, security.md, and Address.php (docblocks plus two LLM-facing strings: `getDescription` line 144 and the `_assertOwnerType` exception at 703-707). Nothing is broken — `_assertOwnerType()` cleanly rejects non-`user` owner types — so this is messaging consistency, not a degraded code path. **Fix:** scrub/soften the two LLM-facing strings so they don't promise a "Commerce edition" the store contract doesn't advertise (e.g. say non-`user` owner types are unsupported, without naming an undeclared edition); internal roadmap docblocks are fine to leave. **Effort: M.**

### Minor — No release version cut; CHANGELOG missing Gate 8/9 entries

`composer.json:2` — *Verified, adjusted major → minor; "no tagged line" and "add version key" claims corrected.* `composer.json` correctly has no `version` key (Craft plugins derive from tags — adding one would be the anti-pattern). CHANGELOG *does* have a tagged `## [5.0.0] — 2026-05-14` entry plus an accumulating `[Unreleased]` block — which is exactly how Keep a Changelog operates mid-branch. The genuine item: Gate 8 (Pro) and Gate 9 (CP) work is not yet logged. **Fix:** add Gate 8/9 entries to `[Unreleased]` and cut to a tag at release time; finalize edition pricing in the store dashboard. **Effort: S.**

### Minor — Connection-tab client config blocks are hardcoded and dated

`docs/plans/gate-9.md:206` — *Unverified (minor); pertains to unbuilt Gate 9.4.* The planned Connection tab embeds hardcoded Claude Desktop / ChatGPT Desktop / Cursor config JSON "verified as of 2026-05-21." MCP client config formats are volatile; a static snippet is a support burden. **Fix:** ship only the endpoint URL plus a generic stanza linking to live docs, or render the verification date visibly if snippets stay. **Effort: S.**

### Minor — Skill element type has no drafts/revisions and is non-localized

`src/elements/Skill.php:34` — *Unverified (minor).* `Skill` sets `trackChanges` false and `isLocalized` false. Once Gate 9.6 ships a CP authoring screen with a CKEditor body, operators will expect revision history and per-site bodies on multi-site installs; `isLocalized` is hard to flip later. **Fix:** confirm the no-revisions/global-not-per-site posture is intended, state it in the Skills CP screen and EXTENDING.md, and flag the multi-site body decision now. **Effort: S.**

---

## Roadmap / phase boundary

**Does the Phase 1 / 2 / 3 boundary hold? Largely yes — the boundary is well-reasoned in `PLANNING.md` §4.12, with three caveats.**

Phase 1 (Free, stdio, read-only) is feature-complete and clean. Phase 2 (Pro: HTTP transport, OAuth, write tools, custom skills, minimal CP UI) is mid-flight on `gate-9`; per `PLANNING.md:972` the plan explicitly gates **Plugin Store submission on Gate 9 (CP UI) being complete**, then ships as Pro. Phase 3 (`PLANNING.md:974-983`) is post-ship work, demand-sequenced, and the plan is emphatic that **none of it gates Pro**. That framing is sound. Three caveats:

1. **Doc drift describes a future that already shipped.** `CONFIGURATION.md` still calls HTTP auth/audit "future" though they landed in Gates 7-8 (see Documentation majors). `PLANNING.md` has no doc-sync gate; add one to the Gate 9 close.
2. **Commerce is removed from the roadmap, but the edition language wasn't scrubbed.** `PLANNING.md:985-988` spins Commerce out as a separate `craft-cortex-commerce` plugin (requires Pro) — it is **not** a Cortex edition, and `Cortex::editions()` correctly returns only `[FREE, PRO]` (`src/Cortex.php:172`). Yet `CLAUDE.md`, the README, and tool copy still say "Free / Pro / Commerce editions." Scrub those strings (see Open-questions minor) — leaving them implies an edition that will never exist in this plugin.
3. **The elevated-session gap straddles the boundary.** It is the one item where Pro's *current* state (any HTTP bearer token can drive password/email/admin mutations without re-elevation) is weaker than the plan implies, and it has no explicit gate. See the decision below.

**The genuine submission decision — and where I'd diverge from the plan.** `PLANNING.md` gates submission on the *full* CP surface (Tokens + Activity + Connection + Skill authoring), justified by a non-dev tester running the whole issue→invoke→audit→revoke loop in the CP (`PLANNING.md:966-970`). That instinct is correct for a content-ops product whose buyers are not developers — console-only token management is not acceptable as the *only* path for that audience. But the four tabs are not equal in weight: **Tokens and Activity are security-critical** (you cannot operate an audited, revocable HTTP surface without them), while **Connection (a static reference page) and Skill-authoring (a Pro convenience) are not.** Recommendation: gate submission on **Tokens + Activity complete**, allow Connection/Skill-authoring to follow in a point release. This is a *narrowing* of the plan's gate, not console-only — and it should be recorded as a deliberate divergence, not assumed.

**Pull forward into Pro before submission (impact-first):**

1. Audit-excerpt redaction (Security major) — S. Durable secret leak; cheap.
2. OAuth `httpEnabled` gate + DCR/token throttling (Security major) — M. Always-on unauthenticated surface.
3. Resolve the elevated-session gap (Security major) — either build re-elevation for HTTP password/email/admin modes, **or** refuse those modes over HTTP behind a stdio-only gate and document it. Do not ship the silent gap. M (refuse) / L (elevate).
4. SECURITY.md + EXTENDING.md + CONFIGURATION.md corrections (Doc majors) — S/M. For a security/extensibility plugin, the docs *are* the product.
5. LICENSE file + icon + `extra.changelogUrl` (Plugin Store) — S each.
6. **Third-party tool registration docs (a partial Phase-3 pull-forward).** The `EVENT_REGISTER_TOOLS` hook already fires at boot with first-wins collision logging (`src/services/Tools.php:110,129`) and `EXTENDING.md` already documents extension — so the *mechanism* effectively ships in Pro. Shipping a live public event with an incorrect contract doc (see DX majors) is worse than either hiding it or documenting it right. Pull the **doc correction** forward; leave the security-review checklist + registry upper-bound (`PLANNING.md:981`) in Phase 3.

**Keep in Phase 3 (correctly placed — do not pull forward):**

- **Vectorised `search_docs`** (`PLANNING.md:980`) — net-new hosted-vector infra; skills already cover "how/why." Genuinely Phase 3.
- **Install wizard GUI** (`PLANNING.md:978`) — console auto-detect (Gate 6.7) already does the work; the GUI is an affordance. Phase 3.
- **Skill remote-fetch** (`PLANNING.md:982`) and **inspector marketing push** (`PLANNING.md:983`) — decoupling and GTM respectively; neither blocks a confident submission.
- **Formal real-LLM E2E harness** (`PLANNING.md:979`) — keep the *full* CI harness in Phase 3, but note the risk it mitigates is real: the LLM selects tools by name+description, so a description regression ships silently. The plan's manual smoke pass (`PLANNING.md` §4.10) is adequate for v1; revisit if the tool surface churns.

**Slip out of Pro into Phase 3 / a point release:**

- CP **Connection** tab + **Skill-authoring** CP UI (Gate 9.4, 9.6) — per the narrowed submission gate above.
- Skill drafts/revisions/localization posture — lock the *decision* before 9.6 builds the authoring UI (S to decide; expensive to reverse once the element ships with `isLocalized() = false`).

**Gaps `PLANNING.md` does not account for:** a doc-sync gate at each phase close; a LICENSE file despite the proprietary declaration; and the Commerce-string scrub. All three are cheap and should be folded into the Gate 9 close-out.

---

## Verification baseline

Run from the test environment (`~/dev/craft-plugin-playground/cms_v5`) at the `gate-9` HEAD on 2026-05-30:

| Gate | Command | Result |
| --- | --- | --- |
| Tests | `vendor/bin/pest --configuration=…/craft-cortex/phpunit.xml.dist` | **1005 passed / 0 skipped / 11,919 assertions** (24.6s) |
| Static analysis | `composer phpstan` | **Clean — level 8, 143 files** |
| Code style | `composer check-cs` (ECS) | **Clean — 243 files** |

All three gates are green. The headline 1005-passing / 0-skipped figure holds, and assertion count (11,919) is up from the Gate-8 baseline (10,999), consistent with the Gate 9.1/9.2 CP work landing. This is a genuinely strong test posture; the coverage findings above are about *boundary* gaps (untested invariants), not raw pass rate.

---

## Recommended pre-submission punch list

Ordered for impact and dependency. Items 1-4 are the should-fix-before-submission core; 5-8 are packaging; 9-12 are polish and roadmap hygiene.

1. **Redact secrets in the persisted audit excerpt** (Server.php:751, :944) — `redactArray()` + `redactString()` over flat values; add a secret-in-`craft_command`-stdout test. *(Security major, S)*
2. **Gate OAuth + .well-known controllers behind `httpEnabled` and throttle DCR/token/revoke** (OauthController, WellKnownController); add a string-keyed RateLimiter path; consider `dcrEnabled=false` default; add `httpEnabled=503` + rate-limit tests. *(Security major, M)*
3. **Fix EXTENDING.md's interface contract** — static `shouldRegister`, document `filterFor`/`inputSchemaFor` with `PermissionedToolTrait`, move the permission example into `filterFor`, correct the `-32602` claim. *(Doc/DX majors, S+M)*
4. **Fix CONFIGURATION.md** — state bearer + OAuth 2.1 auth is enforced; reframe the audit table/CP UI as shipped; reframe "two sections" as a tabbed page. *(Doc major+minor, M)*
5. **Correct SECURITY.md** — fix the overstated PII per-call-confirmation claim (line 117), add the elevated-session known-limitation note, and fix gate-9.md:342's dangling cross-reference. *(Doc minor + Open-questions major doc-half, S)*
6. **Add `LICENSE.md`** at repo root with the Craftpulse proprietary terms. *(Plugin Store major, S)*
7. **Add `src/icon.svg` and `extra.changelogUrl`** (verify the CHANGELOG header parses with a plain-hyphen format). *(Plugin Store minor, S)*
8. **Decide the Pro submission cut** — full CP surface vs console-managed-now + CP later — then add Gate 8/9 CHANGELOG entries and tag the release at cut time. *(Open questions, S)*
9. **Decide and gate the elevated-session posture** — refuse password/email/admin modes over HTTP or require an elevation claim, until the elevation layer lands; add the skipped regression-anchor test. *(Security major, L)*
10. **Resolve the Commerce-edition references** — declare, roadmap-and-scrub, or cut; soften the two LLM-facing Address strings either way. *(Open questions minor, M)*
11. **Reconcile tool counts** across README/INSTALL/TOOLS.md to the canonical Free count. *(Doc minor, S)*
12. **CP copy + perf polish** — replace "fnmatch semantics"/"runtime override TTL" with operator language; batch the table-data user lookup and move filter/sort/slice toward SQL before the Activity endpoint reuses the pattern; lock the Skill drafts/localization posture before 9.6. *(Minors/nits, S each)*
