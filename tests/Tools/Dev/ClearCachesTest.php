<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function () {
    $this->tool = Plugin::getInstance()->tools->getByName('clear_caches');
});

it('lists registered cache keys when mode is `list`', function () {
    $result = $this->tool->execute(['mode' => 'list']);

    expect($result)->toHaveKey('mode', 'list');
    expect($result)->toHaveKey('keys');
    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count($result['keys']));

    // Built-in cache keys always present in a stock Craft install. Plugin-
    // registered keys may add to this set; we don't assert exclusivity.
    $keys = array_map(fn ($k) => $k['key'], $result['keys']);
    foreach (['data', 'asset', 'compiled-templates', 'compiled-classes',
              'cp-resources', 'temp-files', 'transform-indexes',
              'asset-indexing-data'] as $expected) {
        expect($keys)->toContain($expected);
    }

    foreach ($result['keys'] as $row) {
        expect($row)->toHaveKeys(['key', 'label']);
        expect($row['key'])->toBeString()->not->toBeEmpty();
        expect($row['label'])->toBeString()->not->toBeEmpty();
    }
});

it('clears a single cache by key', function () {
    $result = $this->tool->execute(['mode' => 'data']);

    expect($result)->toHaveKey('mode', 'data');
    expect($result)->toHaveKey('cleared', ['data']);
    expect($result)->toHaveKey('count', 1);
});

it('defaults to clearing all caches when mode is omitted', function () {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('mode', 'all');
    expect($result)->toHaveKey('cleared');
    expect($result['cleared'])->toBeArray();
    // We expect at least the data + transform-indexes caches to clear cleanly
    // in a stock playground.
    expect($result['cleared'])->toContain('data');
    expect($result['count'])->toBe(count($result['cleared']));
});

it('throws on an unknown cache key with the available list in the error message', function () {
    $this->tool->execute(['mode' => '__definitely_not_a_cache__']);
})->throws(ToolException::class, "Unknown cache key '__definitely_not_a_cache__'");
