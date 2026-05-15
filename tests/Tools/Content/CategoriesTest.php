<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Category;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('categories');
});

it('returns categories list with pagination metadata', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['categories', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['categories'])->toBeArray();
});

it('returns count as a real integer', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBeInt();
    expect($result['count'])->toBe((int) Category::find()->count());
});

it('returns a single category when id is passed', function() {
    $category = Category::find()->one();
    if ($category === null) {
        $this->markTestSkipped('No categories in playground.');
    }

    $result = $this->tool->execute(['id' => $category->id]);

    expect($result)->toHaveKey('category');
    expect($result['category']['id'])->toBe($category->id);
});

it('throws ToolException for unknown category id', function() {
    $this->tool->execute(['id' => 99999999]);
})->throws(ToolException::class);

it('accepts structure params without erroring (level, hasDescendants, leaves)', function() {
    $result = $this->tool->execute([
        'level' => 1,
        'hasDescendants' => false,
        'leaves' => true,
    ]);

    expect($result)->toHaveKey('categories');
});
