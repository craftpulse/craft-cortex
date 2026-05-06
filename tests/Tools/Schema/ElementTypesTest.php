<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use craft\base\ElementInterface;
use craftpulse\cortex\Plugin;

beforeEach(function () {
    $this->tool = Plugin::getInstance()->tools->getByName('element_types');
});

it('lists every registered element type with capability flags', function () {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKeys(['elementTypes', 'count']);
    expect($result['elementTypes'])->toBeArray()->not->toBeEmpty();

    foreach ($result['elementTypes'] as $type) {
        expect($type)->toHaveKeys([
            'class', 'displayName', 'lowerDisplayName',
            'pluralDisplayName', 'pluralLowerDisplayName', 'refHandle',
            'hasTitles', 'hasUris', 'hasStatuses', 'hasDrafts',
            'isLocalized', 'trackChanges',
        ]);

        expect(class_exists($type['class']))->toBeTrue();
        expect(is_subclass_of($type['class'], ElementInterface::class))->toBeTrue();
    }
});

it('includes the 8 core element types', function () {
    $result = $this->tool->execute([]);
    $classes = array_column($result['elementTypes'], 'class');

    foreach ([
        'craft\\elements\\Address',
        'craft\\elements\\Asset',
        'craft\\elements\\Category',
        'craft\\elements\\Entry',
        'craft\\elements\\GlobalSet',
        'craft\\elements\\Tag',
        'craft\\elements\\User',
    ] as $core) {
        expect($classes)->toContain($core);
    }
});
