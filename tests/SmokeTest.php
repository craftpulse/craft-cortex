<?php

/**
 * =========================================================================
 * Smoke test — proves the bootstrap actually booted Craft and registered
 * the herald plugin. If any test in the suite fails because Herald::
 * getInstance() returns null, this file's failure tells us why faster
 * than 50 individual tool tests would.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\services\Tools;

it('boots Craft', function() {
    expect(Craft::$app)->not->toBeNull();
    expect(class_exists(Craft::class))->toBeTrue();
});

it('registers the herald plugin', function() {
    $plugin = Herald::getInstance();
    expect($plugin)->not->toBeNull();
    expect($plugin->handle)->toBe('herald');
});

it('exposes the Tools service via the plugin component', function() {
    $tools = Herald::getInstance()->tools;
    expect($tools)->toBeInstanceOf(Tools::class);
});
