<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\herald\Herald;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('plugins');
});

it('returns plugins list with handle / name / version / enabled', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['plugins', 'count', 'enabledCount']);
    expect($result['plugins'])->toBeArray();

    foreach ($result['plugins'] as $plugin) {
        expect($plugin)->toHaveKeys([
            'handle', 'name', 'version', 'isInstalled', 'isEnabled',
        ]);
    }
});

it('reports herald itself as enabled and installed', function() {
    $result = $this->tool->execute([]);

    $herald = null;
    foreach ($result['plugins'] as $plugin) {
        if ($plugin['handle'] === 'herald') {
            $herald = $plugin;
            break;
        }
    }

    expect($herald)->not->toBeNull();
    expect($herald['isInstalled'])->toBeTrue();
    expect($herald['isEnabled'])->toBeTrue();
});

it('count matches the number of plugin info entries known to Craft', function() {
    $result = $this->tool->execute([]);

    expect($result['count'])->toBe(count(Craft::$app->getPlugins()->getAllPluginInfo()));
});
