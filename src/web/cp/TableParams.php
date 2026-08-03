<?php

namespace craftpulse\herald\web\cp;

/**
 * =========================================================================
 * The standard `Craft.VueAdminTable` request parameters, read once.
 *
 * Craft's Vue table sends the same five params to every data endpoint
 * (`page`, `per_page`, `search`, `sort.0.field`, `sort.0.direction`).
 * Herald has four such endpoints, so the normalisation rules — the page
 * floor, the per-page ceiling, the trimmed needle — live here rather than
 * being re-derived per action.
 *
 * Deliberately a dumb value object with no request dependency: the
 * controller reads the params off its own request (the HTTP boundary's
 * job) and hands over primitives, which also keeps the object trivially
 * constructible in tests.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class TableParams
{
    // Constants
    // =========================================================================

    /**
     * Rows per page when the caller names none. Matches Craft's own
     * VueAdminTable default.
     *
     * @since 5.0.0
     */
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Hard ceiling on `per_page`. A caller cannot ask for an unbounded
     * page and turn a bounded table into a full-table read.
     *
     * @since 5.0.0
     */
    public const MAX_PER_PAGE = 100;

    // Public Methods
    // =========================================================================

    /**
     * @param int    $page          1-based page number, floored at 1.
     * @param int    $perPage       Rows per page, clamped to 1..MAX_PER_PAGE.
     * @param string $search        Trimmed search needle; empty means no search.
     * @param string $sortField     Requested sort field; empty means no sort requested.
     * @param string $sortDirection Raw `asc` / `desc` as posted.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $search,
        public readonly string $sortField,
        public readonly string $sortDirection,
    ) {
    }

    /**
     * Normalise the raw posted params into a bounded instance. The floor
     * and ceiling are applied here so no endpoint can forget them.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function normalise(
        mixed $page,
        mixed $perPage,
        mixed $search,
        mixed $sortField,
        mixed $sortDirection,
    ): self {
        return new self(
            page: max(1, (int) $page),
            perPage: max(1, min((int) $perPage, self::MAX_PER_PAGE)),
            search: trim((string) $search),
            sortField: (string) $sortField,
            sortDirection: (string) $sortDirection,
        );
    }

    /**
     * Resolve the requested direction to a `SORT_*` constant, falling back
     * to `$default` when the caller posted neither `asc` nor `desc`.
     *
     * The two Herald defaults differ on purpose: the in-memory tables read
     * ascending unless told otherwise, while the Activity log reads
     * descending (newest first) unless told otherwise. Passing the default
     * in keeps both callers honest about which they mean.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function sortDir(int $default): int
    {
        return match ($this->sortDirection) {
            'asc' => SORT_ASC,
            'desc' => SORT_DESC,
            default => $default,
        };
    }
}
