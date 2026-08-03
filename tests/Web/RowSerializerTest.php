<?php

/**
 * =========================================================================
 * `RowSerializer` cell-level tests.
 *
 * The four locked row tuples are asserted end-to-end through
 * `SettingsController*Test`, which is where the table contracts belong.
 * This file covers the shared cell helpers those tuples are built from —
 * the offset-bearing date, the tolerant JSON pretty-printer, the mode
 * extractor, and the user-cell resolution that three tuples share.
 *
 * `resolveUser` touches the database; everything else is pure.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\web\cp\RowSerializer;

beforeEach(function() {
    $this->rows = new RowSerializer();
});

// -----------------------------------------------------------------------------
// date — offset-bearing ISO-8601
// -----------------------------------------------------------------------------

it('renders a naive UTC column as an offset-bearing instant', function() {
    // The browser parses an offset-less datetime as local time, which
    // shifted every rendered timestamp and flipped the client-side expired
    // comparison. Naming the offset fixes both at the source.
    $iso = $this->rows->date('2026-06-10 12:00:00');

    expect($iso)->toBeString()->toMatch('/[+-]\d\d:\d\d$/');
    expect((new DateTimeImmutable((string) $iso))->getTimestamp())
        ->toBe((new DateTimeImmutable('2026-06-10 12:00:00', new DateTimeZone('UTC')))->getTimestamp());
});

it('renders an absent datetime as null', function(mixed $value) {
    expect($this->rows->date($value))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

// -----------------------------------------------------------------------------
// extractMode
// -----------------------------------------------------------------------------

it('extracts the mode from redacted args', function() {
    expect($this->rows->extractMode('{"mode":"create","secret":"<redacted>"}'))->toBe('create');
});

it('returns no mode when the column cannot supply one', function(mixed $args) {
    expect($this->rows->extractMode($args))->toBeNull();
})->with([
    'no mode key' => ['{"other":1}'],
    'empty mode' => ['{"mode":""}'],
    'non-string mode' => ['{"mode":42}'],
    'not json' => ['not json at all'],
    'json scalar' => ['"just a string"'],
    'empty string' => [''],
    'null' => [null],
    'non-string' => [42],
]);

// -----------------------------------------------------------------------------
// prettyJson
// -----------------------------------------------------------------------------

it('pretty-prints a JSON object without escaping slashes', function() {
    $pretty = $this->rows->prettyJson('{"url":"https://example.test/cb"}');

    expect($pretty)->toContain('"url": "https://example.test/cb"');
    expect($pretty)->not->toContain('https:\/\/');
});

it('falls back to the raw text for a truncated payload', function() {
    // The logger clips `responseExcerpt` to a fixed length, so a real
    // excerpt is frequently cut mid-document and is not valid JSON. A throw
    // here 500'd the detail slideout.
    $truncated = '{"data":"multi';

    expect($this->rows->prettyJson($truncated))->toBe($truncated);
});

it('falls back to the raw text for a JSON scalar', function() {
    expect($this->rows->prettyJson('"a bare string"'))->toBe('"a bare string"');
});

it('returns null for an absent payload', function(mixed $raw) {
    expect($this->rows->prettyJson($raw))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'non-string' => [42],
]);

// -----------------------------------------------------------------------------
// resolveUser
// -----------------------------------------------------------------------------

it('resolves a real user id to the cell shape', function() {
    $user = herald_admin_user();
    expect($user)->not->toBeNull();

    $cell = $this->rows->resolveUser((int) $user->id);

    expect($cell)->toBeArray();
    expect(array_keys((array) $cell))->toBe(['id', 'label', 'cpEditUrl']);
    expect($cell['id'])->toBe((int) $user->id);
    expect($cell['label'])->toBeString()->not->toBe('');
});

it('accepts a numeric-string id', function() {
    $user = herald_admin_user();

    expect($this->rows->resolveUser((string) $user->id))->toBeArray();
});

it('returns null when the id cannot address a user', function(mixed $userId) {
    expect($this->rows->resolveUser($userId))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'non-numeric string' => ['abc'],
    'float' => [1.5],
    'array' => [[1]],
    'unknown id' => [999999999],
]);
