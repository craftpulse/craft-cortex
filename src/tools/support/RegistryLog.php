<?php

namespace craftpulse\cortex\tools\support;

use Craft;

/**
 * =========================================================================
 * Shared logger for registry-collision warnings.
 *
 * The `Tools`, `Prompts`, and `Resources` services all apply
 * first-registration-wins semantics on their respective keys (tool name,
 * prompt name, resource URI, resource-template URI). Each emits a
 * `Craft::warning()` line in the `cortex` channel when a third-party
 * registration tries to shadow an existing entry. This helper centralises
 * the wording so all three services use the same shape — locked by
 * `project_locked_decisions.md` under "Collision behavior".
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class RegistryLog
{
    // Public Methods
    // =========================================================================

    /**
     * Emit a first-registration-wins collision warning to Craft's
     * logger under the `cortex` category. `$kind` and `$field` describe
     * what kind of registry rejected the duplicate (e.g. "Tool" /
     * "name", "Resource" / "URI"). `$key` is the colliding value.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function collision(
        string $kind,
        string $field,
        string $key,
        object $existing,
        object $incoming,
    ): void {
        Craft::warning(
            sprintf(
                '%s %s collision on "%s" — first registration (%s) wins; ignoring %s.',
                $kind,
                $field,
                $key,
                $existing::class,
                $incoming::class,
            ),
            'cortex',
        );
    }
}
