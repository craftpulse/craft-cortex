<?php

/**
 * =========================================================================
 * `entry` Pro tool tests — Gate 8.2.
 *
 * Exercises every mode (create / update / delete / restore /
 * apply_draft), the per-user `filterFor()` + `inputSchemaFor()`
 * surface, the validation envelope shape, and the idempotency cache.
 *
 * Free path: the tool must not register at all — `getByName('entry')`
 * returns null and the tool is absent from `asListPayload()`.
 *
 * Pro path: every test wraps its execute in `cortex_with_edition('pro',
 * …)` and re-resolves the tool inside the wrapper because
 * `Tools::_buildRegistry()` ran at boot under the Free edition; calling
 * `Tools::getByName('entry')` on the boot-time registry returns null.
 * We instantiate the tool class directly inside the Pro block to
 * sidestep the boot-time gate — `shouldRegister()` is a static gate
 * that the registry honours, but a direct `new Entry()` is fine
 * because the tool's per-mode permission checks fire regardless.
 *
 * Fixture strategy: write to the playground's `heroes` channel
 * section (one entry type, no parent structure) and clean up by
 * hard-deleting whatever the test created in `afterEach()`. Tests that
 * need a structure section use `teams`. Tests are tolerant of
 * playground state — if the section isn't there, the test skips.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\content\Entry;
use craftpulse\cortex\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Tag every fixture entry with this prefix so cleanup is reliable
    // and doesn't accidentally hard-delete a real playground entry.
    $this->fixturePrefix = '__cortex_entrytest_' . bin2hex(random_bytes(4)) . '_';

    // Bind the admin user as the dispatch identity. Per-mode permission
    // tests later switch to a non-admin or a User-with-permissions to
    // exercise the boundary.
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    // Hard-delete every test-created entry so subsequent tests start
    // clean and the playground database doesn't accumulate junk.
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

/**
 * Direct instantiation sidesteps the boot-time registry gate. The
 * registry was built when the plugin booted under the Free edition;
 * by the time the Pro-edition helper runs, the registry no longer
 * has `entry`. The tool class itself doesn't care which edition is
 * active at execute-time — `shouldRegister()` is a registry concern,
 * not an `execute()` concern.
 */
function _cortex_entry_tool(): Entry
{
    return new Entry();
}

/**
 * Pick a section that should be safe to write test entries into.
 * Returns the section model or null when the playground doesn't
 * carry one with this handle.
 */
function _cortex_section(string $handle): ?\craft\models\Section
{
    return Craft::$app->getEntries()->getSectionByHandle($handle);
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Cortex::getInstance()->tools->getByName('entry'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Cortex::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('entry');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Entry::shouldRegister())->toBeFalse();

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(Entry::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_entry_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_entry_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true — stdio is trusted', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(_cortex_entry_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect($this->admin)->toBeInstanceOf(User::class);
        expect(_cortex_entry_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users with no entry permissions on any section', function() {
    // Build a minimal non-admin user in a group that has zero
    // entry permissions. We synthesise a fresh `User` element each
    // run to keep state out of the playground.
    $user = new User();
    $user->username = '__cortex_no_perms_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($user) {
            expect(_cortex_entry_tool()->filterFor($user))->toBeFalse();
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// inputSchemaFor — mode-enum filtering
// -----------------------------------------------------------------------------

it('inputSchemaFor returns the full mode enum for admins', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $schema = _cortex_entry_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])->toBe([
            'create', 'update', 'delete', 'restore', 'apply_draft',
        ]);
    });
});

it('inputSchemaFor returns the full mode enum for stdio (null user)', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $schema = _cortex_entry_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])->toBe([
            'create', 'update', 'delete', 'restore', 'apply_draft',
        ]);
    });
});

// -----------------------------------------------------------------------------
// create — happy path
// -----------------------------------------------------------------------------

it('create mode round-trips against a test section as admin', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $title = $this->fixturePrefix . 'happy';
        $result = _cortex_entry_tool()->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $title,
        ]);

        expect($result)->toHaveKeys(['success', 'mode', 'entry']);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['entry']['title'])->toBe($title);
        expect($result['entry']['id'])->toBeInt();

        $reloaded = EntryElement::find()->id($result['entry']['id'])->status(null)->one();
        expect($reloaded)->toBeInstanceOf(EntryElement::class);
        expect($reloaded->title)->toBe($title);
    });
});

it('create mode defaults authorId to the dispatch user', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $title = $this->fixturePrefix . 'author';
        $result = _cortex_entry_tool()->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $title,
        ]);

        $reloaded = EntryElement::find()->id($result['entry']['id'])->status(null)->one();
        expect($reloaded)->toBeInstanceOf(EntryElement::class);
        expect($reloaded->getAuthorIds())->toContain((int) $this->admin->id);
    });
});

// -----------------------------------------------------------------------------
// create — argument validation
// -----------------------------------------------------------------------------

it('create mode throws when no section-identifying argument is given', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_entry_tool()->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'nosec',
        ]);
    });
})->throws(ToolException::class);

it('create mode throws when sectionHandle does not resolve', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_entry_tool()->execute([
            'mode' => 'create',
            'sectionHandle' => '__not_a_real_section_handle_x9z__',
            'title' => $this->fixturePrefix . 'bogus',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// update — happy path
// -----------------------------------------------------------------------------

it('update mode mutates an existing entry and persists changes', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();

        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'pre-update',
        ]);
        $id = $created['entry']['id'];

        $updated = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'title' => $this->fixturePrefix . 'post-update',
        ]);

        expect($updated['success'])->toBeTrue();
        expect($updated['mode'])->toBe('update');
        expect($updated['entry']['title'])->toBe($this->fixturePrefix . 'post-update');

        $reloaded = EntryElement::find()->id($id)->status(null)->one();
        expect($reloaded->title)->toBe($this->fixturePrefix . 'post-update');
    });
});

it('update mode throws when the entry does not exist', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_entry_tool()->execute([
            'mode' => 'update',
            'id' => 99999999,
            'title' => $this->fixturePrefix . 'phantom',
        ]);
    });
})->throws(ToolException::class);

it('update mode refuses a trashed entry with a restore-hint message', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'trashed-update',
        ]);
        $id = $created['entry']['id'];

        // Soft-delete via the tool to put the entry in the trash.
        $tool->execute(['mode' => 'delete', 'id' => $id]);

        // Attempting to update the trashed entry must refuse with a
        // message naming `restore` (so the LLM can recover) and
        // `hardDelete` (so it sees the alternative for permanent
        // removal). The trashed entry must not be touched.
        $caught = null;
        try {
            $tool->execute([
                'mode' => 'update',
                'id' => $id,
                'title' => $this->fixturePrefix . 'would-untrash',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('is trashed');
        expect($caught->getMessage())->toContain('mode=restore');
        expect($caught->getMessage())->toContain('hardDelete=true');

        // Defense-in-depth: the trashed entry's title is unchanged
        // (no silent un-trash + re-save).
        $stillTrashed = EntryElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($stillTrashed)->toBeInstanceOf(EntryElement::class);
        expect($stillTrashed->title)->toBe($this->fixturePrefix . 'trashed-update');
    });
});

// -----------------------------------------------------------------------------
// delete — soft + hard
// -----------------------------------------------------------------------------

it('delete mode soft-deletes by default; entry reappears with trashed()', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'soft',
        ]);
        $id = $created['entry']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect($deleted)->toMatchArray([
            'success' => true,
            'mode' => 'delete',
            'id' => $id,
            'hardDeleted' => false,
        ]);

        // Default query excludes trashed.
        expect(EntryElement::find()->id($id)->status(null)->one())->toBeNull();
        // Trashed query finds it.
        $trashed = EntryElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($trashed)->toBeInstanceOf(EntryElement::class);
    });
});

it('delete mode with hardDelete=true removes the row entirely', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'hard',
        ]);
        $id = $created['entry']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id, 'hardDelete' => true]);
        expect($deleted['hardDeleted'])->toBeTrue();

        // Neither default nor trashed query finds it.
        expect(EntryElement::find()->id($id)->status(null)->trashed(null)->one())->toBeNull();
    });
});

// -----------------------------------------------------------------------------
// restore
// -----------------------------------------------------------------------------

it('restore mode brings a soft-deleted entry back', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'restore',
        ]);
        $id = $created['entry']['id'];

        $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect(EntryElement::find()->id($id)->status(null)->one())->toBeNull();

        $restored = $tool->execute(['mode' => 'restore', 'id' => $id]);
        expect($restored['success'])->toBeTrue();
        expect($restored['mode'])->toBe('restore');
        expect($restored['entry']['id'])->toBe($id);

        expect(EntryElement::find()->id($id)->status(null)->one())->toBeInstanceOf(EntryElement::class);
    });
});

it('restore mode throws when the entry is not trashed', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'notrashed',
        ]);

        $tool->execute(['mode' => 'restore', 'id' => $created['entry']['id']]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// apply_draft
// -----------------------------------------------------------------------------

it('apply_draft mode applies the draft to its canonical', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();

        // 1. Create a canonical.
        $created = $tool->execute([
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'draft-canon',
        ]);
        $canonicalId = $created['entry']['id'];

        // 2. Create a draft of it via Craft's drafts service.
        $canonical = EntryElement::find()->id($canonicalId)->status(null)->one();
        $draft = Craft::$app->getDrafts()->createDraft(
            canonical: $canonical,
            creatorId: (int) $this->admin->id,
            name: 'Test draft',
        );
        expect($draft)->toBeInstanceOf(EntryElement::class);

        // 3. Mutate the draft directly so applying produces a visible change.
        $draft->title = $this->fixturePrefix . 'applied';
        $saved = Craft::$app->getElements()->saveElement($draft);
        expect($saved)->toBeTrue();

        // 4. Apply via the tool.
        $applied = $tool->execute([
            'mode' => 'apply_draft',
            'id' => $draft->id,
        ]);
        expect($applied['success'])->toBeTrue();
        expect($applied['mode'])->toBe('apply_draft');

        // 5. Reload canonical; title should reflect the draft's value.
        $reloaded = EntryElement::find()->id($canonicalId)->status(null)->one();
        expect($reloaded->title)->toBe($this->fixturePrefix . 'applied');
    });
});

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($section) {
        $tool = _cortex_entry_tool();
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'idem',
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstId = $first['entry']['id'];
        $firstDateUpdated = $first['entry']['dateUpdated'];

        $second = $tool->execute($args);
        expect($second['entry']['id'])->toBe($firstId);
        expect($second['entry']['dateUpdated'])->toBe($firstDateUpdated);

        // Only one entry exists in the DB.
        $count = (int) EntryElement::find()
            ->status(null)
            ->title($this->fixturePrefix . 'idem')
            ->count();
        expect($count)->toBe(1);
    });
});

it('idempotencyKey does not collide across different users', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    $userA = $this->admin;

    // Create a second user — they don't need permissions for this
    // test because we directly invoke the tool. The point is that
    // the cache key incorporates the user id.
    $userB = new User();
    $userB->username = '__cortex_idem_' . bin2hex(random_bytes(4));
    $userB->email = $userB->username . '@example.test';
    $userB->admin = true; // skip permission check for the test
    if (!Craft::$app->getElements()->saveElement($userB)) {
        $this->markTestSkipped('Could not create second user: ' . json_encode($userB->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($userA, $userB, $section) {
            $tool = _cortex_entry_tool();
            $key = 'idem_' . bin2hex(random_bytes(8));

            Craft::$app->getUser()->setIdentity($userA);
            $rA = $tool->execute([
                'mode' => 'create',
                'sectionHandle' => $section->handle,
                'title' => $this->fixturePrefix . 'idem-A',
                'idempotencyKey' => $key,
            ]);

            Craft::$app->getUser()->setIdentity($userB);
            $rB = $tool->execute([
                'mode' => 'create',
                'sectionHandle' => $section->handle,
                'title' => $this->fixturePrefix . 'idem-B',
                'idempotencyKey' => $key,
            ]);

            expect($rA['entry']['id'])->not->toBe($rB['entry']['id']);
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($userB, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Permission gating
// -----------------------------------------------------------------------------

it('create mode throws -32002-shape ToolException for a user without saveEntries permission', function() {
    $section = _cortex_section('heroes') ?? _cortex_section('teams');
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    $user = new User();
    $user->username = '__cortex_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($section, $user) {
            Craft::$app->getUser()->setIdentity($user);

            try {
                _cortex_entry_tool()->execute([
                    'mode' => 'create',
                    'sectionHandle' => $section->handle,
                    'title' => $this->fixturePrefix . 'denied',
                ]);
                $this->fail('Expected ToolException for non-permitted user');
            } catch (ToolException $e) {
                expect($e->getMessage())->toContain('permission denied');
                expect($e->getMessage())->toContain("saveEntries:{$section->uid}");
            }
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});
