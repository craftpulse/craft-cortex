<?php

namespace craftpulse\cortex\prompts;

/**
 * =========================================================================
 * Base class for MCP prompts.
 *
 * Provides a sensible default for `getArguments()` — bundled prompts
 * take none — and leaves everything else to concrete classes.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
abstract class AbstractPrompt implements PromptInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Default: prompt takes no arguments. Override to declare
     * `PromptArgument` entries when a future prompt needs them.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getArguments(): array
    {
        return [];
    }
}
