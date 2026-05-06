<?php

namespace craftpulse\cortex\tools\support;

use php_user_filter;

/**
 * =========================================================================
 * Stream filter that captures (and suppresses) writes to STDOUT/STDERR.
 *
 * Phase 1 stdio MCP runs as a single console process where `STDOUT` IS
 * the JSON-RPC channel — so any console controller dispatched in-process
 * via `Craft::$app->runAction(...)` would corrupt that channel by
 * writing progress to the same descriptor.
 *
 * The filter intercepts writes on the stream it's attached to, appends
 * them to a per-session buffer, and short-circuits the underlying write.
 * `ConsoleRunner` attaches the filter for the duration of a dispatch
 * and detaches it after, so JSON-RPC output passes through unfiltered
 * outside of tool dispatch.
 *
 * Registered with `stream_filter_register('cortex.capture', ...)` lazily
 * by `ConsoleRunner::run()`.
 *
 * Phase 2 HTTP transport runs in PHP-FPM where this isn't an issue —
 * but we'll keep using the same helper for consistency and isolation.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class StdoutCaptureFilter extends php_user_filter
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Accumulated captured output for the current dispatch.
     *             Cleared by `ConsoleRunner::start()`, drained by
     *             `ConsoleRunner::end()`. A single static buffer is fine
     *             because stdio MCP serves one request at a time.
     */
    public static string $buffer = '';

    /**
     * @var bool Whether captures should suppress the underlying write.
     *           When `false`, the filter is a no-op pass-through (writes
     *           land on the underlying fd as normal). Toggled by
     *           `ConsoleRunner` around the dispatch boundary.
     */
    public static bool $capturing = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if (self::$capturing) {
                self::$buffer .= $bucket->data;
                $consumed += $bucket->datalen;
                // Don't append to $out — suppress the underlying write.
                continue;
            }

            // Pass-through when not capturing.
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
