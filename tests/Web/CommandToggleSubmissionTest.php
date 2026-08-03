<?php

/**
 * =========================================================================
 * `CommandToggleSubmission` posted-payload normalisation tests.
 *
 * The Settings screen's grouped command browser posts three sibling
 * payloads per section. This asserts the two normalisation rules that make
 * them safe to fold: only truly-enabled lightswitches count, and the
 * custom-pattern table flattens to trimmed non-empty strings.
 *
 * The fold itself (and the content-vs-admin bucket boundary) lives in
 * `Allowlist` and is asserted through `SettingsControllerSaveTest`.
 *
 * Pure functions — no Craft state, no database.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\web\cp\CommandToggleSubmission;

// -----------------------------------------------------------------------------
// onlyEnabled — lightswitch truthiness
// -----------------------------------------------------------------------------

it('keeps a switch that is on', function(mixed $on) {
    expect(CommandToggleSubmission::onlyEnabled(['resave' => $on]))->toHaveKey('resave');
})->with([
    "string '1'" => ['1'],
    'int 1' => [1],
    'bool true' => [true],
]);

it('drops anything that is not an on value', function(mixed $off) {
    // The lightswitch macro posts a hidden input for every switch, so a raw
    // read would report every group as enabled.
    expect(CommandToggleSubmission::onlyEnabled(['resave' => $off]))->toBe([]);
})->with([
    'empty string' => [''],
    'string 0' => ['0'],
    "string 'on'" => ['on'],
    "string 'true'" => ['true'],
    'int 0' => [0],
    'int 2' => [2],
    'bool false' => [false],
    'null' => [null],
]);

it('normalises a non-array toggle payload to an empty map', function(mixed $payload) {
    expect(CommandToggleSubmission::onlyEnabled($payload))->toBe([]);
})->with([
    'string' => ['not-an-array'],
    'null' => [null],
    'int' => [1],
]);

it('preserves the enabled keys', function() {
    $enabled = CommandToggleSubmission::onlyEnabled([
        'resave' => '1',
        'cache' => '',
        'up' => '1',
    ]);

    expect(array_keys($enabled))->toBe(['resave', 'up']);
});

// -----------------------------------------------------------------------------
// patterns — editable-table flatten
// -----------------------------------------------------------------------------

it('flattens the editable-table rows to pattern strings', function() {
    $patterns = CommandToggleSubmission::patterns([
        ['pattern' => 'resave/ent*'],
        ['pattern' => 'no-such-plugin/do-thing'],
    ]);

    expect($patterns)->toBe(['resave/ent*', 'no-such-plugin/do-thing']);
});

it('trims each pattern', function() {
    expect(CommandToggleSubmission::patterns([['pattern' => '  spaced/*  ']]))->toBe(['spaced/*']);
});

it('drops rows that carry no usable pattern', function() {
    $patterns = CommandToggleSubmission::patterns([
        ['pattern' => 'kept/*'],
        ['pattern' => '   '],
        ['pattern' => ''],
        ['notPattern' => 'ignored/*'],
        'not-a-row',
        42,
    ]);

    expect($patterns)->toBe(['kept/*']);
});

it('reindexes the surviving patterns as a list', function() {
    $patterns = CommandToggleSubmission::patterns([
        ['pattern' => ''],
        ['pattern' => 'kept/*'],
    ]);

    expect(array_keys($patterns))->toBe([0]);
});

it('normalises a non-array custom-pattern payload to an empty list', function(mixed $payload) {
    expect(CommandToggleSubmission::patterns($payload))->toBe([]);
})->with([
    'string' => ['not-an-array'],
    'null' => [null],
]);

// -----------------------------------------------------------------------------
// fromPosted — the three payloads together
// -----------------------------------------------------------------------------

it('reads all three payloads in one pass', function() {
    $submission = CommandToggleSubmission::fromPosted(
        groups: ['resave' => '1', 'cache' => ''],
        actions: ['up' => '1'],
        customRows: [['pattern' => 'custom/*']],
    );

    expect(array_keys($submission->fullGroups))->toBe(['resave']);
    expect(array_keys($submission->actionIds))->toBe(['up']);
    expect($submission->customPatterns)->toBe(['custom/*']);
});

it('survives all three payloads being absent', function() {
    $submission = CommandToggleSubmission::fromPosted(null, null, null);

    expect($submission->fullGroups)->toBe([]);
    expect($submission->actionIds)->toBe([]);
    expect($submission->customPatterns)->toBe([]);
});
