<?php

namespace craftpulse\cortex\tools\support;

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
 * The class is intentionally small: a boolean flag plus two methods.
 * Locking the shape from sub-gate 7.1 (per `docs/plans/gate-7.md`) means
 * streaming tools in later gates can opt in cooperatively without an
 * interface break here.
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

    // Public Methods
    // =========================================================================

    /**
     * Whether cancellation has been requested. Streaming tools should
     * check this between yields / between batched units of work so the
     * client's cancellation reaches the running tool promptly.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function isCancelled(): bool
    {
        return $this->_cancelled;
    }

    /**
     * Signal cancellation. Called by the transport on receipt of MCP
     * `notifications/cancelled` (wired in sub-gate 7.7). Tools do not
     * call this themselves; the contract is read-only from a tool's
     * perspective.
     *
     * Idempotent — calling twice is a no-op.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function cancel(): void
    {
        $this->_cancelled = true;
    }
}
