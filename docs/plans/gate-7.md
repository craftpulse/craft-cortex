# Gate 7 — HTTP transport, auth, audit, per-user filtering

Internal planning document. **Not** user-facing reference — for that see
[`docs/INSTALL.md`](../INSTALL.md), [`docs/SECURITY.md`](../SECURITY.md), etc.

This is the implementation plan for the Pro-tier foundation. Each sub-gate
is independently mergeable and leaves `develop-v5` green. Total estimated
effort: 2–3 months of focused engineering. Hand the brief for the next
sub-gate to `craft-feature-builder` when ready to start.

**Source planner output**: produced by `craft-planner` agent on 2026-05-14
against PLANNING.md §4.12 Gate 7. Locked architectural decisions live in
[`.claude/rules/architecture.md`](../../.claude/rules/architecture.md) under
"Per-user tool visibility."

## Locked decisions

These are settled. Don't relitigate without good reason.

1. **OAuth implementation**: `league/oauth2-server` (the *server-side* PHP League library — not `oauth2-client`). Security-audited, mature, transfers cryptographic correctness responsibility to a vetted dependency.
2. **Wire-auth model**: bearer-only on the MCP endpoint. Craft session auth is for the CP UI surfaces in Gate 9 only.
3. **Per-user tool filtering**: three-method gating contract on `ToolInterface` — `shouldRegister()` (static, boot-time), `filterFor(?User)` (per-request whole-tool), `inputSchemaFor(?User)` (per-request mode-enum filter). HTTP variants `Tools::asListPayloadFor()` and `Tools::getByNameFor()`. stdio path unchanged (default `null` user → default `true` filter).
4. **`craft_exec` stays stdio-only.** Hard-coded rejection at the dispatcher (already implemented in `src/mcp/Server.php:352`). HTTP transport regression test verifies.
5. **Audit-log wire shape is locked across phases.** DB `cortex_invocations` table must round-trip the same field set the stdio KV log emits. Pinning SIEM forwarders must keep working unchanged.
6. **DCR open by default** with a `$dcrEnabled` flag in Settings. MCP-native expectation.
7. **Origin allowlist**: `Settings::$allowedOrigins` (project config sync) plus `config/cortex.php` override. DNS-rebinding defense.
8. **Token TTLs (defaults)**: bearer tokens — no expiry by default; OAuth access — 1 hour; OAuth refresh — 30 days. All overridable.
9. **Rate limit**: per-user token-bucket. Defaults burst 60, sustained 5/sec. Backed by Craft's PSR-16 cache.
10. **Audit retention**: forever by default (`$auditRetentionDays = null`). Configurable; gc-pruned when set.
11. **stdio invocations**: KV-log only (no DB write). The DB `cortex_invocations` table is the HTTP audit log per PLANNING.md §4.9.
12. **`tools/list_changed` on mid-session permission change**: deferred. Documented limitation; clients reconnect to refresh.
13. **SSE cancellation**: contract lands in 7.1 (`CancellationToken` on `InvocationContext`); wire implementation in 7.7 or later. Adding the contract now avoids retrofitting every streaming tool later.
14. **`Last-Event-ID` SSE resumability**: deferred to Phase 3. Purely additive when added.
15. **Sub-gate sequencing**: 7.1 → 7.2 → 7.3 → 7.4 → 7.5 → 7.6 → 7.7. Each merges independently green.
16. **Token revocation in-flight semantics**: in-flight requests on a revoked token complete normally; no new requests on the revoked token are accepted. Bearer lookup happens once in `McpController::beforeAction()`; revocation is not propagated as a `CancellationToken` flip to running tools (that mechanism is reserved for the explicit `notifications/cancelled` MCP message in 7.7). Simplest correct behaviour per PLANNING.md §4.9.
17. **Bearer + OAuth lookup precedence (7.3)**: `McpController::beforeAction()` tries OAuth tokens first (short-lived, audience-bound, narrower scope), falls back to long-lived bearer tokens on miss. Rationale: checking the more-constrained credential first means a leaked long-lived bearer can never be silently treated as an OAuth access token with narrower scope. Both lookups memoize per-request via their respective services (`Oauth::lookupAccessToken()` and `Tokens::lookup()`). The disambiguation mechanism (JWT structure detection, sequential opaque lookup, etc.) is an implementation detail — what's locked is the precedence order.

## Sub-gate map

| # | Sub-gate | Complexity | Adds |
|---|---|---|---|
| 7.1 | HTTP transport skeleton (no auth, dev-mode) | Large | `McpController`, `transport/Http`, `Session`, `Sessions` service; `httpEnabled` flag; **`CancellationToken` on `InvocationContext` (contract-now)** |
| 7.2 | Bearer token auth | Large | `cortex_tokens` migration, `Tokens` service, `cortex/token/{issue,revoke,list}` console actions |
| 7.3 | OAuth 2.1 (`league/oauth2-server`) + DCR + RFC 9728/8414 metadata | Large (riskiest) | Three tables, `OauthController`, `WellKnownController`, six league-adapter repositories, JWT key bootstrap |
| 7.4 | Per-user filter implementation | Medium | `filterFor()` + `inputSchemaFor()` on `ToolInterface` + `AbstractTool`; `Tools::asListPayloadFor()` / `getByNameFor()` |
| 7.5 | Audit log DB table | Medium | `cortex_invocations` migration, `Invocations` service, `InvocationLogger::EVENT_LOG_CALL` hook |
| 7.6 | Rate limit | Small | `RateLimiter` service, two `Settings` properties |
| 7.7 | SSE generator forwarding (streaming) | Medium | `SseEmitter`, `Server::dispatchStreaming()` parallel to `dispatch()`; wire `notifications/cancelled` to flip `CancellationToken` |

## Sub-gate 7.1 — HTTP transport skeleton

**Goal**: `cortex/mcp` endpoint accepts POST/GET/DELETE, handshakes, dispatches the existing tool registry, terminates cleanly. No auth (anonymous-allowed) — that lands in 7.2. Gated behind `Settings::$httpEnabled = false` (default) so production stays off until 7.4.

**New**:
- `src/controllers/McpController.php` — extends `craft\web\Controller`. `$allowAnonymous = ['index']` (lifted in 7.2). `$enableCsrfValidation = false`. Origin validation. `MCP-Protocol-Version` header required on every request.
- `src/mcp/transport/Http.php` — request/response shaping. Spec mandates single message per POST. SSE upgrade deferred to 7.7.
- `src/mcp/Session.php` — `id`, `createdAt`, `lastSeenAt`, `protocolVersion`, `clientName`, `userId` (null until 7.2). Cache-backed.
- `src/services/Sessions.php` — `create`, `get`, `touch`, `terminate`. PSR-16 cache. TTL via `Settings::$sessionTtl` (default 3600).

**Modified**:
- `src/Plugin.php` — register route. `POST/GET/DELETE cortex/mcp` → `cortex/mcp/index` via `EVENT_REGISTER_SITE_URL_RULES`.
- `src/models/Settings.php` — `$httpEnabled = false`, `$allowedOrigins = []`, `$sessionTtl = 3600`.
- `src/tools/support/InvocationContext.php` — **add `CancellationToken` property (contract-now)**. Default unfired. Lock the shape from day one so streaming tools (Gate 8) check it; full wire implementation in 7.7.

**Untouched**: `src/mcp/Server.php` dispatcher contract. `_handleToolsCall`'s stdio-only check (line 352) already gates `craft_exec` over HTTP.

**Tests**: `tests/Controllers/McpControllerTest.php` — POST `initialize` returns 200 with `Mcp-Session-Id`; subsequent POST without the header → 400; missing/wrong `MCP-Protocol-Version` → 400; missing/wrong `Origin` → 403; DELETE terminates; `craft_exec` over HTTP returns the stdio-only rejection envelope.

**Verification**: Pest green. `ddev craft cortex/serve` (stdio) still works unchanged. Manual `curl` against the test environment.

## Sub-gate 7.2 — Bearer token auth

**Goal**: issue bearer tokens bound to a Craft user from a console command. HTTP transport authenticates against them via `Authorization: Bearer <token>`. Foundation OAuth wraps in 7.3.

**New**:
- `src/migrations/m260514_120000_cortex_tokens.php` — `cortex_tokens` table: `id, name, tokenHash` (SHA-256), `tokenPrefix` (first 8 chars, for CP UI identification without exposing the secret), `userId` (FK CASCADE), `scope` (JSON, nullable — ignored in 7.2), `expiresAt`, `lastUsedAt`, `dateCreated/Updated/Deleted`, `uid`. Indexes: `tokenHash`, `userId`, `expiresAt`, `dateDeleted`.
- `src/records/Token.php`, `src/models/Token.php`, `src/services/Tokens.php`.
- `src/console/controllers/TokenController.php` — `issue`, `revoke`, `list`.
- `src/db/Table.php` — add `TOKENS` constant.

**Modified**:
- `src/controllers/McpController.php` — remove anonymous-allowed. `beforeAction()` parses `Authorization: Bearer`, 401s with `WWW-Authenticate: Bearer realm="cortex"` on miss/bad (extended in 7.3 with `resource_metadata=` per RFC 9728), resolves user, sets `Craft::$app->getUser()->setIdentity()`.
- `src/mcp/Server.php` — `setUserId(?int)` setter so HTTP transport passes the authenticated user into the invocation context. Request-scoped — no leak.
- `src/mcp/Session.php` — bind `userId` at initialize. Token swap mid-session → 401, terminate.
- `src/models/Settings.php` — `$tokenTtlDefault = null` (no expiry).

**Tests**: `tests/Services/TokensTest.php` (issue/lookup/revoke/expiry/lastUsedAt), `tests/Controllers/McpControllerTest.php` (401 without header, with bad token, 200 with valid, audit-log line carries `user=<id>`), `tests/Console/TokenControllerTest.php` (issue prints plaintext once and that plaintext authenticates).

## Sub-gate 7.3 — OAuth 2.1 (`league/oauth2-server`) + DCR + metadata

**Goal**: spec-compliant OAuth flow. Bearer tokens from 7.2 continue to work; OAuth issues short-lived access tokens for clients that need delegation (Claude Desktop's hosted MCP setup, etc.).

**Dependencies to add to `composer.json`**:
- `league/oauth2-server: ^9.0` (brings `defuse/php-encryption`, `lcobucci/jwt`, `psr/http-message`).

**JWT key-pair management**: `storage/cortex/oauth-keys/private.key` + `public.key`, generated by `craft cortex/oauth/init-keys` console action. Gitignored. Permissions enforced (0600 private). Pattern matches Laravel Passport / similar.

**New**:
- `src/migrations/m260514_120100_cortex_oauth.php` — three tables:
  - `cortex_oauth_clients` — `id, clientId, clientName, redirectUris (JSON), scope, isPublic (bool), clientSecretHash (nullable), dateCreated/Updated, uid`. DCR (RFC 7591) inserts here.
  - `cortex_oauth_codes` — `code, clientId, userId, redirectUri, scope, resource, codeChallenge, codeChallengeMethod, expiresAt`. ~5 minute TTL.
  - `cortex_oauth_tokens` — `id, tokenType (access|refresh), tokenHash, userId, clientId, scope, audience, expiresAt, dateCreated, dateRevoked, uid`.
- Six league adapter repositories implementing:
  - `League\OAuth2\Server\Repositories\ClientRepositoryInterface`
  - `…\AccessTokenRepositoryInterface`
  - `…\RefreshTokenRepositoryInterface`
  - `…\AuthCodeRepositoryInterface`
  - `…\ScopeRepositoryInterface`
  - `…\UserRepositoryInterface`
- `src/services/Oauth.php` — orchestrates league's `AuthorizationServer` / `ResourceServer`. Adds MCP-specific bits league doesn't cover: audience binding (RFC 8707), DCR endpoint.
- `src/controllers/OauthController.php` — `actionAuthorize` (GET, consent screen, requires CP login), `actionToken` (POST, code+verifier → tokens), `actionRegister` (POST, RFC 7591 DCR), `actionRevoke` (POST, RFC 7009).
- `src/controllers/WellKnownController.php` — `actionProtectedResource` (RFC 9728), `actionAuthorizationServer` (RFC 8414).
- `src/templates/oauth/authorize.twig` — consent screen.
- `src/console/controllers/OauthController.php` — `init-keys` action.

**Modified**:
- `src/controllers/McpController.php::beforeAction()` — bearer lookup extended to check `cortex_oauth_tokens` too (not just `cortex_tokens`). OAuth-first precedence per locked decision 17. 401 includes `resource_metadata="https://<host>/.well-known/oauth-protected-resource"` per RFC 9728.
- `src/Plugin.php` — register OAuth + well-known URL rules. `/.well-known/*` paths at root (RFC requirement).

**Tests**: Full PKCE flow E2E (`register → authorize → token → tools/call → refresh`), metadata docs valid, audience binding (token for resource A rejected at resource B), PKCE S256 verification, code re-use rejected.

**Risks**:
- The library handles cryptographic correctness; we handle adapter mapping (mostly mechanical) and the MCP-spec bits the library doesn't cover.
- Most-complex sub-gate. Consider splitting at the metadata-endpoints boundary if it grows.

## Sub-gate 7.4 — Per-user tool filtering (architecture already locked)

**Goal**: implement the three-method gating contract from [`.claude/rules/architecture.md`](../../.claude/rules/architecture.md). **No new gates, no alternatives** — the architecture is locked.

**Modified**:
- `src/tools/ToolInterface.php` — add `filterFor(?User $user = null): bool` and `inputSchemaFor(?User $user = null): array`.
- `src/tools/AbstractTool.php` — defaults: `filterFor → true`, `inputSchemaFor → static::getInputSchema()`.
- `src/services/Tools.php` — add `asListPayloadFor(?User)` and `getByNameFor(string, ?User)`. Tools where `filterFor()` is false are omitted entirely.
- `src/mcp/Server.php` — when transport is HTTP, route `tools/list` and `tools/call` through the `*For()` variants using the resolved user. stdio path unchanged (passes `null`).

**Untouched**: every concrete Free tool. They inherit defaults; per-user behaviour lands in Gate 8 (Pro tools).

**Tests**:
- `tests/Mcp/ServerTest.php` — `tools/list` over HTTP with mock tool returning `filterFor → false` for non-admins → tool absent; admin sees it.
- `tests/Services/ToolsTest.php` — invariant: `asListPayloadFor(null) === asListPayload()`.
- Architecture invariant: every `ToolInterface` implementer resolves `filterFor` and `inputSchemaFor` (inherited or override).

## Sub-gate 7.5 — Audit log DB table

**Goal**: persist every HTTP tool invocation to `cortex_invocations` with the same field set the KV log emits. Round-trip invariant locked.

**Schema** (mirrors `InvocationLogger::formatEntry()`):

| Column | Type | Maps to KV field |
|---|---|---|
| `id` | pk | — |
| `toolName` | string(64) | `tool=` |
| `kind` | string(20) | `kind=` (success / tool_error / internal_error) |
| `durationMs` | integer | `duration_ms=` |
| `transport` | string(10) | `transport=` |
| `requestId` | string(255) nullable | `request_id=` |
| `userId` | int FK SET NULL | `user=` |
| `clientName` | string(255) nullable | `client=` |
| `argsRedacted` | text/JSON | `args=` (post-SecretRedactor) |
| `responseExcerpt` | text nullable | new — first ~2KB redacted |
| `errorClass` | string(255) nullable | `error_class=` |
| `errorMessage` | string(1000) nullable | `error_message=` |
| `tokenId` | int FK SET NULL | new — correlate to issuing token |
| `sessionId` | string(64) nullable | new — `Mcp-Session-Id` |
| `dateCreated` | dateTime | — |
| `uid` | uid | — |

Indexes: `toolName`, `userId`, `(transport, dateCreated)`, `kind`.

**New**: migration, `Invocation` Record, `Invocations` service. Soft writes — DB failure must never break the dispatch.

**Modified**: `src/tools/support/InvocationLogger.php` — add `EVENT_LOG_CALL` event. `Invocations` service subscribes. `formatEntry()` stays the wire-format truth.

**Tests**: round-trip parity (`formatEntry(row) === KV line`); invariant test that `cortex_invocations` columns ⊇ `formatEntry()` field set.

**Configurable**: `Settings::$auditResponseExcerptBytes = 2048`, `Settings::$auditRetentionDays = null`.

## Sub-gate 7.6 — Rate limit + burst-quota observability

**Goal**: per-user token bucket via PSR-16 cache. Prevent runaway agents. Surface bucket headroom on every audit row so operators can see throttle pressure before users hit the wall.

**New**:
- `src/services/RateLimiter.php` — `check(int $userId): RateLimitStatus` (read, doesn't consume), `consume(int $userId, int $cost = 1): RateLimitStatus`, `getStatus(int $userId): RateLimitStatus` (snapshot, used by CP widgets in Gate 9 and live ops introspection). Cache key `cortex:ratelimit:user:{id}`. Throws `RateLimitExceededException` carrying the status object when `consume()` exhausts the bucket.
- `src/values/RateLimitStatus.php` — readonly value object: `{remaining: int, limit: int, refilledAt: \DateTimeImmutable, retryAfter: int}`. Single return shape across `check()` / `consume()` / `getStatus()` so callers don't double-hit the cache for headroom.
- `src/exceptions/RateLimitExceededException.php` — carries the status + `retryAfter` seconds. The controller maps to the HTTP 429 + `Retry-After` header.
- `src/migrations/m260514_120300_cortex_invocations_rate_limit.php` — adds nullable `rateLimitRemaining` (int) column to `cortex_invocations`. Index unnecessary (analytic field, not a query predicate).

**Modified**:
- `src/controllers/McpController.php::beforeAction()` — after auth, before `parent::beforeAction()`: `consume()` the bucket. On `RateLimitExceededException`: write a `cortex_invocations` row with `kind=rate_limited`, then return 429 + `Retry-After`. On success: thread the post-consume `remaining` onto the dispatcher.
- `src/mcp/Server.php` — `setRateLimitRemaining(?int)` setter (parallels existing `setUserId` / `setTokenId` / `setSessionId` pattern). Threads into `InvocationContext`.
- `src/tools/support/InvocationContext.php` — readonly `?int $rateLimitRemaining` slot.
- `src/tools/support/InvocationLogger.php` — additively grows the KV line with `rate_limit_remaining=<int|->`. `formatEntry()` + `buildEntry()` both extended. The schema invariant test from Gate 7.5 verifies KV ⊆ DB stays true.
- `src/services/Invocations.php::record()` — persists `rateLimitRemaining` from the entry.
- `src/records/Invocation.php` — `kind` enum validator gains a fourth value: `rate_limited`. PHPDoc `@property` table grows the `rateLimitRemaining` slot.
- `src/models/Settings.php` — `$rateLimitBurst = 60`, `$rateLimitPerSecond = 5`. Validators on both (`> 0`).

**Tests**:
- Bucket allows N rapid calls, 429s on N+1, refills after sleep (fake clock).
- `consume()` returns a `RateLimitStatus` whose `remaining` matches the bucket state.
- A successful HTTP `tools/call` writes a `cortex_invocations` row carrying the post-consume `rateLimitRemaining`.
- A throttled call writes ONE row with `kind=rate_limited`, `errorClass` carrying `RateLimitExceededException::class`, and the response carries `Retry-After: <int>`.
- `getStatus()` returns the same shape `consume()` returns, without mutating the bucket — bucket state before and after `getStatus()` is identical.
- Schema invariant: `cortex_invocations` columns ⊇ KV fields (re-runs the Gate 7.5 invariant test against the extended KV line).

**Deferred (not in 7.6 scope, documented for completeness)**:
- A separate `RateLimiter::EVENT_THROTTLED` event for SIEM hooks. The `kind=rate_limited` audit row carries the same forensic data already; an event would be gold-plating until concrete demand surfaces.

## Sub-gate 7.7 — Streaming infrastructure

**Goal**: HTTP POST can upgrade to SSE when a tool returns a `Generator`. Yields emit as `notifications/progress`; final value is the response. Spec mandates server supports both response modes (JSON object OR SSE) — client chooses via `Accept`.

**New**:
- `src/mcp/transport/SseEmitter.php` — sets `Content-Type: text/event-stream`, disables buffering, writes `event: message\ndata: …\n\n` frames, flushes per frame.

**Modified**:
- `src/mcp/Server.php` — add `dispatchStreaming(array): Generator` parallel to `dispatch()`. Extracts pre-dispatch checks into shared `_validateToolCall()`. Wire `notifications/cancelled` parsing — when received, flips the `InvocationContext`'s `CancellationToken` so the running tool's generator can check it and short-circuit.
- `src/controllers/McpController.php` — if `Accept: text/event-stream` AND request is `tools/call`, dispatch through `dispatchStreaming()` and pipe to `SseEmitter`. Otherwise existing JSON-object response from 7.1.

**Tests**: streaming-tool yields progress frames + final result; non-streaming-tool collapses to single JSON object; cancellation notification mid-stream flips the token and the tool's `isCancelled()` check returns true.

## Per-feature manual verification

After each sub-gate, before moving on:

| After | Manual check |
|---|---|
| 7.1 | `curl -X POST http://cortex-test.ddev.site/cortex/mcp -H 'MCP-Protocol-Version: 2025-06-18' -H 'Origin: http://localhost' -d '<initialize JSON>'` returns 200 with `Mcp-Session-Id`; bad Origin → 403; bad protocol header → 400; `craft_exec` over HTTP → stdio-only rejection. |
| 7.2 | Issue a token from console; hit endpoint without auth → 401; with token → 200; revoke; retry → 401. Audit log shows `user=<id>`. |
| 7.3 | Claude Desktop's HTTP MCP setup auto-DCRs, redirects to consent, returns with working session. Tool call succeeds. |
| 7.4 | Mock-flip a tool's `filterFor()` to false for non-admins; log in as each — non-admin sees absence, admin sees presence. |
| 7.5 | Run several tool calls; check `cortex_invocations` rows match the KV log lines field-for-field. |
| 7.6 | Hammer the endpoint past burst → 429 + `Retry-After`; wait; success again. |
| 7.7 | Register a temp `slow_count` tool; `curl -N -H 'Accept: text/event-stream'` streams frames; send `notifications/cancelled` mid-stream; tool returns early. |

## Cross-cutting test strategy

- **HTTP transport in Pest**: Yii's `craft\test\Craft` trait + `actingAs($user)->post('/cortex/mcp', ...)`. No real HTTP server. Pattern matches `SettingsControllerTest.php`.
- **OAuth E2E**: three-POST flow in one test (register, exchange, hit MCP). Short-circuit the browser/redirect step by calling `OauthService::issueCodeForUser()` directly.
- **Audit-log shape parity**: round-trip a DB row through `InvocationLogger::formatEntry()` against the canonical KV format. Drift = test failure before wire shape breaks externally.
- **Architecture invariants** (extend `tests/Architecture/ConventionsTest.php`): every `ToolInterface` implementer resolves `filterFor`; `WWW-Authenticate` set on every 401.

## Out-of-scope clarifications

Confirmed against PLANNING.md §4.12:

- Gate 7 is HTTP transport + auth + audit + per-user filter + rate limit + streaming infra. Nothing else.
- **Gate 8**: Pro write tools + mode unlocks on Free tools. Consumes Gate 7's filter contract.
- **Gate 8.5**: Custom skills element type. Independent.
- **Gate 9**: CP UI (Tokens / Activity / Connection). Visualises Gate 7's tables.
- `tools/list_changed` — documented limitation per PLANNING.md §4.3. No implementation in Gate 7.
- SSE cancellation **contract** lands in 7.1 (`CancellationToken` on `InvocationContext`); SSE cancellation **wire** lands in 7.7. SSE resumability (`Last-Event-ID`) deferred to Phase 3.
- Edition gating: `Plugin::editions()` array + license-aware tool registration. Small; lands in 7.1 prelude or its own micro-gate.

## File-path reference (existing code)

Builder agents start by reading:

- `src/mcp/Server.php` — extend with `dispatchStreaming()`; user-id injection point at `_invocationContext()`.
- `src/services/Tools.php` — add `asListPayloadFor()`, `getByNameFor()`.
- `src/tools/ToolInterface.php` — add `filterFor()`, `inputSchemaFor()`.
- `src/tools/AbstractTool.php` — add defaults.
- `src/tools/support/InvocationLogger.php` — add `EVENT_LOG_CALL` hook.
- `src/tools/support/InvocationContext.php` — **7.1 adds `CancellationToken` (contract-now)**.
- `src/models/Settings.php` — ~10 new properties across sub-gates.
- `src/Plugin.php` — register new controllers and URL rules.
- `src/db/Table.php` — add `TOKENS`, `OAUTH_CLIENTS`, `OAUTH_CODES`, `OAUTH_TOKENS`, `INVOCATIONS`.
- `src/console/controllers/ServeController.php` — read-only (pattern reference).
- `src/migrations/Install.php` — read-only (pattern reference for new migrations).
- `tests/Architecture/ConventionsTest.php` — extend with per-user-filter invariant.
- `tests/Mcp/ServerTest.php` — extend with HTTP-transport regressions.
- `.claude/rules/architecture.md` — read-only (locked decision reference).
- `/Users/michtio/dev/craft-plugin-playground/PLANNING.md` — read-only (source of truth, §4.12 Gate 7).
