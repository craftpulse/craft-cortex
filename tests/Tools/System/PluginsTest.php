<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('plugins');
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

it('reports cortex itself as enabled and installed', function() {
    $result = $this->tool->execute([]);

    $cortex = null;
    foreach ($result['plugins'] as $plugin) {
        if ($plugin['handle'] === 'cortex') {
            $cortex = $plugin;
            break;
        }
    }

    expect($cortex)->not->toBeNull();
    expect($cortex['isInstalled'])->toBeTrue();
    expect($cortex['isEnabled'])->toBeTrue();
});

it('count matches the number of plugin info entries known to Craft', function() {
    $result = $this->tool->execute([]);

    expect($result['count'])->toBe(count(Craft::$app->getPlugins()->getAllPluginInfo()));
});
