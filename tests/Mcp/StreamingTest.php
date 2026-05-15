<?php

/**
 * =========================================================================
 * Sub-gate 7.7 streaming tests — verify `Server::dispatchStreaming()`
 * against the locked SSE contract:
 *
 *   - `StreamableToolInterface` implementers yield N progress frames
 *     plus one terminal `tools/call` response envelope.
 *   - Non-streamable tools collapse to a single one-frame SSE response
 *     identical in shape to the JSON-mode reply.
 *   - `notifications/cancelled` arriving mid-stream flips the
 *     `CancellationToken` and the running tool short-circuits with a
 *     `notifications/cancelled` terminal envelope.
 *   - Exactly one `cortex_invocations` row per stream completion (not
 *     per frame). Cancellation surfaces as a `kind=cancelled` row.
 *
 * The fixture `_streaming_test` lives in
 * `tests/Tools/Fixtures/StreamingFixtureTool.php` and registers per-test
 * via the existing `EVENT_REGISTER_TOOLS` event listener pattern so it
 * doesn't pollute the production registry.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\services\Tools;
use craftpulse\cortex\tests\Tools\Fixtures\StreamingFixtureTool;
use craftpulse\cortex\tools\support\InvocationLogger;
use yii\base\Event;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Swap the plugin's `tools` service for a freshly-built one with the
 * streaming fixture registered. Returns the original service so the
 * caller can restore it in a `finally` block.
 */
function _cortex_streaming_register_fixture(): array
{
    $listener = static function(RegisterToolsEvent $event): void {
        $event->tools[] = new StreamingFixtureTool();
    };
    Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);

    $original = Cortex::getInstance()->tools;
    $fresh = new Tools();
    $fresh->init();
    Cortex::getInstance()->set('tools', $fresh);

    return [$original, $listener];
}

/**
 * Restore the plugin's tools service and detach the per-test listener.
 */
function _cortex_streaming_restore_fixture(array $context): void
{
    [$original, $listener] = $context;
    Cortex::getInstance()->set('tools', $original);
    Event::off(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);
}

/**
 * Drain a `Generator` into an array. Pest's `expect(...)->toHave...`
 * surface can then assert against the materialised list without
 * special-casing generator iteration.
 *
 * @return array<int,array<string,mixed>>
 */
function _cortex_drain_streaming(Generator $gen): array
{
    return iterator_to_array($gen, preserve_keys: false);
}

// -----------------------------------------------------------------------------
// A streaming tool yields progress frames + final result
// -----------------------------------------------------------------------------

it('a streamable tool yields N notifications/progress frames + one terminal tools/call response', function() {
    $ctx = _cortex_streaming_register_fixture();

    try {
        $server = new Server(Server::TRANSPORT_HTTP);
        $server->setSessionId('sess-7.7-success');
        $frames = _cortex_drain_streaming($server->dispatchStreaming([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => '_streaming_test',
                'arguments' => [],
                '_meta' => ['progressToken' => 'prog-success'],
            ],
        ]));

        // Three progress frames + one terminal response.
        expect($frames)->toHaveCount(4);

        // Frames 0..2: notifications/progress, progress 1..3, total 3.
        for ($i = 0; $i < 3; $i++) {
            expect($frames[$i])
                ->toBeArray()
                ->toHaveKey('jsonrpc', '2.0')
                ->toHaveKey('method', 'notifications/progress')
                ->and($frames[$i]['params']['progressToken'])->toBe('prog-success')
                ->and($frames[$i]['params']['progress'])->toBe($i + 1)
                ->and($frames[$i]['params']['total'])->toBe(3);
        }

        // Final frame: the terminal tools/call response.
        $terminal = $frames[3];
        expect($terminal)
            ->toHaveKey('jsonrpc', '2.0')
            ->toHaveKey('id', 1)
            ->toHaveKey('result');
        expect($terminal['result'])->toHaveKey('isError', false);
        expect($terminal['result']['content'][0]['text'])
            ->toContain('"done": true')
            ->toContain('"progress": 3');
    } finally {
        _cortex_streaming_restore_fixture($ctx);
    }
});

// -----------------------------------------------------------------------------
// A non-streaming tool collapses to a single one-frame SSE
// -----------------------------------------------------------------------------

it('a non-streamable tool dispatched via dispatchStreaming() collapses to a single one-frame SSE', function() {
    // `system_info` is a production tool that doesn't implement
    // StreamableToolInterface. The streaming dispatcher must surface
    // exactly one envelope — the same JSON-RPC response the JSON-mode
    // dispatch would have produced.
    $server = new Server(Server::TRANSPORT_HTTP);
    $server->setSessionId('sess-7.7-degrade');
    $frames = _cortex_drain_streaming($server->dispatchStreaming([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'system_info'],
    ]));

    expect($frames)->toHaveCount(1);
    expect($frames[0])
        ->toHaveKey('jsonrpc', '2.0')
        ->toHaveKey('id', 1)
        ->toHaveKey('result');
    expect($frames[0]['result'])->toHaveKey('isError', false);
});

// -----------------------------------------------------------------------------
// notifications/cancelled mid-stream flips the token and short-circuits
// -----------------------------------------------------------------------------

it('notifications/cancelled mid-stream flips the token and the tool short-circuits with a cancelled envelope', function() {
    $ctx = _cortex_streaming_register_fixture();

    try {
        $sessionId = 'sess-7.7-cancel';
        $requestId = 42;

        // Pre-write the cancellation slot so the streaming token's
        // poll-callback observes the flip on its first read. This
        // models the wire-level race where the client posts
        // notifications/cancelled before the streaming response has
        // sent its second progress frame — the cache slot is the
        // sole synchronisation surface between the two requests.
        $cancelKey = Server::CANCEL_CACHE_KEY_PREFIX . $sessionId . ':' . $requestId;
        Craft::$app->getCache()->set($cancelKey, true, Server::CANCEL_CACHE_TTL);

        $server = new Server(Server::TRANSPORT_HTTP);
        $server->setSessionId($sessionId);
        $frames = _cortex_drain_streaming($server->dispatchStreaming([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'tools/call',
            'params' => [
                'name' => '_streaming_test',
                'arguments' => [],
                '_meta' => ['progressToken' => 'prog-cancel'],
            ],
        ]));

        // The fixture checks `isCancelled()` BEFORE its first yield —
        // the cache slot is pre-armed, so the very first check trips.
        // The terminal frame is the cancelled envelope.
        expect($frames)->toHaveCount(1);
        $cancelled = $frames[0];
        expect($cancelled)
            ->toHaveKey('jsonrpc', '2.0')
            ->toHaveKey('method', 'notifications/cancelled')
            ->and($cancelled['params']['requestId'])->toBe($requestId)
            ->and($cancelled['params']['progressToken'])->toBe('prog-cancel');

        // Cleanup the cache slot so it doesn't leak into other tests.
        Craft::$app->getCache()->delete($cancelKey);
    } finally {
        _cortex_streaming_restore_fixture($ctx);
    }
});

it('notifications/cancelled with a matching requestId arrives via dispatch() and flips the cache slot', function() {
    // Direct test of the dispatch() side of the cancellation
    // contract: a notifications/cancelled MCP notification flows
    // through dispatch() and writes the cache slot the streaming
    // dispatcher reads. No streaming generator running here — this
    // verifies the writer side of the contract in isolation.
    $sessionId = 'sess-7.7-notify';
    $requestId = 'req-99';

    $server = new Server(Server::TRANSPORT_HTTP);
    $server->setSessionId($sessionId);

    // Pre-cleanup: nothing in the slot before dispatch fires.
    $cancelKey = Server::CANCEL_CACHE_KEY_PREFIX . $sessionId . ':' . $requestId;
    Craft::$app->getCache()->delete($cancelKey);
    expect(Craft::$app->getCache()->get($cancelKey))->toBeFalse();

    $response = $server->dispatch([
        'jsonrpc' => '2.0',
        'method' => 'notifications/cancelled',
        'params' => ['requestId' => $requestId, 'reason' => 'user requested'],
    ]);

    // Notifications never get a response per JSON-RPC 2.0.
    expect($response)->toBeNull();

    // The cache slot is now armed.
    expect(Craft::$app->getCache()->get($cancelKey))->toBeTrue();

    Craft::$app->getCache()->delete($cancelKey);
});

// -----------------------------------------------------------------------------
// One audit row per stream completion (not per frame)
// -----------------------------------------------------------------------------

it('a streamed tools/call writes exactly one cortex_invocations row with kind=success', function() {
    $ctx = _cortex_streaming_register_fixture();

    try {
        $sessionId = 'sess-7.7-audit-success';

        // Defense against cross-run pollution: drop any stale rows
        // matching this test's deterministic session id.
        \craftpulse\cortex\records\Invocation::deleteAll(['sessionId' => $sessionId]);

        $server = new Server(Server::TRANSPORT_HTTP);
        $server->setUserId(1);
        $server->setSessionId($sessionId);
        $frames = _cortex_drain_streaming($server->dispatchStreaming([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => '_streaming_test',
                'arguments' => [],
                '_meta' => ['progressToken' => 'prog-audit'],
            ],
        ]));

        expect($frames)->toHaveCount(4);

        $rows = \craftpulse\cortex\records\Invocation::find()
            ->where(['toolName' => '_streaming_test', 'sessionId' => $sessionId])
            ->all();

        // Exactly one row — three progress yields + final response =
        // one stream completion = one audit row. NOT four rows.
        expect($rows)->toHaveCount(1);
        expect($rows[0]->kind)->toBe(InvocationLogger::KIND_SUCCESS);
        expect($rows[0]->durationMs)->toBeGreaterThanOrEqual(0);
        expect($rows[0]->transport)->toBe('http');

        \craftpulse\cortex\records\Invocation::deleteAll(['id' => $rows[0]->id]);
    } finally {
        _cortex_streaming_restore_fixture($ctx);
    }
});

it('a cancelled stream writes exactly one cortex_invocations row with kind=cancelled', function() {
    $ctx = _cortex_streaming_register_fixture();

    try {
        $sessionId = 'sess-7.7-audit-cancel';
        $requestId = 7;

        // Defense against cross-run pollution: drop any stale rows
        // matching this test's deterministic session id before
        // asserting.
        \craftpulse\cortex\records\Invocation::deleteAll(['sessionId' => $sessionId]);

        // Pre-arm the cancellation slot.
        $cancelKey = Server::CANCEL_CACHE_KEY_PREFIX . $sessionId . ':' . $requestId;
        Craft::$app->getCache()->set($cancelKey, true, Server::CANCEL_CACHE_TTL);

        $server = new Server(Server::TRANSPORT_HTTP);
        $server->setUserId(1);
        $server->setSessionId($sessionId);
        iterator_to_array($server->dispatchStreaming([
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'tools/call',
            'params' => [
                'name' => '_streaming_test',
                'arguments' => [],
            ],
        ]), preserve_keys: false);

        $rows = \craftpulse\cortex\records\Invocation::find()
            ->where(['toolName' => '_streaming_test', 'sessionId' => $sessionId])
            ->all();

        expect($rows)->toHaveCount(1);
        expect($rows[0]->kind)->toBe(InvocationLogger::KIND_CANCELLED);
        // Cancellation isn't an error — error_class / error_message
        // stay null. The audit dashboard distinguishes cancellation
        // from tool error via the `kind` enum, not via error
        // payloads.
        expect($rows[0]->errorClass)->toBeNull();
        expect($rows[0]->errorMessage)->toBeNull();

        \craftpulse\cortex\records\Invocation::deleteAll(['id' => $rows[0]->id]);
        Craft::$app->getCache()->delete($cancelKey);
    } finally {
        _cortex_streaming_restore_fixture($ctx);
    }
});
