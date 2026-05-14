<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Gate 7.6 migration — adds `rateLimitRemaining` to `cortex_invocations`.
 *
 * Per-row snapshot of the post-consume bucket headroom for the user
 * who made the call. Operators reading the audit table see throttle
 * pressure rise (`rateLimitRemaining` trending down across rows from
 * the same user) BEFORE a `kind=rate_limited` row lands. The column
 * also carries `0` on the throttle row itself so the audit trail
 * captures the exhaustion point with the same shape every other row
 * uses.
 *
 * Nullable: stdio invocations write the KV log line only (no DB row),
 * and pre-7.6 rows from prior gates have no bucket data to back-fill.
 * Both surfaces lean on null. New 7.6+ rows from the HTTP transport
 * carry a populated integer.
 *
 * No new index: the column is an analytic field consumed by the audit
 * dashboard's per-row read, not a query predicate operators filter by.
 * The existing `(userId)` and `(transport, dateCreated)` indexes carry
 * the per-user / per-window lookups the dashboard already uses.
 *
 * Idempotent: bails out if the column already exists. `safeDown()`
 * drops the column without disturbing the rest of the schema.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260514_120300_cortex_invocations_rate_limit extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeUp(): bool
    {
        if ($this->db->columnExists(Table::INVOCATIONS, 'rateLimitRemaining')) {
            return true;
        }

        $this->addColumn(
            Table::INVOCATIONS,
            'rateLimitRemaining',
            $this->integer()->null()->after('sessionId'),
        );

        return true;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::INVOCATIONS, 'rateLimitRemaining')) {
            $this->dropColumn(Table::INVOCATIONS, 'rateLimitRemaining');
        }
        return true;
    }
}
