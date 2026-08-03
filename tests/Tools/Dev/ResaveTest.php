<?php

/**
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('resave');
});

it('throws when type is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class, '`type` is required');

it('throws on unknown type', function() {
    $this->tool->execute(['type' => 'sandwiches']);
})->throws(ToolException::class, "Unknown type 'sandwiches'");

it('rejects options that do not apply to the chosen type', function() {
    // `volume` is for assets — passing it with `type: tags` must fail before
    // reaching the resave pipeline, so the AI sees a clear validation error.
    $this->tool->execute(['type' => 'tags', 'volume' => 'images']);
})->throws(ToolException::class, "Option(s) not supported for type 'tags'");

it('returns the unified terminal envelope shape from execute()', function() {
    // execute() drains stream() and surfaces its terminal envelope, matching
    // the BulkEntries precedent. Same shape on both transports.
    $result = $this->tool->execute([
        'type' => 'entries',
        'section' => 'minorHeroes',
        'limit' => 1,
    ]);

    expect($result)->toHaveKeys([
        'success',
        'type',
        'route',
        'total',
        'processed',
        'succeeded',
        'failed',
        'cancelled',
        'results',
    ]);
    expect($result['type'])->toBe('entries');
    expect($result['route'])->toBe('resave/entries');
    expect($result['cancelled'])->toBeFalse();
    expect($result['total'])->toBeLessThanOrEqual(1);
    expect($result['processed'])->toBe($result['total']);
    expect($result['succeeded'] + $result['failed'])->toBe($result['processed']);
    expect($result['results'])->toBeArray();
});

it('exposes destructiveHint and idempotentHint annotations', function() {
    $annotations = \craftpulse\herald\tools\support\AttributeReader::annotationsFor($this->tool);
    expect($annotations)->toHaveKey('destructiveHint', true);
    expect($annotations)->toHaveKey('idempotentHint', true);
});
