<?php

namespace craftpulse\herald\tools\dev;

use craft\elements\User;
use craft\helpers\FileHelper;
use craft\utilities\ClearCaches as ClearCachesUtility;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use Throwable;
use yii\base\InvalidArgumentException;

/**
 * =========================================================================
 * `clear_caches` tool — convenience wrapper for the cache-clear surface.
 *
 * Mirrors the cache options exposed by Craft's `clear-caches/*` console
 * commands and the CP utility, but delivered as a single MCP tool with
 * structured input/output. Common enough to deserve its own entry point;
 * the LLM otherwise has to remember `craft_command` + the key name.
 *
 * Modes:
 *   - `list` — return the registered cache keys with labels (no clear).
 *   - `all`  — clear every registered cache (default).
 *   - `<key>` — clear a single cache by key (data / asset / compiled-
 *               templates / compiled-classes / cp-resources / temp-files
 *               / transform-indexes / asset-indexing-data, plus any
 *               plugin-registered keys).
 *
 * Mutates state, but no destructiveHint annotation: clearing caches is
 * recoverable and routine.
 *
 * # Authorization
 *
 * Routine does not mean unauthenticated. Over the HTTP transport this
 * tool is gated on `PERMISSION_CLEAR_CACHES` and carries the
 * `system:write` capability scope, so a read-shaped OAuth grant cannot
 * reach it. Until the 2026-08-03 remediation it required neither, which
 * made it a convenience door onto work `craft_command` gates.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[Title('Clear Caches')]
#[IsIdempotent]
class ClearCaches extends AbstractTool
{
    use PermissionedToolTrait;

    // Constants
    // =========================================================================

    /**
     * Permission a caller must hold before this tool is visible in
     * `tools/list` or dispatchable through `tools/call`. Declared here
     * because this class is the enforcement point: `filterFor()` reads it
     * for visibility, `execute()` re-reads it through
     * `PermissionedToolTrait::_assertPermission()` as the
     * defence-in-depth gate, and
     * `PluginTrait::_registerHeraldPermissions()` registers it from this
     * constant so the registration and the two gates cannot drift.
     *
     * Its own permission rather than a reuse of
     * `CraftCommand::PERMISSION_RUN_COMMANDS`. The two authorities are
     * not nested: `clear-caches/*` is absent from the default
     * `Settings::$allowedCommands`, so holding `herald:run-commands` does
     * not already carry this capability, and requiring it here would
     * force an operator who wants an agent to flush a cache after a
     * deploy to also hand over `migrate/up`, `project-config/apply` and
     * `users/create`. Craft's own control panel gates the equivalent
     * utility behind its own narrow `utility:clear-caches`.
     *
     * Narrow is not the same as harmless. Flushing `transform-indexes`,
     * `compiled-templates` or `cp-resources` on a busy install forces
     * wholesale regeneration, so a caller able to loop this keeps the
     * install cold. Grant it as an operational capability, not as a
     * convenience.
     *
     * Permissions here are flat, as they are in Craft: this one does not
     * imply `herald:run-commands` and `herald:run-commands` does not
     * imply this one. An agent that needs both is granted both.
     *
     * @since 5.0.0
     */
    public const PERMISSION_CLEAR_CACHES = 'herald:clear-caches';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Hides the tool from `tools/list` (and refuses `tools/call` with
     * the same "Unknown tool" shape) for any caller that does not hold
     * `PERMISSION_CLEAR_CACHES`.
     *
     * A null user is the stdio path and passes: the security boundary is
     * the HTTP transport, and the stdio caller already holds a shell plus
     * the `craft` console, so gating them buys nothing (ruled
     * 2026-08-02). Admins pass through Craft's own `can()` semantics, but
     * the branch is explicit so a reconfigured permission system cannot
     * quietly lock out admins.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            return true;
        }

        return $user->admin || $user->can(self::PERMISSION_CLEAR_CACHES);
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'clear_caches';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Clear Craft caches. `mode: "list"` returns available cache keys; ' .
            '`mode: "all"` (default) clears every registered cache; `mode: "<key>"` ' .
            'clears one specific cache (e.g. data / asset / compiled-templates / ' .
            'compiled-classes / cp-resources / temp-files / transform-indexes / ' .
            'asset-indexing-data, plus any plugin-registered keys).';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->description('Optional. `list`, `all` (default), or a specific cache key.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        // Gates every mode, `list` included: the registered cache keys
        // enumerate the plugins on the install, which is reconnaissance
        // for a caller not allowed to clear any of them. Same reasoning
        // as `CraftCommand` gating its own `list` mode.
        $this->_assertPermission($arguments);

        $mode = $this->_mode($arguments) ?? 'all';
        $options = ClearCachesUtility::cacheOptions();

        if ($mode === 'list') {
            return [
                'mode' => 'list',
                'keys' => array_map(
                    static fn(array $o): array => [
                        'key' => $o['key'],
                        'label' => $o['label'],
                        'info' => $o['info'] ?? null,
                    ],
                    $options,
                ),
                'count' => count($options),
            ];
        }

        if ($mode === 'all') {
            $cleared = [];
            $errors = [];
            foreach ($options as $option) {
                $error = $this->_clear($option);
                if ($error === null) {
                    $cleared[] = $option['key'];
                } else {
                    $errors[$option['key']] = $error;
                }
            }

            return [
                'mode' => 'all',
                'cleared' => $cleared,
                'errors' => (object) $errors,
                'count' => count($cleared),
            ];
        }

        // Single-key mode.
        $option = $this->_findOption($options, $mode);
        if ($option === null) {
            $available = array_map(static fn(array $o): string => $o['key'], $options);
            throw new ToolException(
                "Unknown cache key '{$mode}'. Available: " . implode(', ', $available) . '.',
            );
        }

        $error = $this->_clear($option);

        return [
            'mode' => $mode,
            'cleared' => $error === null ? [$mode] : [],
            'errors' => $error === null ? (object) [] : (object) [$mode => $error],
            'count' => $error === null ? 1 : 0,
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Whole-tool gate: every mode implies the same single permission, so
     * the resolved arguments never widen or narrow it.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        return [self::PERMISSION_CLEAR_CACHES];
    }

    /**
     * @inheritdoc
     *
     * Matches the message shape `CraftCommand::_assertMayRunCommands()`
     * emits, so both dev-action refusals read identically to the model
     * and to an operator reading the audit log.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        return sprintf(
            'permission denied: clearing Craft caches requires `%s`.',
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Dispatch a single cache option using the same shape Craft's
     * `ClearCacheAction` does — string ⇒ directory path, callable ⇒ run,
     * array ⇒ call_user_func with `params`. Returns `null` on success or
     * a string error.
     *
     * @param array<string,mixed> $option
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _clear(array $option): ?string
    {
        $action = $option['action'] ?? null;
        $params = $option['params'] ?? null;

        try {
            if (is_string($action)) {
                try {
                    FileHelper::clearDirectory($action);
                } catch (InvalidArgumentException) {
                    // The directory doesn't exist — same swallow Craft does.
                }
                return null;
            }

            if (is_array($params) && is_callable($action)) {
                call_user_func_array($action, $params);
                return null;
            }

            if (is_callable($action)) {
                $action();
                return null;
            }

            return 'Unrecognised cache option shape.';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<int,array<string,mixed>> $options
     * @return array<string,mixed>|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _findOption(array $options, string $key): ?array
    {
        foreach ($options as $option) {
            if (($option['key'] ?? null) === $key) {
                return $option;
            }
        }

        return null;
    }
}
