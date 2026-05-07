# Changelog

All notable changes to Cortex are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning per
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — Gate 6.5 ship-prep close-out (Phase 1 final pass)

Audit-driven follow-up to the Gate 6.5 build below. An adversarial review
flagged two ship blockers in the existing surface and a set of Phase 2
contracts that were cheaper to lock in now than to deprecate later. The
close-out lands the fixes, freezes the public extension surface, and
adds the architecture tests that catch convention drift.

#### BLOCKER fixes

- `tools/dev/CraftExec` — `execDryRunDefault` setting was wired into
  the CP toggle but never read by the tool. The default now correctly
  cascades into `execute()` so a user setting "dry-run by default" in
  the CP actually applies. Was masked because the tests passed
  `confirm: true` explicitly; added a regression test that hits the
  default code path.
- `console/InstallController` — three of the seven generated client
  snippets were wrong against current upstream docs:
  - Claude Code missed the `--` separator on `claude mcp add`,
    causing the wrapped `docker exec -i` to parse `-i` as a Claude
    flag.
  - Continue.dev still pointed at the deprecated
    `experimental.modelContextProtocolServers` JSON form instead of
    the modern YAML `mcpServers:` key.
  - Zed snippet used the wrong key (`assistant.mcp_servers` vs
    `context_servers`).

#### Phase 2 lock-in (interfaces and contracts frozen ahead of ship)

- `ToolInterface` extended with two new methods, both defaulted on
  `AbstractTool` so existing tools require no change:
  - `outputSchema(): array` — JSON Schema for the structured tool
    response. Empty array means "no output schema declared." Pro
    tools and any tool that benefits from advertising response shape
    override.
  - `shouldRegister(): bool` — return `false` to opt the tool out of
    the registry for the current request. Phase 2 Pro tools use this
    to gate visibility on Craft permissions per-user.
  - `execute()` return type widened from `array` to `array|\Generator`
    so Phase 2's HTTP transport can forward intermediate yields as
    `notifications/progress`. Phase 1 dispatchers consume the
    Generator eagerly — final yield becomes the wire response.
- `resources/ResourceTemplateInterface` — Phase 1 addition for
  dynamic URI resolution. `getUriTemplate()` / `matches(string $uri)`
  / `read(string $uri)`. Templates are consulted only when a
  concrete-URI lookup misses, so they don't shadow built-in
  resources. `services/Resources` now exposes `matchTemplate()` and
  `getTemplates()`.
- `Schema` DSL adds `examples(array)` — the only JSON Schema fluent
  setter that was missing. Locking the surface ahead of Phase 2.

#### New surfaces

- The bundled agents from `michtio/craftcms-claude-skills` v1.4.2+
  surface as MCP resources under
  `craft-skills://agents/<agent-name>`. The companion package's
  `Skills::agentNames()` / `Skills::agentContent()` helpers are
  optional — the loop is a no-op against an older companion install,
  so cortex still boots cleanly for users who haven't upgraded.
  See `docs/RESOURCES.md` for the live count.
- `tools/system/SearchSkills` — new `search_skills` tool. Keyword
  search across the bundled skills corpus with snippet
  highlighting. Pulls the LLM toward "search the moat content" rather
  than "ask cortex to read every skill in turn."
- `tools/support/InvocationLogger` — emits per-tool-call audit lines
  to the `cortex` log channel. Tool name, user (when available),
  argument fingerprint (secrets redacted), elapsed ms, success /
  error. Wired into `mcp/Server::dispatchTool()`. Phase 2's HTTP
  transport extends the same channel for cross-request audit.

#### Renames (frozen-surface naming pass)

- `diagnostics` → `system_diagnostics`. Pairs with `system_info`; the
  LLM groups them naturally at tool selection. The plain
  `diagnostics` name was generic enough to be ambiguous against
  future audit categories (security, permission, log).
- `audit` → `content_audit`. The tool's modes are content / data-
  integrity audits; the verbose name distinguishes from any future
  audit category.
- Both renames documented in the locked-decisions auto-memory; third-
  party tools registering after Phase 1 ship pin against these names.

#### Audit envelope upgrade

- `tools/workflow/ImportExport` — export envelope bumped to format 2.
  Adds a manifest header (export timestamp, Craft version, Craft
  edition, project schema version, paging cursors) and per-entry
  identity headers (uid, section / type handles, site handle,
  authorIds, parentUid + level for Structure entries, enabledForSite,
  full date stamps) so a Pro `import` mode can do a round-trip
  without a separate manifest file. Phase 1 still only exposes
  `export`; the format-versioned envelope is forward-compatible with
  the Pro import surface.

#### Architecture tests

- `tests/Architecture/ConventionsTest` adds three enforcers:
  - Every class file in `src/` carries at least one `@since` tag.
  - Every private method and property on a concrete class uses the
    underscore prefix (skips magic methods, only walks members
    declared on the class itself).
  - No tool's `execute()` declares `mixed` as the return type —
    PLANNING.md 4.10 calls this out; the dispatcher branches on
    array vs `\Generator` so `mixed` defeats the contract.

#### Registry collision warnings

- `services/Tools::init`, `Prompts::init`, `Resources::init` —
  duplicate-name registrations were silently skipped with a "logging
  is left to a future audit pass" comment. Wired `Craft::warning()`
  log lines (channel `cortex`) so collisions surface to third-party
  plugin authors. First-registration-wins behaviour stays — bundled
  cortex registrations always trump.

#### Auto-config writer

- `console/InstallController::actionApply` —
  `cortex/install/apply --client=<name>` writes the cortex MCP
  server entry directly into the target client's config file.
  Cross-platform path resolution for all seven supported clients
  (macOS / Linux / Windows / WSL). Atomic write (temp file +
  rename), `<file>.bak.<unix-timestamp>` backup. `--force` to
  overwrite an existing cortex entry; `--dry-run` to see the would-
  be diff without writing. Refuses to ghost-create when the parent
  config dir is missing — that's the canonical "client not
  installed" signal. Existing snippet-printer (`cortex/install`)
  unchanged; remains the documented manual fallback. Per-client
  paths verified against current upstream docs.

#### Adversarial review fixes

- `events/RegisterResourcesEvent::$resources` typed widened from
  `ResourceInterface[]` to
  `array<int, ResourceInterface | ResourceTemplateInterface>`. The
  registry already routed both shapes through the event; the strict
  type forced third-party plugins through a TypeError when
  registering a template. Also refreshes the docblock to drop the
  pre-#12 "silently skipped" wording (collisions now warn).
- `tools/workflow/ImportExport` — docblock example block refreshed
  to format 2 (was still showing format 1's slimmer surface).
- `tools/support/InvocationLogger` — docblock now documents the
  Phase 1 "no user attribution" deferral explicitly (was implicit
  before; the security rule lists user attribution as a Phase 2 ship
  requirement).
- `console/InstallController::resolveConfigPath` /
  `buildMergedConfig` — marked `@internal` to signal that they're
  test-touchpoints, not part of the stable public extension API.

#### Audit log shape locked for Phase 2

- New `tools/support/InvocationContext` value object —
  `transport`, `requestId`, `userId`, `clientName`. Locks the audit
  line shape across the Phase 1 stdio / Phase 2 HTTP transport
  upgrade. Phase 2 populates `user` once OAuth resolves; the line
  format does not change.
- `InvocationLogger::formatEntry()` line shape:
  `tool=<name> kind=<...> duration_ms=<int> transport=<stdio|http>
  request_id=<id|-> user=<id|-> client=<name|-> args=<json>`.
  Unknown fields emit `-` (Apache common-log placeholder). Field
  order is part of the locked surface.
- `mcp/Server` captures `clientInfo.name` from the MCP `initialize`
  handshake (we already received it; we just weren't keeping it) and
  stamps it on every tool invocation context.

#### Test surface

- 331 Pest tests passing, 5 conditional skips, ~3s. PHPStan level
  8 clean. Architecture conventions green (7 enforcers).

### Added — Gate 6.5 (Phase 1, DX Pass + Workflow & Audit + Ship-readiness)

Phase 1 shippable. The DX foundation (Schema DSL, attribute-based
annotations, `make:cortex-tool`, extension events) lands alongside three
new Free tools (drafts/revisions, audit, import-export), the runtime
allowlist UI, the install-snippet command, and a full doc-generation
pipeline. Locks the public extension surface ahead of Phase 2.

#### DX foundation (Laravel-MCP-inspired, retrofit-touched every tool)

- `tools/support/Schema` — fluent JSON Schema builder. Static entry
  points (`Schema::string()`, `Schema::object([...])`, `Schema::any()`,
  `Schema::anyOf(...)`, `Schema::oneOf(...)`, `Schema::allOf(...)`,
  `Schema::not(...)`, `Schema::constant(...)`) plus chainable setters
  (`description`, `enum`, `default`, `format`, `pattern`, `min*`,
  `max*`, `items`, `properties`, `additionalProperties`, `required`,
  `uniqueItems`). Property-level `->required()` bubbles into the
  parent object's `required` array. Output is the same JSON Schema
  array MCP clients expect, just nicer to author.
- `attributes/IsReadOnly`, `IsDestructive`, `IsIdempotent`,
  `IsOpenWorld`, `IsStdioOnly`, `Title` — PHP 8 attributes that
  declare MCP `ToolAnnotations` at the class level. Default to `true`;
  pass `false` to advertise an explicit negative
  (`#[IsIdempotent(false)]`). Replaces the static `getAnnotations()`
  / `isStdioOnly()` methods on `ToolInterface` and `AbstractTool` —
  both removed; `tools/support/AttributeReader` reads the new surface
  via reflection at registry-build time.
- **All 28 existing tools retrofitted** to the DSL + attributes
  patterns over four logical commits (one per category: schema,
  content, system, graphql/dev). No behaviour change — same wire
  format, same annotations, same security gates. Pure refactor.
- `events/RegisterToolsEvent`, `RegisterPromptsEvent`,
  `RegisterResourcesEvent` — third-party plugins register their own
  tools / prompts / resources via `Tools::EVENT_REGISTER_TOOLS`,
  `Prompts::EVENT_REGISTER_PROMPTS`, `Resources::EVENT_REGISTER_RESOURCES`.
  First registration wins on name / URI collision; bundled cortex
  registrations always trump shadowing attempts.
- `ddev craft make cortex-tool` — generator hooked into Craft's
  `make` system via `EVENT_REGISTER_GENERATORS`. Prompts for class
  name, namespace, and MCP tool name, then scaffolds a stub against
  `AbstractTool` with the new attribute and Schema DSL patterns.
  Prints the registration snippet on success. `craftcms/generator`
  added as `require-dev` (always present in dev installs via
  `craftcms/cms`).

#### Workflow & Audit — three net-new Free tools (read modes only)

- `drafts_and_revisions` — modes `list_drafts` / `list_revisions` /
  `compare`. List drafts (filterable by section / canonical entry /
  draft creator) and revisions of a canonical entry. Compare returns
  field-level diffs between any two entries (canonical / draft /
  revision in any combination). Pro adds `apply` / `discard` (Phase 2).
- `audit` — modes `relations` / `unused_assets` / `propagation`.
  Reports broken relational references (target missing or
  soft-deleted), unused assets (not referenced by any element field),
  and entries in multi-site sections that don't exist in every
  enabled site. Pure query-builder lookups; subquery filter on
  `unused_assets` keeps it fast on big sites. Pro adds fix modes
  (Phase 2).
- `import_export` — mode `export`. Structured JSON envelope per
  entry (uid + identity + serialised field values via Craft's
  `getSerializedFieldValues()`) suitable for cross-environment sync.
  Format-versioned (`format: 1`); the Pro `import` mode in Phase 2
  consumes the same shape.

**Phase 1 tool count: 31** (was 28). All three new tools `#[IsReadOnly]`
+ `#[IsIdempotent]`.

#### Allowlist UI

- `migrations/Install.php` — creates `{{%cortex_runtime_overrides}}`
  table on plugin install: `pattern` (string, indexed), `note`,
  `expiresAt` (indexed), `createdByUserId` (FK to users with
  `SET NULL` on delete), `dateCreated` / `dateUpdated` /
  `dateDeleted` (soft-delete, indexed) / `uid`. Idempotent —
  `safeUp()` bails when the table already exists; `safeDown()`
  reverses cleanly.
- `db/Table::RUNTIME_OVERRIDES` constant — single source of truth
  for the table name, mirroring Craft's `craft\db\Table` pattern.
- `records/RuntimeOverride` — Yii ActiveRecord with full PHPDoc
  property surface.
- `services/Allowlist` (registered as
  `Plugin::getInstance()->allowlist`) — `getEffective()` returns the
  union of `Settings::$allowedCommands` and active runtime overrides;
  `getActiveOverrides()` / `getAllOverrides()` for the CP UI;
  `add()` / `remove()` for CRUD; `pruneExpired()` for the cleanup
  job. Carbon throughout (services rule — never mix with
  `DateTimeHelper` in the same class).
- `jobs/PruneExpiredOverrides` — queue job that hard-deletes expired
  non-deleted overrides. Designed to be scheduled via Craft gc.
- `controllers/SettingsController` — `actionAddOverride` /
  `actionRemoveOverride`. `requireAdmin(requireAdminChanges: true)`
  on both — allowlist mutations are a security boundary.
- `templates/settings.twig` — plain-Twig CP form (Settings → Cortex
  in the CP). Two sections: Defaults (saved via Craft's standard
  `plugins/save-plugin-settings`, syncs through project config) and
  Runtime overrides (custom controller actions, DB-backed). Defaults
  use Craft's `_includes/forms` macros; the override list renders
  with cancel/expiry status; an inline form adds new patterns with
  optional note + custom TTL.
- `Settings::$runtimeOverrideTtl` — new field, default 7 days
  (`604800`). Default applied to new overrides when none is supplied.
- `tools/dev/CraftCommand` — switched its allowlist read to use
  `Plugin::getInstance()->allowlist->getEffective()` so runtime
  overrides take effect immediately.

#### Phase 1 ship-readiness

- `console/controllers/InstallController` —
  `ddev craft cortex/install` prints copy-paste MCP client config
  snippets for **seven** known clients: Claude Desktop, Claude Code,
  Cursor, Continue.dev, Cline, Zed, Windsurf. Auto-detects DDEV via
  `IS_DDEV_PROJECT` / `DDEV_PROJECT`; emits the `docker exec` form
  when inside a container. `--client=<name>` / `--all` flags. Read-
  only — never writes config files. Phase 3 adds the GUI auto-detect
  wizard.
- `console/controllers/DocsController` — `ddev craft cortex/docs/all`
  generates `docs/TOOLS.md`, `docs/PROMPTS.md`, `docs/RESOURCES.md`
  from the live registries. Each action also runs solo
  (`cortex/docs/tools` / `prompts` / `resources`). `--out=<dir>`
  overrides the output directory (used by the test suite). Output is
  committed to the repo so consumers get a reference without booting
  cortex.
- `config/cortex.php` reference template — heavily commented config
  file documenting every setting (`allowedCommands`, `execEnabled`,
  `execDryRunDefault`, `runtimeOverrideTtl`) with environment-
  specific override examples (production / staging).
- **Architecture tests** in `tests/Architecture/ConventionsTest.php`:
  - No `eval()` / `exec()` / `shell_exec()` / `proc_open()` /
    `passthru()` / `popen()` calls anywhere in `src/` (PHP token
    parser, not regex — false-positive-proof against docblock /
    string occurrences).
  - No `declare(strict_types=1)` in plugin source.
  - Every concrete tool class implements `ToolInterface`.
  - Every class file under `src/` has a section header (`====`
    marker) and an `@author Craftpulse` PHPDoc tag.
- **README rewritten** for Phase 1 ship — install, configure, what
  the AI gets (with cross-links to `docs/TOOLS.md` etc.), extension
  events, generator, develop, roadmap. Drops the per-gate roadmap
  table; the gates are documentation residue at this point.

#### Test surface

- **266 Pest tests** (was 177 at Gate 6 — added 89 new), 5660
  assertions, ~2.4s. PHPStan level 8 clean. Architecture conventions
  green. ECS still blocked upstream by `craftcms/ecs dev-main`'s
  symplify pin (documented in Gate 4). Manual smoke through Claude
  Desktop verifies tools/list, descriptions, prompts, and a
  representative slice of resources.

### Added — Gate 6 (Phase 1, Skills as Prompts + Resources)
- 8 MCP prompts mapping the bundled
  `michtio/craftcms-claude-skills` package one-to-one (per
  PLANNING.md 4.6):
  - `craftcms_extending` — `craftcms` skill (~13,000 lines).
    Reverse-engineered Craft internals: 15-step element save
    lifecycle, four-layer authorization model, dual-layer
    session architecture.
  - `craftcms_templates` — `craft-site` skill (~8,800 lines).
    Front-end framework + 22 plugin integration guides.
  - `craftcms_cp_javascript` — `craft-garnish` skill
    (~2,200 lines). The only written documentation for
    Craft's internal JS toolkit.
  - `craftcms_content_modeling` — `craft-content-modeling`.
  - `craftcms_php_standards` — `craft-php-guidelines`.
  - `craftcms_twig_standards` — `craft-twig-guidelines`.
  - `craftcms_ddev` — `ddev`.
  - `craftcms_setup` — `craft-project-setup`.
- 72 MCP resources covering both the SKILL.md routers and every
  reference deep-dive across the 8 skills. URI scheme:
  `craft-skills://<skill>[/<reference>]`. Bundled prompts use
  the `craftcms_*` namespace; bundled resources use the
  `craft-skills://` URI scheme — both reserved so the Pro
  custom-skills feature (Gate 8.5) can use `custom_*` and
  `custom-skills://` without collision.
- **Why prompts as the primary delivery:** MCP clients reliably
  invoke prompts as part of task execution, but rarely fetch
  resources spontaneously. The prompt returns SKILL.md inline
  so the LLM has the routing knowledge without having to know
  to ask. Resources serve as the deep-dive sub-fetch the LLM
  does on demand once a prompt routes it.
- New registry surface mirrors the tool registry shape:
  - `prompts/PromptInterface` + `AbstractPrompt` + concrete
    `SkillPrompt`.
  - `resources/ResourceInterface` + `AbstractResource` +
    concrete `SkillResource`.
  - `services/Prompts` (Yii Component, name-keyed lookup,
    `asListPayload()`).
  - `services/Resources` (Yii Component, URI-keyed lookup,
    `asListPayload()`).
- `mcp/Server` replaces the empty `prompts/list` and
  `resources/list` stubs and adds `prompts/get` +
  `resources/read` handlers. Returns JSON-RPC `-32602` on
  unknown name/URI; `-32603` on unexpected render failures.
- Skills consumed via the
  `Michtio\CraftCmsClaudeSkills\Skills` static helper from the
  companion composer package — read on demand, no double-cache.
  Path-traversal validation lives in the helper, not duplicated
  in the resource layer.
- **Cumulative Phase 1: 28 tools + 8 prompts + 72 resources =
  108 MCP entries.** 177 Pest tests passing (27 new, 5 skipped),
  5317 assertions, ~2.7s. PHPStan level 8 clean. Smoke-verified
  through stdio: every prompt and a representative sample of
  resources render correct content.

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
