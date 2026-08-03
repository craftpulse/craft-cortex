<?php

/**
 * =========================================================================
 * FiberProgressBridge tests — isolated coverage against a synthetic
 * event emitter (a tiny `yii\base\Component` subclass that fires N
 * per-row events and returns a final value).
 *
 * The bridge is exercised here BEFORE the production wiring on
 * `Resave::stream()` so a regression in the Fiber surface surfaces at
 * the unit layer, not via a flaky resave integration test.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\db\QueryAbortedException;
use craftpulse\herald\tests\Tools\Support\Fixtures\FiberEmitterFixture;
use craftpulse\herald\tests\Tools\Support\Fixtures\FiberEmitterFixtureEvent;
use craftpulse\herald\tools\support\FiberProgressBridge;
use yii\base\Event;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Build a bridge around the synthetic emitter. The bridge yields one
 * frame per emitter event; the emitter fires `EVENT_ROW` once per
 * `iterations`, then returns a totals string.
 *
 * @return array{0: FiberProgressBridge, 1: FiberEmitterFixture}
 */
function _herald_bridge_fixture(int $iterations, bool $cancellable = true): array
{
    $emitter = new FiberEmitterFixture();
    $bridge = new FiberProgressBridge(
        FiberEmitterFixture::class,
        FiberEmitterFixture::EVENT_ROW,
        static fn(object $event) => $event instanceof FiberEmitterFixtureEvent
            ? ['progress' => $event->position, 'total' => $event->total, 'message' => "row {$event->position}"]
            : null,
        static fn() => $emitter->run($iterations, $cancellable),
        new QueryAbortedException(),
    );
    return [$bridge, $emitter];
}

/**
 * Drain a generator into an array.
 *
 * @return array<int,array<string,mixed>>
 */
function _herald_bridge_drain(Generator $gen): array
{
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $gen->next();
    }
    return $frames;
}

// -----------------------------------------------------------------------------
// Tests
// -----------------------------------------------------------------------------

it('yields one frame per event and returns the blocking call value', function() {
    [$bridge, $emitter] = _herald_bridge_fixture(3);

    $gen = $bridge->run(static fn() => false);
    $frames = _herald_bridge_drain($gen);

    expect($frames)->toHaveCount(3);
    expect($frames[0])->toMatchArray(['progress' => 1, 'total' => 3, 'message' => 'row 1']);
    expect($frames[1])->toMatchArray(['progress' => 2, 'total' => 3, 'message' => 'row 2']);
    expect($frames[2])->toMatchArray(['progress' => 3, 'total' => 3, 'message' => 'row 3']);

    expect($gen->getReturn())->toBe('done:3');
    expect($emitter->aborted)->toBeFalse();
});

it('detaches the listener after the blocking call completes', function() {
    [$bridge] = _herald_bridge_fixture(2);

    $gen = $bridge->run(static fn() => false);
    _herald_bridge_drain($gen);

    // Fire another event from a fresh emitter — no leaked handler from
    // the bridge run should react. We assert no exception is raised
    // and the class-level event handlers slot is empty for our key.
    expect(Event::hasHandlers(FiberEmitterFixture::class, FiberEmitterFixture::EVENT_ROW))->toBeFalse();
});

it('shortcuts on cancellation via $fiber->throw and re-engages the host catch-block', function() {
    [$bridge, $emitter] = _herald_bridge_fixture(10);

    // Cancel after the second frame: the parent generator polls
    // `$shouldCancel` BEFORE each resume, so flipping the flag on yield
    // index 2 throws `QueryAbortedException` into the Fiber on the
    // third would-be iteration.
    $emitted = 0;
    $shouldCancel = static function() use (&$emitted): bool {
        return $emitted >= 2;
    };

    $gen = $bridge->run($shouldCancel);
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $emitted++;
        $gen->next();
    }

    expect(count($frames))->toBeGreaterThanOrEqual(2);
    expect(count($frames))->toBeLessThan(10);
    expect($emitter->aborted)->toBeTrue();
    // The blocking call caught `QueryAbortedException` and continued to
    // its post-loop return — `getReturn()` exposes whatever the host
    // returned after the unwind. Mirrors `Elements::resaveElements()`:
    // line 1677's catch-block swallows the exception and the method
    // falls through to fire `EVENT_AFTER_RESAVE_ELEMENTS` + return void.
    expect($gen->getReturn())->toBe('done:10');
});

it('drains the fiber to termination on cancel when the catch path re-suspends, never leaking the listener', function() {
    $emitter = new FiberEmitterFixture();
    $bridge = new FiberProgressBridge(
        FiberEmitterFixture::class,
        FiberEmitterFixture::EVENT_ROW,
        static fn(object $event) => $event instanceof FiberEmitterFixtureEvent
            ? ['progress' => $event->position, 'total' => $event->total]
            : null,
        // The wrapped service emits one more event from inside its
        // QueryAbortedException catch block — suspending the Fiber again
        // after the cancel throw. A break-on-cancel bridge would abandon
        // the Fiber here and leak the listener.
        static fn() => $emitter->runThenEmitOnCancel(10),
        new QueryAbortedException(),
    );

    // Cancel after the second yielded frame.
    $emitted = 0;
    $shouldCancel = static function() use (&$emitted): bool {
        return $emitted >= 2;
    };

    $gen = $bridge->run($shouldCancel);
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $emitted++;
        $gen->next();
    }

    // (a) The generator completed without error and surfaced the host's
    // post-unwind return value.
    expect($emitter->aborted)->toBeTrue();
    expect($gen->getReturn())->toBe('done:10');

    // (b) The class-level listener is gone — the Fiber's `finally`
    // ran during the post-cancel drain. Firing the event again must NOT
    // re-enter the (now-terminated) Fiber, which would raise a
    // `FiberError`; assert no handler is registered and no throw.
    expect(Event::hasHandlers(FiberEmitterFixture::class, FiberEmitterFixture::EVENT_ROW))->toBeFalse();
    $postRun = new FiberEmitterFixtureEvent();
    $postRun->position = 99;
    $postRun->total = 10;
    expect(static fn() => $emitter->trigger(FiberEmitterFixture::EVENT_ROW, $postRun))
        ->not->toThrow(\Throwable::class);

    // (c) Only the two pre-cancel frames were yielded onward — the
    // catch-path emit (position -1) and the post-run emit (position 99)
    // never reached the consumer.
    expect($frames)->toHaveCount(2);
    expect($frames[0]['progress'])->toBe(1);
    expect($frames[1]['progress'])->toBe(2);
});

it('skips emit when eventToFrame returns null', function() {
    $emitter = new FiberEmitterFixture();
    $bridge = new FiberProgressBridge(
        FiberEmitterFixture::class,
        FiberEmitterFixture::EVENT_ROW,
        // Even positions yield null — only odd positions surface a frame.
        static fn(object $event) => $event instanceof FiberEmitterFixtureEvent && $event->position % 2 === 1
            ? ['progress' => $event->position]
            : null,
        static fn() => $emitter->run(4, true),
        new QueryAbortedException(),
    );

    $gen = $bridge->run(static fn() => false);
    $frames = _herald_bridge_drain($gen);

    expect($frames)->toHaveCount(2);
    expect($frames[0])->toBe(['progress' => 1]);
    expect($frames[1])->toBe(['progress' => 3]);
    expect($gen->getReturn())->toBe('done:4');
});

it('propagates an uncaught exception from the blocking call', function() {
    $emitter = new FiberEmitterFixture();
    $bridge = new FiberProgressBridge(
        FiberEmitterFixture::class,
        FiberEmitterFixture::EVENT_ROW,
        static fn(object $event) => $event instanceof FiberEmitterFixtureEvent
            ? ['progress' => $event->position]
            : null,
        // Throw a RuntimeException that the host does NOT catch.
        static fn() => $emitter->failAfter(2),
        new QueryAbortedException(),
    );

    $gen = $bridge->run(static fn() => false);

    $caught = null;
    try {
        while ($gen->valid()) {
            $gen->next();
        }
    } catch (\RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught->getMessage())->toBe('synthetic blocking-call failure');
});
