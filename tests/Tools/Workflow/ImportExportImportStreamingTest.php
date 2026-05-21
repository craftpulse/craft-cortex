<?php

/**
 * =========================================================================
 * `import_export.import` streaming — Gate 8.9b.
 *
 * Exercises the `stream()` surface for the `import` mode:
 *   - Direct stream invocation yields the expected progress-frame shape
 *     and returns the terminal envelope including `cancelled: false`.
 *   - `progressInterval: 1` yields one frame per item (regression test
 *     for the interval knob).
 *   - Mid-stream cancellation via a pre-armed `CancellationToken` polls
 *     produces a partial `results[]` plus `cancelled: true`.
 *   - `execute()` collapses `stream()` to the same terminal envelope.
 *   - `stream()` rejects `export` and edition-gated calls.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Entry as EntryElement;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\support\CancellationToken;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\ToolException;
use craftpulse\cortex\tools\workflow\ImportExport;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__cortex_impstream_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->title(['like', $this->fixturePrefix . '%'])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _cortex_impstream_tool(): ImportExport
{
    return new ImportExport();
}

/**
 * Drain a `stream()` generator and return `[frames, terminal]`.
 *
 * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
 */
function _cortex_impstream_drain(Generator $gen): array
{
    $frames = [];
    while ($gen->valid()) {
        $frames[] = $gen->current();
        $gen->next();
    }
    /** @var array<string,mixed> $return */
    $return = $gen->getReturn();
    return [$frames, $return];
}

/**
 * Build a dry-run import envelope of N items targeting the `heroes`
 * section.
 *
 * @return array<string,mixed>
 */
function _cortex_impstream_envelope(string $titlePrefix, int $n): array
{
    $section = Craft::$app->getEntries()->getSectionByHandle('heroes');
    $entryType = $section !== null ? (Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null) : null;
    if ($section === null || $entryType === null) {
        return ['format' => ImportExport::FORMAT_VERSION, 'entries' => []];
    }

    $entries = [];
    for ($i = 0; $i < $n; $i++) {
        $entries[] = [
            'uid' => sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff)),
            'title' => $titlePrefix . 'item-' . $i,
            'section' => $section->handle,
            'type' => $entryType->handle,
            'site' => Craft::$app->getSites()->getPrimarySite()->handle,
            'enabled' => true,
            'enabledForSite' => true,
            'fields' => [],
        ];
    }

    return [
        'format' => ImportExport::FORMAT_VERSION,
        'entries' => $entries,
    ];
}

// -----------------------------------------------------------------------------
// stream() yields + terminal envelope
// -----------------------------------------------------------------------------

it('stream(import) yields one progress frame per progressInterval items and returns the envelope', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $envelope = _cortex_impstream_envelope($this->fixturePrefix, 5);

        $gen = _cortex_impstream_tool()->stream(
            [
                'mode' => 'import',
                'payload' => $envelope,
                'progressInterval' => 1,
            ],
            new InvocationContext(),
        );
        [$frames, $terminal] = _cortex_impstream_drain($gen);

        // 5 items × interval 1 = 5 frames.
        expect($frames)->toHaveCount(5);
        $previous = -1;
        foreach ($frames as $frame) {
            expect($frame)->toHaveKeys(['progress', 'total', 'message']);
            expect($frame['total'])->toBe(5);
            expect($frame['progress'])->toBeGreaterThan($previous);
            $previous = $frame['progress'];
        }

        expect($terminal)->toHaveKeys([
            'success', 'mode', 'dryRun', 'committed', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'cancelled', 'results',
        ]);
        expect($terminal['mode'])->toBe('import');
        expect($terminal['total'])->toBe(5);
        expect($terminal['processed'])->toBe(5);
        expect($terminal['cancelled'])->toBeFalse();
        expect($terminal['dryRun'])->toBeTrue();
    });
});

it('stream(import) yields no progress frames for a single-item payload at default interval', function() {
    // total=1, default interval=100 → zero progress frames; terminal only.
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $envelope = _cortex_impstream_envelope($this->fixturePrefix, 1);

        $gen = _cortex_impstream_tool()->stream(
            [
                'mode' => 'import',
                'payload' => $envelope,
            ],
            new InvocationContext(),
        );
        [$frames, $terminal] = _cortex_impstream_drain($gen);

        expect($frames)->toBe([]);
        expect($terminal['total'])->toBe(1);
        expect($terminal['processed'])->toBe(1);
        expect($terminal['cancelled'])->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// stream() cancellation
// -----------------------------------------------------------------------------

it('stream(import) reports cancelled=true when the token flips mid-stream', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $envelope = _cortex_impstream_envelope($this->fixturePrefix, 10);

        $callCount = 0;
        // Flip after the first poll so the loop's mid-iteration check
        // trips before the second item gets visited.
        $token = new CancellationToken(static function() use (&$callCount): bool {
            $callCount++;
            return $callCount > 1;
        });
        $ctx = new InvocationContext(cancellationToken: $token);

        $gen = _cortex_impstream_tool()->stream(
            [
                'mode' => 'import',
                'payload' => $envelope,
                'progressInterval' => 1,
            ],
            $ctx,
        );
        [, $terminal] = _cortex_impstream_drain($gen);

        expect($terminal['cancelled'])->toBeTrue();
        expect($terminal['success'])->toBeFalse();
        expect($terminal['processed'])->toBeGreaterThanOrEqual(1);
        expect($terminal['processed'])->toBeLessThan(10);
    });
});

// -----------------------------------------------------------------------------
// execute() <=> stream() shape parity
// -----------------------------------------------------------------------------

it('execute(import) drains stream() and returns the same terminal envelope shape', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $envelope = _cortex_impstream_envelope($this->fixturePrefix, 3);

        $viaExecute = _cortex_impstream_tool()->execute([
            'mode' => 'import',
            'payload' => $envelope,
        ]);

        $gen = _cortex_impstream_tool()->stream(
            ['mode' => 'import', 'payload' => $envelope],
            new InvocationContext(),
        );
        [, $viaStream] = _cortex_impstream_drain($gen);

        expect(array_keys($viaExecute))->toBe(array_keys($viaStream));
        expect($viaExecute['mode'])->toBe('import');
        expect($viaStream['mode'])->toBe('import');
        expect($viaExecute['total'])->toBe($viaStream['total']);
        expect($viaExecute['cancelled'])->toBeFalse();
        expect($viaStream['cancelled'])->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// stream() rejects export + edition-gated calls
// -----------------------------------------------------------------------------

it('stream() rejects export mode (not streamable)', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $gen = _cortex_impstream_tool()->stream(['mode' => 'export'], new InvocationContext());
        iterator_to_array($gen);
    });
})->throws(ToolException::class, 'is not streamable');

it('stream() rejects Pro modes on Free installs', function() {
    $envelope = ['format' => ImportExport::FORMAT_VERSION, 'entries' => []];
    $gen = _cortex_impstream_tool()->stream(
        ['mode' => 'import', 'payload' => $envelope],
        new InvocationContext(),
    );
    iterator_to_array($gen);
})->throws(ToolException::class, 'unavailable on this edition');
