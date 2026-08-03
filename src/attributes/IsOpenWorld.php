<?php

namespace craftpulse\herald\attributes;

use Attribute;

/**
 * =========================================================================
 * Marks a tool as interacting with the open world — emits MCP
 * `openWorldHint`.
 *
 * Open-world tools touch resources beyond the local Craft instance:
 * remote APIs, external services, third-party endpoints. Closed-world
 * tools operate exclusively on local Craft state. Most herald tools
 * are closed-world; the GraphQL tool is closed-world (queries the
 * local schema), `craft_exec` is closed-world (local PHP context).
 * Open-world examples would include a docs-search tool calling out to
 * a hosted API.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IsOpenWorld
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
