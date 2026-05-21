<?php

/**
 * =========================================================================
 * Per-resource read: assert the envelope shape MCP requires for
 * `resources/read` blocks and that text matches the bundled skills.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\resources\SkillResource;
use Michtio\CraftCmsClaudeSkills\Skills;

it('reads a skill router resource as the SKILL.md content', function() {
    $uri = sprintf('%s://craftcms', SkillResource::URI_SCHEME);
    $resource = Cortex::getInstance()->resources->getByUri($uri);
    expect($resource)->not->toBeNull();

    $block = $resource->read();

    expect($block)
        ->toBeArray()
        ->toHaveKeys(['uri', 'mimeType', 'text'])
        ->and($block['uri'])->toBe($uri)
        ->and($block['mimeType'])->toBe('text/markdown')
        ->and($block['text'])->toBe(Skills::content('craftcms'));
});

it('reads a reference resource as the corresponding references/<name>.md content', function() {
    $uri = sprintf('%s://craftcms/elements', SkillResource::URI_SCHEME);
    $resource = Cortex::getInstance()->resources->getByUri($uri);
    expect($resource)->not->toBeNull();

    $block = $resource->read();

    expect($block['uri'])->toBe($uri);
    expect($block['mimeType'])->toBe('text/markdown');
    expect($block['text'])->toBe(Skills::referenceContent('craftcms', 'elements'));
});

it('reads every registered resource without raising and emits non-empty markdown', function() {
    foreach (Cortex::getInstance()->resources->getAll() as $resource) {
        $block = $resource->read();

        expect($block)->toHaveKeys(['uri', 'mimeType', 'text']);
        expect($block['text'])->toBeString()->not->toBeEmpty();
    }
});

it('builds the expected URI for skill-only and skill+reference cases', function() {
    $router = new SkillResource(skill: 'craftcms');
    expect($router->getUri())->toBe('craft-skills://craftcms');

    $reference = new SkillResource(skill: 'craftcms', reference: 'elements');
    expect($reference->getUri())->toBe('craft-skills://craftcms/elements');
});
