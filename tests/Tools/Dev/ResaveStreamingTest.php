<?php

/**
 * =========================================================================
 * `Resave::stream()` happy-path + rejection + cancellation tests
 * (Gate 8.9a).
 *
 * Runs against the playground's `minorHeroes` section with `limit: 5`
 * so each test exercises the full Fiber-bridge → resave pipeline in
 * sub-second wall-clock while still emitting multiple progress frames.
 *
 * The non-streaming `Resave::execute()` surface has its own
 * `ResaveTest.php` — this file is the streaming-only surface.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\ToolException;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Drain a Resave stream into `[frames, terminal]`. Terminal is the
 * generator's `getReturn()` value.
 *
 * @return array{0: array<int,array<string,mixed>>, 1: array<string,mixed>}
 */
function _cortex_resave_drain(Generator $gen): array
{
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $gen->next();
    }
    $terminal = $gen->getReturn();
    return [$frames, is_array($terminal) ? $terminal : []];
}

// -----------------------------------------------------------------------------
// Happy path
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('resave');
});

it('streams progress frames against the minorHeroes seed and returns a structured terminal envelope', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'limit' => 5,
    ], $ctx);

    [$frames, $terminal] = _cortex_resave_drain($gen);

    expect($frames)->not->toBeEmpty();
    // The 60Hz throttle can collapse some frames into one, but the
    // terminal envelope's `processed` is the ground-truth count.
    expect(count($frames))->toBeLessThanOrEqual(5);

    // Monotonically non-decreasing progress.
    $prev = -1;
    foreach ($frames as $frame) {
        expect($frame)->toHaveKeys(['progress', 'total', 'message']);
        expect($frame['progress'])->toBeGreaterThanOrEqual($prev);
        $prev = $frame['progress'];
    }

    expect($terminal)->toHaveKeys([
        'success',
        'type',
        'route',
        'total',
        'processed',
        'succeeded',
        'failed',
        'cancelled',
        'results',
    ]);
    expect($terminal['type'])->toBe('entries');
    expect($terminal['route'])->toBe('resave/entries');
    expect($terminal['total'])->toBe(5);
    expect($terminal['processed'])->toBe(5);
    expect($terminal['succeeded'] + $terminal['failed'])->toBe(5);
    expect($terminal['cancelled'])->toBeFalse();
    expect($terminal['results'])->toBeArray();
});

it('emits a single zero-row frame when no elements match', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'status' => 'no_such_status_value_xyz',
    ], $ctx);

    [$frames, $terminal] = _cortex_resave_drain($gen);

    expect($frames)->toHaveCount(1);
    expect($frames[0])->toMatchArray(['progress' => 0, 'total' => 0]);
    expect($terminal)->toMatchArray([
        'total' => 0,
        'processed' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'cancelled' => false,
        'success' => true,
    ]);
    expect($terminal['results'])->toBe([]);
});

// -----------------------------------------------------------------------------
// Rejections
// -----------------------------------------------------------------------------

it('rejects queue: true', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'queue' => true,
    ], $ctx);
    // The generator is not started until iterated.
    iterator_to_array($gen);
})->throws(ToolException::class, '`queue: true` is not supported');

it('rejects field-rewrite options', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'set' => 'titleField',
        'to' => "''",
    ], $ctx);
    iterator_to_array($gen);
})->throws(ToolException::class, 'field-rewrite options');

it('rejects an unknown type on the streaming path', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream(['type' => 'sandwiches'], $ctx);
    iterator_to_array($gen);
})->throws(ToolException::class, "Unknown type 'sandwiches'");

it('requires the type argument on the streaming path', function() {
    $ctx = new InvocationContext();
    $gen = $this->tool->stream([], $ctx);
    iterator_to_array($gen);
})->throws(ToolException::class, '`type` is required');

// -----------------------------------------------------------------------------
// Cancellation
// -----------------------------------------------------------------------------

it('returns the streaming terminal envelope with cancelled=true when the token is flipped between yields', function() {
    $ctx = new InvocationContext();
    $token = $ctx->getCancellationToken();

    $gen = $this->tool->stream([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'limit' => 10,
    ], $ctx);

    $frames = [];
    $iteration = 0;
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $iteration++;
        if ($iteration === 2) {
            // Flip the token between iterations — the next bridge
            // resume() poll observes the cancellation and throws
            // QueryAbortedException into the Fiber.
            $token->cancel('test');
        }
        $gen->next();
    }

    $terminal = $gen->getReturn();
    expect($terminal)->toBeArray();
    expect($terminal['cancelled'])->toBeTrue();
    expect($terminal['success'])->toBeFalse();
    // Partial work — strictly less than the requested limit.
    expect($terminal['processed'])->toBeGreaterThanOrEqual(2);
    expect($terminal['processed'])->toBeLessThan(10);
});

// -----------------------------------------------------------------------------
// `execute()` ↔ `stream()` shape parity — `execute()` drains `stream()` and
// returns the same terminal envelope, matching the BulkEntries precedent.
// -----------------------------------------------------------------------------

it('non-streaming execute() drains stream() and returns the same terminal envelope', function() {
    $arguments = [
        'type' => 'entries',
        'section' => 'minorHeroes',
        'limit' => 1,
    ];

    $ctx = new InvocationContext();
    [, $streamTerminal] = _cortex_resave_drain($this->tool->stream($arguments, $ctx));
    $executeResult = $this->tool->execute($arguments);

    // Both surfaces emit the unified shape — no legacy `exitCode` /
    // `output` / `options` keys on either path.
    expect($executeResult)->toHaveKeys([
        'success', 'type', 'route', 'total', 'processed', 'succeeded', 'failed', 'cancelled', 'results',
    ]);
    expect($executeResult)->not->toHaveKeys(['exitCode', 'output', 'options', 'error']);

    // Structural parity: same keys, same shape. We don't compare the
    // values directly because two consecutive resaves can record
    // different `processed` counts when the underlying seed shifts —
    // but the keyset and per-call invariants match.
    expect(array_keys($executeResult))->toBe(array_keys($streamTerminal));
    expect($executeResult['type'])->toBe('entries');
    expect($executeResult['route'])->toBe('resave/entries');
    expect($executeResult['cancelled'])->toBeFalse();
});
