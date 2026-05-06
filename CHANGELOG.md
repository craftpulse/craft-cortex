# Changelog

All notable changes to Cortex are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning per
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — Gate 5 (Phase 1, GraphQL & Dev Actions)
- 5 new tools — Phase 1 free tier complete at **28 tools total**:
  - `graphql` — modes `list_schemas` / `get_sdl` / `list_tokens`. Token
    metadata never returns the access-token value; a SHA-256 fingerprint
    (12 hex chars) is surfaced for correlation only.
  - `clear_caches` — convenience wrapper for the cache-clear surface.
    Modes: `list`, `all` (default), or any registered cache key (`data`,
    `asset`, `compiled-templates`, `compiled-classes`, `cp-resources`,
    `temp-files`, `transform-indexes`, `asset-indexing-data`, plus any
    plugin-registered keys).
  - `resave` — wraps Craft's `resave/*` commands with structured params
    (`type` selects entries / assets / categories / tags / users /
    addresses; per-type filters validated at the tool layer; `set`/`to`
    paired). Output captured cleanly so the JSON-RPC channel stays clean.
  - `craft_command` — allowlisted runner for the rest of the Craft /
    Yii console surface. `mode: "list"` exposes the active patterns,
    `mode: "run"` dispatches. Allowlist comes from
    `Settings::$allowedCommands` (project-config + `config/cortex.php`
    overrides; runtime DB overrides land with the CP UI in Gate 6).
    Default patterns mirror PLANNING.md 4.9.
  - `craft_exec` — wraps Craft's `ExecController` eval pattern with all
    six security gates (PLANNING.md 4.9):
    1. **Dry-run default** — without `confirm: true` returns the parsed
       expression and proposed effect, never the result.
    2. **Structured output** — typed errors (`parse_error` /
       `runtime_error`) with class, file, line, stack trace.
    3. **Secret redaction** — values + captured stdout run through
       `support/SecretRedactor` before return.
    4. **Destructive-op guard** — `delete*` / `drop*` / `truncate*` /
       `Elements::deleteElement` / `migrate/down` patterns require both
       `confirm: true` AND `dangerous: true`.
    5. **stdio only** — `isStdioOnly()` returns true; the dispatcher
       hard-rejects on HTTP regardless of token scope.
    6. **`destructiveHint: true` annotation** — surfaced via
       `getAnnotations()` so spec-compliant clients warn the user.
- Tool contract extended with two static methods on `ToolInterface`
  (defaults on `AbstractTool`):
  - `getAnnotations(): array` — surfaced in `tools/list` per MCP spec
    2025-06-18 (`destructiveHint`, `idempotentHint`, `readOnlyHint`,
    `openWorldHint`, `title`).
  - `isStdioOnly(): bool` — checked by the dispatcher to hard-reject on
    HTTP.
- `mcp/Server` now takes a `$transport` constructor arg
  (`Server::TRANSPORT_STDIO` default; `Server::TRANSPORT_HTTP` for
  Phase 2). Dispatcher rejects stdio-only tools on HTTP with JSON-RPC
  -32601.
- `tools/support/ConsoleRunner` — runs `Craft::$app->runAction()` with
  STDOUT/STDERR captured via a stream filter
  (`tools/support/StdoutCaptureFilter`) so Phase 1 stdio MCP doesn't
  corrupt its own JSON-RPC channel when commands write progress. ANSI
  colour codes are stripped from captured output.
- `tools/support/SecretRedactor` — single source of truth for the
  redaction needle list (`password`, `securitykey`, `token`, `secret`,
  `apikey`, `accesskey`, `privatekey`, `salt`, `cookievalidationkey`,
  `webhooksecret`, `jwt`, `oauth`, `bearer`). `redactArray()` walks
  associative arrays, `redactString()` matches `KEY=value` /
  `KEY: value` patterns in flat text.
- `models/Settings` — plugin settings model with `allowedCommands`
  defaults and `execEnabled` / `execDryRunDefault` toggles. Wired via
  `Plugin::createSettingsModel()`.
- **Total tests: 150 passing** (up from 107), 5 conditional skips,
  3456 assertions, ~2.7s. **PHPStan level 8 clean.** Smoke-verified
  through stdio: `graphql`, `clear_caches`, `craft_command`,
  `craft_exec` (dry-run, evaluated, destructive-guard) all return
  expected envelopes.

### Added — Gate 4 (Phase 1, System & Diagnostics tools)
- 8 system tools in `src/tools/system/`:
  - `system_info` — Craft + PHP + DB + sites + license snapshot.
  - `config` — multi-mode (general / custom / db / email / system_messages),
    fully secrets-redacted via a key-substring matcher.
  - `plugins` — every installed plugin with handle, version, edition,
    schema version, license status, enabled flag.
  - `routes` — combined config-file + project-config + section URI +
    category-group URI map.
  - `diagnostics` — multi-mode (logs / last_error / deprecations /
    queue / project_config_diff). Reverse-walks log files in 8 KiB
    chunks; caps result count at 500; uses Craft's queue API (not
    direct table access) so it survives queue-driver swaps.
  - `database_schema` — full Yii schema introspection (tables,
    columns, indexes, foreign keys); `mode: "list"` for names-only;
    `tables: [...]` filter. No data queries.
  - `extensibility` — events / Twig / utilities / commands inventory.
    Reflects Yii's `Event::$_events` static registry for the events
    section.
  - `permissions_and_groups` — full permissions tree (built-in +
    plugin-registered) plus user groups with assigned permissions.
    No PII.
- Total Phase-1 tool count: **23**.
- **PHPStan level 8 clean** (`ddev exec vendor/bin/phpstan -c phpstan.neon`).
- **Tests: 107 passing**, 5 conditional skips, 3041 assertions, ~0.7s.

### Setup
- `phpstan.neon` — extends `craftcms/phpstan` baseline at level 8 with
  three small ignores for type-narrowing patterns Craft's stubs are
  conservatively typed for.
- `ecs.php` — set up using `craftcms/ecs` SetList::CRAFT_CMS_4 (no
  CRAFT_CMS_5 set published yet). **Currently blocked at runtime** by
  the playground pinning `craftcms/ecs dev-main` against
  `symplify/easy-coding-standard ^10.3.3`, which doesn't support the
  `ECSConfig::configure()` API the playground's own ecs.php uses.
  ECS will run cleanly once `craftcms/ecs` ships a v11+ compatible
  release. The config is correct — just blocked upstream.

### Added — Gate 3 (Phase 1, Content Reading tools)
- 5 content-reading tools in `src/tools/content/`:
  - `entries` — full element-query surface: section/type/status/author/
    relatedTo/search filters, eager loading via `with: [...]`, structure
    params (level, hasDescendants, leaves, descendantOf, ancestorOf,
    siblingOf), pagination (limit hard-cap 1000, default 100), site
    filter, count mode, single-id shortcut.
  - `assets` — same query surface plus `mode: "folders"` returning the
    folder hierarchy for a volume.
  - `categories` — list/get/count with structure params + group filter.
  - `tags` — list/get/count with group filter.
  - `globals` — read global set values, optionally per-site and per-handle.
- `src/tools/support/ElementSerializer` — projects elements to a stable
  JSON shape (identity headers + custom field values). Refuses to
  materialise a relational `ElementQuery` unless the caller passed it
  in `with: [...]`; otherwise stubs as `{type: "relation", loaded: false}`
  so we never trigger N+1.
- `cortex_count_queries(callable): [result, queryCount]` test helper for
  asserting query budgets.
- Total Phase-1 tool count: **15**.
- Total tests: **78 passing**, 5 conditional skips (no fixtures), 1456
  assertions, ~0.4s.

### Changed
- `volumes_and_filesystems` tool now also returns the registered
  filesystem-type classes (`filesystemTypes`) — built-in `craft\fs\Local`
  plus any plugin-provided types (Servd, S3, GCS, Azure, Imgix, …) —
  alongside `volumes`, `orphanFilesystems`, and the counts. Lets the AI
  discover what storage backends are available before configuring a new
  filesystem. Each type is flagged `isFirstParty: true|false`.

### Added — Gate 2 (Phase 1, Schema & Structure tools)
- Tool registry: `ToolInterface`, `AbstractTool`, `ToolException`,
  `services/Tools` (Yii component, registered via `Plugin::config()`).
- Transport-agnostic `mcp/Server` JSON-RPC dispatcher; `ServeController`
  refactored to thin stdio adapter.
- 10 schema tools, all in `src/tools/schema/`:
  - `sections` — list / get / count
  - `entry_types` — list (summary) / get (full field layout) / count
  - `fields` — list / get / count plus `mode: "usage"` map
  - `field_types` — all registered field type classes with capabilities
  - `category_groups` — list / get / count
  - `tag_groups` — list / get / count
  - `volumes_and_filesystems` — combined map + orphan filesystems
  - `sites` — list (with groups + primary) / get / count
  - `image_transforms` — list / get / count
  - `element_types` — all registered element types with capability flags
- Tool design discipline per PLANNING.md 4.13: list/get collapsed
  behind optional `handle`, `count: true` mode where applicable,
  structured JSON content envelopes, tool-level errors via
  `ToolException` (returned as `isError: true`), protocol errors via
  JSON-RPC error response.
- `tools/call` dispatcher with structured error handling.

### Added — Gate 1 (initial release skeleton)
- Initial plugin skeleton: `composer.json`, `Plugin.php`, console
  controller `cortex/serve` speaking MCP JSON-RPC 2.0 over stdio.
- Hand-rolled JSON-RPC handler covering `initialize`,
  `notifications/initialized`, `tools/list`, `resources/list`,
  `prompts/list`, and `ping`. Empty registries; tools landed in Gate 2.
- Pinned MCP spec version: `2025-06-18`.

### Added — Pest test infrastructure
- `tests/Bootstrap.php` boots Craft's console application against the
  surrounding install (the playground), priming `Craft::$app` and
  registering the cortex plugin so tests call
  `Plugin::getInstance()->tools->...` directly.
- `tests/Pest.php` registers global custom expectations
  (`toBeMcpToolListItem`, `toBeMcpSuccessEnvelope`, `toBeMcpErrorEnvelope`)
  and a `cortex_unwrap()` helper for unwrapping MCP success envelopes.
- `phpunit.xml.dist` defines the `cortex` test suite.
- Test coverage:
  - `tests/SmokeTest.php` — Craft boots, plugin registers, Tools
    component wired.
  - `tests/Tools/RegistryTest.php` — registry contains all 10 expected
    tools, lookups by name, MCP `tools/list` payload shape.
  - `tests/Tools/Schema/*Test.php` — one file per schema tool covering
    list / get-by-handle / count / mode-specific paths and
    `ToolException` cases.
  - `tests/Mcp/ServerTest.php` — JSON-RPC dispatcher: initialize,
    notifications, tools/list, tools/call success + error envelopes,
    every protocol error code path (-32600, -32601, -32602).
- **52 tests, 1040 assertions, 0.28s.** One conditional skip when the
  playground has no category groups to fetch by handle.
- Run via `ddev exec --dir=/var/www/html/cms vendor/bin/pest --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist`.
