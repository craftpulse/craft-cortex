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
- [The CP settings page](#the-cp-settings-page)
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

The recommended posture for production is `execEnabled = false`, since `craft_exec` is intentionally a development tool and the HTTP transport (Phase 2) rejects it regardless.

### `execDryRunDefault`

**Type:** `bool` &nbsp;&nbsp; **Default:** `true`

Whether `craft_exec` defaults to dry-run mode. With this on (default), the LLM has to explicitly pass `confirm: true` in the tool call to actually evaluate an expression — without `confirm`, Cortex returns the parsed expression and proposed effect and skips execution.

Flipping this to `false` makes evaluation the default. This is **not** a security override — destructive expressions (`delete*`, `drop*`, `truncate*`, `Elements::deleteElement`, `migrate/down`) still require both `confirm: true` AND `dangerous: true`. The destructive-op guard runs regardless of this setting.

### `runtimeOverrideTtl`

**Type:** `int` (seconds) &nbsp;&nbsp; **Default:** `604800` (7 days)

The default TTL applied to a new runtime override when no expiry is supplied at creation time. Shorter values produce a tighter security posture (overrides expire faster, fewer surprise grants in the system); longer values reduce friction for teams that often need short-term command grants.

Runtime overrides are admin-issued through the CP and stored in the `cortex_runtime_overrides` DB table. Expired overrides remain in the table (soft-delete) but no longer count toward the effective allowlist. Expired non-deleted overrides are hard-deleted inline during Craft's regular garbage-collection sweep.

## The CP settings page

Cortex registers a settings page at **Settings → Cortex** in the Control Panel. It has two sections:

### Defaults

Editable form fields backed by Craft's standard `plugins/save-plugin-settings` action. Saving here writes to project config so changes sync across environments via your normal `project-config/apply` flow.

- **Allowed commands** — text-area editor for the `allowedCommands` array, one pattern per line.
- **`craft_exec` enabled** — toggle for `execEnabled`.
- **`craft_exec` dry-run by default** — toggle for `execDryRunDefault`.
- **Runtime override TTL (seconds)** — integer input for `runtimeOverrideTtl`.

### Runtime overrides

A live editor for short-lived allowlist additions. Useful when you need to grant the LLM a one-off command (`db/restore` after a debugging session, `index-assets/all` while diagnosing a missing thumbnail) without committing the change to project config.

- **Add an override** — pattern (e.g. `db/restore`), optional note, optional custom expiry.
- **Active overrides** — list view with expiry status. Clicking *Cancel* soft-deletes immediately; expired overrides surface in greyed-out form until the cleanup job removes them.

Override mutations require `requireAdmin(requireAdminChanges: true)` — they're a security boundary, so non-admin CP users can't grant themselves new commands.

The effective allowlist `craft_command` consults at dispatch time is the **union** of `Settings::$allowedCommands` and the active runtime overrides. Both are checked; either grants permission.

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

Cortex emits one structured audit-log line per tool invocation under the `cortex` log channel. The line shape is locked across Phase 1 and Phase 2:

```
tool=<name> kind=<success|tool_error|internal_error> duration_ms=<int>
  transport=<stdio|http> request_id=<id|-> user=<id|->
  client=<name|-> args=<redacted-json>
```

Unknown / not-yet-populated fields emit `-` (Apache common-log convention). Phase 1 stdio always emits `transport=stdio`, populates `request_id` from the JSON-RPC envelope, captures `client` from the MCP `initialize` handshake's `clientInfo.name`, and leaves `user` as `-`. Phase 2's HTTP transport will fill `user` once OAuth resolves the bearer token to a Craft user.

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

Phase 2 adds a DB-backed `cortex_invocations` audit table and a CP UI to browse it. The line format above is the same shape that table will consume, so external SIEM forwarders pinned against the Phase 1 lines will keep working unchanged through the upgrade.
