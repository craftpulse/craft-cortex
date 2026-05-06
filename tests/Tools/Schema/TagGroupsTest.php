<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use Craft;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function () {
    $this->tool = Plugin::getInstance()->tools->getByName('tag_groups');
});

it('lists tag groups (possibly empty)', function () {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('tagGroups');
    expect($result['tagGroups'])->toBeArray();

    foreach ($result['tagGroups'] as $group) {
        expect($group)->toHaveKeys(['id', 'uid', 'name', 'handle', 'fieldLayout']);
    }
});

it('returns the count when count: true is passed', function () {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getTags()->getAllTagGroups()));
});

it('throws ToolException for an unknown tag-group handle', function () {
    $this->tool->execute(['handle' => '__cortex_no_such_tag_group__']);
})->throws(ToolException::class);
