<?php

namespace craftpulse\herald\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Wires MCP clients up to herald.
 *
 * Prints copy-paste MCP client config snippets (`herald/install`), writes them
 * directly to the client's config file (`herald/install/apply`), and auto-detects
 * installed clients on the host filesystem (`herald/install/detect` and
 * `herald/install/auto`).
 *
 * The default action prints snippets for the user to copy into their MCP
 * client's config manually (read-only, and it never writes). The `apply`
 * action resolves the client's per-platform config path, merges a herald
 * entry into it, and writes the result atomically with a timestamped
 * backup. The snippet form is the documented manual fallback whenever
 * apply can't proceed (config absent, parent dir missing, herald entry
 * already present without `--force`).
 *
 * The `detect` action scans the host for installed MCP clients and prints
 * a status table. The `auto` action runs detection plus per-client
 * confirm-and-apply in one pass: the "I installed herald,
 * now wire it up everywhere" flow. **Both refuse to run from inside any
 * container** (DDEV, plain Docker, Lando, Sail, Podman, LXC, Kubernetes)
 * because the container can't see the host's `/Applications`, `~/.cursor`,
 * etc., and false negatives are worse than no detection. The manual
 * `herald/install` snippet flow works from inside DDEV and stays the
 * documented fallback.
 *
 * Supported clients:
 *   - Claude Desktop (Anthropic, macOS / Windows / Linux)
 *   - Claude Code (Anthropic CLI; project-scoped `.mcp.json`)
 *   - Cursor (global `~/.cursor/mcp.json`)
 *   - Continue.dev (VS Code extension; standalone `herald.yaml`)
 *   - Cline (VS Code extension; VS Code globalStorage path)
 *   - Zed (`settings.json`, `context_servers` key)
 *   - Windsurf (Codeium fork of VS Code; `mcp_config.json`)
 *
 * Usage:
 *   herald/install                                  # print all snippets
 *   herald/install --client=<name>                  # print one snippet
 *   herald/install/apply --client=<name>            # write one config file
 *   herald/install/detect                           # status table (host-only)
 *   herald/install/auto                             # detect + confirm + apply per client (host-only)
 *
 * Use `--ddev` to emit the docker-exec invocation form (herald running
 * inside DDEV, MCP client on host). Auto-detected from `DDEV_PROJECT` /
 * `IS_DDEV_PROJECT` when present.
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class InstallController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * How many timestamped `<file>.bak.*` backups to retain per client
     * config when re-applying. Older backups beyond this count are
     * pruned on each write so the config directory doesn't grow without
     * bound.
     *
     * @since 5.0.0
     */
    private const BACKUP_KEEP = 5;

    private const CLIENTS = [
        'claude-desktop' => 'Claude Desktop',
        'claude-code' => 'Claude Code',
        'cursor' => 'Cursor',
        'continue' => 'Continue.dev',
        'cline' => 'Cline (VS Code)',
        'zed' => 'Zed',
        'windsurf' => 'Windsurf',
    ];

    /**
     * Per-client detection signals consumed by `detect()`. Three optional
     * keys:
     *
     *   - `macAppBundle`: `.app` folder name under `/Applications` on macOS.
     *   - `binary`: executable name searched against `getenv('PATH')`. We
     *     walk PATH manually rather than calling `which`/`where` so the
     *     no-shell-exec architecture test stays green.
     *   - `windowsPath`: relative path under `%LOCALAPPDATA%\Programs` on
     *     Windows. (Programs install path; `%APPDATA%` is for config.)
     *
     * Clients without an `app` signal (Continue.dev, Cline — both VS Code
     * extensions) rely on the `configured` signal alone.
     *
     * @var array<string,array<string,string>>
     */
    private const DETECTION = [
        'claude-desktop' => [
            'macAppBundle' => 'Claude.app',
            'windowsPath' => 'Programs\\Claude',
        ],
        'claude-code' => [
            'binary' => 'claude',
        ],
        'cursor' => [
            'macAppBundle' => 'Cursor.app',
            'binary' => 'cursor',
            'windowsPath' => 'Programs\\cursor',
        ],
        // Continue.dev and Cline are VS Code extensions. The extension
        // itself isn't installed as a standalone binary or .app bundle —
        // we lean on the `configured` signal (config directory exists).
        'continue' => [],
        'cline' => [],
        'zed' => [
            'macAppBundle' => 'Zed.app',
            'binary' => 'zed',
        ],
        'windsurf' => [
            'macAppBundle' => 'Windsurf.app',
            'binary' => 'windsurf',
        ],
    ];

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Restrict output to a single client (handle from
     * the CLIENTS map above). Default: print all (index
     * action) / required (apply action).
     */
    public ?string $client = null;

    /**
     * @var bool Whether to use the DDEV `docker exec` form. Auto-true if
     * the running process detects DDEV, false otherwise.
     */
    public bool $ddev = false;

    /**
     * @var string|null Override the DDEV project name (used in the
     * `docker exec -i ddev-<name>-web ...` form). Auto-
     * detected from `DDEV_PROJECT` if available.
     */
    public ?string $ddevProject = null;

    /**
     * @var bool Apply-action only. Overwrite an existing herald entry
     * when one is already registered in the target file. Default
     * refuses (idempotent, so re-running apply is a no-op).
     */
    public bool $force = false;

    /**
     * @var bool Apply-action only. Print the target path and would-be
     * before / after contents without writing or backing up.
     */
    public bool $dryRun = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function options($actionID): array
    {
        $shared = ['client', 'ddev', 'ddevProject'];
        if ($actionID === 'apply') {
            $shared[] = 'force';
            $shared[] = 'dryRun';
        }
        return array_merge(parent::options($actionID), $shared);
    }

    /**
     * @inheritdoc
     *
     * @return array<string,string>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'client',
            'd' => 'ddev',
            'f' => 'force',
        ]);
    }

    /**
     * Print MCP client config snippets for herald.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionIndex(): int
    {
        $isDdev = $this->ddev || $this->_detectDdev();
        $project = $this->ddevProject ?? getenv('DDEV_PROJECT') ?: 'PROJECT';
        $command = $this->_buildCommand($isDdev, $project);

        $this->stdout("\n");
        $this->stdout("Herald: MCP client configuration\n", \yii\helpers\Console::FG_PURPLE);
        $this->stdout(str_repeat('=', 70) . "\n\n");

        if ($isDdev) {
            $this->stdout("Detected DDEV environment, using the `docker exec` invocation form.\n", \yii\helpers\Console::FG_GREY);
        } else {
            $this->stdout("Running in a non-DDEV PHP context, using the direct `php` form.\n", \yii\helpers\Console::FG_GREY);
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

    /**
     * Write the herald MCP server entry directly into the chosen client's
     * config file. Resolves the per-platform path, refuses to ghost-create
     * when the client isn't installed, backs the existing file up to
     * `<file>.bak.<unix-timestamp>`, and writes atomically (temp file +
     * rename) so a crashed write never leaves a half-clobbered config.
     *
     * Behaviour summary:
     *   - `--client=<name>` is required.
     *   - `--dry-run` prints the target path and a before / after diff
     *     without writing anything. Skips the confirm prompt.
     *   - Without `--force`, an existing herald entry causes the action
     *     to refuse, because re-runs are idempotent.
     *   - With `--force`, the existing herald entry is replaced. Other
     *     servers in the file are preserved untouched.
     *   - All writes prompt for `y/N` confirmation. `--interactive=0`
     *     auto-rejects (safe default for CI).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionApply(): int
    {
        if ($this->client === null) {
            $this->stderr("--client=<name> is required for apply.\n", Console::FG_RED);
            $this->stderr("Allowed: " . implode(', ', array_keys(self::CLIENTS)) . "\n", Console::FG_GREY);
            return ExitCode::USAGE;
        }

        if (!isset(self::CLIENTS[$this->client])) {
            $this->stderr(sprintf(
                "Unknown client '%s'. Allowed: %s\n",
                $this->client,
                implode(', ', array_keys(self::CLIENTS)),
            ), Console::FG_RED);
            return ExitCode::USAGE;
        }

        return $this->_applyOne($this->client, skipConfirm: false);
    }

    /**
     * Scan the host filesystem for installed MCP clients and print a
     * status table. **Refuses to run from inside any container** (DDEV,
     * plain Docker, Lando, Sail, Podman, LXC, Kubernetes), because the container
     * can't see the host's `/Applications`, `~/.cursor`, etc. and any
     * detection result would be a false negative.
     *
     * Read-only, and it never writes. Pair with `herald/install/auto` to detect
     * and apply in one pass.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionDetect(): int
    {
        if ($this->_refuseInContainer('detect')) {
            return ExitCode::CONFIG;
        }

        $this->stdout("\n");
        $this->stdout("MCP client detection (host filesystem)\n", Console::FG_PURPLE);
        $this->stdout(str_repeat('=', 70) . "\n\n");

        $any = false;
        foreach (self::CLIENTS as $client => $label) {
            $result = $this->detect($client);
            $anySignal = $result['app'] || $result['configured'];
            if ($anySignal) {
                $any = true;
            }

            $this->stdout(sprintf("  %-20s ", $label));
            $this->_renderSignal('app', $result['app']);
            $this->stdout('  ');
            $this->_renderSignal('configured', $result['configured']);
            $this->stdout("\n");
            if ($result['configPath'] !== null) {
                $this->stdout(sprintf("  %-20s   path: %s\n", '', $result['configPath']), Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        if ($any) {
            $this->stdout("Run `herald/install/auto` to apply herald config to detected clients.\n", Console::FG_GREEN);
        } else {
            $this->stdout("No clients detected on this host.\n", Console::FG_YELLOW);
            $this->stdout("If you have a client installed, run `herald/install` for the manual snippet form.\n", Console::FG_GREY);
        }
        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Detect installed clients and walk them interactively: for each
     * detected client, prompt to apply herald config and run the same
     * write pipeline `actionApply()` uses. **Refuses to run from inside
     * any container** (DDEV, plain Docker, Lando, Sail, Podman, LXC,
     * Kubernetes) for the same reason `actionDetect()` does.
     *
     * Honours `--dry-run` (no writes, prints before/after for each) and
     * `--force` (overwrites an existing herald entry instead of
     * refusing). Per-client confirm prompt is the only prompt; the
     * underlying apply path skips its own confirm since auto already
     * gathered the decision.
     *
     * Exit code: OK when at least one client was applied or skipped
     * cleanly; CONFIG when running inside a container.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function actionAuto(): int
    {
        if ($this->_refuseInContainer('auto')) {
            return ExitCode::CONFIG;
        }

        $detected = [];
        foreach (self::CLIENTS as $client => $label) {
            $result = $this->detect($client);
            if ($result['app'] || $result['configured']) {
                $detected[$client] = $label;
            }
        }

        if ($detected === []) {
            $this->stdout("\nNo MCP clients detected on this host.\n", Console::FG_YELLOW);
            $this->stdout("Run `herald/install` for the manual snippet form if you have a client installed.\n\n", Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("\n");
        $this->stdout("Detected MCP clients:\n", Console::FG_PURPLE);
        foreach ($detected as $client => $label) {
            $this->stdout("  - {$label}\n");
        }
        $this->stdout("\n");

        foreach ($detected as $client => $label) {
            $this->stdout(str_repeat('-', 70) . "\n");
            $this->stdout("{$label}\n", Console::FG_YELLOW);
            $this->stdout(str_repeat('-', 70) . "\n");

            if (!$this->confirm("Apply herald config for {$label}?")) {
                $this->stdout("Skipped.\n\n", Console::FG_GREY);
                continue;
            }

            $exit = $this->_applyOne($client, skipConfirm: true);
            if ($exit !== ExitCode::OK) {
                $this->stderr("Apply failed for {$label} (exit code {$exit}); continuing.\n\n", Console::FG_YELLOW);
            } else {
                $this->stdout("\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Detect whether a given MCP client is installed on this host.
     * Returns three signals in a fixed shape:
     *
     *   - `app` (bool): a strong "the client itself is present" signal.
     *     Looks for the macOS `.app` bundle, the executable on `PATH`,
     *     or the Windows install path under `%LOCALAPPDATA%\Programs`,
     *     in that order. Clients with no `app` signal in `DETECTION`
     *     (Continue.dev, Cline — both VS Code extensions) always
     *     return `false` here.
     *
     *   - `configured` (bool): a weaker "the user has used this client
     *     at some point" signal — the parent of the per-client config
     *     path exists. Useful for VS Code-extension clients where the
     *     extension itself has no detectable presence outside VS Code.
     *
     *   - `configPath` (string|null): the resolved per-platform config
     *     path (or `null` when no path is defined for this platform).
     *     Pulled straight from `resolveConfigPath()` so detection and
     *     apply agree on the target.
     *
     * @internal Public for test access only.
     * @return array{app: bool, configured: bool, configPath: ?string}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function detect(string $client): array
    {
        $signal = self::DETECTION[$client] ?? [];
        $appDetected = false;

        if (isset($signal['macAppBundle']) && PHP_OS_FAMILY === 'Darwin') {
            $appDetected = is_dir('/Applications/' . $signal['macAppBundle']);
        }

        if (!$appDetected && isset($signal['binary'])) {
            $appDetected = $this->_binaryInPath($signal['binary']);
        }

        if (!$appDetected && isset($signal['windowsPath']) && PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            if (is_string($localAppData) && $localAppData !== '') {
                $appDetected = is_dir(rtrim($localAppData, '\\/') . '\\' . $signal['windowsPath']);
            }
        }

        $configPath = $this->resolveConfigPath($client);
        $configured = $configPath !== null && is_dir(dirname($configPath));

        return [
            'app' => $appDetected,
            'configured' => $configured,
            'configPath' => $configPath,
        ];
    }

    /**
     * Resolve the per-platform config path for a client. Returns null if
     * the platform / client combination has no defined path.
     *
     * @internal Public for test access only. Not part of the stable
     *           extension API — third-party plugins should not pin
     *           against this method.
     *
     * Path notes:
     *   - claude-desktop: macOS uses ~/Library/Application Support/Claude,
     *     Windows uses %APPDATA%\Claude, Linux uses ~/.config/Claude (the
     *     community convention; Linux is not officially supported).
     *   - claude-code: project-scoped `.mcp.json` in the current working
     *     directory. The user-scoped `~/.claude.json` form keys per-project
     *     entries on absolute paths and isn't a friendly write target —
     *     project scope is the documented committed-config pattern.
     *   - cursor: ~/.cursor/mcp.json across all platforms (Cursor uses
     *     home-relative paths uniformly).
     *   - continue: standalone `~/.continue/mcpServers/herald.yaml`,
     *     leaving the user's `config.yaml` untouched. Standalone block
     *     files require `name` / `version` / `schema` metadata at the top
     *     level — verified against docs.continue.dev/reference.
     *   - cline: VS Code globalStorage path; brittle (depends on the
     *     extension being installed). Per-platform Code config root.
     *   - zed: ~/.config/zed/settings.json on macOS / Linux; on Windows
     *     `%APPDATA%\Zed\settings.json` per zed.dev/docs/configuring-zed.
     *   - windsurf: ~/.codeium/windsurf/mcp_config.json across platforms.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function resolveConfigPath(string $client): ?string
    {
        $os = PHP_OS_FAMILY; // Darwin / Linux / Windows / BSD / Solaris.
        $home = $this->_homeDir();
        $appData = $this->_appData();

        return match ($client) {
            'claude-desktop' => match ($os) {
                'Darwin' => "{$home}/Library/Application Support/Claude/claude_desktop_config.json",
                'Windows' => $appData !== null
                    ? "{$appData}\\Claude\\claude_desktop_config.json"
                    : null,
                default => "{$home}/.config/Claude/claude_desktop_config.json",
            },
            'claude-code' => getcwd() !== false
                ? rtrim(getcwd(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.mcp.json'
                : null,
            'cursor' => "{$home}/.cursor/mcp.json",
            'continue' => "{$home}/.continue/mcpServers/herald.yaml",
            'cline' => match ($os) {
                'Darwin' => "{$home}/Library/Application Support/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json",
                'Windows' => $appData !== null
                    ? "{$appData}\\Code\\User\\globalStorage\\saoudrizwan.claude-dev\\settings\\cline_mcp_settings.json"
                    : null,
                default => "{$home}/.config/Code/User/globalStorage/saoudrizwan.claude-dev/settings/cline_mcp_settings.json",
            },
            'zed' => match ($os) {
                'Windows' => $appData !== null
                    ? "{$appData}\\Zed\\settings.json"
                    : null,
                default => "{$home}/.config/zed/settings.json",
            },
            'windsurf' => "{$home}/.codeium/windsurf/mcp_config.json",
            default => null,
        };
    }

    /**
     * Build the merged config string for a client given its existing file
     * contents (or null) and the herald invocation command. Returns
     * `[contents, action-summary]` on success or `null` when the herald
     * entry already exists and `--force` is not set.
     *
     * @internal Public for test access only. Not part of the stable
     *           extension API — third-party plugins should not pin
     *           against this method.
     * @return array{0: string, 1: string}|null
     * @throws \RuntimeException When the existing file is malformed.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function buildMergedConfig(string $client, ?string $existing, string $command): ?array
    {
        if ($client === 'continue') {
            return $this->_buildContinueStandalone($existing, $command);
        }
        if ($client === 'zed') {
            return $this->_mergeJsonContextServers($existing, $command);
        }
        return $this->_mergeJsonMcpServers($existing, $command);
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _detectDdev(): bool
    {
        return getenv('IS_DDEV_PROJECT') === 'true' || getenv('DDEV_HOSTNAME') !== false;
    }

    /**
     * Return true when the current process is running inside any container
     * runtime — DDEV, plain Docker / Docker Compose, Podman (with or without
     * Docker compatibility mode), LXC, systemd-nspawn, Kubernetes, or any
     * containerd-based runtime. Four independent signals are ORed together:
     *
     *   1. **DDEV env vars** (`_detectDdev()`) — `IS_DDEV_PROJECT=true` or
     *      `DDEV_HOSTNAME` is set. Covers DDEV and DDEV-managed containers
     *      regardless of the underlying runtime.
     *
     *   2. **`/.dockerenv` sentinel file** — Docker, Docker Compose, Podman
     *      in Docker-compatibility mode, and most Docker-based wrappers
     *      (Lando, Laravel Sail, Docksal) all bind-mount this empty file at
     *      the filesystem root. Podman also creates it in compat mode.
     *
     *   3. **`container` env var** — Set to a non-empty string by Podman in
     *      native (non-Docker-compat) mode and by systemd-nspawn containers.
     *      `getenv()` returns `false` when the var is unset, so we check for
     *      a truthy non-empty string only.
     *
     *   4. **`/proc/self/cgroup` content** — Readable on Linux hosts; not
     *      present on macOS/Windows hosts. The file contains one line per
     *      cgroup entry; when any line contains the tokens `docker`,
     *      `kubepods`, `lxc`, or `containerd` the process is containerised.
     *      We use `file_get_contents` — no shell exec. On runtimes that don't
     *      expose `/proc` (e.g. macOS) the file_get_contents call returns
     *      false, which we treat as "not detected via this signal".
     *
     * `_detectDdev()` is kept as a separate predicate because `_buildCommand()`
     * uses it to decide whether to emit the DDEV-specific `docker exec` form.
     * That path is DDEV-specific; we know the `ddev-<project>-web` naming
     * convention. For plain Docker or other runtimes we don't, so `_buildCommand()`
     * is intentionally left untouched.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _detectContainer(): bool
    {
        if ($this->_detectDdev()) {
            return true;
        }

        if (is_file('/.dockerenv')) {
            return true;
        }

        $containerEnv = getenv('container');
        if (is_string($containerEnv) && $containerEnv !== '') {
            return true;
        }

        $cgroup = @file_get_contents('/proc/self/cgroup');
        if (is_string($cgroup) && preg_match('/docker|kubepods|lxc|containerd/', $cgroup)) {
            return true;
        }

        return false;
    }

    /**
     * Refuse to run the detect-or-auto pipeline when herald is invoked
     * from inside any container (DDEV, plain Docker, Lando, Sail, Podman,
     * LXC, Kubernetes). Detection inside a container walks the container's
     * filesystem, not the host's — `/Applications`, `~/.cursor`, etc. don't
     * exist there, so every signal would be a false negative. Returns true
     * (and emits the message) when the action should bail; false (silent)
     * when it should proceed.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _refuseInContainer(string $action): bool
    {
        if (!$this->_detectContainer()) {
            return false;
        }

        $this->stderr("\n");
        $this->stderr("herald/install/{$action} cannot run from inside a container.\n", Console::FG_RED);
        $this->stderr("\n");
        $this->stderr("The herald process can only see the container's filesystem,\n", Console::FG_GREY);
        $this->stderr("not your host's /Applications, ~/.cursor, etc. Detection from\n", Console::FG_GREY);
        $this->stderr("inside the container would produce false negatives.\n", Console::FG_GREY);
        $this->stderr("\n");
        $this->stderr("Run from your host's PHP instead:\n", Console::FG_GREY);
        $this->stderr("  php /path/to/project/craft herald/install/{$action}\n", Console::FG_CYAN);
        $this->stderr("\n");

        if ($this->_detectDdev()) {
            $this->stderr("Or use the manual snippet form (works from inside DDEV):\n", Console::FG_GREY);
            $this->stderr("  ddev craft herald/install [--client=<name>]\n\n", Console::FG_CYAN);
        } else {
            $this->stderr("Or use the manual snippet form:\n", Console::FG_GREY);
            $this->stderr("  php craft herald/install [--client=<name>]\n\n", Console::FG_CYAN);
        }

        return true;
    }

    /**
     * Apply herald config for a single client. Shared pipeline behind
     * `actionApply()` (interactive single-client) and `actionAuto()`
     * (per-detected-client loop). When `$skipConfirm` is true, the
     * write confirmation prompt is omitted — the caller has already
     * gathered the decision.
     *
     * Pre-conditions enforced by callers (not re-checked here):
     *   - `$client` is a known handle in `self::CLIENTS`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _applyOne(string $client, bool $skipConfirm): int
    {
        $label = self::CLIENTS[$client];
        $path = $this->resolveConfigPath($client);
        if ($path === null) {
            $this->stderr("Could not resolve config path for {$label} on this platform.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $isDdev = $this->ddev || $this->_detectDdev();
        $project = $this->ddevProject ?? getenv('DDEV_PROJECT') ?: 'PROJECT';
        $command = $this->_buildCommand($isDdev, $project);

        $existing = is_file($path) ? @file_get_contents($path) : null;
        if ($existing === false) {
            $this->stderr("Could not read existing config at {$path}.\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        // Precondition for real writes: the parent directory must exist.
        // We don't ghost-create config dirs — their absence is the
        // canonical "client not installed" signal, and silently
        // scaffolding them would mask typos and produce non-functional
        // config alongside an actual install elsewhere on the system.
        // For dry-run, we surface the missing-parent state as a warning
        // and still print the would-be diff — the user is asking what
        // *would* happen, not what *can* happen, and dry-run from inside
        // a container that doesn't see the host config dir is the
        // canonical "preview from a different filesystem" case.
        $parent = dirname($path);
        $parentMissing = !is_dir($parent);
        if ($parentMissing && !$this->dryRun) {
            $this->stderr(sprintf(
                "%s config directory not found at %s.\n",
                $label,
                $parent,
            ), Console::FG_RED);
            $this->stderr(sprintf(
                "Install %s first, or use the manual snippet:\n  ddev craft herald/install --client=%s\n",
                $label,
                $client,
            ), Console::FG_GREY);
            $this->stderr(sprintf(
                "If you're running this inside a container (e.g. DDEV) and your MCP\n" .
                "client lives on the host, the manual snippet form is the right path:\n" .
                "the auto-config writer can only see the container's filesystem.\n",
            ), Console::FG_GREY);
            return ExitCode::CONFIG;
        }

        try {
            $result = $this->buildMergedConfig($client, $existing, $command);
        } catch (\RuntimeException $e) {
            $this->stderr("Failed to parse existing config at {$path}: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($result === null) {
            $this->stderr(sprintf(
                "Herald entry already present at %s. Re-run with --force to overwrite.\n",
                $path,
            ), Console::FG_YELLOW);
            return ExitCode::OK;
        }
        [$newContents, $action] = $result;

        if ($this->dryRun) {
            $this->stdout("Dry run, no changes written.\n\n", Console::FG_PURPLE);
            $this->stdout("Target: ", Console::FG_GREY);
            $this->stdout("{$path}\n");
            $this->stdout("Action: ", Console::FG_GREY);
            $this->stdout("{$action}\n\n");

            if ($parentMissing) {
                $this->stdout("Note: parent directory ", Console::FG_YELLOW);
                $this->stdout("{$parent}", Console::FG_YELLOW);
                $this->stdout(" does not exist on this filesystem.\n", Console::FG_YELLOW);
                $this->stdout("A real write would refuse; this dry-run is a preview only.\n", Console::FG_YELLOW);
                if ($this->_detectContainer()) {
                    $this->stdout("(You're running inside a container. The container's filesystem isn't your host's; copy the\n", Console::FG_GREY);
                    $this->stdout("AFTER block below into your host MCP client config manually.)\n\n", Console::FG_GREY);
                } else {
                    $this->stdout("\n");
                }
            }

            $this->stdout("--- BEFORE ---\n", Console::FG_GREY);
            $this->stdout($existing !== null ? rtrim($existing) . "\n" : "(file does not exist)\n");
            $this->stdout("\n--- AFTER ---\n", Console::FG_GREY);
            $this->stdout(rtrim($newContents) . "\n");
            return ExitCode::OK;
        }

        $this->stdout("Target: ", Console::FG_GREY);
        $this->stdout("{$path}\n");
        $this->stdout("Action: ", Console::FG_GREY);
        $this->stdout("{$action}\n");
        if ($existing !== null) {
            $this->stdout("Backup: ", Console::FG_GREY);
            $this->stdout("<file>.bak.<unix-timestamp>\n");
        }

        if (!$skipConfirm && !$this->confirm("\nWrite herald MCP config to {$path}?")) {
            $this->stderr("Aborted.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        try {
            $this->_writeAtomic($path, $newContents, $existing);
        } catch (\RuntimeException $e) {
            $this->stderr("Write failed: {$e->getMessage()}\n", Console::FG_RED);
            return ExitCode::IOERR;
        }

        $this->stdout(sprintf("\nWrote herald config to %s.\n", $path), Console::FG_GREEN);
        $this->stdout("Reload {$label} to pick up the new server.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Render one detection signal (`app installed` / `configured`) with
     * a green ✓ or grey ✗ glyph plus a short label. Used by
     * `actionDetect()` to keep the table compact while staying readable
     * in monochrome terminals.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _renderSignal(string $kind, bool $detected): void
    {
        $glyph = $detected ? '✓' : '✗';
        $label = $kind === 'app' ? 'installed' : 'configured';
        $colour = $detected ? Console::FG_GREEN : Console::FG_GREY;
        $this->stdout("{$glyph} {$label}", $colour);
    }

    /**
     * Walk `PATH` manually looking for an executable file. We don't
     * shell out to `which` / `where` because the architecture test
     * forbids shell-exec functions — and we'd rather keep that ban
     * absolute than carve out an exception for "but only this one
     * convenient case". Honours `PATHEXT` on Windows for extension-less
     * lookups (`cursor` → `cursor.exe`).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _binaryInPath(string $name): bool
    {
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $extensions = [''];
        if (PHP_OS_FAMILY === 'Windows') {
            $pathExt = getenv('PATHEXT');
            if (is_string($pathExt) && $pathExt !== '') {
                $extensions = array_map(
                    static fn(string $e): string => strtolower(trim($e)),
                    explode(';', $pathExt),
                );
            } else {
                $extensions = ['.exe', '.bat', '.cmd'];
            }
        }

        foreach (explode($separator, $path) as $dir) {
            $dir = trim($dir);
            if ($dir === '' || !is_dir($dir)) {
                continue;
            }
            foreach ($extensions as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($candidate) && is_executable($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the command string the MCP client invokes. DDEV mode wraps
     * the call in `docker exec -i`; otherwise we fall back to a `php`
     * direct call against the project's `craft` script.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _buildCommand(bool $isDdev, string $project): string
    {
        if ($isDdev) {
            // DDEV mounts the project root at /var/www/html in the web
            // container. If your `craft` script lives in a subdirectory
            // (e.g. `cms/craft`) adjust the path manually after pasting.
            return "docker exec -i ddev-{$project}-web php /var/www/html/craft herald/serve";
        }

        return 'php /PATH/TO/PROJECT/craft herald/serve';
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _claudeCode(string $command): string
    {
        $snippet = $this->_jsonSnippet($command);

        return <<<TXT
Run from your project directory:

  claude mcp add --transport stdio herald -- {$command}

Or add to project-level `.mcp.json`:

{$snippet}
TXT;
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _continue(string $command): string
    {
        $snippet = $this->_yamlSnippet($command);

        return <<<TXT
Config file: ~/.continue/config.yaml (or project-level .continue/config.yaml).
Standalone form: ~/.continue/mcpServers/herald.yaml with the same `mcpServers:`
list at top level.

Add under `mcpServers:`:

{$snippet}
TXT;
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _jsonSnippet(string $command): string
    {
        $parts = $this->_splitCommand($command);
        $argsJson = json_encode(array_slice($parts, 1), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $cmdJson = json_encode($parts[0]);

        return <<<JSON
  "herald": {
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
     * @author CraftPulse
     * @since  5.0.0
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
            "  - name: herald\n" .
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _splitCommand(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));
        return $parts === false ? [$command] : array_values(array_filter($parts, static fn($p): bool => $p !== ''));
    }

    /**
     * Resolve the user's home directory across platforms. Tries `$HOME`
     * first (set on POSIX and on Git Bash / WSL on Windows), then falls
     * back to `%USERPROFILE%` on Windows.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _homeDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, DIRECTORY_SEPARATOR);
        }
        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return rtrim($userProfile, DIRECTORY_SEPARATOR);
        }
        // Last resort — exec env with neither HOME nor USERPROFILE is
        // unusual; return empty so the caller produces a malformed path
        // and surfaces the error to the user rather than silently
        // resolving to the filesystem root.
        return '';
    }

    /**
     * Resolve `%APPDATA%` on Windows (used for per-user roaming config:
     * Claude Desktop, Cline, Zed). Returns null on non-Windows platforms
     * or when the env var is missing.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _appData(): ?string
    {
        $appData = getenv('APPDATA');
        if (!is_string($appData) || $appData === '') {
            return null;
        }
        return rtrim($appData, '\\/');
    }

    /**
     * Merge a herald entry into a JSON config file using the `mcpServers`
     * top-level key (Claude Desktop, Claude Code `.mcp.json`, Cursor,
     * Cline, Windsurf).
     *
     * @return array{0: string, 1: string}|null
     * @throws \RuntimeException When the existing file is not valid JSON.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _mergeJsonMcpServers(?string $existing, string $command): ?array
    {
        return $this->_mergeJsonObjectKey($existing, $command, 'mcpServers');
    }

    /**
     * Merge a herald entry into a JSON config file using the top-level
     * `context_servers` key (Zed). Same merge semantics as the
     * `mcpServers` form, just under a different top-level name.
     *
     * @return array{0: string, 1: string}|null
     * @throws \RuntimeException When the existing file is not valid JSON.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _mergeJsonContextServers(?string $existing, string $command): ?array
    {
        return $this->_mergeJsonObjectKey($existing, $command, 'context_servers');
    }

    /**
     * Generic JSON merger — reads the existing file (or starts from `{}`
     * if absent), upserts a `herald` entry under the given top-level
     * server-map key, and returns the re-encoded contents. Other entries
     * in the file are preserved untouched.
     *
     * Refusal semantics: when a `herald` entry already exists and
     * `--force` is not set, returns null so the caller can surface the
     * "already present, use --force" message without silently rewriting
     * the file.
     *
     * @return array{0: string, 1: string}|null
     * @throws \RuntimeException When the existing file is not valid JSON.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _mergeJsonObjectKey(?string $existing, string $command, string $serversKey): ?array
    {
        $data = [];
        if ($existing !== null && trim($existing) !== '') {
            $decoded = json_decode($existing, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('not valid JSON');
            }
            $data = $decoded;
        }

        $servers = $data[$serversKey] ?? [];
        if (!is_array($servers)) {
            throw new \RuntimeException("`{$serversKey}` is not an object");
        }

        $alreadyPresent = array_key_exists('herald', $servers);
        if ($alreadyPresent && !$this->force) {
            return null;
        }

        $parts = $this->_splitCommand($command);
        $servers['herald'] = [
            'command' => $parts[0] ?? $command,
            'args' => array_slice($parts, 1),
        ];

        $data[$serversKey] = $servers;

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('failed to encode merged JSON');
        }

        $action = $alreadyPresent
            ? "overwrite herald entry under `{$serversKey}`"
            : ($existing !== null ? "add herald entry under `{$serversKey}`" : "create file with herald entry under `{$serversKey}`");

        return [$encoded . "\n", $action];
    }

    /**
     * Build the standalone `herald.yaml` body for Continue.dev's
     * `~/.continue/mcpServers/` directory. Standalone block files require
     * `name` / `version` / `schema` metadata at the top level (verified
     * against docs.continue.dev/reference). The file is single-purpose —
     * herald owns it — so refusal applies only when an existing file
     * differs from what we'd write and `--force` is not set.
     *
     * @return array{0: string, 1: string}|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _buildContinueStandalone(?string $existing, string $command): ?array
    {
        $body = $this->_renderContinueYaml($command);

        if ($existing === null) {
            return [$body, 'create herald.yaml standalone block'];
        }

        if (rtrim($existing) === rtrim($body)) {
            // Already in the desired state — treat as "already present"
            // so re-runs are idempotent and produce the same "use --force"
            // hint the JSON path does. (Differing content + no --force
            // also lands here, which is the safe default.)
            return $this->force
                ? [$body, 'overwrite herald.yaml standalone block']
                : null;
        }

        if (!$this->force) {
            return null;
        }
        return [$body, 'overwrite herald.yaml standalone block'];
    }

    /**
     * Render the standalone Continue YAML body. Matches the example shape
     * from docs.continue.dev/reference: top-level `name` / `version` /
     * `schema` + `mcpServers:` list with one entry.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _renderContinueYaml(string $command): string
    {
        $parts = $this->_splitCommand($command);
        $cmd = $parts[0] ?? $command;
        $args = array_slice($parts, 1);

        $argsYaml = '';
        foreach ($args as $arg) {
            $argsYaml .= "      - " . $this->_yamlScalar($arg) . "\n";
        }

        return "name: herald\n"
            . "version: 0.0.1\n"
            . "schema: v1\n"
            . "mcpServers:\n"
            . "  - name: herald\n"
            . "    command: " . $this->_yamlScalar($cmd) . "\n"
            . "    args:\n"
            . $argsYaml;
    }

    /**
     * Atomic write with timestamped backup. Backs the existing file up to
     * `<file>.bak.<unix-timestamp>` (suffixing with a counter on the rare
     * collision when two backups land in the same second), writes the new
     * contents to a sibling temp file, and renames the temp into place so
     * a crashed write can never leave a half-clobbered config.
     *
     * @throws \RuntimeException On any I/O failure during the write path.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _writeAtomic(string $path, string $contents, ?string $existing): void
    {
        $dir = dirname($path);

        // Capture the existing file's permission mode before we touch
        // anything, so the atomic replace preserves an operator's
        // deliberate tightening (e.g. 0600 on a file that may carry a
        // token) instead of silently widening it to the new-file
        // default. Null when there is no existing file to inherit from.
        $existingMode = null;
        if ($existing !== null && PHP_OS_FAMILY !== 'Windows') {
            $perms = @fileperms($path);
            if ($perms !== false) {
                $existingMode = $perms & 0777;
            }
        }

        // Backup the existing file first — if anything later fails, the
        // original is still intact and the .bak gives the user an audit
        // trail of writes.
        if ($existing !== null) {
            $stamp = (string) time();
            $backup = "{$path}.bak.{$stamp}";
            $i = 0;
            while (file_exists($backup)) {
                $i++;
                $backup = "{$path}.bak.{$stamp}.{$i}";
            }
            if (!@copy($path, $backup)) {
                throw new \RuntimeException("could not write backup to {$backup}");
            }
            $this->_pruneBackups($path);
        }

        $temp = @tempnam($dir, 'herald-mcp-');
        if ($temp === false) {
            throw new \RuntimeException("could not create temp file in {$dir}");
        }

        if (@file_put_contents($temp, $contents) === false) {
            @unlink($temp);
            throw new \RuntimeException("could not write to temp file {$temp}");
        }

        // tempnam() creates with 0600. Preserve the prior file's mode
        // when replacing one; otherwise default a new file to 0644 so
        // editors and the host MCP client (same user) can read it
        // without surprise. Skip on Windows where chmod is a no-op for
        // ACL-managed paths.
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($temp, $existingMode ?? 0644);
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException("could not rename temp file into {$path}");
        }
    }

    /**
     * Keep the most recent `self::BACKUP_KEEP` `<path>.bak.*` files and
     * delete the rest, so repeated `--force` applies don't litter the
     * client-config directory unboundedly. Best-effort: a failed unlink
     * is ignored — pruning stale backups must never break a write.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _pruneBackups(string $path): void
    {
        $backups = @glob("{$path}.bak.*");
        if ($backups === false || count($backups) <= self::BACKUP_KEEP) {
            return;
        }

        // Newest first by mtime, then drop everything past the keep count.
        usort($backups, static fn(string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
        foreach (array_slice($backups, self::BACKUP_KEEP) as $stale) {
            @unlink($stale);
        }
    }
}
