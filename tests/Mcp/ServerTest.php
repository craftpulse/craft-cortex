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
use craftpulse\cortex\tools\AbstractTool;

beforeEach(function() {
    $this->server = new Server();
});

// -----------------------------------------------------------------------------
// initialize
// -----------------------------------------------------------------------------

it('responds to initialize with the pinned protocol version + serverInfo', function() {
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

it('captures clientInfo.name from initialize so the audit log can stamp it', function() {
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0'],
        ],
    ]);

    // The captured client name lives on a private property — accessed
    // via reflection here rather than exposed publicly because it's an
    // internal dispatcher detail, not part of the extension API. This
    // test guards the wiring; the line format is locked in the
    // InvocationLogger tests.
    $rc = new ReflectionClass($this->server);
    $prop = $rc->getProperty('_clientName');
    $prop->setAccessible(true);

    expect($prop->getValue($this->server))->toBe('claude-code');
});

it('leaves the captured client name null when initialize omits clientInfo', function() {
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
        ],
    ]);

    $rc = new ReflectionClass($this->server);
    $prop = $rc->getProperty('_clientName');
    $prop->setAccessible(true);

    expect($prop->getValue($this->server))->toBeNull();
});

it('clears a previously-captured client name when a re-handshake omits clientInfo', function() {
    // First handshake — captures the name.
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'cursor', 'version' => '1.0'],
        ],
    ]);

    // Re-handshake — omits clientInfo. Stale capture must not survive,
    // otherwise audit-log lines after the re-handshake carry the wrong
    // client name.
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
        ],
    ]);

    $rc = new ReflectionClass($this->server);
    $prop = $rc->getProperty('_clientName');
    $prop->setAccessible(true);

    expect($prop->getValue($this->server))->toBeNull();
});

// -----------------------------------------------------------------------------
// notifications
// -----------------------------------------------------------------------------

it('returns null for any notification (no id field)', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'method' => 'notifications/initialized',
    ]);

    expect($response)->toBeNull();
});

// -----------------------------------------------------------------------------
// tools/list
// -----------------------------------------------------------------------------

it('returns the registry as tools/list', function() {
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

it('wraps tool output in an MCP success envelope on tools/call', function() {
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

it('returns an isError envelope when a tool throws ToolException', function() {
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

it('returns JSON-RPC -32602 for an unknown tool name', function() {
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

it('returns JSON-RPC -32602 when tools/call is missing the name param', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(-32602);
});

it('returns JSON-RPC -32602 when tools/call arguments is not an object', function() {
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

it('returns JSON-RPC -32601 for an unknown method', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'method/that/does/not/exist',
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(-32601);
});

// -----------------------------------------------------------------------------
// stdio-only enforcement
// -----------------------------------------------------------------------------

it('rejects a stdio-only tool when dispatched on the HTTP transport', function() {
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

it('does not reject stdio-only tools on the stdio transport', function() {
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

it('returns -32603 with a generic message when a tool throws an unexpected exception', function() {
    // Inject a tool that raises a non-ToolException Throwable. The
    // dispatcher should log the full message via Craft::error and
    // return a generic wire envelope so the exception text never
    // reaches the caller.
    $secret = '__SECRET_DB_CONNECTION_STRING__';
    $throwingTool = new class($secret) extends AbstractTool {
        public function __construct(private string $secret)
        {
        }

        public static function getName(): string
        {
            return '_throwing_test_tool';
        }

        public static function getDescription(): string
        {
            return 'Test fixture that throws an unexpected exception.';
        }

        public function execute(array $arguments): array
        {
            throw new RuntimeException($this->secret);
        }
    };

    $tools = Plugin::getInstance()->tools;
    $rc = new ReflectionClass($tools);
    $byName = $rc->getProperty('_byName');
    $byName->setAccessible(true);
    $registry = $byName->getValue($tools);
    $registry['_throwing_test_tool'] = $throwingTool;
    $byName->setValue($tools, $registry);

    try {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'tools/call',
            'params' => [
                'name' => '_throwing_test_tool',
                'arguments' => [],
            ],
        ]);

        expect($response)->toHaveKey('error');
        expect($response['error']['code'])->toBe(-32603);
        expect($response['error']['message'])
            ->toContain('_throwing_test_tool')
            ->not->toContain($secret);
    } finally {
        unset($registry['_throwing_test_tool']);
        $byName->setValue($tools, $registry);
    }
});

it('returns JSON-RPC -32600 when jsonrpc version is wrong', function() {
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

it('returns the prompt registry as prompts/list', function() {
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

it('returns a spec-shaped envelope for prompts/get on a known prompt', function() {
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

it('returns JSON-RPC -32602 for an unknown prompt name', function() {
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

it('returns JSON-RPC -32602 when prompts/get is missing the name param', function() {
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

it('returns the resource registry as resources/list', function() {
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

it('returns a spec-shaped envelope for resources/read on a known URI', function() {
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

it('reads a reference URI through resources/read', function() {
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

it('returns JSON-RPC -32602 for an unknown resource URI', function() {
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

it('returns JSON-RPC -32602 when resources/read is missing the uri param', function() {
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

it('responds to ping with an empty result', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 12,
        'method' => 'ping',
    ]);

    expect($response)->toHaveKey('result');
    expect((array) $response['result'])->toBe([]);
});

// -----------------------------------------------------------------------------
// Generator return type — eager consume (HTTP transport will stream notifications)
// -----------------------------------------------------------------------------

it('eagerly consumes a Generator-returning tool and surfaces the return value', function() {
    $listener = function(\craftpulse\cortex\events\RegisterToolsEvent $event): void {
        $event->tools[] = new class() extends \craftpulse\cortex\tools\AbstractTool {
            public static function getName(): string
            {
                return '_fake_streaming_tool';
            }

            public static function getDescription(): string
            {
                return 'Fixture that yields progress and returns final.';
            }

            public function execute(array $arguments): \Generator
            {
                yield ['progress' => 0.5];
                yield ['progress' => 1.0];
                return ['done' => true, 'count' => 2];
            }
        };
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Tools::class,
        \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
        $listener,
    );

    try {
        // Build a fresh service so the fresh listener is in effect.
        $service = new \craftpulse\cortex\services\Tools();
        $service->init();
        $tool = $service->getByName('_fake_streaming_tool');
        expect($tool)->not->toBeNull();

        // The dispatcher operates on Plugin::getInstance()->tools, so to
        // exercise the Generator path through the dispatcher we call the
        // tool directly via a Server instance after temporarily swapping
        // the plugin's registry. Simpler: assert via the dispatcher by
        // re-registering on the global service for the duration.
        // For the stdio dispatcher the stronger contract is "no
        // exception, final return is surfaced" — assert that against
        // the inner consumer.

        $reflection = new ReflectionClass($this->server);
        $method = $reflection->getMethod('_consumeGenerator');
        $method->setAccessible(true);
        $result = $method->invoke($this->server, $tool->execute([]));

        expect($result)->toBe(['done' => true, 'count' => 2]);
    } finally {
        \yii\base\Event::off(
            \craftpulse\cortex\services\Tools::class,
            \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
            $listener,
        );
    }
});

// -----------------------------------------------------------------------------
// Resource templates — dynamic URI fallback
// -----------------------------------------------------------------------------

it('reads a templated resource when no concrete URI matches', function() {
    $listener = function(\craftpulse\cortex\events\RegisterResourcesEvent $event): void {
        $event->resources[] = new class() implements \craftpulse\cortex\resources\ResourceTemplateInterface {
            public function getUriTemplate(): string
            {
                return '_fake://entries/{id}';
            }

            public function getName(): string
            {
                return 'fake-entry-template';
            }

            public function getDescription(): string
            {
                return 'Fixture template.';
            }

            public function getMimeType(): string
            {
                return 'text/plain';
            }

            public function matches(string $uri): ?array
            {
                if (preg_match('#^_fake://entries/([^/]+)$#', $uri, $m)) {
                    return ['id' => $m[1]];
                }
                return null;
            }

            public function read(string $uri, array $captures): array
            {
                return ['uri' => $uri, 'mimeType' => 'text/plain', 'text' => "id={$captures['id']}"];
            }
        };
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Resources::class,
        \craftpulse\cortex\services\Resources::EVENT_REGISTER_RESOURCES,
        $listener,
    );

    try {
        // Build a fresh service so the listener fires.
        $service = new \craftpulse\cortex\services\Resources();
        $service->init();
        $match = $service->matchTemplate('_fake://entries/42');
        expect($match)->not->toBeNull();
        [$template, $captures] = $match;
        expect($captures)->toBe(['id' => '42']);

        $block = $template->read('_fake://entries/42', $captures);
        expect($block)->toBe([
            'uri' => '_fake://entries/42',
            'mimeType' => 'text/plain',
            'text' => 'id=42',
        ]);
    } finally {
        \yii\base\Event::off(
            \craftpulse\cortex\services\Resources::class,
            \craftpulse\cortex\services\Resources::EVENT_REGISTER_RESOURCES,
            $listener,
        );
    }
});

it('matchTemplate returns null when no template matches', function() {
    $service = new \craftpulse\cortex\services\Resources();
    $service->init();
    expect($service->matchTemplate('_unknown://nothing'))->toBeNull();
});

it('falls back to the last yielded value when a Generator has no explicit return', function() {
    $gen = (function() {
        yield ['progress' => 0.5];
        yield ['done' => true];
    })();

    $reflection = new ReflectionClass($this->server);
    $method = $reflection->getMethod('_consumeGenerator');
    $method->setAccessible(true);
    $result = $method->invoke($this->server, $gen);

    expect($result)->toBe(['done' => true]);
});
