<?php

namespace craftpulse\herald\web\cp;

/**
 * =========================================================================
 * One section of the Settings screen's grouped command-toggle browser, read
 * off the posted body.
 *
 * The browser renders two independent sections, content-level and
 * admin-level, and each posts three sibling params:
 *
 *   - `herald[Admin]CommandGroups[<handle>]`  — a fully-toggled group.
 *   - `herald[Admin]CommandActions[<routeId>]` — an individually-checked action.
 *   - `settings[<setting>][N][pattern]`        — the "Custom patterns"
 *                                               editable table.
 *
 * This class only normalises those three payloads. Folding them into the
 * flat `string[]` a setting persists stays in `Allowlist`, which is also
 * where the bucket boundary is enforced so a content toggle can never
 * surface an admin route.
 *
 * Built from primitives rather than from the request, so the controller
 * keeps the body reads (its own job) and this stays trivially testable.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class CommandToggleSubmission
{
    // Public Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $fullGroups    Handles of the fully-toggled groups.
     * @param array<string,mixed> $actionIds     Individually-checked action ids.
     * @param string[]            $customPatterns Verbatim power-user globs.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly array $fullGroups,
        public readonly array $actionIds,
        public readonly array $customPatterns,
    ) {
    }

    /**
     * Normalise one section's three posted payloads.
     *
     * @param mixed $groups     The raw `herald[Admin]CommandGroups` param.
     * @param mixed $actions    The raw `herald[Admin]CommandActions` param.
     * @param mixed $customRows The raw `settings[<setting>]` editable-table rows.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function fromPosted(mixed $groups, mixed $actions, mixed $customRows): self
    {
        return new self(
            fullGroups: self::onlyEnabled($groups),
            actionIds: self::onlyEnabled($actions),
            customPatterns: self::patterns($customRows),
        );
    }

    /**
     * Keep only the keys whose lightswitch is on.
     *
     * Craft's lightswitch macro posts a hidden input for every switch —
     * `'1'` when on, an empty string when off — so a raw read would report
     * every group and action as present. A non-array payload (none posted)
     * normalises to an empty map.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function onlyEnabled(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_filter(
            $value,
            static fn($on): bool => $on === '1' || $on === 1 || $on === true,
        );
    }

    /**
     * Flatten a "Custom patterns" editable-table payload to trimmed,
     * non-empty pattern strings. The macro posts a 2D array
     * (`settings[<setting>][N][pattern]`); the toggle browser folds these
     * back in verbatim so power-user globs survive a round-trip.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function patterns(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn($row): string => is_array($row) ? trim((string) ($row['pattern'] ?? '')) : '',
                $rows,
            ),
            static fn(string $pattern): bool => $pattern !== '',
        ));
    }
}
