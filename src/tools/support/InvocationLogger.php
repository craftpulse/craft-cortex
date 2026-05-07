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
 * the `cortex` category. Captures: tool name, outcome kind (success /
 * tool_error / internal_error), duration in milliseconds, secret-
 * redacted arguments, and the error message when applicable.
 *
 * Phase 1 deliberately omits user attribution. The stdio transport is
 * single-process and runs as the local OS user — there's no per-request
 * Craft user to log. Phase 2's HTTP transport authenticates against a
 * Craft user via OAuth 2.1 / bearer tokens; the audit log surface adds
 * a `userId` field at that point and the wire format bumps from
 * `tool=... kind=... duration_ms=... args=...` to include `user=`. The
 * security rule (`.claude/rules/security.md`) lists user attribution as
 * a Phase 2 ship requirement, not a Phase 1 one.
 *
 * Phase 1 ships logger-backed only — operators tail
 * `storage/logs/web.log` (or wire a Yii log target however they like)
 * to retroactively investigate "what did the LLM do." Phase 2's HTTP
 * transport adds the DB-backed `cortex_invocations` audit table with a
 * VueAdminTable UI; it consumes the same call shape, so this class
 * stays the dispatcher's hook point and the Pro layer subscribes to
 * the same data.
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
    ): void {
        $kind = self::_resolveKind($error);
        $entry = self::formatEntry($toolName, $arguments, $error, $durationMs, $kind);

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
        ?string $kindOverride = null,
    ): string {
        $kind = $kindOverride ?? self::_resolveKind($error);
        $redacted = SecretRedactor::redactArray($arguments);
        $argsJson = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $line = sprintf(
            'tool=%s kind=%s duration_ms=%d args=%s',
            $toolName,
            $kind,
            $durationMs,
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
