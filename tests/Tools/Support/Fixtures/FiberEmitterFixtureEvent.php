<?php

namespace craftpulse\herald\tests\Tools\Support\Fixtures;

use yii\base\Event;

/**
 * =========================================================================
 * Synthetic event payload for `FiberEmitterFixture`. Carries the
 * 1-indexed row position and total row count — the shape the bridge's
 * `eventToFrame` closure consumes when verifying the per-row Fiber
 * surface in isolation.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class FiberEmitterFixtureEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var int 1-indexed row position the emitter just produced.
     */
    public int $position = 0;

    /**
     * @var int Total row count for the current emit pass.
     */
    public int $total = 0;
}
