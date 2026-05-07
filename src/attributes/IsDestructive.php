<?php

namespace craftpulse\cortex\attributes;

use Attribute;

/**
 * =========================================================================
 * Marks a tool as potentially destructive — emits MCP `destructiveHint`.
 *
 * Spec-compliant clients SHOULD warn the user before invoking a tool
 * flagged destructive. Use this for tools that delete records, drop
 * tables, run arbitrary code, or otherwise perform irreversible
 * operations. `craft_exec` is the canonical example.
 *
 * Default value is `true` (the common case); pass `false` to explicitly
 * advertise that a tool is NOT destructive when it might otherwise be
 * inferred to be (e.g. a dev-action tool that's clearly safe).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsDestructive
{
    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    public function __construct(
        public readonly bool $value = true,
    ) {
    }
}
