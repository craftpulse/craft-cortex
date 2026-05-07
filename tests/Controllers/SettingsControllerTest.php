<?php

/**
 * =========================================================================
 * SettingsController smoke tests.
 *
 * Verifies the controller class shape — driver-level test of the
 * action methods (request parsing, redirects) requires a CP web
 * context that's expensive to set up in a console test bootstrap, so
 * those run as manual smoke during gate verification. The structural
 * assertions here catch wiring breakage at minimum.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craft\web\Controller;
use craftpulse\cortex\controllers\SettingsController;

it('extends craft\\web\\Controller', function () {
    expect(is_subclass_of(SettingsController::class, Controller::class))->toBeTrue();
});

it('declares the add-override action', function () {
    $rc = new ReflectionClass(SettingsController::class);
    expect($rc->hasMethod('actionAddOverride'))->toBeTrue();
});

it('declares the remove-override action', function () {
    $rc = new ReflectionClass(SettingsController::class);
    expect($rc->hasMethod('actionRemoveOverride'))->toBeTrue();
});

it('ships a settings template file', function () {
    // Full-render smoke happens manually in the CP — this test runs in
    // a console context where csrfInput() / actionInput() macros
    // dispatch to a console Response that doesn't implement
    // setNoCacheHeaders(). Verify the file exists and is at the right
    // path so package-shape regressions still get caught.
    $path = __DIR__ . '/../../src/templates/settings.twig';
    expect(file_exists($path))->toBeTrue();
    $contents = file_get_contents($path);
    expect($contents)->toContain('Runtime overrides');
    expect($contents)->toContain('Allowed commands');
});
