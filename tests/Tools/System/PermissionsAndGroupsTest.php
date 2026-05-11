<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Plugin;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('permissions_and_groups');
});

it('returns the permissions tree, flat name list, and groups', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys([
        'permissions', 'permissionCount', 'permissionNames', 'groups', 'groupCount',
    ]);
    expect($result['permissions'])->toBeArray()->not->toBeEmpty();
    expect($result['permissionNames'])->toBeArray();
    expect($result['groups'])->toBeArray();
    expect($result['groupCount'])->toBe(count(Craft::$app->getUserGroups()->getAllGroups()));
});

it('groups carry no user data — only handle / name / description / permissions', function() {
    $result = $this->tool->execute([]);

    foreach ($result['groups'] as $group) {
        expect($group)->toHaveKeys(['id', 'uid', 'name', 'handle', 'description', 'permissions']);

        // Hard rule: no PII.
        expect($group)->not->toHaveKey('users');
        expect($group)->not->toHaveKey('email');
        expect($group)->not->toHaveKey('userIds');
        expect($group['permissions'])->toBeArray();
    }
});
