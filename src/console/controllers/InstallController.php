<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;

/**
 * =========================================================================
 * Print copy-paste MCP client config snippets for cortex's stdio
 * transport.
 *
 * Read-only — never writes files. The user copies the relevant snippet
 * into their client's config and reloads. Phase 3 ships an interactive
 * GUI wizard that scans for installed clients and writes their configs
 * directly; this is the no-frills console form.
 *
 * Supported clients (Phase 1):
 *   - Claude Desktop (Anthropic, macOS / Windows / Linux)
 *   - Claude Code (Anthropic CLI)
 *   - Cursor
 *   - Continue.dev (VS Code extension)
 *   - Cline (VS Code extension; flakier MCP support)
 *   - Zed
 *   - Windsurf (Codeium fork of VS Code)
 *
 * Run with `--client=<name>` to print one client's snippet, or `--all`
 * (default) for every supported client. Use `--ddev` to emit the
 * docker-exec form (cortex running inside a DDEV container, MCP client
 * on the host).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class InstallController extends Controller
{
    // Constants
    // =========================================================================

    private const CLIENTS = [
        'claude-desktop' => 'Claude Desktop',
        'claude-code' => 'Claude Code',
        'cursor' => 'Cursor',
        'continue' => 'Continue.dev',
        'cline' => 'Cline (VS Code)',
        'zed' => 'Zed',
        'windsurf' => 'Windsurf',
    ];

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Restrict output to a single client (handle from
     *                  the CLIENTS map above). Default: print all.
     */
    public ?string $client = null;

    /**
     * @var bool Whether to use the DDEV `docker exec` form. Auto-true if
     *          the running process detects DDEV, false otherwise.
     */
    public bool $ddev = false;

    /**
     * @var string|null Override the DDEV project name (used in the
     *                  `docker exec -i ddev-<name>-web ...` form). Auto-
     *                  detected from `DDEV_PROJECT` if available.
     */
    public ?string $ddevProject = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'client',
            'ddev',
            'ddevProject',
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return array<string,string>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'client',
            'd' => 'ddev',
        ]);
    }

    /**
     * Print MCP client config snippets for cortex.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionIndex(): int
    {
        $isDdev = $this->ddev || $this->_detectDdev();
        $project = $this->ddevProject ?? getenv('DDEV_PROJECT') ?: 'PROJECT';
        $command = $this->_buildCommand($isDdev, $project);

        $this->stdout("\n");
        $this->stdout("Cortex — MCP client configuration\n", \yii\helpers\Console::FG_PURPLE);
        $this->stdout(str_repeat('=', 70) . "\n\n");

        if ($isDdev) {
            $this->stdout("Detected DDEV environment — using `docker exec` invocation form.\n", \yii\helpers\Console::FG_GREY);
        } else {
            $this->stdout("Running in a non-DDEV PHP context — using the direct `php` form.\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("If your MCP client lives on the host but Craft runs in DDEV, re-run\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("with --ddev (or set DDEV_PROJECT) to emit the docker-exec form.\n", \yii\helpers\Console::FG_GREY);
        }
        $this->stdout("\n");
        $this->stdout("Command: ");
        $this->stdout("{$command}\n\n", \yii\helpers\Console::FG_CYAN);

        $clients = $this->client !== null
            ? array_intersect_key(self::CLIENTS, [$this->client => true])
            : self::CLIENTS;

        if ($clients === []) {
            $this->stderr("Unknown client '{$this->client}'. Allowed: " . implode(', ', array_keys(self::CLIENTS)) . "\n", \yii\helpers\Console::FG_RED);
            return ExitCode::USAGE;
        }

        foreach ($clients as $handle => $label) {
            $this->_printClientBlock($handle, $label, $command);
        }

        $this->stdout("\nDone. Reload the affected clients to pick up the new server.\n\n", \yii\helpers\Console::FG_GREEN);
        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _detectDdev(): bool
    {
        return getenv('IS_DDEV_PROJECT') === 'true' || getenv('DDEV_HOSTNAME') !== false;
    }

    /**
     * Build the command string the MCP client invokes. DDEV mode wraps
     * the call in `docker exec -i`; otherwise we fall back to a `php`
     * direct call against the project's `craft` script.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildCommand(bool $isDdev, string $project): string
    {
        if ($isDdev) {
            // DDEV mounts the project root at /var/www/html in the web
            // container. If your `craft` script lives in a subdirectory
            // (e.g. `cms/craft`) adjust the path manually after pasting.
            return "docker exec -i ddev-{$project}-web php /var/www/html/craft cortex/serve";
        }

        return 'php /PATH/TO/PROJECT/craft cortex/serve';
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _printClientBlock(string $handle, string $label, string $command): void
    {
        $this->stdout(str_repeat('-', 70) . "\n");
        $this->stdout("{$label}\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout(str_repeat('-', 70) . "\n");

        $body = $this->buildSnippet($handle, $command);
        $this->stdout($body . "\n\n");
    }

    /**
     * Build the per-client copy-paste block as a plain string. Public so
     * the test suite can assert each client's output without driving the
     * action through Yii's console output. Returns an empty string for
     * unknown clients.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function buildSnippet(string $handle, string $command): string
    {
        return match ($handle) {
            'claude-desktop' => $this->_claudeDesktop($command),
            'claude-code' => $this->_claudeCode($command),
            'cursor' => $this->_cursor($command),
            'continue' => $this->_continue($command),
            'cline' => $this->_cline($command),
            'zed' => $this->_zed($command),
            'windsurf' => $this->_windsurf($command),
            default => '',
        };
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _claudeDesktop(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Config file:
  macOS:   ~/Library/Application Support/Claude/claude_desktop_config.json
  Windows: %APPDATA%\\Claude\\claude_desktop_config.json
  Linux:   ~/.config/Claude/claude_desktop_config.json

Add to the `mcpServers` object:

{$snippet}
TXT;
    }

    /**
     * Claude Code uses `claude mcp add --transport stdio <name> -- <cmd...>`.
     * The `--` separator is mandatory: it prevents Claude Code from
     * parsing the wrapped command's flags (e.g. `-i` on `docker exec`) as
     * its own. Verified against https://code.claude.com/docs/en/mcp.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _claudeCode(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Run from your project directory:

  claude mcp add --transport stdio cortex -- {$command}

Or add to project-level `.mcp.json`:

{$snippet}
TXT;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _cursor(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Config file: ~/.cursor/mcp.json (or project-level .cursor/mcp.json)

Add to the `mcpServers` object:

{$snippet}
TXT;
    }

    /**
     * Continue.dev moved to YAML config under `mcpServers:` at the top
     * level of `~/.continue/config.yaml` — the previous JSON form under
     * `experimental.modelContextProtocolServers` is deprecated. Verified
     * against https://docs.continue.dev/customize/deep-dives/mcp.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _continue(string $command): string
    {
        $snippet = $this->_yamlSnippet($command);

        return <<<TXT
Config file: ~/.continue/config.yaml (or project-level .continue/config.yaml).
Standalone form: ~/.continue/mcpServers/cortex.yaml with the same `mcpServers:`
list at top level.

Add under `mcpServers:`:

{$snippet}
TXT;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _cline(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Config file (macOS):
  ~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json

Note: Cline's MCP support has been less stable than other clients. If config doesn't apply, restart VS Code.

Add to the `mcpServers` object:

{$snippet}
TXT;
    }

    /**
     * Zed uses `context_servers` at the top level of `settings.json`. The
     * legacy `assistant.mcp_servers` key is not the current shape.
     * Verified against https://zed.dev/docs/ai/mcp.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _zed(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Config file: ~/.config/zed/settings.json

Add under `context_servers` at the top level:

{$snippet}
TXT;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _windsurf(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Config file: ~/.codeium/windsurf/mcp_config.json

Add to the `mcpServers` object:

{$snippet}
TXT;
    }

    /**
     * Build the canonical JSON snippet for the `mcpServers` shape used
     * by most clients. Clients that diverge get a custom rendering above.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _jsonSnippet(string $command): string
    {
        $parts = $this->_splitCommand($command);
        $argsJson = json_encode(array_slice($parts, 1), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $cmdJson = json_encode($parts[0]);

        return <<<JSON
  "cortex": {
    "command": {$cmdJson},
    "args": {$argsJson}
  }
JSON;
    }

    /**
     * Build the YAML snippet for clients that consume YAML config. Used
     * by Continue.dev. Indentation is two-space per the YAML 1.2 spec
     * conventions Continue's config follows.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _yamlSnippet(string $command): string
    {
        $parts = $this->_splitCommand($command);
        $cmd = $parts[0];
        $args = array_slice($parts, 1);

        $argsYaml = '';
        foreach ($args as $arg) {
            $argsYaml .= "      - " . $this->_yamlScalar($arg) . "\n";
        }

        return rtrim(
            "  - name: cortex\n" .
            "    command: " . $this->_yamlScalar($cmd) . "\n" .
            "    args:\n" .
            $argsYaml,
            "\n",
        );
    }

    /**
     * Quote a YAML scalar when it contains characters that would otherwise
     * need escaping (whitespace, leading dash, special chars). Keeps
     * the snippet copy-paste safe without overquoting plain identifiers.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _yamlScalar(string $value): string
    {
        if ($value === '' || preg_match('/[\s:#\\\\"\'@`,\\[\\]\\{\\}]/', $value) || str_starts_with($value, '-')) {
            return '"' . addcslashes($value, '"\\') . '"';
        }
        return $value;
    }

    /**
     * Split a command-line string into [command, ...args] parts. Falls
     * back to a single-element array if the string can't be parsed.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _splitCommand(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));
        return $parts === false ? [$command] : array_values(array_filter($parts, static fn ($p): bool => $p !== ''));
    }
}
