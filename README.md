# Cortex — MCP server for Craft CMS 5

> Pre-Phase-1 development. Not ready for use yet. See [PLANNING.md section 4](https://github.com/craftpulse/craft-cortex) for the roadmap.

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server for [Craft CMS 5](https://craftcms.com/). It exposes Craft internals (sections, entry types, fields, content, generators, project config, more) to AI agents over a dual transport:

- **stdio** for local development (Claude Code, Cursor, Claude Desktop) — Free tier
- **Streamable HTTP** for content operators — Pro tier (Phase 2)

Skills authored from years of Craft work are bundled as MCP prompts and resources, so the AI doesn't just have tools — it has expertise.

## Status

| Gate | What it means | Status |
|---|---|---|
| Gate 1 | Empty server responds to `initialize` and `tools/list` over stdio | Done |
| Gate 2 | 10 schema tools, integration tests pass | Done (52 Pest tests, 1040 assertions) |
| Gate 3 | 15 tools (entries, assets, categories, tags, globals); no N+1 | Done (77 Pest tests, 1446 assertions) |
| Gate 4 | 23 tools; PHPStan 8 clean; ECS clean | Done (107 Pest tests, 3041 assertions, PHPStan 8 clean — ECS blocked upstream) |
| Gate 5 | 28 tools; `craft_exec` security gates verified | Done (150 Pest tests, 3456 assertions, PHPStan 8 clean) |
| Gate 6 | Skills as MCP prompts; real LLM client validates routing | Phase 1 |
| Gate 7 | Full Pest suite; two real LLM clients validate end-to-end | Phase 1 |
| Gate 8 | Streamable HTTP transport + permission gating | Phase 2 |
| Gate 9 | 11 Pro tools; license-gated | Phase 2 |
| Gate 10 | CP MCP UI usable by a non-dev tester | Phase 2 |
| Gate 11 | Pro ready for Plugin Store | Phase 2 |

## Local development

This plugin is developed against the test environment at
`~/dev/craft-plugin-playground/cms_v5/`, where it gets symlinked into
`vendor/craftpulse/craft-cortex` via a Composer path repository.

Test the stdio transport using the [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector):

```bash
# In the playground:
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector UI:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-plugin-playground-v5-web php /var/www/html/cms/craft cortex/serve`

(`craft` is a PHP script, not on `$PATH` and not chmod +x, so it has to be invoked via `php` with its full path. The path matches the playground's `composer_root: cms` in `.ddev/config.yaml`.)

Click **Connect**. You should see the `initialize` handshake succeed (`cortex 0.1.0`, protocol `2025-06-18`).

## Tools available now (Gates 2–5 — 28 tools)

### Schema & Structure (Gate 2)

| Tool | Modes |
|---|---|
| `sections` | list / get-by-handle / count |
| `entry_types` | list (summary) / get-by-handle (full field layout: tabs, fields, conditions, UI elements) / count |
| `fields` | list / get-by-handle / `mode: "usage"` (which entry types reference this field) / count |
| `field_types` | every registered field type class — built-in + plugin |
| `category_groups` | list / get-by-handle / count |
| `tag_groups` | list / get-by-handle / count |
| `volumes_and_filesystems` | combined map of volumes with their filesystems, plus orphan filesystems |
| `sites` | list (with groups + primary site) / get-by-handle / count |
| `image_transforms` | list / get-by-handle / count |
| `element_types` | every registered element type class — 8 core + plugin — with capability flags |

### Content Reading (Gate 3)

| Tool | Modes |
|---|---|
| `entries` | full query surface: section/type/status/author/relatedTo/search filters, structure params (level/hasDescendants/leaves/descendantOf/ancestorOf/siblingOf), pagination (limit hard-cap 1000), eager loading via `with: [...]`, single-by-id, count |
| `assets` | same query surface plus `mode: "folders"` for the folder tree of a volume |
| `categories` | list/get/count with group filter and structure params |
| `tags` | list/get/count with group filter |
| `globals` | read global set values by handle, optionally site-localised |

### System & Diagnostics (Gate 4)

| Tool | Modes |
|---|---|
| `system_info` | Craft + PHP + DB + sites + license snapshot |
| `config` | `mode: general / custom / db / email / system_messages`, fully secrets-redacted |
| `plugins` | every installed plugin with handle, version, edition, license status, enabled flag |
| `routes` | combined config-file + project-config + section + category-group URI map |
| `diagnostics` | `type: logs / last_error / deprecations / queue / project_config_diff` |
| `database_schema` | tables / columns / indexes / FKs (no data queries); `mode: "list"`, `tables: [...]` filter |
| `extensibility` | `mode: events / twig / utilities / commands` (or all four at once) |
| `permissions_and_groups` | full permissions tree + user groups (no PII) |

### GraphQL (Gate 5)

| Tool | Modes |
|---|---|
| `graphql` | `list_schemas` / `get_sdl` (by `name`, defaults to public schema) / `list_tokens` (metadata only — never the access-token value; SHA-256 fingerprint for correlation) |

### Dev Actions (Gate 5)

| Tool | Notes |
|---|---|
| `clear_caches` | `mode: list` returns registered cache keys; `mode: all` (default) clears everything; or pass a single key (`data`, `asset`, `compiled-templates`, …) |
| `resave` | Wrapper for `resave/*`. Required `type` ∈ entries / assets / categories / tags / users / addresses. Optional filters (section/group/volume/status/limit), field rewrite (`set`/`to`), `queue: true` for background dispatch. Output captured cleanly. |
| `craft_command` | Allowlisted runner for the rest of the Craft / Yii console surface. `mode: list` exposes patterns, `mode: run` dispatches. Allowlist sourced from `Settings::$allowedCommands` (project-config + `config/cortex.php` overrides). |
| `craft_exec` | Wraps Craft's `ExecController`. **stdio only.** Dry-run by default — `confirm: true` to evaluate; destructive patterns (`delete*`, `drop*`, `truncate*`, `Elements::deleteElement`, `migrate/down`) additionally require `dangerous: true`. Result JSON-serialised, secrets redacted, errors typed (`parse_error` / `runtime_error`) with class / file / line / trace. `destructiveHint: true` annotation. |

### N+1 prevention

Every relational field on element output is **stubbed** by default:

```json
{ "fields": { "image": { "type": "relation", "loaded": false, "note": "Pass `with: [\"<fieldHandle>\"]` to eager-load." } } }
```

To materialise the relation, the caller passes `with: ["image"]` — that triggers Craft's eager-loading on the parent query, so the field value comes back as a list of element summaries without per-entry queries.

Design follows PLANNING.md section 4.13: list/get pairs collapsed behind an optional `handle` argument, `count: true` mode where useful, structured JSON output, tool-level errors returned as `isError: true` envelopes (versus protocol errors returned as JSON-RPC errors).

## Running the tests

Pest tests live in `tests/`. They run from the playground (where Craft is bootstrapped) but assert on cortex internals.

```bash
# From the playground (~/dev/craft-plugin-playground/cms_v5/):
ddev exec --dir=/var/www/html/cms vendor/bin/pest \
  --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist
```

Tests cover the registry, every schema tool (count / list / handle / mode / error paths), and the JSON-RPC dispatcher (initialize, notifications, tools/list, tools/call envelopes, every protocol error code). Suite runs in well under a second and asserts on shape (keys + types), not on specific data — so it works against any playground state.

## Static analysis

```bash
ddev exec --dir=/var/www/html/cms vendor/bin/phpstan analyse \
  --memory-limit=1G -c vendor/craftpulse/craft-cortex/phpstan.neon
```

PHPStan level 8, clean. Three suppressions in `phpstan.neon` cover (1) the `array<string,mixed>` argument shape that's MCP's wire contract, (2) Yii element-query generics in private builder helpers, (3) Plugin's auto-discovered Yii component config schema.

## License

Free tier: MIT. Pro/Commerce tiers: proprietary, license-gated through the Craft Plugin Store. Editions and tooling land in Phase 2.

## Credits

- [Model Context Protocol](https://modelcontextprotocol.io/) — Anthropic et al.
- [Craft CMS](https://craftcms.com/) — Pixel & Tonic
