<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craft\base\FieldInterface;
use craftpulse\cortex\Cortex;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('field_types');
});

it('lists every registered field type class with capability flags', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['fieldTypes', 'count']);
    expect($result['count'])->toBe(count(Craft::$app->getFields()->getAllFieldTypes()));
    expect($result['fieldTypes'])->toBeArray()->not->toBeEmpty();

    foreach ($result['fieldTypes'] as $type) {
        expect($type)->toHaveKeys([
            'class', 'displayName',
            'isMultiInstance', 'isRelational',
            'supportedTranslationMethods',
        ]);

        expect($type['class'])->toBeString();
        expect(class_exists($type['class']))->toBeTrue();
        expect(is_subclass_of($type['class'], FieldInterface::class))->toBeTrue();
        expect($type['displayName'])->toBeString()->not->toBeEmpty();
        expect($type['supportedTranslationMethods'])->toBeArray();
    }
});

it('includes the core PlainText field type', function() {
    $result = $this->tool->execute([]);
    $classes = array_column($result['fieldTypes'], 'class');

    expect($classes)->toContain('craft\\fields\\PlainText');
});
