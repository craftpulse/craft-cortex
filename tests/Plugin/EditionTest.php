<?php

/**
 * =========================================================================
 * Plugin edition foundation tests — Gate 8.1.
 *
 * Locks the edition-detection seam that every Gate 8 sub-gate from 8.2
 * onwards builds on:
 *   - `Herald::editions()` returns the two-tier list in ascending order.
 *   - `Herald::EDITION_FREE` / `EDITION_PRO` constants resolve.
 *   - `Herald::getInstance()->is(EDITION_FREE)` is true on the default
 *     playground install (which is Free).
 *   - Flipping `$plugin->edition` to `'pro'` under a muted project-
 *     config makes `is(EDITION_PRO)` true and a `ProToolTrait`-using
 *     fixture's `shouldRegister()` return true.
 *   - The `herald_with_edition()` helper from `tests/Pest.php`
 *     restores state cleanly so a neighbouring test sees Free again.
 *
 * The fixture class lives in this file as a top-level class — mirrors
 * `_HeraldTokenControllerHarness` in `TokenControllerTest.php` so the
 * file is self-contained and the fixture is grep-able alongside its
 * only use site.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\ProToolTrait;

// -----------------------------------------------------------------------------
// Fixture
// -----------------------------------------------------------------------------

/**
 * Minimal `ProToolTrait`-using fixture. Carries no real behaviour —
 * the only surface this test exercises is `shouldRegister()` and
 * confirms the trait wires it to `Herald::is(EDITION_PRO, '>=')`.
 */
class _HeraldProToolFixture extends AbstractTool
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

it('Herald::editions() returns [free, pro] in ascending order', function() {
    expect(Herald::editions())->toBe([Herald::EDITION_FREE, Herald::EDITION_PRO]);
});

it('EDITION_FREE and EDITION_PRO constants carry the expected handles', function() {
    expect(Herald::EDITION_FREE)->toBe('free');
    expect(Herald::EDITION_PRO)->toBe('pro');
});

// -----------------------------------------------------------------------------
// is(...)
// -----------------------------------------------------------------------------

it('is(EDITION_FREE) is true on the default playground install', function() {
    expect(Herald::getInstance()->is(Herald::EDITION_FREE))->toBeTrue();
});

it('is(EDITION_PRO) is false on the default playground install', function() {
    expect(Herald::getInstance()->is(Herald::EDITION_PRO))->toBeFalse();
});

it('flipping edition to pro makes is(EDITION_PRO) true', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        // Default `is()` operator is `=`, so `is(EDITION_PRO)` is true
        // and `is(EDITION_FREE)` is false on a Pro install.
        expect(Herald::getInstance()->is(Herald::EDITION_PRO))->toBeTrue();
        expect(Herald::getInstance()->is(Herald::EDITION_FREE))->toBeFalse();
        // `is(EDITION_FREE, '>=')` is true because Pro sits above Free
        // in the ascending `editions()` array. The `ProToolTrait`
        // gate uses the `>=` form so a hypothetical higher tier
        // (e.g. Commerce) would still satisfy a Pro requirement.
        expect(Herald::getInstance()->is(Herald::EDITION_FREE, '>='))->toBeTrue();
        expect(Herald::getInstance()->is(Herald::EDITION_FREE, '>'))->toBeTrue();
    });
});

it('the edition helper restores Free for the next test', function() {
    // This test depends on `herald_with_edition` having restored the
    // edition handle in the previous test's `finally` block. If the
    // restore leaked, this assertion fails and surfaces the leak.
    expect(Herald::getInstance()->edition)->toBe(Herald::EDITION_FREE);
    expect(Herald::getInstance()->is(Herald::EDITION_PRO))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ProToolTrait
// -----------------------------------------------------------------------------

it('ProToolTrait::shouldRegister() returns false on Free', function() {
    expect(_HeraldProToolFixture::shouldRegister())->toBeFalse();
});

it('ProToolTrait::shouldRegister() returns true on Pro', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_HeraldProToolFixture::shouldRegister())->toBeTrue();
    });
});

it('ProToolTrait::shouldRegister() flips back to false after the helper exits', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_HeraldProToolFixture::shouldRegister())->toBeTrue();
    });
    expect(_HeraldProToolFixture::shouldRegister())->toBeFalse();
});

it('the edition helper restores state even when the callback throws', function() {
    expect(fn() => herald_with_edition(Herald::EDITION_PRO, function() {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect(Herald::getInstance()->edition)->toBe(Herald::EDITION_FREE);
    expect(_HeraldProToolFixture::shouldRegister())->toBeFalse();
});
