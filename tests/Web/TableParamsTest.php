<?php

/**
 * =========================================================================
 * `TableParams` normalisation tests.
 *
 * The four CP data endpoints all read the same five VueAdminTable params
 * through this value object, so the page floor, the per-page ceiling and
 * the sort-direction fallback are asserted once, here, rather than four
 * times through the controller.
 *
 * Pure value object — no Craft state, no database.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\web\cp\TableParams;

// -----------------------------------------------------------------------------
// page / per_page bounds
// -----------------------------------------------------------------------------

it('floors the page at 1', function(mixed $posted) {
    expect(_heraldParams(page: $posted)->page)->toBe(1);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'non-numeric' => ['abc'],
    'empty' => [''],
    'null' => [null],
]);

it('keeps a positive page', function() {
    expect(_heraldParams(page: '7')->page)->toBe(7);
});

it('clamps per_page to the ceiling', function() {
    expect(_heraldParams(perPage: 5000)->perPage)->toBe(TableParams::MAX_PER_PAGE);
});

it('floors per_page at 1', function(mixed $posted) {
    expect(_heraldParams(perPage: $posted)->perPage)->toBe(1);
})->with([
    'zero' => [0],
    'negative' => [-10],
    'non-numeric' => ['abc'],
]);

it('keeps an in-range per_page', function() {
    expect(_heraldParams(perPage: '25')->perPage)->toBe(25);
});

// -----------------------------------------------------------------------------
// search
// -----------------------------------------------------------------------------

it('trims the search needle', function() {
    expect(_heraldParams(search: '  claude  ')->search)->toBe('claude');
});

it('normalises a whitespace-only needle to empty', function() {
    expect(_heraldParams(search: "  \t ")->search)->toBe('');
});

// -----------------------------------------------------------------------------
// sort direction
// -----------------------------------------------------------------------------

it('resolves an explicit direction regardless of the default', function(string $posted, int $expected) {
    expect(_heraldParams(sortDirection: $posted)->sortDir(SORT_ASC))->toBe($expected);
    expect(_heraldParams(sortDirection: $posted)->sortDir(SORT_DESC))->toBe($expected);
})->with([
    'asc' => ['asc', SORT_ASC],
    'desc' => ['desc', SORT_DESC],
]);

it('falls back to the caller default for anything else', function(mixed $posted) {
    // The in-memory tables pass SORT_ASC, the Activity log SORT_DESC — the
    // fallback has to honour whichever the caller named.
    expect(_heraldParams(sortDirection: $posted)->sortDir(SORT_ASC))->toBe(SORT_ASC);
    expect(_heraldParams(sortDirection: $posted)->sortDir(SORT_DESC))->toBe(SORT_DESC);
})->with([
    'empty' => [''],
    'null' => [null],
    'uppercase DESC' => ['DESC'],
    'garbage' => ['sideways'],
]);

// -----------------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------------

/**
 * Build a `TableParams` with every param defaulted, overriding only the
 * one under test.
 */
function _heraldParams(
    mixed $page = 1,
    mixed $perPage = TableParams::DEFAULT_PER_PAGE,
    mixed $search = '',
    mixed $sortField = '',
    mixed $sortDirection = '',
): TableParams {
    return TableParams::normalise($page, $perPage, $search, $sortField, $sortDirection);
}
