<?php

/**
 * =========================================================================
 * Negative tests for the two convenience wrappers that bypassed the
 * gate Phase 1 installed on `craft_command`.
 *
 * `craft_command` was closed with `herald:run-commands` plus the
 * `system:write` scope. `clear_caches` and `resave` reach the same
 * console-route families (`clear-caches/*`, `resave/*`) through a
 * structured argument shape, and both shipped with no permission check
 * anywhere and the read-shaped `system:read` scope. A token carrying
 * only `system:read` could therefore resave every element in the install
 * and flush every cache, which is the same hole Phase 1 closed, reached
 * through a different door. `resave` additionally advertised
 * `#[IsDestructive]` while sitting on a read scope.
 *
 * Three independent gates are asserted per tool, each failing closed on
 * its own, mirroring `CraftCommandAuthorizationTest`:
 *
 *   1. `filterFor()` hides the tool from `tools/list` / `tools/call`.
 *   2. `execute()` (and, for `resave`, `stream()`) re-checks the
 *      permission, so a caller reaching the tool by any other route is
 *      still refused.
 *   3. The capability scope is no longer `system:read`, so a read-only
 *      OAuth grant cannot carry it.
 *
 * # The two permissions are deliberately different
 *
 * `resave` reuses `CraftCommand::PERMISSION_RUN_COMMANDS`: it is the
 * `resave/*` console route wearing a nicer coat, `resave/*` ships on the
 * content-level allowlist, and its own envelope reports the dispatched
 * route. Anyone holding `herald:run-commands` can already do this work
 * through `craft_command`, so reusing the handle removes the asymmetry
 * outright instead of renaming it.
 *
 * `clear_caches` gets its own `ClearCaches::PERMISSION_CLEAR_CACHES`.
 * `clear-caches/*` is not on the default allowlist, so
 * `herald:run-commands` is not a superset of this capability, merely a
 * broader unrelated authority. Forcing an operator to hand out
 * `migrate/up` and `project-config/apply` to let an agent flush the
 * CP-resources cache after a deploy invites over-granting. Craft's own CP
 * gates the same work behind its own narrow `utility:clear-caches`.
 *
 * Permissions are flat here, as they are in Craft: `herald:run-commands`
 * does NOT imply `herald:clear-caches` and vice versa. Both are asserted
 * below so the lattice cannot drift into an implicit implication.
 *
 * stdio stays ungated on purpose (ruled 2026-08-02): the security
 * boundary is the HTTP transport, and a stdio caller already holds a
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
use craftpulse\herald\tools\dev\ClearCaches;
use craftpulse\herald\tools\dev\CraftCommand;
use craftpulse\herald\tools\support\CancellationToken;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    $this->admin = $admin;
    Craft::$app->getUser()->setIdentity($admin);

    $this->fixturePrefix = '__herald_devauth_' . bin2hex(random_bytes(4)) . '_';
    $this->clearCaches = Herald::getInstance()->tools->getByName('clear_caches');
    $this->resave = Herald::getInstance()->tools->getByName('resave');
    expect($this->clearCaches)->not->toBeNull();
    expect($this->resave)->not->toBeNull();
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

    // The tool registry hands out one instance per process, so a
    // context injected by a test outlives it. Reset to a fresh,
    // uncancelled stdio context.
    $this->resave->setInvocationContext(new InvocationContext());

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
function _herald_devauth_user(string $prefix, string $label, array $permissions = []): ?UserElement
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

    // Re-resolve so the permission cache on the in-memory element does
    // not mask the freshly-saved grant.
    return Craft::$app->getUsers()->getUserById((int) $user->id);
}

// -----------------------------------------------------------------------------
// `clear_caches` — gate 1, tools/list visibility
// -----------------------------------------------------------------------------

it('hides clear_caches from a caller without herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_nogrant');
    expect($user)->not->toBeNull();

    expect($this->clearCaches->filterFor($user))->toBeFalse();
});

it('shows clear_caches to a caller holding herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_granted', [ClearCaches::PERMISSION_CLEAR_CACHES]);
    expect($user)->not->toBeNull();

    expect($this->clearCaches->filterFor($user))->toBeTrue();
});

it('shows clear_caches to an admin and on stdio', function() {
    expect($this->clearCaches->filterFor($this->admin))->toBeTrue();
    expect($this->clearCaches->filterFor(null))->toBeTrue();
});

// -----------------------------------------------------------------------------
// `clear_caches` — gate 2, execute() re-check
// -----------------------------------------------------------------------------

it('refuses to clear every cache for a caller without herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_all');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->clearCaches->execute(['mode' => 'all']);
})->throws(ToolException::class, 'herald:clear-caches');

it('refuses to clear a single cache for a caller without herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_one');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->clearCaches->execute(['mode' => 'data']);
})->throws(ToolException::class, 'herald:clear-caches');

it('refuses to list cache keys for a caller without herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_list');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->clearCaches->execute(['mode' => 'list']);
})->throws(ToolException::class, 'herald:clear-caches');

it('does not accept herald:run-commands as a substitute for herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_cmd', [CraftCommand::PERMISSION_RUN_COMMANDS]);
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->clearCaches->execute(['mode' => 'list']);
})->throws(ToolException::class, 'herald:clear-caches');

it('clears caches for a caller holding herald:clear-caches', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'cc_ok', [ClearCaches::PERMISSION_CLEAR_CACHES]);
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    expect($this->clearCaches->execute(['mode' => 'list']))->toHaveKey('mode', 'list');
});

it('clears caches on stdio with no Craft identity bound', function() {
    Craft::$app->getUser()->setIdentity(null);

    expect($this->clearCaches->execute(['mode' => 'list']))->toHaveKey('mode', 'list');
});

// -----------------------------------------------------------------------------
// `clear_caches` — gate 3, capability scope
// -----------------------------------------------------------------------------

it('no longer maps clear_caches to the read-only system:read scope', function() {
    expect(Herald::getInstance()->scopes->scopeForTool('clear_caches'))
        ->toBe(Scopes::SYSTEM_WRITE)
        ->not->toBe(Scopes::SYSTEM_READ);
});

it('denies clear_caches to a token carrying only read scopes', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->grantsTool('clear_caches', [Scopes::SYSTEM_READ]))->toBeFalse();
    expect($scopes->grantsTool('clear_caches', [Scopes::LEGACY_READ]))->toBeFalse();
    expect($scopes->grantsTool('clear_caches', [Scopes::SYSTEM_WRITE]))->toBeTrue();
});

// -----------------------------------------------------------------------------
// `resave` — gate 1, tools/list visibility
// -----------------------------------------------------------------------------

it('hides resave from a caller without herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_nogrant');
    expect($user)->not->toBeNull();

    expect($this->resave->filterFor($user))->toBeFalse();
});

it('shows resave to a caller holding herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_granted', [CraftCommand::PERMISSION_RUN_COMMANDS]);
    expect($user)->not->toBeNull();

    expect($this->resave->filterFor($user))->toBeTrue();
});

it('shows resave to an admin and on stdio', function() {
    expect($this->resave->filterFor($this->admin))->toBeTrue();
    expect($this->resave->filterFor(null))->toBeTrue();
});

// -----------------------------------------------------------------------------
// `resave` — gate 2, execute() and stream() re-check
// -----------------------------------------------------------------------------

it('refuses to resave for a caller without herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_exec');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->resave->execute(['type' => 'entries', 'limit' => 1]);
})->throws(ToolException::class, 'herald:run-commands');

it('refuses to resave on the streaming path for a caller without herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_stream');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    iterator_to_array($this->resave->stream(['type' => 'entries', 'limit' => 1], new InvocationContext()));
})->throws(ToolException::class, 'herald:run-commands');

it('refuses before validating arguments, so an unauthorized caller learns nothing about the option surface', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_probe');
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    // A missing `type` would otherwise enumerate every supported
    // element kind in the error message.
    $this->resave->execute([]);
})->throws(ToolException::class, 'herald:run-commands');

it('does not accept herald:clear-caches as a substitute for herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_cc', [ClearCaches::PERMISSION_CLEAR_CACHES]);
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $this->resave->execute(['type' => 'entries', 'limit' => 1]);
})->throws(ToolException::class, 'herald:run-commands');

it('resaves for a caller holding herald:run-commands', function() {
    $user = _herald_devauth_user($this->fixturePrefix, 'rs_ok', [CraftCommand::PERMISSION_RUN_COMMANDS]);
    expect($user)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($user);

    $result = $this->resave->execute([
        'type' => 'entries',
        'status' => 'no_such_status_value_xyz',
    ]);

    expect($result)->toHaveKey('total', 0);
});

it('resaves on stdio with no Craft identity bound', function() {
    Craft::$app->getUser()->setIdentity(null);

    $result = $this->resave->execute([
        'type' => 'entries',
        'status' => 'no_such_status_value_xyz',
    ]);

    expect($result)->toHaveKey('total', 0);
});

// -----------------------------------------------------------------------------
// `resave` — gate 3, capability scope
// -----------------------------------------------------------------------------

it('no longer maps resave to the read-only system:read scope', function() {
    expect(Herald::getInstance()->scopes->scopeForTool('resave'))
        ->toBe(Scopes::SYSTEM_WRITE)
        ->not->toBe(Scopes::SYSTEM_READ);
});

it('denies resave to a token carrying only read scopes', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->grantsTool('resave', [Scopes::SYSTEM_READ]))->toBeFalse();
    expect($scopes->grantsTool('resave', [Scopes::LEGACY_READ]))->toBeFalse();
    expect($scopes->grantsTool('resave', [Scopes::SYSTEM_WRITE]))->toBeTrue();
});

// -----------------------------------------------------------------------------
// `resave` — the dispatched context survives the non-streaming path
// -----------------------------------------------------------------------------

it('reuses the dispatched invocation context in execute() rather than fabricating a stdio one', function() {
    if (Craft::$app->getEntries()->getSectionByHandle('minorHeroes') === null) {
        $this->markTestSkipped('minorHeroes seed not applied; run `composer test:fixtures`.');
    }

    // `BulkEntries::execute()` had the identical shape and fabricating a
    // fresh `InvocationContext` there made an elevation gate bypassable
    // on the JSON path, because the default transport is stdio and stdio
    // is implicitly elevated. `resave` carries no elevation gate, so the
    // observable loss is the real transport and the cancellation token:
    // a context flipped before dispatch has to reach the terminal
    // envelope.
    $token = new CancellationToken();
    $token->cancel('test');
    $this->resave->setInvocationContext(new InvocationContext(cancellationToken: $token));

    $result = $this->resave->execute([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'limit' => 1,
    ]);

    expect($result['cancelled'])->toBeTrue();
    expect($result['success'])->toBeFalse();
});
