<?php

/**
 * =========================================================================
 * Negative tests for remediation item 1.3 — `craft_command` was
 * completely ungated.
 *
 * Before the fix the tool had no `filterFor()`, no permission assertion
 * inside `execute()`, and sat on the weakest `system:read` capability
 * scope. Any authenticated HTTP caller holding a read-shaped token
 * therefore reached Craft's console runner and every route the
 * allowlist admitted, `users/create` included.
 *
 * Three independent gates are asserted here, because each one fails
 * closed on its own:
 *
 *   1. `filterFor()` hides the tool from `tools/list` / `tools/call`
 *      for a caller without `herald:run-commands`.
 *   2. `execute()` re-checks the permission itself, so a caller that
 *      reaches the tool by any other route is still refused.
 *   3. The capability scope is no longer `system:read`, so a read-only
 *      OAuth grant cannot carry it.
 *
 * stdio stays ungated on purpose (ruled 2026-08-02): the security
 * boundary is the HTTP transport, and the stdio caller already holds a
 * shell plus the `craft` console. A null Craft identity is the stdio
 * path and skips the check, exactly as `PermissionedToolTrait` does.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\elements\User as UserElement;
use craftpulse\herald\Herald;
use craftpulse\herald\services\Scopes;
use craftpulse\herald\tools\dev\CraftCommand;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    $this->admin = $admin;
    Craft::$app->getUser()->setIdentity($admin);

    $this->fixturePrefix = '__herald_cmdauth_' . bin2hex(random_bytes(4)) . '_';
    $this->tool = Herald::getInstance()->tools->getByName('craft_command');
    expect($this->tool)->not->toBeNull();
});

afterEach(function() {
    $users = UserElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    Craft::$app->getUser()->setIdentity($this->admin);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Create and persist a non-admin user carrying the supplied permission
 * strings. Returns null when the save fails so callers assert loudly.
 *
 * @param string[] $permissions
 */
function _herald_cmdauth_user(string $prefix, string $label, array $permissions = []): ?UserElement
{
    $user = new UserElement();
    $user->username = $prefix . $label;
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        return null;
    }

    if ($permissions !== []) {
        Craft::$app->getUserPermissions()->saveUserPermissions((int) $user->id, $permissions);
    }

    return $user;
}

// -----------------------------------------------------------------------------
// Gate 1 — tools/list visibility
// -----------------------------------------------------------------------------

it('hides craft_command from a caller without herald:run-commands', function() {
    $user = _herald_cmdauth_user($this->fixturePrefix, 'nogrant');
    expect($user)->not->toBeNull();

    expect($this->tool->filterFor($user))->toBeFalse();
});

it('shows craft_command to a caller holding herald:run-commands', function() {
    $user = _herald_cmdauth_user($this->fixturePrefix, 'granted', [CraftCommand::PERMISSION_RUN_COMMANDS]);
    expect($user)->not->toBeNull();

    // Re-resolve so the permission cache on the in-memory element does
    // not mask the freshly-saved grant.
    $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
    expect($reloaded)->not->toBeNull();

    expect($this->tool->filterFor($reloaded))->toBeTrue();
});

it('shows craft_command to an admin', function() {
    expect($this->tool->filterFor($this->admin))->toBeTrue();
});

it('shows craft_command on stdio (null user)', function() {
    expect($this->tool->filterFor(null))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Gate 2 — execute() re-check, independent of tools/list filtering
// -----------------------------------------------------------------------------

it('refuses to dispatch a console route for a caller without herald:run-commands', function() {
    $user = _herald_cmdauth_user($this->fixturePrefix, 'runner');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->tool->execute([
        'mode' => 'run',
        'command' => 'clear-deprecations',
    ]);
})->throws(ToolException::class);

it('refuses to list the allowlist for a caller without herald:run-commands', function() {
    $user = _herald_cmdauth_user($this->fixturePrefix, 'lister');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->tool->execute(['mode' => 'list']);
})->throws(ToolException::class);

it('dispatches for a caller holding herald:run-commands', function() {
    $user = _herald_cmdauth_user($this->fixturePrefix, 'allowed', [CraftCommand::PERMISSION_RUN_COMMANDS]);
    expect($user)->not->toBeNull();
    $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
    expect($reloaded)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($reloaded);

    $result = $this->tool->execute(['mode' => 'list']);

    expect($result)->toHaveKey('mode', 'list');
});

it('dispatches on stdio with no Craft identity bound', function() {
    Craft::$app->getUser()->setIdentity(null);

    $result = $this->tool->execute(['mode' => 'list']);

    expect($result)->toHaveKey('mode', 'list');
});

// -----------------------------------------------------------------------------
// Gate 3 — capability scope
// -----------------------------------------------------------------------------

it('no longer maps craft_command to the read-only system:read scope', function() {
    expect(Herald::getInstance()->scopes->scopeForTool('craft_command'))
        ->not->toBe(Scopes::SYSTEM_READ);
});

it('denies craft_command to a token carrying only read scopes', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->grantsTool('craft_command', [Scopes::SYSTEM_READ]))->toBeFalse();
    expect($scopes->grantsTool('craft_command', [Scopes::LEGACY_READ]))->toBeFalse();
});
