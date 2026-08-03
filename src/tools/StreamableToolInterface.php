<?php

namespace craftpulse\herald\tools;

use craftpulse\herald\tools\support\InvocationContext;
use Generator;

/**
 * =========================================================================
 * Opt-in contract for tools that stream progress over the HTTP/SSE
 * transport.
 *
 * Tools that implement this interface gain a streaming entry point
 * (`stream()`) the HTTP dispatcher uses to surface intermediate
 * `notifications/progress` frames to the client between the original
 * `tools/call` request and its terminal JSON-RPC response. Tools that
 * don't implement it are unaffected — the dispatcher's
 * `dispatchStreaming()` collapses non-streaming results into a single
 * SSE frame, identical in shape to the JSON-mode response.
 *
 * Wire shape (per MCP 2025-06-18):
 *
 *   1. Client POSTs `tools/call` with `Accept: text/event-stream` and
 *      a `_meta.progressToken` in the request params.
 *   2. Server returns `Content-Type: text/event-stream`, writes one
 *      `notifications/progress` SSE frame per `stream()` yield, then
 *      one terminal JSON-RPC response frame carrying the tool result.
 *   3. Client may at any point POST a `notifications/cancelled`
 *      JSON-RPC notification referencing the original request id, OR
 *      drop the underlying TCP connection (real client disconnect —
 *      sub-gate 7.7.5). In either case the server flips the
 *      `CancellationToken` on the running generator's
 *      `InvocationContext`; cooperative tools observe
 *      `$ctx->getCancellationToken()->isCancelled()` between yields
 *      and short-circuit.
 *
 * Contract for `stream()`:
 *
 *   - Yields are progress frames. Each yielded value is a
 *     `{progress: number, total?: number, message?: string}` array per
 *     MCP `notifications/progress` semantics. `progress` MUST be
 *     monotonically non-decreasing. The dispatcher wraps each yield in
 *     the `notifications/progress` JSON-RPC envelope with the request's
 *     `progressToken` (when supplied) before emitting on the wire.
 *   - The terminal value is the tool's final result, surfaced via
 *     `Generator::getReturn()` (i.e. `return [...]` after the last
 *     `yield`). The dispatcher wraps it in the standard
 *     `_toolResultEnvelope()` shape and emits as the final SSE frame.
 *   - Cancellation is cooperative. Tools SHOULD check
 *     `$ctx->getCancellationToken()->isCancelled()` between yields and
 *     between batched units of work; on a flipped flag they SHOULD
 *     return early with whatever partial state is appropriate. The
 *     dispatcher does NOT forcibly halt the generator — long-running
 *     CPU-bound code that ignores the token cannot be cancelled.
 *
 * Tools that don't implement this interface continue to work over the
 * HTTP transport — they collapse to a single one-frame SSE response
 * when the client requests `Accept: text/event-stream`, identical in
 * payload to the JSON-mode response. The MCP spec explicitly permits
 * single-frame SSE for non-streaming tools.
 *
 * Locked decisions:
 *   - SSE cancellation contract was set in 7.1 (`CancellationToken` on
 *     `InvocationContext`); wire implementation lands in 7.7.
 *   - `Last-Event-ID` resumability deferred to Phase 3 (locked
 *     decision 14). Each SSE frame still carries a fresh id so the
 *     wire is forward-compatible.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
interface StreamableToolInterface extends ToolInterface
{
    /**
     * Streaming entry point. The HTTP dispatcher calls this when the
     * tool implements `StreamableToolInterface` AND the client requested
     * an SSE response (`Accept: text/event-stream`). The dispatcher
     * forwards each yielded progress frame as a
     * `notifications/progress` JSON-RPC envelope; the generator's
     * return value becomes the terminal `tools/call` response.
     *
     * **Cancellation forwarding:** sub-generators delegated via
     * `yield from` do NOT automatically inherit the cancellation-token
     * check from the parent generator. PHP generator semantics hand
     * control to the sub-generator until it completes or returns; the
     * parent's poll loop between yields never runs during that window.
     * A tool that uses `yield from $someSubGenerator()` and lets the
     * sub-generator do significant work without checking the token will
     * be unresponsive to mid-stream cancellation for the duration of
     * that delegation.
     *
     * To keep a sub-generator cancellable, either:
     *   - Pass the full `InvocationContext` (or at minimum its
     *     `CancellationToken`) to the sub-generator as an argument and
     *     check `$ctx->getCancellationToken()->isCancelled()` inside it
     *     between yields; or
     *   - Break the delegation into explicit yield points in the parent
     *     generator rather than using `yield from`.
     *
     * This is a property of PHP's generator semantics, not a herald
     * limitation — the same constraint applies to any PHP generator
     * that delegates via `yield from`.
     *
     * @param array<string,mixed> $arguments Validated against `getInputSchema()` upstream.
     * @param InvocationContext   $ctx       Per-invocation context — carries the cancellation token, transport, user, audit fields.
     * @return Generator<int,array<string,mixed>,mixed,array<int|string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator;
}
