<?php

/**
 * =========================================================================
 * Tests for the stdio transport's broken-pipe detection (FIX 4).
 *
 * When the MCP client closes the read end of the pipe, `fwrite()`
 * returns `false`; the loop must stop rather than spin on a dead
 * descriptor. `_writeResponse()` reports the write outcome as a bool so
 * `actionIndex()` can break. Sending a real SIGTERM is impractical in a
 * unit test, so the signal path is implemented + guarded but not
 * asserted here; the `fwrite()===false` branch IS unit-tested.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\console\controllers\ServeController;

/**
 * Invoke `_writeResponse()` against the supplied stream and return its
 * boolean result.
 *
 * @param resource $stream
 * @param array<string,mixed> $response
 */
function _cortex_write($stream, array $response): bool
{
    $controller = new ServeController('cortex/serve', Craft::$app);
    $ref = new ReflectionMethod($controller, '_writeResponse');
    $ref->setAccessible(true);
    return $ref->invoke($controller, $stream, $response);
}

it('returns true on a healthy writable stream', function() {
    $stream = fopen('php://temp', 'r+');
    $ok = _cortex_write($stream, ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

    rewind($stream);
    $written = stream_get_contents($stream);
    fclose($stream);

    expect($ok)->toBeTrue()
        ->and(trim($written))->toBe('{"jsonrpc":"2.0","id":1,"result":[]}');
});

it('returns false when the write fails on a closed read-end (broken pipe)', function() {
    // A stream opened read-only fails on fwrite() the same way a pipe
    // whose read end has closed does — the write returns false.
    $stream = fopen('php://temp', 'r');
    $ok = @_cortex_write($stream, ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
    fclose($stream);

    expect($ok)->toBeFalse();
});
