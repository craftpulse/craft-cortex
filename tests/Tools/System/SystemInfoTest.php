<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('system_info');
});

it('returns a craft / php / db / sites / license snapshot', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['craft', 'php', 'db', 'sites', 'license']);

    expect($result['craft'])->toHaveKeys([
        'version', 'edition', 'editionId', 'schemaVersion',
        'devMode', 'environment', 'isInstalled', 'systemName',
    ]);
    expect($result['craft']['version'])->toBe(Craft::$app->getVersion());
    expect($result['craft']['edition'])->toBeIn(['Solo', 'Team', 'Pro', 'Enterprise']);

    expect($result['php'])->toHaveKeys(['version', 'sapi', 'memoryLimit', 'timezone']);
    expect($result['php']['version'])->toBe(PHP_VERSION);

    expect($result['db'])->toHaveKeys(['driver', 'serverVersion', 'isMysql', 'isPgsql']);
    expect($result['db']['driver'])->toBeString();

    expect($result['sites']['count'])->toBeInt()->toBeGreaterThanOrEqual(1);
    expect($result['sites']['primarySiteHandle'])
        ->toBe(Craft::$app->getSites()->getPrimarySite()->handle);
});
