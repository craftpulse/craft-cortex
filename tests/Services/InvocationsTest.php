<?php

/**
 * =========================================================================
 * Invocations service tests — verify the Gate 7.5 audit-log writer.
 *
 * Covers the locked decisions:
 *   - stdio invocations write to the KV log only — no DB row
 *     (decision 11).
 *   - DB failures must never propagate to the dispatcher — the
 *     soft-write contract.
 *   - Retention pruning honours `Settings::$auditRetentionDays`;
 *     null = forever (decision 10).
 *   - Round-trip parity: a row written from a known entry, then read
 *     back, reconstitutes the same KV line via `formatEntry()`
 *     (decision 5).
 *
 * Tests run against the playground's live DB. Each test cleans up the
 * rows it created via the `_test_/...` toolName marker so the table
 * stays in the shape it started in.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\records\Invocation as InvocationRecord;
use craftpulse\herald\records\Token as TokenRecord;
use craftpulse\herald\services\Invocations;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;

beforeEach(function() {
    $this->service = Herald::getInstance()->invocations;

    // Real user + token ids so the FK constraints on
    // `herald_invocations.userId` / `.tokenId` don't trip the
    // happy-path tests.
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    $this->userId = (int) $admin->id;

    // `Tokens::issue()` is Pro-gated: the HTTP transport that consumes a
    // bearer token refuses Free installs, so minting one there would only
    // produce a credential that can never authenticate. Pin Pro for the
    // file so the cases below exercise issuance rather than the gate.
    $this->originalEdition = Herald::getInstance()->edition;
    Herald::getInstance()->edition = Herald::EDITION_PRO;

    $issued = Herald::getInstance()->tokens->issue($this->userId, '_test_/invocations-bearer');
    $this->tokenId = (int) $issued['model']->id;
});

afterEach(function() {
    Herald::getInstance()->edition = $this->originalEdition;
    InvocationRecord::deleteAll(['like', 'toolName', '_test_/%', false]);
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
});

// -----------------------------------------------------------------------------
// record() — happy path
// -----------------------------------------------------------------------------

it('record() persists every column for an HTTP invocation', function() {
    $entry = [
        'tool' => '_test_/persist-all',
        'kind' => 'success',
        'duration_ms' => 42,
        'transport' => 'http',
        'request_id' => 'req-1',
        'user' => $this->userId,
        'client' => 'pest',
        'token_id' => $this->tokenId,
        'session_id' => 'abc123',
        'args' => '{"k":"v"}',
        'response_excerpt' => '{"result":"ok"}',
        'error_class' => null,
        'error_message' => null,
    ];

    $record = $this->service->record($entry);

    expect($record)->not->toBeNull();
    expect($record->toolName)->toBe('_test_/persist-all');
    expect($record->kind)->toBe('success');
    expect($record->durationMs)->toBe(42);
    expect($record->transport)->toBe('http');
    expect($record->requestId)->toBe('req-1');
    expect($record->userId)->toBe($this->userId);
    expect($record->clientName)->toBe('pest');
    expect($record->tokenId)->toBe($this->tokenId);
    expect($record->sessionId)->toBe('abc123');
    expect($record->argsRedacted)->toBe('{"k":"v"}');
    expect($record->responseExcerpt)->toBe('{"result":"ok"}');
    expect($record->errorClass)->toBeNull();
    expect($record->errorMessage)->toBeNull();
});

it('record() persists tool_error rows with the error class/message', function() {
    $entry = [
        'tool' => '_test_/tool-error',
        'kind' => 'tool_error',
        'duration_ms' => 3,
        'transport' => 'http',
        'request_id' => '2',
        'user' => $this->userId,
        'client' => 'pest',
        'token_id' => null,
        'session_id' => null,
        'args' => '{}',
        'response_excerpt' => null,
        'error_class' => 'craftpulse\\herald\\tools\\ToolException',
        'error_message' => 'Command not in allowlist',
    ];

    $record = $this->service->record($entry);

    expect($record)->not->toBeNull();
    expect($record->kind)->toBe('tool_error');
    expect($record->errorClass)->toBe('craftpulse\\herald\\tools\\ToolException');
    expect($record->errorMessage)->toBe('Command not in allowlist');
});

// -----------------------------------------------------------------------------
// record() — stdio filter (decision 11)
// -----------------------------------------------------------------------------

it('record() is a no-op for stdio transport: no DB row written', function() {
    $entry = [
        'tool' => '_test_/stdio-filter',
        'kind' => 'success',
        'duration_ms' => 1,
        'transport' => 'stdio',
        'args' => '{}',
    ];

    $result = $this->service->record($entry);
    expect($result)->toBeNull();

    $count = InvocationRecord::find()
        ->where(['toolName' => '_test_/stdio-filter'])
        ->count();
    expect((int) $count)->toBe(0);
});

it('record() is a no-op for unknown transport: no DB row written', function() {
    $entry = [
        'tool' => '_test_/unknown-filter',
        'kind' => 'success',
        'duration_ms' => 1,
        'transport' => 'unknown',
        'args' => '{}',
    ];

    $result = $this->service->record($entry);
    expect($result)->toBeNull();

    $count = InvocationRecord::find()
        ->where(['toolName' => '_test_/unknown-filter'])
        ->count();
    expect((int) $count)->toBe(0);
});

// -----------------------------------------------------------------------------
// record() — soft-write contract
// -----------------------------------------------------------------------------

it('record() catches DB exceptions and returns null without re-throwing', function() {
    // Force a validation failure — `kind` is constrained to a known
    // enum, so a bogus value triggers a save() failure inside the
    // service's try/catch. The service must log and return null,
    // never propagate.
    $entry = [
        'tool' => '_test_/soft-write-fail',
        'kind' => 'not-a-real-kind',
        'duration_ms' => 1,
        'transport' => 'http',
        'args' => '{}',
    ];

    $result = $this->service->record($entry);
    expect($result)->toBeNull();

    $count = InvocationRecord::find()
        ->where(['toolName' => '_test_/soft-write-fail'])
        ->count();
    expect((int) $count)->toBe(0);
});

// -----------------------------------------------------------------------------
// prune() — retention
// -----------------------------------------------------------------------------

it('prune() returns 0 and deletes nothing when retention is null', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->auditRetentionDays;
    $settings->auditRetentionDays = null;

    try {
        $this->service->record([
            'tool' => '_test_/prune-null',
            'kind' => 'success',
            'duration_ms' => 1,
            'transport' => 'http',
            'args' => '{}',
        ]);

        $deleted = $this->service->prune();
        expect($deleted)->toBe(0);

        $count = InvocationRecord::find()
            ->where(['toolName' => '_test_/prune-null'])
            ->count();
        expect((int) $count)->toBe(1);
    } finally {
        $settings->auditRetentionDays = $original;
    }
});

it('prune() deletes rows older than the retention window', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->auditRetentionDays;
    $settings->auditRetentionDays = 30;

    try {
        // Persist a row, then back-date it to 60 days ago.
        $old = $this->service->record([
            'tool' => '_test_/prune-old',
            'kind' => 'success',
            'duration_ms' => 1,
            'transport' => 'http',
            'args' => '{}',
        ]);
        expect($old)->not->toBeNull();
        $old->dateCreated = Carbon::now()->subDays(60)->toDateTimeString();
        $old->save(false);

        // Persist a fresh row that should survive the prune.
        $fresh = $this->service->record([
            'tool' => '_test_/prune-fresh',
            'kind' => 'success',
            'duration_ms' => 1,
            'transport' => 'http',
            'args' => '{}',
        ]);
        expect($fresh)->not->toBeNull();

        $deleted = $this->service->prune();
        expect($deleted)->toBeGreaterThanOrEqual(1);

        $oldStillThere = InvocationRecord::find()
            ->where(['toolName' => '_test_/prune-old'])
            ->count();
        expect((int) $oldStillThere)->toBe(0);

        $freshStillThere = InvocationRecord::find()
            ->where(['toolName' => '_test_/prune-fresh'])
            ->count();
        expect((int) $freshStillThere)->toBe(1);
    } finally {
        $settings->auditRetentionDays = $original;
    }
});

it('prune() does not delete rows newer than the retention window', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->auditRetentionDays;
    $settings->auditRetentionDays = 7;

    try {
        // Back-date the row to 1 day ago — comfortably inside the
        // 7-day retention window.
        $row = $this->service->record([
            'tool' => '_test_/prune-young',
            'kind' => 'success',
            'duration_ms' => 1,
            'transport' => 'http',
            'args' => '{}',
        ]);
        expect($row)->not->toBeNull();
        $row->dateCreated = Carbon::now()->subDay()->toDateTimeString();
        $row->save(false);

        $this->service->prune();

        $count = InvocationRecord::find()
            ->where(['toolName' => '_test_/prune-young'])
            ->count();
        expect((int) $count)->toBe(1);
    } finally {
        $settings->auditRetentionDays = $original;
    }
});

// -----------------------------------------------------------------------------
// Round-trip parity (locked decision 5)
// -----------------------------------------------------------------------------

it('record() round-trip parity: DB row reconstitutes byte-equal KV line', function() {
    // Build a canonical entry, format the KV line, persist via the
    // service, read the row back, reformat from the row, assert
    // equality. Drift between the wire format and the DB column set
    // trips this test before it breaks a SIEM forwarder downstream.
    $context = new InvocationContext(
        transport: 'http',
        requestId: 'req-roundtrip',
        userId: $this->userId,
        clientName: 'pest-roundtrip',
        tokenId: $this->tokenId,
        sessionId: 'sess-roundtrip',
    );

    $entry = InvocationLogger::buildEntry(
        toolName: '_test_/roundtrip',
        arguments: ['k' => 'v', 'apiKey' => 'should-be-redacted'],
        error: null,
        durationMs: 7,
        context: $context,
        responsePayload: '{"ok":true}',
    );

    $expectedLine = InvocationLogger::formatEntry(
        toolName: '_test_/roundtrip',
        arguments: ['k' => 'v', 'apiKey' => 'should-be-redacted'],
        error: null,
        durationMs: 7,
        context: $context,
        responsePayload: '{"ok":true}',
    );

    $record = $this->service->record($entry);
    expect($record)->not->toBeNull();

    // Reconstitute an entry from the DB row and reformat.
    $reloaded = InvocationRecord::findOne($record->id);
    expect($reloaded)->not->toBeNull();

    $reentryContext = new InvocationContext(
        transport: $reloaded->transport,
        requestId: $reloaded->requestId,
        userId: $reloaded->userId,
        clientName: $reloaded->clientName,
        tokenId: $reloaded->tokenId,
        sessionId: $reloaded->sessionId,
    );

    // Reconstruct the redacted-args view from the column (already
    // JSON-encoded). `formatEntry()` is responsible for the KV
    // assembly; passing the raw decoded args back through it would
    // re-redact — and the redaction is deterministic, so the same
    // redacted output emerges. We pass the pre-redacted arguments
    // here to mirror the original call's input space.
    $decodedArgs = json_decode((string) $reloaded->argsRedacted, true);
    expect($decodedArgs)->toBeArray();

    $reformattedLine = InvocationLogger::formatEntry(
        toolName: $reloaded->toolName,
        arguments: $decodedArgs,
        error: null,
        durationMs: $reloaded->durationMs,
        context: $reentryContext,
        responsePayload: $reloaded->responseExcerpt,
    );

    expect($reformattedLine)->toBe($expectedLine);
});

// -----------------------------------------------------------------------------
// find() — fluent query
// -----------------------------------------------------------------------------

it('find() returns an InvocationQuery bound to the herald_invocations table', function() {
    $query = $this->service->find();

    expect($query)->toBeInstanceOf(\craftpulse\herald\db\InvocationQuery::class);
    expect($query->from)->toBe([\craftpulse\herald\db\Table::INVOCATIONS]);
});

it('find() filters compose: toolName + kind + userId resolve to the right rows', function() {
    // Seed three rows: two matching, one off on toolName.
    foreach (['_test_/q-a', '_test_/q-a', '_test_/q-b'] as $i => $tool) {
        $this->service->record([
            'tool' => $tool,
            'kind' => 'success',
            'duration_ms' => 10,
            'transport' => 'http',
            'user' => $this->userId,
            'token_id' => $this->tokenId,
            'request_id' => "req-{$i}",
        ]);
    }

    $count = $this->service->find()
        ->toolName('_test_/q-a')
        ->kind('success')
        ->userId($this->userId)
        ->count();

    expect((int) $count)->toBe(2);
});

it('find() hasError isolates error rows from success rows', function() {
    $this->service->record([
        'tool' => '_test_/q-clean',
        'kind' => 'success',
        'duration_ms' => 5,
        'transport' => 'http',
        'user' => $this->userId,
        'token_id' => $this->tokenId,
    ]);
    $this->service->record([
        'tool' => '_test_/q-error',
        'kind' => 'tool_error',
        'duration_ms' => 5,
        'transport' => 'http',
        'user' => $this->userId,
        'token_id' => $this->tokenId,
        'error_class' => 'RuntimeException',
        'error_message' => 'boom',
    ]);

    $errors = $this->service->find()
        ->toolName(['_test_/q-clean', '_test_/q-error'])
        ->hasError(true)
        ->count();
    $clean = $this->service->find()
        ->toolName(['_test_/q-clean', '_test_/q-error'])
        ->hasError(false)
        ->count();

    expect((int) $errors)->toBe(1);
    expect((int) $clean)->toBe(1);
});

it('find() date filters bracket rows by dateCreated', function() {
    $this->service->record([
        'tool' => '_test_/q-date',
        'kind' => 'success',
        'duration_ms' => 1,
        'transport' => 'http',
        'user' => $this->userId,
        'token_id' => $this->tokenId,
    ]);

    $hasRow = $this->service->find()
        ->toolName('_test_/q-date')
        ->after(Carbon::now()->subMinute()->toDateTimeImmutable())
        ->before(Carbon::now()->addMinute()->toDateTimeImmutable())
        ->count();

    $beforePast = $this->service->find()
        ->toolName('_test_/q-date')
        ->before(Carbon::now()->subYear()->toDateTimeImmutable())
        ->count();

    expect((int) $hasRow)->toBe(1);
    expect((int) $beforePast)->toBe(0);
});

it('find() null filters are no-ops so optional UI filters chain cleanly', function() {
    $this->service->record([
        'tool' => '_test_/q-null',
        'kind' => 'success',
        'duration_ms' => 1,
        'transport' => 'http',
        'user' => $this->userId,
        'token_id' => $this->tokenId,
    ]);

    $count = $this->service->find()
        ->toolName('_test_/q-null')
        ->kind(null)
        ->userId(null)
        ->tokenId(null)
        ->sessionId(null)
        ->clientName(null)
        ->requestId(null)
        ->transport(null)
        ->before(null)
        ->after(null)
        ->hasError(null)
        ->count();

    expect((int) $count)->toBe(1);
});

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('is registered on the plugin as the `invocations` component', function() {
    expect(Herald::getInstance()->invocations)->toBeInstanceOf(Invocations::class);
});
