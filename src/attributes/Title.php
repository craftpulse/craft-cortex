<?php

namespace craftpulse\herald\attributes;

use Attribute;

/**
 * =========================================================================
 * Sets a human-readable title for a tool — emits MCP `title`.
 *
 * Spec-compliant clients display the title in tool-picker UIs and
 * confirmation dialogs. The title is purely advisory; the tool's
 * machine name (`getName()`) remains the lookup key.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Title
{
    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly string $value,
    ) {
    }
}
