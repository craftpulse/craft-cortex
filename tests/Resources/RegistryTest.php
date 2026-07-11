<?php

/**
 * =========================================================================
 * Registry-level invariants for the Resources service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\resources\AgentResource;
use craftpulse\herald\resources\ResourceInterface;
use craftpulse\herald\resources\SkillResource;
use Michtio\CraftCmsClaudeSkills\Skills;

it('registers one resource per skill plus one per reference plus one per agent', function() {
    $expected = count(Skills::agentNames());
    foreach (Skills::skillNames() as $skill) {
        $expected += 1 + count(Skills::references($skill));
    }

    $resources = Herald::getInstance()->resources;

    expect($resources->getCount())->toBe($expected);
    expect($expected)->toBeGreaterThan(8);
});

it('registers a router resource for every bundled skill', function() {
    $resources = Herald::getInstance()->resources;

    foreach (Skills::skillNames() as $skill) {
        $uri = sprintf('%s://%s', SkillResource::URI_SCHEME, $skill);
        expect($resources->getByUri($uri))
            ->not->toBeNull()
            ->toBeInstanceOf(ResourceInterface::class);
    }
});

it('registers a reference resource for every reference of every skill', function() {
    $resources = Herald::getInstance()->resources;

    foreach (Skills::skillNames() as $skill) {
        foreach (Skills::references($skill) as $reference) {
            $uri = sprintf('%s://%s/%s', SkillResource::URI_SCHEME, $skill, $reference);
            expect($resources->getByUri($uri))
                ->not->toBeNull()
                ->toBeInstanceOf(ResourceInterface::class);
        }
    }
});

it('emits unique URIs across the entire registry', function() {
    $uris = array_map(
        static fn(ResourceInterface $r): string => $r->getUri(),
        Herald::getInstance()->resources->getAll(),
    );

    expect($uris)->toHaveCount(count(array_unique($uris)));
});

it('uses the craft-skills:// scheme for every resource', function() {
    foreach (Herald::getInstance()->resources->getAll() as $resource) {
        expect($resource->getUri())->toStartWith('craft-skills://');
    }
});

it('returns null for unknown URIs', function() {
    expect(Herald::getInstance()->resources->getByUri('craft-skills://no-such-skill'))->toBeNull();
    expect(Herald::getInstance()->resources->getByUri('http://example.com'))->toBeNull();
});

it('builds a spec-shaped resources/list payload', function() {
    $payload = Herald::getInstance()->resources->asListPayload();

    expect($payload)->toBeArray()->toHaveCount(Herald::getInstance()->resources->getCount());

    foreach ($payload as $item) {
        expect($item)
            ->toBeArray()
            ->toHaveKeys(['uri', 'name', 'description', 'mimeType'])
            ->and($item['uri'])->toBeString()->toStartWith('craft-skills://')
            ->and($item['mimeType'])->toBe('text/markdown');
    }
});

it('registers an agent resource for every bundled agent', function() {
    $resources = Herald::getInstance()->resources;

    foreach (Skills::agentNames() as $agent) {
        $uri = sprintf('%s://%s/%s', AgentResource::URI_SCHEME, AgentResource::URI_PREFIX, $agent);
        expect($resources->getByUri($uri))
            ->not->toBeNull()
            ->toBeInstanceOf(AgentResource::class);
    }
});

it('agent resource read returns the agent file content', function() {
    $agents = Skills::agentNames();
    if ($agents === []) {
        // Older skills package; agent surface unavailable.
        $this->markTestSkipped('No agents in the bundled skills package.');
    }

    $first = $agents[0];
    $uri = sprintf('%s://%s/%s', AgentResource::URI_SCHEME, AgentResource::URI_PREFIX, $first);
    $resource = Herald::getInstance()->resources->getByUri($uri);
    $block = $resource->read();

    expect($block)
        ->toHaveKey('uri', $uri)
        ->toHaveKey('mimeType', 'text/markdown')
        ->toHaveKey('text');
    expect($block['text'])->toContain('---'); // frontmatter delimiter
});
