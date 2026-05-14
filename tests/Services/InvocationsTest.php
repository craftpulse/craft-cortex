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
 * @author Craftpulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\Invocation as InvocationRecord;
use craftpulse\cortex\records\Token as TokenRecord;
use craftpulse\cortex\services\Invocations;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\InvocationLogger;

beforeEach(function() {
    $this->service = Plugin::getInstance()->invocations;

    // Real user + token ids so the FK constraints on
    // `cortex_invocations.userId` / `.tokenId` don't trip the
    // happy-path tests.
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    $this->userId = (int) $admin->id;

    $issued = Plugin::getInstance()->tokens->issue($this->userId, '_test_/invocations-bearer');
    $this->tokenId = (int) $issued['model']->id;
});

afterEach(function() {
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
        'error_class' => 'craftpulse\\cortex\\tools\\ToolException',
        'error_message' => 'Command not in allowlist',
    ];

    $record = $this->service->record($entry);

    expect($record)->not->toBeNull();
    expect($record->kind)->toBe('tool_error');
    expect($record->errorClass)->toBe('craftpulse\\cortex\\tools\\ToolException');
    expect($record->errorMessage)->toBe('Command not in allowlist');
});

// -----------------------------------------------------------------------------
// record() — stdio filter (decision 11)
// -----------------------------------------------------------------------------

it('record() is a no-op for stdio transport — no DB row written', function() {
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

it('record() is a no-op for unknown transport — no DB row written', function() {
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
    $settings = Plugin::getInstance()->getSettings();
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
    $settings = Plugin::getInstance()->getSettings();
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
    $settings = Plugin::getInstance()->getSettings();
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

it('record() round-trip parity — DB row reconstitutes byte-equal KV line', function() {
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
// Structural
// -----------------------------------------------------------------------------

it('is registered on the plugin as the `invocations` component', function() {
    expect(Plugin::getInstance()->invocations)->toBeInstanceOf(Invocations::class);
});
