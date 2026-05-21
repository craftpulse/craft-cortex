<?php

namespace craftpulse\cortex\tests\Tools\Fixtures;

use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\StreamableToolInterface;
use craftpulse\cortex\tools\support\InvocationContext;
use Generator;

/**
 * =========================================================================
 * Test fixture — a `StreamableToolInterface` that yields three
 * deterministic progress frames then returns a terminal payload.
 *
 * Used by the Gate 7.7 SSE streaming tests to drive both
 * `Server::dispatchStreaming()` and the controller's SSE response path
 * against a known-shape generator. Registered per-test via the
 * `EVENT_REGISTER_TOOLS` listener pattern the rest of the suite uses
 * — no boot-time wiring in `Cortex::init()` for tests so the fixture
 * stays out of the production registry.
 *
 * Behaviour:
 *   - Yields `{progress: 1, total: 3}`, then `{progress: 2, total: 3}`,
 *     then `{progress: 3, total: 3}`.
 *   - Between every yield, checks `$ctx->getCancellationToken()
 *     ->isCancelled()`; on a flipped flag returns
 *     `['cancelled' => true]` so the dispatcher's cancellation-mid-
 *     stream test can assert the short-circuit.
 *   - Terminal return: `['done' => true, 'progress' => 3]` carrying
 *     enough surface for the success-path test to assert on.
 *
 * `getName()` uses the underscore-prefix convention every other Gate 7
 * fixture follows (`_throwing_test_tool`, `_fake_streaming_tool`, etc.)
 * so the fixture is trivially distinguishable from production tools in
 * the audit log.
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
        return 'Test fixture for Gate 7.7 streaming infrastructure.';
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point. Used by the `tools/call` JSON path
     * tests so the fixture works on both transports without
     * special-casing the dispatcher. Returns the same terminal shape
     * the streaming path's `Generator::getReturn()` produces.
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
        }

        return ['done' => true, 'progress' => 3];
    }
}
