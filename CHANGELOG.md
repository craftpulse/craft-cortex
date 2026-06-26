# Changelog

All notable changes to Cortex are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning per
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — authentication and authorization (Pro HTTP transport)

- **Capability-grained OAuth scopes.** Replaced the coarse `read` / `write`
  scope pair with a capability vocabulary — `content:read`,
  `content:write`, `content:publish`, `content:delete`, `assets:write`,
  `schema:read`, `system:read`, `users:read`, `users:write`. Every tool
  maps to the scope it requires; over the HTTP transport a tool is visible
  and callable only when the token's granted scopes cover it, the user's
  Craft permissions allow it, and the edition permits it. The old
  `read` / `write` scopes are still accepted and expanded to the matching
  capabilities, so existing tokens keep working.
- **In-band elevation for high-stakes operations.** Added an
  `/oauth/elevate` re-authentication flow. Changing a user's password,
  email, or admin status, and publishing or deleting content, are now
  permitted over the HTTP transport only after a fresh re-authentication
  (password + 2FA via Craft's login). The elevation is short-lived,
  tracked server-side, and bound to the specific access token. Code
  execution (`craft_exec`) remains stdio-only and is never unlocked by
  elevation. This reverses the previous blanket refusal of credential
  mutations over HTTP.
- **Refresh-token rotation with theft detection.** Refresh tokens now
  rotate on every use and are grouped into a family. Presenting an
  already-used refresh token (a sign of token theft) revokes the entire
  family of tokens, records a security event in the audit log, and forces
  the client to re-authorize.
- **Client approval gate for self-registration.** Clients that register
  themselves automatically now start unapproved and cannot connect until
  an administrator approves them on the new **Clients** control-panel
  screen. A new setting auto-approves clients for trusted or development
  installs.

### Added — control panel

- **Clients screen.** A new admin-only screen listing every registered
  OAuth client, with inline approve / revoke actions.

### Changed — control panel

- The Cortex CP screens now live under a standard sidebar section with a
  Settings / Temporary grants / Tokens / Activity / Connection subnav
  (edition- and permission-gated), replacing the in-page tab bar. Added a
  CP nav icon.
- The Settings screen's allowed-commands editor is now a grouped toggle
  browser: every available console command (core plus installed plugins)
  is listed by group with on/off switches and a live filter, instead of a
  raw glob-pattern table. Content-level and admin-level commands are shown
  in separate sections — admin-level commands (project config, migrations,
  scaffolding, schema, fixtures) are clearly marked and only dispatch when
  `allowAdminChanges` is enabled. Hand-written glob patterns are preserved
  in a per-section "Custom patterns" table.
- Renamed the "Allowlist" screen to "Temporary grants" and added an
  "Effective allowlist" panel showing the combined result of the
  configured defaults plus active grants.

### Added — Gate 7.7 (Pro tier, SSE streaming infrastructure)

- `StreamableToolInterface` — opt-in contract for tools that stream
  progress. `stream(array, InvocationContext): Generator` yields
  `{progress, total?, message?}` frames and returns the terminal
  payload. Non-streamable tools (the existing 33 Free tools) are
  unaffected — the dispatcher's degrade-gracefully path collapses
  them to a single one-frame SSE response.
- `Server::dispatchStreaming()` — generator-driven counterpart to
  `dispatch()`. Yields one `notifications/progress` JSON-RPC envelope
  per progress frame, plus one terminal `tools/call` response. Pulls
  `_meta.progressToken` from the original `tools/call` and threads it
  onto every progress frame per MCP spec §progress.
- `notifications/cancelled` is now wired end-to-end. The client posts
  a JSON-RPC notification referencing the in-flight request id; the
  server writes a cache slot the streaming dispatcher's
  `CancellationToken` poll-callback observes between yields. On flip
  the tool short-circuits with a `notifications/cancelled` terminal
  envelope and an audit row with `kind=cancelled`.
- `SseEmitter` — wire framing for `text/event-stream` responses. Sets
  the canonical headers (Content-Type, Cache-Control, X-Accel-
  Buffering, Connection), disables PHP output buffering, writes
  `id`/`event`/`data` framed lines with UUIDv4 frame ids
  (forward-compatible with Phase-3 Last-Event-ID resumability),
  bypasses Yii's response framing.
- `McpController` upgrades `tools/call` to SSE when the client
  advertises `Accept: text/event-stream`. Other methods stay on the
  JSON path even with the SSE Accept header per the spec's
  "single-frame SSE for non-tools/call is optional" guidance.
- `cortex_invocations.kind` enum gains `cancelled` for mid-stream
  cancellation events. The schema-invariant test (locked Gate-7.5
  decision 5) ratifies the addition; existing `success`,
  `tool_error`, `internal_error`, and `rate_limited` rows are
  unaffected.
- `_streaming_test` operator-facing fixture tool under
  `src/tools/dev/StreamingFixtureTool.php`. Opt-in via
  `CORTEX_STREAMING_FIXTURE=1` env var; production installs never see
  it. Yields three deterministic progress frames + a terminal
  payload, with cooperative cancellation between yields — drive it
  with `curl -H 'Accept: text/event-stream'` to verify end-to-end
  SSE health on a new install.
- 8 new Pest tests across `tests/Mcp/StreamingTest.php` and
  `tests/Controllers/McpControllerTest.php` covering: progress-frames
  yielded + terminal envelope, non-streamable degrade to one-frame
  SSE, `notifications/cancelled` mid-stream flips the token,
  cancellation writes a `kind=cancelled` row, exactly one audit row
  per stream completion (not per frame), and the SSE-vs-JSON wire
  selection based on `Accept` header.

### Deferred to Phase 3

- `Last-Event-ID` SSE resumability (locked decision 14). Frame ids
  are already emitted on every frame, so the wire is forward-
  compatible — Phase 3 adds a per-stream replay buffer keyed off the
  emitted ids and a `GET /cortex/mcp?Last-Event-ID=…` resume path
  without changing the wire shape.

### Added — Gate 7.6 (Pro tier, rate limit + burst-quota observability)

- `RateLimiter` service — token-bucket per authenticated HTTP user.
  Default burst 60 / refill 5 per second; configurable via
  `rateLimitBurst` and `rateLimitPerSecond` in `config/cortex.php`.
  Bucket state is stored in Craft's cache so the limiter survives
  process restarts without losing accumulated debt.
- `RateLimitStatus` value object returned by `RateLimiter::consume()`
  carries `allowed`, `remaining`, `resetAt`, and `retryAfter` — the
  controller reads these without knowing how the limit is implemented.
- `McpController::beforeAction()` checks the limiter after successful
  auth. Exhausted callers receive `429 Too Many Requests` with a
  `Retry-After` header; the body is a standard MCP error envelope.
- Throttled requests write a `kind=rate_limited` audit row to
  `cortex_invocations` (Gate 7.5 table) with `rateLimitRemaining = 0`
  and no `toolName` / `responseExcerpt`. Audit retention and the
  `EVENT_LOG_CALL` event fire for rate-limited rows the same as for
  tool-call rows.
- 9 new Pest tests across `tests/Services/RateLimiterTest.php` and
  `tests/Controllers/McpControllerTest.php` covering: first-request
  allowed, burst exhaustion returns 429, `Retry-After` header set,
  bucket refills after the quota window, per-user isolation (user A
  exhausted does not throttle user B), and `kind=rate_limited` audit
  row written on 429.

### Added — Gate 7.5 (Pro tier, audit log DB table)

- `cortex_invocations` table — one row per authenticated HTTP
  `tools/call`. Columns: `userId`, `clientId`, `toolName`,
  `arguments` (post-redaction JSON excerpt), `responseExcerpt`
  (capped at `auditResponseExcerptBytes`, default 2048),
  `durationMs`, `kind` (`success`, `tool_error`, `internal_error`,
  `rate_limited`, `cancelled`), `rateLimitRemaining`, `dateCreated`.
- `Invocations` service — `log(InvocationRecord): void` is the
  soft-write contract: failures are logged at `warning` level and
  suppressed so a DB hiccup never interrupts an MCP response. A
  configurable `auditRetentionDays` (default `null` = forever)
  prunes rows during Craft's `gc` sweep via `Gc::EVENT_RUN`.
- `EVENT_LOG_CALL` fires after each row is persisted, carrying the
  hydrated `InvocationRecord`. Operators and third-party plugins can
  forward rows to Elasticsearch, Datadog, etc. without touching core.
- `InvocationQuery` — fluent query builder over `cortex_invocations`.
  Supports `byUser()`, `byTool()`, `byKind()`, `since()`, `until()`,
  `limit()`, and `latest()`. Intended as the read surface for a
  future CP audit dashboard.
- `auditResponseExcerptBytes` and `auditRetentionDays` settings
  added to `models/Settings`. Documented in `config/cortex.php`.
- 12 new Pest tests across `tests/Services/InvocationsTest.php`
  covering: row written on success, suppressed on DB error, retention
  prune removes old rows only, `InvocationQuery` filters, and
  `EVENT_LOG_CALL` fires with the correct record.

### Added — Gate 7.4 (Pro tier, per-user tool filtering)

- Three-method gating contract added to `ToolInterface`:
  `shouldRegister(): bool` — static, runs once at boot, removes
  tools nobody on the install can use (e.g. `craft_exec` when
  `execEnabled = false`); `filterFor(?User $user): bool` — per-
  request per-user gating, default `true`, Pro tools override to
  check Craft permissions; `inputSchemaFor(?User $user): array` —
  per-request schema rewrite, default delegates to static
  `getInputSchema()`, mode-gated tools filter their `mode` enum.
- `Tools::asListPayloadFor(?User)` and `Tools::getByNameFor(string,
  ?User)` are the HTTP variants consumed by `McpController`. The
  existing `asListPayload()` and `getByName()` stay for stdio (`null`
  user is the default-true path).
- `shouldRegister()` promoted from instance to static per the Craft
  contract idiom — the service calls it once at registry build time
  without instantiating the tool.
- No concrete tool overrides ship in Gate 7.4 — all existing Free
  tools default to `filterFor() = true` and `inputSchemaFor() =
  getInputSchema()`. The Pro override layer (entry-save gate,
  user-edit gate, etc.) lands with the first Pro write-tool in a
  future gate.
- 8 new Pest tests in `tests/Services/ToolsTest.php` covering:
  `asListPayloadFor(null)` matches `asListPayload()`, `filterFor`
  returning `false` hides the tool from `asListPayloadFor`, `getByNameFor`
  returns null for filtered tools, and `inputSchemaFor` override
  narrows the schema for the requesting user.

### Added — Gate 7.3 (Pro tier, OAuth 2.1 + DCR + discovery metadata)

- `league/oauth2-server` dependency. Authorization Code + PKCE (S256
  only — `plain` rejected) + Refresh Token grants. RSA 2048-bit JWT
  signing keys generated by `cortex/oauth/init-keys` (0600 on the
  private key) and stored under `storage/cortex/oauth-keys/`.
- Three new tables — `cortex_oauth_clients`, `cortex_oauth_codes`,
  `cortex_oauth_tokens`. Hashed-at-rest secrets; codes are one-shot;
  access tokens carry the RFC 8707 audience indicator in `aud`.
- `Oauth` service orchestrates league's `AuthorizationServer` and
  `ResourceServer`, plus DCR (RFC 7591) and token revocation
  (RFC 7009). Six league-adapter repositories under
  `src/oauth/repositories/`; six entity classes under
  `src/oauth/entities/`. AccessTokenEntity overrides `convertToJWT`
  to bake the resource indicator into `aud` (audience binding) with
  the client id in a custom `cid` claim.
- Four new web endpoints — `oauth/authorize`, `oauth/token`,
  `oauth/register`, `oauth/revoke` — plus the two RFC 8414 / 9728
  discovery endpoints at site root `.well-known/oauth-authorization-server`
  and `.well-known/oauth-protected-resource`. Twig consent screen at
  `src/templates/oauth/authorize.twig`.
- `McpController::beforeAction()` bearer lookup: OAuth-first per the
  locked precedence in `docs/plans/gate-7.md` decision 17, bearer
  fallback on miss. Audience binding rejects tokens whose `aud`
  doesn't match the canonical `cortex/mcp` URL — RFC 8707 confused-
  deputy defense. 401 challenge now carries `resource_metadata=<URL>`
  per RFC 9728.
- New settings: `$dcrEnabled = true` (open registration by default),
  `$oauthAccessTokenTtl = 'PT1H'`, `$oauthRefreshTokenTtl = 'P30D'`.
- 51 new Pest tests across service, controllers, well-known, and
  console — including a full PKCE flow E2E (register → authorize →
  token → MCP call), code-reuse rejection, S256 verifier mismatch,
  audience binding, token revocation, and init-keys idempotency.

### Added — Gate 7.2 (Pro tier, bearer token auth)

- `cortex_tokens` table + `Tokens` service. SHA-256-hashed-at-rest;
  the plaintext is surfaced ONCE at issuance and never returned by
  any service method again. Soft-delete + per-request lookup
  memoization.
- Three console actions: `cortex/token/issue`, `cortex/token/revoke`,
  `cortex/token/list`. Plaintext token printed only on issue, never
  by list. `--ttl=<seconds>` flag on issue; `Settings::$tokenTtlDefault`
  for the global default (null = no expiry).
- HTTP transport `/cortex/mcp` now requires `Authorization: Bearer
  <token>`. Missing / invalid credentials return 401 with
  `WWW-Authenticate: Bearer realm="cortex"`. Authenticated user is
  bound to the session at initialize and validated against the
  bearer token on every touch (catches mid-session token-swap).
- In-flight revocation semantics: revoked tokens fail the next
  `beforeAction()` lookup; in-flight requests on a revoked token
  complete normally. Per PLANNING.md §4.9 and the locked decision
  in `docs/plans/gate-7.md`.

### Added — Gate 7.1 (Pro tier, HTTP transport scaffolding)

- HTTP transport skeleton at `POST/GET/DELETE /cortex/mcp`. Behind
  `Settings::$httpEnabled = false` by default; flip to true to expose.
  Spec target MCP 2025-06-18 — `MCP-Protocol-Version` header validated,
  `Origin` header validated against `Settings::$allowedOrigins`,
  `Mcp-Session-Id` header drives stateful session lookup, single
  JSON-RPC message per POST.
- Session storage in PSR-16 cache via new `Sessions` service; sliding
  TTL via `Settings::$sessionTtl` (default 3600).
- `CancellationToken` added to `InvocationContext` (contract-now per
  `docs/plans/gate-7.md`). Wire implementation in 7.7; contract here
  locks the shape so streaming tools can opt in cooperatively without
  a later interface break.
- No auth on the endpoint in 7.1 — auth lands in 7.2 (bearer tokens).

## [5.0.0] - 2026-05-14

### Initial release

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server for Craft CMS 5. It connects MCP-capable AI assistants — Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf — to your Craft project so they can introspect your content model, read your content safely, run guarded dev actions, and learn Craft conventions from a bundled expert-skill corpus.

This release ships the free tier: a stdio-transport MCP server with **33 tools**, **8 prompts**, and **77 resources**. The Pro tier (HTTP transport, content-write capabilities, Craft permission gating, audit-log UI) is on the Phase 2 roadmap.

### Added

#### Tool catalogue (33 tools)

- **Orientation (1):** `get_initial_context` — bootstrap snapshot a fresh agent should call first. Returns Craft version + edition + environment, primary site, sites/sections/element-types index, the bundled `craftcms_*` skill prompts, `craft_exec` posture, effective command allowlist, and a short operating-hints list. Replaces three or four orientation tool calls. Registered first in `tools/list`. Name matches Contentful's convention so clients with heuristics around `get_initial_context` pick it up.
- **Schema & structure (10):** `sections`, `entry_types`, `fields`, `field_types`, `category_groups`, `tag_groups`, `volumes_and_filesystems`, `sites`, `image_transforms`, `element_types`. Each collapses list / get / count behind a single optional `handle` argument.
- **Content reading (5):** `entries`, `assets`, `categories`, `tags`, `globals`. Full element-query surface — section / type / status / author / `relatedTo` filters, eager loading via `with: [...]`, structure params, pagination (default 100, max 1000), site filter, count mode, single-id shortcut. Relational fields stub by default (`{type: "relation", loaded: false}`) so the LLM never accidentally triggers an N+1 walk.
- **System & diagnostics (9):** `system_info`, `config`, `plugins`, `routes`, `system_diagnostics`, `database_schema`, `extensibility`, `permissions_and_groups`, `search_skills`. `config` and `system_diagnostics` are multi-mode introspection tools; `search_skills` does keyword search across the bundled skills corpus.
- **GraphQL (1):** `graphql` — list schemas, get SDL, list tokens (token values are never returned; a SHA-256 fingerprint surfaces for correlation only).
- **Dev actions (4):** `clear_caches`, `resave`, `craft_command` (allowlist-gated), `craft_exec` (six security gates, stdio-only).
- **Workflow & audit (3):** `drafts_and_revisions` (list / compare drafts and revisions), `content_audit` (relations / unused-assets / propagation audits), `import_export` (structured-JSON export with format-versioned envelope; Pro adds the round-trip import).

Five tools (`get_initial_context`, `system_info`, `routes`, `plugins`, `permissions_and_groups`) declare an MCP `outputSchema`. The dispatcher dual-emits `structuredContent` alongside the legacy text-content block per MCP 2025-06-18 §6.2 — spec-aware clients read `structuredContent` and can validate against the schema; older clients still consume the text block. Polymorphic tools (list-or-single-or-count) intentionally skip `outputSchema` to avoid hand-written `oneOf` schemas that drift from runtime; the convention is documented on `AbstractTool::outputSchema()`.

The full per-tool argument schemas, output schemas, and MCP annotations live in [`docs/TOOLS.md`](docs/TOOLS.md), regenerated via `ddev craft cortex/docs/all`.

#### Skills (8 prompts + 77 resources)

The bundled `michtio/craftcms-claude-skills` package — ~27,000 lines of authored Craft expertise across 8 skills — surfaces over MCP:

- **Prompts** under the `craftcms_*` namespace: `craftcms_extending`, `craftcms_templates`, `craftcms_cp_javascript`, `craftcms_content_modeling`, `craftcms_php_standards`, `craftcms_twig_standards`, `craftcms_ddev`, `craftcms_setup`. The LLM picks them up automatically when relevant, so Cortex's tools answer alongside expert context rather than in a vacuum.
- **Resources** under the `craft-skills://` URI scheme — one per `SKILL.md` router plus every reference deep-dive — accessible on-demand for the LLM's deeper dives, plus the bundled Claude Code agents under `craft-skills://agents/<name>`.

Catalogue lives in [`docs/PROMPTS.md`](docs/PROMPTS.md) and [`docs/RESOURCES.md`](docs/RESOURCES.md).

#### Install command

- `ddev craft cortex/install --client=<name>` prints copy-paste config snippets for the seven supported MCP clients. DDEV-aware — auto-emits the `docker exec` invocation form when running inside a DDEV project.
- `ddev craft cortex/install/apply --client=<name>` writes the entry directly into the client's per-platform config file. Atomic write (temp file + rename), `.bak.<unix-timestamp>` backup of the existing file, idempotent re-runs, `--dry-run` for previewing the diff, `--force` to overwrite an existing Cortex entry.
- `php craft cortex/install/detect` scans the host filesystem for installed MCP clients (`/Applications`, `PATH`, `%LOCALAPPDATA%`) and prints a status table. Read-only — never writes.
- `php craft cortex/install/auto` runs detection plus per-client confirm-and-apply in one pass — the "I installed Cortex, now wire it up everywhere" flow. Honours `--dry-run` and `--force`. Both `detect` and `auto` refuse to run from inside any container (DDEV, plain Docker, Lando, Sail, Podman, LXC, Kubernetes) because the container can't see the host filesystem; the manual snippet form (`ddev craft cortex/install`) is the documented fallback inside DDEV.

Per-client install instructions, troubleshooting, and the manual snippet flow are in [`docs/INSTALL.md`](docs/INSTALL.md).

#### Settings & configuration

- Plugin settings model — `allowedCommands`, `execEnabled`, `execDryRunDefault`, `runtimeOverrideTtl`. All four exposed in the **Settings → Cortex** CP page; `allowedCommands` syncs across environments via project config.
- `config/cortex.php` reference template (heavily commented) for environment-specific overrides — `*` wildcard plus named-environment blocks (`production`, `staging`).
- Runtime allowlist overrides — admin-issued, auto-expiring DB-backed entries that layer on top of the project-config defaults. Useful for short-term command grants without a deploy. Expired rows are pruned inline during Craft's regular GC sweep.
- Settings reference: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md).

#### Security model

- **Six security gates on `craft_exec`** — dry-run-default, structured output, secret redaction, destructive-op guard, hard HTTP rejection (stdio-only), `destructiveHint: true` annotation. The full surface is documented per-gate in [`docs/SECURITY.md`](docs/SECURITY.md).
- **Allowlist-gated `craft_command`** — every console-command dispatch checks the effective allowlist (project-config + runtime overrides + `config/cortex.php`). Dispatch goes through Craft's internal console runner — no `Process`, no `exec()`, no `shell_exec()`.
- **No shell-execution surface anywhere** — verified by an architecture test that tokenises every source file and rejects calls to `eval`, `shell_exec`, `proc_open`, `passthru`, `popen`, `exec`, or backtick operators.
- **Secret redaction** through `tools/support/SecretRedactor` on every tool argument before it reaches the audit log, on every `craft_exec` value and captured-stdout output, and via the same shared keyword needle list across the codebase.
- **Locked audit log shape** — every tool invocation emits one structured KV-formatted line on the `cortex` log channel. Field set and order are stable across the Phase 1 stdio / Phase 2 HTTP transport upgrade so external SIEM forwarders keep working unchanged.

#### Extensibility

Third-party plugins can register tools, prompts, and resources via class-level events:

- `Tools::EVENT_REGISTER_TOOLS` → `RegisterToolsEvent::$tools` (`ToolInterface[]`)
- `Prompts::EVENT_REGISTER_PROMPTS` → `RegisterPromptsEvent::$prompts` (`PromptInterface[]`)
- `Resources::EVENT_REGISTER_RESOURCES` → `RegisterResourcesEvent::$resources` (accepts both `ResourceInterface` and `ResourceTemplateInterface` for dynamic-URI families)

Cortex registers its bundled tools / prompts / resources before firing the events, so first-registration-wins behaviour means third-party plugins cannot shadow built-ins. Collisions surface a `Craft::warning()` line on the `cortex` channel.

A scaffolder — `ddev craft make cortex-tool` — drops a stub tool class with the right attributes, Schema DSL boilerplate, and registration snippet. Hooks into Craft's `make` command via `craftcms/generator`.

Full extension guide (interfaces, attributes, Schema DSL, naming conventions): [`docs/EXTENDING.md`](docs/EXTENDING.md).

### Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- An MCP-capable client (Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, or Windsurf)
