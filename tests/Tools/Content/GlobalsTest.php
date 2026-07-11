<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('globals');
});

it('returns every global set with field values when no handle is passed', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['globalSets', 'count', 'siteId']);
    expect($result['globalSets'])->toBeArray();
    expect($result['siteId'])->toBe(Craft::$app->getSites()->getPrimarySite()->id);

    foreach ($result['globalSets'] as $set) {
        expect($set)->toHaveKeys(['id', 'uid', 'name', 'handle', 'siteId', 'fields']);
    }
});

it('returns a single global set when handle is passed', function() {
    $sets = Craft::$app->getGlobals()->getAllSets();
    if ($sets === []) {
        $this->markTestSkipped('No global sets in playground.');
    }

    $handle = $sets[0]->handle;
    $result = $this->tool->execute(['handle' => $handle]);

    expect($result)->toHaveKey('globalSet');
    expect($result['globalSet']['handle'])->toBe($handle);
});

it('throws ToolException for unknown global set handle', function() {
    $this->tool->execute(['handle' => '__herald_no_such_global__']);
})->throws(ToolException::class);

it('respects the site filter (handle and id forms)', function() {
    $primary = Craft::$app->getSites()->getPrimarySite();

    $byHandle = $this->tool->execute(['site' => $primary->handle]);
    $byId = $this->tool->execute(['site' => $primary->id]);

    expect($byHandle['siteId'])->toBe($primary->id);
    expect($byId['siteId'])->toBe($primary->id);
});
