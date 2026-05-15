<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('category_groups');
});

it('lists category groups (possibly empty)', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('categoryGroups');
    expect($result['categoryGroups'])->toBeArray();

    foreach ($result['categoryGroups'] as $group) {
        expect($group)->toHaveKeys(['id', 'uid', 'name', 'handle', 'maxLevels', 'siteSettings']);
    }
});

it('returns the count when count: true is passed', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getCategories()->getAllGroups()));
});

it('returns a single group when handle is passed', function() {
    $all = Craft::$app->getCategories()->getAllGroups();
    if ($all === []) {
        $this->markTestSkipped('No category groups in playground.');
    }

    $handle = $all[0]->handle;
    $result = $this->tool->execute(['handle' => $handle]);

    expect($result)->toHaveKey('categoryGroup');
    expect($result['categoryGroup']['handle'])->toBe($handle);
});

it('throws ToolException for an unknown category-group handle', function() {
    $this->tool->execute(['handle' => '__cortex_no_such_group__']);
})->throws(ToolException::class);
