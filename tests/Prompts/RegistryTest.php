<?php

/**
 * =========================================================================
 * Registry-level invariants for the Prompts service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\prompts\PromptInterface;

it('registers all eight skill-backed prompts with the planned MCP names', function () {
    $expected = [
        'craftcms_extending',
        'craftcms_templates',
        'craftcms_cp_javascript',
        'craftcms_content_modeling',
        'craftcms_php_standards',
        'craftcms_twig_standards',
        'craftcms_ddev',
        'craftcms_setup',
    ];

    $prompts = Plugin::getInstance()->prompts;

    expect($prompts->getCount())->toBe(count($expected));

    foreach ($expected as $name) {
        $prompt = $prompts->getByName($name);
        expect($prompt)->toBeInstanceOf(PromptInterface::class);
        expect($prompt->getName())->toBe($name);
    }
});

it('returns null for unknown prompt names', function () {
    expect(Plugin::getInstance()->prompts->getByName('nope'))->toBeNull();
});

it('builds a spec-shaped prompts/list payload', function () {
    $payload = Plugin::getInstance()->prompts->asListPayload();

    expect($payload)->toBeArray()->toHaveCount(Plugin::getInstance()->prompts->getCount());

    foreach ($payload as $item) {
        expect($item)
            ->toBeArray()
            ->toHaveKeys(['name', 'description'])
            ->and($item['name'])->toBeString()->not->toBeEmpty()
            ->and($item['description'])->toBeString()->not->toBeEmpty();
    }
});

it('omits the arguments key from list entries when a prompt has none', function () {
    foreach (Plugin::getInstance()->prompts->asListPayload() as $item) {
        // Phase 1 ships only argumentless prompts — assert the spec-
        // optional `arguments` key is absent rather than present-but-empty.
        expect($item)->not->toHaveKey('arguments');
    }
});

it('exposes every prompt with a non-empty description', function () {
    foreach (Plugin::getInstance()->prompts->getAll() as $prompt) {
        expect($prompt->getDescription())
            ->toBeString()
            ->not->toBeEmpty();
    }
});
