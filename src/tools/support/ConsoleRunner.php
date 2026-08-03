<?php

namespace craftpulse\herald\tools\support;

use Craft;
use RuntimeException;
use Throwable;

/**
 * =========================================================================
 * Run a Craft / Yii console route in-process and capture its output.
 *
 * Used by `craft_command`, `resave`, and `craft_exec` to execute a
 * console action while keeping its stdout/stderr off the JSON-RPC
 * channel that herald/serve owns. The capture is achieved by attaching
 * a write filter to STDOUT and STDERR for the duration of the dispatch
 * (see `StdoutCaptureFilter`). Outside of dispatch, the filter is
 * detached so JSON-RPC output flows normally.
 *
 * No `proc_open`, `shell_exec`, `exec`, `passthru`, `popen`, or
 * backticks — this is the only way commands run.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class ConsoleRunner
{
    // Private Properties
    // =========================================================================

    private static bool $_filterRegistered = false;

    // Public Methods
    // =========================================================================

    /**
     * Run `Craft::$app->runAction($route, $params)` with stdout/stderr
     * captured. Returns `{route, exitCode, output}`. The `output` is
     * cleaned of ANSI colour codes for stable LLM-facing rendering.
     *
     * Exceptions thrown inside the action are caught and surfaced via
     * the `error` field in the return value rather than allowed to
     * abort the MCP request — the LLM gets a structured failure to act
     * on instead of a JSON-RPC internal error envelope. Callers that
     * want to fail hard should check `exitCode !== 0`.
     *
     * @param array<string,mixed> $params
     * @return array{route: string, exitCode: int|null, output: string, error: ?string}
     *
     * @throws RuntimeException if a capture is already in flight — the
     *                          static `StdoutCaptureFilter` buffer is
     *                          single-flight, so a nested run would clear
     *                          the outer dispatch's buffer mid-capture.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function run(string $route, array $params = []): array
    {
        // Single-flight invariant: the filter's static `$capturing` /
        // `$buffer` can hold exactly one capture at a time. Nothing nests
        // today (stdio serves one request at a time), but a future
        // command-running tool that recursed into `runAction()` would
        // silently corrupt the outer capture. Fail loud instead.
        if (StdoutCaptureFilter::$capturing) {
            throw new RuntimeException('ConsoleRunner::run() is not re-entrant: a stdout capture is already in flight.');
        }

        self::_register();

        StdoutCaptureFilter::$buffer = '';
        StdoutCaptureFilter::$capturing = true;

        $stdoutFilter = stream_filter_append(STDOUT, 'herald.capture', STREAM_FILTER_WRITE);
        $stderrFilter = stream_filter_append(STDERR, 'herald.capture', STREAM_FILTER_WRITE);

        $error = null;
        $exitCode = null;
        try {
            $result = Craft::$app->runAction($route, $params);
            $exitCode = is_int($result) ? $result : 0;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $exitCode = 1;
        } finally {
            if ($stdoutFilter !== false) {
                stream_filter_remove($stdoutFilter);
            }
            if ($stderrFilter !== false) {
                stream_filter_remove($stderrFilter);
            }

            $output = StdoutCaptureFilter::$buffer;
            StdoutCaptureFilter::$buffer = '';
            StdoutCaptureFilter::$capturing = false;
        }

        return [
            'route' => $route,
            'exitCode' => $exitCode,
            'output' => self::_stripAnsi($output),
            'error' => $error,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Register the user-stream filter once per process. Idempotent.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private static function _register(): void
    {
        if (self::$_filterRegistered) {
            return;
        }

        stream_filter_register('herald.capture', StdoutCaptureFilter::class);
        self::$_filterRegistered = true;
    }

    /**
     * Strip ANSI colour escape sequences from console output. Both Craft
     * and Yii write coloured progress; the LLM only sees the bytes, so
     * we drop the escape codes for cleaner rendering.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private static function _stripAnsi(string $output): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $output);
    }
}
