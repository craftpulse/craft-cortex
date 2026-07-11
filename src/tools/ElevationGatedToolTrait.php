<?php

namespace craftpulse\herald\tools;

use craftpulse\herald\mcp\Server;
use craftpulse\herald\tools\support\InvocationContext;

/**
 * =========================================================================
 * Shared WS2 elevation gate for high-stakes tools.
 *
 * A tool that performs high-stakes operations over the HTTP transport
 * (content publish / unpublish, content / element deletes) `use`s this
 * trait to inherit:
 *
 *   - the `ContextAwareToolInterface::setInvocationContext()` storage,
 *     so the dispatcher injects the per-invocation context before
 *     `execute()`, and
 *   - `_assertElevated()`, which throws a `ToolException` naming the
 *     `/oauth/elevate` flow when the request is not elevated.
 *
 * stdio is the trusted local transport and is implicitly elevated
 * (`InvocationContext::$elevated` is true for stdio), so a stdio caller
 * never trips the gate. Over HTTP, elevation is the per-token marker
 * minted by the in-band `/oauth/elevate` fresh-re-auth flow and tracked
 * server-side — never a client claim.
 *
 * Fails closed: when no context was injected (transport indeterminate)
 * the request is treated as un-elevated HTTP and refused.
 *
 * `craft_exec` / any `IsStdioOnly` tool does NOT use this trait — those
 * stay stdio-only ALWAYS, enforced at the transport boundary in
 * `Server::_validateToolCall()`, and are never unlocked by elevation.
 *
 * A tool using this trait MUST declare
 * `implements ContextAwareToolInterface` so the dispatcher injects the
 * context.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
trait ElevationGatedToolTrait
{
    // Private Properties
    // =========================================================================

    /**
     * @var InvocationContext|null Per-invocation context injected by the
     *                             dispatcher immediately before
     *                             `execute()`. Null on call paths that
     *                             bypass the dispatcher injection — those
     *                             are treated as un-elevated (fail closed).
     */
    private ?InvocationContext $_invocationContext = null;

    // Public Methods
    // =========================================================================

    /**
     * Store the per-invocation context the dispatcher injects before
     * `execute()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setInvocationContext(InvocationContext $ctx): void
    {
        $this->_invocationContext = $ctx;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Throw a `ToolException` naming the elevation flow unless the
     * current request is elevated. stdio is implicitly elevated and
     * always passes; HTTP requires the `/oauth/elevate` marker.
     *
     * @param string $operation Short label for the gated operation
     *                          (e.g. "deleting content", "publishing
     *                          content") woven into the error message.
     * @throws ToolException When the request is not elevated.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _assertElevated(string $operation): void
    {
        $ctx = $this->_invocationContext;
        if ($ctx !== null && ($ctx->elevated || $ctx->transport === Server::TRANSPORT_STDIO)) {
            return;
        }

        throw new ToolException(sprintf(
            '%s over the HTTP transport requires elevation. Re-authenticate via the ' .
                '/oauth/elevate flow, then retry. (Over the trusted stdio transport this ' .
                'is always permitted.)',
            ucfirst($operation),
        ));
    }
}
