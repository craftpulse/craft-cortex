<?php

/**
 * =========================================================================
 * Tests for `StdoutCaptureFilter` (FIX 2 capture window) and the
 * `ConsoleRunner` re-entrancy guard (FIX 6).
 *
 * The filter is what keeps a dispatched console command's stdout/stderr
 * off the JSON-RPC channel `herald/serve` owns: during a capturing
 * window it buffers writes and suppresses the underlying write; outside
 * the window it passes through. Its static `$capturing` / `$buffer`
 * assume strict single-flight, so a nested `ConsoleRunner::run()` would
 * corrupt the outer capture — the guard fails loud instead.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\tools\support\ConsoleRunner;
use craftpulse\herald\tools\support\StdoutCaptureFilter;

beforeEach(function() {
    stream_filter_register('herald.capture.test', StdoutCaptureFilter::class);
    StdoutCaptureFilter::$buffer = '';
    StdoutCaptureFilter::$capturing = false;
});

afterEach(function() {
    StdoutCaptureFilter::$buffer = '';
    StdoutCaptureFilter::$capturing = false;
});

it('suppresses the underlying write and buffers during a capturing window', function() {
    $stream = fopen('php://temp', 'r+');
    $filter = stream_filter_append($stream, 'herald.capture.test', STREAM_FILTER_WRITE);

    StdoutCaptureFilter::$capturing = true;
    fwrite($stream, 'captured-output');
    fflush($stream);

    stream_filter_remove($filter);
    rewind($stream);
    $underlying = stream_get_contents($stream);
    fclose($stream);

    // Nothing reached the underlying stream; it all landed in the buffer.
    expect($underlying)->toBe('')
        ->and(StdoutCaptureFilter::$buffer)->toBe('captured-output');
});

it('passes through to the underlying stream outside a capturing window', function() {
    $stream = fopen('php://temp', 'r+');
    $filter = stream_filter_append($stream, 'herald.capture.test', STREAM_FILTER_WRITE);

    StdoutCaptureFilter::$capturing = false;
    fwrite($stream, 'normal-output');
    fflush($stream);

    stream_filter_remove($filter);
    rewind($stream);
    $underlying = stream_get_contents($stream);
    fclose($stream);

    expect($underlying)->toBe('normal-output')
        ->and(StdoutCaptureFilter::$buffer)->toBe('');
});

it('throws when ConsoleRunner::run() is entered while a capture is already in flight', function() {
    // Simulate an outer capture window being open.
    StdoutCaptureFilter::$capturing = true;

    ConsoleRunner::run('help');
})->throws(RuntimeException::class, 'not re-entrant');
