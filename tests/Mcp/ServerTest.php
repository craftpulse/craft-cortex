<?php

/**
 * =========================================================================
 * Protocol-level tests for the JSON-RPC dispatcher. Cover what the
 * MCP wire format requires the server to do, regardless of which
 * tools happen to be registered.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
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

/**
 * Pull the last `tools/call` audit-log line out of Craft's in-flight
 * message buffer. The locked audit-line format is exactly the contract
 * we want to assert against, so we drive the dispatcher and read the
 * line back rather than inspecting private state.
 *
 * @return string|null the matched log line, or null if nothing was logged
 */
function _cortex_last_audit_line(): ?string
{
    $messages = Craft::getLogger()->messages;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $entry = $messages[$i];
        if (($entry[2] ?? null) !== \craftpulse\cortex\tools\support\InvocationLogger::CATEGORY) {
            continue;
        }
        $text = (string) $entry[0];
        if (str_starts_with($text, 'tool=')) {
            return $text;
        }
    }
    return null;
}

it('stamps the captured clientInfo.name into the audit-log line', function() {
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

    // Drive a real tools/call so the dispatcher emits one audit-log line.
    // `sections` is a known no-arg read-only tool. The locked audit
    // line format renders the captured client as `client=<name>`.
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);

    expect(_cortex_last_audit_line())->toContain('client=claude-code');
});

it('renders client=- in the audit log when initialize omits clientInfo', function() {
    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
        ],
    ]);

    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);

    expect(_cortex_last_audit_line())->toContain('client=-');
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

    $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);

    expect(_cortex_last_audit_line())->toContain('client=-');
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

it('omits structuredContent when the tool declares no outputSchema', function() {
    // `sections` (in count mode) returns a stable `{count: int}` shape but
    // does not declare an outputSchema, so the envelope only carries the
    // text block. Spec lets clients infer "no schema" by absence.
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'sections',
            'arguments' => ['count' => true],
        ],
    ]);

    expect($response['result'])->toBeMcpSuccessEnvelope();
    expect($response['result'])->not->toHaveKey('structuredContent');
});

it('dual-emits structuredContent when the tool declares an outputSchema', function() {
    // `system_info` declares outputSchema, so the envelope carries both
    // the legacy text block AND a structuredContent field with the same
    // parsed payload. Spec-aware clients read structuredContent and can
    // validate against tools/list outputSchema; older clients fall back
    // to the text block.
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'system_info'],
    ]);

    expect($response['result'])->toBeMcpSuccessEnvelope();
    expect($response['result'])->toHaveKey('structuredContent');

    $structured = $response['result']['structuredContent'];
    $text = cortex_unwrap($response['result']);

    expect($structured)->toBe($text);
    expect($structured)->toHaveKeys(['craft', 'php', 'db', 'sites', 'license']);
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
    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
    expect($response['error']['message'])->toContain('Unknown tool');
});

it('returns JSON-RPC -32602 when tools/call is missing the name param', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 6,
        'method' => 'tools/call',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
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

    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
});

it('returns JSON-RPC -32601 for an unknown method', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 8,
        'method' => 'method/that/does/not/exist',
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(Server::ERR_METHOD_NOT_FOUND);
});

// -----------------------------------------------------------------------------
// setUserId() — request-scoped, no static state leak between instances
// -----------------------------------------------------------------------------

it('setUserId() is request-scoped — separate Server instances do not share user state', function() {
    $a = new Server(Server::TRANSPORT_HTTP);
    $a->setUserId(101);

    $b = new Server(Server::TRANSPORT_HTTP);
    $b->setUserId(202);

    // Drive a tools/call through each so the audit-log line surfaces
    // the bound userId. We re-use the existing `sections` tool which
    // is a no-arg success path; the assertion target is the
    // `user=` field on the locked audit line.
    $a->dispatch([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $aLine = _cortex_last_audit_line();
    expect($aLine)->toContain('user=101');

    $b->dispatch([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $bLine = _cortex_last_audit_line();
    expect($bLine)->toContain('user=202');
    // Confirm the second dispatch produced a different line — same
    // text would mean we accidentally read the cached one from `$a`.
    expect($bLine)->not->toBe($aLine);
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
    expect($response['error']['code'])->toBe(Server::ERR_METHOD_NOT_FOUND);
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
    // Register a fixture tool that raises a non-ToolException Throwable
    // via the public extension surface. The dispatcher should log the
    // full message via Craft::error and return a generic wire envelope
    // so the exception text never reaches the caller.
    $secret = '__SECRET_DB_CONNECTION_STRING__';
    $listener = function(\craftpulse\cortex\events\RegisterToolsEvent $event) use ($secret): void {
        $event->tools[] = new class($secret) extends AbstractTool {
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
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Tools::class,
        \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
        $listener,
    );

    // Swap the plugin's `tools` service for a freshly-built one so the
    // listener fires and the dispatcher sees the fixture tool.
    $originalTools = Plugin::getInstance()->tools;
    $freshTools = new \craftpulse\cortex\services\Tools();
    $freshTools->init();
    Plugin::getInstance()->set('tools', $freshTools);

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
        expect($response['error']['code'])->toBe(Server::ERR_INTERNAL);
        expect($response['error']['message'])
            ->toContain('_throwing_test_tool')
            ->not->toContain($secret);
    } finally {
        Plugin::getInstance()->set('tools', $originalTools);
        \yii\base\Event::off(
            \craftpulse\cortex\services\Tools::class,
            \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
            $listener,
        );
    }
});

it('returns JSON-RPC -32600 when jsonrpc version is wrong', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '1.0',
        'id' => 9,
        'method' => 'tools/list',
    ]);

    expect($response)->toHaveKey('error');
    expect($response['error']['code'])->toBe(Server::ERR_INVALID_REQUEST);
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
    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
    expect($response['error']['message'])->toContain('Unknown prompt');
});

it('returns JSON-RPC -32602 when prompts/get is missing the name param', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 23,
        'method' => 'prompts/get',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
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
    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
    expect($response['error']['message'])->toContain('Unknown resource');
});

it('returns JSON-RPC -32602 when resources/read is missing the uri param', function() {
    $response = $this->server->dispatch([
        'jsonrpc' => '2.0',
        'id' => 34,
        'method' => 'resources/read',
        'params' => [],
    ]);

    expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
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

    $originalTools = Plugin::getInstance()->tools;
    $freshTools = new \craftpulse\cortex\services\Tools();
    $freshTools->init();
    Plugin::getInstance()->set('tools', $freshTools);

    try {
        // Drive the Generator path through the public dispatch() surface
        // so the test exercises the actual code path the wire client
        // hits — not a private helper. The dispatcher must consume the
        // generator and surface the return value as the tools/call
        // result.
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => '_fake_streaming_tool', 'arguments' => []],
        ]);

        expect($response)->toHaveKey('result');
        expect($response['result'])->toHaveKey('isError', false);
        expect($response['result']['content'][0]['text'])
            ->toContain('"done": true')
            ->toContain('"count": 2');
    } finally {
        Plugin::getInstance()->set('tools', $originalTools);
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

// -----------------------------------------------------------------------------
// Per-user filtering — HTTP routes through asListPayloadFor / getByNameFor
// -----------------------------------------------------------------------------

it('tools/list over HTTP omits a tool whose filterFor returns false for the resolved user', function() {
    // Register a stub tool that only the null-user (stdio) caller sees.
    // The HTTP path resolves the bound userId to a real User and the
    // stub returns false for it, so the tool drops out of tools/list.
    $listener = function(\craftpulse\cortex\events\RegisterToolsEvent $event): void {
        $event->tools[] = new class() extends AbstractTool {
            public static function getName(): string
            {
                return '_test_/stdio-only-filter';
            }

            public static function getDescription(): string
            {
                return 'Fixture — filterFor only allows null (stdio).';
            }

            public function filterFor(?\craft\elements\User $user = null): bool
            {
                return $user === null;
            }

            public function execute(array $arguments): array
            {
                return ['ok' => true];
            }
        };
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Tools::class,
        \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
        $listener,
    );

    $originalTools = Plugin::getInstance()->tools;
    $freshTools = new \craftpulse\cortex\services\Tools();
    $freshTools->init();
    Plugin::getInstance()->set('tools', $freshTools);

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();

    try {
        $http = new Server(Server::TRANSPORT_HTTP);
        $http->setUserId((int) $admin->id);
        $httpResponse = $http->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $httpNames = array_column($httpResponse['result']['tools'], 'name');
        expect($httpNames)->not->toContain('_test_/stdio-only-filter');

        // Default stdio dispatcher passes null and the stub allows null.
        $stdioResponse = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);
        $stdioNames = array_column($stdioResponse['result']['tools'], 'name');
        expect($stdioNames)->toContain('_test_/stdio-only-filter');
    } finally {
        Plugin::getInstance()->set('tools', $originalTools);
        \yii\base\Event::off(
            \craftpulse\cortex\services\Tools::class,
            \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
            $listener,
        );
    }
});

it('tools/call over HTTP rejects a tool whose filterFor returns false as Unknown tool', function() {
    // A non-admin user (null-user-only stub) tries to call the tool
    // through the HTTP path. The dispatcher must fail closed —
    // indistinguishable from "tool not registered" on the wire.
    $listener = function(\craftpulse\cortex\events\RegisterToolsEvent $event): void {
        $event->tools[] = new class() extends AbstractTool {
            public static function getName(): string
            {
                return '_test_/http-rejected-filter';
            }

            public static function getDescription(): string
            {
                return 'Fixture — filterFor only allows null (stdio).';
            }

            public function filterFor(?\craft\elements\User $user = null): bool
            {
                return $user === null;
            }

            public function execute(array $arguments): array
            {
                return ['ok' => true];
            }
        };
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Tools::class,
        \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
        $listener,
    );

    $originalTools = Plugin::getInstance()->tools;
    $freshTools = new \craftpulse\cortex\services\Tools();
    $freshTools->init();
    Plugin::getInstance()->set('tools', $freshTools);

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();

    try {
        $http = new Server(Server::TRANSPORT_HTTP);
        $http->setUserId((int) $admin->id);
        $response = $http->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => '_test_/http-rejected-filter'],
        ]);

        expect($response)->toHaveKey('error');
        expect($response['error']['code'])->toBe(Server::ERR_INVALID_PARAMS);
        expect($response['error']['message'])->toContain('Unknown tool');

        // stdio path (null user) succeeds — same tool, no user binding.
        $stdioResponse = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => '_test_/http-rejected-filter'],
        ]);
        expect($stdioResponse)->toHaveKey('result');
        expect($stdioResponse['result'])->toHaveKey('isError', false);
    } finally {
        Plugin::getInstance()->set('tools', $originalTools);
        \yii\base\Event::off(
            \craftpulse\cortex\services\Tools::class,
            \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
            $listener,
        );
    }
});

it('falls back to the last yielded value when a Generator has no explicit return', function() {
    $listener = function(\craftpulse\cortex\events\RegisterToolsEvent $event): void {
        $event->tools[] = new class() extends \craftpulse\cortex\tools\AbstractTool {
            public static function getName(): string
            {
                return '_fake_streaming_no_return';
            }

            public static function getDescription(): string
            {
                return 'Fixture that yields without an explicit return.';
            }

            public function execute(array $arguments): \Generator
            {
                yield ['progress' => 0.5];
                yield ['done' => true];
            }
        };
    };
    \yii\base\Event::on(
        \craftpulse\cortex\services\Tools::class,
        \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
        $listener,
    );

    $originalTools = Plugin::getInstance()->tools;
    $freshTools = new \craftpulse\cortex\services\Tools();
    $freshTools->init();
    Plugin::getInstance()->set('tools', $freshTools);

    try {
        $response = $this->server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => '_fake_streaming_no_return', 'arguments' => []],
        ]);

        // No explicit `return` from the Generator — the dispatcher
        // surfaces the last yielded value as the tools/call result.
        expect($response)->toHaveKey('result');
        expect($response['result'])->toHaveKey('isError', false);
        expect($response['result']['content'][0]['text'])->toContain('"done": true');
    } finally {
        Plugin::getInstance()->set('tools', $originalTools);
        \yii\base\Event::off(
            \craftpulse\cortex\services\Tools::class,
            \craftpulse\cortex\services\Tools::EVENT_REGISTER_TOOLS,
            $listener,
        );
    }
});
