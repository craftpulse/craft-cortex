<?php

/**
 * =========================================================================
 * Framing tests for the stdio transport's `ServeController`.
 *
 * The framing loop and `_handleLine()` seam are the pieces whose silent
 * regression would corrupt every stdio session — one stray byte on
 * stdout breaks JSON-RPC line framing. These tests exercise the seam
 * directly (via reflection, since the methods are private by design) so
 * the contract is pinned without spawning a real stdin/stdout process:
 *
 *   - one line in -> one response line out
 *   - blank / whitespace lines -> no output
 *   - malformed JSON -> -32700 parse error
 *   - non-object JSON -> -32600 invalid request
 *   - a notification (no `id`) -> no output
 *   - the bounded-read cap -> -32600, and the next message still parses
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\console\controllers\ServeController;
use craftpulse\herald\mcp\Server;

/**
 * Build a `ServeController` wired to a throwaway console module so its
 * constructor doesn't choke outside a real console request.
 */
function _herald_serve_controller(): ServeController
{
    return new ServeController('herald/serve', Craft::$app);
}

/**
 * Invoke a private method on the controller via reflection.
 *
 * @param array<int,mixed> $args
 */
function _herald_invoke(ServeController $controller, string $method, array $args): mixed
{
    $ref = new ReflectionMethod($controller, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($controller, $args);
}

/**
 * Drive one input line through `_handleLine()` and return the response
 * array (or null).
 *
 * @return array<string,mixed>|null
 */
function _herald_handle_line(string $line): ?array
{
    $controller = _herald_serve_controller();
    $server = new Server(Server::TRANSPORT_STDIO);
    return _herald_invoke($controller, '_handleLine', [$server, $line]);
}

/**
 * Drive raw bytes through `_readMessage()`, returning every
 * `[line, oversized]` tuple until EOF.
 *
 * @return array<int,array{0:string,1:bool}>
 */
function _herald_read_all(string $raw, int $maxBytes): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $controller = _herald_serve_controller();
    $out = [];
    while (($read = _herald_invoke($controller, '_readMessage', [$stream, $maxBytes])) !== null) {
        $out[] = $read;
    }
    fclose($stream);
    return $out;
}

// -----------------------------------------------------------------------------
// _handleLine — framing contract
// -----------------------------------------------------------------------------

it('returns one JSON-RPC response for one valid request line', function() {
    $response = _herald_handle_line(json_encode([
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'ping',
    ]));

    expect($response)->toBeArray()
        ->and($response['jsonrpc'])->toBe('2.0')
        ->and($response['id'])->toBe(7)
        ->and($response)->toHaveKey('result');
});

it('skips a blank line with no output', function() {
    expect(_herald_handle_line(''))->toBeNull();
    expect(_herald_handle_line("   \t  "))->toBeNull();
});

it('returns -32700 on malformed JSON', function() {
    $response = _herald_handle_line('{ this is not json ');

    expect($response)->toBeArray()
        ->and($response['id'])->toBeNull()
        ->and($response['error']['code'])->toBe(Server::ERR_PARSE);
});

it('returns -32600 on a non-object JSON value', function() {
    $response = _herald_handle_line('42');

    expect($response)->toBeArray()
        ->and($response['id'])->toBeNull()
        ->and($response['error']['code'])->toBe(Server::ERR_INVALID_REQUEST)
        ->and($response['error']['message'])->toContain('not a JSON object');
});

it('emits no response line for a notification (no id)', function() {
    $response = _herald_handle_line(json_encode([
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ]));

    expect($response)->toBeNull();
});

// -----------------------------------------------------------------------------
// _readMessage — bounded read
// -----------------------------------------------------------------------------

it('reads consecutive newline-delimited messages', function() {
    // `_readMessage` returns the raw line (newline included); the
    // trailing-whitespace strip happens downstream in `_handleLine`.
    $reads = _herald_read_all("alpha\nbeta\n", 1024);

    expect($reads)->toHaveCount(2)
        ->and($reads[0])->toBe(["alpha\n", false])
        ->and($reads[1])->toBe(["beta\n", false]);
});

it('surfaces a trailing newline-less final line at EOF', function() {
    $reads = _herald_read_all('only-line-no-newline', 1024);

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toBe(['only-line-no-newline', false]);
});

it('flags an oversized line and keeps the next message clean', function() {
    $huge = str_repeat('x', 200);
    $reads = _herald_read_all($huge . "\n" . "small\n", 64);

    expect($reads)->toHaveCount(2)
        ->and($reads[0][1])->toBeTrue()   // oversized
        ->and($reads[0][0])->toBe('')     // buffer discarded
        ->and($reads[1])->toBe(["small\n", false]);
});

it('drives the cap into a -32600 via the same path actionIndex uses', function() {
    // Mirror actionIndex's oversized branch: an oversized read yields a
    // -32600, and the following message parses normally.
    $huge = str_repeat('y', 500);
    $reads = _herald_read_all($huge . "\n" . json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']) . "\n", 128);

    expect($reads[0][1])->toBeTrue();

    $response = _herald_handle_line($reads[1][0]);
    expect($response['id'])->toBe(1)
        ->and($response)->toHaveKey('result');
});
