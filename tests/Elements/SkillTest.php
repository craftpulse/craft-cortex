<?php

/**
 * =========================================================================
 * Element-class tests for the Cortex `Skill` element — Gate 8.6.
 *
 * Covers:
 *   - Save round-trip + field round-trip via the native handle +
 *     description attributes.
 *   - Handle uniqueness via `validateHandleUnique` (DB UNIQUE catches
 *     the same case at the integrity layer).
 *   - `canView` / `canSave` / `canDelete` / `canDuplicate` permission
 *     resolution for admin, permitted, and unpermitted users.
 *   - Soft-delete + restore lifecycle, including
 *     `Cortex::getInstance()->skills->resetMemo()` firing on each
 *     lifecycle event.
 *   - `SkillQuery::handle()` filter returns the saved row.
 *
 * Fixture strategy: prefix every handle with
 * `cortex-skilltest-<hex>-` (slug-shaped to satisfy
 * `Skill::HANDLE_PATTERN`) so `afterEach` can `LIKE`-hard-delete the
 * entire test run. No global state — element overrides land in the DB
 * and are gone by next test.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\elements\Skill;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Slug-shaped so handles satisfy Skill::HANDLE_PATTERN
    // (lowercase letters, digits, single hyphens).
    $this->fixturePrefix = 'cortex-skilltest-' . bin2hex(random_bytes(4)) . '-';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = Skill::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'cortex_skills.handle', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }

    // Hard-delete any throwaway users this test created.
    $users = User::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    Cortex::getInstance()->skills->resetMemo();
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _cortex_skill_save(string $handle, string $title, ?string $description = null): Skill
{
    $skill = new Skill();
    $skill->handle = $handle;
    $skill->title = $title;
    $skill->description = $description;
    expect(Craft::$app->getElements()->saveElement($skill))->toBeTrue();
    return $skill;
}

// -----------------------------------------------------------------------------
// Element type registration
// -----------------------------------------------------------------------------

it('is registered with Craft as an element type', function() {
    $registered = Craft::$app->getElements()->getAllElementTypes();
    expect($registered)->toContain(Skill::class);
});

it('exposes the expected static surface', function() {
    expect(Skill::displayName())->toBe('Skill');
    expect(Skill::pluralDisplayName())->toBe('Skills');
    expect(Skill::refHandle())->toBe('skill');
    expect(Skill::hasTitles())->toBeTrue();
    expect(Skill::hasStatuses())->toBeFalse();
    expect(Skill::hasUris())->toBeFalse();
    expect(Skill::trackChanges())->toBeFalse();
});

// -----------------------------------------------------------------------------
// Save round-trip
// -----------------------------------------------------------------------------

it('round-trips a saved skill through Skill::find()', function() {
    $handle = $this->fixturePrefix . 'roundtrip';
    $skill = _cortex_skill_save($handle, 'Round-trip skill', 'A short description.');

    $reloaded = Skill::find()->status(null)->site('*')->id($skill->id)->one();
    expect($reloaded)->toBeInstanceOf(Skill::class);
    expect($reloaded->handle)->toBe($handle);
    expect($reloaded->title)->toBe('Round-trip skill');
    expect($reloaded->description)->toBe('A short description.');

    $byHandle = Skill::find()->status(null)->site('*')->handle($handle)->one();
    expect($byHandle)->toBeInstanceOf(Skill::class);
    expect($byHandle->id)->toBe($skill->id);
});

it('persists a row in the cortex_skills table after save', function() {
    $handle = $this->fixturePrefix . 'dbrow';
    $skill = _cortex_skill_save($handle, 'DB row check');

    $row = (new \craft\db\Query())
        ->select(['id', 'handle'])
        ->from(\craftpulse\cortex\db\Table::SKILLS)
        ->where(['id' => $skill->id])
        ->one();

    expect($row)->toBeArray();
    expect($row['handle'])->toBe($handle);
});

// -----------------------------------------------------------------------------
// Handle uniqueness
// -----------------------------------------------------------------------------

it('rejects a handle change on an existing skill at the element layer', function() {
    $handle = $this->fixturePrefix . 'immutable';
    $skill = _cortex_skill_save($handle, 'Immutable');

    // Reload and rename the handle, then save via the canonical
    // elements/save path (what the Gate 9.6 CP authoring screen uses).
    $reloaded = Skill::find()->status(null)->site('*')->id($skill->id)->one();
    expect($reloaded)->toBeInstanceOf(Skill::class);
    $reloaded->handle = $this->fixturePrefix . 'renamed';

    expect(Craft::$app->getElements()->saveElement($reloaded))->toBeFalse();
    expect($reloaded->getErrors('handle'))->not->toBeEmpty();

    // The persisted handle is untouched.
    $fresh = Skill::find()->status(null)->site('*')->id($skill->id)->one();
    expect($fresh->handle)->toBe($handle);
});

it('allows re-saving an existing skill with an unchanged handle', function() {
    $handle = $this->fixturePrefix . 'unchanged';
    $skill = _cortex_skill_save($handle, 'Unchanged');

    $reloaded = Skill::find()->status(null)->site('*')->id($skill->id)->one();
    $reloaded->title = 'Unchanged — edited';
    // Handle left as-is.
    expect(Craft::$app->getElements()->saveElement($reloaded))->toBeTrue();
});

it('rejects duplicate handles via validateHandleUnique', function() {
    $handle = $this->fixturePrefix . 'dup';
    _cortex_skill_save($handle, 'First');

    $second = new Skill();
    $second->handle = $handle;
    $second->title = 'Second';
    expect(Craft::$app->getElements()->saveElement($second))->toBeFalse();
    expect($second->getErrors('handle'))->not->toBeEmpty();
});

it('requires a non-empty handle', function() {
    $skill = new Skill();
    $skill->title = 'No handle';
    expect(Craft::$app->getElements()->saveElement($skill))->toBeFalse();
    expect($skill->getErrors('handle'))->not->toBeEmpty();
});

it('rejects a malformed handle that is not a lowercase slug', function(string $handle) {
    $skill = new Skill();
    $skill->handle = $handle;
    $skill->title = 'Malformed';
    expect(Craft::$app->getElements()->saveElement($skill))->toBeFalse();
    expect($skill->getErrors('handle'))->not->toBeEmpty();
})->with([
    'spaces' => 'Foo Bar',
    'slash' => 'a/b',
    'uppercase' => 'FooBar',
    'underscore' => 'foo_bar',
    'leading hyphen' => '-foo',
    'trailing hyphen' => 'foo-',
    'double hyphen' => 'foo--bar',
    'dot' => 'foo.bar',
]);

it('accepts a valid lowercase-slug handle', function(string $handle) {
    $skill = new Skill();
    $skill->handle = $handle;
    $skill->title = 'Valid';
    expect(Craft::$app->getElements()->saveElement($skill))->toBeTrue();
    // Clean up directly — these bypass the fixturePrefix cleanup filter.
    Craft::$app->getElements()->deleteElement($skill, hardDelete: true);
})->with([
    'single word' => 'myskill',
    'dashed' => 'my-skill',
    'with digits' => 'craft-5-guidelines',
    'bundled-style' => 'craft-php-guidelines',
    'short' => 'ddev',
]);

it('rejects recreating a soft-deleted handle with a clean validation error — no IntegrityException, no orphaned element row', function() {
    // BLOCKER (Gate 9 hardening): the DB UNIQUE index on
    // cortex_skills.handle holds the trashed row, so a default
    // (trashed=false) uniqueness probe used to pass, saveElement()
    // persisted the element + elements_sites rows, then afterSave()'s
    // raw SkillRecord save threw an IntegrityException — leaving a
    // half-saved element. validateHandleUnique() must probe the trashed
    // slot and fail closed.
    $handle = $this->fixturePrefix . 'trashedhandle';
    $original = _cortex_skill_save($handle, 'Original');

    // Soft-delete it. The cortex_skills row (and the UNIQUE index entry)
    // survive a soft delete.
    Craft::$app->getElements()->deleteElement($original, hardDelete: false);
    expect(Skill::find()->status(null)->handle($handle)->one())->toBeNull();

    $elementRowsBefore = (new \craft\db\Query())
        ->from(\craft\db\Table::ELEMENTS)
        ->where(['type' => Skill::class])
        ->count();

    // Attempt the colliding create. Must fail closed at validation —
    // no exception, no new element row.
    $clash = new Skill();
    $clash->handle = $handle;
    $clash->title = 'Clash';

    $saved = null;
    try {
        $saved = Craft::$app->getElements()->saveElement($clash);
    } catch (\Throwable $e) {
        $this->fail('Expected a clean validation failure, got ' . $e::class . ': ' . $e->getMessage());
    }

    expect($saved)->toBeFalse();
    expect($clash->getErrors('handle'))->not->toBeEmpty();
    expect($clash->getFirstError('handle'))->toContain('trashed');

    // No orphaned element row: the failed create must not have persisted
    // an `elements` row (the id stays null on a validation-rejected save).
    expect($clash->id)->toBeNull();
    $elementRowsAfter = (new \craft\db\Query())
        ->from(\craft\db\Table::ELEMENTS)
        ->where(['type' => Skill::class])
        ->count();
    expect($elementRowsAfter)->toBe($elementRowsBefore);
});

// -----------------------------------------------------------------------------
// Permission resolution — canView / canSave / canDelete / canDuplicate
// -----------------------------------------------------------------------------

it('admin always passes canSave / canView / canDelete / canDuplicate', function() {
    $skill = _cortex_skill_save($this->fixturePrefix . 'admincan', 'admin');
    expect($skill->canView($this->admin))->toBeTrue();
    expect($skill->canSave($this->admin))->toBeTrue();
    expect($skill->canDelete($this->admin))->toBeTrue();
    expect($skill->canDuplicate($this->admin))->toBeTrue();
});

it('non-admin without permission is denied across the can-suite', function() {
    $skill = _cortex_skill_save($this->fixturePrefix . 'denied', 'denied');

    $user = new User();
    $user->username = $this->fixturePrefix . 'denieduser';
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create unprivileged user');
    }

    expect($skill->canView($user))->toBeFalse();
    expect($skill->canSave($user))->toBeFalse();
    expect($skill->canDelete($user))->toBeFalse();
    expect($skill->canDuplicate($user))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Permission registration
// -----------------------------------------------------------------------------

it('registers `manageCortexSkills` under a `Cortex` heading', function() {
    $permissions = Craft::$app->getUserPermissions()->getAllPermissions();
    $cortexBlock = null;
    foreach ($permissions as $block) {
        if (($block['heading'] ?? null) === 'Cortex') {
            $cortexBlock = $block;
            break;
        }
    }
    expect($cortexBlock)->not->toBeNull();
    expect($cortexBlock['permissions'] ?? [])->toHaveKey(Skill::PERMISSION_MANAGE);
});

// -----------------------------------------------------------------------------
// Lifecycle — afterSave / afterDelete / afterRestore reset the memoized
// merged corpus.  This is one of the four highest-value regression gates.
// -----------------------------------------------------------------------------

it('afterSave / afterDelete / afterRestore reset the memoized corpus', function() {
    // Warm the cache so the memoized array exists.
    $service = Cortex::getInstance()->skills;
    $service->resetMemo();
    $service->getMergedCorpus(); // first read populates the memo

    $handle = $this->fixturePrefix . 'memoreset';
    $skill = _cortex_skill_save($handle, 'memo-reset');

    // afterSave should have reset the memo — the new skill must show
    // up on the very next read. The row's `skill` field carries the
    // handle.
    $rows = $service->getMergedCorpus();
    $handles = array_column($rows, 'skill');
    expect($handles)->toContain($handle);

    // Soft-delete and verify the memo flushes again.
    Craft::$app->getElements()->deleteElement($skill, hardDelete: false);
    $rows = $service->getMergedCorpus();
    $handles = array_column($rows, 'skill');
    expect($handles)->not->toContain($handle);

    // Restore and verify it surfaces again.
    Craft::$app->getElements()->restoreElement($skill);
    $rows = $service->getMergedCorpus();
    $handles = array_column($rows, 'skill');
    expect($handles)->toContain($handle);
});

// -----------------------------------------------------------------------------
// Soft-delete + restore round-trip
// -----------------------------------------------------------------------------

it('soft-deletes and restores cleanly', function() {
    $handle = $this->fixturePrefix . 'softdelete';
    $skill = _cortex_skill_save($handle, 'softdelete');

    Craft::$app->getElements()->deleteElement($skill, hardDelete: false);
    expect(Skill::find()->status(null)->handle($handle)->one())->toBeNull();

    $trashed = Skill::find()->status(null)->trashed(true)->handle($handle)->one();
    expect($trashed)->toBeInstanceOf(Skill::class);

    Craft::$app->getElements()->restoreElement($trashed);
    $restored = Skill::find()->status(null)->handle($handle)->one();
    expect($restored)->toBeInstanceOf(Skill::class);
});

// -----------------------------------------------------------------------------
// Hard-delete wipes the cortex_skills row (FK CASCADE)
// -----------------------------------------------------------------------------

it('hard-delete cascades the cortex_skills row via FK', function() {
    $handle = $this->fixturePrefix . 'hardwipe';
    $skill = _cortex_skill_save($handle, 'hardwipe');
    $skillId = $skill->id;

    Craft::$app->getElements()->deleteElement($skill, hardDelete: true);

    $exists = (new \craft\db\Query())
        ->from(\craftpulse\cortex\db\Table::SKILLS)
        ->where(['id' => $skillId])
        ->exists();
    expect($exists)->toBeFalse();
});
