<?php

/**
 * =========================================================================
 * InvocationLogger format tests.
 *
 * The dispatcher (`Mcp\Server`) calls `InvocationLogger::logCall()` after
 * every tool invocation; the actual log delivery is Craft's logger and
 * is exercised at the system level. These tests assert the formatted
 * line that operators grep — secret redaction, kind classification,
 * duration emission, error class capture.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

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
