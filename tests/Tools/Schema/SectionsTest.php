<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('sections');
});

it('lists all sections with the expected per-section shape', function() {
    $result = $this->tool->execute([]);

    expect($result)->toHaveKey('sections');
    expect($result['sections'])->toBeArray();

    foreach ($result['sections'] as $section) {
        expect($section)->toHaveKeys([
            'id', 'uid', 'name', 'handle', 'type',
            'enableVersioning', 'propagationMethod',
            'entryTypeCount', 'entryTypeHandles', 'siteSettings',
        ]);
        expect($section['type'])->toBeIn(['single', 'channel', 'structure']);
        expect($section['siteSettings'])->toBeArray();
    }
});

it('returns the count when count: true is passed', function() {
    $result = $this->tool->execute(['count' => true]);

    expect($result)->toHaveKey('count');
    expect($result['count'])->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($result['count'])->toBe(count(Craft::$app->getEntries()->getAllSections()));
});

it('returns a single section when handle is passed', function() {
    $allSections = Craft::$app->getEntries()->getAllSections();
    if ($allSections === []) {
        $this->markTestSkipped('No sections in playground to fetch by handle.');
    }

    $handle = $allSections[0]->handle;
    $result = $this->tool->execute(['handle' => $handle]);

    expect($result)->toHaveKey('section');
    expect($result['section']['handle'])->toBe($handle);
});

it('throws ToolException for an unknown section handle', function() {
    $this->tool->execute(['handle' => '__herald_no_such_section__']);
})->throws(ToolException::class);
