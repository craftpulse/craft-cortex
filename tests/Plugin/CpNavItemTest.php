<?php

/**
 * =========================================================================
 * `Herald::getCpNavItem()` behavioural tests.
 *
 * The subnav is presentation-only — every controller action behind a nav
 * item re-checks its own `requireAdmin` / `requirePermission` /
 * `_requirePro()` posture, so these tests verify the nav HIDES correctly
 * without ever widening access. The boundary is enforced by the
 * controller; the nav must merely match it:
 *
 *   - Anonymous                          → null (no nav at all).
 *   - Admin / Free                       → Settings + Temporary grants only.
 *   - Admin / Pro                        → all five items.
 *   - Non-admin with `viewActivity` / Pro → Activity only.
 *
 * Edition flips go through `herald_with_edition()` so the project-config
 * write is muted and restored. Identity is set with `setIdentity()` and
 * cleared in `afterEach`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craftpulse\herald\Herald;

beforeEach(function() {
    $this->admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio');
    if (!$this->admin instanceof User) {
        $this->markTestSkipped('No admin user `michtio` in the playground.');
    }
});

afterEach(function() {
    Craft::$app->getUser()->setIdentity(null);
});

it('returns null for an anonymous request', function() {
    Craft::$app->getUser()->setIdentity(null);

    expect(Herald::getInstance()->getCpNavItem())->toBeNull();
});

it('shows only Settings + Temporary grants to an admin on Free', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    herald_with_edition(Herald::EDITION_FREE, function() {
        $navItem = Herald::getInstance()->getCpNavItem();

        expect($navItem)->toBeArray();
        expect(array_keys($navItem['subnav']))->toBe(['settings', 'grants']);
    });
});

it('shows all items to an admin on Pro', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    herald_with_edition(Herald::EDITION_PRO, function() {
        $navItem = Herald::getInstance()->getCpNavItem();

        expect($navItem)->toBeArray();
        expect(array_keys($navItem['subnav']))
            ->toBe(['settings', 'grants', 'tokens', 'clients', 'activity', 'connection']);
    });
});

it('shows only Activity to a non-admin with viewActivity on Pro', function() {
    // A non-admin user granted only `herald:viewActivity`. The playground
    // may not seed one; skip rather than fail the suite if absent.
    $nonAdmin = User::find()
        ->admin(false)
        ->status(null)
        ->collect()
        ->first(fn(User $u): bool => $u->can(Herald::PERMISSION_VIEW_ACTIVITY));

    if (!$nonAdmin instanceof User) {
        $this->markTestSkipped('No non-admin user with herald:viewActivity in the playground.');
    }

    Craft::$app->getUser()->setIdentity($nonAdmin);

    herald_with_edition(Herald::EDITION_PRO, function() {
        $navItem = Herald::getInstance()->getCpNavItem();

        expect($navItem)->toBeArray();
        expect(array_keys($navItem['subnav']))->toBe(['activity']);
    });
});
