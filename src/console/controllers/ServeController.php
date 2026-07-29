<?php

namespace craftpulse\herald\console\controllers;

use craft\console\Controller;
use craft\log\MonologTarget;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use Monolog\Handler\StreamHandler;
use Throwable;
use Yii;
use yii\console\ExitCode;

/**
 * stdio transport for the MCP server.
 *
 * Reads newline-delimited JSON-RPC requests from STDIN, hands each line
 * to `_handleLine()` (parse → dispatch → encode one response line),
 * writes responses to STDOUT, and exits cleanly when STDIN closes or a
 * termination signal arrives.
 *
 * Threat model: stdio is a trusted-local transport, but a trusted local
 * *user* is not the same as a trusted client *implementation*, and the same
 * argument the `craft_exec` docblock makes. The reader therefore bounds
 * each message at `Settings::$stdioMaxMessageBytes` so a hostile or buggy
 * client streaming one unbounded line can't OOM the long-running serve
 * process; oversized lines are drained and rejected with JSON-RPC
 * `-32600` rather than buffered.
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class ServeController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * @var int Length cap (in bytes) passed to each `fgets()` call while
     *          reassembling one newline-delimited message. `fgets()`
     *          stops at this many bytes OR a newline, whichever comes
     *          first, so a single read never pulls more than this into
     *          memory. Independent of the per-message cap — purely the
     *          granularity of the read loop.
     */
    private const READ_CHUNK_BYTES = 8192;

    // Private Properties
    // =========================================================================

    /**
     * @var int|string|null JSON-RPC id of the request currently being
     *                      dispatched, or null when the loop is idle
     *                      (between messages) or handling a request with
     *                      no id. Set immediately before
     *                      `Server::dispatch()` and cleared immediately
     *                      after, so the fatal-error shutdown handler can
     *                      correlate a `-32603` envelope back to the call
     *                      that crashed the process.
     */
    private int|string|null $_inFlightRequestId = null;

    /**
     * @var bool Whether a request is currently mid-dispatch. The
     *           shutdown handler only emits an error envelope when this
     *           is `true` — a fatal raised between messages (or after
     *           clean EOF) leaves it `false` and the handler is a no-op.
     */
    private bool $_inFlight = false;

    /**
     * @var bool Whether the shutdown handler has already written its
     *           terminal envelope. Guards against any double-emit if the
     *           handler runs more than once.
     */
    private bool $_shutdownEmitted = false;

    /**
     * @var bool Set by a SIGTERM / SIGINT handler so the read loop
     *           breaks cleanly at the next iteration boundary. MCP
     *           clients tear the server down with SIGTERM rather than
     *           closing STDIN, so without this the loop would only exit
     *           on EOF.
     */
    private bool $_shouldStop = false;

    // Public Methods
    // =========================================================================

    /**
     * Run the MCP server loop on stdin/stdout.
     *
     * @return int Exit code per yii\console\ExitCode
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionIndex(): int
    {
        $this->_redirectStdoutLogHandlers();

        $server = new Server(Server::TRANSPORT_STDIO);

        $stdin = STDIN;
        $stdout = STDOUT;

        $this->_registerFatalHandler($stdout);
        $this->_registerSignalHandlers();

        stream_set_blocking($stdin, true);

        $maxBytes = Herald::getInstance()->getSettings()->getStdioMaxMessageBytes();

        while (!$this->_shouldStop && ($read = $this->_readMessage($stdin, $maxBytes)) !== null) {
            [$line, $oversized] = $read;

            if ($oversized) {
                if (!$this->_writeResponse($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => Server::ERR_INVALID_REQUEST,
                        'message' => sprintf('Invalid Request: message exceeds the %d-byte limit.', $maxBytes),
                    ],
                ])) {
                    break;
                }
                continue;
            }

            $response = $this->_handleLine($server, $line);
            if ($response === null) {
                continue;
            }

            // A failed write means the client closed the read end of the
            // pipe — stop rather than spin on a broken descriptor.
            if (!$this->_writeResponse($stdout, $response)) {
                break;
            }
        }

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Handle one raw input line: trim, parse, dispatch, and encode a
     * single JSON-RPC response array — or `null` when nothing should be
     * written (blank line, or a notification the dispatcher answered
     * with no reply). The read/write loop stays thin around this seam so
     * the framing contract is unit-testable without stdin/stdout.
     *
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handleLine(Server $server, string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => Server::ERR_PARSE,
                    'message' => 'Parse error: ' . $e->getMessage(),
                ],
            ];
        }

        if (!is_array($request)) {
            return [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => Server::ERR_INVALID_REQUEST, 'message' => 'Invalid Request: not a JSON object'],
            ];
        }

        // Mark the request in-flight so the fatal-error shutdown handler
        // can emit a -32603 carrying this id if PHP dies inside dispatch.
        $rawId = $request['id'] ?? null;
        $this->_inFlightRequestId = (is_int($rawId) || is_string($rawId)) ? $rawId : null;
        $this->_inFlight = true;
        try {
            return $server->dispatch($request);
        } finally {
            $this->_inFlight = false;
            $this->_inFlightRequestId = null;
        }
    }

    /**
     * Read one newline-delimited message from STDIN with a hard byte
     * cap. Reassembles in `READ_CHUNK_BYTES` chunks until a newline is
     * seen, returning `[$line, false]` for a normal message.
     *
     * If the accumulated bytes for one line exceed `$maxBytes` before a
     * newline arrives, the rest of the line is drained off the stream
     * (still bounded — only one chunk is held in memory at a time) and
     * `['', true]` is returned so the caller can emit a single
     * `-32600` and continue with the next message. Returns `null` on
     * EOF (clean STDIN close).
     *
     * @param resource $stdin
     * @return array{0: string, 1: bool}|null `[line, oversized]` (line
     *                                         is empty when oversized),
     *                                         or null at EOF
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _readMessage($stdin, int $maxBytes): ?array
    {
        $buffer = '';
        $oversized = false;

        while (true) {
            $chunk = fgets($stdin, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                // EOF. If we already buffered a partial (newline-less)
                // final line, surface it; otherwise the stream is done.
                if ($buffer === '' && !$oversized) {
                    return null;
                }
                return [$buffer, $oversized];
            }

            if (!$oversized) {
                $buffer .= $chunk;
                if (strlen($buffer) > $maxBytes) {
                    // Stop accumulating — drain the remainder of the line
                    // chunk-by-chunk so the next message starts clean,
                    // but never hold more than one chunk past the cap.
                    $oversized = true;
                    $buffer = '';
                }
            }

            if (str_ends_with($chunk, "\n")) {
                return [$buffer, $oversized];
            }
        }
    }

    /**
     * Write one JSON-RPC response as a single line on stdout. Returns
     * `false` when the write fails — `fwrite()` returns `false` once the
     * client closes the read end of the pipe, and the caller breaks the
     * loop rather than spinning on a broken descriptor. A response that
     * can't even be JSON-encoded is treated as a successful no-op (there
     * is no envelope to send), so `true` is returned.
     *
     * @param resource $stdout
     * @param array<string,mixed> $response
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _writeResponse($stdout, array $response): bool
    {
        $payload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return true;
        }

        if (fwrite($stdout, $payload . "\n") === false) {
            return false;
        }
        fflush($stdout);
        return true;
    }

    /**
     * Register a `register_shutdown_function` that, on a true PHP fatal
     * (`E_ERROR` and friends) raised while a request was mid-dispatch,
     * writes one final JSON-RPC `-32603` envelope to stdout so the
     * client gets a terminal response instead of hanging on a dead
     * pipe. Clean shutdowns (no fatal, or no in-flight request) are a
     * no-op, and the `$_shutdownEmitted` guard prevents a double-emit.
     *
     * The shutdown function bypasses every try/catch in the dispatch
     * loop — that's the whole point: a memory-exhaustion or other true
     * fatal never reaches `_internalError()` in the `Server`, so this is
     * the only place a terminal envelope can still be produced.
     *
     * @param resource $stdout
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerFatalHandler($stdout): void
    {
        register_shutdown_function(function() use ($stdout): void {
            $this->_handleFatalShutdown($stdout, error_get_last());
        });
    }

    /**
     * Install SIGTERM / SIGINT handlers that flip `$_shouldStop` so the
     * read loop exits cleanly at the next boundary. MCP clients tear the
     * server down with SIGTERM rather than closing STDIN, so without this
     * the process would only exit on EOF.
     *
     * Guarded behind `pcntl_async_signals()` — non-pcntl SAPIs (the
     * `pcntl` extension is CLI-only and may be absent) degrade
     * gracefully: the server still exits on EOF and on a broken pipe,
     * just not on a signal.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function(): void {
            $this->_shouldStop = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /**
     * Shutdown-time body, split out so the fatal-envelope decision is
     * unit-testable without actually crashing PHP. Emits a `-32603`
     * (carrying the in-flight request id) only when (a) the last error
     * is a fatal type, (b) a request was in flight, and (c) nothing was
     * emitted yet.
     *
     * @param resource $stdout
     * @param array{type:int,message:string,file:string,line:int}|null $lastError
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handleFatalShutdown($stdout, ?array $lastError): void
    {
        if ($this->_shutdownEmitted || !$this->_inFlight) {
            return;
        }
        if ($lastError === null || !$this->_isFatalErrorType($lastError['type'])) {
            return;
        }

        $this->_shutdownEmitted = true;
        $this->_writeResponse($stdout, [
            'jsonrpc' => '2.0',
            'id' => $this->_inFlightRequestId,
            'error' => [
                'code' => Server::ERR_INTERNAL,
                'message' => 'Internal error: the server terminated while handling this request.',
            ],
        ]);
    }

    /**
     * Whether a PHP error type is one of the unrecoverable fatals that
     * bypass userland try/catch and trigger the shutdown function.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _isFatalErrorType(int $type): bool
    {
        return in_array(
            $type,
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
            true,
        );
    }

    /**
     * Keep Craft / Yii log output off the JSON-RPC channel.
     *
     * The stdio transport owns STDOUT — every byte on it must be a
     * JSON-RPC frame. When `CRAFT_STREAM_LOG` is on (common in
     * containerized / Cloud deployments), Craft's `MonologTarget` pushes
     * a `StreamHandler` writing to `php://stdout`
     * (`MonologTarget::_createDefaultLogger()`), so any error / warning
     * log record emitted mid-dispatch would corrupt the channel.
     *
     * We walk each `MonologTarget`'s Monolog logger and, for every
     * handler whose stream URL resolves to stdout, swap in an equivalent
     * `StreamHandler` pointed at `php://stderr` — preserving the level
     * and bubble of the original. Uses only Monolog's public handler API
     * (`getHandlers()` / `setHandlers()`); `MonologTarget::setLogger()`
     * throws by design, so the logger itself is never re-assigned.
     *
     * No-op in the default file-logging mode — handlers are
     * `RotatingFileHandler`, never stdout streams, so nothing matches.
     * One-way for the process lifetime: the serve process is dedicated
     * and exits when STDIN closes, so there is no meaningful "restore".
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _redirectStdoutLogHandlers(): void
    {
        if (!isset(Yii::$app->log)) {
            return;
        }

        foreach (Yii::$app->log->targets as $target) {
            if (!$target instanceof MonologTarget) {
                continue;
            }

            $logger = $target->getLogger();
            $handlers = $logger->getHandlers();
            $changed = false;

            foreach ($handlers as $i => $handler) {
                if (!$handler instanceof StreamHandler) {
                    continue;
                }
                if (!$this->_isStdoutUrl($handler->getUrl())) {
                    continue;
                }

                $replacement = new StreamHandler(
                    'php://stderr',
                    $handler->getLevel(),
                    $handler->getBubble(),
                );
                $replacement->setFormatter($handler->getFormatter());
                $handlers[$i] = $replacement;
                $changed = true;
            }

            if ($changed) {
                $logger->setHandlers(array_values($handlers));
            }
        }
    }

    /**
     * Whether a Monolog stream URL points at the process's stdout. Both
     * the `php://stdout` wrapper and the bare `php://output` alias count;
     * the file-descriptor form `php://fd/1` is matched too for the rare
     * config that uses it.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _isStdoutUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        $url = strtolower($url);
        return in_array($url, ['php://stdout', 'php://output', 'php://fd/1'], true);
    }
}
