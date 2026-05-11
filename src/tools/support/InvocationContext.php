<?php

namespace craftpulse\cortex\tools\support;

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
    // Public Methods
    // =========================================================================

    /**
     * @param string          $transport   Transport identifier — `stdio` or
     *                                     `http`. Mirrors `mcp/Server`'s
     *                                     `TRANSPORT_*` constants.
     * @param string|int|null $requestId   JSON-RPC request id (string or
     *                                     int per spec; null for the rare
     *                                     in-process invocation that
     *                                     bypasses JSON-RPC entirely).
     * @param int|null        $userId      Craft user id. Always null on
     *                                     stdio (single-process, local
     *                                     OS user — no per-request
     *                                     Craft identity).
     * @param string|null     $clientName  Client name from
     *                                     `initialize.clientInfo.name`.
     *                                     Null before initialize.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly string $transport = 'stdio',
        public readonly string|int|null $requestId = null,
        public readonly ?int $userId = null,
        public readonly ?string $clientName = null,
    ) {
    }

    /**
     * Convenience: a context with only the transport set. Used when the
     * caller has no request id / user / client info to attach (e.g. an
     * in-process tool invocation outside the JSON-RPC dispatcher).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function transportOnly(string $transport = 'stdio'): self
    {
        return new self(transport: $transport);
    }
}
