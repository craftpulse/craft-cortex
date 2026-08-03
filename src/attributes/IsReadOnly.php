<?php

namespace craftpulse\herald\attributes;

use Attribute;

/**
 * =========================================================================
 * Marks a tool as non-mutating — emits MCP `readOnlyHint`.
 *
 * Spec-compliant clients use the hint to label tools in UIs and skip
 * confirmation prompts. Default value is `true` (the common case);
 * pass `false` to explicitly advertise that a tool DOES mutate state.
 * Absence of the attribute leaves the hint unspecified.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsReadOnly
{
    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly bool $value = true,
    ) {
    }
}
