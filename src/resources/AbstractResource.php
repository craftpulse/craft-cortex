<?php

namespace craftpulse\herald\resources;

/**
 * =========================================================================
 * Base class for MCP resources.
 *
 * Provides defaults for content types that apply across all herald's bundled
 * resources. Skills are exclusively Markdown, so `text/markdown` is
 * the default MIME type — concrete classes override only when they
 * surface non-Markdown content.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
abstract class AbstractResource implements ResourceInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Default: every herald's bundled resource is a Markdown document.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getMimeType(): string
    {
        return 'text/markdown';
    }
}
