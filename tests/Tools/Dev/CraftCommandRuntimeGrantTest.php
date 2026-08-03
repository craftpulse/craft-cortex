<?php

/**
 * =========================================================================
 * Negative tests for remediation item 1.6 — a runtime grant used to
 * launder an admin-level route past `allowAdminChanges`.
 *
 * `CraftCommand::_contentPatterns()` merged active runtime overrides into
 * the content-level bucket, and the content-level bucket is admitted
 * unconditionally. A grant naming an admin-level route (`migrate/*`,
 * `project-config/*`, `make/*`, …) therefore dispatched even on an
 * install where `allowAdminChanges` is false, which is precisely the
 * boundary the flag exists to hold. `Allowlist::getEffective()` had the
 * same hole, so `get_initial_context` advertised the laundered pattern
 * as available.
 *
 * The fix classifies the *route* rather than trusting the bucket the
 * matching pattern came from: an admin-level route needs
 * `allowAdminChanges` no matter which list admitted it.
 *
 * Content-level grants keep working with the flag off — that is the
 * whole point of the temporary-grant surface, and the positive controls
 * below lock it.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\records\RuntimeOverride as RuntimeOverrideRecord;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);

    $this->tool = Herald::getInstance()->tools->getByName('craft_command');
    expect($this->tool)->not->toBeNull();
    $this->grantIds = [];
});

afterEach(function() {
    // Rows roll back with the per-test transaction, but the service's
    // per-request override memoization does not — and a grant leaking
    // into a sibling test file changes which pattern `craft_command`
    // reports as matched. `remove()` resets that cache; `pruneExpired()`
    // would not, because it early-returns before the reset when nothing
    // has actually expired.
    foreach ($this->grantIds as $grantId) {
        Herald::getInstance()->allowlist->remove($grantId);
    }

    RuntimeOverrideRecord::deleteAll(['like', 'note', '_test_/grant-%', false]);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Add a global runtime grant and record its id so `afterEach()` can
 * remove it (which is also what flushes the service's override cache).
 */
function _herald_grant(object $test, string $pattern, string $note): void
{
    $override = Herald::getInstance()->allowlist->add($pattern, null, $note, 600);
    $test->grantIds[] = (int) $override->id;
}

// -----------------------------------------------------------------------------
// The laundering attempt
// -----------------------------------------------------------------------------

it('refuses an admin-level route granted at runtime while allowAdminChanges is false', function() {
    herald_with_admin_changes(false, function() {
        _herald_grant($this, 'migrate/up', '_test_/grant-admin-route');

        $this->tool->execute([
            'mode' => 'run',
            'command' => 'migrate/up',
        ]);
    });
})->throws(ToolException::class, 'allowAdminChanges');

it('refuses a wildcard runtime grant that spans an admin-level route', function() {
    herald_with_admin_changes(false, function() {
        _herald_grant($this, 'project-config/*', '_test_/grant-admin-glob');

        $this->tool->execute([
            'mode' => 'run',
            'command' => 'project-config/rebuild',
        ]);
    });
})->throws(ToolException::class, 'allowAdminChanges');

it('keeps the advertised allowlist in lockstep: a laundered grant is not listed', function() {
    herald_with_admin_changes(false, function() {
        _herald_grant($this, 'migrate/up', '_test_/grant-lockstep');

        expect(Herald::getInstance()->allowlist->getEffective())->not->toContain('migrate/up');
    });
});

// -----------------------------------------------------------------------------
// Positive controls — content-level grants are unaffected
// -----------------------------------------------------------------------------

it('still admits a content-level runtime grant while allowAdminChanges is false', function() {
    herald_with_admin_changes(false, function() {
        _herald_grant($this, 'help', '_test_/grant-content-route');

        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'help',
        ]);

        expect($result)->toHaveKey('matchedPattern', 'help');
    });
});

it('still admits an admin-level runtime grant while allowAdminChanges is true', function() {
    herald_with_admin_changes(true, function() {
        _herald_grant($this, 'migrate/up', '_test_/grant-admin-allowed');

        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'migrate/up',
        ]);

        expect($result)->toHaveKey('mode', 'run');
    });
});
