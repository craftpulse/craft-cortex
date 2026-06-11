# Gate 9.7 — Edition gating of Pro surfaces

> Status: PLANNED (numbering provisional — slots after the shipped 9.5 Tokens /
> 9.3 Activity work; 9.4 Connection and 9.6 Skill-authoring remain slipped).
>
> Source: gate-9 browser smoke (2026-06-10/11), `docs/SMOKE-TEST.md` case
> A1-Free — the submission blocker.

## Problem

Edition gating exists in exactly one layer: the tool registry
(`ProToolTrait::shouldRegister()` → `is(EDITION_PRO, '>=')`). Every other
Pro surface is reachable on a Free install:

1. **CP tabs** — the tab map in `src/templates/_cp/_layout.twig:32` is a
   static five-entry hash. Tokens / Activity / Connection render on Free.
2. **CP actions** — `SettingsController` has zero edition checks. On Free,
   `actionTokens*`, `actionIssueToken`, `actionRevokeToken`,
   `actionActivity*`, and `actionConnection` all serve admins normally.
   Proven: a bearer token was issued on Free via `issue-token` (smoke,
   2026-06-11).
3. **HTTP transport** — `McpController::beforeAction()` runs six gates
   (`httpEnabled`, Origin, method, protocol version, bearer, rate limit)
   but no edition gate. A Free-issued token got a 200 `initialize` from
   `/cortex/mcp`. PLANNING.md §4 is explicit: Streamable HTTP transport is
   Pro ("Free … No HTTP transport").
4. **OAuth + discovery** — `AbstractOauthController::beforeAction()` gates
   only on `httpEnabled` + IP throttle. `/oauth/*` and both `/.well-known/*`
   documents are live on Free.

Blast radius is licensing, not data: the registry stays Free-gated, so a
Free install over HTTP exposes only the 33 Free tools and `craft_exec` is
still rejected at the transport. But the entire "give it to your client"
Pro proposition (HTTP transport + tokens + audit UI + OAuth) works without
a Pro license.

## Locked decisions (proposed)

1. **Edition rejection is `403 Forbidden`**, body shaped like the existing
   kill-switch JSON: `{"error": "The HTTP transport requires the Cortex Pro
   edition."}`. Rationale: `503` means "configured off / temporarily
   unavailable" (kept for `httpEnabled=false`); the edition is a durable
   licensing state, and clear operator messaging beats hiding (`404`).
   There is no enumeration value in concealing a feature tier that is
   public on the Plugin Store listing.
2. **Gate ordering: edition check runs immediately after the `httpEnabled`
   kill switch** in both `McpController` and `AbstractOauthController` —
   before Origin/throttle work, so Free installs spend nothing on requests
   they will never serve.
3. **CP actions throw `ForbiddenHttpException`** (Craft renders the
   standard 403 CP error page; AJAX callers get the JSON error envelope
   for free). Not 404 — the fail-closed 404 on `actionActivityRow` guards
   per-row enumeration, a different axis; the edition gate is a tier
   boundary and should say so.
4. **Connection tab is Pro-only.** Its content (9.4) documents HTTP
   connection details, an exclusively Pro transport. Revisit if 9.4 grows
   a stdio panel.
5. **Tokens persist across downgrades.** Pro → Free leaves `cortex_tokens`
   rows intact but inert (the transport 403s before the bearer lookup);
   re-upgrading restores them. No migration, no cleanup job.
6. **Tab gating is presentation only** — the server-side action gates are
   the enforcement; hiding the tabs is UX. Both ship in this gate, but
   tests treat the controller gates as the invariant.

## Implementation steps (build → verify per layer)

### Step 1 — transport gates (the licensing boundary)

- `McpController::beforeAction()` — after the existing Gate 1 block
  (`httpEnabled`, line ~183) insert:

  ```php
  // Gate 1b — edition. The Streamable HTTP transport is Pro-only
  // (PLANNING.md §4: Free ships stdio only). 403, not 503 — the
  // kill switch means "configured off", this means "not licensed".
  if (!Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
      $this->_status(403, 'The HTTP transport requires the Cortex Pro edition.');
      return false;
  }
  ```

  Renumber the docblock gate lists (six → seven gates).
- `AbstractOauthController::beforeAction()` — same check between the
  `httpEnabled` gate and the IP throttle; add a `_proRequired()` helper
  mirroring `_httpDisabled()`. Covers `OauthController` (authorize, token,
  register, revoke) **and** `WellKnownController` (both discovery docs)
  through inheritance — no per-action work.

**Verify:** Pest — `McpControllerTest`: on Free, POST with a valid bearer
→ 403 + edition message (use `cortex_with_edition('free', …)` /
`cortex_with_pro_registry()` from `tests/Pest.php`; the registry helper is
needed wherever the dispatcher must actually resolve Pro tools).
`OauthControllerTest` + `WellKnownControllerTest`: each endpoint 403s on
Free, behaves as today on Pro. Wire check: `curl /cortex/mcp` and both
`/.well-known/*` on the Free playground → 403.

### Step 2 — CP action gates

- `SettingsController`: add one private helper:

  ```php
  /**
   * Throw unless the install is Pro. Tokens / Activity / Connection
   * are Pro surfaces (PLANNING.md §4); the registry gate cannot help
   * here because CP actions never pass through the tool dispatcher.
   *
   * @throws ForbiddenHttpException on Free installs.
   */
  private function _requirePro(): void
  ```

- Call it as the **first statement** in all nine Pro actions:
  `actionTokens`, `actionTokensTableData`, `actionTokenIssueSlideout`,
  `actionIssueToken`, `actionRevokeToken`, `actionActivity`,
  `actionActivityTableData`, `actionActivityRow`, `actionConnection`.
  (Settings + Allowlist actions stay Free.)
- The existing scaffolding regexes (`/^\s*\$this->requireAcceptsJson…/m`)
  use the `m` flag and keep passing with a line prepended; extend the
  scaffolding invariant instead: every Pro action body must `toMatch`
  `_requirePro()` as its first statement.

**Verify:** Pest — new `SettingsControllerEditionTest` (or sections in the
existing per-tab files): each of the nine actions throws
`ForbiddenHttpException` on Free and succeeds on Pro. The existing
harnesses no-op `requireAdmin` but must NOT no-op `_requirePro` — it is
private, so it cannot be overridden, which is exactly right. Browser:
`/admin/cortex/tokens` on Free → 403 page.

### Step 3 — tab map gating (UX)

- `_cp/_layout.twig`: build the map conditionally —

  ```twig
  {% set isPro = craft.app.plugins.getPlugin('cortex').is('pro') %}
  {% set tabs = { settings: …, allowlist: … } %}
  {% if isPro %}
      {% set tabs = tabs|merge({ tokens: …, activity: …, connection: … }) %}
  {% endif %}
  ```

  Keep tab order Settings · Tokens · Allowlist · Activity · Connection on
  Pro (merge order accordingly — build the full map in one expression per
  branch if `|merge` ordering reads poorly).
- Note `selectedTab` values are unchanged; Free never links to the hidden
  tabs and direct URLs are already covered by Step 2.

**Verify:** structural Pest invariant (scaffolding style): `_layout.twig`
contains the `is('pro')` conditional and the Free branch omits
`cortex/tokens`. Browser: A1 on Free shows exactly Settings + Allowlist;
A1 on Pro shows all five.

### Step 4 — surface the Cortex edition in `get_initial_context`

Smoke C3 found the doc expectation "confirm `edition` matches the flip"
unverifiable: the payload's only `edition` is Craft's. Add a `cortex`
block (`{"edition": "free"|"pro"}`, room for plugin version later) to the
`GetInitialContext` payload + docblock + test. Agents get told which tier
they are talking to — directly useful for clients deciding whether write
tools exist.

**Verify:** tool test asserts `cortex.edition` equals the active edition
under both `cortex_with_edition` branches; SMOKE-TEST.md C3 note removed.

### Step 5 (companion, small) — hide stdio-only tools from HTTP `tools/list`

Smoke D5 observation: `craft_exec` is advertised over HTTP though calls
are rejected. In `Server::_toolsList()`, when `$_transport === 'http'`,
filter tools whose class carries the `IsStdioOnly` attribute (the
`AttributeReader` support class already resolves it for `tools/call`).
List filtering is UX; the call-time hard reject stays the security gate
(defense in depth, same shape as the Gate 7 contract).

**Verify:** transport test — `tools/list` over HTTP excludes `craft_exec`
(41 on Pro), stdio still includes it (42 Pro / 33 Free); D4 rejection test
unchanged.

### Step 6 — full gate

- `ddev exec vendor/bin/pest` full suite on Free (default posture), plus
  the Pro-flipped tests via helpers — green, no skips added.
- `composer phpstan` + `composer check-cs`.
- Browser re-run of SMOKE-TEST.md A1 (both editions) + a Free-edition
  D-case: `initialize` over HTTP on Free → 403. Add that row to the smoke
  doc so the boundary stays covered pre-submission.
- Update `docs/SMOKE-TEST.md` A1-Free expectation text (Connection is
  Pro-only) and flip the go/no-go once green.

## Test-suite interaction warnings

- Edition flips in-process require `cortex_with_pro_registry()` when the
  dispatcher must see Pro tools (`tests/Pest.php` — boot-time registry).
  Pure controller/transport gates only need `cortex_with_edition()`.
- The full suite truncates `cortex_tokens` + `cortex_invocations`
  (Tokens/Activity `beforeEach`) — known side effect on the playground DB;
  irrelevant to CI but don't run it mid-manual-smoke again.
- Known leak (separate hygiene fix, not this gate): a full suite run
  leaves one empty-pattern `cortex_runtime_overrides` row behind, which
  surfaces as `""` in `get_initial_context`'s allowlist array.

## Out of scope

- Per-permission tab visibility (a non-admin with
  `cortex:view-activity` currently has no nav path to Activity) — Gate 7
  decision 7 territory, separate discussion.
- Connection tab content (9.4) and Skill authoring UI (9.6) — still
  slipped.
- `{name}` placeholder in VueAdminTable delete confirms (cosmetic nit from
  the smoke).

## Sizing

Six files in `src/` (`McpController`, `AbstractOauthController`,
`SettingsController`, `_cp/_layout.twig`, `GetInitialContext`,
`mcp/Server.php`) + 5–6 test files. Roughly half a day including the
browser re-verification.
