<?php

/**
 * =========================================================================
 * Tests for the stdio transport's fatal-error shutdown handler (FIX 3).
 *
 * A true PHP fatal (memory exhaustion, E_ERROR) bypasses every
 * try/catch in the dispatch loop, so the client would hang on a dead
 * pipe with no terminal response. `_handleFatalShutdown()` writes one
 * final JSON-RPC -32603 (carrying the in-flight request id) on fatal
 * shutdown, and is a no-op on clean shutdown. We drive the shutdown
 * body directly via reflection — actually crashing PHP isn't
 * unit-testable, but the emit decision is.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\console\controllers\ServeController;
use craftpulse\herald\mcp\Server;

/**
 * Build a controller with private state primed for the shutdown body,
 * invoke `_handleFatalShutdown()` against a `php://temp` stdout, and
 * return the decoded envelope (or null when nothing was written).
 *
 * @param array{type:int,message:string,file:string,line:int}|null $lastError
 * @return array<string,mixed>|null
 */
function _herald_drive_shutdown(bool $inFlight, int|string|null $id, ?array $lastError, bool $alreadyEmitted = false): ?array
{
    $controller = new ServeController('herald/serve', Craft::$app);

    $ref = new ReflectionClass($controller);
    foreach (['_inFlight' => $inFlight, '_inFlightRequestId' => $id, '_shutdownEmitted' => $alreadyEmitted] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($controller, $value);
    }

    $stdout = fopen('php://temp', 'r+');

    $method = $ref->getMethod('_handleFatalShutdown');
    $method->setAccessible(true);
    $method->invoke($controller, $stdout, $lastError);

    rewind($stdout);
    $raw = stream_get_contents($stdout);
    fclose($stdout);

    if (trim($raw) === '') {
        return null;
    }
    return json_decode(trim($raw), true);
}

function _herald_fatal(): array
{
    return ['type' => E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => 'x', 'line' => 1];
}

it('emits a -32603 carrying the in-flight id on a fatal mid-dispatch', function() {
    $envelope = _herald_drive_shutdown(inFlight: true, id: 99, lastError: _herald_fatal());

    expect($envelope)->toBeArray()
        ->and($envelope['jsonrpc'])->toBe('2.0')
        ->and($envelope['id'])->toBe(99)
        ->and($envelope['error']['code'])->toBe(Server::ERR_INTERNAL);
});

it('carries a null id when the in-flight request had none', function() {
    $envelope = _herald_drive_shutdown(inFlight: true, id: null, lastError: _herald_fatal());

    expect($envelope)->toBeArray()
        ->and($envelope['id'])->toBeNull()
        ->and($envelope['error']['code'])->toBe(Server::ERR_INTERNAL);
});

it('is a no-op on clean shutdown (no last error)', function() {
    expect(_herald_drive_shutdown(inFlight: true, id: 1, lastError: null))->toBeNull();
});

it('is a no-op when the last error is non-fatal', function() {
    $warning = ['type' => E_WARNING, 'message' => 'undefined', 'file' => 'x', 'line' => 1];
    expect(_herald_drive_shutdown(inFlight: true, id: 1, lastError: $warning))->toBeNull();
});

it('is a no-op when no request was in flight', function() {
    expect(_herald_drive_shutdown(inFlight: false, id: 1, lastError: _herald_fatal()))->toBeNull();
});

it('does not double-emit when the guard is already set', function() {
    expect(_herald_drive_shutdown(inFlight: true, id: 1, lastError: _herald_fatal(), alreadyEmitted: true))->toBeNull();
});
