<?php

namespace craftpulse\cortex\tools\dev;

use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsOpenWorld;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ConsoleRunner;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `craft_command` tool — allowlisted Craft / Yii console-command runner.
 *
 * Dispatches a console route through Craft's internal runner — never
 * `proc_open`, `shell_exec`, `exec`, `passthru`, `popen`, or backticks
 * (PLANNING.md 4.9). The allowlist is enforced at the tool layer
 * before dispatch: a non-allowlisted command never reaches the
 * `runAction()` call.
 *
 * Allowlist precedence (highest → lowest):
 *   1. `config/cortex.php` overrides (standard Craft pattern; auto-merged).
 *   2. Project config under `plugins.cortex.settings.allowedCommands`.
 *   3. Defaults baked into `Settings::$allowedCommands`.
 *
 * Runtime DB overrides (admin-editable, auto-expiring) ship in Gate 6.
 *
 * Patterns use `fnmatch()` semantics — `resave/*` matches any
 * `resave/<x>` route, `up` matches only the literal `up` command.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
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
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'craft_command';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['list', 'run'],
                    'description' => 'Optional. `list` returns the active allowlist; `run` (default) dispatches.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Console route to dispatch. Required when mode=run.',
                ],
                'options' => [
                    'type' => 'object',
                    'description' => 'Object of CLI option overrides bound to the controller properties.',
                    'additionalProperties' => true,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments) ?? 'run';
        $patterns = $this->_allowlist();

        if ($mode === 'list') {
            return [
                'mode' => 'list',
                'patterns' => array_values($patterns),
                'count' => count($patterns),
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

        $matched = $this->_matchPattern($command, $patterns);
        if ($matched === null) {
            throw new ToolException(
                "Command '{$command}' is not in the allowlist. Allowed patterns: " .
                implode(', ', $patterns) .
                '. Edit `cortex.allowedCommands` in project config or `config/cortex.php` to extend it.',
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
     * Effective allowlist for the current request — settings first, then
     * any `config/cortex.php` override (Craft already merges the file
     * into the settings model at boot, so this is one lookup).
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _allowlist(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $patterns = $settings->allowedCommands;

        return array_values(array_filter(
            $patterns,
            static fn ($p): bool => is_string($p) && $p !== '',
        ));
    }

    /**
     * Match the given route against the allowlist using fnmatch (glob)
     * semantics. Returns the first matching pattern or null.
     *
     * @param string[] $patterns
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
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
