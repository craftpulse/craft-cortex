# Configuring Cortex

Cortex ships with sensible defaults; most projects don't need to touch the configuration. When you do, you have three layers of override available, applied in this order (highest priority first):

1. **`config/cortex.php`** — file-based overrides, environment-aware. Versioned alongside your project.
2. **Runtime overrides** — admin-issued, auto-expiring entries via the CP. Applies to the `craft_command` allowlist only.
3. **Project config** — synced across environments; edited via the CP **Settings → Cortex** page.
4. **Defaults** — baked into `models/Settings`.

This document covers each setting in detail, then explains how to use the CP UI and the `config/cortex.php` file.

- [Settings reference](#settings-reference)
  - [`allowedCommands`](#allowedcommands)
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
- [HTTP transport](#http-transport)
- [The CP settings page](#the-cp-settings-page)
- [Audit log](#audit-log)
- [`config/cortex.php`](#configcortexphp)
- [Logging](#logging)

## Settings reference

### `allowedCommands`

**Type:** `string[]` &nbsp;&nbsp; **Default:** see below

The set of glob patterns the `craft_command` tool is allowed to dispatch. Patterns use `fnmatch` semantics — `resave/*` matches any `resave/<route>`, `up` matches the literal `up` command, `migrate/all` is a single literal route.

Default allowlist (out-of-the-box, on every environment):

```
resave/*            project-config/*    cache/*
invalidate-tags/*   migrate/*           up
index-assets/*      gc                  make/*
fixture/*           sections/*          fields/*
users/create        entrify/*           db/backup
db/restore          utils/*             clear-deprecations
mailer/test
```

These cover the dev / ops surface most teams need without exposing dangerous commands. To lock things down further (or open more up), override per-environment via [`config/cortex.php`](#configcortexphp). For short-lived grants without a deploy, use the [runtime override UI](#the-cp-settings-page).

### `execEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether the `craft_exec` tool is registered at all. The tool is stdio-only and runs through six security gates ([see security docs](SECURITY.md#craft_exec-six-security-gates)) — but if your team doesn't want it on the menu, set this to `false` and Cortex removes it from `tools/list` entirely.

The recommended posture for production is `execEnabled = false`, since `craft_exec` is intentionally a development tool and the HTTP transport rejects it regardless.

### `execDryRunDefault`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether `craft_exec` defaults to dry-run mode. With this on (default), the LLM has to explicitly pass `confirm: true` in the tool call to actually evaluate an expression — without `confirm`, Cortex returns the parsed expression and proposed effect and skips execution.

Flipping this to `false` makes evaluation the default. This is **not** a security override — destructive expressions (`delete*`, `drop*`, `truncate*`, `Elements::deleteElement`, `migrate/down`) still require both `confirm: true` AND `dangerous: true`. The destructive-op guard runs regardless of this setting.

### `runtimeOverrideTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `604800` (7 days)

The default TTL applied to a new runtime override when no expiry is supplied at creation time. Shorter values produce a tighter security posture (overrides expire faster, fewer surprise grants in the system); longer values reduce friction for teams that often need short-term command grants.

Runtime overrides are admin-issued through the CP and stored in the `cortex_runtime_overrides` DB table. Expired overrides remain in the table (soft-delete) but no longer count toward the effective allowlist. Expired non-deleted overrides are hard-deleted inline during Craft's regular garbage-collection sweep.

### `stdioMaxMessageBytes`

**Type:** `int` (bytes) &nbsp;&nbsp; **Default:** `4194304` (4 MiB) &nbsp;&nbsp; **Minimum:** `1024`

Maximum size of a single newline-delimited JSON-RPC message the stdio transport will buffer before rejecting it with a JSON-RPC `-32600` Invalid Request. The reader reassembles each line in bounded chunks; if one line's accumulated bytes exceed the cap before a newline arrives, the reader drains the rest of the line, emits the error envelope, and continues with the next message rather than buffering an unbounded payload into memory.

The 4 MiB default is comfortably larger than any legitimate `tools/call` argument blob, but small enough that a hostile client streaming one giant line can't OOM the long-running `cortex/serve` process. stdio is a trusted-local transport, but a trusted local *user* is not the same as a trusted client *implementation* (cf. the `craft_exec` threat model), so the cap holds regardless.

### `httpEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `false`

Whether the HTTP transport (`POST/GET/DELETE /cortex/mcp`) accepts requests. Defaults to off — the transport is opt-in, not because it is unfinished but because most installs only need stdio. When you do enable it, it enforces full authentication on every request (bearer token or OAuth 2.1 — see [HTTP transport](#http-transport) and [SECURITY.md](SECURITY.md)); it is **not** an anonymous endpoint.

When this flag is `false`, every request to `/cortex/mcp` returns `503 Service Unavailable` regardless of headers or credentials.

### `allowedOrigins`

**Type:** `string[]` &nbsp;&nbsp; **Default:** `[]`

Allowlist of `Origin` header values the HTTP transport accepts. The MCP spec mandates Origin validation as DNS-rebinding defense — when a request's `Origin` header does not match an entry in this list, Cortex rejects it with `403 Forbidden`.

Empty means permissive (every Origin accepted). That's fine for local development; it is **not** fine for any deployed environment. Cortex logs a warning to the `cortex` channel on every request when the allowlist is empty and `httpEnabled` is `true`, so configuration drift is visible in your logs.

Set this to the explicit URLs of every client that talks to the endpoint — Claude Desktop's local proxy, Cursor's HTTP setup, etc.

### `sessionTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `3600` (1 hour)

Sliding TTL applied to HTTP-transport sessions in Craft's cache. Every authenticated request resets the cache entry's expiry, so an active client stays alive indefinitely while idle clients evict naturally.

Sessions are keyed by an opaque `Mcp-Session-Id` returned in the response header on `initialize` and required on every subsequent POST. They are stored in the configured cache backend (PSR-16) — no DB write — and survive request boundaries but not cache flushes.

### `dcrEnabled`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether RFC 7591 Dynamic Client Registration is open on `POST /oauth/register`. MCP-native clients self-register on first contact; flip to `false` to require out-of-band client seeding.

### `dcrAutoApprove`

**Type:** `bool` &nbsp;&nbsp; **Default:** `false`

Whether a newly DCR-registered client is auto-approved. Default `false` means every self-registered client lands **unapproved** — its authorize and token flows are rejected with a "pending admin approval" error until an admin approves it on the **Clients** CP screen. Flip to `true` for trusted / dev installs that want the zero-friction self-registration MCP clients expect. Out-of-band-seeded clients are approved directly and never face this gate.

### `oauthAccessTokenTtl` / `oauthRefreshTokenTtl`

**Type:** `string` (ISO-8601 duration) &nbsp;&nbsp; **Defaults:** `PT1H` / `P30D`

The OAuth access-token and refresh-token lifetimes. Refresh tokens rotate on every exchange and carry family-lineage theft detection (a replayed consumed refresh token revokes the whole family — see [SECURITY.md](SECURITY.md)).

### `elevationTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `300` (5 minutes)

Lifetime of an elevation marker minted by the in-band `/oauth/elevate` re-authentication flow. After a fresh Craft re-auth (password + 2FA), high-stakes operations over HTTP — credential / email / admin-status mutations on `users`, and content publish / delete — are permitted for this window, bound to the specific access token. Tracked server-side, never trusted from a client claim. `craft_exec` is **never** unlocked by elevation. See [SECURITY.md](SECURITY.md).

## HTTP transport

The Streamable HTTP transport at `/cortex/mcp` is **authenticated on every request** — there is no anonymous access. Setting `httpEnabled = true` opens the endpoint; the controller's `beforeAction` pipeline then enforces, in order: method allowlist, `MCP-Protocol-Version` validation, **bearer-token / OAuth 2.1 authentication**, and per-user rate limiting before the JSON-RPC dispatcher ever sees the request. An unauthenticated request gets `401 Unauthorized` with `WWW-Authenticate: Bearer realm="cortex"` per RFC 6750. See [INSTALL.md](INSTALL.md) for issuing tokens and [SECURITY.md](SECURITY.md) for the full auth model.

Two credential shapes are accepted, disambiguated by format:

- **Opaque bearer tokens** — 64-char hex, no dots; issued via `cortex/token/issue`, stored hashed, bound to a Craft user.
- **OAuth 2.1 access tokens** — JWTs (contain dots); audience-bound to the canonical `/cortex/mcp` URL (RFC 8707 confused-deputy defense), minted through the Dynamic Client Registration + authorization-code flow.

The endpoint supports three methods:

- **`POST`** — single JSON-RPC message per request (or an SSE stream when the client sends `Accept: text/event-stream`). Headers required:
  - `Authorization: Bearer <token>` (missing/malformed → 401; invalid/revoked → 401).
  - `MCP-Protocol-Version: 2025-11-25` or `2025-06-18` (missing → 400; an unsupported version → 400).
  - `Origin: …` (must match `allowedOrigins` when the list is non-empty → 403).
  - `Mcp-Session-Id: …` (required on every request except `initialize` → 400; unknown id → 404).
- **`GET`** — reserved for SSE upgrade. Returns 405 today.
- **`DELETE`** — terminates the session identified by the `Mcp-Session-Id` header. Returns 204 on success, 404 if the session is unknown.

`craft_exec` is stdio-only and rejected at the dispatcher when called over HTTP regardless of caller permissions. The rejection comes back as a JSON-RPC error envelope (HTTP 200, JSON-RPC code -32601) so spec-compliant clients can render the message correctly.

## The CP section

Cortex registers a top-level Control Panel section, **Cortex**, in the global sidebar. Its subnav is permission- and edition-gated (`Cortex::getCpNavItem()`): Free installs see **Settings** and **Temporary grants**; Pro adds **Tokens**, **Clients**, **Activity**, and **Connection**. The **Clients** screen lists registered OAuth clients and is where an admin approves or revokes them (the DCR approval gate). Saving the **Settings** screen writes to project config so changes sync across environments via your normal `project-config/apply` flow; the other screens read and mutate runtime DB state.

The plugin Settings screen also stays reachable the usual way, from **Settings → Plugins → Cortex**.

### Settings

Editable form fields for the project-config-synced plugin defaults.

- **Allowed commands** — a grouped toggle browser over the `allowedCommands` patterns. Every console command on the install (core Craft plus installed plugins) is enumerated and grouped by controller. Flip a whole group on to allow `group/*`; expand a group and flip individual actions to allow exact route ids; a partially-allowed group shows an "N/total allowed" badge. A filter box narrows the list live. Patterns that don't map to a listed command — custom wildcards like `resave/ent*`, or commands for plugins not installed here — appear in a **Custom patterns** table and are preserved verbatim. When `allowedCommands` is set in `config/cortex.php` the browser renders read-only with a "defined in config" warning.
- **`craft_exec` enabled** — toggle for `execEnabled`.
- **`craft_exec` dry-run by default** — toggle for `execDryRunDefault`.
- **Temporary grant TTL (seconds)** — integer input for `runtimeOverrideTtl`.

The Settings screen renders in read-only mode when `allowAdminChanges` is off; the save action gates on `requireAdmin(requireAdminChanges: true)`.

### Temporary grants

A live editor for short-lived `craft_command` allowlist additions — time-bound grants an admin issues on top of the Settings allowlist that expire automatically (DB-backed; internally these are the runtime "override" rows). Useful when you need to grant the LLM a one-off command (`db/restore` after a debugging session, `index-assets/all` while diagnosing a missing thumbnail) without committing the change to project config.

- **Issue grant** — pattern (e.g. `db/restore`), optional note, optional custom expiry, edited through a Garnish slideout.
- **Grants table** — table view with expiry status. Removing one soft-deletes immediately; expired grants surface until Craft's `gc` sweep removes them.
- **Effective allowlist right now** — a read-only panel listing the combined set `craft_command` may dispatch this moment: the base Settings patterns plus every active grant, each grant annotated with its expiry.

Grant mutations require `requireAdmin(requireAdminChanges: true)` — they're a security boundary, so non-admin CP users can't grant themselves new commands.

The effective allowlist `craft_command` consults at dispatch time is the **union** of `Settings::$allowedCommands` and the active grants. Both are checked; either grants permission.

### Tokens

Issue, list, and revoke long-lived HTTP-transport bearer tokens. Each token is bound to a Craft user, stored hashed, and shown in plaintext exactly once at issue time. Admin-gated.

### Activity

Browse the `cortex_invocations` audit log — one row per tool invocation, with tool name, transport, user, duration, outcome, and a redacted argument / response excerpt. Gated on the `cortex:viewActivity` permission (`Cortex::PERMISSION_VIEW_ACTIVITY`) rather than admin, so you can grant audit visibility without granting settings access.

### Connection

Read-only connection helper — the stdio command and the HTTP endpoint URL, ready to paste into a client config.

## `config/cortex.php`

For environment-specific overrides, copy `vendor/craftpulse/craft-cortex/src/config/cortex.php` to `<project-root>/config/cortex.php`. The file is heavily commented and shows the precedence rules.

The file follows Craft's standard multi-environment pattern: top-level wildcard `*` applies everywhere; named-environment keys (`production`, `staging`, `dev`) override the wildcard.

```php
<?php

return [
    '*' => [
        // Wildcard — applies everywhere unless overridden below.
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

1. `config/cortex.php` environment-specific block (e.g. `production`)
2. `config/cortex.php` wildcard block (`*`)
3. Project config (CP **Defaults** form, synced via `project-config/apply`)
4. `models/Settings` defaults

Runtime DB overrides layer **on top of** `allowedCommands` only. They don't override the toggles (`execEnabled` / `execDryRunDefault`) or the TTL config.

## Logging

Cortex emits one structured audit-log line per tool invocation under the `cortex` log channel. The line shape is locked across both transports:

```
tool=<name> kind=<success|tool_error|internal_error> duration_ms=<int>
  transport=<stdio|http> request_id=<id|-> user=<id|->
  client=<name|-> args=<redacted-json>
```

Unknown / not-yet-populated fields emit `-` (Apache common-log convention). stdio emits `transport=stdio`, populates `request_id` from the JSON-RPC envelope, captures `client` from the MCP `initialize` handshake's `clientInfo.name`, and leaves `user` as `-` (no per-request identity on the trusted local transport). The HTTP transport emits `transport=http` and fills `user` from the Craft user the bearer/OAuth token resolves to.

Arguments are passed through `tools/support/SecretRedactor` before serialisation. The redactor catches keys named like secrets (`password`, `token`, `apiKey`, `secret`, `accessKey`, `privateKey`, `salt`, `cookieValidationKey`, `webhookSecret`, `jwt`, `oauth`, `bearer`) and replaces values with `[REDACTED]`. Inline `KEY=value` / `KEY: value` patterns in flat strings are also redacted.

To route Cortex logs to a dedicated file, configure a Yii log target in `config/app.php`:

```php
return [
    'components' => [
        'log' => [
            'targets' => [
                [
                    'class' => yii\log\FileTarget::class,
                    'levels' => ['info', 'warning', 'error'],
                    'categories' => ['cortex'],
                    'logFile' => '@storage/logs/cortex.log',
                ],
            ],
        ],
    ],
];
```

## Audit log

Alongside the log-channel line above, Cortex persists every tool invocation to the DB-backed `cortex_invocations` table and surfaces it on the [Activity tab](#activity) of the CP settings page. Each row carries the tool name, transport, resolving user (HTTP), JSON-RPC request id, client name, duration, outcome (`success` / `tool_error` / `internal_error` / `rate_limited`), and a redacted argument / response excerpt.

Two settings tune the table:

- **`auditResponseExcerptBytes`** (`int`, default `2048`, max `65535`) — bytes of the post-redaction JSON-encoded tool response stored in `cortex_invocations.responseExcerpt`. The full response still goes over the wire to the client; the excerpt is for the audit dashboard only.
- **`auditRetentionDays`** (`int|null`, default `null`) — retention window. `null` keeps audit history forever (the compliance-friendly default); set e.g. `90` to prune rows older than 90 days during Craft's `gc` sweep.

The log-channel line and the table share the same field shape, so external SIEM forwarders pinned against the log lines and dashboards reading the table see a consistent record.
