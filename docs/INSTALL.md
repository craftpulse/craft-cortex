# Installing Cortex

This guide walks you through installing Cortex into a Craft CMS 5 project and connecting it to your MCP client.

- [Requirements](#requirements)
- [Install the plugin](#install-the-plugin)
  - [Plugin Store](#plugin-store)
  - [Composer (CLI)](#composer-cli)
  - [DDEV-based projects](#ddev-based-projects)
- [Connect your MCP client](#connect-your-mcp-client)
  - [Auto-config writer](#auto-config-writer)
  - [Manual snippet printer](#manual-snippet-printer)
  - [Per-client configuration](#per-client-configuration)
- [Verify the connection](#verify-the-connection)
- [Troubleshooting](#troubleshooting)

## Requirements

- Craft CMS **5.0.0** or later
- PHP **8.2** or later
- Composer
- An MCP-capable client. Cortex officially supports seven:
  - Claude Desktop (macOS / Windows / Linux)
  - Claude Code (CLI)
  - Cursor
  - Continue.dev (VS Code extension)
  - Cline (VS Code extension)
  - Zed
  - Windsurf

Other MCP clients that accept the standard `mcpServers` JSON shape (`command` + `args`) will work — you just won't get a one-command auto-config.

## Install the plugin

You have three install paths. Pick whichever fits your workflow.

### Plugin Store

This is the easiest path if you're already in the Craft Control Panel.

1. Sign in to your Craft project's Control Panel.
2. Go to **Settings → Plugin Store**.
3. Search for **Cortex**.
4. Click **Install** in the plugin's modal window.

Craft pulls the package via Composer, runs the install migration, and registers the plugin. You'll see **Cortex** appear under **Settings → Plugins** when it's done.

### Composer (CLI)

1. Open your terminal and go to your Craft project root:

        cd /path/to/project

2. Tell Composer to load the plugin:

        composer require craftpulse/craft-cortex

3. Install the plugin via the CLI:

        php craft plugin/install cortex

   …or, in the Control Panel, go to **Settings → Plugins** and click the **Install** button next to Cortex.

### DDEV-based projects

If your local environment runs in [DDEV](https://ddev.com/), use the same commands prefixed with `ddev`:

```bash
ddev composer require craftpulse/craft-cortex
ddev craft plugin/install cortex
```

Cortex is DDEV-aware. When you run `ddev craft cortex/install` it auto-detects the DDEV environment (via `IS_DDEV_PROJECT` / `DDEV_PROJECT`) and emits the `docker exec`-form invocation that lets your host-side MCP client reach into the container. You don't need to expose any ports.

## Connect your MCP client

Once Cortex is installed, your MCP client needs to know how to talk to it. Cortex provides two console actions for this — a snippet printer for manual copy-paste, and an auto-config writer that drops the entry directly into your client's config file.

### Auto-config writer

The fastest path. From your project root:

```bash
ddev craft cortex/install/apply --client=claude-desktop --dry-run
```

The `--dry-run` flag prints the target file path and a before / after diff without touching any files. Inspect the diff. If it looks right, re-run without `--dry-run`:

```bash
ddev craft cortex/install/apply --client=claude-desktop
```

Cortex confirms before writing (`y/N`). It then:

1. Backs the existing config up to `<file>.bak.<unix-timestamp>` (only if a file already exists).
2. Writes the new contents to a sibling temp file.
3. Atomically renames the temp into place.

If the parent config directory doesn't exist (e.g. you're trying to configure Claude Desktop on a machine without Claude Desktop installed), Cortex refuses with a clear error rather than ghost-creating the directory — that's the canonical "client not installed" signal.

**Re-runs are idempotent.** If a Cortex entry is already in the file, the action refuses unless you pass `--force`. With `--force`, Cortex makes a fresh backup, replaces the entry, and leaves every other server in the file untouched.

Supported flags:

| Flag | Effect |
|------|--------|
| `--client=<name>` | Required. One of `claude-desktop`, `claude-code`, `cursor`, `continue`, `cline`, `zed`, `windsurf`. |
| `--dry-run` | Print the target path and would-be diff. Skip the write. Skip the confirm prompt. |
| `--force` (`-f`) | Overwrite an existing Cortex entry. Default refuses for safety. |
| `--ddev` (`-d`) | Force the DDEV `docker exec` invocation form (auto-detected by default). |
| `--ddevProject=<name>` | Override the DDEV project name in the docker-exec command. Auto-detected from `DDEV_PROJECT`. |

### Manual snippet printer

If your client isn't supported by the auto-config writer, or you'd rather copy-paste, use the snippet printer:

```bash
ddev craft cortex/install                        # print snippets for all 7 clients
ddev craft cortex/install --client=claude-desktop  # one client
```

The output includes the client's config file path (per-platform) and the JSON or YAML block to paste in. Reload the client and Cortex shows up.

### Per-client configuration

Reference paths Cortex writes to (or prints in the snippet form). The auto-config writer resolves these per-platform; the manual snippets list all three platforms in the output.

#### Claude Desktop

Config file:

| Platform | Path |
|----------|------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

Format: top-level `mcpServers` JSON object. Reload Claude Desktop after writing (Settings → Developer → Restart, or quit + relaunch).

#### Claude Code

Config file: project-scoped `.mcp.json` in your project root. Cortex writes it next to your `craft` script.

You can also run `claude mcp add --transport stdio cortex -- <command>` from your project directory — the snippet printer shows the exact command for your environment. The `--` separator is required: it stops Claude Code from parsing the wrapped `docker exec -i`'s `-i` flag as one of its own.

#### Cursor

Config file: `~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (project-scoped). Cortex writes the global form by default. Format: `mcpServers` JSON object.

Cursor watches the file and picks up the change without a restart.

#### Continue.dev

Config file: `~/.continue/mcpServers/cortex.yaml` (standalone block file). Cortex writes a dedicated file rather than mutating your existing `~/.continue/config.yaml` so there's zero risk of clobbering your other Continue config.

The standalone block format requires three top-level metadata fields (`name`, `version`, `schema: v1`) per [docs.continue.dev/reference](https://docs.continue.dev/reference). Cortex emits all three.

#### Cline

Config file (per-platform):

| Platform | Path |
|----------|------|
| macOS | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |
| Windows | `%APPDATA%\Code\User\globalStorage\saoudrizwan.claude-dev\settings\cline_mcp_settings.json` |
| Linux | `~/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |

Format: top-level `mcpServers` JSON object. **Note:** Cline's MCP support has historically been less stable than other clients — if changes don't apply, restart VS Code.

#### Zed

Config file:

| Platform | Path |
|----------|------|
| macOS | `~/.config/zed/settings.json` |
| Linux | `~/.config/zed/settings.json` |
| Windows | `%APPDATA%\Zed\settings.json` |

Format: top-level `context_servers` JSON object (Zed uses `context_servers`, not `mcpServers`).

#### Windsurf

Config file: `~/.codeium/windsurf/mcp_config.json` across all platforms. Format: top-level `mcpServers` JSON object.

## Verify the connection

In your MCP client, ask the assistant something Cortex can answer, for example:

> "List the sections in this Craft project."

The assistant should call the `sections` tool and return a structured list. If your client has a tools / servers panel, you'll see Cortex listed alongside any other MCP servers you've registered.

You can also smoke-test the protocol layer with the [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector):

```bash
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector UI, point at:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-<project>-web php /var/www/html/craft cortex/serve`

A successful `initialize` handshake reports `cortex 0.1.0` and protocol `2025-06-18`. `tools/list` returns 32 entries; `prompts/list` returns 8; `resources/list` returns 77.

## Troubleshooting

### The `apply` command refuses with "config directory not found"

Cortex won't ghost-create config directories — their absence is the canonical "client not installed" signal. Either install the client first, or use the manual snippet form (`cortex/install --client=<name>`) and paste into a config file you create yourself.

### `apply` refuses inside DDEV but my client IS installed (on the host)

If you're running `ddev craft cortex/install/apply` from inside a DDEV container, the auto-config writer can only see the container's filesystem — not your host's. Your MCP client lives on the host, so its config directory looks "missing" from the container's perspective.

Two ways to handle it:

1. **`--dry-run` to preview, then copy-paste.** Inside DDEV, run with `--dry-run`. Cortex prints the would-be `AFTER` block including the correct `docker exec` invocation. Copy it into your host MCP client's config file manually.

2. **Use the manual snippet form** — `ddev craft cortex/install --client=<name>` — which is designed to be DDEV-friendly and prints the same content with the surrounding context lines.

### The `apply` command refuses with "Cortex entry already present"

The action is idempotent by default. Re-run with `--force` to overwrite the existing entry. Cortex makes a fresh backup before writing.

### My client doesn't see Cortex after I configured it

1. Reload the client. Most clients pick up config changes on restart, not live.
2. Check the file your client actually reads. Some editors have multiple config scopes (user / project / workspace) and the client may be looking at a different one. Run `cortex/install --client=<name>` to see the canonical path Cortex writes to.
3. Check Cortex's invocation. From your project root:

   ```bash
   ddev exec /var/www/html/craft cortex/serve --help
   ```

   If `cortex/serve` isn't a recognised action, the plugin isn't installed in the playground / project Cortex is pointing at. Re-run `ddev craft plugin/install cortex`.

4. Check the Craft logs at `storage/logs/web.log` (or `phpstorm.log` / `craft.log`). MCP-level errors land in the `cortex` log channel.

### The `docker exec` command in the snippet refers to a project name I don't recognise

Auto-detection uses the `DDEV_PROJECT` env variable. If you're outside a DDEV shell when running `cortex/install`, override it: `ddev craft cortex/install --ddevProject=myproject`.

### `craft_exec` only returns "dry-run" output

That's by design. `execDryRunDefault` is `true` by default (one of six security gates around `craft_exec`). Pass `confirm: true` in your tool call to actually evaluate the expression. Destructive expressions (`delete*`, `drop*`, `truncate*`) require both `confirm: true` and `dangerous: true`. See [`SECURITY.md`](SECURITY.md#craft_exec-six-security-gates) for the full gate model.

### Continue.dev doesn't pick up the standalone YAML

Continue.dev reads standalone block files from `~/.continue/mcpServers/`. Confirm the file exists at `~/.continue/mcpServers/cortex.yaml` and contains `schema: v1` at the top. If your Continue install is older, it may not read this directory — fall back to the manual snippet and add the entry under `mcpServers:` in your existing `~/.continue/config.yaml`.

### Anything else

Open an issue at [github.com/craftpulse/craft-cortex/issues](https://github.com/craftpulse/craft-cortex/issues) — please include:

- Your OS and shell
- The MCP client and its version
- Output of `ddev craft cortex/install --client=<your client>` so we can see the resolved invocation
- Any relevant lines from `storage/logs/web.log` (the `cortex` channel)
