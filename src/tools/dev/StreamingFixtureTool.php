<?php

namespace craftpulse\cortex\tools\dev;

use craft\helpers\App;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\StreamableToolInterface;
use craftpulse\cortex\tools\support\InvocationContext;
use Generator;

/**
 * =========================================================================
 * Streaming fixture tool — `_streaming_test`. Exercises the Gate 7.7
 * Streamable HTTP / SSE dispatcher end-to-end against a deterministic
 * generator that yields three progress frames and returns a terminal
 * payload. Cooperatively checks the `CancellationToken` between
 * yields so `notifications/cancelled` mid-stream short-circuits.
 *
 * Gated behind the `CORTEX_STREAMING_FIXTURE` env var via
 * `shouldRegister()` — production installs leave the env var unset and
 * the tool never appears in `tools/list`. Operators flipping the env
 * var to truthy get a known-shape streaming endpoint they can hit with
 * `curl -H 'Accept: text/event-stream'` to verify the SSE wire is
 * healthy end-to-end (DNS, TLS, nginx buffering, PHP-FPM flush, etc.)
 * without standing up a real Pro-tier streaming tool. Mirrors the
 * test-only fixture in `tests/Tools/Fixtures/StreamingFixtureTool` so
 * the Pest invariants and the production debugging surface stay
 * shape-compatible.
 *
 * Wire shape (per MCP 2025-06-18):
 *
 *   - 3 × `notifications/progress` frames (`progress: 1..3, total: 3`)
 *   - 1 × terminal `tools/call` response `{done: true, progress: 3}`
 *
 * Cancellation: on flipped `CancellationToken`, returns `['cancelled'
 * => true, 'progress' => <last-completed>]` without yielding further.
 * The dispatcher detects the flipped token and emits the terminal
 * `notifications/cancelled` envelope per the streaming spec.
 *
 * 50ms sleep between yields makes the audit row's `durationMs` field
 * meaningful (~150ms total) rather than the sub-millisecond floor a
 * pure CPU-bound generator would produce.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class StreamingFixtureTool extends AbstractTool implements StreamableToolInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return '_streaming_test';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Operator-facing streaming smoke-test fixture (Gate 7.7). Gated behind the CORTEX_STREAMING_FIXTURE env var.';
    }

    /**
     * @inheritdoc
     *
     * Opt-in registration. The fixture only joins the registry when an
     * operator explicitly flips `CORTEX_STREAMING_FIXTURE=1` in their
     * environment — production installs that never set the var never
     * see the tool surface in `tools/list`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function shouldRegister(): bool
    {
        return (bool) App::env('CORTEX_STREAMING_FIXTURE');
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point — used when a client invokes the tool
     * without `Accept: text/event-stream`. Returns the same terminal
     * shape `stream()`'s generator return surfaces so the JSON and SSE
     * paths produce indistinguishable terminal payloads.
     *
     * @param array<string,mixed> $arguments
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        return ['done' => true, 'progress' => 3];
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<int|string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $token = $ctx->getCancellationToken();

        for ($i = 1; $i <= 3; $i++) {
            if ($token->isCancelled()) {
                return ['cancelled' => true, 'progress' => $i - 1];
            }
            yield ['progress' => $i, 'total' => 3];
            // Sleep so the audit row's `durationMs` reflects meaningful
            // wall-clock — without it the fixture finishes in
            // sub-millisecond time and the durationMs column reads `0`.
            usleep(50_000);
        }

        return ['done' => true, 'progress' => 3];
    }
}
