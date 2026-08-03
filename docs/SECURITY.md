# Herald security model

Herald exposes Craft internals to an agent. That is a powerful capability, and Herald's design treats security as the primary concern rather than a feature to retrofit.

This document covers the model in full. If you are integrating Herald into a sensitive project (production with PII, a regulated industry, multi-tenant hosting), read it end to end before turning the plugin on.

- [What Herald guarantees](#what-herald-guarantees)
- [Threat model](#threat-model)
- [Transport boundary](#transport-boundary)
- [OAuth 2.1 authorization](#oauth-21-authorization)
- [`craft_exec`: six security gates](#craft_exec-six-security-gates)
- [`craft_command`: allowlist model](#craft_command-allowlist-model)
- [Write paths: service layer only](#write-paths-service-layer-only)
- [No shell-execution surface](#no-shell-execution-surface)
- [Secret redaction](#secret-redaction)
- [PII separation](#pii-separation)
- [Audit logging](#audit-logging)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## What Herald guarantees

These are the properties Herald holds, each enforced in one named place in the source rather than spread across every tool. They are listed first because they are what the rest of this page details.

**Arguments are validated before a tool sees them.** Every tool declares a JSON Schema, and the dispatcher validates the caller's arguments against that schema before dispatch. A tool never receives an argument of the wrong type, an unknown property, or a missing required field, so no tool carries its own hand-rolled argument parsing that could drift from what it advertises.

**Sort order is an allowlist, not a passthrough.** Element queries forward `orderBy` into the SQL `ORDER BY` clause, so a caller-supplied string reaching a query is a read primitive over every table in the database. Herald's list tools accept only aliases from a per-tool allowlist and map each one to a fully qualified column. A value outside the allowlist is refused with an error naming the accepted aliases, so the calling agent corrects itself in one round trip. Declaring `orderBy` as a schema string does not close this, because the payload legitimately is a string; only allowlisting the values does.

**Console-command execution is its own permission and its own scope.** Running a Craft console route reaches Craft's own controllers, so it is the broadest capability Herald offers wearing the narrowest possible label. It sits behind a dedicated permission checked inside the tool's `execute()`, independent of whatever `tools/list` chose to show, and behind a dedicated `system:write` scope rather than folded into a read scope. A read-only grant cannot run a command.

**An absent scope denies.** Every HTTP credential carries capability scopes, and a credential with no scope authorises nothing: it is refused with `403` on its first request rather than read as "unscoped, therefore unlimited".

**Account state is re-checked on every request, not only at issue.** Suspension, locking and deactivation all happen long after a credential is minted. Herald resolves the bound account on every authenticated request and refuses when it can no longer authenticate, so revoking a person's access in Craft revokes their agent's access at the same moment.

**`allowAdminChanges` is honoured by the grant path too.** A temporary runtime grant cannot admit an admin-level command route on an install where Craft itself has admin changes turned off. The read-side view of the effective allowlist and the dispatch-time gate consult the same policy, so what an operator sees in the control panel is what the dispatcher enforces.

**Code execution cannot cross the transport boundary.** `craft_exec` is refused at the dispatcher on HTTP regardless of permissions, token scope, elevation, or any setting. It is not a configuration option.

**There is no shell.** Console commands dispatch through Craft's in-process console runner. There is no `exec()`, `shell_exec()`, `proc_open()`, `passthru()`, `popen()`, or backtick operator anywhere in the source, so there is no string being interpolated into a command line to escape. An architecture test walks PHP's token stream to keep it that way.

## Threat model

Herald's model assumes:

1. The **stdio transport is trusted**: the person running the MCP client and the person running Craft are the same person on the same machine. There is no per-request authentication. If you would not let that local user run `php craft <anything>`, do not connect them to Herald over stdio.
2. The **HTTP transport is untrusted**. Every request authenticates against a Craft user through OAuth 2.1 or a bearer token before any tool is dispatched. Tool visibility is filtered per user, authorization is re-checked inside `execute()`, every invocation is audit-logged against the authenticated user, and rate limits apply at the controller layer.
3. **The model driving the client is adversarial.** Herald assumes the model will, given the chance, call `craft_exec` with a destructive expression, or carry a prompt-injection payload out through a tool argument. Every dangerous capability has its own gate, and no part of the safety model relies on the model knowing better.

What Herald does **not** protect against:

- A compromised host. With a shell on the box running Craft, no MCP server helps.
- A compromised client on the stdio side. The client is inside the trusted boundary there by definition.
- Bugs in tools registered by third-party plugins. Herald enforces the architectural contract (interface implementation, transport gating, dispatch shape, schema validation) but cannot validate someone else's behaviour.

## Transport boundary

The transport is the boundary. The same tool behaves differently depending on which transport invoked it.

| Concern | stdio (Free) | HTTP (Pro) |
|---|---|---|
| Authentication | None, trusts the local user | OAuth 2.1 or bearer token, resolved to a Craft user |
| Authorization | None, full registry visible | Capability scope, then `filterFor()` per user, then an `execute()` re-check |
| Account state | Not applicable | Suspended / locked / inactive refused per request |
| Rate limiting | Not applicable (single process) | Per user, per token, at the controller |
| `craft_exec` | Available | Rejected at the dispatcher regardless of scope |
| Audit log `user` field | `-` | The authenticated Craft user id |

Tools mark themselves stdio-only with the `#[IsStdioOnly]` attribute. The dispatcher checks it on every `tools/call` and returns a JSON-RPC `-32601` error when the transport does not match. The check lives in the dispatcher (`mcp/Server.php`): it is not a setting, cannot be turned off from the control panel, and is not influenced by token scope.

`craft_exec` is the only tool that carries `#[IsStdioOnly]`.

### Per-user tool gating

Over HTTP, whether a tool is visible and callable is the conjunction of three independent gates: **capability scope, Craft permission, and edition**. All three must hold.

Each tool implements three methods, and `execute()` re-checks regardless:

- `shouldRegister()` runs once at boot and gates whole-tool registration on edition and settings. A Pro tool on a Free install is never loaded into the registry at all.
- `filterFor(?User)` runs on every `tools/list` and every `tools/call`. A tool the caller cannot use is omitted from the list, and calling it anyway fails closed as `Unknown tool`, which is indistinguishable on the wire from a tool that was never registered.
- `inputSchemaFor(?User)` rewrites the schema per request. Mode-gated tools filter their `mode` enum down to what the caller may actually do, so the model is never offered an option that will be refused.

List filtering is tool-selection UX. The `execute()` check is the security boundary. Both fail closed.

### High-stakes operations require elevation over HTTP

Craft's own control panel guards certain operations behind an **elevated session**, meaning the user re-enters their password. The HTTP transport has no other re-authentication step, so Herald adds an in-band elevation flow and gates the same operations behind it:

- **`users` credential and privilege fields**: changing a password (`newPassword`), changing an email address (`email`), or granting or modifying admin status (`admin`).
- **Content publication status**: toggling `enabled` on the `entry` tool's `create` and `update` modes, and the `bulk_entries` tool's `set_status` mode.
- **Element deletes**: the `delete` mode on every write tool, which is `entry`, `category`, `tag`, `address`, `skill` and `users`. Deleting a user is at least as high-stakes as changing that user's email, so the gate covers the whole family rather than content entries alone.

Attempting one of these over HTTP without elevation returns a tool error naming the flow:

> `Deleting a user over the HTTP transport requires elevation. Re-authenticate via the /oauth/elevate flow, then retry. (Over the trusted stdio transport this is always permitted.)`

The flow itself is documented in [HTTP transport](HTTP-TRANSPORT.md#elevation-for-high-stakes-operations). Elevation is tracked server-side and never trusted from a client claim. When the invocation context cannot be determined, the request is treated as un-elevated HTTP and refused. stdio is implicitly elevated and never gated.

**Elevation does not unlock code execution.** `craft_exec` and any `#[IsStdioOnly]` tool stay stdio-only always. Elevation compensates for the missing HTTP re-authentication on credential and content operations; it never crosses the code-execution boundary. An elevated HTTP request that invokes `craft_exec` is still rejected at the dispatcher.

## OAuth 2.1 authorization

The HTTP transport authenticates every request against a Craft user through OAuth 2.1 (Authorization Code with PKCE) or a long-lived bearer token. See [HTTP transport](HTTP-TRANSPORT.md) for the wire-level flows; this section covers the authorization model.

### Capability scopes

The scope vocabulary is capability-grained. These are the scopes Herald advertises under `scopes_supported` and accepts at the authorize and registration boundaries:

| Scope | Grants |
|---|---|
| `content:read` | Read entries, assets, categories, tags, and globals. |
| `content:write` | Create, update, publish, and delete entries, categories, tags, and globals. |
| `assets:write` | Upload and modify assets and address records. |
| `schema:read` | Read schema: sections, fields, entry types, volumes, and sites. |
| `system:read` | Read system configuration, plugins, routes, and diagnostics. |
| `system:write` | Run allowlisted Craft console commands. |
| `users:read` | Read user records, subject to PII gating. |
| `users:write` | Create, update, and delete users. |

Every registered tool maps to exactly one required scope in `services/Scopes.php`, which is the single source of truth. A tool absent from that map resolves to an internal deny sentinel that no credential can carry, so an unmapped tool (a future write tool, or a third-party one) is **refused** over HTTP rather than inheriting a read scope it was never deliberately granted. A test asserts there are no such gaps among the built-in tools.

`craft_command` and `craft_exec` both map to `system:write`, never to a read scope, because both run allowlisted code inside the Craft process. `craft_exec` is mapped for completeness but stays stdio-only at the transport boundary regardless, so no scope reaches it over HTTP.

stdio is not scope-gated, because it is the trusted local transport. The coarse `read` and `write` scopes are still accepted at the authorize and registration boundaries for compatibility and are expanded to their capability clusters at grant time, but they are not advertised.

**A credential carrying no scope at all is refused with `403`.** An absent scope denies; it never means "unscoped, therefore allow".

### Dynamic Client Registration requires approval

RFC 7591 Dynamic Client Registration lets any caller self-register a client, so Herald starts every DCR-registered client **unapproved**. The authorize and token flows reject it, and its authorize request renders a "pending admin approval" page rather than a generic OAuth error, until an admin approves it on the **Clients** control panel screen.

The `dcrAutoApprove` setting (default `false`) turns this into zero-friction self-registration for a trusted or development install. Out-of-band-seeded clients are approved directly. The registration endpoint is per-IP rate-limited.

### Refresh-token rotation and theft detection

Refresh tokens rotate on every exchange: a new access and refresh pair is issued and the old refresh token is revoked. Herald adds **family lineage** tracking, so every token minted from one authorization, and every rotation descended from it, shares a family id.

Presenting an **already-consumed** refresh token is the canonical stolen-token signal. Herald revokes the **entire family**, every live access and refresh token in the lineage, writes a `kind=security` audit row, and returns an OAuth error. The legitimate client must re-authorize through the consent flow. This bounds the blast radius of a leaked refresh token to a single rotation window.

### Credential storage

Plaintext credentials are never persisted. A long-lived bearer token is `random_bytes(32)` rendered as 64 hex characters and stored only as a SHA-256 hash alongside an 8-character prefix for display. OAuth access tokens store only a hash of their `jti`. The plaintext prints exactly once, at issue.

## `craft_exec`: six security gates

`craft_exec` evaluates arbitrary PHP expressions through Craft's own `ExecController`. It is the most powerful tool Herald ships, and it runs behind six gates, all of which run before an expression is evaluated. The gates cannot be turned off from outside the dispatcher.

### Gate 1: dry-run default

Without `confirm: true` in the arguments, Herald returns the parsed expression and the proposed effect (the type and class of the would-be result) and exits without evaluating. This is the default.

The default can be flipped with the `execDryRunDefault` setting (see [Configuration](CONFIGURATION.md#execdryrundefault)), which only changes whether the caller has to opt in to dry-run. It weakens no other gate.

### Gate 2: structured output

Expression results are wrapped in a typed envelope: `{type, class, value, captured_stdout}`. Parse and runtime errors are caught and surfaced as `parse_error` / `runtime_error` envelopes with class, file, line, and stack trace. The caller never sees a raw PHP error or an unstructured value. An object `value` is serialised with its class and relevant properties, and circular references are clipped.

### Gate 3: secret redaction

Expression values and captured stdout pass through `tools/support/SecretRedactor` before they leave the process. The redactor normalises keys and replaces values whose keys match its needle list (`password`, `securitykey`, `token`, `secret`, `apikey`, `privatekey`, `oauth`, `bearer`, and more) with the literal placeholder `<redacted>`. Flat strings are scanned for `KEY=value` patterns and the value half is redacted.

Redaction is conservative: false positives are preferred over false negatives. If your domain has a secret-like field Herald over-redacts, open an issue. Extending the needle list is the right fix, and because the redactor is a single source of truth, one addition extends redaction across the whole surface.

### Gate 4: destructive-op guard

Expressions matching destructive patterns (`delete*`, `drop*`, `truncate*`, `migrate/down`, `project-config/sync`) require **both** `confirm: true` and `dangerous: true`. Either alone is rejected. The match is greedy, so `softDelete()` reads as `delete` and gets gated. Destroying something takes two explicit opt-ins.

### Gate 5: stdio only

`craft_exec` carries the `#[IsStdioOnly]` attribute. The dispatcher's transport gate rejects it on HTTP regardless of token scope, user permissions, elevation, or any setting.

### Gate 6: destructive annotation

`craft_exec` ships the `#[IsDestructive]` annotation from the MCP tool-annotation surface, so a spec-aware client warns the user before the call goes through. This gate relies on client cooperation, where gates 1 to 5 do not.

The `execEnabled` setting is an additional availability switch in front of all six: with it off, the tool is not registered at all.

## `craft_command`: allowlist model

`craft_command` dispatches Craft and Yii console commands through Craft's in-process console runner. There is no `Process` and no `exec()`, so there is no command line to escape.

Running it requires the `herald:run-commands` permission, checked **inside `execute()`** rather than only at list time, so a caller reaching the tool by any route other than the filtered list is still refused. Over HTTP it also requires the `system:write` scope.

Every dispatch is gated against an allowlist of glob patterns, split in two:

- **Content-level patterns** (`allowedCommands`) are always admitted.
- **Admin-level patterns** (`adminLevelCommands`: project config, migrations, scaffolding, section and field DDL, fixtures) are admitted **only when** Craft's own `allowAdminChanges` is `true`. When it is `false`, a matching invocation is refused with a structured error naming the flag, and the refusal still writes a `kind=tool_error` audit row so the boundary attempt is on record.

The effective list is the union of three sources, evaluated on every call:

1. **Project config defaults**, synced across environments and edited on the **Settings** control panel screen.
2. **Runtime grants**, admin-issued and auto-expiring, stored in the `herald_runtime_overrides` table and managed on the **Allowlist** screen. Mutating them requires `requireAdmin(requireAdminChanges: true)`. A runtime grant cannot admit an admin-level route while `allowAdminChanges` is off, so it is not an escape hatch around the policy above.
3. **`config/herald.php` overrides**, environment-aware and highest priority.

Runtime grants are per-machine and deliberately do not sync. A command that is not on the effective list returns a structured error envelope and never reaches the console runner.

See [Configuration](CONFIGURATION.md#allowedcommands) for the shipped defaults.

## Write paths: service layer only

Every mutation Herald can perform is authored through Craft's service layer. Nothing in the tool surface emits project-config YAML or raw SQL, and there is no raw-SQL tool at all: element access goes through typed, permission-scoped tools, so the model is "no arbitrary-query primitive" rather than "arbitrary queries behind a keyword blocklist".

- **Content writes** (the Pro `entry`, `bulk_entries`, `scaffold_entries`, `category`, `tag`, `global_set`, and `address` tools) go through `Craft::$app->getElements()->saveElement()` with validation enabled, and re-check the per-section or per-group permission the resolved arguments imply before touching an element. Content is database state, so it never touches project config, and these tools work regardless of `allowAdminChanges`.
- **Schema writes have no dedicated tool and no `schema:write` scope.** Sections, entry types, and fields are mutated only through `craft_command` dispatching core console commands (`sections/*`, `fields/*`, `entrify/*`) or migrations (`make/*` plus `migrate/*`), all of which call `saveSection()` / `saveField()` internally. Craft validates the model and writes the project-config YAML itself, and that YAML is the reviewable deploy artifact: committed to Git, propagated with `craft up`.
- **`allowAdminChanges` is enforced at dispatch**, as described above. Schema authoring therefore happens only where Craft itself allows it, typically local development, and reaches production through the deploy pipeline rather than as an out-of-band write.

## No shell-execution surface

The architecture test suite (`tests/Architecture/ConventionsTest.php`) asserts that no file under `src/` calls `eval()`, `shell_exec()`, `proc_open()`, `passthru()`, `popen()`, or `exec()`, or uses the backtick operator. The check walks PHP's token stream rather than regex-matching source text, so a docblock or string occurrence cannot produce a false positive and a comment cannot hide a real one. Exactly one file is exempt by name: `tools/dev/CraftExec.php`, whose single `eval` site is the fenced tool described above.

This is a hard rule for Herald's own source. A third-party plugin registering a tool that calls these functions would still satisfy Herald's contract, but it does so on its own ground; Herald cannot vet third-party tool internals.

## Secret redaction

`SecretRedactor` is the **single source of truth** for the secret-keyword needle list, so adding a keyword extends redaction across every surface at once. It runs in the `config` tool, over `craft_exec` results and captured stdout, and over every persisted audit excerpt at both dispatch sites, which means a token passed as a tool argument does not appear in the logs or in the audit table.

The scope is worth stating precisely: the dispatch-site backstop redacts secret-*keyed* fields, and secrets embedded inside flat string values are the tool layer's job. The two tools with live string output, `craft_command` and `craft_exec`, both apply string redaction there.

## PII separation

**Free** ships no PII-exposing tool. There is no `users` tool, no `address` tool, and no order or customer surface. `entries`, `assets`, `categories`, `tags`, and `globals` cover content; user and customer data is not on the menu. The `permissions_and_groups` tool returns the permission tree and the group-to-permission mapping, and deliberately does not return user-to-group memberships, emails, or any user identity surface.

**Pro** adds `users` and `address`, both behind explicit per-user authorization. The `users` tool layers several gates: per-target `canSave`, an explicit refusal when a non-admin caller edits an admin target (Craft's `canSave` does not enforce that natively), an `administrateUsers` requirement for status and credential fields, and elevation over HTTP for password, email, and admin changes.

Custom-field values on a user envelope are opt-in. `userCustomFieldAllowlist` defaults to empty, so no custom-field value is returned until an operator enumerates the handles in project config, and that allowlist is independent of caller permission: a field not on it is never returned, not even to an admin. Craft has no native per-field-value permission, and field-layout hiding is presentation only, so this is Herald's own gate.

Commerce data (orders, customer lookups) is not exposed by Herald.

## Audit logging

Every tool invocation emits one structured line on the `herald` log channel:

```
tool=<name> kind=<success|tool_error|internal_error|cancelled|rate_limited> duration_ms=<int>
  transport=<stdio|http> request_id=<id|-> user=<id|->
  client=<name|-> args=<redacted-json>
```

Over HTTP, each invocation also writes one row to the `herald_invocations` table, carrying the tool, redacted arguments, a redacted response excerpt, the outcome kind, duration, user, client name, session, token correlation, and remaining rate-limit headroom. Every key in the log line has a matching column on the table.

The audit write is **soft**: a database failure in the audit path is caught, logged for operator awareness, and swallowed, so it can never break the JSON-RPC response. Streamed and non-streamed calls produce forensically identical rows, one per invocation rather than one per frame.

What is logged: every invocation, success and failure; the tool name, JSON-RPC request id, transport, and client name from the `initialize` handshake; duration; secret-redacted arguments; and for errors, the error class and a control-character-stripped single-line message.

What is not logged: full tool return values (the excerpt is bounded by `auditResponseExcerptBytes`), captured stdout from `craft_exec` (already redacted in the result envelope, not duplicated here), and anything the caller did not pass, because Herald does not introspect Craft state for the log.

The log surfaces on the **Activity** control panel screen, which is permission-scoped fail-closed: a non-admin holding `herald:view-activity` sees only their own rows, and probing a foreign row id returns 404.

### Audit Kit events

On top of its own two surfaces, Herald emits native [Audit Kit](https://github.com/craftpulse/craft-audit-kit) events onto the shared dispatch bus, so writes reach a tamper-evident chain when a recorder is installed.

- **What is emitted.** One event per write-tool invocation (content, schema, dev, workflow) carrying the tool name, kind, transport, outcome, a coarse duration bucket, and the client and token ids; plus the OAuth and bearer-token lifecycle (client registered, approved, revoked; elevation granted; token issued, revoked). Read-tool calls are not emitted, because the invocation log already covers them and the volume would swamp a chain.
- **Both transports.** The write-tool event fires on stdio and HTTP alike, so writes made over the local transport reach the chain too.
- **Privacy by construction.** Event details are scalar-only, carry no content bodies and no PII, and never carry secret values: token and client *ids* travel, token plaintext and client secrets never do. Each event type declares a fail-closed allowlist of exactly which detail keys a recorder may persist.
- **Zero-config.** With no recorder installed the bus is a no-op, so emission costs nothing and changes no behaviour.

To route Herald's log channel to a dedicated file or a SIEM target, see [Configuration](CONFIGURATION.md#logging).

## Reporting a vulnerability

If you discover a security issue in Herald, please **do not** open a public GitHub issue.

Email [security@craft-pulse.com](mailto:security@craft-pulse.com) with:

- A description of the issue
- Steps to reproduce
- The Herald version, Craft version, and PHP version
- Optionally, a proposed fix or mitigation

We aim to acknowledge reports within 48 hours and to ship fixes for critical issues within 7 days. We credit reporters in the release notes unless you would rather stay anonymous.
