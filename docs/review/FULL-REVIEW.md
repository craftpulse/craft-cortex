# Cortex — Full-Codebase Adversarial Review (third pass)

A total-coverage, adversarial read of the Cortex MCP-server plugin on branch `gate-9-cp`,
commissioned to cover the surfaces the two prior passes (`docs/REVIEW.md`,
`docs/review/ARCHITECTURE.md`) read lightly or not at all: the CP `SettingsController`,
the console controllers (Install/Docs/Token/Edition/Oauth/Serve), the OAuth entity +
repository layer, the web OAuth/WellKnown controllers, every tool support helper, the
CP Twig + OAuth templates, the migrations/records/models, and the generator.

**This pass does not re-litigate findings the prior two docs already carry as remediated**
— I verified the load-bearing ones against the current code (results in the
*Reconciliation* section) and they hold. Everything below is either net-new or an
explicitly-evidenced claim that a prior remediation is incomplete.

## Verdict

The codebase remains materially above the median Craft plugin and the prior remediations
genuinely landed — origin fail-closed, the OAuth `httpEnabled` kill switch (shared
`AbstractOauthController`), the IP throttle, the gc prune across all three tables, the
audit-excerpt + `craft_command` redaction, Skill handle-immutability/trashed-handle
validation, and the elevated-session refusal seam are all present and correct.

**But this pass found one new GA blocker the prior OAuth deep-dive missed: a stored XSS
in the OAuth consent screen**, reachable on any install that has turned the Pro HTTP
transport on with DCR at its default. It executes attacker-controlled script in the
session of the logged-in Craft user who is being asked to authorize — i.e. inside the
exact security gate the consent screen exists to be. That must close before the Pro HTTP
surface ships. The remaining findings are minor/nit doc-and-code drift, including two
spots where the "minor/nit tail closed" claim in `REVIEW.md` is not actually true.

**Baseline reverified (2026-06-10, playground HEAD on `gate-9-cp`):**
`vendor/bin/pest` → **1120 passed / 0 skipped / 12,450 assertions** (26.9s). Green.
(PHPStan/ECS not re-run this pass — no source was modified; prior passes report L8 + ECS clean.)

## Findings by severity

| # | Severity | Dimension | Title | File:line | Effort |
|---|----------|-----------|-------|-----------|--------|
| 1 | **Blocker** | Security (XSS) | Stored XSS in OAuth consent screen via DCR-controlled `client_name` rendered with `\|raw` | src/templates/oauth/authorize.twig:71 | S |
| 2 | Minor | Security / doc-drift | Audit-excerpt boundary backstop is key-only; "covered at the boundary" claim overstated | src/mcp/Server.php:766,963 · CAPABILITIES.md:94 | S |
| 3 | Minor | Correctness / DX | `users` create is impossible over HTTP (mandatory `email` ∈ HTTP_REFUSED_FIELDS); undocumented | src/tools/system/Users.php:421,220 | S |
| 4 | Minor | Doc/code drift | Stale `-32002` references persist despite the "nit tail closed" claim | src/models/Settings.php:73 · src/tools/dev/CraftCommand.php:152,233 | S |
| 5 | Minor | Doc/code drift | `Settings` property docblocks contradict shipped behaviour (`allowedOrigins`, `httpEnabled`) | src/models/Settings.php:152,158 | S |
| 6 | Minor | Robustness | OAuth TTL settings validated as `string` only; a bad value throws an uncaught `\Exception` at server build | src/models/Settings.php:314 · src/services/Oauth.php:220 | S |
| 7 | Minor | Test coverage | No test asserts the consent screen escapes `clientName` (anchors blocker #1) | tests/ (absent) | S |
| 8 | Minor | Security (defense-in-depth) | `InstallController` atomic write forces `0644`, loosening an existing stricter-mode config file | src/console/controllers/InstallController.php:1375 | S |
| 9 | Nit | Correctness | `DocsController::actionAll` discards sub-action exit codes (always returns OK) | src/console/controllers/DocsController.php:66 | S |
| 10 | Nit | Housekeeping | `InstallController` backups accumulate unboundedly (`.bak.<ts>` per write, no cleanup) | src/console/controllers/InstallController.php:1348 | S |
| 11 | Nit | DX | Generator stub `execute()` still returns `[]`; still omits commented `#[Title]` (prior nits, unremediated) | src/generator/Tool.php:138,166 | S |

---

## Blocker

### 1 — Stored XSS in the OAuth consent screen via attacker-controlled DCR `client_name`

`src/templates/oauth/authorize.twig:71` — *Verified by code path; new this pass.*

```twig
{{ '{client} is requesting access to your Craft account.'|t('cortex', { client: '<span class="client">' ~ clientName ~ '</span>' })|raw }}
```

`clientName` is `$authRequest->getClient()->getName()` (`OauthController::_renderConsentScreen`,
line 394), which is the `client_name` a client supplied at **Dynamic Client Registration**.
`Oauth::registerClient()` validates `client_name` only as a non-empty string ≤255 chars
(`_requireString`, Oauth.php:376/738-751) — **no HTML sanitisation**. `Craft::t()` performs
ICU/`strtr` parameter substitution and does **not** HTML-encode its params, and the `|raw`
filter defeats Twig's auto-escaping. So a `client_name` of
`<img src=x onerror=…>` / `<script>…</script>` is substituted verbatim and emitted unescaped.

**Attack path (no privilege required to register):**
1. `dcrEnabled` defaults to `true`; with the Pro HTTP transport on, `POST /oauth/register`
   is open to anonymous callers. Register a client whose `client_name` carries a script
   payload and whose `redirect_uris` are anything HTTPS/loopback the attacker controls.
2. Craft a `GET /oauth/authorize?client_id=…&redirect_uri=…&…` link for that client and
   get a logged-in Craft user (any CP user — `authorize` requires a live session) to open it.
3. `validateAuthorizationRequest()` passes (client + redirect_uri are valid), the consent
   screen renders, and the payload executes **on GET, before any approve/deny** — in the
   victim's authenticated CP origin. From there: CSRF-token theft, silent self-approval of
   the OAuth grant, or any CP action the victim can perform.

The two other `|raw` sites are safe — `_allowlist-override-slideout.twig:29` interpolates a
hardcoded `<code>craft_command</code>` literal, and `authorize.twig:69/75/82` plus the
query-param hidden inputs (`:98-100`) all use auto-escaping. The vulnerability is isolated to
line 71's combination of `|raw` + an untrusted param.

Precondition is `httpEnabled = true` (default false) **and** `dcrEnabled = true` (default
true). That is exactly the Pro-HTTP production configuration the consent screen exists for,
so this is not a hypothetical — it is the live posture of the tier this template ships in.
The prior OAuth deep-dive verified PKCE, audience binding, key handling, and DCR redirect
validation but did not read the consent template's `|raw`.

**Fix (S):** drop `|raw` and stop interpolating untrusted markup into a translated string.
Either escape the param explicitly, or split the styling out of the translation — e.g.
`<p class="lead">{{ '{client} is requesting access to your Craft account.'|t('cortex', { client: clientName }) }}</p>` (auto-escaped, the `.client` span is cosmetic and can wrap a
separate auto-escaped `{{ clientName }}`). Belt-and-suspenders: strip/encode HTML in
`client_name` at registration in `Oauth::registerClient()`. Add the coverage test in finding #7.

---

## Minor

### 2 — Audit boundary backstop is key-only; the "covered at the boundary" claim is overstated

`src/mcp/Server.php:766,963` redact the persisted response excerpt with
`SecretRedactor::redactArray($result)` **only**. `redactArray()` (SecretRedactor.php:79-92)
replaces values whose *key* matches a secret needle and recurses into nested arrays, but it
never runs `redactString()` over flat string values. The live secret vectors are in fact
covered — `craft_command` (CraftCommand.php:195-196) and `craft_exec` both `redactString()`
their own `output` at the tool layer before returning. But `CAPABILITIES.md:94` sells the
boundary pass as defense-in-depth: *"so a future tool that forgets to redact its own result
is still covered at the boundary."* That is false for the most likely shape — a tool that
returns a secret embedded in a flat string under an innocuous key (`['result' => 'token=abc']`)
sails through `redactArray()` unredacted into `cortex_invocations.responseExcerpt`, which is
retained forever by default (`auditRetentionDays = null`).

**Fix (S):** either make the boundary a real backstop by also running `redactString()` over
the encoded payload at both dispatch sites, or correct the CAPABILITIES claim to state the
boundary catches secret-*keyed* fields only and per-tool `redactString()` is the actual
control for value-embedded secrets.

### 3 — `users` create is impossible over the HTTP transport, and it isn't documented

`src/tools/system/Users.php:421-422` runs `_assertTransportAllowsCredentialMutations()` for
both `create` and `update`. That guard refuses the call when **any** of
`HTTP_REFUSED_FIELDS = ['newPassword', 'email', 'admin']` (`:220`) is present and the
transport isn't stdio (`:508-530`). But `email` is *required* on create (`:307`), so every
HTTP `mode=create` throws. The class docblock and `HTTP_REFUSED_FIELDS` doc frame the gate as
blocking credential **mutations** (the account-takeover threat — changing an existing user's
email/password/admin behind Craft's elevated-session). Refusing *new-user creation with an
email* is a defensible but materially broader policy that is nowhere stated; a consumer reading
the docs will expect to create a user over HTTP with just an email and silently can't.

**Fix (S):** decide intent. If new-user creation should work over HTTP, exclude `email` from
the create-path check (gate only `newPassword`/`admin` on create; gate `email` only as a
*change* on update — the takeover vector). If creation is meant to be stdio-only, say so in
the tool description and `HTTP_REFUSED_FIELDS` docblock.

### 4 — Stale `-32002` references persist despite the "minor/nit tail closed" claim

`REVIEW.md:17` and `:206` state the `-32002` nit (flagged at `CraftCommand.php:151,226`) was
closed in the minor/nit tail. It was fixed in `PermissionedToolTrait` (now correctly says
*"there is no `-32002` code in this path"*, PermissionedToolTrait.php:64-65) but **three
references survive**:
- `src/models/Settings.php:73-74` — *"rejected at dispatch time with JSON-RPC code `-32002`"*.
- `src/tools/dev/CraftCommand.php:152` — *"return a precise `-32002` rejection that names `allowAdminChanges`"*.
- `src/tools/dev/CraftCommand.php:233` — *"so the `-32002` rejection message can name the exact cause"*.

The actual code throws a plain `ToolException` (message-text envelope, no numeric code), so
all three comments lie about the wire shape — the precise doc/code-drift class the project
treats as a real finding, and a false claim of remediation. **Fix (S):** delete the `-32002`
clauses in those three spots.

### 5 — `Settings` property docblocks contradict shipped behaviour

- `src/models/Settings.php:158-167` (`allowedOrigins`): *"Empty means permissive — every
  Origin is accepted, intended for dev only."* The shipped code fails **closed** for an empty
  allowlist outside devMode (`McpController::_passesOrigin`, :381-399, the remediation in
  `9e94759`). The property doc still describes the pre-remediation behaviour.
- `src/models/Settings.php:147-155` (`httpEnabled`): *"Defaults to false so production installs
  stay off until auth (sub-gates 7.2 / 7.3) and per-user filtering (7.4) land."* Auth and
  per-user filtering shipped; the rationale is stale.

**Fix (S):** update both docblocks to describe the current behaviour (empty allowlist =
rejected outside devMode; httpEnabled gates an authenticated transport).

### 6 — OAuth TTL settings aren't validated as parseable `DateInterval`s

`src/models/Settings.php:314` validates `oauthAccessTokenTtl` / `oauthRefreshTokenTtl` only as
`['string', 'min' => 2]`. `Oauth::getAuthorizationServer()` (:220-221) does
`new DateInterval($settings->oauthAccessTokenTtl)`, which throws a raw `\Exception` on a
malformed value (e.g. `"1h"` instead of `"PT1H"`). An operator typo in project config then
500s every `/oauth/token` and `/oauth/authorize` call with an opaque exception rather than a
clean settings-validation error. Trusted-config path, so low blast radius, but cheap to harden.

**Fix (S):** add a `defineRules()` validator that attempts `new DateInterval(...)` and rejects
on failure, or document the required ISO-8601 duration format prominently.

### 7 — No test asserts the consent screen escapes `clientName`

Tied to blocker #1. `tests/Controllers/OauthControllerTest.php` covers the flow but no test
registers a client with a markup-bearing `client_name` and asserts the rendered consent HTML
escapes it. The XSS would not have flagged in CI. **Fix (S):** add a test that registers a
client named `<script>x</script>`, renders `actionAuthorize` GET, and asserts the payload is
HTML-escaped in the response body.

### 8 — `InstallController` atomic write forces `0644`, loosening a stricter existing file

`src/console/controllers/InstallController.php:1375-1377` chmods the temp file to `0644`
unconditionally before renaming it into place. If the existing client config was `0600`
(a user who deliberately tightened a file that may hold tokens), re-running apply silently
widens it to world-readable. The backup preserves the old content but not the operator's mode
intent. **Fix (S):** stat the existing file and preserve its mode when one exists; default to
`0644` only when creating a new file.

---

## Nits

### 9 — `DocsController::actionAll` swallows sub-action failures
`src/console/controllers/DocsController.php:66-72` calls `actionTools()/Prompts()/Resources()`
and discards their return codes, always returning `ExitCode::OK`. A failed write (IOERR) on
one doc is reported on stderr but the command exits 0 — CI calling `cortex/docs/all` can't
detect a partial failure. **Fix:** OR the sub-results and return non-OK if any failed.

### 10 — Install backups accumulate unboundedly
`InstallController::_writeAtomic` (:1348-1359) writes a fresh `<file>.bak.<unix-ts>` on every
apply with no pruning. Repeated `--force` runs litter the client config dir. Harmless, but a
keep-last-N or a one-line note would be tidier.

### 11 — Generator stub still bare (prior nits, never remediated)
`src/generator/Tool.php:138-139` emits only `#[IsReadOnly]`/`#[IsIdempotent]` with no
commented `#[Title]`; `:166-167` scaffolds `execute()` as `// TODO: implement.\nreturn [];`
with no envelope-shape or `ToolException`/streaming guidance. Both were flagged as nits in
`REVIEW.md` and do not appear in any remediation table — confirming they remain open.

---

## Per-area verdicts (the ten scope areas)

1. **`SettingsController` (CP Tokens/Activity/Allowlist)** — *Solid.* The Activity fail-closed
   scoping is correct: `_scopeActivityQueryToUser()` pins non-admins to their own `userId`
   via `andWhere` *before* any caller filter (`:579`, `:928-937`), the foreign/missing row
   returns 404 not 403 to kill the enumeration oracle (`:690-695`), and all filter input flows
   through `Db::parseParam`/`Db::parseDateParam`. Mutations gate on
   `requireAdmin(requireAdminChanges:true)` + `requirePostRequest`. The activity endpoint
   correctly pushed pagination/sort into SQL (the prior review's in-PHP concern is resolved
   for Activity; Tokens/Allowlist remain in-PHP but are admin-bounded to dozens). Token
   plaintext surfaces exactly once and never enters the tuple. No new findings.

2. **`InstallController`** — *Good, two nits.* No path-traversal surface: `--client` is
   whitelisted against `CLIENTS`, paths derive from `$HOME`/`%APPDATA%`/cwd, never from
   caller input. Atomic write (backup → temp → rename) is correct; container-refusal for
   detect/auto is thorough (four ORed signals); PATH-walk avoids shell-exec. Findings: #8
   (mode-loosening), #10 (backup accumulation).

3. **Console `Docs`/`Token`/`Edition`/`Oauth`** — *Clean.* TokenController never leaks
   plaintext beyond the one issue line; revoke handles the race; init-keys is `--force`-guarded
   with correct 0600/0644. Only nit #9 (DocsController exit-code swallowing).

4. **OAuth entities + repositories** — *Excellent, fail-closed throughout.* Every repository
   treats a missing row as revoked/invalid (`AccessToken:140`, `RefreshToken:102`,
   `AuthCode:105`), confidential-client secret check fails closed (`Client:73-81`), public
   clients correctly defer to PKCE, `UserRepository` returns null (Password grant disabled),
   `ScopeRepository` rejects unknown scopes. Plaintext never persisted (SHA-256 of jti only).
   No findings.

5. **Web `Oauth`/`AbstractOauth`/`WellKnown` controllers** — *Strong, except the consent
   template.* The shared `AbstractOauthController` kill switch (503 when `!httpEnabled`) and
   IP throttle (`consumeKey('oauth:ip:'+ip)`) cover all three controllers; CSRF is correctly
   re-enabled for the `authorize` consent POST only. The controllers themselves are clean —
   **but they render `authorize.twig`, which carries blocker #1.**

6. **~42 tools (content/schema/system/graphql/dev/workflow)** — *Good discipline.* Spot-checks
   confirm `status(null)->site('*')` scoping on trashed/draft/list queries (Entry.php:557,607,829),
   `_assertPermission` resolves per-resource UIDs before the wildcard-sentinel guard, and the
   `users` elevated-session refusal fails closed on null context. CraftCommand redacts its own
   stdout. Findings: #3 (users HTTP-create), #4 (CraftCommand `-32002` comments).

7. **`tools/support/*`** — *Clean.* InvocationLogger redacts args, threads context, soft-fails;
   ElementSerializer correctly stubs non-eager `ElementQuery` to prevent N+1; ConsoleRunner
   enforces single-flight non-re-entrancy and strips ANSI; StdoutCaptureFilter suppresses
   correctly; CancellationToken is monotonic and poll-callback-driven. SecretRedactor is the
   only support finding (#2, key-only boundary).

8. **migrations / records / models / db / elements/db** — *Good.* `Install.php` is idempotent
   with guarded `createTable`, correct FK CASCADE (skills→elements) / SET NULL (overrides→users),
   and indexed query columns. `SkillQuery::beforePrepare()` uses `addSelect()` (additive) and
   `Db::parseParam` with type asserts — textbook. Skill handle is format-validated, immutable
   post-create, and trashed-handle-aware. Findings: Settings doc/validation (#4,#5,#6).

9. **templates + CP JS** — *One blocker.* The Activity detail slideout (`_activity-detail-slideout.twig`)
   auto-escapes all persisted audit data (args/error/response/clientName) — safe. Token/allowlist
   slideouts use `csrfInput()`. The consent screen is the sole `|raw`-with-untrusted-input sink
   (blocker #1). CP JS (`dist/cortex.js`) shows no `innerHTML`/`.html(` sinks and injects only
   server-rendered (escaped) slideout HTML; not deep-read beyond the grep, as it is a built
   bundle (no committed source).

10. **generator / PluginTrait / Services / Cortex / config** — *Clean.* GC listener wires all
    three prunes (allowlist + invocations + oauth, PluginTrait.php:121-123); registry builds
    once. Only the generator-stub nits (#11) remain.

---

## Reconciliation with the prior reviews

**Confirmed genuinely remediated (verified against current code, not taken on faith):**
- OAuth/`.well-known` `httpEnabled` kill switch — present via `AbstractOauthController::beforeAction` (503 before any DB work), inherited by both controllers. ✓
- Anonymous OAuth throttle — `AbstractOauthController::_passesIpThrottle` + `RateLimiter::consumeKey`, applied to `register`/`token`/`revoke`. ✓
- OAuth prune — `Oauth::pruneExpired()` deletes expired codes and expired/revoked tokens, wired to `Gc::EVENT_RUN`; fail-closed-safe. ✓
- Audit `response_excerpt` + `craft_command` redaction — dispatch-site `redactArray` (Server.php:766,963) **plus** tool-layer `redactString` on `craft_command.output` (CraftCommand.php:195-196). Live vector closed (see #2 for the residual doc overstatement). ✓
- OAuth consent POST CSRF — re-enabled for `authorize` only (OauthController.php:135-142). ✓
- Origin fail-closed outside devMode — McpController.php:381-399. ✓
- Skill handle format + post-create immutability + trashed-handle validation — Skill.php:413-489. ✓
- Elevated-session refusal over HTTP via real-transport seam (`ContextAwareToolInterface`), fail-closed on null context — Users.php:508-530. ✓
- stdio hardening (bounded read, signal handlers, fatal envelope, stdout-log redirect, capture re-entrancy guard) — ServeController.php / ConsoleRunner.php, all present. ✓
- PermissionedToolTrait `-32002` removed — ✓ (but see #4 for the references that survive elsewhere).

**Where a prior remediation claim is incomplete (fresh evidence):**
- `REVIEW.md:17` claims "the minor/nit tail is also closed." The `-32002` nit is **not** fully
  closed — finding #4 documents three surviving references (Settings.php:73, CraftCommand.php:152,233).
- The generator-stub nits (`REVIEW.md` rows for `generator/Tool.php:165-168` and `:138-139`)
  are not in any remediation table and remain open (finding #11) — consistent with their nit
  status, but worth recording as still-open rather than silently dropped.

**Net-new beyond both prior passes:** the consent-screen stored XSS (blocker #1) — the single
most important finding in this review, and the one gap a coherence review + a happy-path test
suite + an OAuth-crypto-focused deep dive all missed because it lives in a Twig `|raw`, not in
the PHP security path.

## Coverage statement

Read in full: `controllers/SettingsController` (1135 lines), `console/controllers/InstallController`
(1384), `DocsController`, `TokenController`, `EditionController`, console `OauthController`,
`ServeController`; `controllers/AbstractOauthController`, web `OauthController`, `WellKnownController`,
and the relevant `McpController` Origin/bearer section; all six `oauth/repositories/*` and the
`services/Oauth` service (839); `services/Invocations`; `tools/support/{InvocationLogger,
ElementSerializer, SecretRedactor, ConsoleRunner, StdoutCaptureFilter, CancellationToken}`;
`tools/PermissionedToolTrait`, `tools/ContextAwareToolInterface`, `tools/dev/CraftCommand`,
`tools/system/Users` (transport-seam + execute + schema sections); `elements/db/SkillQuery`,
`elements/Skill` (rules/validators); `models/Settings`; `migrations/Install`; `generator/Tool`;
all CP Twig (`_activity-detail-slideout`, `settings`, slideout partials via grep) and
`oauth/authorize.twig`. Grepped all templates + the built CP JS for `|raw`/`innerHTML`/`.html(`/CSRF.
Re-ran the Pest suite (1120/0). Skimmed (not line-by-line): the remaining content/schema tools
beyond Entry, the prompts/resources services, and the SSE transport — the prior passes' core-spine
read covers these and nothing in the sampled set suggested a systemic issue. The built
`dist/cortex.js` bundle was grep-audited only (no committed source).
