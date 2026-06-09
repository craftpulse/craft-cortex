<?php

namespace craftpulse\cortex\tools\support;

use Closure;
use Fiber;
use Generator;
use Throwable;
use yii\base\Event;

/**
 * =========================================================================
 * Fiber bridge that surfaces per-row events fired inside a blocking
 * Craft service call as a yielded progress stream.
 *
 * Use case (Gate 8.9a): wrap `Craft::$app->getElements()->resaveElements()`
 * so the streaming `resave` tool can yield one progress frame per
 * `EVENT_AFTER_RESAVE_ELEMENT` without rewriting Craft's resave loop.
 * The blocking call runs inside a PHP Fiber; the event listener
 * registered for the duration of the call invokes `Fiber::suspend()` on
 * each event, surfacing a progress frame to the parent generator. The
 * parent generator yields the frame onward and `$fiber->resume()`s the
 * Fiber, which lets the inner call iterate to the next row, fire the
 * next event, suspend again, and so on.
 *
 * The bridge is deliberately tool-agnostic — any future
 * "stream-around-a-blocking-Craft-API" tool (export → CSV, search
 * index rebuild, asset transform regeneration, etc.) gets the seam
 * for free by constructing the bridge with a different event +
 * blocking callable.
 *
 * # Cancellation contract
 *
 * Cancellation rides on `$shouldCancel`, a callable consulted by the
 * parent generator BEFORE each `$fiber->resume()`. When it returns
 * true the bridge calls `$fiber->throw($cancelException)` to engage
 * the host service's clean-exit path, then **drains the Fiber to
 * termination** — resuming (and discarding) any further suspended
 * frames the catch path produces — so the Fiber's
 * `finally { Event::off() }` ALWAYS runs. Post-cancel frames are NOT
 * yielded onward; the drain exists solely to let the wrapped service
 * unwind fully and detach the listener. This makes the listener safe
 * to leave even for a wrapped service whose catch block fires another
 * event (and thus suspends again) before returning.
 *
 * **The exception type still matters for a CLEAN exit.** For
 * `Elements::resaveElements()` Craft catches
 * `craft\db\QueryAbortedException` at
 * `vendor/craftcms/cms/src/services/Elements.php:1677` ("fail
 * silently") and unwinds cleanly, letting the Fiber terminate and its
 * return value surface via `getReturn()`. Passing an exception type
 * the wrapped service does NOT catch no longer leaks the listener (the
 * Fiber still unwinds through its `finally`), but the uncaught
 * throwable bubbles out of `run()` to the caller instead of producing
 * a clean return. Callers SHOULD pass the exception type the wrapped
 * service's catch-block recognises; the bridge does not police the
 * choice.
 *
 * # Event listener placement
 *
 * The handler is attached via Yii's class-level `Event::on($senderClass,
 * $eventName, $handler)` for the duration of `run()`. The blocking
 * call MUST fire the event from an instance of `$senderClass` (or a
 * subclass — Yii's `trigger()` walks up). The handler is detached in
 * a `finally` block; an exception bubbling out of the bridge cannot
 * leak the listener registration.
 *
 * # PHP Fiber availability
 *
 * Fibers require PHP >= 8.1. Cortex declares `>=8.2` in composer.json
 * so the language feature is always present.
 *
 * # Hard invariants
 *
 *   - `$eventToFrame` MUST be pure: read fields off the event, return
 *     an array or null. Mutating Craft state from inside the closure
 *     can re-enter the blocking call mid-event with undefined results.
 *   - `$blockingCall` MUST fire events from `$senderClass`. The bridge
 *     does not invoke it for you; it merely runs it inside a Fiber.
 *   - `$cancelException` MUST be of a type the wrapped service catches
 *     internally. See the per-service notes above.
 *   - The bridge yields each non-null frame returned by
 *     `$eventToFrame`. Yielding `null` from the closure suppresses the
 *     frame entirely (the Fiber suspends on it but the parent never
 *     surfaces it onward).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class FiberProgressBridge
{
    // Private Properties
    // =========================================================================

    /**
     * @var class-string Class name of the event sender. Yii's
     *                   class-level event subscription requires the
     *                   fully-qualified class name (not an instance).
     */
    private string $_senderClass;

    /**
     * @var string Event name constant — e.g.
     *             `Elements::EVENT_AFTER_RESAVE_ELEMENT`.
     */
    private string $_eventName;

    /**
     * @var Closure(object): (array<string,mixed>|null) Maps a Yii event
     *               object to a progress frame (or null to skip emit).
     */
    private Closure $_eventToFrame;

    /**
     * @var Closure(): mixed The blocking callable that runs inside the
     *                       Fiber. Returns whatever the wrapped service
     *                       returns; the bridge re-exposes the value as
     *                       the parent generator's `getReturn()`.
     */
    private Closure $_blockingCall;

    /**
     * @var Throwable Exception thrown into the Fiber on cancellation.
     *                MUST be of a type the wrapped service catches
     *                internally — `QueryAbortedException` for
     *                `Elements::resaveElements()`.
     */
    private Throwable $_cancelException;

    // Public Methods
    // =========================================================================

    /**
     * @param class-string                                 $senderClass     Fully-qualified class name of the event sender. Yii subscribes class-level.
     * @param string                                       $eventName       Event name constant.
     * @param Closure(object): (array<string,mixed>|null)  $eventToFrame    Maps each event to a frame array, or null to suppress.
     * @param Closure(): mixed                             $blockingCall    The blocking callable that fires events.
     * @param Throwable                                    $cancelException Exception to throw into the Fiber on cancellation. MUST be catchable by the wrapped service for clean exit.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        string $senderClass,
        string $eventName,
        Closure $eventToFrame,
        Closure $blockingCall,
        Throwable $cancelException,
    ) {
        $this->_senderClass = $senderClass;
        $this->_eventName = $eventName;
        $this->_eventToFrame = $eventToFrame;
        $this->_blockingCall = $blockingCall;
        $this->_cancelException = $cancelException;
    }

    /**
     * Run the wrapped blocking call inside a Fiber, yielding one
     * progress frame per `Fiber::suspend()` (which fires once per
     * caught event). The generator's `getReturn()` exposes whatever
     * the blocking call returned.
     *
     * Cancellation: `$shouldCancel` is polled BEFORE every
     * `$fiber->resume()` (i.e. between rows). On a truthy return the
     * bridge throws `$cancelException` into the Fiber, which lets the
     * wrapped service unwind via its existing catch path, then resumes
     * the Fiber repeatedly — discarding any further suspended frames —
     * until `isTerminated()`. Draining to termination guarantees the
     * Fiber's `finally { Event::off() }` runs even when the catch path
     * suspends again, so the class-level listener never leaks. The
     * Fiber may terminate normally (its return value is captured) or by
     * an uncaught exception (re-thrown out of the bridge to the caller).
     *
     * @param Closure(): bool $shouldCancel Polled before each resume.
     * @return Generator<int,array<string,mixed>,mixed,mixed>
     * @throws Throwable Any uncaught throwable from the blocking call.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function run(Closure $shouldCancel): Generator
    {
        $eventToFrame = $this->_eventToFrame;
        $blockingCall = $this->_blockingCall;
        $senderClass = $this->_senderClass;
        $eventName = $this->_eventName;

        // The handler closure is created here and used both to subscribe
        // and unsubscribe so Yii's `Event::off()` matches the exact
        // callable instance.
        $handler = static function(object $event) use ($eventToFrame): void {
            $frame = $eventToFrame($event);
            if ($frame !== null) {
                Fiber::suspend($frame);
            }
        };

        $fiber = new Fiber(static function() use ($senderClass, $eventName, $handler, $blockingCall): mixed {
            Event::on($senderClass, $eventName, $handler);
            try {
                return $blockingCall();
            } finally {
                Event::off($senderClass, $eventName, $handler);
            }
        });

        // First start: runs the blocking call up to the first
        // `Fiber::suspend()` (or to completion if the call never fires
        // an event). The value returned by `start()` is whatever was
        // passed to `Fiber::suspend()` — i.e. the first progress frame.
        $frame = $fiber->start();

        while (!$fiber->isTerminated()) {
            if ($shouldCancel()) {
                // Throw the cancellation exception INTO the Fiber's
                // suspended frame, then drain the Fiber to termination.
                //
                // The wrapped service's catch-block engages and the
                // call unwinds. `$fiber->throw()` returns the next value
                // the Fiber suspends on (or null on terminated). We do
                // NOT yield post-cancel frames onward, but we MUST keep
                // resuming until `isTerminated()` so the Fiber's
                // `finally { Event::off() }` runs — otherwise a wrapped
                // service whose catch path fires another event (and thus
                // suspends again) would leave the Fiber suspended and the
                // class-level listener leaked for the rest of the process.
                //
                // `throw()`/`resume()` return the value the Fiber next
                // suspends on, or null once it terminates (returns). The
                // handler only suspends with a NON-null frame array (it
                // guards `if ($frame !== null)`), so a null return here
                // unambiguously means "the Fiber returned" — i.e. its
                // `finally` ran and the listener is detached. Looping on
                // that signal needs no explicit iteration cap: every
                // resume advances the wrapped service toward its return,
                // and a well-behaved `finally` guarantees termination.
                $next = $fiber->throw($this->_cancelException);
                while ($next !== null) {
                    $next = $fiber->resume();
                }
                break;
            }

            if (is_array($frame)) {
                yield $frame;
            }

            $frame = $fiber->resume();
        }

        // Capture the blocking call's return value (or null if the
        // Fiber unwound via exception). `getReturn()` throws if the
        // Fiber is not terminated, so the guard above matters.
        if ($fiber->isTerminated()) {
            return $fiber->getReturn();
        }

        return null;
    }
}
