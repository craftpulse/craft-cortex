<?php

/**
 * =========================================================================
 * `search_skills` tool tests.
 *
 * Verifies the search and topics modes against the live bundled-skills
 * corpus. Tests aren't tied to specific match counts (the corpus
 * evolves with skills releases) — they assert relative ranking and
 * envelope shape.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('search_skills');
});

it('is registered on the tool registry', function() {
    expect($this->tool)->not->toBeNull();
    expect($this->tool::getName())->toBe('search_skills');
});

it('throws when query is missing in search mode', function() {
    $this->tool->execute(['mode' => 'search']);
})->throws(ToolException::class, 'query');

it('throws when query has no alphanumeric tokens', function() {
    $this->tool->execute(['mode' => 'search', 'query' => '!!!']);
})->throws(ToolException::class, 'alphanumeric');

it('returns ranked results for a real query', function() {
    $result = $this->tool->execute(['mode' => 'search', 'query' => 'element']);

    expect($result)->toHaveKeys([
        'mode', 'query', 'kind', 'count', 'totalMatches', 'limit', 'results',
    ]);
    expect($result['mode'])->toBe('search');
    expect($result['query'])->toBe('element');
    expect($result['count'])->toBeGreaterThan(0);
    expect($result['results'])->toBeArray();

    // Highest-scoring result first.
    $scores = array_column($result['results'], 'score');
    $sorted = $scores;
    rsort($sorted);
    expect($scores)->toBe($sorted);
});

it('snippet includes the matched run', function() {
    $result = $this->tool->execute(['mode' => 'search', 'query' => 'element', 'limit' => 1]);

    expect($result['results'])->toHaveCount(1);
    $top = $result['results'][0];
    expect($top)->toHaveKeys(['kind', 'uri', 'skill', 'name', 'score', 'matches', 'snippet']);
    expect($top['snippet'])->toBeString()->not->toBeEmpty();
    // The snippet was extracted from a hit so it should mention the
    // query token in some case (snippet preserves original case).
    expect(stripos($top['snippet'], 'element'))->not->toBeFalse();
});

it('respects the kind filter — agent-only search returns only agents', function() {
    $result = $this->tool->execute([
        'mode' => 'search',
        'query' => 'craft',
        'kind' => 'agent',
        'limit' => 50,
    ]);

    expect($result['kind'])->toBe('agent');
    foreach ($result['results'] as $row) {
        expect($row['kind'])->toBe('agent');
        expect($row['uri'])->toStartWith('craft-skills://agents/');
    }
});

it('respects the kind filter — skill-only search returns only routers', function() {
    $result = $this->tool->execute([
        'mode' => 'search',
        'query' => 'craft',
        'kind' => 'skill',
        'limit' => 50,
    ]);

    foreach ($result['results'] as $row) {
        expect($row['kind'])->toBe('skill');
        expect($row['name'])->toBeNull();
        expect($row['uri'])->not->toContain('/agents/');
    }
});

it('topics mode enumerates the corpus without scoring', function() {
    $result = $this->tool->execute(['mode' => 'topics']);

    expect($result)->toHaveKeys(['mode', 'kind', 'count', 'topics']);
    expect($result['mode'])->toBe('topics');
    expect($result['count'])->toBeGreaterThan(8); // at least 8 skill routers + refs + agents
    foreach ($result['topics'] as $row) {
        expect($row)->toHaveKeys(['kind', 'uri', 'skill', 'name', 'length']);
        expect($row['length'])->toBeInt()->toBeGreaterThan(0);
    }
});

it('topics mode kind=agent enumerates only agents', function() {
    $result = $this->tool->execute(['mode' => 'topics', 'kind' => 'agent']);

    foreach ($result['topics'] as $row) {
        expect($row['kind'])->toBe('agent');
        expect($row['uri'])->toStartWith('craft-skills://agents/');
    }
});

it('limit caps the results list', function() {
    $result = $this->tool->execute(['mode' => 'search', 'query' => 'craft', 'limit' => 3]);

    expect(count($result['results']))->toBeLessThanOrEqual(3);
    expect($result['limit'])->toBe(3);
});

it('clamps limit to MAX_LIMIT', function() {
    $result = $this->tool->execute(['mode' => 'search', 'query' => 'craft', 'limit' => 9999]);
    expect($result['limit'])->toBeLessThanOrEqual(50);
});

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'unknown']);
})->throws(ToolException::class, 'Unknown mode');

it('appears in the registry tools/list payload with annotations', function() {
    $payload = Plugin::getInstance()->tools->asListPayload();
    $entry = collect($payload)->firstWhere('name', 'search_skills');

    expect($entry)->not->toBeNull();
    expect($entry)->toBeMcpToolListItem();
    expect($entry['annotations'] ?? [])->toHaveKey('readOnlyHint', true);
    expect($entry['annotations'] ?? [])->toHaveKey('idempotentHint', true);
});
