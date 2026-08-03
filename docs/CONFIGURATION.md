# Configuring Herald

Herald ships with sensible defaults; most projects don't need to touch the configuration. When you do, you have four layers of override available, applied in this order (highest priority first):

1. **`config/herald.php`**: file-based overrides, environment-aware. Versioned alongside your project.
2. **Runtime grants**: admin-issued, auto-expiring entries via the control panel. Applies to the `craft_command` allowlist only.
3. **Project config**: synced across environments; edited on the **Settings** control panel screen.
4. **Defaults**: baked into `models/Settings`.

This document covers each setting in detail, then explains how to use the CP UI and the `config/herald.php` file.

- [Settings reference](#settings-reference)
  - [`allowedCommands`](#allowedcommands)
  - [`adminLevelCommands`](#adminlevelcommands)
  - [`execEnabled`](#execenabled)
  - [`execDryRunDefault`](#execdryrundefault)
  - [`runtimeOverrideTtl`](#runtimeoverridettl)
  - [`stdioMaxMessageBytes`](#stdiomaxmessagebytes)
  - [`httpEnabled`](#httpenabled)
  - [`allowedOrigins`](#allowedorigins)
  - [`sessionTtl`](#sessionttl)
  - [`dcrEnabled`](#dcrenabled)
  - [`dcrAutoApprove`](#dcrautoapprove)
  - [`oauthAccessTokenTtl` / `oauthRefreshTokenTtl`](#oauthaccesstokenttl--oauthrefreshtokenttl)
  - [`elevationTtl`](#elevationttl)
  - [`tokenTtlDefault`](#tokenttldefault)
  - [`userCustomFieldAllowlist`](#usercustomfieldallowlist)
  - [`auditResponseExcerptBytes` / `auditRetentionDays`](#auditresponseexcerptbytes--auditretentiondays)
- [HTTP transport](#http-transport)
- [The CP section](#the-cp-section)
- [Audit log](#audit-log)
- [`config/herald.php`](#configheraldphp)
- [Logging](#logging)

## Settings reference

### `allowedCommands`

**Type:** `string[]` &nbsp;&nbsp; **Default:** see below

The set of glob patterns the `craft_command` tool may dispatch, regardless of whether Craft allows admin changes. These are the content-level and operational routes. Patterns use `fnmatch` semantics: `resave/*` matches any `resave/<route>`, `gc` matches the literal `gc` command.

Default allowlist, on every environment:

```
resave/*            cache/*             invalidate-tags/*
index-assets/*      gc                  users/create
utils/*             clear-deprecations  mailer/test
```

These cover the operational surface most teams need without admitting anything that mutates schema or project config. Routes that do sit on [`adminLevelCommands`](#adminlevelcommands) instead. To tighten or widen the set per environment, override via [`config/herald.php`](#configheraldphp); for a short-lived grant without a deploy, use the [Temporary grants screen](#temporary-grants).

### `adminLevelCommands`

**Type:** `string[]` &nbsp;&nbsp; **Default:** see below

The set of glob patterns `craft_command` may dispatch **only when** Craft's own `allowAdminChanges` is `true`. These routes mutate admin-only state: project config, schema migrations, plugin scaffolding, section and field DDL, and fixture loads.

Default admin-level allowlist:

```
project-config/*    pc/*                migrate/*
up                  make/*              entrify/*
sections/*          fields/*            fixture/*
```

`pc/*` is listed alongside `project-config/*` because `pc` is Craft's own short alias for the same controller, so it must be classified identically or the alias would slip past the gate.

When `allowAdminChanges` is `false`, a `craft_command` invocation matching one of these patterns is refused at dispatch with a structured error naming the flag, and the refusal still writes a `kind=tool_error` audit row. The effective-allowlist view the control panel shows and the dispatch-time gate consult the same policy, so they never disagree.

Classification is fixed in code. Tightening this setting cannot re-class a `migrate/*` route as content-level and slip it into the always-admitted bucket.

### `execEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether the `craft_exec` tool is registered at all. The tool is stdio-only and runs through six security gates ([see security docs](SECURITY.md#craft_exec-six-security-gates)). If your team doesn't want it on the menu, set this to `false` and Herald removes it from `tools/list` entirely.

The recommended posture for production is `execEnabled = false`, since `craft_exec` is intentionally a development tool and the HTTP transport rejects it regardless.

### `execDryRunDefault`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether `craft_exec` defaults to dry-run mode. With this on (default), the LLM has to explicitly pass `confirm: true` in the tool call to actually evaluate an expression. Without `confirm`, Herald returns the parsed expression and proposed effect and skips execution.

Flipping this to `false` makes evaluation the default. This is **not** a security override: destructive expressions (`delete*`, `drop*`, `truncate*`, `migrate/down`, `project-config/sync`) still require both `confirm: true` AND `dangerous: true`. The destructive-op guard runs regardless of this setting.

### `runtimeOverrideTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `604800` (7 days)

The default TTL applied to a new runtime override when no expiry is supplied at creation time. Shorter values produce a tighter security posture (overrides expire faster, fewer surprise grants in the system); longer values reduce friction for teams that often need short-term command grants.

Runtime overrides are admin-issued through the CP and stored in the `herald_runtime_overrides` DB table. Expired overrides remain in the table (soft-delete) but no longer count toward the effective allowlist. Expired non-deleted overrides are hard-deleted inline during Craft's regular garbage-collection sweep.

### `stdioMaxMessageBytes`

**Type:** `int` (bytes) &nbsp;&nbsp; **Default:** `4194304` (4 MiB) &nbsp;&nbsp; **Minimum:** `1024`

Maximum size of a single newline-delimited JSON-RPC message the stdio transport will buffer before rejecting it with a JSON-RPC `-32600` Invalid Request. The reader reassembles each line in bounded chunks; if one line's accumulated bytes exceed the cap before a newline arrives, the reader drains the rest of the line, emits the error envelope, and continues with the next message rather than buffering an unbounded payload into memory.

The 4 MiB default is comfortably larger than any legitimate `tools/call` argument blob, but small enough that a hostile client streaming one giant line can't OOM the long-running `herald/serve` process. stdio is a trusted-local transport, but a trusted local *user* is not the same as a trusted client *implementation* (cf. the `craft_exec` threat model), so the cap holds regardless.

### `httpEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `false`

Whether the HTTP transport (`POST/GET/DELETE /herald/mcp`) accepts requests. Defaults to off, because the transport is opt-in and most installs only need stdio. When you do enable it, it enforces full authentication on every request (bearer token or OAuth 2.1, see [HTTP transport](#http-transport) and [SECURITY.md](SECURITY.md)); it is **not** an anonymous endpoint.

When this flag is `false`, every request to `/herald/mcp` returns `503 Service Unavailable` regardless of headers or credentials.

### `allowedOrigins`

**Type:** `string[]` &nbsp;&nbsp; **Default:** `[]`

Allowlist of `Origin` header values the HTTP transport accepts. The MCP spec mandates Origin validation as a DNS-rebinding defence, so a request whose `Origin` header does not match an entry here is rejected with `403 Forbidden`.

**An empty allowlist fails closed outside `devMode`.** With `httpEnabled` on, no configured origins, and `devMode` off, every request is refused with `403` and an error telling the operator to configure the list. In `devMode` an empty list is permissive, so local development is not blocked. Configuration drift therefore surfaces as a refused request rather than an open endpoint.

Set this to the explicit origin of every client that talks to the endpoint.

### `sessionTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `3600` (1 hour)

Sliding TTL applied to HTTP-transport sessions in Craft's cache. Every authenticated request resets the cache entry's expiry, so an active client stays alive indefinitely while idle clients evict naturally.

Sessions are keyed by an opaque `Mcp-Session-Id` returned in the response header on `initialize` and required on every subsequent POST. They are stored in the configured cache backend (PSR-16), with no DB write, and survive request boundaries but not cache flushes.

### `dcrEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether RFC 7591 Dynamic Client Registration is open on `POST /oauth/register`. MCP-native clients self-register on first contact; flip to `false` to require out-of-band client seeding.

### `dcrAutoApprove`

**Type:** `bool` &nbsp;&nbsp; **Default:** `false`

Whether a newly DCR-registered client is auto-approved. Default `false` means every self-registered client lands **unapproved**, and its authorize and token flows are rejected with a "pending admin approval" error until an admin approves it on the **Clients** CP screen. Flip to `true` for trusted / dev installs that want the zero-friction self-registration MCP clients expect. Out-of-band-seeded clients are approved directly and never face this gate.

### `oauthAccessTokenTtl` / `oauthRefreshTokenTtl`

**Type:** `string` (ISO-8601 duration) &nbsp;&nbsp; **Defaults:** `PT1H` / `P30D`

The OAuth access-token and refresh-token lifetimes. Refresh tokens rotate on every exchange and carry family-lineage theft detection (a replayed consumed refresh token revokes the whole family, see [SECURITY.md](SECURITY.md)).

### `elevationTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `300` (5 minutes)

Lifetime of an elevation marker minted by the in-band `/oauth/elevate` re-authentication flow. After a fresh Craft re-auth, high-stakes operations over HTTP (credential, email and admin-status mutations on `users`, plus content publish and delete) are permitted for this window. Tracked server-side, never trusted from a client claim. `craft_exec` is **never** unlocked by elevation. See [SECURITY.md](SECURITY.md).

### `tokenTtlDefault`

**Type:** `int|null` (seconds) &nbsp;&nbsp; **Default:** `null`

Default lifetime applied to a bearer token issued without an explicit TTL. `null` means the token does not expire, which is the right default for a credential an admin issues deliberately and revokes deliberately. Set it to a number of seconds to force rotation across the whole install; a per-token `--ttl` always wins.

### `userCustomFieldAllowlist`

**Type:** `string[]` &nbsp;&nbsp; **Default:** `[]`

The custom-field handles whose values the Pro `users` tool may return on a user envelope. The default is empty, so no custom-field value is returned until an operator enumerates the handles here.

The allowlist is independent of caller permission: a handle that is not on it is never returned, not even to an admin. Craft has no native per-field-value permission and field-layout hiding is presentation only, so this is Herald's own gate. Allowlisted handles that do not exist on the User field layout are dropped silently.

### `auditResponseExcerptBytes` / `auditRetentionDays`

**Types:** `int` / `int|null` &nbsp;&nbsp; **Defaults:** `2048` (max `65535`) / `null`

`auditResponseExcerptBytes` is the number of bytes of the post-redaction, JSON-encoded tool response stored in `herald_invocations.responseExcerpt`. The full response still goes over the wire to the client; the excerpt exists for the Activity screen.

`auditRetentionDays` is the retention window for audit rows. `null` keeps history indefinitely, which is the compliance-friendly default. Set it to a number of days to prune older rows during Craft's `gc` sweep.

## HTTP transport

The Streamable HTTP transport at `/herald/mcp` requires the Pro edition and is **authenticated on every request**, with no anonymous access. Setting `httpEnabled` to `true` opens the endpoint; the controller's `beforeAction` pipeline then runs, in order: the kill switch, the edition gate, the `Origin` allowlist, the method allowlist, `MCP-Protocol-Version` validation, bearer-token or OAuth 2.1 authentication, an account-state check, the capability-scope check, and a per-user rate limit, all before the JSON-RPC dispatcher sees the request. An unauthenticated request gets `401 Unauthorized` with `WWW-Authenticate: Bearer realm="herald"` per RFC 6750. See [HTTP transport](HTTP-TRANSPORT.md) for issuing credentials and [SECURITY.md](SECURITY.md) for the full authorization model.

Two credential shapes are accepted, disambiguated by format:

- **Opaque bearer tokens**: 64-char hex, no dots; issued via `herald/token/issue`, stored hashed, bound to a Craft user.
- **OAuth 2.1 access tokens**: JWTs (contain dots); audience-bound to the canonical `/herald/mcp` URL (RFC 8707 confused-deputy defense), minted through the Dynamic Client Registration + authorization-code flow.

The endpoint supports three methods:

- **`POST`**: single JSON-RPC message per request (or an SSE stream when the client sends `Accept: text/event-stream`). Headers required:
  - `Authorization: Bearer <token>` (missing/malformed → 401; invalid/revoked → 401).
  - `MCP-Protocol-Version: 2025-11-25` or `2025-06-18` (missing → 400; an unsupported version → 400).
  - `Origin: …` (must match `allowedOrigins`; an empty allowlist is itself refused with 403 outside `devMode`).
  - `Mcp-Session-Id: …` (required on every request except `initialize` → 400; unknown id → 404).
- **`GET`**: not supported. Returns 405.
- **`DELETE`**: terminates the session identified by the `Mcp-Session-Id` header. Returns 204 on success, 404 if the session is unknown.

`craft_exec` is stdio-only and rejected at the dispatcher when called over HTTP regardless of caller permissions. The rejection comes back as a JSON-RPC error envelope (HTTP 200, JSON-RPC code -32601) so spec-compliant clients can render the message correctly.

## The CP section

Herald registers a top-level Control Panel section, **Herald**, in the global sidebar. Its subnav is permission- and edition-gated (`Herald::getCpNavItem()`): Free installs see **Settings** and **Temporary grants**; Pro adds **Tokens**, **Clients**, **Activity**, and **Connection**. The **Clients** screen lists registered OAuth clients and is where an admin approves or revokes them (the DCR approval gate). Saving the **Settings** screen writes to project config so changes sync across environments via your normal `project-config/apply` flow; the other screens read and mutate runtime DB state.

The plugin Settings screen also stays reachable the usual way, from **Settings → Plugins → Herald**.

### Settings

Editable form fields for the project-config-synced plugin defaults.

- **Allowed commands**: a grouped toggle browser over the `allowedCommands` patterns. Every console command on the install (core Craft plus installed plugins) is enumerated and grouped by controller. Flip a whole group on to allow `group/*`; expand a group and flip individual actions to allow exact route ids; a partially-allowed group shows an "N/total allowed" badge. A filter box narrows the list live. Patterns that don't map to a listed command (custom wildcards like `resave/ent*`, or commands for plugins not installed here) appear in a **Custom patterns** table and are preserved verbatim. When `allowedCommands` is set in `config/herald.php` the browser renders read-only with a "defined in config" warning.
- **`craft_exec` enabled**: toggle for `execEnabled`.
- **`craft_exec` dry-run by default**: toggle for `execDryRunDefault`.
- **Temporary grant TTL (seconds)**: integer input for `runtimeOverrideTtl`.

The Settings screen renders in read-only mode when `allowAdminChanges` is off; the save action gates on `requireAdmin(requireAdminChanges: true)`.

### Temporary grants

A live editor for short-lived `craft_command` allowlist additions: time-bound grants an admin issues on top of the Settings allowlist that expire automatically (DB-backed; internally these are the runtime "override" rows). Useful when you need to grant the LLM a one-off command (`db/restore` after a debugging session, `index-assets/all` while diagnosing a missing thumbnail) without committing the change to project config.

- **Issue grant**: pattern (e.g. `db/restore`), optional note, optional custom expiry, edited through a Garnish slideout.
- **Grants table**: table view with expiry status. Removing one soft-deletes immediately; expired grants surface until Craft's `gc` sweep removes them.
- **Effective allowlist right now**: a read-only panel listing the combined set `craft_command` may dispatch this moment: the base Settings patterns plus every active grant, each grant annotated with its expiry.

Grant mutations require `requireAdmin(requireAdminChanges: true)` because they're a security boundary, so non-admin CP users can't grant themselves new commands.

The effective allowlist `craft_command` consults at dispatch time is the **union** of `Settings::$allowedCommands` and the active grants. Both are checked; either grants permission.

### Tokens

Issue, list, and revoke long-lived HTTP-transport bearer tokens. Each token is bound to a Craft user, stored hashed, and shown in plaintext exactly once at issue time. Admin-gated.

### Activity

Browse the `herald_invocations` audit log: one row per tool invocation, with tool name, transport, user, duration, outcome, and a redacted argument / response excerpt. Gated on the `herald:view-activity` permission (`Herald::PERMISSION_VIEW_ACTIVITY`) rather than admin, so you can grant audit visibility without granting settings access.

### Connection

Read-only connection helper: the stdio command and the HTTP endpoint URL, ready to paste into a client config.

## `config/herald.php`

For environment-specific overrides, copy `vendor/craftpulse/craft-herald/src/config/herald.php` to `<project-root>/config/herald.php`. The file is heavily commented and shows the precedence rules.

The file follows Craft's standard multi-environment pattern: top-level wildcard `*` applies everywhere; named-environment keys (`production`, `staging`, `dev`) override the wildcard.

```php
<?php

return [
    '*' => [
        // Wildcard: applies everywhere unless overridden below.
        'execDryRunDefault' => true,
        'runtimeOverrideTtl' => 604800,
    ],

    'production' => [
        // Tighter posture on production.
        'allowedCommands' => [
            'cache/flush',
            'cache/flush-all',
            'invalidate-tags/*',
            'gc',
            'mailer/test',
        ],
        'execEnabled' => false,
    ],

    'staging' => [
        'runtimeOverrideTtl' => 86400, // 1 day on staging.
    ],
];
```

`CRAFT_ENVIRONMENT` (set in `.env`) determines which named-environment block applies.

### Precedence summary

When the same setting appears in multiple layers, this is what wins (highest priority first):

1. `config/herald.php` environment-specific block (e.g. `production`)
2. `config/herald.php` wildcard block (`*`)
3. Project config (CP **Defaults** form, synced via `project-config/apply`)
4. `models/Settings` defaults

Runtime DB overrides layer **on top of** `allowedCommands` only. They don't override the toggles (`execEnabled` / `execDryRunDefault`) or the TTL config.

## Logging

Herald emits one structured audit-log line per tool invocation under the `herald` log channel. The line shape is locked across both transports:

```
tool=<name> kind=<success|tool_error|internal_error|cancelled|rate_limited> duration_ms=<int>
  transport=<stdio|http> request_id=<id|-> user=<id|->
  client=<name|-> args=<redacted-json>
```

Unknown / not-yet-populated fields emit `-` (Apache common-log convention). stdio emits `transport=stdio`, populates `request_id` from the JSON-RPC envelope, captures `client` from the MCP `initialize` handshake's `clientInfo.name`, and leaves `user` as `-` (no per-request identity on the trusted local transport). The HTTP transport emits `transport=http` and fills `user` from the Craft user the bearer/OAuth token resolves to.

Arguments are passed through `tools/support/SecretRedactor` before serialisation. The redactor normalises keys and catches those named like secrets (`password`, `securitykey`, `token`, `secret`, `apikey`, `privatekey`, `oauth`, `bearer`, and more), replacing the value with the literal placeholder `<redacted>`. Inline `KEY=value` patterns in flat strings are also redacted.

To route Herald logs to a dedicated file, configure a Yii log target in `config/app.php`:

```php
return [
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['info', 'warning', 'error'],
                    'categories' => ['herald'],
                    'logFile' => '@storage/logs/herald.log',
                ],
            ],
        ],
    ],
];
```

## Audit log

Alongside the log-channel line above, Herald persists every tool invocation to the DB-backed `herald_invocations` table and surfaces it on the [Activity tab](#activity) of the CP settings page. Each row carries the tool name, transport, resolving user (HTTP), JSON-RPC request id, client name, duration, outcome (`success` / `tool_error` / `internal_error` / `rate_limited`), and a redacted argument / response excerpt.

Two settings tune the table:

- **`auditResponseExcerptBytes`** (`int`, default `2048`, max `65535`): bytes of the post-redaction JSON-encoded tool response stored in `herald_invocations.responseExcerpt`. The full response still goes over the wire to the client; the excerpt is for the audit dashboard only.
- **`auditRetentionDays`** (`int|null`, default `null`): retention window. `null` keeps audit history forever (the compliance-friendly default); set e.g. `90` to prune rows older than 90 days during Craft's `gc` sweep.

The log-channel line and the table share the same field shape, so external SIEM forwarders pinned against the log lines and dashboards reading the table see a consistent record.
