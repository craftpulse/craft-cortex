<?php

/**
 * =========================================================================
 * `drafts_and_revisions` Pro modes — apply / discard — Gate 8.8a.
 *
 * Exercises:
 *   - The `inputSchemaFor()` mode-enum filter across Free/Pro editions
 *     and stdio/admin/permitted/non-permitted users.
 *   - The `apply` happy path against a real draft+canonical pair.
 *   - The `discard` happy path verifying the draft row is hard-deleted
 *     and the canonical is untouched.
 *   - The permission-denied error-message contract — the locked rich
 *     format `permission denied: mode `X` on section `Y` requires `Z`.`
 *     keyed on by the 8.10 invariant test.
 *   - The cross-edition guard: dispatching `apply` / `discard` on a
 *     Free install throws `ToolException`.
 *
 * Fixture strategy: write to the playground's `heroes` channel section
 * (fall back to `teams`); skip when neither is present. Use a per-test
 * prefix so afterEach() can hard-delete safely.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\workflow\DraftsAndRevisions;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_dar_' . bin2hex(random_bytes(4)) . '_';

    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    // Hard-delete every fixture entry (canonical + any drafts) so the
    // playground stays clean across runs.
    $rows = EntryElement::find()
        ->status(null)
        ->trashed(null)
        ->drafts(null)
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

function _herald_dar_tool(): DraftsAndRevisions
{
    return new DraftsAndRevisions();
}

function _herald_dar_section(): ?\craft\models\Section
{
    return Craft::$app->getEntries()->getSectionByHandle('heroes')
        ?? Craft::$app->getEntries()->getSectionByHandle('teams');
}

// -----------------------------------------------------------------------------
// inputSchemaFor — stdio invariant
// -----------------------------------------------------------------------------

it('inputSchemaFor(null) returns the Free enum on Free (edition gate beats stdio trust)', function() {
    // The runtime execute()-time gate refuses Pro modes on Free
    // regardless of caller (including stdio); the schema mirrors that
    // so an LLM is never advertised a mode the runtime would reject.
    // Pro-install stdio still sees the full enum (next test).
    $schema = _herald_dar_tool()->inputSchemaFor(null);
    expect($schema['properties']['mode']['enum'])->toBe(['list_drafts', 'list_revisions', 'compare']);
});

it('inputSchemaFor(null) returns the full static enum on Pro (stdio invariant)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_dar_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])->toBe([
            'list_drafts', 'list_revisions', 'compare', 'apply', 'discard',
        ]);
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Free edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Free returns the Free enum for admins (HTTP path)', function() {
    // Admin or not, Free-edition HTTP callers don't see Pro modes —
    // the modes don't exist on this install regardless of permission.
    $schema = _herald_dar_tool()->inputSchemaFor($this->admin);
    expect($schema['properties']['mode']['enum'])->toBe(['list_drafts', 'list_revisions', 'compare']);
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Pro edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Pro returns the full enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_dar_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])->toBe([
            'list_drafts', 'list_revisions', 'compare', 'apply', 'discard',
        ]);
    });
});

it('inputSchemaFor on Pro hides the Pro modes from users without saveEntries on any section', function() {
    $user = new User();
    $user->username = '__herald_dar_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $schema = _herald_dar_tool()->inputSchemaFor($user);
            expect($schema['properties']['mode']['enum'])->toBe(['list_drafts', 'list_revisions', 'compare']);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

it('inputSchemaFor on Pro exposes the Pro modes to users with at least one saveEntries permission', function() {
    $section = _herald_dar_section();
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    $user = new User();
    $user->username = '__herald_dar_save_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $user->id,
        [
            "viewEntries:{$section->uid}",
            "saveEntries:{$section->uid}",
            "viewPeerEntries:{$section->uid}",
            "savePeerEntries:{$section->uid}",
        ],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            // Re-fetch the user so the freshly-saved permission rows are
            // visible to `can()` — `User::can()` caches on the live
            // instance otherwise.
            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            expect($reloaded)->toBeInstanceOf(User::class);
            $schema = _herald_dar_tool()->inputSchemaFor($reloaded);
            expect($schema['properties']['mode']['enum'])->toBe([
                'list_drafts', 'list_revisions', 'compare', 'apply', 'discard',
            ]);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// apply — happy path
// -----------------------------------------------------------------------------

it('apply mode applies the draft to its canonical and returns the locked envelope shape', function() {
    $section = _herald_dar_section();
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section) {
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('hero')
            ?? Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
        if ($entryType === null) {
            $this->markTestSkipped('No entry type available for the section.');
        }

        // Seed canonical.
        $canonical = new EntryElement();
        $canonical->sectionId = $section->id;
        $canonical->typeId = (int) $entryType->id;
        $canonical->title = $this->fixturePrefix . 'canon';
        if (!Craft::$app->getElements()->saveElement($canonical)) {
            $this->markTestSkipped('Could not seed canonical: ' . json_encode($canonical->getErrors()));
        }

        // Create + mutate the draft.
        $draft = Craft::$app->getDrafts()->createDraft(
            canonical: $canonical,
            creatorId: (int) $this->admin->id,
            name: 'Test draft',
        );
        expect($draft)->toBeInstanceOf(EntryElement::class);
        $draft->title = $this->fixturePrefix . 'applied';
        expect(Craft::$app->getElements()->saveElement($draft))->toBeTrue();

        $draftId = (int) $draft->id;

        $result = _herald_dar_tool()->execute([
            'mode' => 'apply',
            'id' => $draftId,
        ]);

        expect($result)->toHaveKeys([
            'success', 'mode', 'appliedDraftId', 'canonicalId', 'canonicalUid', 'siteHandle',
        ]);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('apply');
        expect($result['appliedDraftId'])->toBe($draftId);
        expect($result['canonicalId'])->toBe((int) $canonical->id);
        expect($result['canonicalUid'])->toBe($canonical->uid);

        $reloaded = EntryElement::find()->id((int) $canonical->id)->status(null)->one();
        expect($reloaded)->toBeInstanceOf(EntryElement::class);
        expect($reloaded->title)->toBe($this->fixturePrefix . 'applied');
    });
});

it('apply mode throws when no draft id/uid is supplied', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_dar_tool()->execute(['mode' => 'apply']);
    });
})->throws(ToolException::class);

it('apply mode throws when the draft id does not resolve', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_dar_tool()->execute(['mode' => 'apply', 'id' => 99999999]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// apply — permission gating
// -----------------------------------------------------------------------------

it('apply mode throws permission-denied with the locked rich-format message for non-permitted users', function() {
    $section = _herald_dar_section();
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('hero')
        ?? Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
    if ($entryType === null) {
        $this->markTestSkipped('No entry type available for the section.');
    }

    $canonical = new EntryElement();
    $canonical->sectionId = $section->id;
    $canonical->typeId = (int) $entryType->id;
    $canonical->title = $this->fixturePrefix . 'denied-canon';
    if (!Craft::$app->getElements()->saveElement($canonical)) {
        $this->markTestSkipped('Could not seed canonical: ' . json_encode($canonical->getErrors()));
    }

    $draft = Craft::$app->getDrafts()->createDraft(
        canonical: $canonical,
        creatorId: (int) $this->admin->id,
        name: 'Denied draft',
    );

    $user = new User();
    $user->username = '__herald_dar_denied_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($draft, $user, $section) {
            Craft::$app->getUser()->setIdentity($user);

            $caught = null;
            try {
                _herald_dar_tool()->execute([
                    'mode' => 'apply',
                    'id' => (int) $draft->id,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toMatch(
                "/^permission denied: mode `apply` on section `{$section->uid}` requires `saveEntries:{$section->uid}`\\.\$/",
            );
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// discard — happy path
// -----------------------------------------------------------------------------

it('discard mode hard-deletes the draft and leaves the canonical untouched', function() {
    $section = _herald_dar_section();
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section) {
        $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('hero')
            ?? Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
        if ($entryType === null) {
            $this->markTestSkipped('No entry type available for the section.');
        }

        $canonical = new EntryElement();
        $canonical->sectionId = $section->id;
        $canonical->typeId = (int) $entryType->id;
        $canonical->title = $this->fixturePrefix . 'discard-canon';
        if (!Craft::$app->getElements()->saveElement($canonical)) {
            $this->markTestSkipped('Could not seed canonical: ' . json_encode($canonical->getErrors()));
        }

        $draft = Craft::$app->getDrafts()->createDraft(
            canonical: $canonical,
            creatorId: (int) $this->admin->id,
            name: 'Discardable draft',
        );
        $draftId = (int) $draft->id;
        $canonicalTitle = $canonical->title;

        $result = _herald_dar_tool()->execute([
            'mode' => 'discard',
            'id' => $draftId,
        ]);

        expect($result)->toHaveKeys([
            'success', 'mode', 'discardedDraftId', 'canonicalId', 'canonicalUid',
        ]);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('discard');
        expect($result['discardedDraftId'])->toBe($draftId);
        expect($result['canonicalId'])->toBe((int) $canonical->id);

        // Draft row is gone (hard-delete).
        $survivingDraft = EntryElement::find()
            ->id($draftId)
            ->drafts(true)
            ->status(null)
            ->trashed(null)
            ->one();
        expect($survivingDraft)->toBeNull();

        // Canonical untouched.
        $reloaded = EntryElement::find()->id((int) $canonical->id)->status(null)->one();
        expect($reloaded)->toBeInstanceOf(EntryElement::class);
        expect($reloaded->title)->toBe($canonicalTitle);
    });
});

it('discard mode throws permission-denied with the locked rich-format message', function() {
    $section = _herald_dar_section();
    if ($section === null) {
        $this->markTestSkipped('No writable section available in playground.');
    }

    $entryType = Craft::$app->getEntries()->getEntryTypeByHandle('hero')
        ?? Craft::$app->getEntries()->getEntryTypesBySectionId((int) $section->id)[0] ?? null;
    if ($entryType === null) {
        $this->markTestSkipped('No entry type available for the section.');
    }

    $canonical = new EntryElement();
    $canonical->sectionId = $section->id;
    $canonical->typeId = (int) $entryType->id;
    $canonical->title = $this->fixturePrefix . 'discard-denied-canon';
    if (!Craft::$app->getElements()->saveElement($canonical)) {
        $this->markTestSkipped('Could not seed canonical: ' . json_encode($canonical->getErrors()));
    }

    $draft = Craft::$app->getDrafts()->createDraft(
        canonical: $canonical,
        creatorId: (int) $this->admin->id,
        name: 'Discard-denied draft',
    );

    $user = new User();
    $user->username = '__herald_dar_discard_denied_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($draft, $user, $section) {
            Craft::$app->getUser()->setIdentity($user);

            $caught = null;
            try {
                _herald_dar_tool()->execute([
                    'mode' => 'discard',
                    'id' => (int) $draft->id,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toMatch(
                "/^permission denied: mode `discard` on section `{$section->uid}` requires `saveEntries:{$section->uid}`\\.\$/",
            );
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Cross-edition guard
// -----------------------------------------------------------------------------

it('apply throws "mode unavailable on this edition" on a Free install', function() {
    // Default edition in tests is Free — no wrapper needed.
    _herald_dar_tool()->execute(['mode' => 'apply', 'id' => 1]);
})->throws(ToolException::class, 'unavailable on this edition');

it('discard throws "mode unavailable on this edition" on a Free install', function() {
    _herald_dar_tool()->execute(['mode' => 'discard', 'id' => 1]);
})->throws(ToolException::class, 'unavailable on this edition');
