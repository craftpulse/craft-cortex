<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\models\UserGroup;
use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * `permissions_and_groups` tool — permissions tree + user-group structure.
 *
 * Returns:
 *   - **permissions** — the full hierarchical permissions tree as
 *     registered via `EVENT_REGISTER_PERMISSIONS` (built-in + plugin).
 *     Each node has `name`, `label`, optional `info`, and nested
 *     children. The AI uses this to discover what permission strings
 *     exist before suggesting `requirePermission()` calls.
 *   - **groups** — every user group with handle, name, description,
 *     and assigned permission identifiers. **No user data** — group
 *     membership lives in the Pro `users` tool with PII gating.
 *
 * No parameters. Permissions and groups are small and read together.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class PermissionsAndGroups extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'permissions_and_groups';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'The full Craft permissions tree (built-in + plugin-registered) and the ' .
            'user-group structure with each group\'s assigned permissions. Use to discover ' .
            'what permission identifiers exist and how groups carve up access. Returns no ' .
            'user data — membership is Pro-tier with PII gating.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $permissionsService = Craft::$app->getUserPermissions();
        $userGroupsService = Craft::$app->getUserGroups();

        $tree = $permissionsService->getAllPermissions();
        $allFlat = $this->_flatten($tree);

        $groups = array_map(
            function (UserGroup $g) use ($permissionsService): array {
                return [
                    'id' => $g->id,
                    'uid' => $g->uid,
                    'name' => $g->name,
                    'handle' => $g->handle,
                    'description' => $g->description,
                    'permissions' => $permissionsService->getPermissionsByGroupId((int) $g->id),
                ];
            },
            $userGroupsService->getAllGroups(),
        );

        return [
            'permissions' => $tree,
            'permissionCount' => count($allFlat),
            'permissionNames' => $allFlat,
            'groups' => $groups,
            'groupCount' => count($groups),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Walk the permissions tree and collect every permission name into
     * a flat sorted list. Useful for the AI to do membership checks
     * without traversing the nested structure each time.
     *
     * @param array<int|string,mixed> $tree
     * @return string[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _flatten(array $tree): array
    {
        $names = [];

        $walk = function ($node) use (&$walk, &$names): void {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    if (is_string($key) && str_contains($key, ':')) {
                        // The Craft tree uses `prefix:Label` keys at top
                        // level — strip the label.
                    }
                    if (is_array($value)) {
                        // Each entry can be a permission def `[ 'name', 'label', 'nested' ]`
                        // or a category bucket. Walk both.
                        if (isset($value['label']) || isset($value['name'])) {
                            $name = $value['name'] ?? null;
                            if (is_string($name) && $name !== '') {
                                $names[] = $name;
                            }
                            $nested = $value['nested'] ?? null;
                            if (is_array($nested)) {
                                $walk($nested);
                            }
                        } else {
                            $walk($value);
                        }
                    }
                }
            }
        };

        $walk($tree);

        sort($names);
        return array_values(array_unique($names));
    }
}
