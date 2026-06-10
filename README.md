# Cortex plugin for Craft CMS 5.x

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server that gives your AI assistant a direct line into your Craft 5 project. Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf — they all connect over a single stdio transport and immediately have read access to your content model, your content, and a bundled ~30,000-line corpus of authored Craft expertise. Stop pasting Craft docs into your chat window.

The free tier ships **33 tools**, **10 prompts**, and **98 resources**. The upcoming Pro tier (Phase 2) adds an authenticated HTTP transport with content-write capabilities for non-developer operators — agencies hand it to their clients without handing over shell access.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- An MCP-capable client (Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, or Windsurf)

## Installation

To install Cortex, follow these steps:

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require craftpulse/craft-cortex

3. Install the plugin via `./craft plugin/install cortex` from the CLI, or in the Control Panel go to **Settings → Plugins** and click the **Install** button for Cortex.

You can also install Cortex via the **Plugin Store** in the Craft Control Panel — search for *Cortex* and click **Install**.

If you develop with [DDEV](https://ddev.com/), run the same commands through the container: `ddev composer require craftpulse/craft-cortex` then `ddev craft plugin/install cortex`. Cortex is DDEV-aware and emits the correct `docker exec` invocation form when generating client config.

Cortex works on Craft 5.x.

## Connect your MCP client

Cortex ships four console actions for hooking up your MCP client, in increasing order of magic:

```bash
# 1. Print copy-paste snippets for all seven supported clients
ddev craft cortex/install

# 2. Write Cortex config to one specific client's config file
ddev craft cortex/install/apply --client=claude-desktop --dry-run
ddev craft cortex/install/apply --client=claude-desktop

# 3. Scan your host for installed clients and print a status table
php craft cortex/install/detect

# 4. Scan, then prompt to apply Cortex config for each detected client
php craft cortex/install/auto
```

The `apply` action writes atomically (temp file + rename), backs the original up to `<file>.bak.<unix-timestamp>`, and is idempotent — re-runs are no-ops unless you pass `--force`. The `detect` and `auto` actions scan `/Applications`, `PATH`, and `%LOCALAPPDATA%\Programs` for installed clients, plus the per-client config directories for "configured" signals.

**`detect` and `auto` refuse to run from inside a container** (DDEV, Docker, Lando, Sail, Podman, LXC, Kubernetes) because the container can't see your host's `/Applications`, `~/.cursor`, etc. — detection from inside would return false negatives. Run them from your host's PHP, or stick with the snippet form (`ddev craft cortex/install`) which works fine inside DDEV.

Cortex supports seven clients today — Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf. Full per-client instructions, troubleshooting, and per-platform config paths are in **[`docs/INSTALL.md`](docs/INSTALL.md)**.

## What makes Cortex different

**The bundled skills are the moat.** Cortex ships [`craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills) — ten skills, ~30,000 lines of authored Craft expertise — as MCP prompts that your assistant picks up automatically when the conversation touches Craft architecture, content modelling, Twig templating, PHP standards, DDEV, Garnish, or hosting on Servd / Craft Cloud. This isn't a vectorised docs lookup. It's reverse-engineered internals — the 15-step element save lifecycle, the four-layer authorization model, the dual-layer session architecture — that don't exist in the public docs.

**Lean tool surface, parameter-rich.** 33 thick tools cover broader ground than a 50-tool catalogue would by collapsing `list_*`/`get_*` pairs into single tools with optional `handle`/`id` parameters, exposing a `count: true` mode on every list-capable tool, and offering the full element-query surface (`relatedTo`, `with: [...]` eager loading, structure params, site filter) on the content tools. Smaller `tools/list` = faster LLM tool selection + fewer tokens consumed by the system prompt every turn.

**Defense in depth, not naive blocklisting.** `craft_exec` runs PHP `eval` behind six layered security gates — dry-run-by-default, structured output, secret redaction, destructive-op guard, hard HTTP-transport rejection (stdio only), and the MCP `destructiveHint` annotation so spec-aware clients warn before invoking. Nothing else in the source uses `eval` / `shell_exec` / `proc_open` / `passthru` / `popen` / backticks, enforced by a tokenising architecture test. No raw SQL tool. No PII in Free. The threat model isn't sandbox escape — it's an LLM choosing destructive operations because it misread context, and the gates are designed around that.

**MCP 2025-11-25 spec compliance.** Cortex advertises the current 2025-11-25 revision and negotiates 2025-06-18 for older clients. Five tools declare `outputSchema`; the dispatcher dual-emits `structuredContent` alongside the legacy text block. PHP 8 attributes (`#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`, `#[IsStdioOnly]`, `#[Title]`) carry the MCP `ToolAnnotations`. JSON-RPC 2.0 dispatcher uses correct error codes (-32600 / -32601 / -32602 / -32603) and returns tool-execution errors as `isError: true` envelopes (not protocol errors) so the LLM can self-correct.

## What the AI gets

After Cortex is connected, your assistant can:

- **Get oriented in one call.** `get_initial_context` returns Craft version + edition + environment, your primary site, a thin index of sites / sections / element types, the bundled `craftcms_*` skill prompts, the `craft_exec` security posture, and the effective command allowlist — all in one tool call. Fresh agents stop wasting four turns on orientation tools.
- **Inspect your content model.** Read-only tools cover sections, entry types, fields, field types, category groups, tag groups, sites, image transforms, volumes, filesystems, plugins, routes, system info, permissions, GraphQL schemas, the database schema, and more. List / get / count modes collapse behind a single `handle` argument so the LLM picks the right tool faster and uses fewer tokens.
- **Read your content safely.** `entries`, `assets`, `categories`, `tags`, `globals` expose the full element-query surface (filters, eager loading, pagination, count mode). Relational fields stub to `{type: "relation", loaded: false}` by default — pass `with: [...]` to materialise them, so the LLM never accidentally triggers an N+1 walk.
- **Run safe dev actions.** `clear_caches`, `resave`, `craft_command` (allowlisted), `craft_exec` (six security gates including dry-run-default and stdio-only).
- **Audit workflow state.** `drafts_and_revisions` (list and compare drafts / revisions), `content_audit` (broken relations / unused assets / propagation gaps), `import_export` (structured-JSON export in Free; round-trippable import in Pro). Fix-mode unlocks in Pro.
- **Search 30,000+ lines of Craft expertise.** `search_skills` does keyword search across the bundled `michtio/craftcms-claude-skills` corpus — ten skills covering Craft internals, templating, content modelling, PHP/Twig standards, DDEV, project setup, Garnish, and Servd / Craft Cloud hosting. The matching skill content is also exposed as MCP **prompts** so the LLM picks them up automatically when relevant. **Resources** expose the per-skill reference deep-dives and the six bundled Claude Code agents.

For the full reference (per-tool argument schemas, annotations, and prompt / resource catalogue), see:

- [`docs/TOOLS.md`](docs/TOOLS.md) — auto-generated tool reference.
- [`docs/PROMPTS.md`](docs/PROMPTS.md) — auto-generated prompt reference.
- [`docs/RESOURCES.md`](docs/RESOURCES.md) — auto-generated resource reference.

All three regenerate from the live registries via `ddev craft cortex/docs/all`.

## Configuration

The plugin ships with sensible defaults. For environment-specific overrides, copy `vendor/craftpulse/craft-cortex/src/config/cortex.php` to your project's `config/cortex.php` and edit there. Per-environment blocks (`'production'`, `'staging'`) work the same way as Craft's other config files.

Cortex's CP settings page (**Settings → Cortex**) provides a live editor for:

- the `craft_command` allowlist (writes to project config so it syncs across environments)
- the `craft_exec` toggles (`execEnabled`, `execDryRunDefault`)
- runtime allowlist overrides (admin-only, auto-expiring patterns that layer on top of the project-config defaults)

Full configuration reference: **[`docs/CONFIGURATION.md`](docs/CONFIGURATION.md)**.

## Security

Cortex's security model treats the transport as the boundary. The Phase 1 stdio transport is trusted (local user, single process). The Phase 2 HTTP transport will authenticate every request against a Craft user before dispatching.

Highlights:

- `craft_exec` runs PHP `eval` behind six layered security gates (same approach as Craft's own `ExecController`, not a wrapper around it): dry-run-default, structured output, secret redaction, destructive-op guard, hard HTTP rejection, and `destructiveHint: true` annotation.
- `craft_command` enforces an allowlist at the tool layer, layered as project-config defaults + admin-issued runtime overrides + optional `config/cortex.php` overrides.
- No `eval` / `shell_exec` / `proc_open` / `passthru` / `popen` / backticks anywhere in the source — verified by the architecture test suite.
- Every tool invocation emits one structured audit-log line (`cortex` channel) with secret-redacted arguments. The line shape is locked across Phase 1 and Phase 2 so log consumers stay stable through the transport upgrade.

Full security reference: **[`docs/SECURITY.md`](docs/SECURITY.md)**.

## Extending

Third-party plugins can register their own tools, prompts, and resources via class-level events. Cortex enforces architectural contracts (interface implementation, transport gating, dispatch shape) — third-party authors are responsible for behavioural correctness, the same trust model Craft itself uses for plugin extensibility.

```php
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\services\Tools;
use yii\base\Event;

Event::on(
    Tools::class,
    Tools::EVENT_REGISTER_TOOLS,
    function (RegisterToolsEvent $event): void {
        $event->tools[] = new MyPlugin\Tools\MyCustomTool();
    },
);
```

To scaffold a new tool against Cortex's `AbstractTool` parent with the right attributes and Schema DSL stub:

```bash
ddev craft make cortex-tool
```

Full extension guide (events, interfaces, attributes, Schema DSL, naming conventions, generator usage): **[`docs/EXTENDING.md`](docs/EXTENDING.md)**.

## Develop

This plugin is developed against the test environment at `~/dev/craft-plugin-playground/cms_v5/`, where it's symlinked into `vendor/craftpulse/craft-cortex` via a Composer path repository.

### Run the tests

```bash
ddev exec --dir=/var/www/html/cms vendor/bin/pest \
  --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist
```

Pest covers the registry, every tool, every prompt, every resource, the JSON-RPC dispatcher, the extension events, and seven architectural conventions (no `eval` / shell-exec family / `declare(strict_types=1)`, every tool implements `ToolInterface`, every class has a section header + `@author Craftpulse` + `@since`, every private method/property uses the underscore prefix, no tool's `execute()` declares `mixed`).

### Static analysis

```bash
ddev exec --dir=/var/www/html/cms vendor/bin/phpstan analyse \
  --memory-limit=1G -c vendor/craftpulse/craft-cortex/phpstan.neon
```

PHPStan level 8 — clean.

### Smoke through the inspector

The [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector) is the visual harness for protocol-level testing:

```bash
# In your Craft project:
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector UI, point at:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-<project>-web php /var/www/html/craft cortex/serve`

The `initialize` handshake should succeed (`cortex 5.0.0`, protocol `2025-11-25` — or `2025-06-18` if your client requests it), and every tool / prompt / resource shows up in the lists.

## Roadmap

- **Phase 1 — Free. Shipping.** 33 tools, 10 prompts, 98 resources, stdio transport, allowlist UI, full install toolkit (snippet printer + per-client auto-apply + auto-detect + interactive auto-apply across detected clients), MCP `outputSchema` / `structuredContent` dual-emit per MCP 2025-11-25 (negotiating 2025-06-18), docs generators, extension events for third-party tools / prompts / resources, full Pest + PHPStan level 8 + ECS green.
- **Phase 2 — Pro.** Streamable HTTP transport with OAuth 2.1, per-user permission filtering on `tools/list` (architecture locked: static `shouldRegister()` + instance `filterFor($user)` + instance `inputSchemaFor($user)`), DB-backed audit log, 7 net-new write tools (`entry`, `category`, `tag`, `address`, `global_set`, `users`, `bulk_entries`), mode unlocks on Free tools (`drafts_and_revisions apply/discard`, `content_audit` fix modes, `import_export` import, `system_diagnostics manage_queue`), Pro-exclusive custom-skills element type, minimal CP UI for tokens / activity / connection.
- **Phase 3 — Polish.** CP-side install wizard (GUI affordance on top of the Phase 1 console actions, not a re-implementation), formal real-LLM E2E harness, vectorised docs search complementary to the authored skills corpus, third-party tool registration battle-tested across the ecosystem, skill remote-fetch, OAuth Client ID Metadata Documents (CIMD — the registration mechanism the MCP spec now recommends over DCR), and the planned migration to the stateless MCP 2026-07-28 revision.

## Support

- Issues: [github.com/craftpulse/craft-cortex/issues](https://github.com/craftpulse/craft-cortex/issues)
- Email: [support@craftpulse.com](mailto:support@craftpulse.com)

## License

Cortex is a proprietary, commercially-licensed Craft plugin distributed under the [Craft License](https://craftcms.github.io/license/) through the Craft Plugin Store. The Free edition is free of charge; the Pro edition is paid and license-gated through the Plugin Store. (The bundled [`michtio/craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills) corpus package is separately MIT-licensed.)

## Credits

- [Model Context Protocol](https://modelcontextprotocol.io/) — Anthropic et al.
- [Craft CMS](https://craftcms.com/) — Pixel & Tonic
- [`craftcms/generator`](https://github.com/craftcms/generator) — the make-system extensibility Cortex hooks into for `cortex-tool`
- [`michtio/craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills) — the bundled skills package Cortex serves as prompts and resources

Brought to you by [CraftPulse](https://craft-pulse.com/)
