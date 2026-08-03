<?php

/**
 * =========================================================================
 * `drafts_and_revisions` tool tests.
 *
 * The playground may not have any drafts or revisions to inspect at any
 * given moment, so list_* and compare-against-real-data tests skip when
 * fixtures are unavailable. Argument-validation and shape tests run
 * unconditionally.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\elements\Entry;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('drafts_and_revisions');
});

// -----------------------------------------------------------------------------
// Registration
// -----------------------------------------------------------------------------

it('is registered on the tool registry', function() {
    expect($this->tool)->not->toBeNull();
    expect($this->tool::getName())->toBe('drafts_and_revisions');
});

// -----------------------------------------------------------------------------
// Argument validation
// -----------------------------------------------------------------------------

it('throws when mode is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class);

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'wat']);
})->throws(ToolException::class);

it('throws when list_revisions is called without canonicalId', function() {
    $this->tool->execute(['mode' => 'list_revisions']);
})->throws(ToolException::class);

it('throws when compare is missing leftId/rightId', function() {
    $this->tool->execute(['mode' => 'compare', 'leftId' => 1]);
})->throws(ToolException::class);

it('throws when compare ids do not resolve to entries', function() {
    $this->tool->execute(['mode' => 'compare', 'leftId' => 999999, 'rightId' => 999998]);
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// list_drafts — payload shape
// -----------------------------------------------------------------------------

it('list_drafts returns an envelope with drafts/count/totalCount/limit/offset', function() {
    $result = $this->tool->execute(['mode' => 'list_drafts']);

    expect($result)->toHaveKeys(['drafts', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['drafts'])->toBeArray();
    expect($result['count'])->toBeInt();
    expect($result['totalCount'])->toBeInt();
});

it('list_drafts respects limit and offset bounds', function() {
    $result = $this->tool->execute(['mode' => 'list_drafts', 'limit' => 5]);
    expect($result['limit'])->toBe(5);

    $clamped = $this->tool->execute(['mode' => 'list_drafts', 'limit' => 99999]);
    expect($clamped['limit'])->toBeLessThanOrEqual(500);
});

// -----------------------------------------------------------------------------
// list_revisions — payload shape (skips if no canonical entries exist)
// -----------------------------------------------------------------------------

it('list_revisions returns an envelope when given a canonical id', function() {
    $entry = Entry::find()->status(null)->site('*')->one();
    if (!$entry instanceof Entry) {
        $this->markTestSkipped('No entries available in the playground.');
    }

    $result = $this->tool->execute([
        'mode' => 'list_revisions',
        'canonicalId' => (int) $entry->id,
    ]);

    expect($result)->toHaveKeys(['canonicalId', 'revisions', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['canonicalId'])->toBe((int) $entry->id);
    expect($result['revisions'])->toBeArray();
});

// -----------------------------------------------------------------------------
// compare — verify shape against a self-comparison (no differences)
// -----------------------------------------------------------------------------

it('compare returns zero differences when comparing an entry to itself', function() {
    $entry = Entry::find()->status(null)->site('*')->one();
    if (!$entry instanceof Entry) {
        $this->markTestSkipped('No entries available in the playground.');
    }

    $result = $this->tool->execute([
        'mode' => 'compare',
        'leftId' => (int) $entry->id,
        'rightId' => (int) $entry->id,
    ]);

    expect($result)->toHaveKeys(['left', 'right', 'differences', 'differenceCount']);
    expect($result['differenceCount'])->toBe(0);
    expect($result['differences'])->toBe([]);

    // Both summaries should be identical.
    expect($result['left'])->toBe($result['right']);
    expect($result['left'])->toHaveKeys(['id', 'canonicalId', 'kind', 'title', 'slug', 'siteHandle']);
    expect($result['left']['kind'])->toBe('canonical');
});

// -----------------------------------------------------------------------------
// MCP tools/list shape
// -----------------------------------------------------------------------------

it('appears in the registry tools/list payload with annotations', function() {
    $payload = Herald::getInstance()->tools->asListPayload();

    $entry = collect($payload)->firstWhere('name', 'drafts_and_revisions');
    expect($entry)->not->toBeNull();
    expect($entry)->toBeMcpToolListItem();
    expect($entry['annotations'] ?? [])->toHaveKeys(['readOnlyHint', 'idempotentHint']);
});
