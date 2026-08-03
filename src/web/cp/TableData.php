<?php

namespace craftpulse\herald\web\cp;

use craft\helpers\AdminTable;

/**
 * =========================================================================
 * In-memory paging, search and sort for Herald's CP tables.
 *
 * Three of the four Settings tables (Tokens, grants, OAuth clients) hold
 * row counts bounded by admin issuance — low dozens at most — so they
 * fetch the whole set and page it in PHP rather than carrying a paginated
 * query. That was the same 30 lines of filter / sort / slice / envelope in
 * three actions; it lives here now.
 *
 * The Activity log is the exception: it is an append-only audit table with
 * no upper bound, so it pages in SQL and uses only `envelope()` to wrap
 * an already-sliced result.
 *
 * Comparison is `strcmp` over the stringified cell, which is why every
 * sortable column is either a plain string or an ISO-8601 timestamp
 * (lexicographically ordered by construction). A null cell stringifies to
 * the empty string and therefore sorts first ascending, last descending.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class TableData
{
    // Public Methods
    // =========================================================================

    /**
     * Wrap an already-sliced result set in the `{pagination, data}` shape
     * Craft's Vue component consumes. See
     * `vendor/craftcms/cms/src/helpers/AdminTable.php::paginationLinks`
     * for the canonical pagination dict.
     *
     * @param array<int,array<string,mixed>> $data  The rows for this page.
     * @param int                            $total The row count across all pages.
     * @return array{pagination:array<string,mixed>,data:array<int,array<string,mixed>>}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function envelope(array $data, int $total, TableParams $params): array
    {
        return [
            'pagination' => AdminTable::paginationLinks($params->page, $total, $params->perPage),
            'data' => array_values($data),
        ];
    }

    /**
     * Slice the requested page out of a full in-memory set and wrap it in
     * the response envelope. `$map` runs on the slice only, so a caller
     * holding raw DB rows pays serialisation for one page rather than the
     * whole table.
     *
     * @param array<int,array<string,mixed>>                                  $rows
     * @param (callable(array<string,mixed>): array<string,mixed>)|null        $map
     * @return array{pagination:array<string,mixed>,data:array<int,array<string,mixed>>}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function page(array $rows, TableParams $params, ?callable $map = null): array
    {
        $total = count($rows);
        $slice = array_slice($rows, ($params->page - 1) * $params->perPage, $params->perPage);

        return self::envelope(
            $map === null ? $slice : array_map($map, $slice),
            $total,
            $params,
        );
    }

    /**
     * Case-insensitive substring filter across the named row keys.
     *
     * The keys are joined with a space into one haystack rather than
     * matched individually, which is the behaviour the tables shipped
     * with: an operator can paste a value spanning two columns and still
     * hit the row. An empty needle is a no-op.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[]                       $keys
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function search(array $rows, array $keys, string $needle): array
    {
        if ($needle === '') {
            return $rows;
        }

        $needle = mb_strtolower($needle);

        return array_values(array_filter(
            $rows,
            static function(array $row) use ($keys, $needle): bool {
                $haystack = implode(' ', array_map(
                    static fn(string $key): string => (string) ($row[$key] ?? ''),
                    $keys,
                ));

                return str_contains(mb_strtolower($haystack), $needle);
            },
        ));
    }

    /**
     * Sort rows by the row key the requested sort field maps to, falling
     * back to `$defaultColumn` for an unmapped (or absent) field so a
     * caller cannot order by a column the row tuple does not carry.
     *
     * With no sort requested at all the order is `$defaultColumn`
     * descending — newest first, which is the row an operator who just
     * issued something wants at the top.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string>           $columns Requested field => row key.
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function sort(array $rows, TableParams $params, array $columns, string $defaultColumn): array
    {
        $column = $columns[$params->sortField] ?? $defaultColumn;
        $direction = $params->sortField === '' ? SORT_DESC : $params->sortDir(SORT_ASC);

        usort($rows, static function(array $a, array $b) use ($column, $direction): int {
            $cmp = strcmp((string) ($a[$column] ?? ''), (string) ($b[$column] ?? ''));

            return $direction === SORT_DESC ? -$cmp : $cmp;
        });

        return $rows;
    }
}
