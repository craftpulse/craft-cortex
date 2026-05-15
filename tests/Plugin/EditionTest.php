<?php

/**
 * =========================================================================
 * Plugin edition foundation tests — Gate 8.1.
 *
 * Locks the edition-detection seam that every Gate 8 sub-gate from 8.2
 * onwards builds on:
 *   - `Plugin::editions()` returns the two-tier list in ascending order.
 *   - `Plugin::EDITION_FREE` / `EDITION_PRO` constants resolve.
 *   - `Plugin::getInstance()->is(EDITION_FREE)` is true on the default
 *     playground install (which is Free).
 *   - Flipping `$plugin->edition` to `'pro'` under a muted project-
 *     config makes `is(EDITION_PRO)` true and a `ProToolTrait`-using
 *     fixture's `shouldRegister()` return true.
 *   - The `cortex_with_edition()` helper from `tests/Pest.php`
 *     restores state cleanly so a neighbouring test sees Free again.
 *
 * The fixture class lives in this file as a top-level class — mirrors
 * `_CortexTokenControllerHarness` in `TokenControllerTest.php` so the
 * file is self-contained and the fixture is grep-able alongside its
 * only use site.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ProToolTrait;

// -----------------------------------------------------------------------------
// Fixture
// -----------------------------------------------------------------------------

/**
 * Minimal `ProToolTrait`-using fixture. Carries no real behaviour —
 * the only surface this test exercises is `shouldRegister()` and
 * confirms the trait wires it to `Plugin::is(EDITION_PRO, '>=')`.
 */
class _CortexProToolFixture extends AbstractTool
{
    use ProToolTrait;

    public static function getName(): string
    {
        return '_pro_fixture';
    }

    public static function getDescription(): string
    {
        return 'Test fixture for ProToolTrait::shouldRegister().';
    }

    public function execute(array $arguments): array
    {
        return [];
    }
}

// -----------------------------------------------------------------------------
// editions()
// -----------------------------------------------------------------------------

it('Plugin::editions() returns [free, pro] in ascending order', function() {
    expect(Plugin::editions())->toBe([Plugin::EDITION_FREE, Plugin::EDITION_PRO]);
});

it('EDITION_FREE and EDITION_PRO constants carry the expected handles', function() {
    expect(Plugin::EDITION_FREE)->toBe('free');
    expect(Plugin::EDITION_PRO)->toBe('pro');
});

// -----------------------------------------------------------------------------
// is(...)
// -----------------------------------------------------------------------------

it('is(EDITION_FREE) is true on the default playground install', function() {
    expect(Plugin::getInstance()->is(Plugin::EDITION_FREE))->toBeTrue();
});

it('is(EDITION_PRO) is false on the default playground install', function() {
    expect(Plugin::getInstance()->is(Plugin::EDITION_PRO))->toBeFalse();
});

it('flipping edition to pro makes is(EDITION_PRO) true', function() {
    cortex_with_edition(Plugin::EDITION_PRO, function() {
        // Default `is()` operator is `=`, so `is(EDITION_PRO)` is true
        // and `is(EDITION_FREE)` is false on a Pro install.
        expect(Plugin::getInstance()->is(Plugin::EDITION_PRO))->toBeTrue();
        expect(Plugin::getInstance()->is(Plugin::EDITION_FREE))->toBeFalse();
        // `is(EDITION_FREE, '>=')` is true because Pro sits above Free
        // in the ascending `editions()` array. The `ProToolTrait`
        // gate uses the `>=` form so a hypothetical higher tier
        // (e.g. Commerce) would still satisfy a Pro requirement.
        expect(Plugin::getInstance()->is(Plugin::EDITION_FREE, '>='))->toBeTrue();
        expect(Plugin::getInstance()->is(Plugin::EDITION_FREE, '>'))->toBeTrue();
    });
});

it('the edition helper restores Free for the next test', function() {
    // This test depends on `cortex_with_edition` having restored the
    // edition handle in the previous test's `finally` block. If the
    // restore leaked, this assertion fails and surfaces the leak.
    expect(Plugin::getInstance()->edition)->toBe(Plugin::EDITION_FREE);
    expect(Plugin::getInstance()->is(Plugin::EDITION_PRO))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ProToolTrait
// -----------------------------------------------------------------------------

it('ProToolTrait::shouldRegister() returns false on Free', function() {
    expect(_CortexProToolFixture::shouldRegister())->toBeFalse();
});

it('ProToolTrait::shouldRegister() returns true on Pro', function() {
    cortex_with_edition(Plugin::EDITION_PRO, function() {
        expect(_CortexProToolFixture::shouldRegister())->toBeTrue();
    });
});

it('ProToolTrait::shouldRegister() flips back to false after the helper exits', function() {
    cortex_with_edition(Plugin::EDITION_PRO, function() {
        expect(_CortexProToolFixture::shouldRegister())->toBeTrue();
    });
    expect(_CortexProToolFixture::shouldRegister())->toBeFalse();
});

it('the edition helper restores state even when the callback throws', function() {
    expect(fn() => cortex_with_edition(Plugin::EDITION_PRO, function() {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect(Plugin::getInstance()->edition)->toBe(Plugin::EDITION_FREE);
    expect(_CortexProToolFixture::shouldRegister())->toBeFalse();
});
