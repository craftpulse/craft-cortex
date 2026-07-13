<?php

namespace craftpulse\herald\tools\support;

use Craft;
use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\tools\ToolException;
use Throwable;
use yii\base\Event;

/**
 * =========================================================================
 * Per-invocation logger for herald tool calls.
 *
 * Writes a single structured line per tool call to Craft's logger under
 * the `herald` category. The line shape is locked across transports so
 * log consumers (operators tailing `storage/logs/web.log`, the Pro
 * audit dashboard, external SIEM forwarders) don't break when HTTP
 * lands. Format:
 *
 *   tool=<name> kind=<success|tool_error|internal_error|rate_limited>
 *   duration_ms=<int> transport=<stdio|http> request_id=<id|->
 *   user=<id|-> client=<name|-> token_id=<id|-> session_id=<id|->
 *   rate_limit_remaining=<int|-> args=<redacted-json>
 *
 * Unknown fields emit `-`; the line is one space-separated KV record
 * per call. Adding a field is backward-compatible by definition (a
 * positional regex would break, but space/`=`-splitting parsers stay
 * happy). Gate 7.6 added `rate_limited` to the kind enum and
 * `rate_limit_remaining` to the field set; the schema-invariant test
 * keeps DB columns ⊇ KV fields true.
 *
 * Field lifecycle is documented on `InvocationContext`. The summary:
 *   - `transport` — always populated by the dispatcher.
 *   - `request_id` — JSON-RPC id from the calling envelope.
 *   - `user` — null until the HTTP transport authenticates a Craft
 *     user (stdio is single-process, runs as local OS user, no
 *     per-request Craft identity).
 *   - `client` — from MCP `initialize`'s `clientInfo.name`; populates
 *     once per session, after handshake.
 *   - `token_id` — `herald_tokens` row id for bearer-authenticated
 *     requests; null for stdio and OAuth-authenticated requests.
 *   - `session_id` — `Mcp-Session-Id` header value; null for stdio
 *     and for the `initialize` call.
 *
 * Logger-backed today — operators tail Craft logs to retroactively
 * investigate "what did the LLM do." A DB-backed `herald_invocations`
 * audit table sits alongside in Gate 7.5; the table consumes the same
 * structured entry array via `EVENT_LOG_CALL` so this class stays the
 * dispatcher's hook point and the DB layer subscribes to the same
 * data.
 *
 * Logger output stays off STDOUT in `herald/serve` — `ServeController`
 * caps log levels to error+warning during stdio sessions, so info-level
 * invocation lines go to the configured file targets only and never
 * corrupt the JSON-RPC stream.
 *
 * Round-trip invariant (locked, Gate 7.5 decision 5): every key emitted
 * in the formatted KV line has a matching column on `herald_invocations`.
 * `response_excerpt` is the one Gate-7.5 addition that extends both the
 * KV line and the DB column — additive on both sides preserves the
 * invariant.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class InvocationLogger
{
    // Constants
    // =========================================================================

    /**
     * Log category. Targets can filter on this to route invocation lines
     * to a dedicated file.
     */
    public const CATEGORY = 'herald';

    public const KIND_SUCCESS = 'success';
    public const KIND_TOOL_ERROR = 'tool_error';
    public const KIND_INTERNAL_ERROR = 'internal_error';
    public const KIND_RATE_LIMITED = 'rate_limited';
    public const KIND_CANCELLED = 'cancelled';

    /**
     * Audit kind for an out-of-band security event that did not flow
     * through a tool dispatch — currently the refresh-token theft
     * detection family-wide revoke. Lets operators filter the Activity
     * dashboard for security incidents independent of tool calls.
     *
     * @since 5.0.0
     */
    public const KIND_SECURITY = 'security';

    /**
     * Fired once per `logCall()` invocation, after the formatted KV
     * line has been emitted to Craft's logger. Subscribers receive a
     * `LogCallEvent` carrying both the structured entry array and the
     * formatted KV line.
     *
     * The Gate-7.5 audit-log writer (`services/Invocations::record()`)
     * subscribes to this event in `Herald::init()` to persist a row to
     * `herald_invocations` for HTTP-transport invocations. Third-party
     * plugins can subscribe to the same event to mirror the audit
     * trail elsewhere (SIEM forwarders, external observability stacks)
     * without re-implementing the formatter.
     *
     * @since 5.0.0
     */
    public const EVENT_LOG_CALL = 'logCall';

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
     * `$responsePayload` is the (post-redaction, JSON-encoded) tool
     * response or null for error paths. Captured by the dispatcher and
     * threaded through so the audit-log writer can persist the first
     * `Settings::$auditResponseExcerptBytes` bytes for forensics. The
     * KV line carries the same prefix as `response_excerpt=` so the
     * round-trip invariant holds.
     *
     * After the Craft logger write, an `EVENT_LOG_CALL` is fired
     * carrying the structured entry array + the formatted KV line.
     * Subscribers persist to alternate audit surfaces (the DB-backed
     * `herald_invocations` table is the canonical subscriber).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function logCall(
        string $toolName,
        array $arguments,
        ?Throwable $error,
        int $durationMs,
        ?InvocationContext $context = null,
        ?string $responsePayload = null,
    ): void {
        $kind = self::_resolveKind($error);
        $line = self::formatEntry($toolName, $arguments, $error, $durationMs, $context, $kind, $responsePayload);

        // Errors get a higher log level so default targets capture them
        // even when info filtering is on.
        if ($kind === self::KIND_INTERNAL_ERROR) {
            Craft::error($line, self::CATEGORY);
        } elseif ($kind === self::KIND_TOOL_ERROR) {
            Craft::warning($line, self::CATEGORY);
        } else {
            Craft::info($line, self::CATEGORY);
        }

        // Fire the structured event after the file-log write succeeds.
        // The dispatched event carries both the parsed entry array
        // (`$event->entry`) and the formatted KV line (`$event->line`)
        // so subscribers can pick whichever surface fits their need.
        $event = new LogCallEvent();
        $event->entry = self::buildEntry($toolName, $arguments, $error, $durationMs, $context, $kind, $responsePayload);
        $event->line = $line;
        Event::trigger(self::class, self::EVENT_LOG_CALL, $event);
    }

    /**
     * Build the log line as a string. Pure — no side effects — so the
     * test suite asserts shape without driving Craft's logger. Consumers
     * outside the dispatcher (the future audit-log writer, debugging
     * helpers) can call this directly to share the same redaction +
     * formatting policy.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function formatEntry(
        string $toolName,
        array $arguments,
        ?Throwable $error,
        int $durationMs,
        ?InvocationContext $context = null,
        ?string $kindOverride = null,
        ?string $responsePayload = null,
    ): string {
        $kind = $kindOverride ?? self::_resolveKind($error);
        $redacted = SecretRedactor::redactArray($arguments);
        $argsJson = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ctx = $context ?? new InvocationContext(transport: Server::TRANSPORT_UNKNOWN);

        $line = sprintf(
            'tool=%s kind=%s duration_ms=%d transport=%s request_id=%s user=%s client=%s token_id=%s session_id=%s rate_limit_remaining=%s args=%s',
            $toolName,
            $kind,
            $durationMs,
            $ctx->transport,
            self::_orDash($ctx->requestId),
            self::_orDash($ctx->userId),
            self::_orDash($ctx->clientName),
            self::_orDash($ctx->tokenId),
            self::_orDash($ctx->sessionId),
            self::_orDash($ctx->rateLimitRemaining),
            $argsJson,
        );

        if ($responsePayload !== null) {
            $excerpt = self::_excerptResponse($responsePayload);
            $line .= sprintf(' response_excerpt=%s', self::_quoteForLog($excerpt));
        }

        if ($error !== null) {
            $line .= sprintf(
                ' error_class=%s error_message=%s',
                $error::class,
                self::_quoteForLog($error->getMessage()),
            );
        }

        return $line;
    }

    /**
     * Build the structured entry array that mirrors the KV line. The
     * `LogCallEvent` carries this array verbatim so subscribers (the
     * DB-backed audit log, SIEM forwarders) read individual fields
     * without re-parsing the formatted line. The keys here match the
     * KV tokens in `formatEntry()` 1:1 — the round-trip invariant
     * locked in Gate 7.5 decision 5.
     *
     * `args` carries the post-redaction JSON-encoded arguments as a
     * string (same surface the KV line emits); subscribers that want
     * to JSON-decode them can `json_decode($entry['args'], true)`.
     *
     * `response_excerpt` carries the first
     * `Settings::$auditResponseExcerptBytes` bytes of the response —
     * already truncated and ready to persist.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function buildEntry(
        string $toolName,
        array $arguments,
        ?Throwable $error,
        int $durationMs,
        ?InvocationContext $context = null,
        ?string $kindOverride = null,
        ?string $responsePayload = null,
    ): array {
        $kind = $kindOverride ?? self::_resolveKind($error);
        $redacted = SecretRedactor::redactArray($arguments);
        $argsJson = (string) json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ctx = $context ?? new InvocationContext(transport: Server::TRANSPORT_UNKNOWN);

        return [
            'tool' => $toolName,
            'kind' => $kind,
            'duration_ms' => $durationMs,
            'transport' => $ctx->transport,
            'request_id' => $ctx->requestId,
            'user' => $ctx->userId,
            'client' => $ctx->clientName,
            'token_id' => $ctx->tokenId,
            'session_id' => $ctx->sessionId,
            'rate_limit_remaining' => $ctx->rateLimitRemaining,
            'args' => $argsJson,
            'response_excerpt' => $responsePayload !== null
                ? self::_excerptResponse($responsePayload)
                : null,
            'error_class' => $error !== null ? $error::class : null,
            'error_message' => $error?->getMessage(),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Truncate a response payload to the configured excerpt budget.
     * Reads `Settings::$auditResponseExcerptBytes` at call time so an
     * operator changing the setting at runtime sees the new bound
     * applied to subsequent invocations without a reboot. Falls back
     * to the default (2048) when the plugin isn't yet booted — in
     * practice every in-flight log emission has Plugin available, but
     * the guard keeps standalone unit tests calling `formatEntry()`
     * directly from blowing up.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private static function _excerptResponse(string $payload): string
    {
        $plugin = Herald::getInstance();
        $bytes = $plugin !== null ? $plugin->getSettings()->getAuditResponseExcerptBytes() : 2048;
        if (strlen($payload) <= $bytes) {
            return $payload;
        }
        // `substr` cuts at byte boundaries; we don't try to preserve
        // multi-byte char alignment because the excerpt is for
        // forensics, not display, and the audit dashboard re-decodes
        // best-effort.
        return substr($payload, 0, $bytes);
    }

    /**
     * @author Craftpulse
     * @since  5.0.0
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
     * stays stable across transports.
     *
     * @author Craftpulse
     * @since  5.0.0
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
     * @since  5.0.0
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
