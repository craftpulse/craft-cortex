<?php

namespace craftpulse\cortex\tools\dev;

use Craft;
use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsOpenWorld;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ConsoleRunner;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `craft_command` tool — allowlisted Craft / Yii console-command runner.
 *
 * Dispatches a console route through Craft's internal runner — never
 * `proc_open`, `shell_exec`, `exec`, `passthru`, `popen`, or backticks.
 * The allowlist is enforced at the tool layer before dispatch: a
 * non-allowlisted command never reaches the `runAction()` call.
 *
 * Allowlist is split into two arrays per `docs/plans/gate-8.md`
 * locked decision 14:
 *
 *   - `Settings::$allowedCommands`     — content-level patterns, always admitted.
 *   - `Settings::$adminLevelCommands`  — admin-level patterns, admitted ONLY
 *                                        when
 *                                        `Craft::$app->getConfig()->getGeneral()
 *                                        ->allowAdminChanges === true`.
 *
 * Allowlist precedence (highest → lowest):
 *   1. `config/cortex.php` overrides (standard Craft pattern; auto-merged).
 *   2. Project config under `plugins.cortex.settings.allowedCommands` /
 *      `adminLevelCommands`.
 *   3. Defaults baked into `Settings`.
 *   4. Runtime DB overrides (admin-editable, auto-expiring) layered on top
 *      of the content-level set.
 *
 * Patterns use `fnmatch()` semantics — `resave/*` matches any
 * `resave/<x>` route, `up` matches only the literal `up` command.
 *
 * Admin-changes-denied rejections still write a `cortex_invocations`
 * row with `kind=tool_error` (the audit-log seam from Gate 7.5
 * audit-logs every thrown `ToolException` automatically — no manual
 * write needed). The audit trail captures both successful boundary
 * crossings and rejected boundary attempts.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[Title('Run Craft Command')]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CraftCommand extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'craft_command';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Run an allowlisted Craft / Yii console command in-process. Required ' .
            '`command` is the route (e.g. `cache/flush-all`, `migrate/up`, ' .
            '`project-config/apply`, `resave/entries`). Optional `options` is an ' .
            'object whose keys map to the controller\'s public option properties ' .
            '(e.g. `{section: "news"}`). Use `mode: "list"` to see the active ' .
            'allowlist patterns. Returns the dispatched route, exit code, captured ' .
            'output, matched allowlist pattern, and any error.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['list', 'run'])
                ->description('Optional. `list` returns the active allowlist; `run` (default) dispatches.'),
            'command' => Schema::string()
                ->description('Console route to dispatch. Required when mode=run.'),
            'options' => Schema::object()
                ->additionalProperties(true)
                ->description('Object of CLI option overrides bound to the controller properties.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments) ?? 'run';
        $effective = $this->_allowlist();

        if ($mode === 'list') {
            return [
                'mode' => 'list',
                'patterns' => array_values($effective),
                'count' => count($effective),
            ];
        }

        if ($mode !== 'run') {
            throw new ToolException("Unknown mode: '{$mode}'. Allowed: list, run.");
        }

        $command = isset($arguments['command']) && is_string($arguments['command']) && $arguments['command'] !== ''
            ? $arguments['command']
            : null;
        if ($command === null) {
            throw new ToolException('`command` is required for mode=run.');
        }

        // Normalise: strip leading slashes that the LLM might tack on.
        $command = ltrim($command, '/');

        // Content-level patterns admit regardless of `allowAdminChanges`;
        // admin-level patterns admit only when the host flag is true.
        // Distinguishing the two paths lets us return a precise
        // `-32002` rejection that names `allowAdminChanges` as the
        // reason when the only matching pattern is admin-level and the
        // flag is off.
        $matched = $this->_matchPattern($command, $this->_contentPatterns());

        if ($matched === null) {
            $adminMatch = $this->_matchPattern($command, $this->_adminPatterns());

            if ($adminMatch !== null && !Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
                throw new ToolException(
                    "Command '{$command}' matches admin-level pattern '{$adminMatch}', but " .
                    '`allowAdminChanges` is false on this install. Admin-level routes ' .
                    '(project-config, migrations, scaffolding, schema DDL) refuse to dispatch ' .
                    'unless `allowAdminChanges = true` in `config/general.php`.',
                );
            }

            $matched = $adminMatch;
        }

        if ($matched === null) {
            throw new ToolException(
                "Command '{$command}' is not in the allowlist. Allowed patterns: " .
                implode(', ', $effective) .
                '. Edit `cortex.allowedCommands` (or `cortex.adminLevelCommands`) in project config or ' .
                '`config/cortex.php` to extend it.',
            );
        }

        $options = $this->_options($arguments);

        $result = ConsoleRunner::run($command, $options);

        return [
            'mode' => 'run',
            'command' => $command,
            'matchedPattern' => $matched,
            'options' => $options,
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'error' => $result['error'],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Effective allowlist for the current request — the union of
     * content-level patterns, runtime overrides, and admin-level
     * patterns (when `allowAdminChanges` is true). Mirrors what
     * `Allowlist::getEffective()` exposes to the rest of the plugin
     * (notably `InitialContext.allowlist`) so the LLM-visible view
     * and the dispatch gate agree.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _allowlist(): array
    {
        $patterns = Plugin::getInstance()->allowlist->getEffective();

        return array_values(array_filter(
            $patterns,
            static fn($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Content-level patterns only — `Settings::$allowedCommands` plus
     * active runtime overrides. Always admitted regardless of the
     * host's `allowAdminChanges` flag. Separating this from
     * `_allowlist()` lets `execute()` distinguish "admin-level pattern
     * blocked by `allowAdminChanges = false`" from "no matching
     * pattern at all" so the `-32002` rejection message can name the
     * exact cause.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _contentPatterns(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $defaults = $settings->allowedCommands;
        $overridePatterns = array_map(
            static fn(array $row): string => (string) $row['pattern'],
            Plugin::getInstance()->allowlist->getActiveOverrides(),
        );

        return array_values(array_unique(array_filter(
            array_merge($defaults, $overridePatterns),
            static fn($p): bool => is_string($p) && $p !== '',
        )));
    }

    /**
     * Admin-level patterns only — `Settings::$adminLevelCommands`. Used
     * by `execute()` to detect "this command would have matched if
     * `allowAdminChanges` were true" so the rejection message can name
     * the config flag.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _adminPatterns(): array
    {
        $patterns = Plugin::getInstance()->getSettings()->adminLevelCommands;

        return array_values(array_filter(
            $patterns,
            static fn($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Match the given route against the allowlist using fnmatch (glob)
     * semantics. Returns the first matching pattern or null.
     *
     * @param string[] $patterns
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _matchPattern(string $command, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $command)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * Coerce the `options` argument into a string-keyed array suitable
     * for `Craft::$app->runAction($route, $params)` — Yii's controller
     * option binding wants a flat key/value map.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _options(array $arguments): array
    {
        $options = $arguments['options'] ?? [];
        if (!is_array($options)) {
            return [];
        }

        $clean = [];
        foreach ($options as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            $clean[$k] = $v;
        }

        return $clean;
    }
}
