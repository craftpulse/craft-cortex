# Cortex plugin for Craft CMS 5.x

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server for Craft CMS 5. It connects your AI assistant — Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf — directly to your Craft project so the assistant can answer questions about your content model, run safe inspections, and learn Craft conventions from bundled expert skills.

The free tier ships **32 tools**, **8 prompts**, and **77 resources** over a local stdio transport. The upcoming Pro tier (Phase 2) adds an authenticated HTTP transport with content-write capabilities for non-developer operators.

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

After installation, run the install helper from your project root:

```bash
ddev craft cortex/install --client=claude-desktop
```

This prints a copy-paste config snippet for the client you named. Cortex supports seven clients today — Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf. Drop `--client=...` to print snippets for all of them.

To skip the copy-paste step entirely, use the auto-config writer:

```bash
ddev craft cortex/install/apply --client=claude-desktop --dry-run
ddev craft cortex/install/apply --client=claude-desktop
```

The `--dry-run` flag prints the would-be diff so you can preview before writing. Without it, Cortex writes the entry atomically (temp file + rename) and backs the original file up to `<file>.bak.<unix-timestamp>`. Re-runs are idempotent — pass `--force` if you want to overwrite an existing Cortex entry.

Full per-client instructions, troubleshooting, and the manual snippet flow are in **[`docs/INSTALL.md`](docs/INSTALL.md)**.

## What the AI gets

After Cortex is connected, your assistant can:

- **Inspect your content model.** 32 read-only tools cover sections, entry types, fields, field types, category groups, tag groups, sites, image transforms, volumes, filesystems, plugins, routes, system info, permissions, GraphQL schemas, the database schema, and more. List/get/count modes collapse behind a single `handle` argument so the LLM picks the right tool faster and uses fewer tokens.
- **Read your content safely.** `entries`, `assets`, `categories`, `tags`, `globals` expose the full element-query surface (filters, eager loading, pagination, count mode). Relational fields stub to `{type: "relation", loaded: false}` by default — pass `with: [...]` to materialise them, so the LLM never accidentally triggers an N+1 walk.
- **Run safe dev actions.** `clear_caches`, `resave`, `craft_command` (allowlisted), `craft_exec` (six security gates including dry-run-default and stdio-only), and `import_export` (export-only in Free; round-trippable JSON envelope).
- **Search 27,000+ lines of Craft expertise.** `search_skills` does keyword search across the bundled `michtio/craftcms-claude-skills` corpus — eight skills covering Craft internals, templating, content modelling, PHP/Twig standards, DDEV, project setup, and Garnish. The matching skill content is also exposed as MCP **prompts** so the LLM picks them up automatically when relevant. **Resources** expose the per-skill reference deep-dives and the five bundled Claude Code agents.

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

- `craft_exec` runs through Craft's own `ExecController` with six layered gates: dry-run-default, structured output, secret redaction, destructive-op guard, hard HTTP rejection, and `destructiveHint: true` annotation.
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

The `initialize` handshake should succeed (`cortex 5.0.0`, protocol `2025-06-18`), and every tool / prompt / resource shows up in the lists.

## Roadmap

- **Phase 1 — Free.** 32 tools, 8 prompts, 77 resources, stdio transport, allowlist UI, install command (snippet printer + auto-config writer), docs generators, extension events. Shipping.
- **Phase 2 — Pro.** Streamable HTTP transport with OAuth 2.1, per-user permission filtering, DB-backed audit log, 7 net-new write tools, mode unlocks on Free tools (`drafts_and_revisions` apply/discard, `content_audit` fix modes, `import_export` import), Pro-exclusive custom-skills element type, minimal CP UI for tokens / activity / connection.
- **Phase 3 — Polish.** Install wizard auto-detecting installed clients, formal real-LLM E2E harness, vectorised docs search, third-party tool registration battle-tested across the ecosystem, skill remote-fetch.

## Support

- Issues: [github.com/craftpulse/craft-cortex/issues](https://github.com/craftpulse/craft-cortex/issues)
- Email: [support@craftpulse.com](mailto:support@craftpulse.com)

## License

Free tier: MIT. Pro tier: proprietary, license-gated through the Craft Plugin Store (Phase 2).

## Credits

- [Model Context Protocol](https://modelcontextprotocol.io/) — Anthropic et al.
- [Craft CMS](https://craftcms.com/) — Pixel & Tonic
- [`craftcms/generator`](https://github.com/craftcms/generator) — the make-system extensibility Cortex hooks into for `cortex-tool`
- [`michtio/craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills) — the bundled skills package Cortex serves as prompts and resources

Brought to you by [CraftPulse](https://craft-pulse.com/)
