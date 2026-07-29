<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craft\base\FieldInterface;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('fields');
});

it('lists fields with type, handle, instructions', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('fields');
    expect($result['fields'])->toBeArray();

    foreach ($result['fields'] as $field) {
        expect($field)->toHaveKeys([
            'id', 'uid', 'name', 'handle', 'type', 'typeDisplayName',
            'instructions', 'searchable', 'translationMethod', 'isRelational',
        ]);

        // The tool must report a real field class for every field,
        // regardless of which plugin registered it. Asserting a `craft\`
        // namespace prefix here would fail the moment any third-party
        // field type is installed in the playground (e.g.
        // percipiolondon/colourswatches, craftpulse/timeloop) — that's a
        // fact about the playground's plugin roster, not about Herald's
        // field handling.
        expect($field['type'])->toBeString();
        expect(class_exists($field['type']))->toBeTrue();
        expect(is_subclass_of($field['type'], FieldInterface::class))->toBeTrue();
    }
});

it('includes a core field type among the reported classes', function() {
    $result = $this->tool->execute([]);

    if ($result['fields'] === []) {
        $this->markTestSkipped('No fields in playground.');
    }

    $types = array_column($result['fields'], 'type');
    $coreTypes = array_filter($types, fn(string $type): bool => str_starts_with($type, 'craft\\fields\\'));

    expect($coreTypes)->not->toBeEmpty();
});

it('returns full field detail (with settings) when handle is passed', function() {
    $all = Craft::$app->getFields()->getAllFields();
    if ($all === []) {
        $this->markTestSkipped('No fields in playground.');
    }

    $handle = $all[0]->handle;
    $result = $this->tool->execute(['handle' => $handle]);

    expect($result)->toHaveKey('field');
    expect($result['field']['handle'])->toBe($handle);
    expect($result['field'])->toHaveKey('settings');
});

it('returns count when count: true is passed', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count(Craft::$app->getFields()->getAllFields()));
});

it('returns a usage map for a field via mode: usage', function() {
    $all = Craft::$app->getFields()->getAllFields();
    if ($all === []) {
        $this->markTestSkipped('No fields in playground.');
    }

    $handle = $all[0]->handle;
    $result = $this->tool->execute(['mode' => 'usage', 'handle' => $handle]);

    expect($result)->toHaveKey('usage');
    expect($result['usage'])->toHaveKeys([
        'fieldHandle', 'fieldId', 'entryTypes', 'entryTypeCount',
    ]);
    expect($result['usage']['fieldHandle'])->toBe($handle);
    expect($result['usage']['entryTypes'])->toBeArray();
    expect($result['usage']['entryTypeCount'])->toBe(count($result['usage']['entryTypes']));
});

it('throws ToolException when mode: usage is requested without a handle', function() {
    $this->tool->execute(['mode' => 'usage']);
})->throws(ToolException::class, 'mode "usage" requires a `handle`.');

it('throws ToolException for an unknown field handle', function() {
    $this->tool->execute(['handle' => '__herald_no_such_field__']);
})->throws(ToolException::class);
