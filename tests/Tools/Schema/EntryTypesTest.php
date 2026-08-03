<?php

/**
 * @author CraftPulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('entry_types');
});

it('lists entry types with field-layout summary', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('entryTypes');
    expect($result['entryTypes'])->toBeArray();

    foreach ($result['entryTypes'] as $type) {
        expect($type)->toHaveKeys([
            'id', 'uid', 'name', 'handle',
            'hasTitleField', 'titleTranslationMethod', 'slugTranslationMethod',
            'fieldLayout',
        ]);

        if ($type['fieldLayout'] !== null) {
            expect($type['fieldLayout'])->toHaveKeys(['id', 'tabCount', 'customFieldCount']);
        }
    }
});

it('returns the full field layout (with tabs) when handle is passed', function() {
    $all = Craft::$app->getEntries()->getAllEntryTypes();
    if ($all === []) {
        $this->markTestSkipped('No entry types in playground.');
    }

    $handle = $all[0]->handle;
    $result = $this->tool->execute(['handle' => $handle]);

    expect($result)->toHaveKey('entryType');
    expect($result['entryType']['handle'])->toBe($handle);

    if ($result['entryType']['fieldLayout'] !== null) {
        expect($result['entryType']['fieldLayout'])->toHaveKey('tabs');
        expect($result['entryType']['fieldLayout']['tabs'])->toBeArray();
    }
});

it('returns count when count: true is passed', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getEntries()->getAllEntryTypes()));
});

it('throws ToolException for an unknown entry type handle', function() {
    $this->tool->execute(['handle' => '__herald_no_such_entry_type__']);
})->throws(ToolException::class);
