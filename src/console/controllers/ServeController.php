<?php

namespace craftpulse\cortex\console\controllers;

use craft\console\Controller;
use craftpulse\cortex\mcp\Server;
use Throwable;
use Yii;
use yii\console\ExitCode;

/**
 * =========================================================================
 * stdio transport for the MCP server.
 *
 * Reads newline-delimited JSON-RPC requests from STDIN, hands them to
 * the transport-agnostic `Server` for dispatch, writes responses to
 * STDOUT, exits cleanly when STDIN closes.
 *
 * Memory hygiene per PLANNING.md section 4.3: logger flush interval set
 * to 1 so log records don't accumulate in memory during long-running
 * sessions; logger levels reduced to error+warning to avoid noisy
 * Craft / Yii output corrupting the JSON-RPC stream on stdout.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class ServeController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Run the MCP server loop on stdin/stdout.
     *
     * @return int Exit code per yii\console\ExitCode
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function actionIndex(): int
    {
        $this->_silenceLogs();

        $server = new Server(Server::TRANSPORT_STDIO);

        $stdin = STDIN;
        $stdout = STDOUT;

        stream_set_blocking($stdin, true);

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $this->_writeResponse($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32700,
                        'message' => 'Parse error: ' . $e->getMessage(),
                    ],
                ]);
                continue;
            }

            if (!is_array($request)) {
                $this->_writeResponse($stdout, [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32600, 'message' => 'Invalid Request: not a JSON object'],
                ]);
                continue;
            }

            $response = $server->dispatch($request);
            if ($response !== null) {
                $this->_writeResponse($stdout, $response);
            }
        }

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Write one JSON-RPC response as a single line on stdout.
     *
     * @param resource $stdout
     * @param array<string,mixed> $response
     *
     * @author Craftpulse
     * @since  0.1.0
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

    /**
     * Keep noise off stdout. Yii and Craft can both write to stdout via
     * the default logger, which would corrupt the JSON-RPC stream. Cap
     * log levels to error+warning and flush eagerly.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _silenceLogs(): void
    {
        if (isset(Yii::$app->log)) {
            foreach (Yii::$app->log->targets as $target) {
                if (method_exists($target, 'setLevels')) {
                    $target->setLevels(['error', 'warning']);
                }
            }
            Yii::$app->log->flushInterval = 1;
        }
    }
}
