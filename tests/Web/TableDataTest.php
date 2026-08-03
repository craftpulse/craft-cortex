<?php

/**
 * =========================================================================
 * `TableData` in-memory paging / search / sort tests.
 *
 * The three bounded CP tables (Tokens, grants, OAuth clients) share this
 * filter/sort/slice/envelope path, and the Activity log reuses only
 * `envelope()`. Asserted directly here so a change to the shared path
 * cannot hide behind one table's controller test.
 *
 * Pure functions — no Craft state, no database.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\web\cp\TableData;
use craftpulse\herald\web\cp\TableParams;

// -----------------------------------------------------------------------------
// search
// -----------------------------------------------------------------------------

it('returns every row for an empty needle', function() {
    $rows = [['name' => 'a'], ['name' => 'b']];

    expect(TableData::search($rows, ['name'], ''))->toBe($rows);
});

it('matches case-insensitively', function() {
    $rows = [['name' => 'Claude-Desktop'], ['name' => 'cursor']];

    expect(TableData::search($rows, ['name'], 'CLAUDE'))->toHaveCount(1);
});

it('joins the named keys into one haystack', function() {
    // The keys are concatenated with a space rather than matched
    // individually, so a needle spanning two columns still hits.
    $rows = [['name' => 'claude', 'prefix' => 'abc123']];

    expect(TableData::search($rows, ['name', 'prefix'], 'claude abc'))->toHaveCount(1);
});

it('ignores keys the row does not carry', function() {
    $rows = [['name' => 'claude']];

    expect(TableData::search($rows, ['name', 'missing'], 'claude'))->toHaveCount(1);
});

it('reindexes the filtered result as a list', function() {
    $rows = [['name' => 'a'], ['name' => 'match'], ['name' => 'c']];

    expect(array_keys(TableData::search($rows, ['name'], 'match')))->toBe([0]);
});

// -----------------------------------------------------------------------------
// sort
// -----------------------------------------------------------------------------

it('sorts descending when no sort field is requested', function() {
    // Newest first: the row an operator just issued belongs at the top.
    $rows = [['dateCreated' => '2026-01-01'], ['dateCreated' => '2026-06-01']];

    $sorted = TableData::sort($rows, _heraldSortParams(''), [], 'dateCreated');

    expect($sorted[0]['dateCreated'])->toBe('2026-06-01');
});

it('sorts ascending by default once a field is requested', function() {
    $rows = [['name' => 'zulu'], ['name' => 'alpha']];

    $sorted = TableData::sort($rows, _heraldSortParams('name'), ['name' => 'name'], 'dateCreated');

    expect($sorted[0]['name'])->toBe('alpha');
});

it('honours an explicit descending direction', function() {
    $rows = [['name' => 'alpha'], ['name' => 'zulu']];

    $sorted = TableData::sort($rows, _heraldSortParams('name', 'desc'), ['name' => 'name'], 'dateCreated');

    expect($sorted[0]['name'])->toBe('zulu');
});

it('maps a requested field onto a differently-named row key', function() {
    $rows = [['toolName' => 'zulu'], ['toolName' => 'alpha']];

    $sorted = TableData::sort($rows, _heraldSortParams('tool'), ['tool' => 'toolName'], 'dateCreated');

    expect($sorted[0]['toolName'])->toBe('alpha');
});

it('falls back to the default column for an unmapped field', function() {
    // A caller must not be able to order by a column the row tuple does
    // not carry.
    $rows = [
        ['name' => 'alpha', 'dateCreated' => '2026-06-01'],
        ['name' => 'zulu', 'dateCreated' => '2026-01-01'],
    ];

    $sorted = TableData::sort($rows, _heraldSortParams('bogus'), ['name' => 'name'], 'dateCreated');

    expect($sorted[0]['name'])->toBe('zulu');
});

it('sorts a null cell as the empty string', function() {
    $rows = [['lastUsedAt' => null], ['lastUsedAt' => '2026-06-01T00:00:00+00:00']];

    $sorted = TableData::sort($rows, _heraldSortParams('lastUsedAt', 'desc'), ['lastUsedAt' => 'lastUsedAt'], 'dateCreated');

    expect($sorted[0]['lastUsedAt'])->toBe('2026-06-01T00:00:00+00:00');
    expect($sorted[1]['lastUsedAt'])->toBeNull();
});

// -----------------------------------------------------------------------------
// page / envelope
// -----------------------------------------------------------------------------

it('slices the requested page and reports the full total', function() {
    $rows = array_map(static fn(int $i): array => ['id' => $i], range(1, 5));

    $result = TableData::page($rows, _heraldPageParams(page: 2, perPage: 2));

    expect($result['pagination']['total'])->toBe(5);
    expect($result['pagination']['per_page'])->toBe(2);
    expect($result['pagination']['current_page'])->toBe(2);
    expect($result['pagination']['last_page'])->toBe(3);
    expect($result['data'])->toBe([['id' => 3], ['id' => 4]]);
});

it('returns an empty page past the end without changing the total', function() {
    $rows = [['id' => 1]];

    $result = TableData::page($rows, _heraldPageParams(page: 9, perPage: 2));

    expect($result['pagination']['total'])->toBe(1);
    expect($result['data'])->toBe([]);
});

it('runs the mapper on the slice only', function() {
    // A caller holding raw DB rows pays serialisation for one page, not the
    // whole table.
    $rows = array_map(static fn(int $i): array => ['id' => $i], range(1, 5));
    $mapped = 0;

    $result = TableData::page(
        $rows,
        _heraldPageParams(page: 1, perPage: 2),
        function(array $row) use (&$mapped): array {
            $mapped++;
            return ['serialised' => $row['id']];
        },
    );

    expect($mapped)->toBe(2);
    expect($result['data'])->toBe([['serialised' => 1], ['serialised' => 2]]);
});

it('wraps a pre-sliced set with an externally supplied total', function() {
    // The Activity log pages in SQL, so its total comes from a COUNT rather
    // than from the row array.
    $result = TableData::envelope([['id' => 42]], 500, _heraldPageParams(page: 1, perPage: 50));

    expect($result['pagination']['total'])->toBe(500);
    expect($result['data'])->toBe([['id' => 42]]);
});

it('reindexes the envelope data as a list', function() {
    $result = TableData::envelope([3 => ['id' => 1]], 1, _heraldPageParams(page: 1, perPage: 50));

    expect(array_keys($result['data']))->toBe([0]);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Params carrying only a sort field / direction.
 */
function _heraldSortParams(string $field, string $direction = ''): TableParams
{
    return TableParams::normalise(1, TableParams::DEFAULT_PER_PAGE, '', $field, $direction);
}

/**
 * Params carrying only paging.
 */
function _heraldPageParams(int $page, int $perPage): TableParams
{
    return TableParams::normalise($page, $perPage, '', '', '');
}
