<?php

namespace craftpulse\herald\events;

use yii\base\Event;

/**
 * =========================================================================
 * Fired by `InvocationLogger::logCall()` after the formatted KV log line
 * has been emitted to Craft's logger.
 *
 * The event carries both the structured entry array (the same shape the
 * formatter consumes — useful for subscribers that want to persist
 * specific fields) and the formatted KV line as a string (useful for
 * SIEM forwarders that want to mirror the bytes Craft already wrote to
 * its file log).
 *
 * Subscribers MUST treat their handler as soft — a thrown exception
 * from within a listener WILL be caught by the dispatcher wrapper
 * (`Herald::init()`'s try/catch around `Invocations::record()`), but
 * subscribers should still avoid raising on logging activity to keep
 * the dispatch hot path tight. Defense in depth, not a primary
 * defense.
 *
 * Locked contract:
 *   - `$entry` carries the structured fields documented by
 *     `InvocationLogger::formatEntry()` — `tool`, `kind`, `duration_ms`,
 *     `transport`, `request_id`, `user`, `client`, `args`,
 *     `error_class`, `error_message`, plus the Gate-7.5 additions
 *     `token_id`, `session_id`, `response_excerpt`.
 *   - `$line` carries the formatted KV string exactly as it was
 *     written to Craft's logger.
 *
 * Adding a new field is backward-compatible (subscribers ignore keys
 * they don't recognise); renaming or removing one is a contract bump.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class LogCallEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var array<string,mixed> Structured invocation entry. Keys map
     *                          1:1 to the KV tokens in `$line`, plus
     *                          the DB-only additions documented above.
     *                          Subscribers read individual fields here
     *                          rather than parsing the formatted line.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public array $entry = [];

    /**
     * @var string Formatted KV line (the bytes operators tail in
     *             `storage/logs/web.log`). Provided alongside `$entry`
     *             so SIEM forwarders that pin against the wire format
     *             have a copy without re-running the formatter.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public string $line = '';
}
