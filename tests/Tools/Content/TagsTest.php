<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Tag;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('tags');
});

it('returns tags list with pagination metadata', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['tags', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['tags'])->toBeArray();
});

it('returns count as a real integer', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBeInt();
    expect($result['count'])->toBe((int) Tag::find()->count());
});

it('returns a single tag when id is passed', function() {
    $tag = Tag::find()->one();
    if ($tag === null) {
        $this->markTestSkipped('No tags in playground.');
    }

    $result = $this->tool->execute(['id' => $tag->id]);

    expect($result)->toHaveKey('tag');
    expect($result['tag']['id'])->toBe($tag->id);
});

it('throws ToolException for unknown tag id', function() {
    $this->tool->execute(['id' => 99999999]);
})->throws(ToolException::class);
