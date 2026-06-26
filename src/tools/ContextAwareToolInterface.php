<?php

namespace craftpulse\cortex\tools;

use craftpulse\cortex\tools\support\InvocationContext;

/**
 * =========================================================================
 * Opt-in contract for non-streaming tools that need the per-invocation
 * context inside `execute()`.
 *
 * `ToolInterface::execute()` receives only the validated arguments — it
 * has no view of the transport, the resolved user, or the request id.
 * `StreamableToolInterface::stream()` gets all of that via its
 * `InvocationContext` parameter, but non-streaming tools have no such
 * seam.
 *
 * A non-streaming tool that must make a transport-dependent decision
 * (e.g. refusing credential mutations over the HTTP transport while
 * allowing them over trusted stdio) implements this interface. The
 * dispatcher injects the same `InvocationContext` it already built for
 * the dispatch — carrying the real `$_transport`, resolved user, and
 * request id — immediately BEFORE calling `execute()`. The tool stores
 * the context and reads `$ctx->transport` from within `execute()`.
 *
 * Keying on the real `InvocationContext::$transport` is mandatory: it is
 * the only authoritative transport signal threaded from the dispatcher.
 * Inferring transport from the absence of a resolved user
 * (`getIdentity() === null`) is a security hole — an HTTP bearer whose
 * user id fails to resolve leaves identity unset, so that proxy would
 * misread an HTTP request as stdio and let a guarded mutation through.
 *
 * Tools that don't implement this interface are unaffected — the
 * dispatcher skips injection and calls `execute()` as before.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
interface ContextAwareToolInterface extends ToolInterface
{
    /**
     * Inject the per-invocation context for the current dispatch. The
     * dispatcher calls this immediately before `execute()`. The tool
     * stores the context and reads transport / user / request id from
     * it inside `execute()`.
     *
     * @param InvocationContext $ctx Per-invocation context — carries transport, resolved user, request id, audit fields.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setInvocationContext(InvocationContext $ctx): void;
}
