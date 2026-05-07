<?php

namespace craftpulse\cortex\attributes;

use Attribute;

/**
 * =========================================================================
 * Marks a tool as stdio-transport-only — cortex-specific (not an MCP
 * spec annotation).
 *
 * The dispatcher hard-rejects HTTP requests for stdio-only tools at
 * the tool-call layer with a JSON-RPC error, regardless of token scope
 * or permission. `craft_exec` is the canonical example: PHP eval is
 * trustable to a local user but never to a remote authenticated user,
 * so the gate is enforced at the transport boundary.
 *
 * Default value is `true`; pass `false` only if you specifically need
 * to override an inherited stdio-only flag (uncommon).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsStdioOnly
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
