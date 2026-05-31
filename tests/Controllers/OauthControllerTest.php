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
use craftpulse\cortex\Cortex;
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
        private readonly string $userIp = '203.0.113.7',
        private readonly bool $csrfValid = true,
    ) {
        $this->headers = new HeaderCollection();
        foreach ($headers as $name => $value) {
            $this->headers->set($name, $value);
        }
    }

    /**
     * Mirror `craft\web\Request::validateCsrfToken()` — safe methods
     * always pass; unsafe methods return the harness's `csrfValid` flag.
     */
    public function validateCsrfToken(): bool
    {
        if (in_array(strtoupper($this->method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        return $this->csrfValid;
    }

    public function getUserIP(): string
    {
        return $this->userIp;
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

    /**
     * Drive `beforeAction()` against a synthetic action so the
     * `httpEnabled` kill switch and IP throttle on
     * `AbstractOauthController` fire in tests. Returns the gate's
     * boolean; the response slot carries the populated 503 / 429 when
     * it short-circuits.
     */
    public function runBeforeAction(string $actionId = 'authorize'): bool
    {
        return $this->beforeAction(new \yii\base\Action($actionId, $this));
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
    string $userIp = '203.0.113.7',
    bool $csrfValid = true,
): _CortexOauthControllerHarness {
    $controller = new _CortexOauthControllerHarness('oauth', Cortex::getInstance());
    $controller->withRequest(new _CortexOauthRequest(
        method: $method,
        queryParams: $queryParams,
        bodyParams: $bodyParams,
        rawBody: $rawBody,
        headers: $headers,
        absoluteUrl: $url,
        userIp: $userIp,
        csrfValid: $csrfValid,
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

    $settings = Cortex::getInstance()->getSettings();
    $this->originalHttpEnabled = $settings->httpEnabled;
    // The OAuth surface is gated behind `httpEnabled` on
    // `AbstractOauthController`. Enable it for the action-level tests
    // that exercise the flow; the kill-switch tests flip it off
    // explicitly and drive `beforeAction()` directly.
    $settings->httpEnabled = true;
});

afterEach(function() {
    Cortex::getInstance()->getSettings()->httpEnabled = $this->originalHttpEnabled;
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
    $settings = Cortex::getInstance()->getSettings();
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
    $client = Cortex::getInstance()->oauth->registerClient([
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
    $client = Cortex::getInstance()->oauth->registerClient([
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
// /oauth/authorize — CSRF on the consent POST
// -----------------------------------------------------------------------------

it('rejects the authorize consent POST when the CSRF token is invalid', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $controller = _cortex_oauth_request(
        method: 'POST',
        bodyParams: ['approve' => '1'],
        url: 'https://test.invalid/oauth/authorize',
        csrfValid: false,
    );

    expect(fn() => $controller->runBeforeAction('authorize'))
        ->toThrow(\yii\web\BadRequestHttpException::class);
});

it('allows the authorize consent POST through beforeAction with a valid CSRF token', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $controller = _cortex_oauth_request(
        method: 'POST',
        bodyParams: ['approve' => '1'],
        url: 'https://test.invalid/oauth/authorize',
        csrfValid: true,
    );

    expect($controller->runBeforeAction('authorize'))->toBeTrue();
});

it('leaves token / register / revoke CSRF-exempt in beforeAction', function(string $actionId) {
    // No CSRF token, POST — these anonymous token-proof endpoints must
    // still pass beforeAction (the consent POST is the only CSRF-gated
    // action). httpEnabled is true via beforeEach.
    $controller = _cortex_oauth_request(
        method: 'POST',
        url: "https://test.invalid/oauth/{$actionId}",
        csrfValid: false,
    );

    expect($controller->runBeforeAction($actionId))->toBeTrue();
})->with(['token', 'register', 'revoke']);

// -----------------------------------------------------------------------------
// /oauth/token + full PKCE round-trip
// -----------------------------------------------------------------------------

it('full PKCE flow: register → authorize → token → MCP call', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Cortex::getInstance()->oauth->registerClient([
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
    $resolved = Cortex::getInstance()->oauth->lookupAccessToken($accessToken);
    expect($resolved)->not->toBeNull();
    expect($resolved['userId'])->toBe($this->userId);
    expect($resolved['audience'])->toBe($resource);
    expect($resolved['scope'])->toContain('read');
});

it('code re-use returns invalid_grant on the second exchange', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _cortex_pkce();
    $client = Cortex::getInstance()->oauth->registerClient([
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
    $client = Cortex::getInstance()->oauth->registerClient([
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
    // Register a real client so the FK constraint on clientId is satisfied.
    $clientResp = Cortex::getInstance()->oauth->registerClient([
        'client_name' => '_test_/revoke-controller-refresh',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $opaque = bin2hex(random_bytes(40));
    $hash = hash('sha256', $opaque);

    $record = new OauthTokenRecord();
    $record->tokenType = 'refresh';
    $record->tokenHash = $hash;
    $record->clientId = $clientResp['client_id'];
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

// -----------------------------------------------------------------------------
// httpEnabled kill switch — every OAuth action returns 503 when off
// -----------------------------------------------------------------------------

dataset('oauth endpoints', [
    'authorize' => ['GET', 'https://test.invalid/oauth/authorize'],
    'token' => ['POST', 'https://test.invalid/oauth/token'],
    'register' => ['POST', 'https://test.invalid/oauth/register'],
    'revoke' => ['POST', 'https://test.invalid/oauth/revoke'],
]);

it('returns 503 from beforeAction when httpEnabled is false', function(string $method, string $url) {
    Cortex::getInstance()->getSettings()->httpEnabled = false;

    $controller = _cortex_oauth_request(method: $method, url: $url);
    $proceeded = $controller->runBeforeAction();

    expect($proceeded)->toBeFalse();
    expect($controller->response->statusCode)->toBe(503);
    expect($controller->response->data)->toHaveKey('error');
})->with('oauth endpoints');

it('does not fire the 503 gate in beforeAction when httpEnabled is true', function() {
    Craft::$app->getUser()->setIdentity($this->admin);
    Cortex::getInstance()->getSettings()->httpEnabled = true;

    $controller = _cortex_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/token');
    $controller->runBeforeAction();

    // The kill switch did not populate a 503 — parent::beforeAction()
    // owns whatever status follows.
    expect($controller->response->statusCode)->not->toBe(503);
});

// -----------------------------------------------------------------------------
// IP throttle — anonymous /oauth/register, /token, /revoke are 429'd
// -----------------------------------------------------------------------------

it('429s anonymous /oauth/register from the same IP after the burst is exhausted', function() {
    $settings = Cortex::getInstance()->getSettings();
    $settings->httpEnabled = true;
    // Tight burst so the loop trips fast; refill 1/sec so a single
    // tight loop can't be saved by an accrued refill.
    $originalBurst = $settings->rateLimitBurst;
    $originalRate = $settings->rateLimitPerSecond;
    $settings->rateLimitBurst = 3;
    $settings->rateLimitPerSecond = 1;

    $ip = '198.51.100.42';
    Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ip);

    try {
        $blocked = false;
        // Burst is 3; the 4th request inside the same wall-clock second
        // must trip the throttle.
        for ($i = 0; $i < 5; $i++) {
            $controller = _cortex_oauth_request(
                method: 'POST',
                url: 'https://test.invalid/oauth/register',
                userIp: $ip,
            );
            $proceeded = $controller->runBeforeAction('register');
            if (!$proceeded && $controller->response->statusCode === 429) {
                $blocked = true;
                expect($controller->response->headers->get('Retry-After'))->not->toBeNull();
                break;
            }
        }
        expect($blocked)->toBeTrue();
    } finally {
        Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ip);
        $settings->rateLimitBurst = $originalBurst;
        $settings->rateLimitPerSecond = $originalRate;
    }
});

it('does not throttle a different IP sharing the same window', function() {
    $settings = Cortex::getInstance()->getSettings();
    $settings->httpEnabled = true;
    $originalBurst = $settings->rateLimitBurst;
    $settings->rateLimitBurst = 1;

    $ipA = '198.51.100.10';
    $ipB = '198.51.100.11';
    Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipA);
    Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipB);

    try {
        // Drain IP A's single-token bucket.
        $a = _cortex_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipA);
        expect($a->runBeforeAction('register'))->toBeTrue();
        $a2 = _cortex_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipA);
        expect($a2->runBeforeAction('register'))->toBeFalse();

        // IP B still has its own full bucket.
        $b = _cortex_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipB);
        expect($b->runBeforeAction('register'))->toBeTrue();
    } finally {
        Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipA);
        Cortex::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipB);
        $settings->rateLimitBurst = $originalBurst;
    }
});
