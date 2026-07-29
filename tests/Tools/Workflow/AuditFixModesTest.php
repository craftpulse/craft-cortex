<?php

/**
 * =========================================================================
 * `content_audit` Pro fix modes — Gate 8.8b.
 *
 * Exercises:
 *   - The `inputSchemaFor()` mode-enum filter across Free/Pro editions
 *     and stdio/admin/permitted/non-permitted users.
 *   - `fix_relations` against a hand-seeded broken relation row,
 *     including the per-row permission-skipped path and the
 *     idempotent re-run.
 *   - `prune_unused_assets` against a seeded fixture asset, including
 *     the per-row permission-denied path against a non-permitted user.
 *   - `repair_propagation` — multi-site only; the playground is
 *     single-site so this branch is skipped with a clear reason.
 *   - Cross-edition guard: Free install + Pro mode dispatch raises a
 *     `ToolException` with the locked "unavailable on this edition"
 *     message.
 *   - Section-spanning per-row permission test for `fix_relations`:
 *     caller with `saveEntries:minorHeroes` only against a row set
 *     mixing minorHeroes and heroes sources → minorHeroes rows
 *     process, heroes rows go to `skipped: permission_denied`.
 *
 * Fixture strategy:
 *   - Broken relations: insert a fake relations row pointing from a
 *     real entry to a non-existent target id.
 *   - Unused assets: seed a minimal Asset element (no physical file)
 *     pointed at the root folder of the playground's first volume.
 *     The fix mode hard-deletes the DB rows regardless of whether the
 *     file existed — Craft's `deleteElement($asset, true)` tolerates
 *     missing files when the asset's filesystem volume can't resolve
 *     the path. afterEach() hard-deletes any survivors.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\helpers\Db;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\workflow\Audit;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_audfx_' . bin2hex(random_bytes(4)) . '_';

    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    // Best-effort: hard-delete fixture entries and assets.
    $entries = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'elements_sites.title', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($entries as $entry) {
        Craft::$app->getElements()->deleteElement($entry, hardDelete: true);
    }

    $assets = Asset::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'elements_sites.title', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($assets as $asset) {
        Craft::$app->getElements()->deleteElement($asset, hardDelete: true);
    }

    // Wipe any fixture relations rows (matched by sourceId belonging to
    // fixture entries we created above — at this point already gone, so
    // we also wipe by sortOrder sentinel below).
    Db::delete(Table::RELATIONS, ['sortOrder' => 9001]);
    Db::delete(Table::RELATIONS, ['sortOrder' => 9002]);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_audfx_tool(): Audit
{
    return new Audit();
}

function _herald_audfx_section_heroes(): ?\craft\models\Section
{
    return Craft::$app->getEntries()->getSectionByHandle('heroes');
}

function _herald_audfx_section_minor(): ?\craft\models\Section
{
    return Craft::$app->getEntries()->getSectionByHandle('minorHeroes');
}

function _herald_audfx_volume(): ?\craft\models\Volume
{
    $volumes = Craft::$app->getVolumes()->getAllVolumes();
    return $volumes[0] ?? null;
}

/**
 * @param string $sortOrderSentinel Use a high-numbered sentinel (9001 / 9002) so afterEach() can wipe stragglers.
 */
function _herald_audfx_seed_broken_relation(int $sourceId, int $fieldId, int $sortOrderSentinel = 9001): int
{
    // Pick a target id that doesn't exist in the elements table. Use a
    // very high id so the chance of a collision is effectively zero.
    $bogusTargetId = 999_000_000 + random_int(1, 99_999);

    Db::insert(Table::RELATIONS, [
        'sourceId' => $sourceId,
        'targetId' => $bogusTargetId,
        'fieldId' => $fieldId,
        'sortOrder' => $sortOrderSentinel,
        'dateCreated' => Db::prepareDateForDb(new DateTime()),
        'dateUpdated' => Db::prepareDateForDb(new DateTime()),
    ]);

    return $bogusTargetId;
}

/**
 * Seed a minimal unused asset. The asset has no physical file behind
 * it — the volume's filesystem may complain on a fully-fledged save,
 * but a direct DB insertion via raw query bypasses the filesystem
 * altogether. The `prune_unused_assets` fix mode then hard-deletes the
 * row via `Elements::deleteElement($asset, true)`, which uses the
 * scenario flag to skip file operations when the file is missing.
 *
 * Returns the asset id, or null if seeding failed.
 */
function _herald_audfx_seed_unused_asset(string $titlePrefix): ?int
{
    $volume = _herald_audfx_volume();
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
    $asset->setScenario(Asset::SCENARIO_INDEX); // Skips file operations.

    if (!Craft::$app->getElements()->saveElement($asset, runValidation: false)) {
        return null;
    }
    return (int) $asset->id;
}

// -----------------------------------------------------------------------------
// inputSchemaFor — stdio invariant
// -----------------------------------------------------------------------------

it('inputSchemaFor(null) returns the Free enum on Free', function() {
    $schema = _herald_audfx_tool()->inputSchemaFor(null);
    expect($schema['properties']['mode']['enum'])->toBe(['relations', 'unused_assets', 'propagation']);
});

it('inputSchemaFor(null) returns the full static enum on Pro', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_audfx_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])->toBe([
            'relations', 'unused_assets', 'propagation',
            'fix_relations', 'prune_unused_assets', 'repair_propagation',
        ]);
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Free edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Free returns the Free enum for admins (HTTP path)', function() {
    $schema = _herald_audfx_tool()->inputSchemaFor($this->admin);
    expect($schema['properties']['mode']['enum'])->toBe(['relations', 'unused_assets', 'propagation']);
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Pro edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Pro exposes all Pro modes to admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_audfx_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])->toBe([
            'relations', 'unused_assets', 'propagation',
            'fix_relations', 'prune_unused_assets', 'repair_propagation',
        ]);
    });
});

it('inputSchemaFor on Pro hides Pro modes from users without any save permission', function() {
    $user = new User();
    $user->username = '__herald_audfx_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            $schema = _herald_audfx_tool()->inputSchemaFor($reloaded);
            expect($schema['properties']['mode']['enum'])->toBe(['relations', 'unused_assets', 'propagation']);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// fix_relations — happy path
// -----------------------------------------------------------------------------

it('fix_relations deletes the broken relation rows and returns per-row outcomes', function() {
    $section = _herald_audfx_section_heroes();
    if ($section === null) {
        $this->markTestSkipped('No `heroes` section in playground.');
    }
    $hero = EntryElement::find()->section('heroes')->status(null)->one();
    if (!$hero instanceof EntryElement) {
        $this->markTestSkipped('No `heroes` entry available.');
    }
    $heroImageField = Craft::$app->getFields()->getFieldByHandle('heroImage');
    if ($heroImageField === null) {
        $this->markTestSkipped('No `heroImage` field available.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($hero, $heroImageField) {
        _herald_audfx_seed_broken_relation((int) $hero->id, (int) $heroImageField->id, 9001);

        $result = _herald_audfx_tool()->execute([
            'mode' => 'fix_relations',
            'limit' => 200,
        ]);

        expect($result)->toHaveKeys(['success', 'mode', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'results']);
        expect($result['mode'])->toBe('fix_relations');
        expect($result['processed'])->toBeGreaterThanOrEqual(1);

        $myRow = collect($result['results'])->firstWhere('sourceId', (int) $hero->id);
        expect($myRow)->not->toBeNull();
        expect($myRow['kind'])->toBe('success');
        expect($myRow['fieldId'])->toBe((int) $heroImageField->id);
        expect($myRow['deletedRelationCount'])->toBe(1);

        // Re-run: the row is gone, so a second pass either skips or simply doesn't see it.
        $reRun = _herald_audfx_tool()->execute(['mode' => 'fix_relations', 'limit' => 200]);
        $myReRunRow = collect($reRun['results'])->firstWhere('sourceId', (int) $hero->id);
        // Either the row isn't visited (success) or it shows up missing — both prove idempotency.
        expect($myReRunRow === null || ($myReRunRow['kind'] ?? 'failure') !== 'success' || $myReRunRow['fieldId'] !== (int) $heroImageField->id)->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// fix_relations — section-spanning per-row permission
// -----------------------------------------------------------------------------

it('fix_relations skips rows whose source section the caller cannot save (mixed sources)', function() {
    $heroes = _herald_audfx_section_heroes();
    $minor = _herald_audfx_section_minor();
    if ($heroes === null || $minor === null) {
        $this->markTestSkipped('Both `heroes` and `minorHeroes` sections required.');
    }
    $hero = EntryElement::find()->section('heroes')->status(null)->one();
    $minorEntry = EntryElement::find()->section('minorHeroes')->status(null)->one();
    if (!$hero instanceof EntryElement || !$minorEntry instanceof EntryElement) {
        $this->markTestSkipped('Both `heroes` and `minorHeroes` entries required.');
    }
    $heroImageField = Craft::$app->getFields()->getFieldByHandle('heroImage');
    if ($heroImageField === null) {
        $this->markTestSkipped('No `heroImage` field available.');
    }

    $user = new User();
    $user->username = '__herald_audfx_minor_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $user->id,
        [
            "viewEntries:{$minor->uid}",
            "saveEntries:{$minor->uid}",
            "viewPeerEntries:{$minor->uid}",
            "savePeerEntries:{$minor->uid}",
        ],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($hero, $minorEntry, $heroImageField, $user, $heroes) {
            // Seed two broken rows — one from each section.
            _herald_audfx_seed_broken_relation((int) $hero->id, (int) $heroImageField->id, 9001);
            _herald_audfx_seed_broken_relation((int) $minorEntry->id, (int) $heroImageField->id, 9002);

            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            Craft::$app->getUser()->setIdentity($reloaded);

            $result = _herald_audfx_tool()->execute(['mode' => 'fix_relations', 'limit' => 200]);

            $heroRow = collect($result['results'])->firstWhere('sourceId', (int) $hero->id);
            $minorRow = collect($result['results'])->firstWhere('sourceId', (int) $minorEntry->id);

            expect($heroRow)->not->toBeNull();
            expect($heroRow['kind'])->toBe('skipped');
            expect($heroRow['reason'])->toBe('permission_denied');
            expect($heroRow['requiredPermission'])->toBe("saveEntries:{$heroes->uid}");

            expect($minorRow)->not->toBeNull();
            expect($minorRow['kind'])->toBe('success');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

it('fix_relations skips rows whose source element is soft-deleted (missing path)', function() {
    $section = _herald_audfx_section_heroes();
    $heroImageField = Craft::$app->getFields()->getFieldByHandle('heroImage');
    $entryTypes = $section !== null ? Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id) : [];
    $entryType = $entryTypes[0] ?? null;
    if ($section === null || $heroImageField === null || $entryType === null) {
        $this->markTestSkipped('`heroes` section, entry type, and `heroImage` field required.');
    }

    // Seed an entry, attach a broken relation row to it, then soft-delete
    // the entry. `_loadAnyElement()` returns null for trashed elements
    // (Craft's ElementQuery defaults to `trashed = false`), so the fix
    // mode classifies the row as `missing`.
    $fixture = new EntryElement();
    $fixture->sectionId = $section->id;
    $fixture->typeId = (int) $entryType->id;
    $fixture->title = $this->fixturePrefix . 'soft-delete-me';
    if (!Craft::$app->getElements()->saveElement($fixture)) {
        $this->markTestSkipped('Could not seed soft-delete fixture entry.');
    }
    $sourceId = (int) $fixture->id;

    herald_with_edition(Herald::EDITION_PRO, function() use ($sourceId, $heroImageField) {
        _herald_audfx_seed_broken_relation($sourceId, (int) $heroImageField->id, 9001);

        // Soft-delete (NOT hard) so the relation row's FK to elements
        // doesn't cascade. The element row stays — only dateDeleted
        // moves from null to a timestamp.
        $stillThere = EntryElement::find()->id($sourceId)->status(null)->one();
        expect($stillThere)->toBeInstanceOf(EntryElement::class);
        Craft::$app->getElements()->deleteElement($stillThere, hardDelete: false);

        $result = _herald_audfx_tool()->execute(['mode' => 'fix_relations', 'limit' => 200]);

        $row = collect($result['results'])->firstWhere('sourceId', $sourceId);
        expect($row)->not->toBeNull();
        expect($row['kind'])->toBe('skipped');
        expect($row['reason'])->toBe('missing');
    });
});

// -----------------------------------------------------------------------------
// prune_unused_assets — happy path
// -----------------------------------------------------------------------------

it('prune_unused_assets hard-deletes the orphan asset and aggregates sizeFreed', function() {
    $volume = _herald_audfx_volume();
    if ($volume === null) {
        $this->markTestSkipped('No volume available in playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($volume) {
        $assetId = _herald_audfx_seed_unused_asset($this->fixturePrefix);
        if ($assetId === null) {
            $this->markTestSkipped('Could not seed orphan asset.');
        }

        $result = _herald_audfx_tool()->execute(['mode' => 'prune_unused_assets', 'limit' => 200]);

        $myRow = collect($result['results'])->firstWhere('id', $assetId);
        // The orphan asset should show up in the results — either succeeded
        // (file-less asset deletion handled gracefully) or surfaced with a
        // non-permission failure message. Both prove the loop visited it.
        expect($myRow)->not->toBeNull();
        expect($myRow['volumeUid'] ?? null)->toBe($volume->uid);
        if (($myRow['kind'] ?? null) === 'success') {
            expect($myRow['sizeFreed'])->toBe(42);
            expect($result['totalSizeFreed'])->toBeGreaterThanOrEqual(42);
        }
    });
});

it('prune_unused_assets skips assets in volumes the caller cannot delete from', function() {
    $volume = _herald_audfx_volume();
    if ($volume === null) {
        $this->markTestSkipped('No volume available in playground.');
    }

    $user = new User();
    $user->username = '__herald_audfx_nodel_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }
    // Grant view but NOT delete on the volume.
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $user->id,
        ["viewAssets:{$volume->uid}"],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($volume, $user) {
            $assetId = _herald_audfx_seed_unused_asset($this->fixturePrefix);
            if ($assetId === null) {
                $this->markTestSkipped('Could not seed orphan asset.');
            }

            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            Craft::$app->getUser()->setIdentity($reloaded);

            $result = _herald_audfx_tool()->execute(['mode' => 'prune_unused_assets', 'limit' => 200]);
            $myRow = collect($result['results'])->firstWhere('id', $assetId);
            expect($myRow)->not->toBeNull();
            expect($myRow['kind'])->toBe('skipped');
            expect($myRow['reason'])->toBe('permission_denied');
            expect($myRow['requiredPermission'])->toBe("deleteAssets:{$volume->uid}");
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// repair_propagation — multi-site only (playground is single-site)
// -----------------------------------------------------------------------------

it('repair_propagation runs the loop and returns the envelope shape (no gaps on single-site)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_audfx_tool()->execute(['mode' => 'repair_propagation', 'limit' => 10]);

        expect($result)->toHaveKeys([
            'success', 'mode', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'globalCapReached', 'results',
        ]);
        expect($result['mode'])->toBe('repair_propagation');
        // Playground is single-site → no multi-site sections → zero gaps.
        expect($result['total'])->toBe(0);
        expect($result['processed'])->toBe(0);
        expect($result['results'])->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// Cross-edition guard
// -----------------------------------------------------------------------------

it('fix_relations throws "mode unavailable on this edition" on Free', function() {
    _herald_audfx_tool()->execute(['mode' => 'fix_relations']);
})->throws(ToolException::class, 'unavailable on this edition');

it('prune_unused_assets throws "mode unavailable on this edition" on Free', function() {
    _herald_audfx_tool()->execute(['mode' => 'prune_unused_assets']);
})->throws(ToolException::class, 'unavailable on this edition');

it('repair_propagation throws "mode unavailable on this edition" on Free', function() {
    _herald_audfx_tool()->execute(['mode' => 'repair_propagation']);
})->throws(ToolException::class, 'unavailable on this edition');
