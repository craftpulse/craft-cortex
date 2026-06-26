# Cortex security model

Cortex exposes Craft internals to an LLM. That's a powerful capability, and Cortex's design treats security as a first-class concern rather than a feature to retrofit.

This document covers the security model in full. If you're integrating Cortex into a sensitive project — production with PII, regulated industries, multi-tenant — read it end-to-end before turning the plugin on.

- [Threat model](#threat-model)
- [Transport boundary](#transport-boundary)
- [`craft_exec` — six security gates](#craft_exec--six-security-gates)
- [`craft_command` — allowlist model](#craft_command--allowlist-model)
- [No shell-execution surface](#no-shell-execution-surface)
- [Secret redaction](#secret-redaction)
- [PII separation](#pii-separation)
- [Audit logging](#audit-logging)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Threat model

Cortex's primary security model assumes:

1. The **stdio transport is trusted** — the user invoking the MCP client and the user running Craft are the same person on the same machine. There is no per-request authentication. If you wouldn't let the local user run `php craft <anything>`, don't connect them to Cortex over stdio.
2. The **HTTP transport** (Phase 2) **is untrusted**. Every HTTP request authenticates against a Craft user via OAuth 2.1 / bearer tokens before any tool is dispatched. Tool visibility is filtered per-user (`shouldRegister()` consults Craft permissions); audit logging captures the authenticated user; rate limits apply at the controller layer.
3. **The LLM itself is adversarial.** Cortex assumes the model will, given the chance, call `craft_exec` with `delete_all_users()` or send PII through a prompt-injection payload. Every dangerous tool has its own gate — none of the safety relies on the LLM "knowing better."

What Cortex does **not** protect against:

- A compromised host. If an attacker has a shell on the box running Craft, no MCP server is going to save you.
- A compromised MCP client. The client is on the trusted side of the stdio boundary in Phase 1.
- Application bugs in tools registered by third-party plugins. Cortex enforces the architectural contract (interface implementation, transport gating, dispatch shape) but cannot validate behavioural correctness.

## Transport boundary

The transport is the boundary. The same tool runs differently depending on which transport invoked it:

| Concern | stdio (Phase 1, Free) | HTTP (Phase 2, Pro) |
|---------|-----------------------|---------------------|
| Authentication | None — trusts the local user | OAuth 2.1 / bearer token, resolved to a Craft user |
| Authorization | None — full registry visible | Per-user `shouldRegister()` filtering against Craft permissions |
| Rate limiting | Not applicable (single-process) | Per-controller, per-token |
| `craft_exec` | Available | **Hard rejected** at the transport layer regardless of token scope |
| Audit log `user` field | `-` | Populated with Craft user id |

Tools mark themselves stdio-only via the `#[IsStdioOnly]` attribute. The dispatcher checks this at every `tools/call` and returns a JSON-RPC `-32601` error if the transport doesn't match. The check is hard-coded in the dispatcher (`mcp/Server.php`) — it isn't a config setting, can't be turned off via the CP, and isn't influenced by token scope.

`craft_exec` carries `#[IsStdioOnly]`. So does `import_export` (Free has export-only, Pro will lift this restriction once the Pro permission gating lands).

### High-stakes operations require elevation over HTTP

Craft's own control panel guards certain operations behind an **elevated session** — the user must re-authenticate (re-enter their password; 2FA is part of the standard login) before the change is accepted. Cortex brings the same posture to the HTTP transport with an in-band elevation flow.

The operations that require elevation over HTTP are:

- **`users` credential / privilege fields** — changing a password (`newPassword`), changing an email address (`email`), or granting / modifying admin status (`admin`).
- **Content publish / unpublish** — toggling the `enabled` (publication status) field on the `entry` tool's `create` / `update` modes.
- **Content / element deletes** — the `entry` tool's `delete` mode.

When one of these is attempted over HTTP **without elevation**, Cortex refuses it with a tool error naming the elevation flow:

> `users: changing password/email/admin status over the HTTP transport requires elevation. Re-authenticate via the /oauth/elevate flow, then retry.`

#### The `/oauth/elevate` flow

To elevate, the user re-authenticates through Craft:

1. The client opens `/oauth/elevate?token=<access_token>` in a browser.
2. Cortex requires a live Craft session (anonymous visitors are redirected to the CP login).
3. On confirmation, Cortex calls Craft's native `requireElevatedSession()` — a fresh password re-entry (2FA is handled by the standard login).
4. Cortex verifies the token belongs to the logged-in user, then mints a short-lived **elevation marker** bound to that specific access token's `jti`, stored server-side in the cache for `elevationTtl` seconds (default 300 / 5 minutes).

Elevation is tracked **server-side**, never trusted from a client claim. It binds to the exact access token presented — a second token for the same user is not elevated unless it goes through its own elevate flow. The marker is read on every HTTP request and threaded onto `InvocationContext::$elevated`; the gated tools check that flag.

The gate keys on the real `InvocationContext` threaded from the dispatcher — never on a proxy such as "is a Craft user resolved". When the context cannot be determined, the request is treated as un-elevated HTTP and refused (fail closed). stdio is the trusted local transport and is implicitly elevated, so a stdio caller is never gated.

#### Elevation does NOT unlock code execution

This is a hard, non-negotiable boundary: **`craft_exec` (and any `#[IsStdioOnly]` tool) stays stdio-only ALWAYS, regardless of elevation.** Elevation compensates for the missing HTTP re-authentication on credential and content operations; it never crosses the code-execution transport boundary. An elevated HTTP request that invokes `craft_exec` is still hard-rejected at the dispatcher (`mcp/Server.php`) with the stdio-only error.

## OAuth 2.1 authorization

The HTTP transport authenticates every request against a Craft user via OAuth 2.1 (Authorization Code + PKCE) or a long-lived bearer token. Three layers harden the OAuth surface.

### Capability scopes

Authorization over HTTP is the conjunction of three independent gates: **scope ∧ Craft-permission ∧ edition**. A tool is visible (`tools/list`) and callable (`tools/call`) only when all three hold.

The scope vocabulary is capability-grained:

| Scope | Grants |
|-------|--------|
| `content:read` | Read entries, assets, categories, tags, globals |
| `content:write` | Create / update content + bulk / scaffold tools |
| `content:publish` | Change entry publication status |
| `content:delete` | Delete content |
| `assets:write` | Mutate assets and address records |
| `schema:read` | Read sections, fields, entry types, volumes, sites |
| `system:read` | Read config, plugins, routes, diagnostics |
| `users:read` | Read user records (PII-gated) |
| `users:write` | Create / update / delete users |

Every registered tool maps to exactly one required scope in `services/Scopes.php` (the single source of truth). A tool absent from the map defaults to `system:read` — fail-safe: an unmapped tool is gated behind a read scope, never granted a write capability by default. stdio is not scope-gated (the trusted local transport). The legacy coarse `read` / `write` scopes are still accepted at the authorize / DCR boundary and expanded to their capability clusters at grant time, so pre-existing tokens keep working.

`craft_exec` maps to a scope for completeness but stays stdio-only at the transport boundary regardless — no scope can reach it over HTTP.

### Dynamic Client Registration requires approval

RFC 7591 Dynamic Client Registration lets any caller self-register a client. Cortex starts every DCR-registered client **unapproved**: the authorize and token flows reject it (it is invisible to league's client repository) until an admin approves it on the **Clients** CP screen. An unapproved client's authorize request renders a clear "pending admin approval" page rather than a generic OAuth error.

The `dcrAutoApprove` setting (default `false`) flips this to zero-friction self-registration for trusted / dev installs. Out-of-band-seeded clients are approved directly. The `/oauth/register` endpoint is also per-IP rate-limited.

### Refresh-token rotation + theft detection

Refresh tokens rotate on every exchange — league issues a new access + refresh pair and revokes the old refresh token. Cortex adds **family lineage** tracking (RFC 6819 §5.2.2.3 / the OAuth 2.1 refresh-rotation BCP): every token minted from one authorization, and every rotation descended from it, shares a `familyId`.

When an **already-consumed** refresh token is presented again (a replay — the canonical stolen-token signal), Cortex revokes the **entire family** — every live access and refresh token in the lineage — emits a `kind=security` audit row, and returns an OAuth error. The legitimate client must re-authorize through the consent flow. This bounds the blast radius of a leaked refresh token to a single rotation window.

## `craft_exec` — six security gates

`craft_exec` evaluates arbitrary PHP expressions through Craft's own `ExecController`. It's the most powerful tool Cortex ships, and it's wrapped in six gates that all run before any expression is evaluated. The gates cannot be turned off from outside the dispatcher.

### Gate 1 — Dry-run default

Without `confirm: true` in the arguments, Cortex returns the parsed expression and proposed effect (the type and class of the would-be result) and exits without evaluating. This is the default.

The default can be flipped via the `execDryRunDefault` setting (see [CONFIGURATION.md](CONFIGURATION.md#execdryrundefault)) — but this only changes whether the LLM has to opt in to dry-run; it does not weaken any other gate.

### Gate 2 — Structured output

Expression results are wrapped in a typed envelope: `{type, class, value, captured_stdout}`. Errors during parsing or runtime are caught and surfaced as `parse_error` / `runtime_error` envelopes with class, file, line, and stack trace. The LLM never sees a raw PHP error or unstructured value. A captured `value` that's an object is serialised with class + relevant properties; circular references are clipped.

### Gate 3 — Secret redaction

Expression values and captured stdout pass through `tools/support/SecretRedactor` before they leave the process. The redactor walks associative-array structures and replaces values whose keys match secret patterns (`password`, `token`, `apiKey`, `secret`, `accessKey`, `privateKey`, `salt`, `cookieValidationKey`, `webhookSecret`, `jwt`, `oauth`, `bearer`) with `[REDACTED]`. Flat strings are scanned for `KEY=value` / `KEY: value` patterns and the value half is redacted.

Redaction is conservative: false positives are preferred over false negatives. If your domain has a secret-like field that Cortex is over-redacting, file an issue — extending the needle list is the right fix.

### Gate 4 — Destructive-op guard

Expressions matching destructive patterns (`delete*`, `drop*`, `truncate*`, `Elements::deleteElement`, `migrate/down`) require **both** `confirm: true` AND `dangerous: true`. Either alone is rejected. The pattern match is greedy — `softDelete()` looks like `delete` and gets gated. If you genuinely need to destroy something, you have to opt in twice.

### Gate 5 — stdio-only

`craft_exec` carries the `#[IsStdioOnly]` attribute. The dispatcher's transport gate rejects it on HTTP regardless of token scope, user permissions, or any setting. Phase 2 doesn't relax this.

### Gate 6 — Destructive annotation

`craft_exec` ships with `#[IsDestructive]` per the MCP spec's tool annotation surface. Spec-compliant clients warn the user before the call goes through. This is a defence-in-depth gate — it relies on client cooperation, where gates 1-5 don't.

## `craft_command` — allowlist model

`craft_command` dispatches Craft / Yii console commands through Craft's internal console runner (no `Process`, no `exec()`). Every dispatch is gated against an allowlist of glob patterns.

The effective allowlist is the union of three sources, evaluated at every call:

1. **Project config defaults** — `Settings::$allowedCommands`. Synced across environments via `project-config/apply`. Edited via the CP **Settings → Cortex** page.
2. **Runtime overrides** — admin-issued, auto-expiring entries in the `cortex_runtime_overrides` DB table. Edited via the CP UI; require `requireAdmin(requireAdminChanges: true)` to mutate.
3. **`config/cortex.php` overrides** — environment-aware file overrides. Highest priority.

Any of the three granting permission grants permission. Allowlist mutations through the CP and via project config sync; runtime overrides do not sync (they're per-machine, ephemeral grants).

If the LLM tries to dispatch a command that isn't on the effective allowlist, `craft_command` returns a structured `ToolException` envelope with `isError: true` — the call never reaches the console runner.

## No shell-execution surface

The architecture test suite (`tests/Architecture/ConventionsTest.php`) enforces that no source file under `src/` calls `eval()`, `shell_exec()`, `proc_open()`, `passthru()`, `popen()`, `exec()`, or uses backtick operators. The check tokenises the source rather than regex-matching, so it's false-positive-proof against docblock / string occurrences.

This is a hard rule. Any third-party plugin that registers a tool calling these functions would still pass the cortex contract, but it would do so on its own ground — Cortex does not, and can not, vet third-party tool internals.

## Secret redaction

In addition to gating `craft_exec`'s output (Gate 3 above), Cortex redacts secrets on every audit-log line. Tool arguments pass through `SecretRedactor::redactArray()` before being serialised into the log entry. This means even if your LLM passes a token to a tool argument, that token does not appear in `storage/logs`.

The redactor is the **single source of truth** for the secret-keyword needle list — every part of Cortex that touches user input runs through it, so adding a new keyword extends redaction across the entire surface at once.

## PII separation

Free tier:

- Cortex Free does **not** ship a PII-exposing tool. There is no `users` tool, no `addresses` tool, no `orders` tool. `entries`, `assets`, `categories`, `tags`, `globals` cover content; user / customer data is not on the menu.
- The `permissions_and_groups` tool returns the permission tree and group → permission mappings. It does NOT return user → group memberships, user emails, or any user identity surface.

Pro tier (Phase 2):

- PII tools (`users`, `address`) ship in Pro and require explicit per-user authorization. Commerce data (orders, customer lookups) is not exposed by Cortex; Commerce support is planned as a separate plugin.
- Pro tools default to `#[IsStdioOnly(false)]` (HTTP-allowed) and add Craft permission checks via `shouldRegister()`. A user without `accessUsers` won't see the `users` tool in `tools/list`.

Even on Pro, PII tools default to read-only with explicit per-call confirmation for any mutation. The same six-gate philosophy applies — destructive write tools require dry-run + dangerous handshake.

## Audit logging

Every tool invocation emits a structured line on the `cortex` log channel:

```
tool=<name> kind=<success|tool_error|internal_error> duration_ms=<int>
  transport=<stdio|http> request_id=<id|-> user=<id|->
  client=<name|-> args=<redacted-json>
```

The fields are locked across Phase 1 / Phase 2 — Phase 2 fills `user` from authenticated bearer tokens, but the line shape doesn't change. External SIEM forwarders pinned against Phase 1 keep working unchanged through the upgrade.

What's logged:

- Every tool invocation (success and failure)
- Tool name, JSON-RPC request id, transport, client name (from the MCP `initialize` handshake)
- Duration in milliseconds
- Tool arguments — secret-redacted
- For errors: error class, error message (control-character-stripped, single-line)

What's **not** logged:

- Tool return values (the result envelope is too large; redaction would require structural awareness of every tool's return shape)
- Captured stdout from `craft_exec` (already passes through SecretRedactor, but is not duplicated to the audit log — the result envelope is the place to look)
- Anything the user didn't pass to the tool (Cortex doesn't introspect Craft state for the log)

To route Cortex logs to a dedicated file or SIEM target, see [CONFIGURATION.md > Logging](CONFIGURATION.md#logging).

## Reporting a vulnerability

If you discover a security issue in Cortex, please **do not** open a public GitHub issue.

Email [security@craftpulse.com](mailto:security@craftpulse.com) with:

- A description of the issue
- Steps to reproduce
- The Cortex version, Craft version, and PHP version
- Optional: a proposed fix or mitigation

We aim to acknowledge reports within 48 hours and ship fixes within 7 days for critical issues. We'll credit reporters in the release notes unless you'd rather stay anonymous.
