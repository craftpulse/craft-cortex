<?php

/**
 * =========================================================================
 * InvocationLogger format tests.
 *
 * The dispatcher (`Mcp\Server`) calls `InvocationLogger::logCall()` after
 * every tool invocation; the actual log delivery is Craft's logger and
 * is exercised at the system level. These tests assert the formatted
 * line that operators grep — secret redaction, kind classification,
 * duration emission, error class capture, and the locked Phase 1 /
 * Phase 2 context shape (transport, request id, user, client).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\InvocationLogger;
use craftpulse\cortex\tools\ToolException;

it('emits a success line with redacted args and zero error fields', function () {
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

it('classifies a ToolException as kind=tool_error', function () {
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

it('classifies any other Throwable as kind=internal_error', function () {
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

it('redacts secret-keyed values in arguments before emitting the line', function () {
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

it('strips control characters from error messages so log lines stay grep-safe', function () {
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
// Locked Phase 1 / Phase 2 context shape
// -----------------------------------------------------------------------------

it('emits transport / request_id / user / client fields on every line', function () {
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

it('emits a `-` placeholder for any null context field', function () {
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

it('honours a Phase 2 user id when one is provided', function () {
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

it('falls back to transport=unknown when no context is supplied', function () {
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

it('emits the locked field order: transport / request_id / user / client / args', function () {
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
