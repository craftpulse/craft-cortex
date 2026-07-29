<?php

/**
 * =========================================================================
 * InvocationLogger format tests.
 *
 * The dispatcher (`Mcp\Server`) calls `InvocationLogger::logCall()` after
 * every tool invocation; the actual log delivery is Craft's logger and
 * is exercised at the system level. These tests assert the formatted
 * line that operators grep — secret redaction, kind classification,
 * duration emission, error class capture, and the locked context
 * shape (transport, request id, user, client).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;
use craftpulse\herald\tools\ToolException;
use yii\base\Event;

it('emits a success line with redacted args and zero error fields', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: ['handle' => 'news'],
        error: null,
        durationMs: 12,
    );

    expect($entry)
        ->toContain('tool=sections')
        ->toContain('kind=success')
        ->toContain('duration_ms=12')
        ->toContain('args={"handle":"news"}')
        ->and($entry)->not->toContain('error_class=');
});

it('classifies a ToolException as kind=tool_error', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'craft_command',
        arguments: ['command' => 'mailer/test'],
        error: new ToolException('Command "mailer/test" is not in the allowlist'),
        durationMs: 3,
    );

    expect($entry)
        ->toContain('kind=tool_error')
        ->toContain('error_class=' . ToolException::class)
        ->toContain('error_message="Command \\"mailer/test\\" is not in the allowlist"');
});

it('classifies any other Throwable as kind=internal_error', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'craft_exec',
        arguments: ['expression' => '1 + 1'],
        error: new RuntimeException('database is gone'),
        durationMs: 800,
    );

    expect($entry)
        ->toContain('kind=internal_error')
        ->toContain('error_class=RuntimeException')
        ->toContain('error_message="database is gone"');
});

it('redacts secret-keyed values in arguments before emitting the line', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'craft_exec',
        arguments: [
            'expression' => 'my_secret = "abc"',
            'apiKey' => 'should-be-redacted',
            'token' => 'sk_live_12345',
            'safe' => 'visible',
        ],
        error: null,
        durationMs: 1,
    );

    expect($entry)
        ->toContain('"safe":"visible"')
        ->and($entry)->not->toContain('sk_live_12345')
        ->and($entry)->not->toContain('should-be-redacted');
});

it('strips control characters from error messages so log lines stay grep-safe', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: new RuntimeException("multi\nline\rmessage"),
        durationMs: 0,
    );

    // Newlines + carriage returns should be replaced; the line is a
    // single line.
    expect(substr_count($entry, "\n"))->toBe(0);
    expect($entry)->toContain('error_message="multi line message"');
});

// -----------------------------------------------------------------------------
// Locked context shape
// -----------------------------------------------------------------------------

it('emits transport / request_id / user / client fields on every line', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 4,
        context: new InvocationContext(
            transport: 'stdio',
            requestId: 42,
            userId: null,
            clientName: 'claude-code',
        ),
    );

    expect($entry)
        ->toContain('transport=stdio')
        ->toContain('request_id=42')
        ->toContain('user=-')
        ->toContain('client=claude-code');
});

it('emits a `-` placeholder for any null context field', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
        context: new InvocationContext(transport: 'stdio'),
    );

    expect($entry)
        ->toContain('transport=stdio')
        ->toContain('request_id=-')
        ->toContain('user=-')
        ->toContain('client=-');
});

it('honours an HTTP-transport user id when one is provided', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
        context: new InvocationContext(
            transport: 'http',
            requestId: 'req-99',
            userId: 7,
            clientName: 'cursor',
        ),
    );

    expect($entry)
        ->toContain('transport=http')
        ->toContain('request_id=req-99')
        ->toContain('user=7')
        ->toContain('client=cursor');
});

it('falls back to transport=unknown when no context is supplied', function() {
    // Backward-compat for in-process callers that haven't been migrated
    // to context. Should still produce a parseable line.
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
    );

    expect($entry)
        ->toContain('transport=unknown')
        ->toContain('request_id=-')
        ->toContain('user=-')
        ->toContain('client=-');
});

it('emits the locked field order: transport / request_id / user / client / args', function() {
    // Field order is part of the locked surface — log parsers that
    // don't split on `=` (positional regex) will rely on it. Assert.
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: ['k' => 'v'],
        error: null,
        durationMs: 1,
        context: new InvocationContext(
            transport: 'stdio',
            requestId: 1,
            userId: 5,
            clientName: 'cursor',
        ),
    );

    $transportPos = strpos($entry, 'transport=');
    $requestIdPos = strpos($entry, 'request_id=');
    $userPos = strpos($entry, 'user=');
    $clientPos = strpos($entry, 'client=');
    $argsPos = strpos($entry, 'args=');

    expect($transportPos)->toBeLessThan($requestIdPos);
    expect($requestIdPos)->toBeLessThan($userPos);
    expect($userPos)->toBeLessThan($clientPos);
    expect($clientPos)->toBeLessThan($argsPos);
});

// -----------------------------------------------------------------------------
// Sub-gate 7.5 — EVENT_LOG_CALL contract
// -----------------------------------------------------------------------------

it('fires EVENT_LOG_CALL once per logCall() with the structured entry + formatted line', function() {
    $captured = [];
    $handler = function(LogCallEvent $event) use (&$captured): void {
        $captured[] = ['entry' => $event->entry, 'line' => $event->line];
    };

    Event::on(InvocationLogger::class, InvocationLogger::EVENT_LOG_CALL, $handler);

    try {
        InvocationLogger::logCall(
            toolName: 'sections',
            arguments: ['k' => 'v'],
            error: null,
            durationMs: 12,
            context: new InvocationContext(
                transport: 'http',
                requestId: 'r1',
                userId: 1,
                clientName: 'pest',
                tokenId: 2,
                sessionId: 'sess',
            ),
        );
    } finally {
        Event::off(InvocationLogger::class, InvocationLogger::EVENT_LOG_CALL, $handler);
    }

    expect($captured)->toHaveCount(1);
    $payload = $captured[0];

    expect($payload['line'])->toBeString();
    expect($payload['line'])->toContain('tool=sections');
    expect($payload['line'])->toContain('transport=http');
    expect($payload['line'])->toContain('token_id=2');
    expect($payload['line'])->toContain('session_id=sess');

    expect($payload['entry'])->toBeArray();
    expect($payload['entry']['tool'])->toBe('sections');
    expect($payload['entry']['transport'])->toBe('http');
    expect($payload['entry']['token_id'])->toBe(2);
    expect($payload['entry']['session_id'])->toBe('sess');
    expect($payload['entry']['user'])->toBe(1);
    expect($payload['entry']['client'])->toBe('pest');
    expect($payload['entry']['kind'])->toBe('success');
    expect($payload['entry']['duration_ms'])->toBe(12);
});

it('fires EVENT_LOG_CALL even for stdio invocations: listener filters by transport', function() {
    // Decision 11: stdio gets the KV log line, no DB row. The event
    // fires regardless so subscribers can route stdio differently
    // (e.g. mirror to a separate sink); the audit-log listener in
    // Herald::init() is the one that filters by transport.
    $events = [];
    $handler = function(LogCallEvent $event) use (&$events): void {
        $events[] = $event->entry['transport'] ?? null;
    };

    Event::on(InvocationLogger::class, InvocationLogger::EVENT_LOG_CALL, $handler);

    try {
        InvocationLogger::logCall(
            toolName: 'sections',
            arguments: [],
            error: null,
            durationMs: 1,
            context: new InvocationContext(transport: 'stdio'),
        );
    } finally {
        Event::off(InvocationLogger::class, InvocationLogger::EVENT_LOG_CALL, $handler);
    }

    expect($events)->toBe(['stdio']);
});

it('extends the KV line with token_id / session_id when context carries them', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 1,
        context: new InvocationContext(
            transport: 'http',
            requestId: 'r1',
            userId: 1,
            clientName: 'pest',
            tokenId: 42,
            sessionId: 'abcdef',
        ),
    );

    expect($entry)
        ->toContain('token_id=42')
        ->toContain('session_id=abcdef');
});

it('emits dash placeholders for token_id / session_id when context omits them', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
        context: new InvocationContext(transport: 'stdio'),
    );

    expect($entry)
        ->toContain('token_id=-')
        ->toContain('session_id=-');
});

it('extends the KV line with response_excerpt when a response payload is provided', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
        context: new InvocationContext(transport: 'http'),
        responsePayload: '{"a":1}',
    );

    expect($entry)->toContain('response_excerpt=');
});

it('omits response_excerpt when no response payload is provided', function() {
    $entry = InvocationLogger::formatEntry(
        toolName: 'sections',
        arguments: [],
        error: null,
        durationMs: 0,
        context: new InvocationContext(transport: 'http'),
    );

    expect($entry)->not->toContain('response_excerpt=');
});
