<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\mcp\Server;
use Throwable;
use yii\console\ExitCode;

/**
 * =========================================================================
 * stdio transport for the MCP server.
 *
 * Reads newline-delimited JSON-RPC requests from STDIN, hands each line
 * to `_handleLine()` (parse → dispatch → encode one response line),
 * writes responses to STDOUT, and exits cleanly when STDIN closes or a
 * termination signal arrives.
 *
 * Threat model: stdio is a trusted-local transport, but a trusted local
 * *user* is not the same as a trusted client *implementation* — the same
 * argument the `craft_exec` docblock makes. The reader therefore bounds
 * each message at `Settings::$stdioMaxMessageBytes` so a hostile or buggy
 * client streaming one unbounded line can't OOM the long-running serve
 * process; oversized lines are drained and rejected with JSON-RPC
 * `-32600` rather than buffered.
 * =========================================================================
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
        $server = new Server(Server::TRANSPORT_STDIO);

        $stdin = STDIN;
        $stdout = STDOUT;

        stream_set_blocking($stdin, true);

        $maxBytes = Cortex::getInstance()->getSettings()->stdioMaxMessageBytes;

        while (($read = $this->_readMessage($stdin, $maxBytes)) !== null) {
            [$line, $oversized] = $read;

            if ($oversized) {
                $this->_writeResponse($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => Server::ERR_INVALID_REQUEST,
                        'message' => sprintf('Invalid Request: message exceeds the %d-byte limit.', $maxBytes),
                    ],
                ]);
                continue;
            }

            $response = $this->_handleLine($server, $line);
            if ($response === null) {
                continue;
            }

            $this->_writeResponse($stdout, $response);
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

        return $server->dispatch($request);
    }

    /**
     * Read one newline-delimited message from STDIN with a hard byte
     * cap. Reassembles in `READ_CHUNK_BYTES` chunks until a newline is
     * seen, returning `[$line, false]` for a normal message.
     *
     * If the accumulated bytes for one line exceed `$maxBytes` before a
     * newline arrives, the rest of the line is drained off the stream
     * (still bounded — only one chunk is held in memory at a time) and
     * `[null, true]` is returned so the caller can emit a single
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
     * Write one JSON-RPC response as a single line on stdout.
     *
     * @param resource $stdout
     * @param array<string,mixed> $response
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _writeResponse($stdout, array $response): void
    {
        $payload = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        fwrite($stdout, $payload . "\n");
        fflush($stdout);
    }
}
