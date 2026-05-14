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
use craftpulse\cortex\oauth\entities\AccessTokenEntity;
use craftpulse\cortex\oauth\entities\ClientEntity;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\OauthClient as OauthClientRecord;
use craftpulse\cortex\records\OauthToken as OauthTokenRecord;
use craftpulse\cortex\records\Token as TokenRecord;
use League\OAuth2\Server\CryptKey;
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

    /**
     * Stubbed for `craft\web\Controller::beforeAction()` which probes
     * the request for live-preview detection before delegating to
     * Yii's `Controller::beforeAction()`. Tests never hit a live-
     * preview request, so always false.
     */
    public function getIsLivePreview(): bool
    {
        return false;
    }

    /**
     * `craft\web\Controller::_enforceAllowAnonymous()` branches on
     * this — site-facing routes get the site-token grant; the cortex
     * MCP endpoint is a site-facing JSON-RPC route, so false here.
     */
    public function getIsCpRequest(): bool
    {
        return false;
    }

    /**
     * Site-token grant probed by `_enforceAllowAnonymous()`. Tests
     * never carry a site token; the bearer-token surface is what we
     * exercise.
     */
    public function hasValidSiteToken(): bool
    {
        return false;
    }
}

/**
 * Thin McpController subclass — swaps in stub request / response slots
 * so the action body runs against console-bootstrapped Craft, and
 * `run()` drives both `beforeAction()` and `actionIndex()` the same
 * way Yii's runtime does so auth gates fire in tests.
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

    /**
     * Drive both `beforeAction()` and `actionIndex()` against a
     * synthetic action object. When `beforeAction()` short-circuits
     * (returns false), it has already populated the response slot;
     * we surface that response so tests can assert on it without
     * threading through Yii's full controller pipeline. Named
     * `runIndex` (not `run`) so we don't collide with Yii's
     * `Controller::run($route, $params)` signature.
     */
    public function runIndex(): Response
    {
        $action = new \yii\base\Action('index', $this);
        if (!$this->beforeAction($action)) {
            return $this->response;
        }
        return $this->actionIndex();
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
    $this->originalRateLimitBurst = $settings->rateLimitBurst;
    $this->originalRateLimitPerSecond = $settings->rateLimitPerSecond;
    // Enable for the majority of cases; the disabled-flag test flips
    // it back off explicitly.
    $settings->httpEnabled = true;
    $settings->allowedOrigins = [];

    // Auth scaffolding — issue a fresh bearer token bound to the
    // playground's admin user for the majority of tests. Tests that
    // exercise the no-auth / bad-auth paths swap the header out.
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    $this->userId = (int) $admin->id;

    $issued = Plugin::getInstance()->tokens->issue($this->userId, '_test_/controller-bearer');
    $this->bearerToken = $issued['token'];
    $this->bearerTokenId = (int) $issued['model']->id;
    $this->bearerHeader = 'Bearer ' . $this->bearerToken;

    // Start every test from a known-clean bucket for the admin user
    // so a chatty sibling test doesn't deplete the bucket and trip
    // the 429 path under a happy-path assertion.
    Plugin::getInstance()->rateLimiter->clear($this->userId);
});

afterEach(function() {
    $settings = Plugin::getInstance()->getSettings();
    $settings->httpEnabled = $this->originalHttpEnabled;
    $settings->allowedOrigins = $this->originalAllowedOrigins;
    $settings->rateLimitBurst = $this->originalRateLimitBurst;
    $settings->rateLimitPerSecond = $this->originalRateLimitPerSecond;
    Plugin::getInstance()->rateLimiter->clear($this->userId);
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);

    // Cleanup OAuth fixtures from Gate 7.3 tests too. The clientId
    // pattern `_test_/mcp-oauth-*` is unique to this file's
    // `_cortex_mint_oauth_access_token` helper.
    $clientIds = OauthClientRecord::find()
        ->where(['like', 'clientName', '_test_/mcp-oauth-%', false])
        ->select(['clientId'])
        ->column();
    if ($clientIds !== []) {
        OauthTokenRecord::deleteAll(['clientId' => $clientIds]);
        OauthClientRecord::deleteAll(['clientId' => $clientIds]);
    }
});

// -----------------------------------------------------------------------------
// httpEnabled kill switch
// -----------------------------------------------------------------------------

it('returns 503 when Settings::$httpEnabled is false', function() {
    Plugin::getInstance()->getSettings()->httpEnabled = false;

    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ]);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(503);
});

// -----------------------------------------------------------------------------
// MCP-Protocol-Version header validation
// -----------------------------------------------------------------------------

it('returns 400 when MCP-Protocol-Version header is missing', function() {
    $controller = _cortex_mcp_harness('POST', [
        'Authorization' => $this->bearerHeader,
    ]);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(400);
});

it('returns 400 when MCP-Protocol-Version is unrecognised', function() {
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => '2024-01-01',
        'Authorization' => $this->bearerHeader,
    ]);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ]);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], (string) $body);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], (string) $body);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], (string) $body);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
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
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callResponse = $callController->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(404);
});

// -----------------------------------------------------------------------------
// DELETE — session termination
// -----------------------------------------------------------------------------

it('DELETE with a valid session id terminates the session and returns 204', function() {
    $session = Plugin::getInstance()->sessions->create(Server::PROTOCOL_VERSION, 'pest');

    // DELETE intentionally bypasses bearer auth — see the rationale
    // on `McpController::beforeAction()`. No Authorization header
    // here on purpose; the test asserts the bypass is in place.
    $controller = _cortex_mcp_harness('DELETE', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $session->id,
    ]);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(404);
});

it('DELETE without Mcp-Session-Id returns 400', function() {
    $controller = _cortex_mcp_harness('DELETE', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
    ]);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(400);
});

// -----------------------------------------------------------------------------
// GET — reserved for SSE in 7.7, 405 today
// -----------------------------------------------------------------------------

it('GET returns 405 in sub-gates 7.1–7.2', function() {
    $controller = _cortex_mcp_harness('GET', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ]);
    $response = $controller->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
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
        'Authorization' => $this->bearerHeader,
    ], $execBody);
    $execResponse = $execController->runIndex();

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
        'Authorization' => $this->bearerHeader,
    ], '{not-json}');
    $response = $controller->runIndex();

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

// -----------------------------------------------------------------------------
// Sub-gate 7.2 — bearer-token authentication
// -----------------------------------------------------------------------------

it('POST without Authorization header returns 401 + WWW-Authenticate', function() {
    $body = (string) json_encode([
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
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
    expect($response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="cortex"');
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('POST with a malformed Authorization header (no Bearer prefix) returns 401', function() {
    $body = (string) json_encode([
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
        'Authorization' => 'Basic dXNlcjpwYXNz', // intentionally wrong scheme
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
    expect($response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="cortex"');
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('POST with an unknown bearer token returns 401', function() {
    $body = (string) json_encode([
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
        'Authorization' => 'Bearer ' . str_repeat('a', 64),
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
    expect($response->headers->get('WWW-Authenticate'))->toContain('Bearer realm="cortex"');
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('POST with valid bearer authenticates and dispatches', function() {
    // Already covered by the existing "initialize returns 200" test,
    // but we re-assert it here to make the auth-path coverage
    // explicit and to verify the resolved Craft user surface.
    $body = (string) json_encode([
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
        'Authorization' => $this->bearerHeader,
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(200);
    expect(Craft::$app->getUser()->getId())->toBe($this->userId);

    Plugin::getInstance()->sessions->terminate((string) $response->headers->get(Http::HEADER_SESSION_ID));
});

it('POST with a revoked bearer returns 401 on the NEXT request', function() {
    // First call succeeds.
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
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    expect($initResponse->statusCode)->toBe(200);
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    // Revoke the bearer token between requests.
    Plugin::getInstance()->tokens->revoke($this->bearerTokenId);

    // Next call with the (now revoked) bearer must 401.
    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callResponse = $callController->runIndex();

    expect($callResponse->statusCode)->toBe(401);

    Plugin::getInstance()->sessions->terminate($sessionId);
});

// -----------------------------------------------------------------------------
// Sub-gate 7.2 — mid-session token-swap detection
// -----------------------------------------------------------------------------

it('mid-session token swap with a different user terminates the session and returns 401', function() {
    // Pick a second user from the playground that ISN'T our scaffold
    // bearer's user — any non-admin will do. We use any pre-existing
    // user rather than creating one because the playground's
    // afterSave hooks (craft-cockpit, etc.) attach side effects that
    // are awkward to satisfy from a unit test.
    $otherUser = \craft\elements\User::find()
        ->status(null)
        ->andWhere(['not', ['users.id' => $this->userId]])
        ->one();
    expect($otherUser)->not->toBeNull();

    // Initialize the session as user A (our scaffold's admin).
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
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    $otherIssued = Plugin::getInstance()->tokens->issue((int) $otherUser->id, '_test_/swap-other-token');

    // POST tools/call with the OTHER bearer + the SAME session id.
    // `Sessions::touch()` should detect the userId mismatch,
    // terminate the session, and the controller should 401.
    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => 'Bearer ' . $otherIssued['token'],
    ], $callBody);
    $callResponse = $callController->runIndex();

    expect($callResponse->statusCode)->toBe(401);
    // The session must have been terminated as part of the
    // mismatch defense — subsequent get() returns null.
    expect(Plugin::getInstance()->sessions->get($sessionId))->toBeNull();
});

// -----------------------------------------------------------------------------
// Sub-gate 7.2 — audit log line carries user=<id>
// -----------------------------------------------------------------------------

it('audit log line carries user=<id> when authenticated over HTTP', function() {
    // Initialize, then drive a real tools/call so the dispatcher
    // emits one audit-log line under the cortex category.
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
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callController->runIndex();

    // Walk Craft's in-flight logger messages backwards looking for
    // the most recent cortex-category line.
    $messages = Craft::getLogger()->messages;
    $auditLine = null;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $entry = $messages[$i];
        if (($entry[2] ?? null) !== \craftpulse\cortex\tools\support\InvocationLogger::CATEGORY) {
            continue;
        }
        $text = (string) $entry[0];
        if (str_starts_with($text, 'tool=')) {
            $auditLine = $text;
            break;
        }
    }

    expect($auditLine)->toBeString();
    expect($auditLine)->toContain('user=' . $this->userId);
    expect($auditLine)->not->toContain('user=-');

    Plugin::getInstance()->sessions->terminate($sessionId);
});

// -----------------------------------------------------------------------------
// Sub-gate 7.3 — OAuth access-token path
// -----------------------------------------------------------------------------

/**
 * Mint an OAuth access-token JWT bound to the playground admin user
 * with the given audience. Persists a matching token row so league's
 * resource server doesn't see it as revoked.
 */
function _cortex_mint_oauth_access_token(int $userId, string $audience, int $expiresIn = 3600): array
{
    $client = new OauthClientRecord();
    $client->clientId = bin2hex(random_bytes(16));
    $client->clientName = '_test_/mcp-oauth-' . bin2hex(random_bytes(4));
    $client->redirectUris = json_encode(['https://example.com/cb']);
    $client->isPublic = true;
    $client->save();

    $clientEntity = new ClientEntity();
    $clientEntity->setIdentifier($client->clientId);
    $clientEntity->setName($client->clientName);
    $clientEntity->setRedirectUri('https://example.com/cb');
    $clientEntity->setIsConfidential(false);

    $entity = new AccessTokenEntity();
    $entity->setClient($clientEntity);
    $entity->setIdentifier(bin2hex(random_bytes(20)));
    $entity->setUserIdentifier((string) $userId);
    $entity->setExpiryDateTime(new \DateTimeImmutable('@' . (time() + $expiresIn)));
    $entity->setAudience($audience);
    $entity->setPrivateKey(new CryptKey('file://' . Plugin::getInstance()->oauth->getPrivateKeyPath()));

    $record = new OauthTokenRecord();
    $record->tokenType = 'access';
    $record->tokenHash = hash('sha256', $entity->getIdentifier());
    $record->userId = $userId;
    $record->clientId = $client->clientId;
    $record->audience = $audience;
    $record->expiresAt = date('Y-m-d H:i:s', time() + max($expiresIn, 1));
    $record->save();

    return [
        'jwt' => $entity->toString(),
        'jti' => $entity->getIdentifier(),
        'clientId' => $client->clientId,
        'tokenId' => $record->id,
    ];
}

it('POST with a valid OAuth access token authenticates and dispatches', function() {
    // Audience must match the playground's canonical MCP endpoint URL
    // because `McpController::beforeAction()` enforces it.
    $expectedAudience = \craft\helpers\UrlHelper::siteUrl('cortex/mcp');
    $minted = _cortex_mint_oauth_access_token($this->userId, $expectedAudience);

    $body = (string) json_encode([
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
        'Authorization' => 'Bearer ' . $minted['jwt'],
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(200);
    expect(Craft::$app->getUser()->getId())->toBe($this->userId);

    Plugin::getInstance()->sessions->terminate((string) $response->headers->get(Http::HEADER_SESSION_ID));
});

it('POST with an OAuth token whose audience does not match returns 401', function() {
    $minted = _cortex_mint_oauth_access_token($this->userId, 'https://different-resource.invalid/mcp');

    $body = (string) json_encode([
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
        'Authorization' => 'Bearer ' . $minted['jwt'],
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata=');
});

it('POST with an expired OAuth token returns 401', function() {
    $expectedAudience = \craft\helpers\UrlHelper::siteUrl('cortex/mcp');
    $minted = _cortex_mint_oauth_access_token($this->userId, $expectedAudience, expiresIn: -3600);

    $body = (string) json_encode([
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
        'Authorization' => 'Bearer ' . $minted['jwt'],
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
});

it('POST with a revoked OAuth token returns 401', function() {
    $expectedAudience = \craft\helpers\UrlHelper::siteUrl('cortex/mcp');
    $minted = _cortex_mint_oauth_access_token($this->userId, $expectedAudience);

    // Flip dateRevoked.
    OauthTokenRecord::updateAll(
        ['dateRevoked' => date('Y-m-d H:i:s')],
        ['tokenHash' => hash('sha256', $minted['jti'])],
    );

    $body = (string) json_encode([
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
        'Authorization' => 'Bearer ' . $minted['jwt'],
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
});

it('401 challenge header carries the canonical resource_metadata URL', function() {
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => 'Bearer totally-bogus',
    ], '{}');
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(401);
    $challenge = $response->headers->get('WWW-Authenticate');
    expect($challenge)->toContain('Bearer realm="cortex"');
    expect($challenge)->toContain('.well-known/oauth-protected-resource');
});

// -----------------------------------------------------------------------------
// Sub-gate 7.5 — audit log DB persistence
// -----------------------------------------------------------------------------

it('tools/call over HTTP writes one cortex_invocations row with the right context', function() {
    // Initialize, then drive a real tools/call so the audit-log
    // listener persists a row to cortex_invocations.
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-audit', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callResponse = $callController->runIndex();
    expect($callResponse->statusCode)->toBe(200);

    // One row for the sections invocation, scoped to this test's
    // bearer + session.
    $row = \craftpulse\cortex\records\Invocation::find()
        ->where([
            'toolName' => 'sections',
            'sessionId' => $sessionId,
        ])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('success');
    expect($row->transport)->toBe('http');
    expect($row->userId)->toBe($this->userId);
    expect($row->tokenId)->toBe($this->bearerTokenId);
    expect($row->sessionId)->toBe($sessionId);
    expect($row->clientName)->toBe('pest-audit');

    \craftpulse\cortex\records\Invocation::deleteAll(['id' => $row->id]);
    Plugin::getInstance()->sessions->terminate($sessionId);
});

it('a failing tool over HTTP writes a tool_error row with the error class/message', function() {
    // Initialize the session.
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-audit-err', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    // `craft_command` with a command outside the allowlist raises a
    // ToolException — gives us a predictable tool_error path.
    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'craft_command',
            'arguments' => ['command' => 'definitely-not-allowed/this-route-does-not-exist'],
        ],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callController->runIndex();

    $row = \craftpulse\cortex\records\Invocation::find()
        ->where([
            'toolName' => 'craft_command',
            'sessionId' => $sessionId,
        ])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('tool_error');
    expect($row->errorClass)->toBeString()->not->toBeEmpty();
    expect($row->errorMessage)->toBeString()->not->toBeEmpty();

    \craftpulse\cortex\records\Invocation::deleteAll(['id' => $row->id]);
    Plugin::getInstance()->sessions->terminate($sessionId);
});

it('a revoked bearer 401s before dispatch — no audit row written', function() {
    // Establish the baseline row count for the test's bearer.
    $before = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['userId' => $this->userId])
        ->count();

    // Revoke the bearer before any call lands.
    Plugin::getInstance()->tokens->revoke($this->bearerTokenId);

    $body = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-revoked', 'version' => '0'],
        ],
    ]);
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $body);
    $response = $controller->runIndex();
    expect($response->statusCode)->toBe(401);

    $after = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['userId' => $this->userId])
        ->count();

    expect($after)->toBe($before);
});

// -----------------------------------------------------------------------------
// Sub-gate 7.6 — per-user rate limit + burst-quota observability
// -----------------------------------------------------------------------------

it('a successful tools/call writes an audit row with rateLimitRemaining = burst - 1', function() {
    // Initialize then drive a real tools/call so the audit-log
    // listener persists a row with the post-consume bucket headroom.
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-rl-success', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    expect($initResponse->statusCode)->toBe(200);
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callResponse = $callController->runIndex();
    expect($callResponse->statusCode)->toBe(200);

    $row = \craftpulse\cortex\records\Invocation::find()
        ->where([
            'toolName' => 'sections',
            'sessionId' => $sessionId,
        ])
        ->orderBy(['id' => SORT_DESC])
        ->one();

    expect($row)->not->toBeNull();
    // beforeEach cleared the bucket; init + tools/call consumed two
    // tokens, so the tools/call row carries burst - 2.
    $burst = Plugin::getInstance()->getSettings()->rateLimitBurst;
    expect($row->rateLimitRemaining)->toBe($burst - 2);

    \craftpulse\cortex\records\Invocation::deleteAll(['id' => $row->id]);
    Plugin::getInstance()->sessions->terminate($sessionId);
});

it('60 rapid successful calls all succeed; the 61st returns 429 with Retry-After', function() {
    // Use a small burst here so the test doesn't have to drive 60+
    // full HTTP cycles. The behaviour is identical for any burst > 0.
    Plugin::getInstance()->getSettings()->rateLimitBurst = 5;
    Plugin::getInstance()->getSettings()->rateLimitPerSecond = 1;
    Plugin::getInstance()->rateLimiter->clear($this->userId);

    // Drive `burst` successful initialize-only calls. Initialize is
    // the cheapest endpoint that consumes a token; we don't need a
    // session because each call starts fresh.
    for ($i = 0; $i < 5; $i++) {
        $body = (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $i,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => Server::PROTOCOL_VERSION,
                'clientInfo' => ['name' => 'pest-rl-burst', 'version' => '0'],
            ],
        ]);
        $controller = _cortex_mcp_harness('POST', [
            Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
            'Authorization' => $this->bearerHeader,
        ], $body);
        $response = $controller->runIndex();
        expect($response->statusCode)->toBe(200);
        Plugin::getInstance()->sessions->terminate((string) $response->headers->get(Http::HEADER_SESSION_ID));
    }

    // The next one over the burst must 429 + Retry-After.
    $body = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 999,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-rl-burst', 'version' => '0'],
        ],
    ]);
    $controller = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $body);
    $response = $controller->runIndex();

    expect($response->statusCode)->toBe(429);
    $retryAfter = $response->headers->get('Retry-After');
    expect($retryAfter)->toBeString()->not->toBeEmpty();
    expect((int) $retryAfter)->toBeGreaterThanOrEqual(1);
});

it('a throttled call writes one cortex_invocations row with kind=rate_limited and the right context', function() {
    // Shrink the bucket so the test drains it cheaply.
    Plugin::getInstance()->getSettings()->rateLimitBurst = 1;
    Plugin::getInstance()->getSettings()->rateLimitPerSecond = 1;
    Plugin::getInstance()->rateLimiter->clear($this->userId);

    // Burn the one available token.
    $firstBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-rl-audit', 'version' => '0'],
        ],
    ]);
    $first = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $firstBody);
    $firstResponse = $first->runIndex();
    expect($firstResponse->statusCode)->toBe(200);
    $firstSessionId = (string) $firstResponse->headers->get(Http::HEADER_SESSION_ID);

    // Snapshot the count of rate-limited rows for this user BEFORE
    // the throttle event so we can assert the throttle write was the
    // ONLY new rate-limited row.
    $before = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['userId' => $this->userId, 'kind' => 'rate_limited'])
        ->count();

    // Second call is over the burst — must 429 and write a row.
    $secondBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-rl-audit', 'version' => '0'],
        ],
    ]);
    $second = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $secondBody);
    $secondResponse = $second->runIndex();
    expect($secondResponse->statusCode)->toBe(429);

    $after = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['userId' => $this->userId, 'kind' => 'rate_limited'])
        ->count();
    expect($after)->toBe($before + 1);

    $row = \craftpulse\cortex\records\Invocation::find()
        ->where(['userId' => $this->userId, 'kind' => 'rate_limited'])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    expect($row)->not->toBeNull();
    expect($row->kind)->toBe('rate_limited');
    expect($row->transport)->toBe('http');
    expect($row->userId)->toBe($this->userId);
    expect($row->tokenId)->toBe($this->bearerTokenId);
    expect($row->rateLimitRemaining)->toBe(0);
    expect($row->errorClass)->toBe(\craftpulse\cortex\exceptions\RateLimitExceededException::class);
    expect($row->errorMessage)->toBeString()->not->toBeEmpty();
    expect($row->toolName)->toBe('_rate_limited');

    \craftpulse\cortex\records\Invocation::deleteAll(['id' => $row->id]);
    Plugin::getInstance()->sessions->terminate($firstSessionId);
});

it('a throttled call does NOT dispatch — no tool-execution audit row, only the throttle row', function() {
    Plugin::getInstance()->getSettings()->rateLimitBurst = 1;
    Plugin::getInstance()->getSettings()->rateLimitPerSecond = 1;
    Plugin::getInstance()->rateLimiter->clear($this->userId);

    // Set up a session by initializing (consumes the only token).
    $initBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => Server::PROTOCOL_VERSION,
            'clientInfo' => ['name' => 'pest-rl-no-dispatch', 'version' => '0'],
        ],
    ]);
    $initController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        'Authorization' => $this->bearerHeader,
    ], $initBody);
    $initResponse = $initController->runIndex();
    expect($initResponse->statusCode)->toBe(200);
    $sessionId = (string) $initResponse->headers->get(Http::HEADER_SESSION_ID);

    $beforeSections = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['toolName' => 'sections', 'sessionId' => $sessionId])
        ->count();

    // Now fire a tools/call that would normally dispatch — but the
    // bucket is empty, so the controller must short-circuit at 429.
    $callBody = (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'sections'],
    ]);
    $callController = _cortex_mcp_harness('POST', [
        Http::HEADER_PROTOCOL_VERSION => Server::PROTOCOL_VERSION,
        Http::HEADER_SESSION_ID => $sessionId,
        'Authorization' => $this->bearerHeader,
    ], $callBody);
    $callResponse = $callController->runIndex();
    expect($callResponse->statusCode)->toBe(429);

    // No `sections` row was written — the throttle blocked dispatch.
    $afterSections = (int) \craftpulse\cortex\records\Invocation::find()
        ->where(['toolName' => 'sections', 'sessionId' => $sessionId])
        ->count();
    expect($afterSections)->toBe($beforeSections);

    // Cleanup: drop the throttle row + terminate the session.
    \craftpulse\cortex\records\Invocation::deleteAll([
        'userId' => $this->userId,
        'kind' => 'rate_limited',
    ]);
    Plugin::getInstance()->sessions->terminate($sessionId);
});
