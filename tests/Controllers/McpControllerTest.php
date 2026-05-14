<?php

/**
 * =========================================================================
 * McpController tests — verify the HTTP transport's request flow:
 * `httpEnabled` gate, Origin allowlist, protocol-version header,
 * session affinity, DELETE termination, and the stdio-only rejection
 * for `craft_exec` over HTTP.
 *
 * The controller pulls headers off `$this->request->getHeaders()`, the
 * raw body off `$this->request->getRawBody()`, and dispatches via
 * `Plugin::getInstance()->sessions`. The test harness subclasses the
 * controller and swaps `$this->request` with a stub that responds to
 * the methods the controller actually calls — no Yii web stack is
 * stood up.
 *
 * `Settings::$httpEnabled` is toggled per test via the live settings
 * model (no project-config write), and reset in `afterEach()`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\controllers\McpController;
use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\mcp\transport\Http;
use craftpulse\cortex\Plugin;
use yii\web\HeaderCollection;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * Stub request — exposes only the surface the controller actually
 * uses (`getMethod()`, `getHeaders()`, `getRawBody()`). Headers are a
 * `yii\web\HeaderCollection` so the controller's `$headers->get(…)`
 * calls work unchanged.
 */
class _CortexMcpRequest
{
    public HeaderCollection $headers;

    public function __construct(
        private readonly string $method = 'POST',
        array $headers = [],
        private readonly string $rawBody = '',
    ) {
        $this->headers = new HeaderCollection();
        foreach ($headers as $name => $value) {
            $this->headers->set($name, $value);
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getHeaders(): HeaderCollection
    {
        return $this->headers;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }
}

/**
 * Thin McpController subclass — swaps in stub request / response slots
 * so the action body runs against console-bootstrapped Craft.
 */
class _CortexMcpControllerHarness extends McpController
{
    public function withRequest(_CortexMcpRequest $req): self
    {
        // Yii's `craft\web\Controller` exposes a `request` magic getter
        // that resolves through the `request` Yii component. We
        // shortcut by setting `$this->request` directly — Yii's
        // `__set` for the inherited slot accepts the assignment.
        $this->request = $req;
        return $this;
    }

    public function withFreshResponse(): self
    {
        $this->response = new Response();
        return $this;
    }
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Build a harness with the supplied HTTP method, headers, and body.
 *
 * @param array<string,string> $headers
 */
function _cortex_mcp_harness(string $method, array $headers, string $body = ''): _CortexMcpControllerHarness
{
    $controller = new _CortexMcpControllerHarness('mcp', Plugin::getInstance());
    $controller->withRequest(new _CortexMcpRequest($method, $headers, $body));
    $controller->withFreshResponse();
    return $controller;
}

/**
 * Pull a JSON-RPC envelope out of a `FORMAT_RAW` response by JSON-
 * decoding the body string the controller wrote.
 *
 * @return array<string,mixed>
 */
function _cortex_decode_response(Response $response): array
{
    $body = (string) $response->content;
    /** @var array<string,mixed> $decoded */
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    return $decoded;
}

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $settings = Plugin::getInstance()->getSettings();
    $this->originalHttpEnabled = $settings->httpEnabled;
    $this->originalAllowedOrigins = $settings->allowedOrigins;
    // Enable for the majority of cases; the disabled-flag test flips
    // it back off explicitly.
    $settings->httpEnabled = true;
    $settings->allowedOrigins = [];
});

afterEach(function() {
    $settings = Plugin::getInstance()->getSettings();
    $settings->httpEnabled = $this->originalHttpEnabled;
    $settings->allowedOrigins = $this->originalAllowedOrigins;
});

// -----------------------------------------------------------------------------
// httpEnabled kill switch
// -----------------------------------------------------------------------------

it('returns 503 when Settings::$httpEnabled is false', function() {
    Plugin::getInstance()->getSettings()->httpEnabled = false;

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(503);
});

// -----------------------------------------------------------------------------
// MCP-Protocol-Version header validation
// -----------------------------------------------------------------------------

it('returns 400 when MCP-Protocol-Version header is missing', function() {
    $controller = _cortex_mcp_harness('POST', []);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(400);
});

it('returns 400 when MCP-Protocol-Version is unrecognised', function() {
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => '2024-01-01',
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(400);
});

// -----------------------------------------------------------------------------
// Origin allowlist
// -----------------------------------------------------------------------------

it('returns 403 when Origin is not in a non-empty allowlist', function() {
    Plugin::getInstance()->getSettings()->allowedOrigins = ['https://allowed.example'];

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_ORIGIN => 'https://attacker.example',
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(403);
});

it('passes Origin validation when Origin matches the allowlist exactly', function() {
    Plugin::getInstance()->getSettings()->allowedOrigins = ['https://allowed.example'];

    $body = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest', 'version' => '0'],
        ],
    ]);

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_ORIGIN => 'https://allowed.example',
    ], (string) $body);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(200);
});

// -----------------------------------------------------------------------------
// initialize → session minted; subsequent POST requires session id
// -----------------------------------------------------------------------------

it('POST initialize returns 200 with Mcp-Session-Id response header and valid initialize result', function() {
    $body = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'pest', 'version' => '0'],
        ],
    ]);

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ], (string) $body);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(200);
    $sessionId = $response->headers->get(Http::HEADER_SESSION_ID);
    expect($sessionId)->toBeString()->not->toBeEmpty();

    $envelope = _cortex_decode_response($response);
    expect($envelope)->toHaveKeys(['jsonrpc', 'id', 'result']);
    expect($envelope['result']['protocolVersion'])->toBe(Server::PROTOCOL_VERSION);
    expect($envelope['result']['serverInfo']['name'])->toBe(Server::SERVER_NAME);

    // Cleanup: terminate the freshly-minted session so it doesn't
    // accumulate in the cache across test runs.
    Plugin::getInstance()->sessions->terminate((string) $sessionId);
});

it('POST without Mcp-Session-Id on non-initialize requests returns 400', function() {
    $body = json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ], (string) $body);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(400);
});

it('POST with a valid session id continues processing tools/call', function() {
    // Mint a session by running initialize through the controller.
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ], $initBody);
    $initResponse = $initController->actionIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    // Now drive a real tools/call against that session.
    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
    ], $callBody);
    $callResponse = $callController->actionIndex();

    expect($callResponse->statusCode)->toBe(200);
    $envelope = _cortex_decode_response($callResponse);
    expect($envelope)->toHaveKey('result');
    expect($envelope['result'])->toHaveKey('content');

    Plugin::getInstance()->sessions->terminate($sessionId);
});

it('POST with an unknown Mcp-Session-Id returns 404', function() {
    $body = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => 'this-id-was-never-issued',
    ], $body);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(404);
});

// -----------------------------------------------------------------------------
// DELETE — session termination
// -----------------------------------------------------------------------------

it('DELETE with a valid session id terminates the session and returns 204', function() {
    $session = Plugin::getInstance()->sessions->create(Server::PROTOCOL_VERSION, 'pest');

    $controller = _cortex_mcp_harness('DELETE', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $session->id,
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(204);
    expect(Plugin::getInstance()->sessions->get($session->id))->toBeNull();
});

it('subsequent POST with a terminated session id returns 404', function() {
    $session = Plugin::getInstance()->sessions->create(Server::PROTOCOL_VERSION, 'pest');
    Plugin::getInstance()->sessions->terminate($session->id);

    $body = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $session->id,
    ], $body);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(404);
});

it('DELETE without Mcp-Session-Id returns 400', function() {
    $controller = _cortex_mcp_harness('DELETE', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(400);
});

// -----------------------------------------------------------------------------
// GET — reserved for SSE in 7.7, 405 today
// -----------------------------------------------------------------------------

it('GET returns 405 in sub-gate 7.1', function() {
    $controller = _cortex_mcp_harness('GET', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ]);
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(405);
});

// -----------------------------------------------------------------------------
// craft_exec stdio-only rejection over HTTP
// -----------------------------------------------------------------------------

it('tools/call for craft_exec over HTTP returns the JSON-RPC stdio-only rejection at HTTP 200', function() {
    // Mint a session.
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ], $initBody);
    $initResponse = $initController->actionIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    // Call craft_exec — should be rejected with a JSON-RPC error
    // envelope at HTTP 200 (not an HTTP-level 403).
    $execBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'craft_exec',
            'arguments' => ['expression' => 'true'],
        ],
    ]);
    $execController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
    ], $execBody);
    $execResponse = $execController->actionIndex();

    expect($execResponse->statusCode)->toBe(200);
    $envelope = _cortex_decode_response($execResponse);
    expect($envelope)->toHaveKey('error');
    expect($envelope['error']['code'])->toBe(Server::ERR_METHOD_NOT_FOUND);
    expect($envelope['error']['message'])->toContain('stdio-only');

    Plugin::getInstance()->sessions->terminate($sessionId);
});

// -----------------------------------------------------------------------------
// Malformed body → JSON-RPC parse error envelope
// -----------------------------------------------------------------------------

it('malformed JSON body returns a JSON-RPC parse-error envelope at HTTP 200', function() {
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ], '{not-json}');
    $response = $controller->actionIndex();

    expect($response->statusCode)->toBe(200);
    $envelope = _cortex_decode_response($response);
    expect($envelope['error']['code'])->toBe(Server::ERR_PARSE);
});

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('extends craft\\web\\Controller', function() {
    expect(is_subclass_of(McpController::class, \craft\web\Controller::class))->toBeTrue();
});

it('declares the index action', function() {
    $rc = new ReflectionClass(McpController::class);
    expect($rc->hasMethod('actionIndex'))->toBeTrue();
});

it('disables CSRF on the JSON-RPC endpoint', function() {
    $controller = new McpController('mcp', Plugin::getInstance());
    expect($controller->enableCsrfValidation)->toBeFalse();
});
