# Installing Herald

This guide walks you through installing Herald into a Craft CMS 5 project and connecting it to your MCP client.

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
- An MCP-capable client. Herald officially supports seven:
  - Claude Desktop (macOS / Windows / Linux)
  - Claude Code (CLI)
  - Cursor
  - Continue.dev (VS Code extension)
  - Cline (VS Code extension)
  - Zed
  - Windsurf

Other MCP clients that accept the standard `mcpServers` JSON shape (`command` + `args`) will work; you just won't get a one-command auto-config.

## Install the plugin

You have three install paths. Pick whichever fits your workflow.

### Plugin Store

This is the easiest path if you're already in the Craft Control Panel.

1. Sign in to your Craft project's Control Panel.
2. Go to **Settings → Plugin Store**.
3. Search for **Herald**.
4. Click **Install** in the plugin's modal window.

Craft pulls the package via Composer, runs the install migration, and registers the plugin. You'll see **Herald** appear under **Settings → Plugins** when it's done.

### Composer (CLI)

**Not yet on Packagist.** Herald is pre-Plugin-Store-submission, so `composer require` needs a VCS repository entry in your project's `composer.json` for both Herald and its `craftpulse/craft-audit-kit` dependency:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/craftpulse/craft-herald.git" },
    { "type": "vcs", "url": "https://github.com/craftpulse/craft-audit-kit.git" }
]
```

1. Open your terminal and go to your Craft project root:

        cd /path/to/project

2. Tell Composer to load the plugin:

        composer require craftpulse/craft-herald

3. Install the plugin via the CLI:

        php craft plugin/install herald

   …or, in the Control Panel, go to **Settings → Plugins** and click the **Install** button next to Herald.

### DDEV-based projects

If your local environment runs in [DDEV](https://ddev.com/), use the same commands prefixed with `ddev`:

```bash
ddev composer require craftpulse/craft-herald
ddev craft plugin/install herald
```

Herald is DDEV-aware. When you run `ddev craft herald/install` it auto-detects the DDEV environment (via `IS_DDEV_PROJECT` / `DDEV_PROJECT`) and emits the `docker exec`-form invocation that lets your host-side MCP client reach into the container. You don't need to expose any ports.

## Connect your MCP client

Once Herald is installed, your MCP client needs to know how to talk to it. Herald provides four console actions for this, listed below in order of decreasing magic:

| Action | What it does | Where it runs |
|--------|--------------|---------------|
| `herald/install/auto` | Scans the host for installed MCP clients, then for each one prompts to apply Herald config. The "I installed Herald, now wire it up everywhere" flow. | **Host only.** Refuses to run from inside a container (DDEV, Docker, Lando, Sail, Podman, LXC, Kubernetes). |
| `herald/install/detect` | Scans the host and prints a status table of which clients are installed and configured. Read-only, and it never writes. | **Host only.** Refuses to run from inside a container. |
| `herald/install/apply --client=<name>` | Writes Herald config to one specific client's config file. Atomic write + timestamped backup. | Anywhere (host or DDEV), but the config path it targets must exist on the running filesystem. |
| `herald/install` | Prints copy-paste snippets for every supported client (or just one with `--client=<name>`). Read-only. | Anywhere. |

**If you're running Herald inside a container** (DDEV, plain Docker, Lando, Sail, Podman, LXC, Kubernetes), your container can't see your host's `/Applications`, `~/.cursor`, etc., so `detect` and `auto` refuse to run there to avoid false negatives. Run them from your host's PHP:

```bash
php /path/to/project/craft herald/install/auto
```

Or stick with the manual snippet form (`ddev craft herald/install`), which works fine inside DDEV.

### Bearer-token authentication for the HTTP transport

Herald also exposes an HTTP transport at `POST /herald/mcp` for clients that don't speak stdio (Claude Desktop's hosted MCP setup, browser-based agents, anything behind a remote agent). The HTTP transport is **disabled by default**. Flip `Settings::$httpEnabled = true` in `config/herald.php` to expose it.

Once enabled, every request to `/herald/mcp` must carry `Authorization: Bearer <token>`. Issue a token from the console:

```bash
ddev craft herald/token/issue <user> [--name=<name>] [--ttl=<seconds>]
```

The plaintext token prints **exactly once** at issuance, so copy it then. Herald stores only the SHA-256 hash; if you lose the plaintext, revoke the token and issue a fresh one. By default tokens never expire; pass `--ttl=<seconds>` (e.g. `--ttl=2592000` for 30 days) for shorter rotation, or set `Settings::$tokenTtlDefault` for a global default.

Configure your MCP client with:

```
Authorization: Bearer <plaintext-token>
```

Manage tokens with two more actions:

```bash
ddev craft herald/token/list [--user=<email-or-username>]
ddev craft herald/token/revoke <id>
```

Revocation is immediate for new requests. In-flight requests on a revoked token complete normally, the next request fails 401.

### OAuth 2.1 for the HTTP transport (optional, MCP-spec-compliant)

For clients that auto-discover and self-register against a remote MCP server (Claude Desktop's hosted MCP setup, Anthropic's `/.well-known` flow, IDE plugins that ship with OAuth support), Herald also exposes a full OAuth 2.1 surface: Authorization Code + PKCE (S256), Refresh Token, RFC 7591 Dynamic Client Registration, RFC 7009 token revocation, and RFC 8414 / RFC 9728 discovery metadata.

The OAuth surface coexists with bearer tokens: both authenticate against the same `herald/mcp` endpoint, OAuth checked first per RFC 8707 audience binding, bearer as fallback for the long-lived admin-issued credentials.

**One-time setup:**

```bash
ddev craft herald/oauth/init-keys
```

This generates an RSA 2048-bit key pair at `storage/herald/oauth-keys/{private,public}.key`. The private key is set to `0600` and signs every JWT access token Herald issues; the public key verifies them. The pair stays put across deploys (Git ignores `storage/`). Rotating with `--force` invalidates every in-flight access token.

**Discovery endpoints** (no auth required):

```
GET https://your-site.test/.well-known/oauth-authorization-server   # RFC 8414
GET https://your-site.test/.well-known/oauth-protected-resource     # RFC 9728
```

MCP-spec-aware clients hit these on first contact to discover the authorization server, registration endpoint, supported scopes (`read`, `write`), and PKCE methods (`S256` only, with `plain` rejected).

**Dynamic Client Registration (RFC 7591):**

```bash
curl -X POST https://your-site.test/oauth/register \
  -H 'Content-Type: application/json' \
  -d '{
    "client_name": "My MCP Client",
    "redirect_uris": ["https://my-client.test/callback"],
    "token_endpoint_auth_method": "none"
  }'
```

Returns a fresh `client_id` (and `client_secret` if the method is not `none`). DCR is open-by-default (`Settings::$dcrEnabled = true`). Set it to false to require out-of-band client provisioning.

**Full PKCE flow:**

1. Generate a code verifier (43+ chars, `[A-Za-z0-9-._~]`) and SHA-256 it for the `code_challenge`.
2. Redirect the user to:
   ```
   https://your-site.test/oauth/authorize?
     response_type=code&
     client_id=<from DCR>&
     redirect_uri=<one of registered>&
     code_challenge=<S256 challenge>&
     code_challenge_method=S256&
     scope=read&
     state=<random>&
     resource=https://your-site.test/herald/mcp
   ```
3. The user lands on Herald's consent screen (Craft CP login required); approving redirects back to `redirect_uri` with `code` and `state`.
4. Exchange the code for tokens:
   ```bash
   curl -X POST https://your-site.test/oauth/token \
     -d 'grant_type=authorization_code' \
     -d 'code=<from redirect>' \
     -d 'redirect_uri=<same as step 2>' \
     -d 'client_id=<from DCR>' \
     -d 'code_verifier=<original verifier>' \
     -d 'resource=https://your-site.test/herald/mcp'
   ```
5. Use the resulting access token:
   ```bash
   curl -X POST https://your-site.test/herald/mcp \
     -H 'MCP-Protocol-Version: 2025-11-25' \
     -H 'Authorization: Bearer <access_token>' \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"my-client","version":"1.0"}}}'
   ```

**Audience binding (RFC 8707):** The `resource` parameter on `/authorize` and `/token` ends up in the JWT `aud` claim. Herald verifies it matches the canonical `herald/mcp` URL on every request, so a token issued for resource A can't be replayed against resource B. Pass `resource=<absolute URL to /herald/mcp>` on both endpoints.

**Token lifetimes** (defaults; configurable via `Settings::$oauthAccessTokenTtl` / `$oauthRefreshTokenTtl`):
- Access tokens: 1 hour (`PT1H`).
- Refresh tokens: 30 days (`P30D`).

Refresh:

```bash
curl -X POST https://your-site.test/oauth/token \
  -d 'grant_type=refresh_token' \
  -d 'refresh_token=<from previous exchange>' \
  -d 'client_id=<from DCR>'
```

Revoke (RFC 7009):

```bash
curl -X POST https://your-site.test/oauth/revoke -d 'token=<access or refresh>'
```

Per RFC 7009 §2.2 the endpoint returns 200 regardless of whether the token was known, so there is no information leakage.

### Streaming Tools (SSE over HTTP)

Herald's HTTP transport supports MCP's Streamable HTTP profile: long-running tools can emit progress frames between the original `tools/call` request and its terminal JSON-RPC response, and clients can cancel an in-flight call without dropping the connection. The Free tier ships no streaming tools; the wire is ready for Gate 8's Pro write tools (eager resave, content audit, batch import/export).

**Client opt-in.** Clients request the SSE response by sending `Accept: text/event-stream` on the `tools/call` POST. MCP-spec-compliant clients (Claude Desktop, Claude Code, Cursor) send the SSE Accept by default. Herald falls back to the JSON response when the header is absent or doesn't mention `text/event-stream`.

```
POST /herald/mcp
Accept: text/event-stream
Content-Type: application/json
Authorization: Bearer <token>
Mcp-Session-Id: <session-id>
MCP-Protocol-Version: 2025-11-25

{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"<tool>","arguments":{...},"_meta":{"progressToken":"prog-1"}}}
```

**Response wire format.** SSE frames, one event per line block:

```
id: <uuid>
event: message
data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"prog-1","progress":1,"total":3}}

id: <uuid>
event: message
data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"prog-1","progress":2,"total":3}}

id: <uuid>
event: message
data: {"jsonrpc":"2.0","id":2,"result":{"content":[...],"isError":false}}
```

The terminal frame carries the original request id and the `tools/call` result envelope; intermediate frames are `notifications/progress` envelopes carrying the client's `progressToken` (when supplied in `_meta`) plus `progress` and optional `total` / `message` fields. Each frame's `id:` is a UUIDv4, currently emitted for forward compatibility with `Last-Event-ID` resumability (deferred to Phase 3); no replay buffer exists today, so reconnects after a dropped connection lose in-flight frames.

**Cancellation.** Send a JSON-RPC `notifications/cancelled` notification (not a request, so no `id` field) referencing the in-flight request id:

```
POST /herald/mcp
Authorization: Bearer <token>
Mcp-Session-Id: <session-id>
MCP-Protocol-Version: 2025-11-25

{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":2,"reason":"user requested"}}
```

The server flips a cache-backed cancellation flag the running tool observes between yields. Cooperative tools short-circuit and the server emits a terminal `notifications/cancelled` envelope on the SSE stream. Tools that ignore the flag (pure CPU loops without yield checkpoints) cannot be cancelled, because the contract is cooperative, not preemptive. The cancellation slot's TTL is one hour: a delayed `notifications/cancelled` arriving after a network blip still flips a running stream.

**Audit logging.** Streamed invocations write exactly one row to `herald_invocations` per stream completion, not per frame. The `durationMs` column reflects wall-clock from stream start to stream end. Cancellation events surface as `kind=cancelled` rows (distinct from `tool_error`, `internal_error`, and `rate_limited`); `errorClass` / `errorMessage` stay null, since cancellation isn't an error.

**Operator smoke test.** A built-in fixture tool, `_streaming_test`, exists for end-to-end SSE health checks. It's gated behind the `HERALD_STREAMING_FIXTURE=1` env var and never registers in production unless an operator opts in. Flip the env var, restart your PHP-FPM workers, and:

```bash
curl -N -X POST https://your-site.test/herald/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Mcp-Session-Id: $SESSION" \
  -H 'Accept: text/event-stream' \
  -H 'MCP-Protocol-Version: 2025-11-25' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"_streaming_test","arguments":{},"_meta":{"progressToken":"prog-1"}}}'
```

Expect: HTTP 200, `Content-Type: text/event-stream`, three progress frames (`progress: 1..3, total: 3`), then a terminal response with `done: true`. If you see anything else (nginx buffering the response into one block, FPM workers not flushing, an SSE-aware proxy rewriting the Content-Type), the fixture's deterministic output makes the misconfiguration easy to spot.

### Auto-detect and apply (fastest)

If you're running Herald from the host (not inside a container), this is one command:

```bash
php craft herald/install/auto
```

Herald scans your host for installed MCP clients (`/Applications`, `PATH`, `%LOCALAPPDATA%`) and the directories where they store config. For each detected client, you'll see a `[Y/n]` prompt. Confirm and Herald writes the config entry the same way `apply` does (atomic write, timestamped backup, idempotent re-runs). Skip with `n` and move to the next.

To preview without writing anything, pair with `--dry-run`:

```bash
php craft herald/install/auto --dry-run
```

To just see the detection table without applying:

```bash
php craft herald/install/detect
```

### Auto-config writer

The fastest path. From your project root:

```bash
ddev craft herald/install/apply --client=claude-desktop --dry-run
```

The `--dry-run` flag prints the target file path and a before / after diff without touching any files. Inspect the diff. If it looks right, re-run without `--dry-run`:

```bash
ddev craft herald/install/apply --client=claude-desktop
```

Herald confirms before writing (`y/N`). It then:

1. Backs the existing config up to `<file>.bak.<unix-timestamp>` (only if a file already exists).
2. Writes the new contents to a sibling temp file.
3. Atomically renames the temp into place.

If the parent config directory doesn't exist (e.g. you're trying to configure Claude Desktop on a machine without Claude Desktop installed), Herald refuses with a clear error rather than ghost-creating the directory, which is the canonical "client not installed" signal.

**Re-runs are idempotent.** If a Herald entry is already in the file, the action refuses unless you pass `--force`. With `--force`, Herald makes a fresh backup, replaces the entry, and leaves every other server in the file untouched.

Supported flags:

| Flag | Effect |
|------|--------|
| `--client=<name>` | Required. One of `claude-desktop`, `claude-code`, `cursor`, `continue`, `cline`, `zed`, `windsurf`. |
| `--dry-run` | Print the target path and would-be diff. Skip the write. Skip the confirm prompt. |
| `--force` (`-f`) | Overwrite an existing Herald entry. Default refuses for safety. |
| `--ddev` (`-d`) | Force the DDEV `docker exec` invocation form (auto-detected by default). |
| `--ddevProject=<name>` | Override the DDEV project name in the docker-exec command. Auto-detected from `DDEV_PROJECT`. |

### Manual snippet printer

If your client isn't supported by the auto-config writer, or you'd rather copy-paste, use the snippet printer:

```bash
ddev craft herald/install                        # print snippets for all 7 clients
ddev craft herald/install --client=claude-desktop  # one client
```

The output includes the client's config file path (per-platform) and the JSON or YAML block to paste in. Reload the client and Herald shows up.

### Per-client configuration

Reference paths Herald writes to (or prints in the snippet form). The auto-config writer resolves these per-platform; the manual snippets list all three platforms in the output.

#### Claude Desktop

Config file:

| Platform | Path |
|----------|------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux | `~/.config/Claude/claude_desktop_config.json` |

Format: top-level `mcpServers` JSON object. Reload Claude Desktop after writing (Settings → Developer → Restart, or quit + relaunch).

#### Claude Code

Config file: project-scoped `.mcp.json` in your project root. Herald writes it next to your `craft` script.

You can also run `claude mcp add --transport stdio herald -- <command>` from your project directory. The snippet printer shows the exact command for your environment. The `--` separator is required: it stops Claude Code from parsing the wrapped `docker exec -i`'s `-i` flag as one of its own.

#### Cursor

Config file: `~/.cursor/mcp.json` (global) or `.cursor/mcp.json` (project-scoped). Herald writes the global form by default. Format: `mcpServers` JSON object.

Cursor watches the file and picks up the change without a restart.

#### Continue.dev

Config file: `~/.continue/mcpServers/herald.yaml` (standalone block file). Herald writes a dedicated file rather than mutating your existing `~/.continue/config.yaml` so there's zero risk of clobbering your other Continue config.

The standalone block format requires three top-level metadata fields (`name`, `version`, `schema: v1`) per [docs.continue.dev/reference](https://docs.continue.dev/reference). Herald emits all three.

#### Cline

Config file (per-platform):

| Platform | Path |
|----------|------|
| macOS | `~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |
| Windows | `%APPDATA%\Code\User\globalStorage\saoudrizwan.claude-dev\settings\cline_mcp_settings.json` |
| Linux | `~/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json` |

Format: top-level `mcpServers` JSON object. **Note:** Cline's MCP support has historically been less stable than other clients, so if changes don't apply, restart VS Code.

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

In your MCP client, ask the assistant something Herald can answer, for example:

> "List the sections in this Craft project."

The assistant should call the `sections` tool and return a structured list. If your client has a tools / servers panel, you'll see Herald listed alongside any other MCP servers you've registered.

You can also smoke-test the protocol layer with the [DDEV MCP Inspector add-on](https://github.com/michtio/ddev-mcp-inspector):

```bash
ddev add-on get michtio/ddev-mcp-inspector
ddev restart
ddev mcp-inspector
```

In the Inspector UI, point at:

- **Transport Type:** STDIO
- **Command:** `docker`
- **Arguments:** `exec -i ddev-<project>-web php /var/www/html/craft herald/serve`

A successful `initialize` handshake reports `herald 5.0.0` and the negotiated protocol version: `2025-11-25` (the latest Herald advertises) when your client requests it, or `2025-06-18` when an older client does. On a Free install `tools/list` returns 33 entries; `prompts/list` returns 10; `resources/list` returns 98.

## Troubleshooting

### The `apply` command refuses with "config directory not found"

Herald won't ghost-create config directories; their absence is the canonical "client not installed" signal. Either install the client first, or use the manual snippet form (`herald/install --client=<name>`) and paste into a config file you create yourself.

### `apply` refuses inside a container but my client IS installed (on the host)

If you're running `ddev craft herald/install/apply` (or any containerised equivalent) from inside a container, the auto-config writer can only see the container's filesystem, not your host's. Your MCP client lives on the host, so its config directory looks "missing" from the container's perspective.

Two ways to handle it:

1. **`--dry-run` to preview, then copy-paste.** Inside DDEV, run with `--dry-run`. Herald prints the would-be `AFTER` block including the correct `docker exec` invocation. Copy it into your host MCP client's config file manually.

2. **Use the manual snippet form**: `ddev craft herald/install --client=<name>`, which is designed to be DDEV-friendly and prints the same content with the surrounding context lines.

### The `apply` command refuses with "Herald entry already present"

The action is idempotent by default. Re-run with `--force` to overwrite the existing entry. Herald makes a fresh backup before writing.

### My client doesn't see Herald after I configured it

1. Reload the client. Most clients pick up config changes on restart, not live.
2. Check the file your client actually reads. Some editors have multiple config scopes (user / project / workspace) and the client may be looking at a different one. Run `herald/install --client=<name>` to see the canonical path Herald writes to.
3. Check Herald's invocation. From your project root:

   ```bash
   ddev exec /var/www/html/craft herald/serve --help
   ```

   If `herald/serve` isn't a recognised action, the plugin isn't installed in the playground / project Herald is pointing at. Re-run `ddev craft plugin/install herald`.

4. Check the Craft logs at `storage/logs/web.log` (or `phpstorm.log` / `craft.log`). MCP-level errors land in the `herald` log channel.

### The `docker exec` command in the snippet refers to a project name I don't recognise

Auto-detection uses the `DDEV_PROJECT` env variable. If you're outside a DDEV shell when running `herald/install`, override it: `ddev craft herald/install --ddevProject=myproject`.

### `craft_exec` only returns "dry-run" output

That's by design. `execDryRunDefault` is `true` by default (one of six security gates around `craft_exec`). Pass `confirm: true` in your tool call to actually evaluate the expression. Destructive expressions (`delete*`, `drop*`, `truncate*`) require both `confirm: true` and `dangerous: true`. See [`SECURITY.md`](SECURITY.md#craft_exec-six-security-gates) for the full gate model.

### Continue.dev doesn't pick up the standalone YAML

Continue.dev reads standalone block files from `~/.continue/mcpServers/`. Confirm the file exists at `~/.continue/mcpServers/herald.yaml` and contains `schema: v1` at the top. If your Continue install is older, it may not read this directory. Fall back to the manual snippet and add the entry under `mcpServers:` in your existing `~/.continue/config.yaml`.

### Anything else

Open an issue at [github.com/craftpulse/craft-herald/issues](https://github.com/craftpulse/craft-herald/issues). Please include:

- Your OS and shell
- The MCP client and its version
- Output of `ddev craft herald/install --client=<your client>` so we can see the resolved invocation
- Any relevant lines from `storage/logs/web.log` (the `herald` channel)
