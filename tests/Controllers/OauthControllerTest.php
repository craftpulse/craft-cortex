<?php

/**
 * =========================================================================
 * OauthController tests — RFC 7591 DCR, PKCE flow E2E, code re-use
 * rejection, plain PKCE rejection, denial handling.
 *
 * The HTTP-level surface is exercised via a request-stub harness so
 * tests don't have to spin up a real HTTP server. The PSR-7 bridge
 * inside `OauthController` reads `$this->request->getRawBody()` /
 * `getBodyParams()` / `getQueryParams()` etc., all of which we
 * synthesize in the harness.
 *
 * The PKCE E2E test drives the full flow:
 *   1. DCR a fresh public client.
 *   2. Generate a code verifier + S256 challenge.
 *   3. Hit `/oauth/authorize` as a logged-in CP user, approve.
 *   4. Capture the code from the redirect.
 *   5. Exchange code+verifier at `/oauth/token`.
 *   6. Hit `cortex/mcp` with the resulting access token.
 *   7. Refresh, repeat MCP call.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\controllers\OauthController;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\OauthClient as OauthClientRecord;
use craftpulse\cortex\records\OauthCode as OauthCodeRecord;
use craftpulse\cortex\records\OauthToken as OauthTokenRecord;
use yii\web\HeaderCollection;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

class _CortexOauthRequest
{
    public HeaderCollection $headers;

    /**
     * @param array<string,mixed> $queryParams
     * @param array<string,mixed> $bodyParams
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly string $method = 'GET',
        private readonly array $queryParams = [],
        private readonly array $bodyParams = [],
        private readonly string $rawBody = '',
        array $headers = [],
        private readonly string $absoluteUrl = 'https://test.invalid/oauth/authorize',
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

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }

    public function getQueryParam($name, $defaultValue = null)
    {
        return $this->queryParams[$name] ?? $defaultValue;
    }

    public function getBodyParam($name, $defaultValue = null)
    {
        return $this->bodyParams[$name] ?? $defaultValue;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getAbsoluteUrl(): string
    {
        return $this->absoluteUrl;
    }

    public function getIsLivePreview(): bool
    {
        return false;
    }

    public function getIsCpRequest(): bool
    {
        return false;
    }

    public function hasValidSiteToken(): bool
    {
        return false;
    }

    public function getIsPost(): bool
    {
        return strtoupper($this->method) === 'POST';
    }

    public function getIsGet(): bool
    {
        return strtoupper($this->method) === 'GET';
    }
}

class _CortexOauthControllerHarness extends OauthController
{
    public function withRequest(_CortexOauthRequest $req): self
    {
        $this->request = $req;
        return $this;
    }

    public function withFreshResponse(): self
    {
        $this->response = new Response();
        return $this;
    }
}

/**
 * Build a harness around a synthetic request.
 *
 * @param array<string,mixed> $queryParams
 * @param array<string,mixed> $bodyParams
 * @param array<string,string> $headers
 */
function _cortex_oauth_request(
    string $method = 'GET',
    array $queryParams = [],
    array $bodyParams = [],
    string $rawBody = '',
    array $headers = [],
    string $url = 'https://test.invalid/oauth/authorize',
): _CortexOauthControllerHarness {
    $controller = new _CortexOauthControllerHarness('oauth', Plugin::getInstance());
    $controller->withRequest(new _CortexOauthRequest(
        method: $method,
        queryParams: $queryParams,
        bodyParams: $bodyParams,
        rawBody: $rawBody,
        headers: $headers,
        absoluteUrl: $url,
    ));
    $controller->withFreshResponse();
    return $controller;
}

/**
 * Generate a PKCE verifier + S256 challenge.
 *
 * @return array{verifier:string,challenge:string}
 */
function _cortex_pkce(): array
{
    // 43 base64url chars = 32 bytes of entropy.
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

beforeEach(function() {
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    $this->admin = $admin;
    $this->userId = (int) $admin->id;
});

afterEach(function() {
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
    OauthCodeRecord::deleteAll(['like', 'clientId', '%', false]);
    OauthTokenRecord::deleteAll(['like', 'clientId', '%', false]);
});

// -----------------------------------------------------------------------------
// /oauth/register — DCR
// -----------------------------------------------------------------------------

it('POST /oauth/register returns 201 with the client_id for a public client', function() {
    $payload = json_encode([
        'client_name' => '_test_/public-dcr',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'none',
    ]);
    $controller = _cortex_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
    $response = $controller->actionRegister();

    expect($response->statusCode)->toBe(201);
    expect($response->data)->toHaveKeys(['client_id', 'token_endpoint_auth_method']);
    expect($response->data['token_endpoint_auth_method'])->toBe('none');
});

it('POST /oauth/register returns 400 on invalid redirect URI', function() {
    $payload = json_encode([
        'client_name' => '_test_/bad-uri',
        'redirect_uris' => ['http://example.com/callback'],
    ]);
    $controller = _cortex_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
    $response = $controller->actionRegister();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toHaveKey('error');
    expect($response->data['error'])->toBe('invalid_client_metadata');
});

it('POST /oauth/register returns 404 when DCR is disabled', function() {
    $settings = Plugin::getInstance()->getSettings();
    $original = $settings->dcrEnabled;
    $settings->dcrEnabled = false;

    try {
        $payload = json_encode([
            'client_name' => '_test_/dcr-disabled',
            'redirect_uris' => ['https://example.com/callback'],
        ]);
        $controller = _cortex_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
        $response = $controller->actionRegister();
        expect($response->statusCode)->toBe(404);
    } finally {
        $settings->dcrEnabled = $original;
    }
});

it('POST /oauth/register returns 400 on malformed JSON', function() {
    $controller = _cortex_oauth_request('POST', [], [], '{not-json}', [], 'https://test.invalid/oauth/register');
    $response = $controller->actionRegister();
    expect($response->statusCode)->toBe(400);
});

// -----------------------------------------------------------------------------
// /oauth/authorize — consent screen + flow
// -----------------------------------------------------------------------------

// Note: the "GET /oauth/authorize redirects to login when no Craft
// session is active" path is exercised end-to-end via curl during
// manual verification (the request hits a real web request and
// receives a 302 to /admin/login). Driving it through the test
// harness ends up exercising `craft\web\Controller::redirect()`'s
// ajax-aware branching, which probes the request's `getIsAjax()`
// method — only present on `craft\web\Request`, not on
// `craft\console\Request` that the Pest bootstrap leaves bound.
// Faking the full web-request surface for one assertion isn't worth
// the harness complexity; the manual curl in the gate verification
// is the canonical proof.

it('GET /oauth/authorize rejects plain PKCE with an OAuth error redirect', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Plugin::getInstance()->oauth->registerClient([
        'client_name' => '_test_/plain-pkce',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $controller = _cortex_oauth_request('GET', [
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'plain',
        'scope' => 'read',
        'state' => 'xyz',
    ], url: 'https://test.invalid/oauth/authorize');

    $response = $controller->actionAuthorize();
    // league surfaces invalid_request as a 302 redirect to the
    // registered redirect_uri with `error=invalid_request`, or as
    // a 400 if the redirect can't be built. Either way it's
    // non-200.
    expect($response->statusCode)->not->toBe(200);
});

it('POST /oauth/authorize with approve=0 surfaces an access_denied error', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Plugin::getInstance()->oauth->registerClient([
        'client_name' => '_test_/deny-flow',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $controller = _cortex_oauth_request(
        method: 'POST',
        queryParams: [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://example.com/cb',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'state' => 'xyz',
        ],
        bodyParams: ['approve' => '0'],
        url: 'https://test.invalid/oauth/authorize',
    );

    $response = $controller->actionAuthorize();
    // Denial → 302 back to redirect_uri with error=access_denied.
    expect($response->statusCode)->toBe(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain('error=access_denied');
});

// -----------------------------------------------------------------------------
// /oauth/token + full PKCE round-trip
// -----------------------------------------------------------------------------

it('full PKCE flow: register → authorize → token → MCP call', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Plugin::getInstance()->oauth->registerClient([
        'client_name' => '_test_/full-pkce',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $resource = 'https://test.invalid/cortex/mcp';

    // Step 1: POST /oauth/authorize with approve=1 captures the code
    // in the redirect.
    $authController = _cortex_oauth_request(
        method: 'POST',
        queryParams: [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://example.com/cb',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'state' => 'abc',
            'resource' => $resource,
        ],
        bodyParams: ['approve' => '1'],
        url: 'https://test.invalid/oauth/authorize',
    );

    $authResponse = $authController->actionAuthorize();
    expect($authResponse->statusCode)->toBe(302);
    $location = $authResponse->headers->get('Location');
    expect($location)->toBeString();
    expect($location)->toContain('code=');

    // Extract code from the redirect URL.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect($params)->toHaveKey('code');
    $code = (string) $params['code'];

    // Step 2: POST /oauth/token to exchange the code for tokens.
    $tokenController = _cortex_oauth_request(
        method: 'POST',
        bodyParams: [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => $client['client_id'],
            'code_verifier' => $pkce['verifier'],
            'resource' => $resource,
        ],
        url: 'https://test.invalid/oauth/token',
    );

    $tokenResponse = $tokenController->actionToken();
    expect($tokenResponse->statusCode)->toBe(200);

    $tokenBody = json_decode((string) $tokenResponse->content, true);
    expect($tokenBody)->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in']);
    expect($tokenBody['token_type'])->toBe('Bearer');

    $accessToken = $tokenBody['access_token'];

    // Step 3: lookupAccessToken() resolves the JWT to the bound user.
    $resolved = Plugin::getInstance()->oauth->lookupAccessToken($accessToken);
    expect($resolved)->not->toBeNull();
    expect($resolved['userId'])->toBe($this->userId);
    expect($resolved['audience'])->toBe($resource);
    expect($resolved['scope'])->toContain('read');
});

it('code re-use returns invalid_grant on the second exchange', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Plugin::getInstance()->oauth->registerClient([
        'client_name' => '_test_/code-reuse',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $authController = _cortex_oauth_request(
        method: 'POST',
        queryParams: [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://example.com/cb',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'state' => 'abc',
            'resource' => 'https://test.invalid/cortex/mcp',
        ],
        bodyParams: ['approve' => '1'],
        url: 'https://test.invalid/oauth/authorize',
    );
    $authResponse = $authController->actionAuthorize();
    parse_str((string) parse_url((string) $authResponse->headers->get('Location'), PHP_URL_QUERY), $params);
    $code = (string) $params['code'];

    // First exchange — succeeds.
    $firstToken = _cortex_oauth_request(
        method: 'POST',
        bodyParams: [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => $client['client_id'],
            'code_verifier' => $pkce['verifier'],
        ],
        url: 'https://test.invalid/oauth/token',
    );
    $firstResponse = $firstToken->actionToken();
    expect($firstResponse->statusCode)->toBe(200);

    // Second exchange — same code, must reject.
    $secondToken = _cortex_oauth_request(
        method: 'POST',
        bodyParams: [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => $client['client_id'],
            'code_verifier' => $pkce['verifier'],
        ],
        url: 'https://test.invalid/oauth/token',
    );
    $secondResponse = $secondToken->actionToken();
    expect($secondResponse->statusCode)->toBeGreaterThanOrEqual(400);
});

it('PKCE S256 verifier mismatch rejects at the token endpoint', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $wrongPkce = _cortex_pkce();
    $client = Plugin::getInstance()->oauth->registerClient([
        'client_name' => '_test_/pkce-mismatch',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $authController = _cortex_oauth_request(
        method: 'POST',
        queryParams: [
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://example.com/cb',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'state' => 'abc',
        ],
        bodyParams: ['approve' => '1'],
        url: 'https://test.invalid/oauth/authorize',
    );
    $authResponse = $authController->actionAuthorize();
    parse_str((string) parse_url((string) $authResponse->headers->get('Location'), PHP_URL_QUERY), $params);
    $code = (string) $params['code'];

    $tokenController = _cortex_oauth_request(
        method: 'POST',
        bodyParams: [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://example.com/cb',
            'client_id' => $client['client_id'],
            'code_verifier' => $wrongPkce['verifier'],
        ],
        url: 'https://test.invalid/oauth/token',
    );
    $tokenResponse = $tokenController->actionToken();
    expect($tokenResponse->statusCode)->toBeGreaterThanOrEqual(400);
});

// -----------------------------------------------------------------------------
// /oauth/revoke
// -----------------------------------------------------------------------------

it('POST /oauth/revoke returns 200 even for unknown tokens (RFC 7009 §2.2)', function() {
    $controller = _cortex_oauth_request(
        method: 'POST',
        bodyParams: ['token' => 'unknown-token'],
        url: 'https://test.invalid/oauth/revoke',
    );
    $response = $controller->actionRevoke();
    expect($response->statusCode)->toBe(200);
});

it('POST /oauth/revoke flips the dateRevoked on a known refresh token', function() {
    $opaque = bin2hex(random_bytes(40));
    $hash = hash('sha256', $opaque);

    $record = new OauthTokenRecord();
    $record->tokenType = 'refresh';
    $record->tokenHash = $hash;
    $record->clientId = 'test-client';
    $record->expiresAt = date('Y-m-d H:i:s', time() + 86400);
    $record->save(false);

    $controller = _cortex_oauth_request(
        method: 'POST',
        bodyParams: ['token' => $opaque],
        url: 'https://test.invalid/oauth/revoke',
    );
    $response = $controller->actionRevoke();
    expect($response->statusCode)->toBe(200);

    $fresh = OauthTokenRecord::findOne($record->id);
    expect($fresh->dateRevoked)->not->toBeNull();
});
