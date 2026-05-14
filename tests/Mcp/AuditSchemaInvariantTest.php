<?php

/**
 * =========================================================================
 * Architecture invariant — `cortex_invocations` schema ⊇ KV log fields.
 *
 * Locked decision 5 (Gate 7.5): every field
 * `InvocationLogger::formatEntry()` emits as a KV token has a matching
 * column on the `cortex_invocations` table. Drift between the wire
 * format and the DB column set will break SIEM forwarders and the Pro
 * audit dashboard's column-by-column display. This test catches the
 * drift at the test layer before it lands in production.
 *
 * The check is directional: KV ⊆ DB. Adding a DB-only column (e.g. an
 * internal correlation id used by the dashboard but not surfaced on
 * the wire) is fine; that's an additive DB extension. Adding a KV
 * token without a corresponding column would break round-trip parity
 * — that's what this test catches.
 *
 * Implementation: build a known-shape entry via `formatEntry()`, parse
 * the emitted line into KV tokens, then assert every token name maps
 * to a column on the table via `craft\db\TableSchema::$columns`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\db\Table;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\InvocationLogger;

it('every KV field on the formatted log line has a matching cortex_invocations column', function() {
    // Wire-format token → DB column. The KV uses snake_case for
    // human-readable log tailing; the DB uses camelCase Craft
    // conventions. The mapping is explicit so a future column rename
    // surfaces here.
    $kvToColumn = [
        'tool' => 'toolName',
        'kind' => 'kind',
        'duration_ms' => 'durationMs',
        'transport' => 'transport',
        'request_id' => 'requestId',
        'user' => 'userId',
        'client' => 'clientName',
        'token_id' => 'tokenId',
        'session_id' => 'sessionId',
        'rate_limit_remaining' => 'rateLimitRemaining',
        'args' => 'argsRedacted',
        'response_excerpt' => 'responseExcerpt',
        'error_class' => 'errorClass',
        'error_message' => 'errorMessage',
    ];

    $line = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: ['k' => 'v'],
        error: new RuntimeException('boom'),
        durationMs: 1,
        context: new InvocationContext(
            transport: 'http',
            requestId: 'r1',
            userId: 1,
            clientName: 'pest',
            tokenId: 2,
            sessionId: 's1',
        ),
        responsePayload: '{"ok":true}',
    );

    // Extract every leading-KV token name from the line. The shape is
    // `name=value name=value …` where value either has no whitespace
    // or is double-quoted. Match the bare `name=` openers across the
    // whole line.
    preg_match_all('/(?:^|\s)([a-z_]+)=/', $line, $matches);
    $emittedKeys = array_values(array_unique($matches[1]));

    // Resolve the live column list from the DB.
    $schema = Craft::$app->getDb()->getTableSchema(Table::INVOCATIONS);
    expect($schema)->not->toBeNull();
    $columns = array_keys($schema->columns);

    $missing = [];
    foreach ($emittedKeys as $kv) {
        if (!isset($kvToColumn[$kv])) {
            $missing[] = sprintf('KV field `%s` emitted by formatEntry() has no DB-column mapping', $kv);
            continue;
        }
        $expectedColumn = $kvToColumn[$kv];
        if (!in_array($expectedColumn, $columns, true)) {
            $missing[] = sprintf('KV field `%s` maps to column `%s` but no such column exists', $kv, $expectedColumn);
        }
    }

    expect($missing)->toBe([]);
});

it('every documented KV field appears on a representative formatted line', function() {
    // Sister invariant: build a line that exercises every documented
    // field path and assert each shows up. Catches the inverse drift
    // where a field gets DROPPED from `formatEntry()` — the previous
    // test would pass for the smaller emitted set, but downstream
    // consumers (SIEM forwarders pinned to the full field list) would
    // break.
    $expected = [
        'tool',
        'kind',
        'duration_ms',
        'transport',
        'request_id',
        'user',
        'client',
        'token_id',
        'session_id',
        'rate_limit_remaining',
        'args',
        'response_excerpt',
        'error_class',
        'error_message',
    ];

    $line = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: ['k' => 'v'],
        error: new RuntimeException('boom'),
        durationMs: 1,
        context: new InvocationContext(
            transport: 'http',
            requestId: 'r1',
            userId: 1,
            clientName: 'pest',
            tokenId: 2,
            sessionId: 's1',
        ),
        responsePayload: '{"ok":true}',
    );

    $missing = [];
    foreach ($expected as $field) {
        if (!str_contains($line, $field . '=')) {
            $missing[] = $field;
        }
    }

    expect($missing)->toBe([]);
});
