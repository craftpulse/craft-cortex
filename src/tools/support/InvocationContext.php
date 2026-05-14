<?php

namespace craftpulse\cortex\tools\support;

use craftpulse\cortex\mcp\Server;

/**
 * =========================================================================
 * Per-invocation context object for `InvocationLogger`.
 *
 * Locks the audit-log line shape across stdio and HTTP transports so
 * the log format stays stable as auth and correlation surfaces fill
 * in. Every field is optional on construction;
 * `InvocationLogger::formatEntry()` emits a `-` placeholder for any
 * field that's null at log time.
 *
 * Field lifecycle:
 *
 *   - `transport` — `stdio` or `http`. Always populated by the
 *     dispatcher.
 *   - `requestId` — JSON-RPC request id from the calling envelope.
 *     Passed through verbatim. `null` for notifications, but
 *     notifications never invoke tools so logCall() shouldn't see one.
 *   - `userId` — Craft user id. stdio is single-process and runs as
 *     the local OS user with no per-request Craft identity, so this
 *     is always null on stdio. HTTP authenticates via OAuth 2.1 /
 *     bearer tokens and populates the resolved Craft user.
 *   - `clientName` — from MCP `initialize`'s `clientInfo.name` (e.g.
 *     "claude-code", "cursor"). Captured at initialize time and
 *     attached to every subsequent invocation context. `null` until
 *     initialize fires.
 *
 * Locked because third-party log consumers (Pro audit dashboard,
 * external SIEM forwarders) will pin against the field names. Adding
 * a field is additive and backward-compatible; renaming or removing
 * one is a contract bump.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class InvocationContext
{
    // Private Properties
    // =========================================================================

    /**
     * @var CancellationToken Cooperative cancellation signal. Streaming
     *                        tools check `isCancelled()` between yields
     *                        and short-circuit when the flag is up. The
     *                        contract lives here from sub-gate 7.1; the
     *                        wire implementation (HTTP/SSE flipping the
     *                        flag on `notifications/cancelled`) lands in
     *                        7.7. stdio invocations construct a fresh,
     *                        unfired token and never call `cancel()`.
     */
    private readonly CancellationToken $_cancellationToken;

    // Public Methods
    // =========================================================================

    /**
     * @param string                $transport         Transport identifier — `stdio` or
     *                                                 `http`. Mirrors `mcp/Server`'s
     *                                                 `TRANSPORT_*` constants.
     * @param string|int|null       $requestId         JSON-RPC request id (string or
     *                                                 int per spec; null for the rare
     *                                                 in-process invocation that
     *                                                 bypasses JSON-RPC entirely).
     * @param int|null              $userId            Craft user id. Always null on
     *                                                 stdio (single-process, local
     *                                                 OS user — no per-request
     *                                                 Craft identity).
     * @param string|null           $clientName        Client name from
     *                                                 `initialize.clientInfo.name`.
     *                                                 Null before initialize.
     * @param CancellationToken|null $cancellationToken Cooperative cancellation
     *                                                 signal. Defaults to a fresh,
     *                                                 unfired token so non-streaming
     *                                                 call sites don't have to know
     *                                                 about it.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly string $transport = Server::TRANSPORT_STDIO,
        public readonly string|int|null $requestId = null,
        public readonly ?int $userId = null,
        public readonly ?string $clientName = null,
        ?CancellationToken $cancellationToken = null,
    ) {
        $this->_cancellationToken = $cancellationToken ?? new CancellationToken();
    }

    /**
     * Get the cancellation token. Streaming tools call this between
     * yields and bail when `isCancelled()` returns true.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getCancellationToken(): CancellationToken
    {
        return $this->_cancellationToken;
    }
}
