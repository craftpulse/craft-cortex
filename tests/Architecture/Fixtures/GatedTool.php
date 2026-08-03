<?php

namespace craftpulse\herald\tests\Architecture\Fixtures;

use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * Test fixture — a tool that calls its authorization gate directly from
 * `execute()`.
 *
 * Anchors the positive half of the gate detector in
 * `tests/Architecture/ToolAuthorizationTest.php`. A detector that stopped
 * finding anything would pass every production tool silently, so it is
 * pinned against a known-gated shape and a known-ungated one rather than
 * trusted.
 *
 * `getName()` follows the underscore-prefix convention the other test
 * fixtures use, so it is trivially distinguishable from a production
 * tool. It is never registered.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class GatedTool extends AbstractTool
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
        return '_fixture_gated';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Test fixture — gates inside execute().';
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $this->_assertPermission($arguments);

        return [];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Permissions the resolved arguments imply, per the
     * `PermissionedToolTrait` contract. Empty for a fixture that only
     * has to be structurally gated.
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
