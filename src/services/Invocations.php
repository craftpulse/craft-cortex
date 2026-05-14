<?php

namespace craftpulse\cortex\services;

use Carbon\Carbon;
use Craft;
use craftpulse\cortex\db\InvocationQuery;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\Invocation as InvocationRecord;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * =========================================================================
 * HTTP-transport audit-log writer.
 *
 * Subscribes to `InvocationLogger::EVENT_LOG_CALL` (wired in
 * `Plugin::init()`) and persists one row per HTTP `tools/call` to
 * `cortex_invocations`. stdio invocations are silently dropped per the
 * locked decision in `docs/plans/gate-7.md` item 11: stdio is single-
 * process trusted-local; the DB audit log exists for the HTTP
 * transport's forensic surface, not for the in-process surface.
 *
 * Soft-write contract: a DB failure in `record()` MUST NOT break the
 * dispatch. Every exception is caught, logged to Craft's error log under
 * the `cortex.audit` category for operator awareness, and swallowed. The
 * caller (the event listener in `Plugin::init()`) never sees the
 * exception, so the JSON-RPC response continues normally. The KV log
 * line in Craft's file log is the secondary audit trail when the DB
 * write fails.
 *
 * Retention is driven by `Settings::$auditRetentionDays` — null (the
 * default) means rows are retained forever, satisfying the
 * compliance-friendly default per Gate 7.5 decision 10. Setting a
 * positive integer enables `prune()` which deletes older rows during
 * Craft's `gc` sweep.
 *
 * Carbon over `DateTimeHelper` here per the services rule.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Invocations extends Component
{
    // Constants
    // =========================================================================

    /**
     * Log category for soft-write failures. Operators filter on this
     * to distinguish audit-write trouble from regular cortex log
     * traffic. Tailing `cortex.audit` surfaces only failures of the
     * audit DB-write path.
     *
     * @since 5.0.0
     */
    public const LOG_CATEGORY = 'cortex.audit';

    // Public Methods
    // =========================================================================

    /**
     * Persist one structured entry to `cortex_invocations`. Filters
     * out non-HTTP transports up-front per locked decision 11. On any
     * exception, logs to Craft's error log and returns null — the DB
     * write is fire-and-forget from the dispatcher's perspective.
     *
     * The `$entry` shape is the array produced by
     * `InvocationLogger::buildEntry()`. Required keys: `tool`, `kind`,
     * `duration_ms`, `transport`. All other keys are optional and
     * default to null.
     *
     * @param array<string,mixed> $entry
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function record(array $entry): ?InvocationRecord
    {
        $transport = $entry['transport'] ?? null;
        if ($transport !== 'http') {
            // stdio (and the synthetic `unknown` transport used by in-
            // process callers) write to the KV log only — no DB row.
            return null;
        }

        try {
            $record = new InvocationRecord();
            $record->toolName = (string) ($entry['tool'] ?? '');
            $record->kind = (string) ($entry['kind'] ?? '');
            $record->durationMs = (int) ($entry['duration_ms'] ?? 0);
            $record->transport = (string) $transport;
            $record->requestId = $this->_stringOrNull($entry['request_id'] ?? null);
            $record->userId = $this->_intOrNull($entry['user'] ?? null);
            $record->clientName = $this->_stringOrNull($entry['client'] ?? null);
            $record->argsRedacted = $this->_stringOrNull($entry['args'] ?? null);
            $record->responseExcerpt = $this->_stringOrNull($entry['response_excerpt'] ?? null);
            $record->errorClass = $this->_stringOrNull($entry['error_class'] ?? null);
            $record->errorMessage = $this->_stringOrNull($entry['error_message'] ?? null);
            $record->tokenId = $this->_intOrNull($entry['token_id'] ?? null);
            $record->sessionId = $this->_stringOrNull($entry['session_id'] ?? null);
            $record->rateLimitRemaining = $this->_intOrNull($entry['rate_limit_remaining'] ?? null);

            if (!$record->save()) {
                Craft::error(
                    sprintf(
                        'Failed to persist cortex_invocations row: %s',
                        implode(', ', $record->getFirstErrors()),
                    ),
                    self::LOG_CATEGORY,
                );
                return null;
            }

            return $record;
        } catch (Throwable $e) {
            // Catching Throwable here is intentional — the soft-write
            // contract says a DB failure (connection drop, schema
            // drift, FK violation from a deleted-but-cached user)
            // must never propagate to the dispatcher. Log and move
            // on; the KV file-log line is still the secondary audit
            // trail.
            Craft::error(
                sprintf(
                    'Exception while persisting cortex_invocations row: %s — %s',
                    $e::class,
                    $e->getMessage(),
                ),
                self::LOG_CATEGORY,
            );
            return null;
        }
    }

    /**
     * Delete invocation rows older than `Settings::$auditRetentionDays`.
     * Returns the count of deleted rows. No-op (returns 0) when
     * retention is null — the forever-retention default.
     *
     * Called inline during Craft's gc sweep via the listener wired in
     * `Plugin::init()`. The delete is a single indexed range scan on
     * `dateCreated` so it stays cheap even on large audit tables.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function prune(): int
    {
        $retentionDays = Plugin::getInstance()->getSettings()->auditRetentionDays;
        if ($retentionDays === null) {
            return 0;
        }

        $cutoff = Carbon::now()->subDays($retentionDays)->toDateTimeString();
        return (int) InvocationRecord::deleteAll([
            '<', 'dateCreated', new Expression(':cutoff', [':cutoff' => $cutoff]),
        ]);
    }

    /**
     * Open a fluent query against the `cortex_invocations` table. The
     * canonical entry point for CP dashboards (Gate 9), GraphQL
     * resolvers, and any third-party consumer that wants to read the
     * audit log. Returns rows as associative arrays shaped like the
     * `Invocation` Record's `@property` table.
     *
     * Chain filters and finalise with `all()` / `one()` / `count()`:
     *
     *     $rows = Plugin::getInstance()->invocations->find()
     *         ->kind('tool_error')
     *         ->after(Carbon::now()->subDay())
     *         ->orderBy(['dateCreated' => SORT_DESC])
     *         ->limit(50)
     *         ->all();
     *
     * Single source of truth for the table name lives in
     * `InvocationQuery::init()`. Routing every read through this method
     * keeps the call sites column-uniform.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function find(): InvocationQuery
    {
        return new InvocationQuery();
    }

    // Private Methods
    // =========================================================================

    /**
     * Coerce a mixed value to a non-empty string or null. Treats the
     * empty string and the dash placeholder `-` (Apache-style "not
     * applicable") as null so the DB stores semantic absence rather
     * than the wire-format placeholder.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        return (string) $value;
    }

    /**
     * Coerce a mixed value to an int or null. Strings that look like
     * integers are cast; anything else (including the `-` placeholder)
     * becomes null.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '-') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return null;
    }
}
