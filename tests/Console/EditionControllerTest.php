<?php

/**
 * =========================================================================
 * EditionController tests — verify `cortex/edition/show` prints the
 * active edition handle, the full editions list, and the `is(pro)`
 * flag on a default Free install, and that flipping to Pro flips the
 * output.
 *
 * Same harness shape as `TokenControllerTest.php` — capturing
 * subclass routes `stdout` / `stderr` into a public buffer so tests
 * can assert on what the action printed (Yii's `Controller::stdout()`
 * writes directly to the file descriptors, bypassing PHP's output
 * buffer).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\console\controllers\EditionController;
use craftpulse\cortex\Cortex;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * Capturing EditionController — `stdout` / `stderr` route into a
 * public buffer instead of the file descriptors so tests can read
 * what the action printed.
 */
class _CortexEditionControllerHarness extends EditionController
{
    public string $captured = '';

    public function stdout($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }

    public function stderr($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }
}

function _cortex_edition_harness(): _CortexEditionControllerHarness
{
    return new _CortexEditionControllerHarness('edition', Craft::$app);
}

// -----------------------------------------------------------------------------
// show
// -----------------------------------------------------------------------------

it('show exits OK and prints the current edition on a default Free install', function() {
    $controller = _cortex_edition_harness();
    $exit = $controller->actionShow();

    expect($exit)->toBe(0);
    expect($controller->captured)->toContain('Cortex edition');
    expect($controller->captured)->toContain('edition:');
    expect($controller->captured)->toContain('free');
    expect($controller->captured)->toContain('is(pro):');
    expect($controller->captured)->toContain('false');
});

it('show prints the full editions list', function() {
    $controller = _cortex_edition_harness();
    $exit = $controller->actionShow();

    expect($exit)->toBe(0);
    // The list shape is `[free, pro]` — assert both handles appear
    // inside the line so a future Commerce edition appended to
    // `Cortex::editions()` doesn't silently break the contract.
    expect($controller->captured)->toContain(Cortex::EDITION_FREE);
    expect($controller->captured)->toContain(Cortex::EDITION_PRO);
});

it('show prints pro / is(pro): true when the edition is flipped', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $controller = _cortex_edition_harness();
        $exit = $controller->actionShow();

        expect($exit)->toBe(0);
        expect($controller->captured)->toContain('edition:');
        expect($controller->captured)->toContain('pro');
        expect($controller->captured)->toContain('is(pro):');
        expect($controller->captured)->toContain('true');
    });
});
