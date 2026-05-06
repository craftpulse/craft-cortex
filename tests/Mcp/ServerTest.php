<?php

/**
 * =========================================================================
 * Protocol-level tests for the JSON-RPC dispatcher. Cover what the
 * MCP wire format requires the server to do, regardless of which
 * tools happen to be registered.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\Plugin;

beforeEach(function () {
    $this->server = new Server();
});

// -----------------------------------------------------------------------------
// initialize
// -----------------------------------------------------------------------------

it('responds to initialize with the pinned protocol version + serverInfo', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'pest', 'version' => '0'],
        ],
    ]);

    expect($response)
        ->toHaveKeys(['jsonrpc', 'id', 'result'])
        ->and($response['jsonrpc'])->toBe('2.0')
        ->and($response['id'])->toBe(1)
        ->and($response['result']['protocolVersion'])->toBe(Server::PROTOCOL_VERSION)
        ->and($response['result']['serverInfo']['name'])->toBe(Server::SERVER_NAME)
        ->and($response['result']['serverInfo']['version'])->toBe(Server::SERVER_VERSION)
        ->and($response['result']['capabilities'])->toHaveKeys(['tools', 'resources', 'prompts']);
});

// -----------------------------------------------------------------------------
// notifications
// -----------------------------------------------------------------------------

it('returns null for any notification (no id field)', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ]);

    expect($response)->toBeNull();
});

// -----------------------------------------------------------------------------
// tools/list
// -----------------------------------------------------------------------------

it('returns the registry as tools/list', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]);

    expect($response['result'])->toHaveKey('tools');
    expect($response['result']['tools'])->toHaveCount(Plugin::getInstance()->tools->getCount());

    foreach ($response['result']['tools'] as $tool) {
        expect($tool)->toBeMcpToolListItem();
    }
});

// -----------------------------------------------------------------------------
// tools/call
// -----------------------------------------------------------------------------

it('wraps tool output in an MCP success envelope on tools/call', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'sections',
            'arguments' => ['count' => true],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpSuccessEnvelope();

    $unwrapped = cortex_unwrap($response['result']);
    expect($unwrapped)->toHaveKey('count');
});

it('returns an isError envelope when a tool throws ToolException', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => [
            'name' => 'sections',
            'arguments' => ['handle' => '__cortex_no_such_section__'],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toBeMcpErrorEnvelope();
    expect($response['result']['content'][0]['text'])
        ->toContain('__cortex_no_such_section__');
});

// -----------------------------------------------------------------------------
// Protocol-level errors
// -----------------------------------------------------------------------------

it('returns JSON-RPC -32602 for an unknown tool name', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 5,
        'method' => 'tools/call',
        'params' => ['name' => 'no_such_tool'],
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32602);
    expect($response['error']['message'])->toContain('Unknown tool');
});

it('returns JSON-RPC -32602 when tools/call is missing the name param', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(-32602);
});

it('returns JSON-RPC -32602 when tools/call arguments is not an object', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 7,
        'method' => 'tools/call',
        'params' => [
            'name' => 'sections',
            'arguments' => 'not an array',
        ],
    ]);

    expect($response['error']['code'])->toBe(-32602);
});

it('returns JSON-RPC -32601 for an unknown method', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'method/that/does/not/exist',
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32601);
});

// -----------------------------------------------------------------------------
// stdio-only enforcement (Gate 5 of craft_exec)
// -----------------------------------------------------------------------------

it('rejects a stdio-only tool when dispatched on the HTTP transport', function () {
    $httpServer = new Server(Server::TRANSPORT_HTTP);

    $response = $httpServer->dispatch([
        'jsonrpc' => '2.0',
        'id' => 9,
        'method' => 'tools/call',
        'params' => [
            'name' => 'craft_exec',
            'arguments' => ['expression' => '1 + 1'],
        ],
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32601);
    expect($response['error']['message'])->toContain('stdio-only');
    expect($response['error']['message'])->toContain('craft_exec');
});

it('does not reject stdio-only tools on the stdio transport', function () {
    // Same call as above, but on the stdio server. The tool's dry-run
    // default will short-circuit before evaluating, so the response is
    // a successful tools/call envelope (not the stdio-rejection error).
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 10,
        'method' => 'tools/call',
        'params' => [
            'name' => 'craft_exec',
            'arguments' => ['expression' => '1 + 1'],
        ],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toHaveKey('isError', false);
});

it('returns JSON-RPC -32600 when jsonrpc version is wrong', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '1.0',
        'id' => 9,
        'method' => 'tools/list',
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32600);
});

// -----------------------------------------------------------------------------
// prompts/list & prompts/get
// -----------------------------------------------------------------------------

it('returns the prompt registry as prompts/list', function () {
    $response = $this->server->dispatch(['jsonrpc' => '2.0', 'id' => 20, 'method' => 'prompts/list']);

    expect($response['result'])->toHaveKey('prompts');
    expect($response['result']['prompts'])->toHaveCount(Plugin::getInstance()->prompts->getCount());

    foreach ($response['result']['prompts'] as $entry) {
        expect($entry)
            ->toBeArray()
            ->toHaveKeys(['name', 'description'])
            ->and($entry['name'])->toBeString()->not->toBeEmpty()
            ->and($entry['description'])->toBeString()->not->toBeEmpty();
    }
});

it('returns a spec-shaped envelope for prompts/get on a known prompt', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 21,
        'method' => 'prompts/get',
        'params' => ['name' => 'craftcms_extending'],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])
        ->toBeArray()
        ->toHaveKeys(['description', 'messages']);

    $message = $response['result']['messages'][0] ?? null;
    expect($message)
        ->toHaveKeys(['role', 'content'])
        ->and($message['role'])->toBe('user')
        ->and($message['content']['type'])->toBe('text')
        ->and($message['content']['text'])->toBeString()->not->toBeEmpty();
});

it('returns JSON-RPC -32602 for an unknown prompt name', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 22,
        'method' => 'prompts/get',
        'params' => ['name' => 'no_such_prompt'],
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32602);
    expect($response['error']['message'])->toContain('Unknown prompt');
});

it('returns JSON-RPC -32602 when prompts/get is missing the name param', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 23,
        'method' => 'prompts/get',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(-32602);
});

// -----------------------------------------------------------------------------
// resources/list & resources/read
// -----------------------------------------------------------------------------

it('returns the resource registry as resources/list', function () {
    $response = $this->server->dispatch(['jsonrpc' => '2.0', 'id' => 30, 'method' => 'resources/list']);

    expect($response['result'])->toHaveKey('resources');
    expect($response['result']['resources'])->toHaveCount(Plugin::getInstance()->resources->getCount());

    foreach ($response['result']['resources'] as $entry) {
        expect($entry)
            ->toBeArray()
            ->toHaveKeys(['uri', 'name', 'description', 'mimeType'])
            ->and($entry['uri'])->toStartWith('craft-skills://');
    }
});

it('returns a spec-shaped envelope for resources/read on a known URI', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 31,
        'method' => 'resources/read',
        'params' => ['uri' => 'craft-skills://craftcms'],
    ]);

    expect($response)->toHaveKey('result');
    expect($response['result'])->toHaveKey('contents');
    expect($response['result']['contents'])->toBeArray()->toHaveCount(1);

    $block = $response['result']['contents'][0];
    expect($block)
        ->toHaveKeys(['uri', 'mimeType', 'text'])
        ->and($block['uri'])->toBe('craft-skills://craftcms')
        ->and($block['mimeType'])->toBe('text/markdown')
        ->and($block['text'])->toBeString()->not->toBeEmpty();
});

it('reads a reference URI through resources/read', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 32,
        'method' => 'resources/read',
        'params' => ['uri' => 'craft-skills://craftcms/elements'],
    ]);

    $block = $response['result']['contents'][0];
    expect($block['uri'])->toBe('craft-skills://craftcms/elements');
    expect($block['text'])->toBeString()->not->toBeEmpty();
});

it('returns JSON-RPC -32602 for an unknown resource URI', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 33,
        'method' => 'resources/read',
        'params' => ['uri' => 'craft-skills://no-such-skill'],
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32602);
    expect($response['error']['message'])->toContain('Unknown resource');
});

it('returns JSON-RPC -32602 when resources/read is missing the uri param', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 34,
        'method' => 'resources/read',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(-32602);
});

// -----------------------------------------------------------------------------
// ping
// -----------------------------------------------------------------------------

it('responds to ping with an empty result', function () {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 12,
        'method' => 'ping',
    ]);

    expect($response)->toHaveKey('result');
    expect((array) $response['result'])->toBe([]);
});
