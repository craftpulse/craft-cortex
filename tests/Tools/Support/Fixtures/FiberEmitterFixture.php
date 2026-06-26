<?php

namespace craftpulse\cortex\tests\Tools\Support\Fixtures;

use craft\db\QueryAbortedException;
use RuntimeException;
use yii\base\Component;

/**
 * =========================================================================
 * Synthetic blocking-call fixture for `FiberProgressBridgeTest`.
 *
 * Stands in for `Craft::$app->getElements()` — a Yii `Component`
 * subclass that fires `EVENT_ROW` once per iteration when `run()` is
 * called. Cancellation cooperation mirrors `Elements::resaveElements()`:
 * the catch-block around the per-row loop swallows
 * `QueryAbortedException` and lets the call return cleanly, so the
 * bridge's `$fiber->throw(QueryAbortedException)` engages a clean
 * unwind exactly like the production wrapping target.
 *
 * `failAfter()` exists for the "uncaught exception propagates out"
 * test — fires N-1 events, throws `RuntimeException` on the Nth row,
 * does NOT catch.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class FiberEmitterFixture extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event FiberEmitterFixtureEvent Fired once per row inside `run()`.
     */
    public const EVENT_ROW = 'cortex.test.fiberEmitterRow';

    // Public Properties
    // =========================================================================

    /**
     * @var bool Flag set when the per-row loop unwound via
     *           `QueryAbortedException` — the bridge cancellation path
     *           sets this so tests can assert clean termination.
     */
    public bool $aborted = false;

    // Public Methods
    // =========================================================================

    /**
     * Fire `EVENT_ROW` `$iterations` times, then return the totals
     * string. Mirrors `Elements::resaveElements()`'s shape: a single
     * try/catch around the loop, with `QueryAbortedException` causing
     * a "fail silently" early return.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function run(int $iterations, bool $cancellable = true): string
    {
        try {
            for ($i = 1; $i <= $iterations; $i++) {
                $event = new FiberEmitterFixtureEvent();
                $event->position = $i;
                $event->total = $iterations;
                $this->trigger(self::EVENT_ROW, $event);
            }
        } catch (QueryAbortedException) {
            if (!$cancellable) {
                throw new RuntimeException('uncancellable emitter received QueryAbortedException');
            }
            $this->aborted = true;
        }

        return "done:{$iterations}";
    }

    /**
     * Fire `EVENT_ROW` until cancelled, then — inside the catch block —
     * fire ONE MORE `EVENT_ROW` before returning. Models a wrapped
     * service whose cancellation catch path emits another event (and so
     * suspends the Fiber again) before unwinding. This is the exact
     * scenario that, under a break-on-cancel bridge, would leave the
     * Fiber suspended and leak the class-level listener.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function runThenEmitOnCancel(int $iterations): string
    {
        try {
            for ($i = 1; $i <= $iterations; $i++) {
                $event = new FiberEmitterFixtureEvent();
                $event->position = $i;
                $event->total = $iterations;
                $this->trigger(self::EVENT_ROW, $event);
            }
        } catch (QueryAbortedException) {
            $this->aborted = true;

            // Fire one more event from inside the catch path. The
            // bridge's handler suspends the Fiber on this frame; a
            // break-on-cancel bridge would never resume past it.
            $cleanup = new FiberEmitterFixtureEvent();
            $cleanup->position = -1;
            $cleanup->total = $iterations;
            $this->trigger(self::EVENT_ROW, $cleanup);
        }

        return "done:{$iterations}";
    }

    /**
     * Fire two rows then throw an uncaught `RuntimeException`. Used to
     * verify the bridge re-throws blocking-call failures rather than
     * swallowing them.
     *
     * @throws RuntimeException Always, after the second emitted row.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function failAfter(int $rowsBeforeFailure): never
    {
        for ($i = 1; $i <= $rowsBeforeFailure; $i++) {
            $event = new FiberEmitterFixtureEvent();
            $event->position = $i;
            $event->total = $rowsBeforeFailure + 1;
            $this->trigger(self::EVENT_ROW, $event);
        }

        throw new RuntimeException('synthetic blocking-call failure');
    }
}
