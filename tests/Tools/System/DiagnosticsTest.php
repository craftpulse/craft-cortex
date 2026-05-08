<?php

/**
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Plugin::getInstance()->tools->getByName('system_diagnostics');
});

it('throws when type is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class);

it('throws on unknown type', function() {
    $this->tool->execute(['type' => 'oops']);
})->throws(ToolException::class, 'Unknown type');

it('returns deprecations payload with entries + counts', function() {
    $result = $this->tool->execute(['type' => 'deprecations']);

    expect($result)->toHaveKeys(['type', 'entries', 'count', 'totalCount']);
    expect($result['type'])->toBe('deprecations');
    expect($result['entries'])->toBeArray();
});

it('returns queue payload with totals + jobs', function() {
    $result = $this->tool->execute(['type' => 'queue']);

    expect($result)->toHaveKeys(['type', 'totals', 'jobs', 'count']);
    expect($result['type'])->toBe('queue');
    expect($result['totals'])->toHaveKeys(['waiting', 'delayed', 'reserved', 'failed', 'total']);
});

it('returns project_config_diff payload', function() {
    $result = $this->tool->execute(['type' => 'project_config_diff']);

    expect($result)->toHaveKeys(['type', 'areChangesPending', 'pendingChangesSummary']);
    expect($result['type'])->toBe('project_config_diff');
});

it('returns logs payload (gracefully handles missing file)', function() {
    $result = $this->tool->execute(['type' => 'logs', 'channel' => 'cortex_no_such_log']);

    expect($result)->toHaveKeys(['type', 'channel', 'path', 'exists', 'entries']);
    expect($result['exists'])->toBeFalse();
});

it('strips path traversal segments from the channel argument', function() {
    // `..` segments in `channel` would otherwise let a caller read
    // arbitrary files via FileHelper::normalizePath(). The tool is
    // stdio-only and trusted-local in Phase 1, but the basename guard
    // is a Phase-2 prebreak hardening — verify it stays in place.
    $result = $this->tool->execute([
        'type' => 'logs',
        'channel' => '../../config/db',
    ]);

    expect($result['type'])->toBe('logs');

    $logDir = Craft::$app->getPath()->getLogPath();
    expect($result['path'])
        ->toStartWith($logDir)
        ->and(basename($result['path']))->toBe('db.log')
        ->and($result['path'])->not->toContain('..');
});

it('caps limit at MAX_LIMIT and floors at 1', function() {
    $result = $this->tool->execute(['type' => 'deprecations', 'limit' => 99999]);
    expect(count($result['entries']))->toBeLessThanOrEqual(500);

    $result = $this->tool->execute(['type' => 'deprecations', 'limit' => 0]);
    expect($result['count'])->toBeLessThanOrEqual(1);
});
