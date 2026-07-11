<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Entry;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('entries');
});

it('returns an entries list with pagination metadata', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['entries', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['entries'])->toBeArray();
    expect($result['limit'])->toBe(100);
    expect($result['offset'])->toBe(0);

    foreach ($result['entries'] as $entry) {
        expect($entry)->toHaveKeys([
            'id', 'uid', 'title', 'siteId', 'status', 'enabled', 'fields',
        ]);
    }
});

it('caps limit at MAX_LIMIT and floors at 1', function() {
    $result = $this->tool->execute(['limit' => 99999]);
    expect($result['limit'])->toBe(1000);

    $result = $this->tool->execute(['limit' => 0]);
    expect($result['limit'])->toBe(1);
});

it('returns count as a real integer (not a string from the DB driver)', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBeInt();
    expect($result['count'])->toBe((int) Entry::find()->count());
});

it('filters by section handle', function() {
    $entry = Entry::find()->one();
    if ($entry === null) {
        $this->markTestSkipped('No entries in playground.');
    }

    $sectionHandle = $entry->getSection()?->handle;
    if ($sectionHandle === null) {
        $this->markTestSkipped('Entry has no section (nested entry).');
    }

    $result = $this->tool->execute(['section' => $sectionHandle, 'limit' => 5]);
    expect($result['entries'])->toBeArray();

    foreach ($result['entries'] as $serialized) {
        $reloaded = Entry::find()->id($serialized['id'])->one();
        expect($reloaded?->getSection()?->handle)->toBe($sectionHandle);
    }
});

it('returns a single entry when id is passed', function() {
    $entry = Entry::find()->one();
    if ($entry === null) {
        $this->markTestSkipped('No entries in playground.');
    }

    $result = $this->tool->execute(['id' => $entry->id]);

    expect($result)->toHaveKey('entry');
    expect($result['entry']['id'])->toBe($entry->id);
});

it('throws ToolException when id has no match', function() {
    $this->tool->execute(['id' => 99999999]);
})->throws(ToolException::class);

it('stubs relational fields when `with` is not supplied (no N+1)', function() {
    $entries = Entry::find()->limit(5)->all();
    if ($entries === []) {
        $this->markTestSkipped('No entries in playground.');
    }

    [$result] = herald_count_queries(fn() => $this->tool->execute(['limit' => 5]));

    foreach ($result['entries'] as $entry) {
        foreach ($entry['fields'] as $value) {
            // Relational fields without eager loading land as a stub —
            // never as a fully materialised array (which would mean N+1).
            if (is_array($value) && isset($value['type']) && $value['type'] === 'relation') {
                expect($value)->toHaveKey('loaded', false);
            }
        }
    }
});

it('exposes the with parameter so eager-loading is configurable', function() {
    $result = $this->tool->execute(['limit' => 1, 'with' => []]);
    expect($result)->toHaveKey('entries');
    // Empty `with` array is a no-op — should not throw.
});
