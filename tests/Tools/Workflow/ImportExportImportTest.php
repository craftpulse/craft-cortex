<?php

/**
 * =========================================================================
 * `import_export` Pro mode — `import` — Gate 8.8b.
 *
 * Exercises:
 *   - The `inputSchemaFor()` mode-enum filter across Free/Pro editions
 *     and stdio/admin/permitted/non-permitted users.
 *   - Round-trip (export → import dry-run, then export → import live).
 *   - Create vs update path: uid in DB → update, uid absent → create.
 *   - Validation failure surfaces via the per-item envelope.
 *   - Permission denied on per-item section UID.
 *   - Section / entry-type resolution misses are reported as skipped.
 *   - Format-version mismatch raises a top-level `ToolException`.
 *   - Cross-edition guard: Free install + Pro mode dispatch raises
 *     the locked "unavailable on this edition" `ToolException`.
 *
 * Fixture strategy: round-trip against the playground's `heroes`
 * section. Per-test prefix ensures `afterEach()` can hard-delete every
 * fixture entry safely.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\workflow\ImportExport;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_imp_' . bin2hex(random_bytes(4)) . '_';

    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'elements_sites.title', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_imp_tool(): ImportExport
{
    return new ImportExport();
}

function _herald_imp_section(): ?\craft\models\Section
{
    return Craft::$app->getEntries()->getSectionByHandle('heroes');
}

function _herald_imp_seed_entry(string $titlePrefix): ?EntryElement
{
    $section = _herald_imp_section();
    if ($section === null) {
        return null;
    }
    $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
    if ($entryType === null) {
        return null;
    }
    $entry = new EntryElement();
    $entry->sectionId = (int) $section->id;
    $entry->typeId = (int) $entryType->id;
    $entry->title = $titlePrefix . 'seed';
    if (!Craft::$app->getElements()->saveElement($entry)) {
        return null;
    }
    return $entry;
}

// -----------------------------------------------------------------------------
// inputSchemaFor — stdio invariant
// -----------------------------------------------------------------------------

it('inputSchemaFor(null) returns the Free enum on Free', function() {
    $schema = _herald_imp_tool()->inputSchemaFor(null);
    expect($schema['properties']['mode']['enum'])->toBe(['export']);
});

it('inputSchemaFor(null) returns the full static enum on Pro', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_imp_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])->toBe(['export', 'import']);
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Free + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Free returns the Free enum for admins', function() {
    $schema = _herald_imp_tool()->inputSchemaFor($this->admin);
    expect($schema['properties']['mode']['enum'])->toBe(['export']);
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Pro + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Pro returns the full enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_imp_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])->toBe(['export', 'import']);
    });
});

it('inputSchemaFor on Pro hides import from users without any saveEntries permission', function() {
    $user = new User();
    $user->username = '__herald_imp_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }
    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            $schema = _herald_imp_tool()->inputSchemaFor($reloaded);
            expect($schema['properties']['mode']['enum'])->toBe(['export']);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// import — dry-run round-trip
// -----------------------------------------------------------------------------

it('import dry-run validates the payload without writing and reports per-item success', function() {
    $seed = _herald_imp_seed_entry($this->fixturePrefix);
    if ($seed === null) {
        $this->markTestSkipped('Could not seed entry.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($seed) {
        // Export the entry (Free mode, no edition needed but we're already in Pro).
        $exportEnvelope = _herald_imp_tool()->execute([
            'mode' => 'export',
            'id' => $seed->id,
        ]);

        $result = _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => $exportEnvelope,
            // dryRun unset → defaults to true.
        ]);

        expect($result)->toHaveKeys([
            'success', 'mode', 'dryRun', 'committed', 'total', 'processed', 'succeeded', 'failed', 'skipped', 'results',
        ]);
        expect($result['mode'])->toBe('import');
        expect($result['dryRun'])->toBeTrue();
        expect($result['committed'])->toBe(0);
        expect($result['total'])->toBe(1);
        expect($result['processed'])->toBe(1);
        expect($result['succeeded'])->toBe(1);
        expect($result['failed'])->toBe(0);
        expect($result['skipped'])->toBe(0);

        $row = $result['results'][0];
        expect($row['kind'])->toBe('success');
        expect($row['mode'])->toBe('update');
        expect($row['dryRun'])->toBeTrue();
        expect($row['uid'])->toBe($seed->uid);
    });
});

it('import live commits writes when dryRun is false', function() {
    $seed = _herald_imp_seed_entry($this->fixturePrefix);
    if ($seed === null) {
        $this->markTestSkipped('Could not seed entry.');
    }
    $originalUid = $seed->uid;
    $originalId = (int) $seed->id;

    herald_with_edition(Herald::EDITION_PRO, function() use ($seed, $originalUid, $originalId) {
        $exportEnvelope = _herald_imp_tool()->execute(['mode' => 'export', 'id' => $seed->id]);

        // Mutate the title in the payload so we can detect the live write.
        $exportEnvelope['entries'][0]['title'] = $this->fixturePrefix . 'imported';

        $result = _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => $exportEnvelope,
            'dryRun' => false,
        ]);

        expect($result['dryRun'])->toBeFalse();
        expect($result['committed'])->toBe(1);
        expect($result['succeeded'])->toBe(1);
        expect($result['failed'])->toBe(0);
        expect($result['results'][0]['kind'])->toBe('success');
        expect($result['results'][0]['mode'])->toBe('update');

        // Reload and confirm the title actually changed.
        $reloaded = EntryElement::find()->id($originalId)->status(null)->one();
        expect($reloaded)->toBeInstanceOf(EntryElement::class);
        expect($reloaded->title)->toBe($this->fixturePrefix . 'imported');
        expect($reloaded->uid)->toBe($originalUid);
    });
});

// -----------------------------------------------------------------------------
// import — create path
// -----------------------------------------------------------------------------

it('import creates a new entry when the payload uid is absent from the DB', function() {
    $section = _herald_imp_section();
    if ($section === null) {
        $this->markTestSkipped('No `heroes` section.');
    }
    $entryType = Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
    if ($entryType === null) {
        $this->markTestSkipped('No entry type.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section, $entryType) {
        $newUid = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
        $envelope = [
            'format' => ImportExport::FORMAT_VERSION,
            'entries' => [
                [
                    'uid' => $newUid,
                    'title' => $this->fixturePrefix . 'created',
                    'section' => $section->handle,
                    'type' => $entryType->handle,
                    'site' => Craft::$app->getSites()->getPrimarySite()->handle,
                    'enabled' => true,
                    'enabledForSite' => true,
                    'fields' => [],
                ],
            ],
        ];

        $result = _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => $envelope,
            'dryRun' => false,
        ]);

        expect($result['succeeded'])->toBe(1);
        expect($result['results'][0]['kind'])->toBe('success');
        expect($result['results'][0]['mode'])->toBe('create');
        expect($result['results'][0]['uid'])->toBe($newUid);

        $created = EntryElement::find()->uid($newUid)->status(null)->one();
        expect($created)->toBeInstanceOf(EntryElement::class);
        expect($created->title)->toBe($this->fixturePrefix . 'created');
    });
});

// -----------------------------------------------------------------------------
// import — per-item skip paths
// -----------------------------------------------------------------------------

it('import skips items whose section handle does not resolve', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $envelope = [
            'format' => ImportExport::FORMAT_VERSION,
            'entries' => [
                ['uid' => 'doesnt-matter', 'title' => 'x', 'section' => 'totally_not_a_section', 'type' => 'whatever', 'site' => 'default'],
            ],
        ];

        $result = _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => $envelope,
        ]);

        expect($result['skipped'])->toBe(1);
        expect($result['results'][0]['kind'])->toBe('skipped');
        expect($result['results'][0]['reason'])->toBe('missing_section');
    });
});

it('import skips items where the caller lacks saveEntries on the target section', function() {
    $section = _herald_imp_section();
    $entryType = $section !== null ? (Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null) : null;
    if ($section === null || $entryType === null) {
        $this->markTestSkipped('`heroes` section + entry type required.');
    }

    $user = new User();
    $user->username = '__herald_imp_denied_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($section, $entryType, $user) {
            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            Craft::$app->getUser()->setIdentity($reloaded);

            $envelope = [
                'format' => ImportExport::FORMAT_VERSION,
                'entries' => [
                    [
                        'uid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                        'title' => $this->fixturePrefix . 'denied',
                        'section' => $section->handle,
                        'type' => $entryType->handle,
                        'site' => 'default',
                    ],
                ],
            ];

            $result = _herald_imp_tool()->execute([
                'mode' => 'import',
                'payload' => $envelope,
            ]);

            expect($result['skipped'])->toBe(1);
            $row = $result['results'][0];
            expect($row['kind'])->toBe('skipped');
            expect($row['reason'])->toBe('permission_denied');
            expect($row['requiredPermission'])->toBe("saveEntries:{$section->uid}");
            expect($row['message'])->toMatch("/^permission denied: mode `import` on section `{$section->uid}` requires `saveEntries:{$section->uid}`\\.\$/");
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// import — top-level guards
// -----------------------------------------------------------------------------

it('import throws ToolException on format-version mismatch', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => ['format' => 1, 'entries' => []],
        ]);
    });
})->throws(ToolException::class, 'format` mismatch');

it('import throws ToolException when payload.entries is not an array', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_imp_tool()->execute([
            'mode' => 'import',
            'payload' => ['format' => ImportExport::FORMAT_VERSION, 'entries' => 'nope'],
        ]);
    });
})->throws(ToolException::class, 'entries` must be an array');

it('import throws ToolException when payload is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_imp_tool()->execute(['mode' => 'import']);
    });
})->throws(ToolException::class, '`payload` is required');

// -----------------------------------------------------------------------------
// Cross-edition guard
// -----------------------------------------------------------------------------

it('import throws "mode unavailable on this edition" on Free', function() {
    _herald_imp_tool()->execute([
        'mode' => 'import',
        'payload' => ['format' => ImportExport::FORMAT_VERSION, 'entries' => []],
    ]);
})->throws(ToolException::class, 'unavailable on this edition');
