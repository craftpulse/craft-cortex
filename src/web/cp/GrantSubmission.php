<?php

namespace craftpulse\herald\web\cp;

/**
 * =========================================================================
 * The Temporary grants slideout's posted body, normalised.
 *
 * Handles the shape variance between the guided slideout and the legacy /
 * API path so the action does not have to:
 *
 *   - **Command scope** — `patterns[]` from the grouped command picker, or
 *     a single `pattern` string. De-duped, trimmed, order-preserving.
 *   - **Duration** — a raw `ttlSeconds` wins; otherwise the slideout's
 *     `durationPreset` is read as a second-count, or `custom` plus
 *     `customTtlSeconds`. Null falls through to the plugin default in
 *     `Allowlist::add()`.
 *   - **Subject** — `elementSelectField` posts an array of ids, a plain
 *     field posts a scalar. Resolution against a real user record stays in
 *     the controller, because the failure is a `400` response.
 *   - **Note** — empty normalises to null.
 *
 * Built from primitives rather than from the request: the controller keeps
 * the body reads and this stays trivially testable.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class GrantSubmission
{
    // Public Methods
    // =========================================================================

    /**
     * @param string[] $patterns       De-duped, trimmed fnmatch patterns.
     * @param string|null $note        Trimmed operator note, null when blank.
     * @param int|null $ttlSeconds     Resolved grant lifetime, null = plugin default.
     * @param bool $hasSubject         Whether a subject was posted at all.
     * @param int $subjectUserId       The posted subject id, 0 when unparseable.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly array $patterns,
        public readonly ?string $note,
        public readonly ?int $ttlSeconds,
        public readonly bool $hasSubject,
        public readonly int $subjectUserId,
    ) {
    }

    /**
     * Normalise the posted grant body.
     *
     * @param mixed $patterns         The raw `patterns` param.
     * @param mixed $pattern          The raw single `pattern` param (legacy).
     * @param mixed $note             The raw `note` param.
     * @param mixed $ttlSeconds       The raw `ttlSeconds` param.
     * @param mixed $durationPreset   The raw `durationPreset` param.
     * @param mixed $customTtlSeconds The raw `customTtlSeconds` param.
     * @param mixed $subjectUserId    The raw `subjectUserId` param.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function fromPosted(
        mixed $patterns,
        mixed $pattern,
        mixed $note,
        mixed $ttlSeconds,
        mixed $durationPreset,
        mixed $customTtlSeconds,
        mixed $subjectUserId,
    ): self {
        // `elementSelectField` posts an array of element ids; a plain
        // numeric field posts a scalar. Take the first id from an array.
        $subject = is_array($subjectUserId) ? reset($subjectUserId) : $subjectUserId;

        return new self(
            patterns: self::_patterns($patterns, $pattern),
            note: is_string($note) && $note !== '' ? trim($note) : null,
            ttlSeconds: self::_ttl($ttlSeconds, $durationPreset, $customTtlSeconds),
            hasSubject: $subject !== null && $subject !== '',
            subjectUserId: (int) $subject,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalise the posted command scope into a de-duped,
     * order-preserving list of non-empty fnmatch patterns.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private static function _patterns(mixed $patterns, mixed $pattern): array
    {
        if (!is_array($patterns)) {
            $patterns = is_string($pattern) ? [$pattern] : [];
        }

        $normalised = [];
        foreach ($patterns as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '' && !in_array($trimmed, $normalised, true)) {
                $normalised[] = $trimmed;
            }
        }

        return $normalised;
    }

    /**
     * Resolve the grant lifetime in seconds, or null to fall through to the
     * plugin default.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private static function _ttl(mixed $ttlSeconds, mixed $durationPreset, mixed $customTtlSeconds): ?int
    {
        // A raw `ttlSeconds` wins when posted (the API / legacy path and
        // the controller tests use it directly).
        if (is_numeric($ttlSeconds) && (int) $ttlSeconds > 0) {
            return (int) $ttlSeconds;
        }

        // Otherwise resolve the guided slideout's duration control: a
        // preset whose value is a second-count, or `custom` plus
        // `customTtlSeconds`.
        if ($durationPreset === 'custom') {
            return is_numeric($customTtlSeconds) && (int) $customTtlSeconds > 0 ? (int) $customTtlSeconds : null;
        }

        if (is_numeric($durationPreset) && (int) $durationPreset > 0) {
            return (int) $durationPreset;
        }

        return null;
    }
}
