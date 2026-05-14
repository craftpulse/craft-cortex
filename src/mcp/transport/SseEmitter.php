<?php

namespace craftpulse\cortex\mcp\transport;

/**
 * =========================================================================
 * Server-Sent Events frame emitter for the MCP Streamable HTTP transport.
 *
 * Handles wire framing only — no MCP semantics. The `Server::
 * dispatchStreaming()` pipeline pre-shapes each envelope (one
 * `notifications/progress` per progress frame, one terminal JSON-RPC
 * response) and hands it to `emit()`; this class writes the SSE
 * `id:`/`event:`/`data:` lines, flushes per frame, and tears down
 * cleanly.
 *
 * The emitter bypasses Yii's `Response` framing because Yii assumes a
 * single fixed body that gets sent once `Response::send()` runs.
 * Streaming requires writing bytes to the wire as soon as each frame
 * is built, so we `header()` / `echo` / `flush()` directly. The
 * controller returns an empty `FORMAT_RAW` Response after the
 * emitter has already written the body; Yii's send-body step is a
 * no-op against an empty `Response::$content`.
 *
 * Wire shape (per HTML living standard "Server-Sent Events"):
 *
 *   id: <uuid>\n
 *   event: message\n
 *   data: <single-line JSON>\n
 *   \n
 *
 * `id:` is emitted for forward-compatibility with the deferred
 * `Last-Event-ID` resumability protocol (Phase 3 / locked decision 14).
 * Today no replay buffer exists — clients that reconnect lose
 * in-flight frames. Emitting the id keeps the wire shape from churning
 * when resumability lands.
 *
 * Headers:
 *   - `Content-Type: text/event-stream` — SSE wire format.
 *   - `Cache-Control: no-cache, no-transform` — no caching of partial
 *     streams; no transcoding (proxies that gzip mid-stream break SSE).
 *   - `X-Accel-Buffering: no` — disable nginx response buffering
 *     (idempotent on Apache / non-nginx fronts).
 *   - `Connection: keep-alive` — clients keep the socket open for
 *     subsequent frames.
 *
 * Output buffering: PHP's default web SAPI runs through one or more
 * output buffers. SSE frames need to hit the wire immediately, so
 * `disableBuffering()` walks every active level and flushes them all,
 * then calls `flush()` to push through the SAPI. Idempotent — safe to
 * call multiple times.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class SseEmitter
{
    // Constants
    // =========================================================================

    public const CONTENT_TYPE_SSE = 'text/event-stream';

    /**
     * Default `event:` token. The MCP spec doesn't reserve a specific
     * event name on the POST-upgrade path — every frame carries one
     * `message` event with the JSON-RPC payload in `data:`. Sticking
     * with `message` matches the spec's example output and lets
     * `EventSource`-style clients (which default to listening for
     * `message`) work without custom event-name handling.
     */
    public const EVENT_NAME = 'message';

    // Private Properties
    // =========================================================================

    /**
     * @var bool Whether `start()` has emitted the headers + flushed
     *           output buffers. Subsequent `emit()` calls assume the
     *           wire is open; this slot guards against accidental
     *           double-start.
     */
    private bool $_started = false;

    /**
     * @var bool Whether `end()` has been called. Subsequent
     *           writes / flushes become no-ops so a tool that yields
     *           after returning doesn't try to push bytes to a closed
     *           socket.
     */
    private bool $_closed = false;

    /**
     * @var (\Closure(string): void)|null Optional writer override.
     *                                    When supplied, frames are
     *                                    pushed through the callable
     *                                    instead of `echo` +
     *                                    `flush()`. Used by tests to
     *                                    capture frames without
     *                                    standing up a real HTTP
     *                                    server — production callers
     *                                    leave this null and the
     *                                    default `echo` path runs.
     */
    private ?\Closure $_writer = null;

    // Public Methods
    // =========================================================================

    /**
     * @param (callable(string): void)|null $writer Optional sink for
     *                                              frame bytes. When
     *                                              null (production)
     *                                              frames go to PHP's
     *                                              output stream via
     *                                              `echo` and `flush()`.
     *                                              When supplied, the
     *                                              callable receives
     *                                              each frame and the
     *                                              emitter skips the
     *                                              buffer-tearing-down
     *                                              `ob_end_flush()`
     *                                              loop — tests can
     *                                              capture frames
     *                                              cleanly under an
     *                                              outer `ob_start`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(?callable $writer = null)
    {
        $this->_writer = $writer !== null ? \Closure::fromCallable($writer) : null;
    }

    /**
     * Open the SSE stream. Sets the canonical headers, disables
     * buffering, and flushes through to the wire so the client sees
     * the response start before the first frame arrives.
     *
     * Idempotent — calling twice is a no-op past the first.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function start(): void
    {
        if ($this->_started) {
            return;
        }
        $this->_started = true;

        // Custom writer paths skip header() + buffer teardown — those
        // are wire-level side effects that only make sense when bytes
        // are heading to the SAPI. Tests using a capturing writer
        // don't want either.
        if ($this->_writer !== null) {
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: ' . self::CONTENT_TYPE_SSE);
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');
        }

        $this->disableBuffering();
    }

    /**
     * Walk every active PHP output buffer and flush it through, then
     * `flush()` to push through the SAPI. Idempotent — safe to call
     * from `start()` and again from each `emit()` in defense-in-depth
     * against late-installed handlers.
     *
     * Suppress the `@` on `ob_end_flush()` because PHP raises a notice
     * when there are no buffers to flush; the loop guard is the
     * authority, not the notice.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function disableBuffering(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }

    /**
     * Emit one SSE frame. Each frame carries an `id:` for forward
     * compatibility with Phase-3 Last-Event-ID resumability, an
     * `event:` token (default `message`), and a `data:` line carrying
     * the JSON-encoded payload.
     *
     * The dispatcher pre-builds the payload (JSON-RPC envelope shape);
     * this method is wire-only. After writing the frame, `flush()`
     * pushes through any remaining SAPI buffer so the client sees the
     * frame immediately.
     *
     * @param string                       $event Event token; default `message`.
     * @param array<int|string,mixed>      $data  Pre-shaped JSON-RPC envelope to emit as the `data:` payload.
     * @param string|null                  $id    Optional frame id. When null, a fresh UUIDv4 is generated.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function emit(string $event, array $data, ?string $id = null): void
    {
        if (!$this->_started || $this->_closed) {
            return;
        }

        // JSON-encode the envelope without pretty-printing — SSE
        // `data:` lines MUST NOT contain embedded newlines unless
        // continued with another `data:` line. Single-line JSON
        // keeps the frame trivially parseable.
        $payload = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $frame = sprintf(
            "id: %s\nevent: %s\ndata: %s\n\n",
            $id ?? $this->_generateFrameId(),
            $event,
            $payload,
        );

        if ($this->_writer !== null) {
            ($this->_writer)($frame);
            return;
        }

        echo $frame;
        flush();
    }

    /**
     * Close the stream. Idempotent — subsequent emits become no-ops.
     * Pushes one final flush through so any buffered tail bytes hit
     * the wire before the caller returns control to Yii.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function end(): void
    {
        if ($this->_closed) {
            return;
        }
        $this->_closed = true;
        if ($this->_writer === null) {
            flush();
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Generate a fresh UUIDv4-shaped frame id. Cryptographically
     * random (`random_bytes(16)`) so the id is globally unique even
     * across concurrent streams. Forward-compatible with the deferred
     * Last-Event-ID resumability protocol — a future resumable
     * implementation can use these ids as cursor keys without
     * changing the wire shape.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _generateFrameId(): string
    {
        $bytes = random_bytes(16);
        // RFC 4122 variant + version bits for UUIDv4.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
