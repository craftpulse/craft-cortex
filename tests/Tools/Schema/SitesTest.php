<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use Craft;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function () {
    $this->tool = Plugin::getInstance()->tools->getByName('sites');
});

it('lists sites with their groups + the primary site handle', function () {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['sites', 'siteGroups', 'primarySiteHandle']);
    expect($result['sites'])->toBeArray()->not->toBeEmpty();

    foreach ($result['sites'] as $site) {
        expect($site)->toHaveKeys([
            'id', 'uid', 'name', 'handle', 'language',
            'primary', 'enabled', 'hasUrls', 'baseUrl',
        ]);
    }

    foreach ($result['siteGroups'] as $group) {
        expect($group)->toHaveKeys(['id', 'uid', 'name', 'siteHandles']);
        expect($group['siteHandles'])->toBeArray();
    }

    expect($result['primarySiteHandle'])->toBe(Craft::$app->getSites()->getPrimarySite()->handle);
});

it('returns count when count: true is passed', function () {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getSites()->getAllSites()));
});

it('returns a single site when handle is passed', function () {
    $primary = Craft::$app->getSites()->getPrimarySite();
    $result = $this->tool->execute(['handle' => $primary->handle]);

    expect($result)->toHaveKey('site');
    expect($result['site']['handle'])->toBe($primary->handle);
    expect($result['site']['primary'])->toBeTrue();
});

it('throws ToolException for an unknown site handle', function () {
    $this->tool->execute(['handle' => '__cortex_no_such_site__']);
})->throws(ToolException::class);
