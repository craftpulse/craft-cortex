<?php

/**
 * =========================================================================
 * SseEmitter tests — output-buffer teardown on the streaming transport.
 *
 * `disableBuffering()` is the one method on the emitter that touches
 * process-global state: it walks PHP's output-buffer stack so SSE frames
 * reach the wire instead of accumulating in a handler. The hazard being
 * covered here is a handler that refuses to be torn down, which is what
 * `zlib.output_compression = On` installs. A level-polling loop can
 * never escape one, and an infinite loop inside a streaming transport
 * holds the worker until the request times out.
 *
 * The case below reproduces that handler with `ob_start()` flags rather
 * than the ini setting, because `zlib.output_compression` cannot be
 * turned on mid-process. Omitting `PHP_OUTPUT_HANDLER_REMOVABLE` is the
 * same shape: `ob_end_flush()` returns false and `ob_get_level()` does
 * not move.
 *
 * Such a buffer cannot be removed for the remainder of the process, so
 * this case is deliberately the only one that installs one.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\mcp\transport\SseEmitter;

it('disableBuffering() returns when a handler refuses to be torn down', function() {
    $levelBefore = ob_get_level();

    // No PHP_OUTPUT_HANDLER_REMOVABLE: `ob_end_flush()` fails, the level
    // stays put, and `ob_flush()` is the only way to push the contents
    // onward. FLUSHABLE is kept so the buffer stays transparent to
    // everything that runs after this case.
    ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);

    // Assert the reproduction is faithful before relying on it.
    expect(ob_get_level())->toBe($levelBefore + 1);
    expect(@ob_end_flush())->toBeFalse();
    expect(ob_get_level())->toBe($levelBefore + 1);

    (new SseEmitter())->disableBuffering();

    // Reaching this line is the assertion: a level-polling loop never
    // returns here. The stuck handler is still on the stack, which is
    // what proves the walk gave up on a bound rather than on the level
    // reaching zero.
    expect(ob_get_level())->toBe($levelBefore + 1);
});
