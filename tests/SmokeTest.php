<?php

/**
 * =========================================================================
 * Smoke test — proves the bootstrap actually booted Craft and registered
 * the cortex plugin. If any test in the suite fails because Cortex::
 * getInstance() returns null, this file's failure tells us why faster
 * than 50 individual tool tests would.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\services\Tools;

it('boots Craft', function() {
    expect(Craft::$app)->not->toBeNull();
    expect(class_exists(Craft::class))->toBeTrue();
});

it('registers the cortex plugin', function() {
    $plugin = Cortex::getInstance();
    expect($plugin)->not->toBeNull();
    expect($plugin->handle)->toBe('cortex');
});

it('exposes the Tools service via the plugin component', function() {
    $tools = Cortex::getInstance()->tools;
    expect($tools)->toBeInstanceOf(Tools::class);
});
