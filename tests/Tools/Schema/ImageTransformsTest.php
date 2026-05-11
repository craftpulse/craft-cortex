<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('image_transforms');
});

it('lists image transforms (possibly empty)', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('imageTransforms');
    expect($result['imageTransforms'])->toBeArray();

    foreach ($result['imageTransforms'] as $t) {
        expect($t)->toHaveKeys(['id', 'uid', 'name', 'handle', 'mode']);
    }
});

it('returns count when count: true is passed', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getImageTransforms()->getAllTransforms()));
});

it('throws ToolException for an unknown transform handle', function() {
    $this->tool->execute(['handle' => '__cortex_no_such_transform__']);
})->throws(ToolException::class);
