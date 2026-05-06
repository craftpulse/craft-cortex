<?php

/**
 * =========================================================================
 * Registry-level invariants for the Resources service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\resources\ResourceInterface;
use craftpulse\cortex\resources\SkillResource;
use Michtio\CraftCmsClaudeSkills\Skills;

it('registers one resource per skill plus one per reference (8 + sum-of-references)', function () {
    $expected = 0;
    foreach (Skills::skillNames() as $skill) {
        $expected += 1 + count(Skills::references($skill));
    }

    $resources = Plugin::getInstance()->resources;

    expect($resources->getCount())->toBe($expected);
    expect($expected)->toBeGreaterThan(8);
});

it('registers a router resource for every bundled skill', function () {
    $resources = Plugin::getInstance()->resources;

    foreach (Skills::skillNames() as $skill) {
        $uri = sprintf('%s://%s', SkillResource::URI_SCHEME, $skill);
        expect($resources->getByUri($uri))
            ->not->toBeNull()
            ->toBeInstanceOf(ResourceInterface::class);
    }
});

it('registers a reference resource for every reference of every skill', function () {
    $resources = Plugin::getInstance()->resources;

    foreach (Skills::skillNames() as $skill) {
        foreach (Skills::references($skill) as $reference) {
            $uri = sprintf('%s://%s/%s', SkillResource::URI_SCHEME, $skill, $reference);
            expect($resources->getByUri($uri))
                ->not->toBeNull()
                ->toBeInstanceOf(ResourceInterface::class);
        }
    }
});

it('emits unique URIs across the entire registry', function () {
    $uris = array_map(
        static fn (ResourceInterface $r): string => $r->getUri(),
        Plugin::getInstance()->resources->getAll(),
    );

    expect($uris)->toHaveCount(count(array_unique($uris)));
});

it('uses the craft-skills:// scheme for every resource', function () {
    foreach (Plugin::getInstance()->resources->getAll() as $resource) {
        expect($resource->getUri())->toStartWith('craft-skills://');
    }
});

it('returns null for unknown URIs', function () {
    expect(Plugin::getInstance()->resources->getByUri('craft-skills://no-such-skill'))->toBeNull();
    expect(Plugin::getInstance()->resources->getByUri('http://example.com'))->toBeNull();
});

it('builds a spec-shaped resources/list payload', function () {
    $payload = Plugin::getInstance()->resources->asListPayload();

    expect($payload)->toBeArray()->toHaveCount(Plugin::getInstance()->resources->getCount());

    foreach ($payload as $item) {
        expect($item)
            ->toBeArray()
            ->toHaveKeys(['uri', 'name', 'description', 'mimeType'])
            ->and($item['uri'])->toBeString()->toStartWith('craft-skills://')
            ->and($item['mimeType'])->toBe('text/markdown');
    }
});
