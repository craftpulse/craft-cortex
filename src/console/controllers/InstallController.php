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

        match ($handle) {
            'claude-desktop' => $this->_claudeDesktop($command),
            'claude-code' => $this->_claudeCode($command),
            'cursor' => $this->_cursor($command),
            'continue' => $this->_continue($command),
            'cline' => $this->_cline($command),
            'zed' => $this->_zed($command),
            'windsurf' => $this->_windsurf($command),
            default => null,
        };

        $this->stdout("\n");
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _claudeDesktop(string $command): void
    {
        $this->stdout("Config file:\n");
        $this->stdout("  macOS:   ~/Library/Application Support/Claude/claude_desktop_config.json\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("  Windows: %APPDATA%\\Claude\\claude_desktop_config.json\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("  Linux:   ~/.config/Claude/claude_desktop_config.json\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("\nAdd to the `mcpServers` object:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _claudeCode(string $command): void
    {
        $parts = $this->_splitCommand($command);
        $this->stdout("Run from your project directory:\n\n");
        $this->stdout("  claude mcp add cortex {$command}\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("\nOr add to project-level `.mcp.json`:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _cursor(string $command): void
    {
        $this->stdout("Config file: ~/.cursor/mcp.json (or project-level .cursor/mcp.json)\n");
        $this->stdout("\nAdd to the `mcpServers` object:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _continue(string $command): void
    {
        $this->stdout("Config file: ~/.continue/config.json\n");
        $this->stdout("\nAdd under `experimental.modelContextProtocolServers`:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _cline(string $command): void
    {
        $this->stdout("Config file (macOS):\n");
        $this->stdout("  ~/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("\nNote: Cline's MCP support has been less stable than other clients. If config doesn't apply, restart VS Code.\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("\nAdd to the `mcpServers` object:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _zed(string $command): void
    {
        $this->stdout("Config file: ~/.config/zed/settings.json\n");
        $this->stdout("\nAdd under `assistant.mcp_servers`:\n\n");
        $this->stdout($this->_jsonSnippet($command));
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _windsurf(string $command): void
    {
        $this->stdout("Config file: ~/.codeium/windsurf/mcp_config.json\n");
        $this->stdout("\nAdd to the `mcpServers` object:\n\n");
        $this->stdout($this->_jsonSnippet($command));
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
