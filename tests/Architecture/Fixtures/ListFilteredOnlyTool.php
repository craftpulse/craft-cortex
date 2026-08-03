<?php

namespace craftpulse\herald\tests\Architecture\Fixtures;

use craft\elements\User;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * Test fixture — a tool that checks permissions in `filterFor()` and
 * nowhere else.
 *
 * This is the `craft_command` regression in miniature. The tool looked
 * guarded, because a caller without the permission never saw it in
 * `tools/list`, but `filterFor()` is tool-selection UX for the model and
 * any caller reaching `execute()` by another route was unrefused.
 *
 * The detector in `tests/Architecture/ToolAuthorizationTest.php` must
 * report this shape as ungated. If it ever reports it as gated, the whole
 * classification is worthless, because `filterFor()` is the one thing
 * every tool already has.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class ListFilteredOnlyTool extends AbstractTool
{
    // Traits
    // =========================================================================

    use PermissionedToolTrait;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return '_fixture_list_filtered';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Test fixture — permission check in filterFor() only.';
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            return true;
        }

        $this->_assertPermission([]);

        return true;
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        return [];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Permissions the resolved arguments imply, per the
     * `PermissionedToolTrait` contract. Empty for a fixture that only
     * has to be structurally ungated on the dispatch path.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        return [];
    }
}
