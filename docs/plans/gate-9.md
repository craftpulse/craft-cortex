# Gate 9 — Minimal CP UI (Tokens, Activity, Connection, Settings polish, Skill authoring)

Internal planning document. **Not** user-facing reference — for that see [`docs/INSTALL.md`](../INSTALL.md), [`docs/SECURITY.md`](../SECURITY.md), etc.

This is the implementation plan for the final pre-Plugin-Store-submission gate. Gate 9 ships the operator surface for the Pro tier: a tabbed Cortex Settings page (Tokens / Activity / Connection / Settings) plus CP authoring screens for the skill element type deferred from Gate 8.6. Each sub-gate is independently mergeable and leaves the active branch green. Hand the brief for the next sub-gate to `craft-feature-builder` when ready to start.

**Source planner output**: produced by `craft-planner` agent on 2026-05-21 against PLANNING.md §4.7 (Pro Tier — Minimal CP UI, lines 525–595), §"Phase 2" Gate 9 checklist (lines 955–970), the post-Gate-8 state in [`.session-handoff.md`](../../.session-handoff.md), and the out-of-scope deferral note in [`docs/plans/gate-8.md`](./gate-8.md) §"Out-of-scope clarifications" line 458 (skill CP authoring screens deferred to Gate 9). Locked architectural decisions reference [`.claude/rules/architecture.md`](../../.claude/rules/architecture.md) and the locked Gate 7 + Gate 8 contracts.

## Executive summary

**Structure: ONE unified master plan, six sub-gates.** Rejected: splitting into three separate plan files (`gate-9.1-tokens.md`, `gate-9.2-activity.md`, `gate-9.3-connection.md`). The reason: every Gate 9 surface shares the same foundation — a custom `SettingsController` extending the existing one, a tabbed parent template extending `_layouts/cp` directly (mandatory because `settingsHtml()` cannot host tabs per the craftcms skill cp.md §"Tabbed Settings Pages"), a single shared asset bundle for VueAdminTable + Garnish wiring, and a single shared CP-URL-rule block. Splitting repeats that context three times and produces three plans with hairline-thin sub-gate scope. The Gate 8 master plan with per-sub-gate sub-plans for the larger gates is the established precedent — Gate 9 follows the same pattern, with sub-plans spawned ad hoc by the builder if a single sub-gate's diff exceeds the single-session target. The pre-merge state at the end of Gate 9 ships every Pro-tier operator surface in one branch; partial merges (e.g. Tokens-only) are blocked from Plugin Store submission because the gate-acceptance criterion requires the full end-to-end flow (issue → invoke → audit → revoke).

**Sub-gate count: six.** 9.1 foundation (custom controller, tabbed scaffold, CP nav, asset bundle, base permission set). 9.2 Tokens. 9.3 Activity. 9.4 Connection. 9.5 Settings tab move + Skill CP authoring screens. 9.6 cross-cutting tests + manual acceptance run.

## Locked decisions

These are settled. Don't relitigate without good reason.

1. **The existing `settingsHtml()` path is replaced, not extended.** Per the craftcms skill `references/cp.md` §"Tabbed Settings Pages" line 432: ``settingsHtml()` returns HTML that Craft embeds inside `vendor/craftcms/cms/src/templates/settings/plugins/_settings.twig` ... no `tabs` variable you set inside `settingsHtml()` output can reach the layout.`` So Gate 9 redirects via `getSettingsResponse()` to a Cortex-owned route, registers CP URL rules, ships a template extending `_layouts/cp` directly, and owns the full save flow in `SettingsController`. The existing single-pane `src/templates/settings.twig` content moves into the Settings tab of the new tabbed page. The `settingsHtml()` method on `Cortex.php` is deleted. Rejected: a hybrid (settingsHtml hosts Settings, separate route hosts Tokens/Activity/Connection) — splits the operator's mental model and introduces two URLs the user has to learn. Rejected: anchor-based single-page tabs without per-tab routes — breaks bookmarking, breaks deep links to a specific token row, and breaks browser-back navigation.

2. **Per-tab routes, not anchor-based single-page tabs.** Each tab gets its own URL: `settings/plugins/cortex` (Settings, default landing), `cortex/tokens`, `cortex/activity`, `cortex/connection`. Rationale: VueAdminTable's per-row click-to-expand on Activity is a slideout (Garnish.Slideout — owned by the per-tab page), not an in-page panel — so a single-page anchor tab would couple unrelated DOM state. Per-tab routes also let `tools/list` shape the nav badge counts independently and let operators deep-link to a tab from email/Slack. The `tabs` variable in each per-tab template lists ALL four tabs with absolute URLs — Craft's CP layout renders the tab strip uniformly, the page only changes the selected tab. Reference: craftcms skill `cp.md` §"Twig-level tabs" line 213.

3. **CP nav placement: under Settings, no top-level "Cortex" nav slot.** Per PLANNING.md line 354 ("Settings → Cortex with three tabs"), the tabbed CP UI lives at `settings/plugins/cortex`. No top-level nav entry for the tabs themselves. Rationale: Tokens, Activity, Connection, and Settings are all operator-grade screens (admin-issued tokens, audit history, copy-paste reference, plugin config) — none are content-creation surfaces, and putting them at the same nav level as Entries/Categories/Users misrepresents the operator surface as a content area. **Exception: the Skill element type DOES get a top-level nav entry** (`skills`, sibling to Entries/Categories/Users) per the standard Craft element-type pattern. Skills are content (admin-authored knowledge entries the LLM reads); Tokens/Activity/Connection are configuration. Rejected: top-level "Cortex" nav with subnav for all four — duplicates Settings → Plugin → Cortex with the same surface and clutters the main nav.

4. **Token issuance UX: plaintext revealed exactly once via a slideout.** A "New token" button on the Tokens tab opens a Garnish.Slideout containing the issue form (name, expiry override, user picker — admin-only). On submit, the slideout swaps to a "Token issued — copy now, it won't be shown again" view with the plaintext in a `<code>` block plus a copy-to-clipboard button. Close button dismisses the slideout. Pattern matches Craft 5's GraphQL token UI (`vendor/craftcms/cms/src/templates/graphql/tokens/_edit.twig` displays the token in a one-shot reveal). The plaintext is NEVER persisted to a session flash, NEVER logged, NEVER reloadable. Rejected: store hashed with a "regenerate" button — defeats the security model. The plaintext is one-way exit only.

5. **Activity row detail: Garnish.Slideout, not a separate route.** Clicking a row opens a slideout with the full redacted args (pretty-printed JSON), response excerpt (when present), error class/message (when `kind ∈ {tool_error, internal_error}`), cancellation reason (when `kind=cancelled`, surfaced from `errorMessage` since the schema has no separate `cancellationReason` column), and rate-limit-remaining. The row dataset is the `cortex_invocations` row plus the related user/token labels (already JOINed via the table-data endpoint). Rejected: a dedicated `cortex/activity/<id>` route — extra round-trip for data the table-data endpoint already paginated through. Rejected: inline row-expand — visually messy in a paginated table, and the JSON payloads are too large to render in a cell. The slideout is the standard Craft CP pattern (matches the entry-quick-edit slideout, the asset preview, the user-info HUD).

6. **No new composer dependencies.** Everything composes against Craft's built-in CP surfaces — `Craft.VueAdminTable`, Garnish.Slideout, the `_layouts/cp` template, the `_includes/forms.twig` macros, the `AdminTableAsset` bundle. The only plugin-side asset bundle Gate 9 ships is `CortexCpAsset` for: (a) a tiny `cortex.css` (cosmetic — table column widths, token-prefix monospace font, status pill colours per `kind`); (b) a tiny `cortex.js` (Garnish.Slideout wiring for token-issuance + activity-detail; copy-to-clipboard wiring; that's it). No Vite, no TypeScript, no Vue components beyond what `VueAdminTable` already gives. No npm dependencies. If a future Pro feature needs Vue components (e.g. a live-stream activity feed), it can introduce `craft-plugin-vite` as a separate plan — out of scope here.

7. **CP permissions: ONE new Cortex permission (`cortex:viewActivity`).** Token issuance/revocation stays admin-only (matches `cortex/token/issue` console behaviour: admin-issued credentials only). Connection tab stays admin-only (it surfaces the endpoint URL + bearer-token config snippets — operationally minor disclosure but the issued-token plaintext is the actual sensitive piece, never displayed). Allowlist runtime override editor stays admin-only via `requireAdmin(requireAdminChanges: true)` (already enforced by `SettingsController`). The ONE new permission is `cortex:viewActivity` so non-admin content operators can audit their own activity without seeing other users' rows — query is scoped by `userId` against the current user when the caller is non-admin, unscoped when the caller is admin. Rejected: `cortex:manageTokens` and `cortex:manageAllowlist` as separate permissions — admins are the issuance authority by definition; carving out a sub-admin role adds complexity without a clear operator persona behind it. If a future tenant of the plugin wants delegated token management, it lands in a follow-up gate. Permission registration extends the existing `_registerSkillPermissions()` in `src/plugin/PluginTrait.php` — rename to `_registerCortexPermissions()` and add `cortex:viewActivity` alongside the existing `Skill::PERMISSION_MANAGE`.

8. **`hasReadOnlyCpSettings = true`** is mandatory the moment `getSettingsResponse()` is overridden, per the craftcms skill `cp.md` line 516. Without it, the CP nav link to Cortex settings disappears when `allowAdminChanges = false`. The override sends read-only sessions to the same tabbed page; the view actions (`actionIndex`, `actionTokens`, `actionActivity`, `actionConnection`) call `requireAdmin(requireAdminChanges: false)` (or `requirePermission('cortex:viewActivity')` for Activity); the mutation actions (`actionSave`, `actionIssueToken`, `actionRevokeToken`, `actionAddOverride`, `actionRemoveOverride`) call `requireAdmin(requireAdminChanges: true)`. Read-only mode shows the data without write controls — same posture every other Craft settings screen takes.

9. **Skill element type CP authoring screens follow the Category template, not the Entry template.** Categories are the closest Craft-core analogue: structure-aware element type with a flat field layout and admin-managed group config (in our case: the project-config-stored field layout from Gate 8.6, no per-group split). Builder agents copy `vendor/craftcms/cms/src/templates/categories/_index.twig` and `_edit.twig` as starting points. Routes registered: `skills` (index, redirects to `skills/all`), `skills/all` (the canonical index — single source per `defineSources` returns one entry), `skills/new` (create), `skills/<id>` (edit), `skills/<id>/<slug>` (canonical edit URL). The Skill element class gains a `cpEditUrl()` override returning `UrlHelper::cpUrl("skills/{$this->id}")`. CP URL rules append to the existing `_registerUrlRules()` in PluginTrait. The save/delete flow goes through Craft's built-in `elements/save` and `elements/delete` actions — no Cortex-owned ElementsController needed; the redirect target after save is the canonical edit URL. Rejected: an Entry-style multi-section nav with section sidebar — Skills are flat (one source) and adding a sidebar would be empty chrome. Rejected: a slideout-only authoring flow without a dedicated edit page — the CKEditor body field (or whatever the field layout includes from Gate 8.6) needs a full-page editor surface to be usable.

10. **VueAdminTable table-data endpoints return `{pagination, data}`.** Both Tokens and Activity use the same shape Craft's own `sections/table-data` endpoint returns: `{data: [row, row, ...], pagination: {total, totalPages, current_page, per_page}}`. Reference: `vendor/craftcms/cms/src/controllers/SectionsController.php:255-280`. The pagination dataset shape is implicitly contracted by Craft's frontend Vue component — drift breaks the table silently. Endpoints: `cortex/settings/tokens/table-data`, `cortex/settings/activity/table-data`. Both require auth (admin + permission as per decision 7); both require `acceptsJson`; both honour the standard `page`, `per_page`, `search`, `sort.0.field`, `sort.0.direction` query params; Activity additionally honours `filters[kind]`, `filters[toolName]`, `filters[userId]`, `filters[from]`, `filters[to]`. The query-builder side delegates to `Cortex::getInstance()->invocations->find()` (the `InvocationQuery` chain already shipped in Gate 7.5 — see `src/db/InvocationQuery.php`) and to `Cortex::getInstance()->tokens->getAll()` / `getAllForUser()` (already shipped in Gate 7.2). No new service methods; the existing query surface is sufficient.

11. **Activity table never displays plaintext args or response payloads — only the already-redacted columns.** The DB columns `argsRedacted` and `responseExcerpt` carry post-`SecretRedactor` payloads (per `src/migrations/m260514_120200_cortex_invocations.php` docblock). The slideout pretty-prints those columns as-is. There is NO endpoint and NO permission level that exposes pre-redaction payloads — those don't exist in the DB to begin with. Rationale: defense-in-depth — even an admin reviewing audit history doesn't get the option to "unredact" because that data was never persisted. The slideout's `<code>` block renders `argsRedacted` through `|json_encode(constant('JSON_PRETTY_PRINT'))` after `|json_decode` round-trip; on a malformed JSON column the raw text falls through.

12. **Activity CSV export — DEFERRED.** PLANNING.md does not specify CSV export. Operators wanting bulk export query the DB directly today; a CSV button would be polish, not a Plugin-Store-submission gate. Out of scope for Gate 9. If demand surfaces post-submission, lands in Phase 3.

13. **No live SSE feed on Activity, no "currently streaming" view.** Sessions are PSR-16-cache-backed (`src/services/Sessions.php`) with no enumeration method — listing currently-active streaming sessions would require building a session-index surface that doesn't exist today. PLANNING.md does not specify a live feed. Activity is a historical-query screen, period. The session-handoff note suggesting "what's currently streaming, kill-switch" is forward-looking, not built. Out of scope for Gate 9; if added later, lands behind a `Sessions::getAllActive()` service method as a separate gate.

14. **Skills element type's first-launch CP discovery.** Once 9.5 ships the Skill CP authoring screens, a fresh install with `manageCortexSkills` granted to admins (default per the permission registration) lands on `settings/plugins/cortex` first, sees the Settings tab, and discovers Skills through the dedicated top-level "Skills" nav entry registered in 9.5. The Settings tab grows a short "Authoring skills" paragraph with a link to the Skills index, so operators landing on Settings discover the authoring surface without hunting. Rejected: a "Skills" tab on the Cortex settings page — Skills are content, not configuration; collapsing them into Settings buries the authoring flow.

15. **PC-write tests run sequential.** Gate 9 introduces a new Cortex permission (`cortex:viewActivity`) via `EVENT_REGISTER_PERMISSIONS`. The permission is event-registered and *not* PC-stored — `Users::saveLayout()` is only triggered when an admin assigns the permission to a group, not when the permission itself is registered. So Gate 9 sub-gates 9.1–9.4 do NOT need the `**SEQUENTIAL ONLY**` callout. The Settings-tab save action (9.5) DOES write to project config (`plugins.cortex.settings.*` via `Craft::$app->getPlugins()->savePluginSettings()`) — every test exercising that path runs sequentially with `Craft::$app->getProjectConfig()->muteEvents = true` per the locked rule in `.claude/rules/testing.md`. Mark `tests/Controllers/SettingsControllerSaveTest.php` (the new full-flow test) with the `**SEQUENTIAL ONLY**` callout in its docblock.

16. **No JS framework introduction.** Gate 9 uses vanilla JS for the small custom interactions (Garnish.Slideout wiring, copy-to-clipboard, table-row click handler) and `Craft.VueAdminTable` for the tables. No Vue.js components authored. No TypeScript. No Vite. The plugin's asset surface stays minimal — one CSS file, one JS file, one asset bundle. Future Pro features (CP-side install wizard, live streaming activity feed) may want Vue or Vite, but those are separate plans. Rejected: building the slideout content as a Vue component — Craft 5's CP is built on Garnish + a sprinkle of Vue at the table level; adding a Vue component for one slideout grows the dependency footprint and breaks the "everything is Twig + Garnish" mental model for operators reading the source.

## Sub-gate map

| # | Sub-gate | Complexity | Adds |
|---|---|---|---|
| 9.1 | CP scaffolding foundation (custom controller, tabbed parent, nav, asset bundle, permission) | Medium | `SettingsController` extended with view actions, custom CP routes, base tabbed template lineage, `cortex/settings/index` redirect target, `CortexCpAsset` bundle, `cortex:viewActivity` permission |
| 9.2 | Tokens tab (VueAdminTable + slideout issuance + revoke) | Large | `tokens` Twig template, `actionTokens` + `actionTokensTableData` + `actionIssueToken` + `actionRevokeToken`, slideout wiring, copy-to-clipboard, Pest tests for controller + JSON-shape invariant |
| 9.3 | Activity tab (VueAdminTable + filters + detail slideout) | Large | `activity` Twig template, `actionActivity` + `actionActivityTableData`, filter dropdowns (kind/tool/user/date-range), detail slideout with redacted-payload rendering, permission-scoped query, Pest tests |
| 9.4 | Connection tab (static reference) | Small | `connection` Twig template, `actionConnection` view action, endpoint-URL autodetect via `UrlHelper`, per-client config blocks (Claude Desktop, ChatGPT Desktop, Cursor) with copy buttons |
| 9.5 | Settings tab move + Skill CP authoring screens | Medium | Existing `settings.twig` body moves into a tabbed Settings template, `Cortex.php` settings boundary moves to `getSettingsResponse()`, Skill `cpEditUrl()` override + CP routes + index + edit templates, top-level Skills nav entry |
| 9.6 | Cross-cutting tests + manual acceptance | Small | Architecture invariant: every tabbed sub-page extends `_layouts/cp` directly + sets a `tabs` variable; permission-boundary tests for every view + mutation action; non-dev tester acceptance run per PLANNING.md line 966-970 |

## Sub-gate 9.1 — CP scaffolding foundation

**Goal**: stand up the tabbed Cortex CP page with one working tab (Settings, hosting the existing single-pane content). No new tabs yet — those land in 9.2–9.4. The deliverable is the infrastructure: custom routes, base template lineage, CP nav placement, asset bundle, permission registration. Once 9.1 ships, every subsequent sub-gate adds a tab template + controller action + JSON endpoint as a vertical slice.

**New**:
- `src/web/assets/cp/CortexCpAsset.php` — extends `craft\web\AssetBundle`. Depends on `craft\web\assets\cp\CpAsset` + `craft\web\assets\admintable\AdminTableAsset`. Includes `cortex.css` and `cortex.js`. Source-path `__DIR__/dist`.
- `src/web/assets/cp/dist/cortex.css` — minimal cosmetic overrides: status-pill colour per `kind` (success=green, tool_error=red, internal_error=red, rate_limited=amber, cancelled=grey), monospace font on `tokenPrefix` columns, slight table-column-width tuning.
- `src/web/assets/cp/dist/cortex.js` — vanilla JS module: `Cortex.openTokenIssuanceSlideout()`, `Cortex.openActivityDetailSlideout()`, `Cortex.copyToClipboard()`. ~80 lines total — slideout instantiation, fetch-and-render, focus management, dismiss-on-escape.
- `src/templates/_cp/_layout.twig` — shared chrome for every Cortex CP page. Extends `_layouts/cp`. Sets `tabs` (four entries: Settings, Tokens, Activity, Connection) and `crumbs`. Subtemplates override `selectedTab` and `content`. `view.registerAssetBundle(CortexCpAsset::class)` at the top.
- `src/templates/_cp/settings.twig` — moves the existing `src/templates/settings.twig` body verbatim into a template extending `_cp/_layout`. The `_cp/` subfolder makes the new tab templates greppable as a group.

**Modified**:
- `src/Cortex.php` — add `public bool $hasReadOnlyCpSettings = true`. Override `getSettingsResponse()` to redirect to `UrlHelper::cpUrl('settings/plugins/cortex')`. Delete `settingsHtml()` (replaced by the route). Add a `/** @noinspection MissedFieldInspection */` or PHPDoc note covering the deletion if PHPStan flags it.
- `src/plugin/PluginTrait.php`:
  - Rename `_registerSkillPermissions()` to `_registerCortexPermissions()` (keep the call site in `onPluginInit()` aligned). Add a second permission entry for `cortex:viewActivity` alongside `Skill::PERMISSION_MANAGE`.
  - Extend `_registerUrlRules()` with new CP URL rules (under `EVENT_REGISTER_CP_URL_RULES`, a separate event from the existing site URL rules). Routes:
    - `settings/plugins/cortex` → `cortex/settings/index` (Settings tab, default landing).
    - `cortex/tokens` → `cortex/settings/tokens` (Tokens tab — landing).
    - `cortex/tokens/table-data` → `cortex/settings/tokens-table-data`.
    - `cortex/tokens/issue` → `cortex/settings/issue-token` (POST only — body shape `{userId, name, ttlSeconds?}`).
    - `cortex/tokens/revoke` → `cortex/settings/revoke-token` (POST only — body `{id}`).
    - `cortex/activity` → `cortex/settings/activity` (Activity tab — landing).
    - `cortex/activity/table-data` → `cortex/settings/activity-table-data`.
    - `cortex/activity/row` → `cortex/settings/activity-row` (GET only — returns the redacted-payload slideout content for one row by id, JSON).
    - `cortex/connection` → `cortex/settings/connection` (Connection tab).
- `src/controllers/SettingsController.php` — extend with view actions (`actionIndex`, `actionTokens`, `actionActivity`, `actionConnection`) returning rendered templates. Each view action calls `requireAdmin(requireAdminChanges: false)` (Settings + Tokens + Connection) or `requirePermission('cortex:viewActivity')` (Activity) — Activity is scoped-by-permission per locked decision 7. Mutation actions (existing `actionAddOverride` + `actionRemoveOverride`, plus the new `actionSave` for Settings save, `actionIssueToken`, `actionRevokeToken`, the table-data endpoints, and `actionActivityRow`) land in 9.2–9.5. For 9.1 the only mutation action added is `actionSave` for the Settings tab (moves the save flow out of Craft's `plugins/save-plugin-settings` action into the Cortex controller per decision 1). 9.1 controller body sketch:
  - `actionIndex` — `requireAdmin(false)`; renders `_cp/settings`.
  - `actionSave` — `requirePostRequest`; `requireAdmin(requireAdminChanges: true)`; `$posted = $this->request->getBodyParam('settings', [])`; `$settings = Cortex::getInstance()->getSettings(); $settings->setAttributes($posted, false)`; `Craft::$app->getPlugins()->savePluginSettings(Cortex::getInstance(), $settings->toArray())`; flash success/fail; redirect to posted URL.

**Untouched**: `src/templates/settings.twig` (the existing template) — kept until 9.5 deletes it, but the new `_cp/settings.twig` is the canonical source going forward. 9.1 ships both files; 9.5 removes the old one. Reason: 9.1 needs to verify the route → controller → template flow works against existing content before 9.5 starts moving the body.

**Tests** (`tests/Controllers/SettingsControllerScaffoldingTest.php`):
- `actionIndex` returns a rendered Response (200) when invoked by an admin via the harness from `SettingsControllerTest.php`.
- `actionTokens` (placeholder — empty body, real content lands in 9.2) returns 200 for admin, redirects to login for anonymous, 403 for non-admin authenticated user.
- `actionActivity` (placeholder) requires `cortex:viewActivity` — admin OK, granted user OK, ungranted user 403.
- `actionConnection` (placeholder) admin OK, non-admin 403.
- `actionSave` — POST with `settings[execEnabled]=false` flips the setting (assert via PC re-read).
- Architecture invariant: every action method in `SettingsController` calls one of `requireAdmin`, `requirePermission`, or `requirePostRequest` in its first statement. Static analysis via PHP token introspection (no regex).
- Route resolution: `Craft::$app->getUrlManager()->createUrl('settings/plugins/cortex')` resolves to a non-empty URL containing the CP trigger.

**Verification gate**: Pest filter `SettingsControllerScaffoldingTest` green. Manual: `ddev craft up`; log in as admin in the playground; navigate to Settings → Plugins → Cortex; verify the tabbed page loads with four tabs in the strip and the Settings tab shows the existing allowlist+overrides content.

**Risks**:
- Removing `settingsHtml()` while keeping `hasCpSettings = true` is the right pattern but a regression vector if the redirect chain is mis-wired. Test: visit `/admin/settings/plugins/cortex` (Craft's auto-route to `settingsHtml`-using plugins) and verify it lands on the new tabbed page, not 404.
- `EVENT_REGISTER_CP_URL_RULES` vs `EVENT_REGISTER_SITE_URL_RULES`: Gate 7's MCP routes use the site rules (front-end-style endpoint). Gate 9 routes are CP routes — different event constant, different rule set. Don't merge them.

## Sub-gate 9.2 — Tokens tab

**Goal**: VueAdminTable listing of live bearer tokens with admin-issued creation and revocation. Plaintext surfaces once at issuance via Garnish.Slideout and is never reloadable per locked decision 4.

**New**:
- `src/templates/_cp/tokens.twig` — extends `_cp/_layout`. Sets `selectedTab = 'tokens'`. Content: a "+ New token" button bound to `Cortex.openTokenIssuanceSlideout()`, a `<div id="cortex-tokens-vue-admin-table">` container, a `{% js %}` block instantiating `new Craft.VueAdminTable({columns: [...], tableDataEndpoint: 'cortex/tokens/table-data', deleteAction: 'cortex/tokens/revoke', deleteConfirmationMessage: ...})`. Columns: `name`, `tokenPrefix` (monospace), `user` (linked to the Users CP screen), `expiresAt` (humanised or "never"), `lastUsedAt` (humanised or "never"), `dateCreated` (humanised).
- `src/templates/_cp/_token-issuance-slideout.twig` — the slideout body. Form with `name` (required, default `cli-{timestamp}`), `userId` (Element selector for User), `ttlSeconds` (number, default `settings.tokenTtlDefault ?? blank`). Submit posts to `cortex/tokens/issue`; on success, swaps to a "Token issued" view with the plaintext in `<code>` + copy-to-clipboard button + Close.
- `SettingsController` actions added:
  - `actionTokens` — `requireAdmin(false)`; renders `_cp/tokens`.
  - `actionTokensTableData` — `requireAcceptsJson`; `requireAdmin(false)`. Reads `page`, `per_page`, `search`, `sort.0.field`, `sort.0.direction`. Builds the query via `Cortex::getInstance()->tokens->getAll()` (no native pagination on the service — `getAll()` returns the full list; pagination is in-PHP). For the next gate (post-9.6 follow-up if perf matters), `Tokens` service can grow a paginating method; today the table size is bounded by operator-issued tokens (low dozens at most), so in-PHP slicing is fine. Returns `{pagination: {total, totalPages, current_page, per_page}, data: [{id, name, tokenPrefix, user: {id, label, cpEditUrl}, expiresAt, lastUsedAt, dateCreated}]}`.
  - `actionIssueToken` — `requirePostRequest`; `requireAdmin(requireAdminChanges: true)`. Body: `userId` (required int), `name` (optional string, default `cli-{timestamp}` synthesised by the controller), `ttlSeconds` (optional int). Resolves `User::findOne($userId)`; calls `Cortex::getInstance()->tokens->issue($userId, $name, $ttlSeconds)`. Returns `asJson(['token' => $plaintext, 'model' => $serialized])` — the JS layer renders the plaintext-reveal slideout from this response. On failure, returns 422 + `asJson(['errors' => [...]])`.
  - `actionRevokeToken` — `requirePostRequest`; `requireAdmin(requireAdminChanges: true)`. Body: `id` (required int). Calls `Cortex::getInstance()->tokens->revoke($id)`. Returns `asSuccess()` or 404 if unknown.
- `cortex.js` extends with the slideout-instantiation flow.

**Modified**:
- `src/services/Tokens.php` — no API change required. If the table-data endpoint's in-PHP pagination hot-paths become slow on operators with hundreds of tokens, a follow-up adds `getPaginated(int $offset, int $limit, ?string $search, string $orderBy, int $sortDir): array` — not blocking 9.2.

**Tests** (`tests/Controllers/SettingsControllerTokensTest.php`):
- `actionTokens` — admin OK, non-admin 403, anonymous redirected.
- `actionTokensTableData` — admin sees all tokens; payload shape matches the locked `{pagination, data}` contract; search by name reduces row count; sort by name DESC reverses the order; missing token columns null-out cleanly.
- `actionIssueToken` — admin posts `{userId: $u->id, name: 'integration-test'}`; response contains a plaintext token + serialized model; the plaintext authenticates against the MCP endpoint (`/cortex/mcp`) per the existing Gate 7.2 flow; lookup via `Cortex::getInstance()->tokens->lookup($plaintext)` returns the model. Non-admin gets 403.
- `actionRevokeToken` — admin revokes; subsequent `tokens->lookup($plaintext)` returns null; non-admin gets 403; unknown id returns 404.
- Architecture invariant (JSON shape): `actionTokensTableData` returns a payload whose `data[0]` keys equal `['id', 'name', 'tokenPrefix', 'user', 'expiresAt', 'lastUsedAt', 'dateCreated']` exactly. Drift = test failure.

**Verification gate**: Pest filter `SettingsControllerTokensTest` green. Manual: log in as admin; navigate to Tokens tab; click "+ New token"; select a user; submit; verify the slideout swaps to the plaintext-reveal view; copy the plaintext; paste into `curl -H "Authorization: Bearer <plaintext>" -X POST .../cortex/mcp -d '{...initialize...}'`; verify 200. Refresh the Tokens tab; click the trash icon on the newly-issued token; confirm; verify the row disappears AND a subsequent `curl` with the same plaintext returns 401.

**Risks**:
- Plaintext leaking into the browser history via URL fragment / form-resubmit — the slideout post is XHR, plaintext lives in the JSON response, NEVER in a URL. Document in the JS source.
- Element-selector for `userId` — Craft's `_includes/forms/elementSelect.twig` macro handles this; pass the User class. If the operator picks no user the form falls back to a 422.
- Slideout focus management — Garnish.Slideout handles aria + focus trap automatically per the craft-garnish skill. Verify in manual smoke that ESC dismisses cleanly.

## Sub-gate 9.3 — Activity tab

**Goal**: VueAdminTable invocation log with kind/tool/user/date-range filters. Row click opens a Garnish.Slideout with the redacted args + response excerpt + error/cancellation context. Non-admin operators with `cortex:viewActivity` see only their own rows.

**New**:
- `src/templates/_cp/activity.twig` — extends `_cp/_layout`. Sets `selectedTab = 'activity'`. Content: a filter bar (kind dropdown, tool dropdown, user dropdown — admin only — and a date-range pair), the `<div id="cortex-activity-vue-admin-table">` container, the `{% js %}` block. Columns: `dateCreated` (humanised), `toolName`, `kind` (status pill — colour per locked decision 6 CSS), `userId` (linked to User CP screen, or "anonymous" if null), `durationMs` (humanised — `42ms` / `1.2s`), `clientName`. Row click → `Cortex.openActivityDetailSlideout(rowId)`.
- `src/templates/_cp/_activity-detail-slideout.twig` — slideout content rendered server-side and fetched via `cortex/activity/row?id=<id>`. Layout: metadata table (tool, mode/type-from-args, kind, duration, transport, client, session, token, rateLimitRemaining), then pretty-printed `argsRedacted` in a code block, then pretty-printed `responseExcerpt` (if non-null), then error metadata (errorClass + errorMessage, when present), then cancellation reason (when `kind=cancelled`, sourced from `errorMessage`).
- `SettingsController` actions:
  - `actionActivity` — `requirePermission('cortex:viewActivity')`; renders `_cp/activity` with the available filter values (kind enum values from the InvocationRecord rules, distinct tool names from the DB via `InvocationQuery::distinct('toolName')` — add this helper if missing, see Risks).
  - `actionActivityTableData` — `requireAcceptsJson`; `requirePermission('cortex:viewActivity')`. Reads pagination + sort + filter params. Builds the query via `Cortex::getInstance()->invocations->find()->kind(...)->toolName(...)->userId(...)->after(...)->before(...)->orderBy(...)->offset(...)->limit(...)`. **Non-admin scoping**: if `!$user->admin`, force `->userId($user->id)` regardless of filter value (the filter dropdown for user is hidden client-side for non-admins, but defense-in-depth in the controller). Returns `{pagination, data}` per the locked contract.
  - `actionActivityRow` — `requireAcceptsJson`; `requirePermission('cortex:viewActivity')`. Reads `id` param. Loads `Cortex::getInstance()->invocations->find()->id($id)->one()`. Non-admin scoping: if `!$user->admin && $row->userId !== $user->id`, 404 (NOT 403 — prevents enumeration). Returns the slideout HTML via `renderTemplate('cortex/_cp/_activity-detail-slideout', ['row' => $row, ...])` wrapped in `asJson(['html' => $rendered])`. JS injects into the slideout.

**Modified**:
- `src/db/InvocationQuery.php` — add `distinctToolNames(): array` returning a deduplicated, alphabetised list of `toolName` values across all rows in the table. The filter-dropdown population reads this once on `actionActivity` render. Implementation: `$this->select(['toolName'])->distinct()->column()`. Cheap — indexed column.
- `src/plugin/PluginTrait.php::_registerCortexPermissions()` — already added `cortex:viewActivity` in 9.1.

**Tests** (`tests/Controllers/SettingsControllerActivityTest.php`):
- `actionActivity` — admin OK, granted-permission user OK, ungranted 403, anonymous redirected.
- `actionActivityTableData`:
  - Admin sees all rows.
  - Non-admin with `cortex:viewActivity` sees only rows where `userId === currentUserId`. Even if the request includes `filters[userId]=<otherId>`, the controller overrides it.
  - Filter by `kind=tool_error` returns only error rows.
  - Filter by `toolName=entry` returns only `entry` rows.
  - Date-range filter narrows correctly (after + before bracket).
  - Sort by `dateCreated DESC` is the default.
  - Pagination: 100 fixture rows, `per_page=25` returns 4 pages.
- `actionActivityRow`:
  - Admin can view any row.
  - Non-admin with permission can view own rows; foreign rows return 404 (not 403).
  - Cancelled row: response includes the cancellation reason from `errorMessage`.
  - Error row: response includes `errorClass` + `errorMessage`.
  - Missing row: 404.
- Architecture invariant: the redacted-payload columns (`argsRedacted`, `responseExcerpt`) are NEVER referenced outside the slideout-detail render path. Static check via grep across the controller + templates.

**Verification gate**: Pest filter `SettingsControllerActivityTest` green. Manual: seed ~30 invocation rows by hitting MCP tools with a token from 9.2; navigate to Activity tab; verify the table loads; filter by `kind=tool_error`; click a row; verify the slideout opens with redacted args + error trace; log out; log in as a non-admin with `cortex:viewActivity`; verify only that user's rows show.

**Risks**:
- The filter-bar markup needs to match Craft's existing filter conventions (`<div class="flex">` + the standard `<select>` macros from `_includes/forms.twig`). Inspect `vendor/craftcms/cms/src/templates/utilities/queue-manager/index.twig` or similar for an existing filter-bar shape to mimic.
- The detail slideout's `responseExcerpt` is capped at `Settings::$auditResponseExcerptBytes` (default 2KB). For longer responses, surface a "Response truncated to N bytes — full payload not retained" notice in the slideout.
- `distinctToolNames()` scaling: at 10k+ rows the query is fast (indexed column) but the dropdown grows. If a future install has hundreds of tools, the dropdown becomes a typeahead. Out of scope for 9.3; flag for the builder.

## Sub-gate 9.4 — Connection tab

**Goal**: static reference page surfacing the Streamable HTTP endpoint URL plus copy-paste config snippets for known MCP clients. Not a wizard.

**New**:
- `src/templates/_cp/connection.twig` — extends `_cp/_layout`. Sets `selectedTab = 'connection'`. Content:
  - A heading "Endpoint" with the auto-detected URL: `siteUrl('cortex/mcp')` (front-end-style URL — matches the Gate 7.1 route registration). Copy-to-clipboard button.
  - Three "Client configuration" sections — Claude Desktop, ChatGPT Desktop, Cursor — each with a code block containing the canonical config JSON for that client (per their docs as of 2026-05-21). Copy-to-clipboard button per block.
  - A note: "Issue a bearer token in the Tokens tab and replace `<your-bearer-token>` in the config."
  - When `Cortex::getInstance()->getSettings()->httpEnabled === false`: a yellow warning card above the endpoint section saying "HTTP transport is disabled. Enable in Settings tab to accept incoming connections."
- `SettingsController::actionConnection` — `requireAdmin(false)`; renders `_cp/connection` with `endpoint` (auto-detected URL), `settings` (the model), and `clients` (a static array of `{name, configTemplate}` rows).

**Modified**: none.

**Tests** (`tests/Controllers/SettingsControllerConnectionTest.php`):
- `actionConnection` — admin OK, non-admin 403.
- Rendered content includes the auto-detected endpoint URL.
- Rendered content includes the warning card when `httpEnabled = false` and excludes it when `true`.
- Each client config block contains a placeholder for the bearer token.

**Verification gate**: Pest filter `SettingsControllerConnectionTest` green. Manual: log in as admin; navigate to Connection tab; verify the URL matches the playground's site URL + `/cortex/mcp`; copy a config block; paste into a fresh Claude Desktop config; replace the token placeholder with a 9.2-issued token; verify Claude Desktop connects (the broader end-to-end flow is the Gate 9.6 acceptance criterion).

**Risks**:
- Client config formats change. The hardcoded JSON blocks may drift from upstream. Each block carries a comment "Verified against <client>'s docs as of 2026-05-21" in the template — future updates are a Phase 3 polish task.

## Sub-gate 9.5 — Settings tab finalization + Skill CP authoring screens

**Goal**: complete the Settings tab move out of the deprecated `settings.twig` (deferred from 9.1 to keep 9.1 small) and ship the deferred Gate 8.6 Skill CP authoring surface.

**Modified (Settings tab finalization)**:
- `src/templates/settings.twig` — DELETED. Content already moved to `src/templates/_cp/settings.twig` in 9.1.
- `src/templates/_cp/settings.twig` — small polish: wrap the body in a `{% block content %}`; ensure form fields use the `settings[xxx]` bracket naming per the craftcms skill cp.md line 583 footgun callout; add the "Authoring skills" paragraph per locked decision 14 linking to `cpUrl('skills')`.
- `src/controllers/SettingsController::actionSave` (already added in 9.1) — verify the body-param shape matches `settings[allowedCommands][]`, `settings[adminLevelCommands][]`, `settings[execEnabled]`, etc. The `editableTableField` macro for `allowedCommands` posts as `settings[allowedCommands][N][pattern]`; the controller flattens this back to `string[]` before calling `savePluginSettings()`. Same for `adminLevelCommands` + `userCustomFieldAllowlist`. Test specifically for this flatten step.

**New (Skill CP authoring)**:
- `src/controllers/SkillsController.php` — extends `craft\web\Controller`. Actions:
  - `actionIndex(string $source = 'all')` — `requirePermission(Skill::PERMISSION_MANAGE)`; renders `cortex/skills/_index.twig`. The `$source` arg matches the only `defineSources()` key (`*` — passed in as `all` from the URL, mapped internally). Mirrors `vendor/craftcms/cms/src/controllers/CategoriesController.php::actionEditCategoryGroup` shape.
  - `actionCreate()` — `requirePermission(Skill::PERMISSION_MANAGE)`; creates a fresh `Skill` element via `Craft::$app->getElements()->createElement(['type' => Skill::class])` and redirects to its edit URL.
  - `actionEdit(int $id)` — `requirePermission(Skill::PERMISSION_MANAGE)`; loads via `Skill::find()->id($id)->one()`. Returns a `CpScreenResponseBehavior`-wrapped response via `$element->asCpScreen()` — leverages Craft's element-CP-screen pattern (used by Categories), gives us the editor chrome + field layout rendering for free. Save flow routes through Craft's built-in `elements/save` action — no Cortex code needed there.
- `src/elements/Skill.php` — override `cpEditUrl()` to return `UrlHelper::cpUrl("skills/{$this->id}")`. Override `cpRevisionsUrl()` to return null (skills have no revisions today). Update `defineSources()` if needed to populate `defaultSort` etc. for the index sort default.
- `src/templates/skills/_index.twig` — element index template. Extends `_layouts/elementindex`. Sets element type to `Skill::class`, the sources from `defineSources`, the table attributes (`handle`, `description`, `dateCreated`). The built-in `_layouts/elementindex` template handles all the chrome — we just feed it the element type and source.
- `src/templates/skills/_edit.twig` — minimal edit-screen template. If the element's `asCpScreen()` returns a usable response (Categories' pattern), this template may be empty or unneeded. Verify against `vendor/craftcms/cms/src/templates/categories/_edit.twig` in 9.5 — that template is ~50 lines because Categories uses asCpScreen.

**Modified (Skill CP authoring wire-up)**:
- `src/plugin/PluginTrait.php::_registerUrlRules()` — append CP URL rules:
  - `skills` → `cortex/skills/index`.
  - `skills/all` → `cortex/skills/index` (matches the single source key).
  - `skills/new` → `cortex/skills/create`.
  - `skills/<id:\d+><slug:(?:\-[^\/]*)?>` → `cortex/skills/edit`.
- `src/plugin/PluginTrait.php::_registerCpNavItem()` — NEW private method called from `onPluginInit()`. Registers a top-level "Skills" nav entry under `Cp::EVENT_REGISTER_CP_NAV_ITEMS`. Icon: a tiny inline placeholder SVG (~10 lines XML, mortar-and-pestle or similar). Phase 3 polish replaces with a designed icon. Visibility filter: the nav item is `false` unless the current user has `Skill::PERMISSION_MANAGE`.

**Tests**:
- `tests/Controllers/SkillsControllerTest.php`:
  - `actionIndex` — granted user OK, ungranted 403.
  - `actionCreate` — granted user creates a fresh element; redirect target is the edit URL.
  - `actionEdit` — granted user loads existing skill; ungranted 403.
- `tests/Elements/SkillCpEditUrlTest.php` — `$skill->getCpEditUrl()` returns the expected `cpUrl('skills/<id>')` shape. Unsaved element returns null.
- `tests/Controllers/SettingsControllerSaveTest.php` (`**SEQUENTIAL ONLY**` per locked decision 15):
  - Posting `settings[allowedCommands][0][pattern]=resave/*` flattens to `$settings->allowedCommands === ['resave/*']`.
  - Posting nothing leaves the existing setting intact.
  - Non-admin gets 403.
  - Read-only mode (`allowAdminChanges = false`) on save gets 403 from `requireAdmin(requireAdminChanges: true)`.

**Verification gate**: Pest filters `SkillsControllerTest`, `SkillCpEditUrlTest`, `SettingsControllerSaveTest` green. Manual: log in as admin; verify a "Skills" top-level nav entry appears in the left CP nav; click it; verify the index page loads with any existing element-stored skills; click "+ New skill" (or whatever the Craft index page's create button reads); fill in handle + title + body; save; verify it round-trips. Settings tab: edit `allowedCommands` (add a row), save, verify PC sync via `ddev craft project-config/diff`.

**Risks**:
- The `elements/save` action's signature for a custom element type may require additional plumbing if the element class' `prepareEditScreen()` isn't overridden. Builder agent should attempt the Categories-template-copy approach FIRST; only fall back to a custom save action if `asCpScreen()` doesn't work for Skills.
- The `_layouts/elementindex` template needs the element type + sources to be discoverable. The element type is already registered via `_registerSkillElementType` (Gate 8.6); the sources are already defined in `Skill::defineSources()`. Risk is minimal — the build should be straightforward.
- The CP nav SVG icon ships as a tiny inline placeholder per locked decision 9; designed icon is a Phase 3 concern.

## Sub-gate 9.6 — Cross-cutting tests + acceptance run

**Goal**: the test invariant sweep + the non-dev tester acceptance run that proves Gate 9 ships green per PLANNING.md line 966-970.

**New**:
- `tests/Architecture/CpTemplateInvariantTest.php`:
  - Every `.twig` file under `src/templates/_cp/` either extends `_cp/_layout` OR is a partial (starts with `_`).
  - `_cp/_layout.twig` extends `_layouts/cp` directly (NOT `_layouts/basecp`, not a wrapper). Per the craftcms skill cp.md line 623.
  - Every per-tab template sets a `selectedTab` variable.
  - The four `tabs` keys in `_layout.twig` are `['settings', 'tokens', 'activity', 'connection']` exactly.
- `tests/Architecture/SettingsControllerPermissionInvariantTest.php`:
  - Every view-action method in `SettingsController` (`actionIndex`, `actionTokens`, `actionActivity`, `actionConnection`) calls `requireAdmin(false)` OR `requirePermission(...)` in its body.
  - Every mutation-action method (`actionSave`, `actionIssueToken`, `actionRevokeToken`, `actionAddOverride`, `actionRemoveOverride`) calls both `requirePostRequest()` AND (`requireAdmin(requireAdminChanges: true)` OR `requirePermission(...)`).
  - Static check via PHP token parsing — same pattern as the existing `tests/Architecture/ConventionsTest.php`.
- `tests/Integration/CpEndToEndTest.php`:
  - Full flow simulated: admin issues a token via `actionIssueToken`; the plaintext authenticates against `/cortex/mcp` via a synthetic POST; an `entry create` tool call runs; the invocation appears in `actionActivityTableData`; revocation via `actionRevokeToken` invalidates the token; subsequent POST returns 401.
  - This is the "non-dev tester gate" automated. The PLANNING.md line 966-970 manual gate stays — this test is for regression prevention.

**Manual acceptance run** (per PLANNING.md line 966-970, performed by a non-dev tester):
- Log into the CP as admin.
- Navigate to Settings → Plugins → Cortex.
- Tokens tab: click "+ New token", select a user (yourself or a test user), submit. Copy the revealed plaintext.
- Open a terminal: `curl -X POST -H "Authorization: Bearer <plaintext>" -H "MCP-Protocol-Version: 2025-06-18" -H "Origin: http://localhost" http://cortex-test.ddev.site/cortex/mcp -d '<initialize JSON>'`. Verify 200.
- Run a Pro tool (e.g. `entry` mode=list) via curl.
- Activity tab: verify the invocation appears as the top row. Click the row. Verify the slideout shows redacted args + response excerpt. Close.
- Tokens tab: revoke the token via the row's trash icon. Confirm.
- Re-run the curl. Verify 401.
- Settings tab: add a runtime allowlist override (e.g. `mailer/test` with 60s TTL). Save. Verify the row appears. Wait 60s. Refresh. Verify it's marked expired.
- Connection tab: verify the endpoint URL matches the test environment. Copy the Claude Desktop config block. Paste into Claude Desktop's actual config file. Restart Claude Desktop. Issue a fresh token. Replace the placeholder. Verify Claude Desktop connects and lists tools.

The non-dev tester records pass/fail on each step. Any failure blocks Plugin Store submission until fixed.

**Verification gate**: Pest filters `CpTemplateInvariantTest`, `SettingsControllerPermissionInvariantTest`, `CpEndToEndTest` green. PHPStan + ECS green. Manual acceptance run signed off by a non-dev tester (Michael, content-team member, or QA).

**Risks**:
- The non-dev tester step depends on a real MCP client (Claude Desktop or equivalent) being installable. If the tester doesn't have Claude Desktop, the Connection-tab manual verification falls back to a `curl`-only flow. Document both paths.

## Per-feature manual verification

After each sub-gate, before moving on:

| After | Manual check |
|---|---|
| 9.1 | `ddev craft up`; admin lands on `settings/plugins/cortex` and sees the tabbed page with four tab labels in the strip. Settings tab shows the existing single-pane content. Save flow on Settings still works (toggle execEnabled, save, verify PC sync via `ddev craft project-config/diff`). Anonymous user redirected to login; non-admin user gets 403 attempting `cortex/tokens`. |
| 9.2 | Tokens tab loads. "+ New token" opens slideout. Issue a token. Plaintext displays in the post-issue view. Copy. Use in `curl` against `/cortex/mcp`. 200. Refresh Tokens tab. Token appears in the row. Revoke. Re-curl. 401. Non-admin → 403 on the whole tab. |
| 9.3 | Activity tab loads. Filters render. Click a row. Slideout opens with redacted args + response excerpt + (for tool_error rows) error class + message. As a non-admin with `cortex:viewActivity` granted: only own rows visible; foreign row URL returns 404. |
| 9.4 | Connection tab loads. Endpoint URL auto-detected. Copy a config block. Paste into Claude Desktop. Verify it works (full Claude Desktop end-to-end is the 9.6 acceptance gate). |
| 9.5 | Skills nav entry appears in CP for admins. Index page loads. Create new skill: handle + title + body. Save. Round-trips. Existing settings move into the Settings tab cleanly — no diff vs pre-9.5 functionality. Settings save with `allowedCommands` edit syncs to PC. |
| 9.6 | Full Pest suite green. PHPStan + ECS green. Non-dev tester acceptance run signed off. |

## Cross-cutting test strategy

- **Controller tests use the existing `_CortexSettingsControllerHarness` pattern from `tests/Controllers/SettingsControllerTest.php`**. The harness bypasses HTTP plumbing — `requirePostRequest`, `requireAdmin`, `redirectToPostedUrl` short-circuit; the action body runs against a console-bootstrapped Craft. Reuse for every new controller action in 9.1-9.5. Real CP flow lives in the manual smoke gate.
- **Permission boundary tests** for every action — admin + granted-permission + ungranted-permission + anonymous matrix. Reuses the `tests/Factories/UserFactory.php` from Gate 8 (with scoped permissions).
- **Architecture invariants** — `CpTemplateInvariantTest` (template lineage), `SettingsControllerPermissionInvariantTest` (every action calls `requireAdmin` or `requirePermission`). Static analysis, no runtime.
- **VueAdminTable JSON shape** — assert the `{pagination, data}` contract on every table-data endpoint. Sort + filter + search exercised via direct param injection. The frontend Vue component is NOT tested (out of scope without Playwright/JS harness); the JSON contract IS tested as the load-bearing seam.
- **End-to-end flow** — `CpEndToEndTest.php` simulates the full PLANNING.md line 966-970 gate in-process. Manual non-dev tester run remains the canonical acceptance gate.
- **PC-write tests** — only `SettingsControllerSaveTest.php` writes PC. Marked `**SEQUENTIAL ONLY**` per locked decision 15.

## Out-of-scope clarifications

Confirmed against PLANNING.md §4.7 (lines 525-595), Gate 9 checklist (lines 955-970), and `docs/plans/gate-8.md` §"Out-of-scope clarifications" (lines 454-466):

- **NOT an embedded MCP client.** No chat UI, no in-CP conversation surface, no tool-invocation widget. Confirmed via PLANNING.md line 570 ("**Pro CP UI (minimal, NOT an embedded MCP client)**").
- **NO live activity stream.** Activity is a historical-query screen. The session-handoff note about "what's currently streaming, kill-switch" is forward-looking, not PLANNING.md content. See locked decision 13.
- **NO CSV export of Activity.** See locked decision 12.
- **NO multi-edition CP behaviour switch.** Gate 9 ships the same CP surface on Free and Pro installs — Free installs get the same Tokens / Activity / Connection / Settings tabs, but Tokens won't authenticate against the HTTP transport (which is `httpEnabled = false` by default on Free anyway), Activity won't have HTTP rows to display (stdio invocations don't write to `cortex_invocations` per Gate 7.5 locked decision 11), and Connection will show the endpoint URL with the "HTTP transport disabled" warning card. The Connection-tab warning surfaces the edition / settings interaction; no separate Free vs Pro template branches.
- **Elevated-session model for the `users` tool** — deferred. Per session-handoff line 263-265: "the MCP HTTP transport elevated-session model is NOT yet built. Users tool accepts password/email/admin mutations without elevation — documented gap, Gate 9+ deliverable alongside CP UI." Gate 9 does NOT build the elevated session model; the existing `Users::canSave()` admin-protection in tool layer (per `src/tools/system/Users.php:619-631`) holds the line. No UI banner — the gap is documented in `SECURITY.md`; drawing attention to a gap operators can't act on is more harmful than helpful.
- **`tools/list_changed` on mid-session permission change** — same deferral as Gate 7 + Gate 8. Documented gap.
- **`Last-Event-ID` SSE resumability** — Phase 3.
- **License validation network call** — never. Plugin Store sets the edition handle; Cortex trusts it.
- **CSV / JSON export of activity, audit log retention dashboard, runtime override audit trail** — Phase 3.
- **CP-side install wizard (the GUI wrapping `cortex/install/*` console actions)** — Phase 3 per PLANNING.md line 978.
- **Live Vue component framework, Vite integration, TypeScript** — locked decision 16; out of scope.

## Composer dependencies

**ZERO new composer dependencies.** Locked decision 6. If the builder agent finds itself reaching for a new dep, surface as a Q before installing.

## File-path reference (existing code)

Builder agents start by reading:

**Core wiring**:
- `src/Cortex.php` — replace `settingsHtml()` with `getSettingsResponse()`; add `$hasReadOnlyCpSettings = true` (9.1).
- `src/plugin/PluginTrait.php` — extend `_registerCortexPermissions()` (renamed from `_registerSkillPermissions()`), extend `_registerUrlRules()` with CP routes, NEW `_registerCpNavItem()` (9.5).

**Controller surface**:
- `src/controllers/SettingsController.php` — extend with view + mutation actions across 9.1-9.5. The existing `actionAddOverride` and `actionRemoveOverride` stay.
- `src/controllers/SkillsController.php` — NEW in 9.5.

**Templates**:
- `src/templates/settings.twig` — DELETED in 9.5 (moved to `_cp/settings.twig` in 9.1).
- `src/templates/_cp/_layout.twig` — NEW in 9.1 (the tabbed parent).
- `src/templates/_cp/settings.twig` — NEW in 9.1 (Settings tab body — initially mirrors `settings.twig`, polished in 9.5).
- `src/templates/_cp/tokens.twig` — NEW in 9.2.
- `src/templates/_cp/_token-issuance-slideout.twig` — NEW in 9.2.
- `src/templates/_cp/activity.twig` — NEW in 9.3.
- `src/templates/_cp/_activity-detail-slideout.twig` — NEW in 9.3.
- `src/templates/_cp/connection.twig` — NEW in 9.4.
- `src/templates/skills/_index.twig` — NEW in 9.5.
- `src/templates/skills/_edit.twig` — NEW in 9.5 (or empty if `asCpScreen()` covers it).

**Asset bundle**:
- `src/web/assets/cp/CortexCpAsset.php` — NEW in 9.1.
- `src/web/assets/cp/dist/cortex.css` — NEW in 9.1.
- `src/web/assets/cp/dist/cortex.js` — NEW in 9.1, extended in 9.2 + 9.3.

**Read-only references (existing code Gate 9 surfaces over the UI)**:
- `src/services/Tokens.php` — `getAll()`, `getAllForUser()`, `issue()`, `revoke()`, `lookup()`, `getById()`. No API changes needed.
- `src/services/Invocations.php` — `find()` returns an `InvocationQuery`. No API changes.
- `src/db/InvocationQuery.php` — full filter surface. ADD `distinctToolNames()` in 9.3.
- `src/services/Allowlist.php` — `add()`, `remove()`, `getAllOverrides()`. No API changes.
- `src/elements/Skill.php` — ADD `cpEditUrl()` override in 9.5.
- `src/services/Skills.php` — element-stored skills CRUD. No API changes.
- `src/models/Settings.php` — every settings field. No new fields in Gate 9.
- `src/console/controllers/TokenController.php` — pattern reference for the issue/revoke flow.
- `src/records/Invocation.php` — column reference for the Activity slideout payload.
- `src/migrations/m260514_120000_cortex_tokens.php` — column reference for the Tokens table.
- `src/migrations/m260514_120200_cortex_invocations.php` — column reference for the Activity table.

**Craft core references**:
- `vendor/craftcms/cms/src/templates/settings/sections/_index.twig` — canonical VueAdminTable pattern.
- `vendor/craftcms/cms/src/controllers/SectionsController.php:255-280` — canonical `actionTableData()` shape.
- `vendor/craftcms/cms/src/templates/categories/_index.twig` and `_edit.twig` — closest Craft-core analogue for the Skill CP authoring screens.
- `vendor/craftcms/cms/src/templates/graphql/tokens/_edit.twig` — Craft-core's one-shot token-reveal pattern.
- `vendor/craftcms/cms/src/web/twig/variables/Cp.php` — `EVENT_REGISTER_CP_NAV_ITEMS`, `EVENT_REGISTER_CP_SETTINGS`, read-only event variant.
- `vendor/craftcms/cms/src/base/Plugin.php:135-141` — `$hasReadOnlyCpSettings` auto-flip condition.
- `~/.claude-eng/skills/craftcms/references/cp.md` §"Tabbed Settings Pages" (line 209) — non-negotiable pattern reference for tabbed CP pages.

**Plan source-of-truth**:
- `/Users/michtio/dev/craft-plugin-playground/PLANNING.md` — §4.7 (lines 525-595), Gate 9 checklist (lines 955-970).
- `/Users/michtio/dev/craft-plugins/v5/craft-cortex/.session-handoff.md` — post-Gate-8 state, locked decisions inherited from Gate 8.
- `/Users/michtio/dev/craft-plugins/v5/craft-cortex/docs/plans/gate-8.md` §"Out-of-scope clarifications" line 458 (skill CP authoring deferred to Gate 9).

## Resolved open questions

The three open questions flagged at plan time were resolved on 2026-05-21 before builder dispatch. Recorded here for traceability — locked decisions above already reflect each answer.

1. **Activity scoping for non-admins** — **resolved: ship `cortex:viewActivity` with per-user scoping** (locked decision 7). Non-admins see only their own rows; admins see everything. Recorded reason: "I want to see what I just did" is a real operator need; ~20 lines of query scoping is cheap.

2. **Elevated-session banner on the Settings tab** — **resolved: silent default** (Out-of-scope clarifications, elevated-session bullet). The `users` tool gap remains documented in `SECURITY.md`; no in-CP banner. Recorded reason: drawing attention to a gap operators can't act on is more harmful than helpful.

3. **Skill CP nav icon** — **resolved: tiny inline placeholder SVG** (locked decision 9 + sub-gate 9.5 risk note). ~10 lines XML, mortar-and-pestle or similar; designed icon is a Phase 3 concern.
