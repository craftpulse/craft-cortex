<?php

/**
 * =========================================================================
 * Smoke test — proves the bootstrap actually booted Craft and registered
 * the cortex plugin. If any test in the suite fails because Plugin::
 * getInstance() returns null, this file's failure tells us why faster
 * than 50 individual tool tests would.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\services\Tools;

it('boots Craft', function () {
    expect(Craft::$app)->not->toBeNull();
    expect(class_exists(Craft::class))->toBeTrue();
});

it('registers the cortex plugin', function () {
    $plugin = Plugin::getInstance();
    expect($plugin)->not->toBeNull();
    expect($plugin->handle)->toBe('cortex');
});

it('exposes the Tools service via the plugin component', function () {
    $tools = Plugin::getInstance()->tools;
    expect($tools)->toBeInstanceOf(Tools::class);
});
