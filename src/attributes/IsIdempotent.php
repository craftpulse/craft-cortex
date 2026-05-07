<?php

namespace craftpulse\cortex\attributes;

use Attribute;

/**
 * =========================================================================
 * Marks a tool as idempotent — emits MCP `idempotentHint`.
 *
 * Idempotent tools produce the same effect when invoked repeatedly with
 * the same arguments. Spec-compliant clients use this to enable safe
 * retry behaviour. Most read tools are trivially idempotent; mutating
 * tools usually are not unless they implement explicit idempotency
 * keys (cf. the `entry` tool's `idempotencyKey` parameter in Pro).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsIdempotent
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
