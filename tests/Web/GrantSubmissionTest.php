<?php

/**
 * =========================================================================
 * `GrantSubmission` posted-payload normalisation tests.
 *
 * The grants slideout and the legacy / API path post different shapes for
 * the same three inputs (command scope, duration, subject). This asserts
 * the normalisation directly; the `400` responses it feeds are asserted
 * through `SettingsControllerAllowlistTest`.
 *
 * Pure value object — no Craft state, no database.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\web\cp\GrantSubmission;

// -----------------------------------------------------------------------------
// Command scope
// -----------------------------------------------------------------------------

it('reads the picker patterns array', function() {
    expect(_heraldGrant(patterns: ['a/*', 'b/*'])->patterns)->toBe(['a/*', 'b/*']);
});

it('falls back to the legacy single pattern field', function() {
    expect(_heraldGrant(pattern: 'legacy/*')->patterns)->toBe(['legacy/*']);
});

it('prefers the patterns array over the single field', function() {
    expect(_heraldGrant(patterns: ['picked/*'], pattern: 'legacy/*')->patterns)->toBe(['picked/*']);
});

it('de-dupes while preserving order', function() {
    expect(_heraldGrant(patterns: ['b/*', 'a/*', 'b/*'])->patterns)->toBe(['b/*', 'a/*']);
});

it('trims each pattern and drops the blanks', function() {
    expect(_heraldGrant(patterns: ['  a/*  ', '   ', ''])->patterns)->toBe(['a/*']);
});

it('skips non-string entries', function() {
    expect(_heraldGrant(patterns: ['keep/*', ['nested'], 42, null, true])->patterns)->toBe(['keep/*']);
});

it('yields no patterns when neither param is usable', function(mixed $patterns, mixed $pattern) {
    expect(_heraldGrant(patterns: $patterns, pattern: $pattern)->patterns)->toBe([]);
})->with([
    'both absent' => [null, null],
    'empty array' => [[], null],
    'blank single' => [null, '   '],
    'non-string single' => [null, 42],
]);

// -----------------------------------------------------------------------------
// Duration
// -----------------------------------------------------------------------------

it('prefers a raw ttlSeconds', function() {
    expect(_heraldGrant(ttlSeconds: 7200, durationPreset: '3600')->ttlSeconds)->toBe(7200);
});

it('reads a numeric duration preset as a second count', function() {
    expect(_heraldGrant(durationPreset: '3600')->ttlSeconds)->toBe(3600);
});

it('reads the custom duration when the preset says custom', function() {
    expect(_heraldGrant(durationPreset: 'custom', customTtlSeconds: '900')->ttlSeconds)->toBe(900);
});

it('falls through to the plugin default when no duration resolves', function(mixed $ttl, mixed $preset, mixed $custom) {
    expect(_heraldGrant(ttlSeconds: $ttl, durationPreset: $preset, customTtlSeconds: $custom)->ttlSeconds)->toBeNull();
})->with([
    'nothing posted' => [null, null, null],
    'zero ttl' => [0, null, null],
    'negative ttl' => [-1, null, null],
    'non-numeric ttl' => ['abc', null, null],
    'custom with no value' => [null, 'custom', null],
    'custom with zero' => [null, 'custom', 0],
    'custom with garbage' => [null, 'custom', 'abc'],
    'non-numeric preset' => [null, 'forever', null],
    'zero preset' => [null, '0', null],
]);

// -----------------------------------------------------------------------------
// Subject
// -----------------------------------------------------------------------------

it('reports no subject when none was posted', function(mixed $posted) {
    // No subject means a global grant that applies to every caller.
    $submission = _heraldGrant(subjectUserId: $posted);

    expect($submission->hasSubject)->toBeFalse();
})->with([
    'null' => [null],
    'empty string' => [''],
]);

it('reads a scalar subject id', function() {
    $submission = _heraldGrant(subjectUserId: '42');

    expect($submission->hasSubject)->toBeTrue();
    expect($submission->subjectUserId)->toBe(42);
});

it('reads the first id from the elementSelect array form', function() {
    $submission = _heraldGrant(subjectUserId: [42, 99]);

    expect($submission->hasSubject)->toBeTrue();
    expect($submission->subjectUserId)->toBe(42);
});

it('reports an empty subject array as a subject with an unusable id', function() {
    // Documents current behaviour, not desired behaviour: `reset([])` is
    // `false`, which is neither null nor the empty string, so the submission
    // claims a subject and hands the controller the id 0 — which the
    // controller refuses with a 400. No CP path posts this shape (the macro's
    // hidden placeholder posts an empty string), but an API caller can.
    $submission = _heraldGrant(subjectUserId: []);

    expect($submission->hasSubject)->toBeTrue();
    expect($submission->subjectUserId)->toBe(0);
});

// -----------------------------------------------------------------------------
// Note
// -----------------------------------------------------------------------------

it('trims the note', function() {
    expect(_heraldGrant(note: '  ticket-1  ')->note)->toBe('ticket-1');
});

it('normalises a missing or empty note to null', function(mixed $posted) {
    expect(_heraldGrant(note: $posted)->note)->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'non-string' => [42],
]);

// -----------------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------------

/**
 * Build a `GrantSubmission` with every param defaulted, overriding only the
 * ones under test.
 */
function _heraldGrant(
    mixed $patterns = null,
    mixed $pattern = null,
    mixed $note = null,
    mixed $ttlSeconds = null,
    mixed $durationPreset = null,
    mixed $customTtlSeconds = null,
    mixed $subjectUserId = null,
): GrantSubmission {
    return GrantSubmission::fromPosted(
        patterns: $patterns,
        pattern: $pattern,
        note: $note,
        ttlSeconds: $ttlSeconds,
        durationPreset: $durationPreset,
        customTtlSeconds: $customTtlSeconds,
        subjectUserId: $subjectUserId,
    );
}
