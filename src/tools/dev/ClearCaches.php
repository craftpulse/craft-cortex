<?php

namespace craftpulse\cortex\tools\dev;

use craft\helpers\FileHelper;
use craft\utilities\ClearCaches as ClearCachesUtility;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ToolException;
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
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class ClearCaches extends AbstractTool
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
        return 'clear_caches';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
                    'description' => 'Optional. `list`, `all` (default), or a specific cache key.',
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
        $mode = $this->_mode($arguments) ?? 'all';
        $options = ClearCachesUtility::cacheOptions();

        if ($mode === 'list') {
            return [
                'mode' => 'list',
                'keys' => array_map(
                    static fn (array $o): array => [
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
            $available = array_map(static fn (array $o): string => $o['key'], $options);
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
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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
