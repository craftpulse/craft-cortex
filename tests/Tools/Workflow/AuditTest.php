<?php

/**
 * =========================================================================
 * `content_audit` tool tests.
 *
 * Verifies argument validation, payload shape, and that each mode returns
 * a paginated envelope. Real-data assertions are limited because the
 * playground may have zero broken relations / unused assets / propagation
 * gaps at any given moment — the tool just needs to return the right
 * shape with `count: 0` when there's nothing to report.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('content_audit');
});

it('is registered on the tool registry', function() {
    expect($this->tool)->not->toBeNull();
    expect($this->tool::getName())->toBe('content_audit');
});

it('throws when mode is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class);

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'wat']);
})->throws(ToolException::class);

it('relations mode returns a paginated envelope', function() {
    $result = $this->tool->execute(['mode' => 'relations', 'limit' => 10]);

    expect($result)->toHaveKeys(['mode', 'broken', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['mode'])->toBe('relations');
    expect($result['broken'])->toBeArray();
    expect($result['count'])->toBeInt();
    expect($result['totalCount'])->toBeInt();
    expect($result['limit'])->toBe(10);
});

it('unused_assets mode returns a paginated envelope', function() {
    $result = $this->tool->execute(['mode' => 'unused_assets', 'limit' => 10]);

    expect($result)->toHaveKeys(['mode', 'assets', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['mode'])->toBe('unused_assets');
    expect($result['assets'])->toBeArray();
});

it('propagation mode returns a paginated envelope', function() {
    $result = $this->tool->execute(['mode' => 'propagation', 'limit' => 10]);

    expect($result)->toHaveKeys(['mode', 'gaps', 'count', 'totalCount', 'limit', 'offset']);
    expect($result['mode'])->toBe('propagation');
    expect($result['gaps'])->toBeArray();
});

it('clamps limit to MAX_LIMIT', function() {
    $result = $this->tool->execute(['mode' => 'relations', 'limit' => 99999]);
    expect($result['limit'])->toBeLessThanOrEqual(1000);
});

it('appears in the registry tools/list payload with annotations', function() {
    $payload = Plugin::getInstance()->tools->asListPayload();

    $entry = collect($payload)->firstWhere('name', 'content_audit');
    expect($entry)->not->toBeNull();
    expect($entry)->toBeMcpToolListItem();
    expect($entry['annotations'] ?? [])->toHaveKeys(['readOnlyHint', 'idempotentHint']);
});
