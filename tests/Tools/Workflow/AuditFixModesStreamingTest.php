<?php

/**
 * =========================================================================
 * `content_audit` Pro fix-mode streaming — Gate 8.9b.
 *
 * Exercises the `stream()` surface for `fix_relations`,
 * `prune_unused_assets`, and `repair_propagation`:
 *   - Direct stream invocation yields the expected progress-frame shape
 *     and returns the terminal envelope plus the new `cancelled` field.
 *   - `progressInterval: 1` yields one frame per row (regression test
 *     for the interval knob).
 *   - Mid-stream cancellation via a pre-armed `CancellationToken` polls
 *     produces a partial `results[]` plus `cancelled: true`.
 *   - `execute()` collapses `stream()` to the same terminal envelope.
 *
 * Fixture strategy mirrors `AuditFixModesTest`: hand-seeded broken
 * relation rows + orphan assets, cleaned up in `afterEach()`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry as EntryElement;
use craft\helpers\Db;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\support\CancellationToken;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\workflow\Audit;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_audfxstream_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $entries = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->title(['like', $this->fixturePrefix . '%'])
        ->all();
    foreach ($entries as $entry) {
        Craft::$app->getElements()->deleteElement($entry, hardDelete: true);
    }

    $assets = Asset::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->title(['like', $this->fixturePrefix . '%'])
        ->all();
    foreach ($assets as $asset) {
        Craft::$app->getElements()->deleteElement($asset, hardDelete: true);
    }

    // Wipe fixture broken-relations rows.
    Db::delete(Table::RELATIONS, ['sortOrder' => 9101]);
    Db::delete(Table::RELATIONS, ['sortOrder' => 9102]);
    Db::delete(Table::RELATIONS, ['sortOrder' => 9103]);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_audfxstream_tool(): Audit
{
    return new Audit();
}

/**
 * Drain a `stream()` generator and return `[frames, terminal]`.
 *
 * @return array{0: list<array<string,mixed>>, 1: array<string,mixed>}
 */
function _herald_audfxstream_drain(Generator $gen): array
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
 * Seed N broken relation rows pointing from N distinct fixture entries.
 *
 * @return list<int> The seeded fixture entry ids.
 */
function _herald_audfxstream_seed_broken_relations(string $titlePrefix, int $n, int $sortOrderSentinel): array
{
    $section = Craft::$app->getEntries()->getSectionByHandle('heroes');
    if ($section === null) {
        return [];
    }
    $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
    if ($entryType === null) {
        return [];
    }
    $heroImageField = Craft::$app->getFields()->getFieldByHandle('heroImage');
    if ($heroImageField === null) {
        return [];
    }

    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $entry = new EntryElement();
        $entry->sectionId = (int) $section->id;
        $entry->typeId = (int) $entryType->id;
        $entry->title = $titlePrefix . 'fixture-' . $i;
        if (!Craft::$app->getElements()->saveElement($entry)) {
            continue;
        }
        $ids[] = (int) $entry->id;

        $bogusTargetId = 999_000_000 + random_int(1, 99_999);
        Db::insert(Table::RELATIONS, [
            'sourceId' => (int) $entry->id,
            'targetId' => $bogusTargetId,
            'fieldId' => (int) $heroImageField->id,
            'sortOrder' => $sortOrderSentinel,
            'dateCreated' => Db::prepareDateForDb(new DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    return $ids;
}

function _herald_audfxstream_seed_unused_asset(string $titlePrefix): ?int
{
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;
    if ($volume === null) {
        return null;
    }
    $folder = Craft::$app->getAssets()->getRootFolderByVolumeId((int) $volume->id);
    if ($folder === null) {
        return null;
    }

    $asset = new Asset();
    $asset->volumeId = (int) $volume->id;
    $asset->folderId = (int) $folder->id;
    $asset->filename = $titlePrefix . 'orphan.txt';
    $asset->title = $titlePrefix . 'orphan';
    $asset->kind = 'text';
    $asset->size = 42;
    $asset->setScenario(Asset::SCENARIO_INDEX);

    if (!Craft::$app->getElements()->saveElement($asset, runValidation: false)) {
        return null;
    }
    return (int) $asset->id;
}

// -----------------------------------------------------------------------------
// fix_relations — direct stream yields + terminal envelope
// -----------------------------------------------------------------------------

it('stream(fix_relations) yields one frame per progressInterval rows and returns a structured envelope', function() {
    $ids = _herald_audfxstream_seed_broken_relations($this->fixturePrefix, 3, 9101);
    if ($ids === []) {
        $this->markTestSkipped('Could not seed fixture relations.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($ids) {
        $gen = _herald_audfxstream_tool()->stream(
            [
                'mode' => 'fix_relations',
                'limit' => 200,
                'progressInterval' => 1,
            ],
            new InvocationContext(),
        );
        [$frames, $terminal] = _herald_audfxstream_drain($gen);

        // At least one frame per seeded row (additional pre-existing
        // broken relations on the playground may push the count higher).
        expect(count($frames))->toBeGreaterThanOrEqual(count($ids));

        $previous = -1;
        foreach ($frames as $frame) {
            expect($frame)->toHaveKeys(['progress', 'total', 'message']);
            expect($frame['progress'])->toBeGreaterThan($previous);
            $previous = $frame['progress'];
        }

        expect($terminal)->toHaveKeys([
            'success', 'mode', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'cancelled', 'results',
        ]);
        expect($terminal['mode'])->toBe('fix_relations');
        expect($terminal['cancelled'])->toBeFalse();
        expect($terminal['processed'])->toBeGreaterThanOrEqual(count($ids));
    });
});

it('stream(fix_relations) reports cancelled=true when the token flips mid-stream', function() {
    $ids = _herald_audfxstream_seed_broken_relations($this->fixturePrefix, 5, 9101);
    if (count($ids) < 5) {
        $this->markTestSkipped('Could not seed 5 fixture relations.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $callCount = 0;
        // Flip after the first poll so the loop's mid-iteration check
        // trips before the second row gets visited.
        $token = new CancellationToken(static function() use (&$callCount): bool {
            $callCount++;
            return $callCount > 1;
        });
        $ctx = new InvocationContext(cancellationToken: $token);

        $gen = _herald_audfxstream_tool()->stream(
            [
                'mode' => 'fix_relations',
                'limit' => 200,
                'progressInterval' => 1,
            ],
            $ctx,
        );
        [, $terminal] = _herald_audfxstream_drain($gen);

        expect($terminal['cancelled'])->toBeTrue();
        expect($terminal['success'])->toBeFalse();
        expect($terminal['processed'])->toBeGreaterThanOrEqual(1);
    });
});

it('execute(fix_relations) drains stream() and returns the same terminal envelope shape', function() {
    _herald_audfxstream_seed_broken_relations($this->fixturePrefix, 2, 9101);

    herald_with_edition(Herald::EDITION_PRO, function() {
        $viaExecute = _herald_audfxstream_tool()->execute([
            'mode' => 'fix_relations',
            'limit' => 200,
        ]);

        // Re-seed so the streamed run finds work too — execute() above
        // already deleted the previous fixture rows.
        _herald_audfxstream_seed_broken_relations($this->fixturePrefix, 2, 9101);

        $gen = _herald_audfxstream_tool()->stream(
            ['mode' => 'fix_relations', 'limit' => 200],
            new InvocationContext(),
        );
        [, $viaStream] = _herald_audfxstream_drain($gen);

        expect(array_keys($viaExecute))->toBe(array_keys($viaStream));
        expect($viaExecute['mode'])->toBe('fix_relations');
        expect($viaStream['mode'])->toBe('fix_relations');
        expect($viaExecute['cancelled'])->toBeFalse();
        expect($viaStream['cancelled'])->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// prune_unused_assets — direct stream yields + cancellation + parity
// -----------------------------------------------------------------------------

it('stream(prune_unused_assets) yields progress frames and returns a structured envelope', function() {
    $assetId = _herald_audfxstream_seed_unused_asset($this->fixturePrefix);
    if ($assetId === null) {
        $this->markTestSkipped('Could not seed orphan asset.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $gen = _herald_audfxstream_tool()->stream(
            [
                'mode' => 'prune_unused_assets',
                'limit' => 200,
                'progressInterval' => 1,
            ],
            new InvocationContext(),
        );
        [$frames, $terminal] = _herald_audfxstream_drain($gen);

        expect(count($frames))->toBeGreaterThanOrEqual(1);
        $previous = -1;
        foreach ($frames as $frame) {
            expect($frame)->toHaveKeys(['progress', 'total', 'message']);
            expect($frame['progress'])->toBeGreaterThan($previous);
            $previous = $frame['progress'];
        }

        expect($terminal)->toHaveKeys([
            'success', 'mode', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'totalSizeFreed', 'cancelled', 'results',
        ]);
        expect($terminal['mode'])->toBe('prune_unused_assets');
        expect($terminal['cancelled'])->toBeFalse();
    });
});

it('stream(prune_unused_assets) reports cancelled=true when the token flips mid-stream', function() {
    // Seed two assets so the loop has at least two rows to cancel between.
    _herald_audfxstream_seed_unused_asset($this->fixturePrefix);
    _herald_audfxstream_seed_unused_asset($this->fixturePrefix . 'b');

    herald_with_edition(Herald::EDITION_PRO, function() {
        $callCount = 0;
        $token = new CancellationToken(static function() use (&$callCount): bool {
            $callCount++;
            return $callCount > 1;
        });
        $ctx = new InvocationContext(cancellationToken: $token);

        $gen = _herald_audfxstream_tool()->stream(
            [
                'mode' => 'prune_unused_assets',
                'limit' => 200,
                'progressInterval' => 1,
            ],
            $ctx,
        );
        [, $terminal] = _herald_audfxstream_drain($gen);

        expect($terminal['cancelled'])->toBeTrue();
        expect($terminal['success'])->toBeFalse();
    });
});

it('execute(prune_unused_assets) drains stream() and returns the same terminal envelope shape', function() {
    _herald_audfxstream_seed_unused_asset($this->fixturePrefix);

    herald_with_edition(Herald::EDITION_PRO, function() {
        $viaExecute = _herald_audfxstream_tool()->execute([
            'mode' => 'prune_unused_assets',
            'limit' => 200,
        ]);

        _herald_audfxstream_seed_unused_asset($this->fixturePrefix);

        $gen = _herald_audfxstream_tool()->stream(
            ['mode' => 'prune_unused_assets', 'limit' => 200],
            new InvocationContext(),
        );
        [, $viaStream] = _herald_audfxstream_drain($gen);

        expect(array_keys($viaExecute))->toBe(array_keys($viaStream));
        expect($viaExecute['mode'])->toBe('prune_unused_assets');
        expect($viaStream['mode'])->toBe('prune_unused_assets');
    });
});

// -----------------------------------------------------------------------------
// repair_propagation — single-site playground constrains the test surface
// -----------------------------------------------------------------------------

it('stream(repair_propagation) returns the envelope shape (no gaps on single-site)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $gen = _herald_audfxstream_tool()->stream(
            ['mode' => 'repair_propagation', 'limit' => 10],
            new InvocationContext(),
        );
        [$frames, $terminal] = _herald_audfxstream_drain($gen);

        // Playground is single-site → no multi-site sections → zero gaps.
        // Loop never iterates, so no progress frames emit.
        expect($frames)->toBe([]);
        expect($terminal)->toHaveKeys([
            'success', 'mode', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'globalCapReached', 'cancelled', 'results',
        ]);
        expect($terminal['mode'])->toBe('repair_propagation');
        expect($terminal['total'])->toBe(0);
        expect($terminal['processed'])->toBe(0);
        expect($terminal['cancelled'])->toBeFalse();
        expect($terminal['results'])->toBe([]);
    });
});

it('execute(repair_propagation) drains stream() and returns the same terminal envelope shape', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $viaExecute = _herald_audfxstream_tool()->execute(['mode' => 'repair_propagation', 'limit' => 10]);

        $gen = _herald_audfxstream_tool()->stream(
            ['mode' => 'repair_propagation', 'limit' => 10],
            new InvocationContext(),
        );
        [, $viaStream] = _herald_audfxstream_drain($gen);

        expect(array_keys($viaExecute))->toBe(array_keys($viaStream));
        expect($viaExecute['mode'])->toBe('repair_propagation');
        expect($viaStream['mode'])->toBe('repair_propagation');
        expect($viaExecute['cancelled'])->toBeFalse();
        expect($viaStream['cancelled'])->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// stream() rejects read modes and edition-gated calls
// -----------------------------------------------------------------------------

it('stream() rejects read modes', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $gen = _herald_audfxstream_tool()->stream(['mode' => 'relations'], new InvocationContext());
        iterator_to_array($gen);
    });
})->throws(\craftpulse\herald\tools\ToolException::class, 'is not streamable');

it('stream() rejects Pro modes on Free installs', function() {
    $gen = _herald_audfxstream_tool()->stream(['mode' => 'fix_relations'], new InvocationContext());
    iterator_to_array($gen);
})->throws(\craftpulse\herald\tools\ToolException::class, 'unavailable on this edition');
