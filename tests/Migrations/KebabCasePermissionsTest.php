<?php

/**
 * =========================================================================
 * Covers the camelCase-to-kebab-case permission rename migration
 * `m260729_160000_kebab_case_permissions`.
 *
 * Craft lowercases a permission name both when it stores it and when it
 * checks it, so a code-only rename would silently stop matching every
 * existing grant, and it would fail invisibly (admins hold everything
 * implicitly). These tests fixture a pre-rename install, run the
 * migration, and assert the grants land on the kebab name.
 *
 * The headline case for Herald is the skills handle: it was registered
 * WITHOUT the `herald:` prefix (`manageHeraldSkills`), so the rename maps
 * an unprefixed old name onto a namespaced new one. Two tests pin that
 * specifically, plus one that proves the resolver's suffix branch does
 * not mis-handle a key that carries no colon at all, plus one that
 * proves a name that merely starts with the unprefixed key is left
 * alone.
 *
 * Fixture strategy: permission names are fixed handles and cannot be
 * prefixed, so cleanup targets exactly the Herald handle set (old, new,
 * and per-entity forms) along with the throwaway users and groups. The
 * content fixtures pre-seed a real `herald:view-activity` grant on a
 * non-admin user, and that grant must survive this suite.
 *
 * A row-id snapshot is not enough: `m260729_160000_kebab_case_permissions`
 * merges onto an already-existing target row by deleting BOTH the old and
 * the new permission rows and reinserting the new name under a fresh id
 * (`_moveGrants()`), so a permission id captured before a migration run is
 * not the id the surviving grant lives on afterward. `afterEach` therefore
 * cleans up by orphan status instead of by id: it removes this test's own
 * throwaway users and groups FIRST (their join rows cascade away with
 * them), then deletes only the Herald-handle-set permission rows left with
 * no grantee at all. A row a real, pre-existing grantee still holds is
 * never a candidate, regardless of which id it ends up on.
 *
 * **SEQUENTIAL ONLY** — one test writes to
 * `users.groups.<uid>.permissions` in project config. Concurrent readers
 * of the same PC surface would race this test's set/remove window.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\records\UserGroup as UserGroupRecord;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\elements\Skill;
use craftpulse\herald\Herald;
use craftpulse\herald\migrations\m260729_160000_kebab_case_permissions;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = 'herald-permtest-' . bin2hex(random_bytes(4)) . '-';
    $this->fixtureGroupIds = [];
});

afterEach(function() {
    foreach ($this->fixtureGroupIds as $groupId) {
        UserGroupRecord::deleteAll(['id' => $groupId]);
    }

    $users = User::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();

    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    // The throwaway grantees are gone (and their join rows with them, via
    // FK CASCADE), so any Herald-handle-set row with no grantee left is
    // safe to remove. A row a real, pre-existing grantee still holds is
    // not a candidate here regardless of which id the migration left it
    // on.
    _herald_delete_orphaned_permissions();

    Craft::$app->getUserPermissions()->reset();
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Every Herald permission name this suite may create, in both its
 * pre-rename and post-rename form, plus the per-entity shapes. Used to
 * scope `afterEach` cleanup.
 *
 * @return array<int, mixed>
 */
function _herald_permission_cleanup_condition(): array
{
    $names = [
        'herald:managesettings',
        'herald:managegrants',
        'herald:viewactivity',
        'manageheraldskills',
        'manageheraldskillsextra',
        SettingsController::PERMISSION_MANAGE_SETTINGS,
        SettingsController::PERMISSION_MANAGE_GRANTS,
        Herald::PERMISSION_VIEW_ACTIVITY,
        Skill::PERMISSION_MANAGE,
    ];

    return [
        'or',
        ['name' => $names],
        ['like', 'name', 'herald:view-activity:%', false],
        ['like', 'name', 'herald:viewactivity:%', false],
        ['like', 'name', 'herald:manage-skills:%', false],
        ['like', 'name', 'manageheraldskills:%', false],
    ];
}

/**
 * Grants a permission by its raw stored name, bypassing the
 * `UserPermissions` service so a pre-rename (camelCase, lowercased) name
 * can be fixtured. The service filters orphaned handles, which is
 * exactly what makes it unusable here.
 *
 * `userpermissions.name` is UNIQUE, so an already-stored name is reused
 * rather than re-inserted. Use `_herald_permission_id()` beforehand when
 * the caller needs to know whether it owns the row.
 */
function _herald_grant_raw_permission(string $name, ?int $userId = null, ?int $groupId = null): int
{
    $db = Craft::$app->getDb();
    $permissionId = _herald_permission_id($name);

    if ($permissionId === null) {
        $db->createCommand()->insert(CraftTable::USERPERMISSIONS, ['name' => $name])->execute();
        $permissionId = (int) $db->getLastInsertID(CraftTable::USERPERMISSIONS);
    }

    if ($userId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERS, ['permissionId' => $permissionId, 'userId' => $userId])
            ->execute();
    }

    if ($groupId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERGROUPS, ['permissionId' => $permissionId, 'groupId' => $groupId])
            ->execute();
    }

    return $permissionId;
}

/**
 * Returns the id of the stored permission row with the given name, or
 * null when it is not stored.
 */
function _herald_permission_id(string $name): ?int
{
    $id = (new Query())
        ->select(['id'])
        ->from([CraftTable::USERPERMISSIONS])
        ->where(['name' => $name])
        ->scalar(Craft::$app->getDb());

    return ($id === false || $id === null) ? null : (int) $id;
}

/**
 * Deletes every Herald-handle-set permission row that no longer has any
 * grantee, in either grant table. Called after this test's own throwaway
 * users and groups are already gone, so a row a real grantee (for
 * example, the content fixtures' non-admin `herald:view-activity` holder)
 * still holds is never a candidate, no matter which id the rename
 * migration left the row on.
 */
function _herald_delete_orphaned_permissions(): void
{
    $candidateIds = (new Query())
        ->select(['id'])
        ->from([CraftTable::USERPERMISSIONS])
        ->where(_herald_permission_cleanup_condition())
        ->column();

    if ($candidateIds === []) {
        return;
    }

    $grantedIds = array_unique(array_merge(
        (new Query())
            ->select(['permissionId'])
            ->from([CraftTable::USERPERMISSIONS_USERS])
            ->where(['permissionId' => $candidateIds])
            ->column(),
        (new Query())
            ->select(['permissionId'])
            ->from([CraftTable::USERPERMISSIONS_USERGROUPS])
            ->where(['permissionId' => $candidateIds])
            ->column(),
    ));

    $orphanIds = array_diff($candidateIds, $grantedIds);

    if ($orphanIds === []) {
        return;
    }

    Craft::$app->getDb()->createCommand()
        ->delete(CraftTable::USERPERMISSIONS, ['id' => array_values($orphanIds)])
        ->execute();
}

/**
 * Returns the permission names granted directly to a user.
 *
 * @return string[]
 */
function _herald_user_permission_names(int $userId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pu' => CraftTable::USERPERMISSIONS_USERS], '[[pu.permissionId]] = [[p.id]]')
        ->where(['pu.userId' => $userId])
        ->column();
}

/**
 * Returns the permission names granted to a user group.
 *
 * @return string[]
 */
function _herald_group_permission_names(int $groupId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pg' => CraftTable::USERPERMISSIONS_USERGROUPS], '[[pg.permissionId]] = [[p.id]]')
        ->where(['pg.groupId' => $groupId])
        ->column();
}

/**
 * Returns every stored permission name in the Herald handle set.
 *
 * @return string[]
 */
function _herald_stored_permission_names(): array
{
    return (new Query())
        ->select(['name'])
        ->from([CraftTable::USERPERMISSIONS])
        ->where(_herald_permission_cleanup_condition())
        ->column();
}

/**
 * Creates a bare user for a grant fixture.
 *
 * @throws Throwable
 * @throws \craft\errors\ElementNotFoundException
 * @throws \yii\base\Exception
 */
function _herald_permission_user(string $prefix): User
{
    $suffix = bin2hex(random_bytes(4));

    $user = new User();
    $user->username = $prefix . $suffix;
    $user->email = $prefix . $suffix . '@example.test';
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

/**
 * Creates a throwaway user group record. Written straight to the record
 * so the fixture needs no particular Craft edition and the group's
 * permission list stays under this test's control.
 */
function _herald_permission_group(): UserGroupRecord
{
    $handle = 'heraldPerm' . bin2hex(random_bytes(4));

    $group = new UserGroupRecord();
    $group->name = $handle;
    $group->handle = $handle;
    $group->uid = StringHelper::UUID();
    $group->save(false);

    return $group;
}

// -----------------------------------------------------------------------------
// Prefixed handles
// -----------------------------------------------------------------------------

it('moves a user grant from the camelCase name to the kebab name', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    _herald_grant_raw_permission('herald:managesettings', userId: (int) $user->id);

    expect(_herald_user_permission_names((int) $user->id))->toContain('herald:managesettings');

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_user_permission_names((int) $user->id))
        ->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS)
        ->not->toContain('herald:managesettings');
});

it('moves a group grant onto the kebab name and drops the old row', function() {
    $group = _herald_permission_group();
    $this->fixtureGroupIds[] = (int) $group->id;
    _herald_grant_raw_permission('herald:managegrants', groupId: (int) $group->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_group_permission_names((int) $group->id))
        ->toContain(SettingsController::PERMISSION_MANAGE_GRANTS)
        ->not->toContain('herald:managegrants')
        ->and(_herald_stored_permission_names())->not->toContain('herald:managegrants');
});

// -----------------------------------------------------------------------------
// The unprefixed handle — `manageHeraldSkills` becomes `herald:manage-skills`
// -----------------------------------------------------------------------------

it('namespaces the unprefixed skills handle onto herald:manage-skills', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    $group = _herald_permission_group();
    $this->fixtureGroupIds[] = (int) $group->id;
    _herald_grant_raw_permission('manageheraldskills', userId: (int) $user->id, groupId: (int) $group->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(Skill::PERMISSION_MANAGE)->toBe('herald:manage-skills')
        ->and(_herald_user_permission_names((int) $user->id))
        ->toContain('herald:manage-skills')
        ->not->toContain('manageheraldskills')
        ->and(_herald_group_permission_names((int) $group->id))
        ->toContain('herald:manage-skills')
        ->not->toContain('manageheraldskills')
        ->and(_herald_stored_permission_names())->not->toContain('manageheraldskills');
});

it('reverses the unprefixed skills handle back to manageHeraldSkills on the way down', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    _herald_grant_raw_permission('manageheraldskills', userId: (int) $user->id);

    $migration = new m260729_160000_kebab_case_permissions();
    $migration->safeUp();

    expect(_herald_user_permission_names((int) $user->id))->toContain('herald:manage-skills');

    $migration->safeDown();

    expect(_herald_user_permission_names((int) $user->id))
        ->toContain('manageheraldskills')
        ->not->toContain('herald:manage-skills');
});

it('leaves a name that merely starts with the unprefixed key alone', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    _herald_grant_raw_permission('manageheraldskillsextra', userId: (int) $user->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_user_permission_names((int) $user->id))
        ->toContain('manageheraldskillsextra')
        ->not->toContain('herald:manage-skillsextra');
});

// -----------------------------------------------------------------------------
// Per-entity (colon-suffixed) shapes
// -----------------------------------------------------------------------------

it('preserves the entity suffix on an unprefixed key, which has no colon of its own', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    $uid = StringHelper::UUID();
    _herald_grant_raw_permission("manageheraldskills:{$uid}", userId: (int) $user->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_user_permission_names((int) $user->id))
        ->toContain("herald:manage-skills:{$uid}")
        ->not->toContain("manageheraldskills:{$uid}");
});

it('preserves the entity suffix on a prefixed key', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    $uid = StringHelper::UUID();
    _herald_grant_raw_permission("herald:viewactivity:{$uid}", userId: (int) $user->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_user_permission_names((int) $user->id))
        ->toContain("herald:view-activity:{$uid}")
        ->not->toContain("herald:viewactivity:{$uid}");
});

// -----------------------------------------------------------------------------
// Project config
// -----------------------------------------------------------------------------

it('rewrites a group project-config permission list, leaving its other handles alone', function() {
    $group = _herald_permission_group();
    $this->fixtureGroupIds[] = (int) $group->id;

    $projectConfig = Craft::$app->getProjectConfig();
    $path = sprintf('users.groups.%s.permissions', $group->uid);

    // Events muted and read-only lifted around the fixture write and its
    // teardown: the group record already exists, so nothing needs to
    // reconcile, and the fixture must land regardless of allowAdminChanges.
    $muteEvents = $projectConfig->muteEvents;
    $readOnly = $projectConfig->readOnly;
    $projectConfig->muteEvents = true;
    $projectConfig->readOnly = false;

    try {
        $projectConfig->set($path, ['accesscp', 'herald:viewactivity', 'manageheraldskills']);

        (new m260729_160000_kebab_case_permissions())->safeUp();

        expect($projectConfig->get($path))
            ->toContain(Herald::PERMISSION_VIEW_ACTIVITY)
            ->toContain(Skill::PERMISSION_MANAGE)
            ->toContain('accesscp')
            ->not->toContain('herald:viewactivity')
            ->not->toContain('manageheraldskills');
    } finally {
        $projectConfig->remove(sprintf('users.groups.%s', $group->uid));
        $projectConfig->muteEvents = $muteEvents;
        $projectConfig->readOnly = $readOnly;
    }
});

// -----------------------------------------------------------------------------
// Safety properties
// -----------------------------------------------------------------------------

it('is idempotent: running twice does not duplicate or drop grants', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    $group = _herald_permission_group();
    $this->fixtureGroupIds[] = (int) $group->id;
    _herald_grant_raw_permission('manageheraldskills', userId: (int) $user->id, groupId: (int) $group->id);

    $migration = new m260729_160000_kebab_case_permissions();
    $migration->safeUp();
    $migration->safeUp();

    expect(_herald_user_permission_names((int) $user->id))->toBe([Skill::PERMISSION_MANAGE])
        ->and(_herald_group_permission_names((int) $group->id))->toBe([Skill::PERMISSION_MANAGE]);
});

it('unions the grant sets when the new name already carries grants', function() {
    $oldGrantee = _herald_permission_user($this->fixturePrefix);
    $newGrantee = _herald_permission_user($this->fixturePrefix);
    _herald_grant_raw_permission('herald:viewactivity', userId: (int) $oldGrantee->id);
    _herald_grant_raw_permission(Herald::PERMISSION_VIEW_ACTIVITY, userId: (int) $newGrantee->id);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    expect(_herald_user_permission_names((int) $oldGrantee->id))->toContain(Herald::PERMISSION_VIEW_ACTIVITY)
        ->and(_herald_user_permission_names((int) $newGrantee->id))->toContain(Herald::PERMISSION_VIEW_ACTIVITY);
});

it('leaves an install with no camelCase Herald grants untouched', function() {
    $before = _herald_stored_permission_names();
    sort($before);

    (new m260729_160000_kebab_case_permissions())->safeUp();

    $after = _herald_stored_permission_names();
    sort($after);

    expect($after)->toBe($before);
});

it('does not touch permissions owned by Craft or other plugins', function() {
    $user = _herald_permission_user($this->fixturePrefix);
    $uid = StringHelper::UUID();
    $names = ['accesscp', 'editusers', 'viewusers', 'administrateusers', 'utility:queue-manager', "saveentries:{$uid}"];

    // Only rows this test creates get cleaned up; the playground's own core
    // permission rows are reused and left in place. The grant join rows go
    // away with the fixture user (FK CASCADE) in `afterEach`.
    $createdIds = [];

    foreach ($names as $name) {
        $preExisting = _herald_permission_id($name) !== null;
        $permissionId = _herald_grant_raw_permission($name, userId: (int) $user->id);

        if (!$preExisting) {
            $createdIds[] = $permissionId;
        }
    }

    try {
        (new m260729_160000_kebab_case_permissions())->safeUp();

        $granted = _herald_user_permission_names((int) $user->id);

        foreach ($names as $name) {
            expect($granted)->toContain($name);
        }
    } finally {
        if ($createdIds !== []) {
            Craft::$app->getDb()->createCommand()
                ->delete(CraftTable::USERPERMISSIONS, ['id' => $createdIds])
                ->execute();
        }
    }
});
