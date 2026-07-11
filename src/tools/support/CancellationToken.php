<?php

namespace craftpulse\herald\tools\support;

/**
 * =========================================================================
 * Cooperative cancellation signal threaded through `InvocationContext`.
 *
 * Streaming tools (Gate 8) check `isCancelled()` between yields and
 * short-circuit when the flag is up; the wiring source (the HTTP/SSE
 * transport on receipt of MCP `notifications/cancelled`) flips the flag
 * by calling `cancel()`. stdio invocations construct a fresh, unfired
 * token and never call `cancel()` — the contract holds, the wire stays
 * dormant.
 *
 * The class is intentionally small: a boolean flag, an optional
 * cancellation reason, and three read methods. Locking the shape from
 * sub-gate 7.1 (per `docs/plans/gate-7.md`) means streaming tools in
 * later gates can opt in cooperatively without an interface break here.
 *
 * Concurrency: PHP requests are single-threaded inside a worker, so a
 * plain bool is sufficient. The wire implementation in sub-gate 7.7
 * flips the flag from the same generator that the tool is iterating —
 * no shared-memory hazard.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class CancellationToken
{
    // Private Properties
    // =========================================================================

    /**
     * @var bool Whether `cancel()` has been called. Once flipped, stays
     *           true for the lifetime of the token — there's no
     *           uncancel.
     */
    private bool $_cancelled = false;

    /**
     * @var string|null Optional reason supplied to `cancel()` — e.g.
     *                  `'client disconnected'` when sub-gate 7.7.5's
     *                  HTTP-transport TCP-disconnect path flips the
     *                  token, or a structured reason when an HTTP
     *                  `notifications/cancelled` arrival carries one.
     *                  Stays null when `cancel()` was either never
     *                  called or called without a reason.
     */
    private ?string $_reason = null;

    /**
     * @var (\Closure(): bool)|null Optional poll callback consulted on
     *                              every `isCancelled()` check. Set by
     *                              the streaming dispatcher to read a
     *                              cache-backed cancellation slot
     *                              without subclassing this final class.
     *                              A truthy return flips the token
     *                              permanently — the dispatcher's
     *                              cancellation contract is monotonic.
     *
     *                              Sub-gate 7.7 contract: the streaming
     *                              dispatcher binds the callback at
     *                              token construction; stdio invocations
     *                              never bind one and the callback stays
     *                              null.
     */
    private readonly ?\Closure $_pollCallback;

    // Public Methods
    // =========================================================================

    /**
     * @param (callable(): bool)|null $pollCallback Optional callback
     *                                              consulted on every
     *                                              `isCancelled()` read.
     *                                              When the callback
     *                                              returns true the
     *                                              token flips
     *                                              permanently.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(?callable $pollCallback = null)
    {
        $this->_pollCallback = $pollCallback !== null
            ? \Closure::fromCallable($pollCallback)
            : null;
    }

    /**
     * Whether cancellation has been requested. Streaming tools should
     * check this between yields / between batched units of work so the
     * client's cancellation reaches the running tool promptly.
     *
     * When a poll callback was supplied at construction (sub-gate 7.7
     * streaming dispatcher) the callback is consulted on every read —
     * a truthy return flips the token permanently. This is how the
     * cache-backed `notifications/cancelled` arrival reaches a running
     * generator without the dispatcher needing to subclass this
     * final class.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function isCancelled(): bool
    {
        if ($this->_cancelled) {
            return true;
        }
        if ($this->_pollCallback !== null && ($this->_pollCallback)()) {
            $this->_cancelled = true;
            return true;
        }
        return false;
    }

    /**
     * Signal cancellation. Called by infrastructure code at the
     * boundary where a cancellation arrives — today the HTTP/SSE
     * transport's `notifications/cancelled` cache-slot path (sub-gate
     * 7.7) and the streaming controller's TCP-disconnect path (sub-
     * gate 7.7.5). Tools do not call this themselves; the contract is
     * read-only from a tool's perspective.
     *
     * Idempotent — calling twice is a no-op. The first non-null
     * `$reason` wins; subsequent calls leave the stored reason
     * unchanged so the original cancellation context survives any
     * defensive double-cancel.
     *
     * @param string|null $reason Optional human-readable reason —
     *                            captured for forensic / audit
     *                            surfaces. `'client disconnected'`
     *                            when the streaming controller
     *                            observes `connection_aborted() === 1`
     *                            mid-loop; structured reasons from a
     *                            future `notifications/cancelled` body
     *                            carrying `params.reason`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function cancel(?string $reason = null): void
    {
        if ($this->_cancelled) {
            return;
        }
        $this->_cancelled = true;
        $this->_reason = $reason;
    }

    /**
     * Reason last supplied to `cancel()`, or null when `cancel()` was
     * never called or was called without a reason. Read by audit /
     * envelope surfaces that want to surface why a stream stopped
     * (e.g. `'client disconnected'` vs. an explicit
     * `notifications/cancelled`). Forward-compatible — today the audit
     * row's `kind=cancelled` is the sole disambiguation surface; this
     * accessor opens the door to richer payloads without a contract
     * bump.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getReason(): ?string
    {
        return $this->_reason;
    }
}
