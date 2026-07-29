<?php

namespace craftpulse\herald\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\services\ProjectConfig as ProjectConfigService;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\elements\Skill;
use craftpulse\herald\Herald;

/**
 * =========================================================================
 * Renames every Herald-owned permission handle to the estate-wide
 * `<prefix>:<kebab-case-action>` shape and carries the existing grants
 * over so nobody loses access:
 *
 *   - `herald:manageSettings` → `herald:manage-settings`
 *   - `herald:manageGrants`   → `herald:manage-grants`
 *   - `herald:viewActivity`   → `herald:view-activity`
 *   - `manageHeraldSkills`    → `herald:manage-skills`
 *
 * The last pair is the odd one out: the skills handle was registered
 * WITHOUT the `herald:` prefix, so this rename both namespaces it and
 * kebab-cases it. An unprefixed old name mapping onto a namespaced new
 * one is why the scan below is composed from the map rather than from a
 * single `herald:%` prefix filter — a prefix filter would never have
 * seen `manageheraldskills` at all.
 *
 * Why the data migration is mandatory: Craft lowercases a permission
 * name both when it stores it and when it checks it (see
 * [[\craft\services\UserPermissions::saveGroupPermissions()]] and
 * [[\craft\services\UserPermissions::doesGroupHavePermission()]]), so
 * the database and project config hold `herald:managesettings` while the
 * renamed code checks `herald:manage-settings`. A code-only rename would
 * stop matching silently, and it would fail invisibly: an admin holds
 * every permission implicitly and would notice nothing, while every
 * non-admin grantee would lose the screen.
 *
 * Herald's schema migrations are folded back into [[Install]] while the
 * plugin is pre-release. This one is not: it is a DATA migration over
 * Craft's own permission tables, which a fresh install has nothing to
 * rewrite in, so it cannot live in [[Install]].
 *
 * For each renamed handle the migration moves the user grants
 * ([[CraftTable::USERPERMISSIONS_USERS]]), the group grants
 * ([[CraftTable::USERPERMISSIONS_USERGROUPS]]), and the project-config
 * group lists (`users.groups.<uid>.permissions`) onto the new name, then
 * drops the old permission row so no dead handle is left behind. Where a
 * row already carries the new name, the two grant sets are unioned
 * rather than replaced, so a grant that somehow already sits on the new
 * name cannot be dropped.
 *
 * Herald owns no per-entity (suffixed) permission today — every handle
 * is stored bare. The resolver still matches a map key as the segment
 * before a `:` suffix and preserves the remainder, so a future
 * `herald:manage-skills:<uid>` shape would carry over correctly rather
 * than being silently dropped. The other colon-suffixed `herald:*`
 * strings in the codebase (`herald:cancel:`, `herald:session:`,
 * `herald:elevation:`, `herald:ratelimit:user:`, `herald:<tool>:idem:`)
 * are cache keys, not permissions, and are deliberately untouched.
 *
 * Craft's own handles are never read: the scan is built from the map, so
 * `accessCp`, `editUsers`, `utility:queue-manager`, `saveEntries:<uid>`
 * and every other core or third-party permission are out of range by
 * construction.
 *
 * The project config is written with events muted and the read-only flag
 * temporarily lifted (the same pairing
 * [[\craft\services\ProjectConfig::rebuild()]] uses): the grant rows are
 * rewritten here directly, so the group-permission change handler has
 * nothing left to reconcile, and the rename must land even on an install
 * running with `allowAdminChanges` disabled.
 *
 * Idempotent: a handle is only touched when a row still carries its old
 * name, and a project-config list is only rewritten when it still
 * carries an old name.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260729_160000_kebab_case_permissions extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeUp(): bool
    {
        $this->_renamePermissions($this->_map());

        return true;
    }

    /**
     * @inheritdoc
     *
     * Reverses the map so a rollback restores the pre-rename handles,
     * including the unprefixed `manageHeraldSkills` form.
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        $this->_renamePermissions(array_flip($this->_map()));

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * The rename map, lowercased on both sides the way Craft stores
     * permission names. Keyed by the old handle, valued with the new
     * handle taken from the constants that now own it.
     *
     * @return array<string, string>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _map(): array
    {
        $map = [
            'herald:manageSettings' => SettingsController::PERMISSION_MANAGE_SETTINGS,
            'herald:manageGrants' => SettingsController::PERMISSION_MANAGE_GRANTS,
            'herald:viewActivity' => Herald::PERMISSION_VIEW_ACTIVITY,
            'manageHeraldSkills' => Skill::PERMISSION_MANAGE,
        ];

        $lowercased = [];

        foreach ($map as $old => $new) {
            $lowercased[strtolower($old)] = strtolower($new);
        }

        return $lowercased;
    }

    /**
     * Moves every grant of each old permission name onto its new name,
     * in the grant tables and in the project-config group lists, then
     * removes the old permission rows.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renamePermissions(array $map): void
    {
        $this->_renameGrants($map);
        $this->_rewriteProjectConfig($map);
    }

    /**
     * Resolves a stored permission name to its renamed form, or null
     * when the map does not cover it.
     *
     * A map key matches either the whole name or the segment before a
     * `:` suffix, so a hypothetical per-entity handle keeps its entity
     * UID. The whole-name branch runs first, which is what keeps an
     * unprefixed key such as `manageheraldskills` resolving to the plain
     * rename instead of falling through to the suffix branch.
     *
     * @param string $storedName The permission name as stored (lowercased by Craft).
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renamedName(string $storedName, array $map): ?string
    {
        $storedName = strtolower($storedName);

        if (isset($map[$storedName])) {
            return $map[$storedName];
        }

        foreach ($map as $oldName => $newName) {
            if (str_starts_with($storedName, $oldName . ':')) {
                return $newName . substr($storedName, strlen($oldName));
            }
        }

        return null;
    }

    /**
     * Repoints the user and group grants of every stored Herald
     * permission the map covers at its new name, dropping the old rows.
     * A no-op for a name that has already been renamed, so the migration
     * can run twice.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     *
     * @throws \yii\db\Exception
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renameGrants(array $map): void
    {
        $rows = (new Query())
            ->select(['id', 'name'])
            ->from([CraftTable::USERPERMISSIONS])
            ->where($this->_scanCondition($map))
            ->all($this->db);

        foreach ($rows as $row) {
            $newName = $this->_renamedName((string) $row['name'], $map);

            if ($newName === null) {
                continue;
            }

            $this->_moveGrants((int) $row['id'], $newName);
        }
    }

    /**
     * Builds the stored-name scan condition from the map itself: an
     * exact match on any old name, or a `<oldName>:%` match for the
     * per-entity shape. Composing it from the map is what guarantees no
     * Craft-owned or third-party handle is ever read, which a blanket
     * `herald:%` filter could not do here because one of the old names
     * carries no prefix at all.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return array<int, mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _scanCondition(array $map): array
    {
        $condition = ['or', ['name' => array_keys($map)]];

        foreach (array_keys($map) as $oldName) {
            $condition[] = ['like', 'name', $oldName . ':%', false];
        }

        return $condition;
    }

    /**
     * Moves one permission row's user and group grants onto the new
     * name. Any row already carrying the new name is folded in rather
     * than overwritten: both grant sets are unioned, so a pre-existing
     * grant on the new name survives.
     *
     * @throws \yii\db\Exception
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _moveGrants(int $oldPermissionId, string $newPermissionName): void
    {
        $newPermissionId = (new Query())
            ->select(['id'])
            ->from([CraftTable::USERPERMISSIONS])
            ->where(['name' => $newPermissionName])
            ->scalar($this->db);

        $permissionIds = [$oldPermissionId];

        if ($newPermissionId !== false && $newPermissionId !== null) {
            $permissionIds[] = (int) $newPermissionId;
        }

        $userIds = $this->_grantees(CraftTable::USERPERMISSIONS_USERS, 'userId', $permissionIds);
        $groupIds = $this->_grantees(CraftTable::USERPERMISSIONS_USERGROUPS, 'groupId', $permissionIds);

        // Drop both rows first (cascading their grants away), then reinsert the
        // new name, so the insert cannot collide with a half-applied run.
        $this->delete(CraftTable::USERPERMISSIONS, ['id' => $permissionIds]);

        $this->insert(CraftTable::USERPERMISSIONS, ['name' => $newPermissionName]);
        $permissionId = $this->db->getLastInsertID(CraftTable::USERPERMISSIONS);

        if ($userIds !== []) {
            $this->batchInsert(
                CraftTable::USERPERMISSIONS_USERS,
                ['permissionId', 'userId'],
                array_map(static fn(int $userId): array => [$permissionId, $userId], $userIds),
            );
        }

        if ($groupIds !== []) {
            $this->batchInsert(
                CraftTable::USERPERMISSIONS_USERGROUPS,
                ['permissionId', 'groupId'],
                array_map(static fn(int $groupId): array => [$permissionId, $groupId], $groupIds),
            );
        }
    }

    /**
     * Returns the distinct grantee ids held against the given permission
     * rows.
     *
     * @param string $table The grant join table.
     * @param string $column The grantee id column on that table.
     * @param array<int, int> $permissionIds
     * @return array<int, int>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _grantees(string $table, string $column, array $permissionIds): array
    {
        $ids = (new Query())
            ->select([$column])
            ->from([$table])
            ->where(['permissionId' => $permissionIds])
            ->column($this->db);

        return array_values(array_unique(array_map(static fn(mixed $id): int => (int) $id, $ids)));
    }

    /**
     * Swaps the old permission names for the new ones in every user
     * group's project-config permission list, keeping Craft's stored
     * shape (lowercased, sorted ascending, sequentially keyed).
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     *
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _rewriteProjectConfig(array $map): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $groups = $projectConfig->get(ProjectConfigService::PATH_USER_GROUPS) ?? [];

        if (!is_array($groups)) {
            return;
        }

        $muteEvents = $projectConfig->muteEvents;
        $readOnly = $projectConfig->readOnly;
        $projectConfig->muteEvents = true;
        $projectConfig->readOnly = false;

        try {
            foreach ($groups as $uid => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $permissions = $group['permissions'] ?? [];

                if (!is_array($permissions) || $permissions === []) {
                    continue;
                }

                $renamed = array_map(
                    fn(mixed $permission): mixed => is_string($permission)
                        ? ($this->_renamedName($permission, $map) ?? $permission)
                        : $permission,
                    $permissions,
                );

                if ($renamed === $permissions) {
                    continue;
                }

                $renamed = array_values(array_unique($renamed));
                sort($renamed);

                $projectConfig->set(
                    sprintf('%s.%s.permissions', ProjectConfigService::PATH_USER_GROUPS, $uid),
                    $renamed,
                    'Rename Herald permission handles to kebab-case',
                );
            }
        } finally {
            $projectConfig->muteEvents = $muteEvents;
            $projectConfig->readOnly = $readOnly;
        }
    }
}
