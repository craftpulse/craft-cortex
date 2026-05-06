<?php

namespace craftpulse\cortex\resources;

/**
 * =========================================================================
 * Base class for MCP resources.
 *
 * Provides defaults for content types that apply across all Phase 1
 * resources. Skills are exclusively Markdown, so `text/markdown` is
 * the default MIME type — concrete classes override only when they
 * surface non-Markdown content.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
abstract class AbstractResource implements ResourceInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Default: every Phase 1 resource is a Markdown document.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getMimeType(): string
    {
        return 'text/markdown';
    }
}
