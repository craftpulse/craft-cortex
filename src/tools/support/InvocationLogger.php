<?php

namespace craftpulse\cortex\tools\support;

use Craft;
use craftpulse\cortex\tools\ToolException;
use Throwable;

/**
 * =========================================================================
 * Per-invocation logger for cortex tool calls.
 *
 * Writes a single structured line per tool call to Craft's logger under
 * the `cortex` category. The line shape is locked across Phase 1 and
 * Phase 2 so log consumers (operators tailing `storage/logs/web.log`,
 * the Pro audit dashboard, external SIEM forwarders) don't break on
 * the transport upgrade. Format:
 *
 *   tool=<name> kind=<success|tool_error|internal_error>
 *   duration_ms=<int> transport=<stdio|http> request_id=<id|->
 *   user=<id|-> client=<name|-> args=<redacted-json>
 *
 * Unknown fields emit `-`; the line is one space-separated KV record
 * per call. Adding a field is backward-compatible by definition (a
 * positional regex would break, but space/`=`-splitting parsers stay
 * happy).
 *
 * Field lifecycle is documented on `InvocationContext`. The summary:
 *   - `transport` — always populated by the dispatcher.
 *   - `request_id` — JSON-RPC id, present from Phase 1.
 *   - `user` — null until Phase 2's HTTP transport authenticates a
 *     Craft user (stdio is single-process, runs as local OS user, no
 *     per-request Craft identity).
 *   - `client` — from MCP `initialize`'s `clientInfo.name`; populates
 *     once per session, after handshake.
 *
 * Phase 1 ships logger-backed only — operators tail Craft logs to
 * retroactively investigate "what did the LLM do." Phase 2 adds a
 * DB-backed `cortex_invocations` audit table; it consumes the same
 * call shape, so this class stays the dispatcher's hook point and the
 * Pro layer subscribes to the same data.
 *
 * Logger output stays off STDOUT in `cortex/serve` — `ServeController`
 * caps log levels to error+warning during stdio sessions, so info-level
 * invocation lines go to the configured file targets only and never
 * corrupt the JSON-RPC stream.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
final class InvocationLogger
{
    // Constants
    // =========================================================================

    /**
     * Log category. Targets can filter on this to route invocation lines
     * to a dedicated file.
     */
    public const CATEGORY = 'cortex';

    public const KIND_SUCCESS = 'success';
    public const KIND_TOOL_ERROR = 'tool_error';
    public const KIND_INTERNAL_ERROR = 'internal_error';

    // Public Methods
    // =========================================================================

    /**
     * Log one invocation. Outcome is determined by which exception (if
     * any) was caught: a ToolException is a tool-level error returned to
     * the client as `isError: true`; any other Throwable is an internal
     * error surfaced as JSON-RPC -32603. Null means the call succeeded.
     *
     * `$context` is optional for backward compatibility with in-process
     * callers; the dispatcher always passes one with at least the
     * transport set. Omitting it emits `-` placeholders for all
     * context fields.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function logCall(
        string $toolName,
        array $arguments,
        ?Throwable $error,
        int $durationMs,
        ?InvocationContext $context = null,
    ): void {
        $kind = self::_resolveKind($error);
        $entry = self::formatEntry($toolName, $arguments, $error, $durationMs, $context, $kind);

        // Errors get a higher log level so default targets capture them
        // even when info filtering is on.
        if ($kind === self::KIND_INTERNAL_ERROR) {
            Craft::error($entry, self::CATEGORY);
        } elseif ($kind === self::KIND_TOOL_ERROR) {
            Craft::warning($entry, self::CATEGORY);
        } else {
            Craft::info($entry, self::CATEGORY);
        }
    }

    /**
     * Build the log line as a string. Pure — no side effects — so the
     * test suite asserts shape without driving Craft's logger. Consumers
     * outside the dispatcher (Phase 2 audit-log writer, future debugging
     * helpers) can call this directly to share the same redaction +
     * formatting policy.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function formatEntry(
        string $toolName,
        array $arguments,
        ?Throwable $error,
        int $durationMs,
        ?InvocationContext $context = null,
        ?string $kindOverride = null,
    ): string {
        $kind = $kindOverride ?? self::_resolveKind($error);
        $redacted = SecretRedactor::redactArray($arguments);
        $argsJson = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ctx = $context ?? new InvocationContext(transport: 'unknown');

        $line = sprintf(
            'tool=%s kind=%s duration_ms=%d transport=%s request_id=%s user=%s client=%s args=%s',
            $toolName,
            $kind,
            $durationMs,
            $ctx->transport,
            self::_orDash($ctx->requestId),
            self::_orDash($ctx->userId),
            self::_orDash($ctx->clientName),
            $argsJson,
        );

        if ($error !== null) {
            $line .= sprintf(
                ' error_class=%s error_message=%s',
                $error::class,
                self::_quoteForLog($error->getMessage()),
            );
        }

        return $line;
    }

    // Private Methods
    // =========================================================================

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private static function _resolveKind(?Throwable $error): string
    {
        if ($error === null) {
            return self::KIND_SUCCESS;
        }
        if ($error instanceof ToolException) {
            return self::KIND_TOOL_ERROR;
        }
        return self::KIND_INTERNAL_ERROR;
    }

    /**
     * Render a context field for inclusion in the log line. Null becomes
     * the literal `-` (Apache-style "not applicable / not yet known"
     * placeholder) so the field is always present and the line shape
     * stays stable across Phase 1 / Phase 2.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private static function _orDash(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        return (string) $value;
    }

    /**
     * Wrap a string for inclusion in the log line. Quote when it contains
     * whitespace or `=`; otherwise emit bare. Newlines and control bytes
     * are stripped — the line is meant to grep cleanly.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private static function _quoteForLog(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
        $clean = trim($clean);
        if ($clean === '' || preg_match('/[\s="]/', $clean)) {
            return '"' . addcslashes($clean, '"\\') . '"';
        }
        return $clean;
    }
}
