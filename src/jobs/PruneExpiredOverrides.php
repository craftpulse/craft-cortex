<?php

namespace craftpulse\cortex\jobs;

use craft\queue\BaseJob;
use craftpulse\cortex\Plugin;

/**
 * =========================================================================
 * Queue job — hard-delete expired runtime overrides.
 *
 * Cortex schedules this job during Craft's gc routine (registered in
 * `Plugin::init()`) so the runtime-override table doesn't accumulate
 * dead rows over time. Soft-deleted rows are NOT pruned here — those
 * stay around for audit trail. Only `expiresAt < now` rows go.
 *
 * The job is intentionally simple: one delete-all SQL, no batching.
 * Override volume is expected to stay small (admin-issued, not
 * end-user-driven), so a sweep stays in the millisecond range even
 * with hundreds of expired rows.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class PruneExpiredOverrides extends BaseJob
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute($queue): void
    {
        $pruned = Plugin::getInstance()->allowlist->pruneExpired();
        $this->setProgress($queue, 1.0, "Pruned {$pruned} expired override(s).");
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function defaultDescription(): ?string
    {
        return 'Cortex — prune expired runtime overrides';
    }
}
