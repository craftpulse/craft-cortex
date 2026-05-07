<?php

namespace craftpulse\cortex\tools;

/**
 * =========================================================================
 * Base class for MCP tools.
 *
 * Provides defaults for the input schema (object with no properties) and
 * helpers shared across schema-tool implementations: handle extraction,
 * count-mode detection, mode-param extraction. Concrete tools override
 * `getName`, `getDescription`, `getInputSchema`, and `execute`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
abstract class AbstractTool implements ToolInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Default schema: object with no required properties. Override per
     * tool to expose `handle`, `count`, `mode`, etc.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Return the optional `handle` argument as a string, or null if not
     * present / not a string. Tools that support list-OR-single mode use
     * this to switch behaviour.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function _handle(array $arguments): ?string
    {
        $handle = $arguments['handle'] ?? null;
        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Return whether the caller asked for count-only output.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function _isCount(array $arguments): bool
    {
        return ($arguments['count'] ?? false) === true;
    }

    /**
     * Return the optional `mode` argument as a string, or null if not
     * present. Tools with multiple operation modes (e.g. `fields` with
     * `mode: usage`) use this to dispatch.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function _mode(array $arguments): ?string
    {
        $mode = $arguments['mode'] ?? null;
        return is_string($mode) && $mode !== '' ? $mode : null;
    }
}
