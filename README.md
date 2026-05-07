# Cortex — MCP server for Craft CMS 5

Cortex is a [Model Context Protocol](https://modelcontextprotocol.io/) server for [Craft CMS 5](https://craftcms.com/). It exposes Craft internals — sections, entry types, fields, content, drafts, audits, GraphQL, project config, and more — to AI agents over a dual transport:

- **stdio** for local development (Claude Code, Cursor, Claude Desktop, others) — Free
- **Streamable HTTP** for content operators — Pro (Phase 2)

Skills authored from years of Craft work ship as MCP prompts and resources, so the AI doesn't just have tools — it has expertise.

## Highlights

- **32 tools, lean by design.** List/get pairs collapsed behind a single optional `handle`. Mode-driven write-side. Same coverage as competitors with half the tool count, faster LLM tool selection, fewer tokens consumed by `tools/list`.
- **Skills moat.** Eight bundled skills from `michtio/craftcms-claude-skills` ship as MCP prompts (and 72 resources for deep-dives). LLMs route through the prompts; cortex serves the matching skill content inline.
- **Schema-DSL authored tool inputs.** Every tool's argument schema is built with a fluent JSON Schema DSL — `Schema::object([...])->required()`. Same wire format MCP clients expect, friendlier authoring.
- **Attribute-based annotations.** PHP 8 attributes — `#[IsReadOnly]`, `#[IsDestructive]`, `#[IsIdempotent]`, `#[IsOpenWorld]`, `#[IsStdioOnly]`, `#[Title]` — replace boilerplate static methods. Surface metadata at the class declaration, not buried in method bodies.
- **Extension events.** Third-party plugins register their own tools, prompts, and resources via `EVENT_REGISTER_TOOLS` / `EVENT_REGISTER_PROMPTS` / `EVENT_REGISTER_RESOURCES`. Cortex enforces architectural contracts (interface, transport gating, dispatch) — third-party authors are responsible for behavioural correctness, the same trust model Craft itself uses for plugins.
- **Security gates on `craft_exec`.** Six layered gates per [PLANNING.md 4.9](https://github.com/craftpulse/craft-cortex): dry-run default, structured output, secret redaction, destructive-op guard, hard HTTP rejection (stdio-only), `destructiveHint: true` annotation. We use Craft's own `ExecController` rather than a homebrew eval blocklist.

## Install

```bash
composer require craftpulse/craft-cortex
ddev craft plugin/install cortex
```

Then run `ddev craft cortex/install` to print copy-paste config snippets for every supported MCP client (Claude Desktop, Claude Code, Cursor, Continue.dev, Cline, Zed, Windsurf):

```bash
ddev craft cortex/install              # all clients
ddev craft cortex/install --client=claude-desktop
ddev craft cortex/install --ddev=0     # non-DDEV form
```

For Claude Desktop on macOS, the snippet looks like:

```json
{
  "mcpServers": {
    "cortex": {
      "command": "docker",
      "args": ["exec", "-i", "ddev-myproject-web", "php", "/var/www/html/craft", "cortex/serve"]
    }
  }
}
```

Reload your client. `cortex` shows up alongside whatever else you've registered.

## What the AI gets

| Category | Count | Tools |
|---|---|---|
| Schema & Structure | 10 | `sections`, `entry_types`, `fields`, `field_types`, `category_groups`, `tag_groups`, `volumes_and_filesystems`, `sites`, `image_transforms`, `element_types` |
| Content Reading | 5 | `entries`, `assets`, `categories`, `tags`, `globals` |
| System & Diagnostics | 9 | `system_info`, `config`, `plugins`, `routes`, `system_diagnostics`, `database_schema`, `extensibility`, `permissions_and_groups`, `search_skills` |
| GraphQL | 1 | `graphql` |
| Dev Actions | 4 | `craft_command`, `craft_exec`, `clear_caches`, `resave` |
| Workflow & Audit | 3 | `drafts_and_revisions`, `content_audit`, `import_export` |

Plus 8 prompts and 72 resources covering the full bundled-skills surface.

For the per-tool argument schemas and annotations, see [`docs/TOOLS.md`](docs/TOOLS.md). For prompts, [`docs/PROMPTS.md`](docs/PROMPTS.md). For resources, [`docs/RESOURCES.md`](docs/RESOURCES.md). All three regenerate via `ddev craft cortex/docs/all`.

### N+1 prevention

Relational fields on element output are stubbed by default:

```json
{ "fields": { "image": { "type": "relation", "loaded": false } } }
```

To materialise, pass `with: ["image"]` — Craft's eager-loading kicks in on the parent query, no per-entry round-trips.

## Configure

The plugin ships with sensible defaults. For environment-specific overrides, copy `vendor/craftpulse/craft-cortex/src/config/cortex.php` into your project's `config/cortex.php` and edit there. The file is heavily commented; settings cover:

- `allowedCommands` — glob patterns the `craft_command` tool may dispatch
- `execEnabled` / `execDryRunDefault` — `craft_exec` toggles
- `runtimeOverrideTtl` — default expiry for runtime allowlist overrides

Settings → Cortex in the CP also provides:

- A live editor for the allowlist (writes to project config)
- Toggles for the exec settings
- Runtime-override management (admin-issued, auto-expiring patterns that layer on top of the defaults)

## Extend

Third-party plugins can register their own tools, prompts, and resources via class-level events:

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

Same pattern for `Prompts::EVENT_REGISTER_PROMPTS` and `Resources::EVENT_REGISTER_RESOURCES`.

To scaffold a new tool against cortex's `AbstractTool` parent with the right attributes and Schema DSL stub:

```bash
ddev craft make cortex-tool --plugin=myplugin
```

The generator hooks into Craft's standard `make` command via `EVENT_REGISTER_GENERATORS`. It prompts for class name, namespace, and MCP tool name, then drops a stub class with `#[IsReadOnly]` `#[IsIdempotent]` defaults and an instruction block for registering it.

## Develop

This plugin is developed against the test environment at `~/dev/craft-plugin-playground/cms_v5/`, where it's symlinked into `vendor/craftpulse/craft-cortex` via a Composer path repository.

### Run the tests

```bash
ddev exec --dir=/var/www/html/cms vendor/bin/pest \
  --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist
```

Pest covers the registry, every tool, every prompt, every resource, the JSON-RPC dispatcher, the extension events, and architectural conventions (no `eval()` / `shell_exec()` family / `declare(strict_types=1)`, every tool implements `ToolInterface`, every class file has a section header and `@author Craftpulse`).

### Static analysis

```bash
ddev exec --dir=/var/www/html/cms vendor/bin/phpstan analyse \
  --memory-limit=1G -c vendor/craftpulse/craft-cortex/phpstan.neon
```

PHPStan level 8 — clean.

### Smoke through the inspector

The [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector) is the visual harness:

```bash
# In the playground:
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector UI, point at:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-plugin-playground-v5-web php /var/www/html/cms/craft cortex/serve`

The `initialize` handshake should succeed (`cortex 0.1.0`, protocol `2025-06-18`), and every tool / prompt / resource shows up in the lists.

## Roadmap

- **Phase 1 — Free.** 32 tools, 8 prompts, 77 resources (8 skill routers + 64 references + 5 agents), stdio transport, allowlist UI, install command, docs generators, extension events. Shipping.
- **Phase 2 — Pro.** HTTP transport with OAuth 2.1, permission filtering, audit log, 7 net-new write tools, mode unlocks on Free tools (drafts/audit/import-export apply / fix / import), Pro-exclusive custom-skills element type, minimal CP UI (tokens / activity / connection).
- **Phase 3 — Polish.** Install wizard auto-detecting installed clients, formal real-LLM E2E harness, vectorised docs search, third-party tool registration documented and battle-tested, skill remote-fetch.

Full plan: [PLANNING.md section 4](https://github.com/craftpulse/craft-cortex) (offsite — not in this repo).

## License

Free: MIT. Pro: proprietary, license-gated through the Craft Plugin Store (Phase 2).

## Credits

- [Model Context Protocol](https://modelcontextprotocol.io/) — Anthropic et al.
- [Craft CMS](https://craftcms.com/) — Pixel & Tonic
- [`craftcms/generator`](https://github.com/craftcms/generator) — the make-system extensibility we hook into for `cortex-tool`
- [`michtio/craftcms-claude-skills`](https://github.com/michtio/craftcms-claude-skills) — the skills bundle cortex serves as prompts + resources
