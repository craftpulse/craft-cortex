# Changelog

All notable changes to Cortex are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning per
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Initial release

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server for Craft CMS 5. It connects MCP-capable AI assistants — Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf — to your Craft project so they can introspect your content model, read your content safely, run guarded dev actions, and learn Craft conventions from a bundled expert-skill corpus.

This release ships the free tier: a stdio-transport MCP server with **32 tools**, **8 prompts**, and **77 resources**. The Pro tier (HTTP transport, content-write capabilities, Craft permission gating, audit-log UI) is on the Phase 2 roadmap.

### Added

#### Tool catalogue (32 tools)

- **Schema & structure (10):** `sections`, `entry_types`, `fields`, `field_types`, `category_groups`, `tag_groups`, `volumes_and_filesystems`, `sites`, `image_transforms`, `element_types`. Each collapses list / get / count behind a single optional `handle` argument.
- **Content reading (5):** `entries`, `assets`, `categories`, `tags`, `globals`. Full element-query surface — section / type / status / author / `relatedTo` filters, eager loading via `with: [...]`, structure params, pagination (default 100, max 1000), site filter, count mode, single-id shortcut. Relational fields stub by default (`{type: "relation", loaded: false}`) so the LLM never accidentally triggers an N+1 walk.
- **System & diagnostics (9):** `system_info`, `config`, `plugins`, `routes`, `system_diagnostics`, `database_schema`, `extensibility`, `permissions_and_groups`, `search_skills`. `config` and `system_diagnostics` are multi-mode introspection tools; `search_skills` does keyword search across the bundled skills corpus.
- **GraphQL (1):** `graphql` — list schemas, get SDL, list tokens (token values are never returned; a SHA-256 fingerprint surfaces for correlation only).
- **Dev actions (4):** `clear_caches`, `resave`, `craft_command` (allowlist-gated), `craft_exec` (six security gates, stdio-only).
- **Workflow & audit (3):** `drafts_and_revisions` (list / compare drafts and revisions), `content_audit` (relations / unused-assets / propagation audits), `import_export` (structured-JSON export with format-versioned envelope; Pro adds the round-trip import).

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
- `php craft cortex/install/auto` runs detection plus per-client confirm-and-apply in one pass — the "I installed Cortex, now wire it up everywhere" flow. Honours `--dry-run` and `--force`. Both `detect` and `auto` refuse to run from inside DDEV because the container can't see the host filesystem; the manual snippet form (`ddev craft cortex/install`) is the documented fallback inside DDEV.

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
