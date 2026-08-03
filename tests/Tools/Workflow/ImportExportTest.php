<?php

/**
 * =========================================================================
 * `import_export` tool tests — export-only mode.
 *
 * Verifies the envelope shape, format versioning, and pagination contract.
 * Real entries are exported when the playground has any; otherwise the
 * shape-only assertions still pass with `count: 0`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\workflow\ImportExport;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('import_export');
});

it('is registered on the tool registry', function() {
    expect($this->tool)->not->toBeNull();
    expect($this->tool::getName())->toBe('import_export');
});

it('throws when mode is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class);

it('throws on import mode (Pro-only)', function() {
    $this->tool->execute(['mode' => 'import']);
})->throws(ToolException::class);

it('export returns an envelope with format version and entries array', function() {
    $result = $this->tool->execute(['mode' => 'export', 'limit' => 5]);

    expect($result)->toHaveKeys([
        'format', 'mode', 'exportedAt', 'craftVersion', 'craftEdition', 'schemaVersion',
        'count', 'totalCount', 'limit', 'offset', 'entries',
    ]);
    expect($result['format'])->toBe(ImportExport::FORMAT_VERSION);
    expect($result['format'])->toBe(2);
    expect($result['mode'])->toBe('export');
    expect($result['entries'])->toBeArray();
    expect($result['count'])->toBeInt();
    expect($result['totalCount'])->toBeInt();
    expect($result['limit'])->toBe(5);
    expect($result['craftVersion'])->toBeString()->not->toBeEmpty();
    expect($result['schemaVersion'])->toBeString()->not->toBeEmpty();
});

it('serialised entries carry the cross-env identity surface', function() {
    $result = $this->tool->execute(['mode' => 'export', 'limit' => 1]);

    if ($result['count'] === 0) {
        $this->markTestSkipped('No entries available in the playground.');
    }

    $entry = $result['entries'][0];
    expect($entry)->toHaveKeys([
        'uid', 'id', 'title', 'slug', 'section', 'type', 'site',
        'enabled', 'enabledForSite',
        'authorId', 'authorIds',
        'parentUid', 'level',
        'postDate', 'expiryDate', 'dateCreated', 'dateUpdated',
        'fields',
    ]);
    expect($entry['uid'])->toBeString()->not->toBeEmpty();
    expect($entry['fields'])->toBeArray();
    expect($entry['authorIds'])->toBeArray();
    // `level` is int for Structure entries, null otherwise (channels,
    // singles). Both are valid.
    expect($entry['level'] === null || is_int($entry['level']))->toBeTrue();
    // `parentUid` is string for nested Structure entries, null otherwise.
    expect($entry['parentUid'] === null || is_string($entry['parentUid']))->toBeTrue();
});

it('clamps limit to MAX_LIMIT', function() {
    $result = $this->tool->execute(['mode' => 'export', 'limit' => 99999]);
    expect($result['limit'])->toBeLessThanOrEqual(1000);
});

it('appears in the registry tools/list payload with annotations', function() {
    $payload = Herald::getInstance()->tools->asListPayload();

    $entry = collect($payload)->firstWhere('name', 'import_export');
    expect($entry)->not->toBeNull();
    expect($entry)->toBeMcpToolListItem();
    expect($entry['annotations'] ?? [])->toHaveKeys(['readOnlyHint', 'idempotentHint']);
});
