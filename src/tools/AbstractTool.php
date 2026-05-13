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
 * @since  5.0.0
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
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * Default: no output schema declared. Override per tool when the
     * caller benefits from advertising response shape (Pro tools, tools
     * with strictly-shaped output the LLM can validate against).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     *
     * Default: every tool registers. Pro write tools override to gate
     * visibility on Craft permissions for the current user.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function shouldRegister(): bool
    {
        return true;
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
     * @since  5.0.0
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
     * @since  5.0.0
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
     * @since  5.0.0
     */
    protected function _mode(array $arguments): ?string
    {
        $mode = $arguments['mode'] ?? null;
        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    /**
     * Clamp the caller-supplied `limit` to the tool's `[1, $max]` band,
     * falling back to `$default` when no value was supplied. Concrete
     * tools pass their own `DEFAULT_LIMIT` / `MAX_LIMIT` constants so
     * each tool keeps tool-specific bounds.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _limit(array $arguments, int $default, int $max): int
    {
        $limit = (int) ($arguments['limit'] ?? $default);
        return max(1, min($max, $limit));
    }

    /**
     * Floor the caller-supplied `offset` to zero. Tools use this with a
     * matching `_limit()` call for paginated envelopes.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _offset(array $arguments): int
    {
        return max(0, (int) ($arguments['offset'] ?? 0));
    }

    /**
     * Return the caller-supplied `with` argument as a list of eager-load
     * handles. Strings are trimmed of empties; non-array `with` is
     * normalised to `[]`. Content tools forward this list to the
     * `ElementSerializer` so the LLM can opt in to related-field
     * expansion per call.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _eagerHandles(array $arguments): array
    {
        $with = $arguments['with'] ?? [];
        if (!is_array($with)) {
            return [];
        }

        return array_values(array_filter(
            $with,
            static fn(mixed $h): bool => is_string($h) && $h !== '',
        ));
    }
}
