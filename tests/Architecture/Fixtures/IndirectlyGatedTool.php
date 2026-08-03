<?php

namespace craftpulse\herald\tests\Architecture\Fixtures;

use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * Test fixture — a tool whose authorization gate sits two calls deep,
 * inside a private per-mode handler.
 *
 * This is the shape every real multi-mode write tool has: `execute()`
 * dispatches on `mode` and each `_create()` / `_update()` / `_delete()`
 * handler gates itself. The detector in
 * `tests/Architecture/ToolAuthorizationTest.php` therefore has to walk
 * the tool's own calls breadth-first rather than scan `execute()` alone,
 * and this fixture is what proves the walk happens.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class IndirectlyGatedTool extends AbstractTool
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
        return '_fixture_indirect';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Test fixture — gates two calls deep.';
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
        return $this->_update($arguments);
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

    // Private Methods
    // =========================================================================

    /**
     * Per-mode handler carrying the gate.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _update(array $arguments): array
    {
        $this->_assertPermission($arguments);

        return [];
    }
}
