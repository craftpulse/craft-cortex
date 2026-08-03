# Connecting a client

Herald serves nothing until an MCP client is pointed at it. This page covers the console actions that write client config, the per-client config paths, and what to check when a client does not see Herald.

For the HTTP transport (bearer tokens, OAuth 2.1, streaming), see [HTTP transport](HTTP-TRANSPORT.md).

- [The four console actions](#the-four-console-actions)
- [Auto-detect and apply](#auto-detect-and-apply)
- [Write config for one client](#write-config-for-one-client)
- [Print a snippet and paste it yourself](#print-a-snippet-and-paste-it-yourself)
- [Per-client configuration](#per-client-configuration)
- [Verify the connection](#verify-the-connection)
- [Troubleshooting](#troubleshooting)

## The four console actions

| Action | What it does | Where it runs |
|---|---|---|
| `herald/install` | Prints a copy-paste snippet for every supported client, or one with `--client=<name>`. Read-only. | Anywhere. |
| `herald/install/apply --client=<name>` | Writes Herald config into one client's config file, atomically, with a timestamped backup. | Anywhere, as long as the target config path exists on the running filesystem. |
| `herald/install/detect` | Scans the host and prints which clients are installed and configured. Read-only. | Host only. Refuses inside a container. |
| `herald/install/auto` | Scans the host, then prompts to apply Herald config for each detected client. | Host only. Refuses inside a container. |

`detect` and `auto` refuse to run inside a container (DDEV, Docker, Lando, Sail, Podman, LXC, Kubernetes) because a container cannot see the host's `/Applications`, `~/.cursor`, or `%LOCALAPPDATA%`, so detection from inside would report false negatives. Run them from the host's PHP:

```shell
php /path/to/project/craft herald/install/auto
```

The snippet form works fine inside DDEV. Herald detects DDEV from `IS_DDEV_PROJECT` / `DDEV_PROJECT` and emits the `docker exec` invocation form, so a host-side client can reach into the container without any exposed port.

## Auto-detect and apply

From the host, this is one command:

```shell
php craft herald/install/auto
```

Herald scans for installed clients and their config directories, then shows a `[Y/n]` prompt per client. Confirming writes the config the same way `apply` does. Pair it with `--dry-run` to preview every write without touching a file, or run `herald/install/detect` for the table alone.

## Write config for one client

```shell
ddev craft herald/install/apply --client=claude-desktop --dry-run
```

`--dry-run` prints the target path and a before / after diff and writes nothing. When the diff looks right, re-run without it:

```shell
ddev craft herald/install/apply --client=claude-desktop
```

Herald confirms before writing (`y/N`), then backs the existing file up to `<file>.bak.<unix-timestamp>`, writes to a sibling temp file, and renames it into place.

Re-runs are idempotent: when a Herald entry is already present the action refuses unless you pass `--force`, and with `--force` it takes a fresh backup, replaces only the Herald entry, and leaves every other server in the file untouched. When the parent config directory does not exist, Herald refuses rather than creating it, because a missing config directory is the canonical "client not installed" signal.

| Flag | Effect |
|---|---|
| `--client=<name>` | Required. One of `claude-desktop`, `claude-code`, `cursor`, `continue`, `cline`, `zed`, `windsurf`. |
| `--dry-run` | Print the target path and would-be diff, skip the write and the confirm prompt. |
| `--force` (`-f`) | Overwrite an existing Herald entry. The default refuses. |
| `--ddev` (`-d`) | Force the DDEV `docker exec` invocation form. Auto-detected by default. |
| `--ddevProject=<name>` | Override the DDEV project name in the docker-exec command. Read from `DDEV_PROJECT` by default. |

## Print a snippet and paste it yourself

```shell
ddev craft herald/install                          # every supported client
ddev craft herald/install --client=claude-desktop  # one client
```

The output carries the client's per-platform config path and the JSON or YAML block to paste in. Reload the client afterwards.

## Per-client configuration

These are the paths the config writer resolves, and the ones the snippet printer lists.

### Claude Desktop

| Platform | Path |
|---|---|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

Format: a top-level `mcpServers` JSON object. Reload the client after writing.

### Claude Code

Config file: a project-scoped `.mcp.json` in the project root, written next to your `craft` script.

You can also run `claude mcp add --transport stdio herald -- <command>` from the project directory; the snippet printer shows the exact command for your environment. The `--` separator is required, because it stops the client from parsing the wrapped `docker exec -i`'s `-i` flag as one of its own.

### Cursor

Config file: `~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (project-scoped). Herald writes the global form by default. Format: a top-level `mcpServers` JSON object. Cursor watches the file and picks the change up without a restart.

### Continue.dev

Config file: `~/.continue/mcpServers/herald.yaml`, a standalone block file. Herald writes a dedicated file rather than mutating your existing `~/.continue/config.yaml`, so an existing Continue config cannot be clobbered.

The standalone block format requires three top-level metadata fields (`name`, `version`, `schema: v1`) per [docs.continue.dev/reference](https://docs.continue.dev/reference). Herald emits all three.

### Cline

| Platform | Path |
|---|---|
| macOS | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |
| Windows | `%APPDATA%\Code\User\globalStorage\saoudrizwan.claude-dev\settings\cline_mcp_settings.json` |
| Linux | `~/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |

Format: a top-level `mcpServers` JSON object. Restart VS Code if a change does not take effect.

### Zed

| Platform | Path |
|---|---|
| macOS | `~/.config/zed/settings.json` |
| Linux | `~/.config/zed/settings.json` |
| Windows | `%APPDATA%\Zed\settings.json` |

Format: a top-level `context_servers` JSON object. Zed uses `context_servers`, not `mcpServers`.

### Windsurf

Config file: `~/.codeium/windsurf/mcp_config.json` on every platform. Format: a top-level `mcpServers` JSON object.

## Verify the connection

Ask the client something Craft can answer:

> "List the sections in this Craft project."

The client should call the `sections` tool and return a structured list. A client with a tools or servers panel shows Herald alongside your other MCP servers.

You can also smoke-test the protocol layer with the [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector):

```shell
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector, set:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-<project>-web php /var/www/html/craft herald/serve`

A successful `initialize` handshake reports `herald` with its version and the negotiated protocol revision: `2025-11-25` when the client requests it, or `2025-06-18` for an older client. On a Free install `tools/list` returns 33 entries and `prompts/list` returns 10. The `resources/list` count tracks the bundled corpus, so it moves with the version of `michtio/craftcms-claude-skills` that Composer resolved.

## Troubleshooting

### The apply command refuses with "config directory not found"

Herald will not create a client's config directory, because its absence is the canonical "client not installed" signal. Install the client first, or use `herald/install --client=<name>` and paste into a file you create yourself.

### Apply refuses inside a container but my client is installed on the host

From inside a container the writer sees only the container filesystem, so a host-side client's config directory looks missing. Two ways round it:

1. Run with `--dry-run` inside the container. Herald prints the would-be `AFTER` block including the correct `docker exec` invocation, and you paste it into the host config file.
2. Use `ddev craft herald/install --client=<name>`, which prints the same content with its surrounding context lines.

### The apply command refuses with "Herald entry already present"

The action is idempotent by default. Re-run with `--force`; Herald takes a fresh backup before replacing the entry.

### My client does not see Herald after I configured it

1. Reload the client. Most clients read config on start, not live.
2. Check which file the client actually reads. Editors often have user, project and workspace config scopes. `herald/install --client=<name>` prints the canonical path Herald writes to.
3. Check the invocation resolves: `ddev exec /var/www/html/craft herald/serve --help`. If `herald/serve` is not a recognised action, the plugin is not installed in the project the client points at.
4. Check Craft's logs. Protocol-level errors land on the `herald` log channel.

### The docker exec command names a project I do not recognise

Auto-detection reads `DDEV_PROJECT`. Outside a DDEV shell, override it: `ddev craft herald/install --ddevProject=myproject`.

### craft_exec only returns dry-run output

That is the default, and it is the first of six gates around the tool. Pass `confirm: true` in the tool call to evaluate the expression. Destructive expressions require both `confirm: true` and `dangerous: true`. See [`SECURITY.md`](SECURITY.md#craft_exec-six-security-gates).

### Continue.dev does not pick up the standalone YAML

Continue reads standalone block files from `~/.continue/mcpServers/`. Confirm `~/.continue/mcpServers/herald.yaml` exists and carries `schema: v1` at the top. On an older Continue install that directory is not read, so fall back to adding the entry under `mcpServers:` in `~/.continue/config.yaml`.

### Anything else

Open an issue at [github.com/craftpulse/craft-herald/issues](https://github.com/craftpulse/craft-herald/issues) with your OS and shell, the client and its version, the output of `ddev craft herald/install --client=<your client>` so the resolved invocation is visible, and any relevant lines from the `herald` log channel.
