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
 *   6. Hit `herald/mcp` with the resulting access token.
 *   7. Refresh, repeat MCP call.
 *
 * Every consent POST goes through `_herald_consent_post()`, which
 * reproduces the shape `oauth/authorize.twig` actually submits:
 * authorization parameters in the BODY, empty query string. Passing them
 * as `queryParams` instead tests a request no browser sends and keeps a
 * broken flow green, which is exactly how the body/query split shipped.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\controllers\OauthController;
use craftpulse\herald\Herald;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use craftpulse\herald\records\OauthCode as OauthCodeRecord;
use craftpulse\herald\records\OauthToken as OauthTokenRecord;
use yii\web\HeaderCollection;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

class _HeraldOauthRequest
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

class _HeraldOauthControllerHarness extends OauthController
{
    /**
     * Captured template + variables from the last elevate render, so
     * tests can assert on the outcome without driving CP Twig (which
     * needs a web request the console bootstrap doesn't supply).
     *
     * @var array{template:string,variables:array<string,mixed>}|null
     */
    public ?array $renderedElevate = null;

    public function withRequest(_HeraldOauthRequest $req): self
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
     * Override the CP-Twig render boundary. The auth + mint decision in
     * `_completeElevation()` runs for real; only the HTML rendering is
     * captured here.
     *
     * @param array<string,mixed> $variables
     */
    protected function _renderElevateTemplate(string $template, array $variables): Response
    {
        $this->renderedElevate = ['template' => $template, 'variables' => $variables];
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = $template;
        return $this->response;
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
function _herald_oauth_request(
    string $method = 'GET',
    array $queryParams = [],
    array $bodyParams = [],
    string $rawBody = '',
    array $headers = [],
    string $url = 'https://test.invalid/oauth/authorize',
    string $userIp = '203.0.113.7',
    bool $csrfValid = true,
): _HeraldOauthControllerHarness {
    $controller = new _HeraldOauthControllerHarness('oauth', Herald::getInstance());
    $controller->withRequest(new _HeraldOauthRequest(
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
function _herald_pkce(): array
{
    // 43 base64url chars = 32 bytes of entropy.
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/**
 * Count the authorization codes issued to one client. Scoped to the
 * client under test so the assertion doesn't depend on whatever else the
 * fixtures database happens to hold.
 */
function _herald_code_count(string $clientId): int
{
    return (int) OauthCodeRecord::find()
        ->where(['clientId' => $clientId])
        ->count();
}

/**
 * Build the consent POST in the EXACT shape the shipped
 * `oauth/authorize.twig` produces.
 *
 * That shape is the whole point of this helper, and it is the inverse of
 * what a hand-written harness reaches for: the form action is a bare
 * `url('oauth/authorize')` with no query string, and every original
 * authorization parameter is re-emitted as a hidden BODY field next to
 * `approve`. So the consent POST carries an EMPTY query string, and the
 * authorization parameters live in the body and nowhere else.
 *
 * Every consent-POST test in this file goes through here. A test that
 * passes the authorization parameters as `queryParams` is testing a shape
 * no browser ever submits, and will stay green against a flow that 400s
 * in production. The `posts the authorization parameters as hidden body
 * fields to a bare action URL` test below pins the template to this
 * contract.
 *
 * @param array<string,mixed> $authParams The parameters the consent GET
 *        received, which the template echoes back as hidden fields.
 */
function _herald_consent_post(
    array $authParams,
    string $approve,
    string $url = 'https://test.invalid/oauth/authorize',
): _HeraldOauthControllerHarness {
    return _herald_oauth_request(
        method: 'POST',
        queryParams: [],
        bodyParams: $authParams + ['approve' => $approve],
        url: $url,
    );
}

beforeEach(function() {
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    $this->admin = $admin;
    $this->userId = (int) $admin->id;

    $settings = Herald::getInstance()->getSettings();
    $this->originalHttpEnabled = $settings->httpEnabled;
    // The OAuth surface is gated behind `httpEnabled` on
    // `AbstractOauthController`. Enable it for the action-level tests
    // that exercise the flow; the kill-switch tests flip it off
    // explicitly and drive `beforeAction()` directly.
    $settings->httpEnabled = true;

    // The full authorize → token flow needs an APPROVED client (the
    // WS3 DCR approval gate). Auto-approve registered clients for the
    // file so `registerClient()` lands an approved row; the dedicated
    // approval-gate behaviour is covered in `ClientApprovalTest`.
    $this->originalAutoApprove = $settings->dcrAutoApprove;
    $settings->dcrAutoApprove = true;

    // OAuth serves the Pro-only HTTP transport (Gate 9.7) — pin Pro
    // for the file; the dedicated Free-edition test flips it inline.
    $this->originalEdition = Herald::getInstance()->edition;
    Herald::getInstance()->edition = Herald::EDITION_PRO;
});

afterEach(function() {
    Herald::getInstance()->edition = $this->originalEdition;
    Herald::getInstance()->getSettings()->httpEnabled = $this->originalHttpEnabled;
    Herald::getInstance()->getSettings()->dcrAutoApprove = $this->originalAutoApprove;
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
    $controller = _herald_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
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
    $controller = _herald_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
    $response = $controller->actionRegister();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toHaveKey('error');
    expect($response->data['error'])->toBe('invalid_client_metadata');
});

it('POST /oauth/register returns 404 when DCR is disabled', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->dcrEnabled;
    $settings->dcrEnabled = false;

    try {
        $payload = json_encode([
            'client_name' => '_test_/dcr-disabled',
            'redirect_uris' => ['https://example.com/callback'],
        ]);
        $controller = _herald_oauth_request('POST', [], [], (string) $payload, [], 'https://test.invalid/oauth/register');
        $response = $controller->actionRegister();
        expect($response->statusCode)->toBe(404);
    } finally {
        $settings->dcrEnabled = $original;
    }
});

it('POST /oauth/register returns 400 on malformed JSON', function() {
    $controller = _herald_oauth_request('POST', [], [], '{not-json}', [], 'https://test.invalid/oauth/register');
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

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/plain-pkce',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $controller = _herald_oauth_request('GET', [
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

it('HTML-escapes a script payload in the consent-screen client name', function() {
    // Regression: a DCR-registered `client_name` is rendered into the
    // consent screen. The lead paragraph interpolates it inside a
    // `<span>` and pipes the result through `|raw`, so the client name
    // MUST be HTML-escaped at that boundary or an attacker who can
    // register a client (anonymous when DCR is enabled) lands stored
    // XSS in any logged-in CP user's session on GET, before approval.
    //
    // The full consent GET cannot be driven through the Pest harness —
    // the template's `craft.app.request.csrfToken` needs a web request,
    // and the bootstrap leaves a console request bound (same constraint
    // documented for the login-redirect path above). So this renders
    // the actual vulnerable line lifted verbatim from the template file
    // through Craft's Twig view: if the `|e` escape is ever dropped, the
    // raw payload reappears and this fails.
    $template = file_get_contents(
        dirname(__DIR__, 2) . '/src/templates/oauth/authorize.twig',
    );
    expect($template)->toBeString();

    $line = null;
    foreach (explode("\n", (string) $template) as $candidate) {
        if (str_contains($candidate, 'is requesting access to your Craft account')) {
            $line = trim($candidate);
            break;
        }
    }
    expect($line)->not->toBeNull();

    $rendered = Craft::$app->getView()->renderString(
        (string) $line,
        ['clientName' => '<script>alert(1)</script>'],
    );

    expect($rendered)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

it('posts the authorization parameters as hidden body fields to a bare action URL', function() {
    // Pins the premise every consent-POST test in this file depends on.
    // The template's form action is a bare `url('oauth/authorize')` — an
    // HTML form action replaces the whole URL, so any query string added
    // here would put `client_id`, `redirect_uri`, `scope` and `state`
    // back into a URL on the POST. The parameters instead ride as hidden
    // BODY fields, which is why `OauthController` has to merge them into
    // the PSR request's query params on the resume.
    //
    // If the action URL ever grows a query string, or the hidden-field
    // loop disappears, `_herald_consent_post()` stops matching what
    // browsers submit and this fails.
    $template = (string) file_get_contents(
        dirname(__DIR__, 2) . '/src/templates/oauth/authorize.twig',
    );

    expect($template)
        ->toContain("<form method=\"POST\" action=\"{{ url('oauth/authorize') }}\">")
        ->toContain('{% for key, value in query %}')
        ->toContain('<input type="hidden" name="{{ key }}" value="{{ value }}">');

    // No `url('oauth/authorize', ...)` two-argument form anywhere — that
    // would append the parameters as a query string.
    expect($template)->not->toContain("url('oauth/authorize',");
});

it('POST /oauth/authorize with approve=0 surfaces an access_denied error', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/deny-flow',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $controller = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'xyz',
    ], approve: '0');

    $response = $controller->actionAuthorize();
    // Denial → 302 back to redirect_uri with error=access_denied.
    expect($response->statusCode)->toBe(302);
    $location = $response->headers->get('Location');
    expect($location)->toContain('error=access_denied');
});

it('refuses a redirect_uri the client never registered on the consent POST', function() {
    // The consent resume trusts the resubmitted body parameters, so the
    // guarantee that makes that safe needs its own test: league re-runs
    // full validation on the resume, so a substituted `redirect_uri`
    // must be rejected against the client's registered URIs rather than
    // used to deliver the authorization code elsewhere.
    //
    // league 9.4.1: `AuthCodeGrant::validateAuthorizationRequest()` calls
    // `AbstractGrant::validateRedirectUri()`, which runs
    // `RedirectUriValidator::validateRedirectUri()` against
    // `$client->getRedirectUri()` and throws `invalid_client` on a miss.
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/redirect-substitution',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $controller = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        // Registered URI swapped for an attacker-controlled one.
        'redirect_uri' => 'https://attacker.example/steal',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'xyz',
    ], approve: '1');

    $response = $controller->actionAuthorize();

    // Refused outright, not redirected: no code is minted and nothing
    // points at the substituted host. The status and error code pin the
    // rejection to the redirect-URI check specifically — a 400
    // `invalid_request` would mean the request died earlier, before
    // league ever compared the URI.
    expect($response->statusCode)->toBe(401);
    expect((string) $response->content)->toContain('"error":"invalid_client"');
    expect($response->headers->get('Location'))->toBeNull();
    expect((string) $response->content)
        ->not->toContain('code=')
        ->not->toContain('attacker.example');
    expect(_herald_code_count($client['client_id']))->toBe(0);
});

it('renders the pending-approval page on the consent POST for an unapproved client', function() {
    // The DCR approval gate reads `client_id` off the authorize request.
    // On the consent POST that parameter is in the body, so a query-only
    // read resolves null and the gate is silently skipped — an
    // unapproved client would sail through the resume.
    Craft::$app->getUser()->setIdentity($this->admin);

    $settings = Herald::getInstance()->getSettings();
    $settings->dcrAutoApprove = false;

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/pending-on-post',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $record = OauthClientRecord::findOne(['clientId' => $client['client_id']]);
    expect($record)->not->toBeNull();
    expect((bool) $record->approved)->toBeFalse();

    $controller = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'xyz',
    ], approve: '1');

    $response = $controller->actionAuthorize();

    expect($response->statusCode)->toBe(403);
    expect((string) $response->content)->toContain('Awaiting approval');
    expect(_herald_code_count($client['client_id']))->toBe(0);
});

// -----------------------------------------------------------------------------
// /oauth/authorize — CSRF on the consent POST
// -----------------------------------------------------------------------------

it('rejects the authorize consent POST when the CSRF token is invalid', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $controller = _herald_oauth_request(
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

    $controller = _herald_oauth_request(
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
    $controller = _herald_oauth_request(
        method: 'POST',
        url: "https://test.invalid/oauth/{$actionId}",
        csrfValid: false,
    );

    expect($controller->runBeforeAction($actionId))->toBeTrue();
})->with(['token', 'register', 'revoke']);

// -----------------------------------------------------------------------------
// /oauth/token + full PKCE round-trip
// -----------------------------------------------------------------------------

it('full PKCE flow in the shipped consent shape: authorize → code → token → MCP call', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/full-pkce',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $resource = 'https://test.invalid/herald/mcp';

    // Step 1: the consent POST in the shape `oauth/authorize.twig`
    // actually submits — authorization parameters in the BODY, empty
    // query string — captures the code in the redirect.
    $authController = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'abc',
        'resource' => $resource,
    ], approve: '1');

    $authResponse = $authController->actionAuthorize();
    // league reads every authorization parameter from the query string
    // only, so without the controller's consent-resume merge this comes
    // back as a 400 carrying league's own
    // "Check the `response_type` parameter" hint instead of a redirect.
    expect((string) $authResponse->content)->not->toContain('response_type');
    expect($authResponse->statusCode)->toBe(302);
    $location = $authResponse->headers->get('Location');
    expect($location)->toBeString();
    expect($location)->toContain('code=');

    // Extract code from the redirect URL.
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    expect($params)->toHaveKey('code');
    $code = (string) $params['code'];

    // Step 2: POST /oauth/token to exchange the code for tokens.
    $tokenController = _herald_oauth_request(
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
    $resolved = Herald::getInstance()->oauth->lookupAccessToken($accessToken);
    expect($resolved)->not->toBeNull();
    expect($resolved['userId'])->toBe($this->userId);
    expect($resolved['audience'])->toBe($resource);
    expect($resolved['scope'])->toContain('read');
});

it('code re-use returns invalid_grant on the second exchange', function() {
    Craft::$app->getUser()->setIdentity($this->admin);

    $pkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/code-reuse',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $authController = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'abc',
        'resource' => 'https://test.invalid/herald/mcp',
    ], approve: '1');
    $authResponse = $authController->actionAuthorize();
    expect($authResponse->statusCode)->toBe(302);
    parse_str((string) parse_url((string) $authResponse->headers->get('Location'), PHP_URL_QUERY), $params);
    $code = (string) $params['code'];

    // First exchange — succeeds.
    $firstToken = _herald_oauth_request(
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
    $secondToken = _herald_oauth_request(
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

    $pkce = _herald_pkce();
    $wrongPkce = _herald_pkce();
    $client = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/pkce-mismatch',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $authController = _herald_consent_post([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://example.com/cb',
        'code_challenge' => $pkce['challenge'],
        'code_challenge_method' => 'S256',
        'scope' => 'read',
        'state' => 'abc',
    ], approve: '1');
    $authResponse = $authController->actionAuthorize();
    expect($authResponse->statusCode)->toBe(302);
    parse_str((string) parse_url((string) $authResponse->headers->get('Location'), PHP_URL_QUERY), $params);
    $code = (string) $params['code'];

    $tokenController = _herald_oauth_request(
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
    $controller = _herald_oauth_request(
        method: 'POST',
        bodyParams: ['token' => 'unknown-token'],
        url: 'https://test.invalid/oauth/revoke',
    );
    $response = $controller->actionRevoke();
    expect($response->statusCode)->toBe(200);
});

it('POST /oauth/revoke flips the dateRevoked on a known refresh token', function() {
    // Register a real client so the FK constraint on clientId is satisfied.
    $clientResp = Herald::getInstance()->oauth->registerClient([
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

    $controller = _herald_oauth_request(
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
// /oauth/elevate — fresh re-auth (Blocker 1 + 2, MAJOR 4)
// -----------------------------------------------------------------------------

/**
 * Create an active user with a known password so the elevate flow's
 * `User::authenticate()` call can be exercised against real credentials.
 */
function _herald_elevate_user(string $password): craft\elements\User
{
    $user = new craft\elements\User();
    $user->username = '_test_elevate_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->active = true;
    expect(Craft::$app->getElements()->saveElement($user))->toBeTrue();

    // Set the password hash directly. `newPassword` on a bare
    // saveElement() doesn't reliably persist in the console test harness
    // (the password-change scenario isn't engaged), so write the hash the
    // same way Craft stores it. `passwordResetRequired` defaults true for
    // a freshly-activated account with no real password event — clear it
    // so `authenticate()` doesn't short-circuit on that gate.
    $hash = Craft::$app->getSecurity()->hashPassword($password);
    Craft::$app->getDb()->createCommand()
        ->update(
            \craft\db\Table::USERS,
            ['password' => $hash, 'passwordResetRequired' => false],
            ['id' => $user->id],
        )
        ->execute();

    // Element queries omit the sensitive `password` column, so hydrate the
    // returned user with the hash + cleared reset flag directly.
    $fresh = Craft::$app->getUsers()->getUserById((int) $user->id);
    expect($fresh)->not->toBeNull();
    $fresh->password = $hash;
    $fresh->passwordResetRequired = false;
    expect($fresh->authenticate($password))->toBeTrue();
    return $fresh;
}

it('elevate POST does NOT mint without a password and stays refused', function() {
    $password = 'correct-horse-battery-staple-1';
    $user = _herald_elevate_user($password);
    $oauth = Herald::getInstance()->oauth;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));

    try {
        Craft::$app->getUser()->setIdentity($user);

        $controller = _herald_oauth_request(method: 'POST', bodyParams: [], url: 'https://test.invalid/oauth/elevate');
        $controller->actionElevate();

        expect($oauth->isElevated((int) $user->id))->toBeFalse();
        // Re-renders the challenge screen with an error, not the success.
        expect($controller->renderedElevate['template'])->toBe('herald/oauth/elevate');
        expect($controller->renderedElevate['variables']['error'])->not->toBeNull();
    } finally {
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('elevate POST rejects a wrong password and does NOT mint', function() {
    $user = _herald_elevate_user('correct-horse-battery-staple-2');
    $oauth = Herald::getInstance()->oauth;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));

    try {
        Craft::$app->getUser()->setIdentity($user);

        $controller = _herald_oauth_request(
            method: 'POST',
            bodyParams: ['password' => 'this-is-not-the-password'],
            url: 'https://test.invalid/oauth/elevate',
        );
        $controller->actionElevate();

        expect($oauth->isElevated((int) $user->id))->toBeFalse();
        expect($controller->renderedElevate['template'])->toBe('herald/oauth/elevate');
        expect($controller->renderedElevate['variables']['error'])->not->toBeNull();
    } finally {
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('elevate POST mints on the correct password and unlocks the user', function() {
    $password = 'correct-horse-battery-staple-3';
    $user = _herald_elevate_user($password);
    $oauth = Herald::getInstance()->oauth;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));

    try {
        Craft::$app->getUser()->setIdentity($user);

        expect($oauth->isElevated((int) $user->id))->toBeFalse();

        $controller = _herald_oauth_request(
            method: 'POST',
            bodyParams: ['password' => $password],
            url: 'https://test.invalid/oauth/elevate',
        );
        $controller->actionElevate();

        expect($oauth->isElevated((int) $user->id))->toBeTrue();
        expect($controller->renderedElevate['template'])->toBe('herald/oauth/elevated');
    } finally {
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('elevate POST still requires the password when elevatedSessionDuration is 0 (Blocker 1)', function() {
    // The crux of Blocker 1: when `elevatedSessionDuration === 0`,
    // Craft's `getHasElevatedSession()` returns true unconditionally. A
    // flow built on `requireElevatedSession()` would mint with no fresh
    // challenge. Ours must still demand — and verify — the password.
    $password = 'correct-horse-battery-staple-4';
    $user = _herald_elevate_user($password);
    $oauth = Herald::getInstance()->oauth;
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $originalDuration = $generalConfig->elevatedSessionDuration;
    $generalConfig->elevatedSessionDuration = 0;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));

    try {
        Craft::$app->getUser()->setIdentity($user);

        // No password supplied — must NOT mint despite the ambient
        // "elevated" session state.
        $noPw = _herald_oauth_request(method: 'POST', bodyParams: [], url: 'https://test.invalid/oauth/elevate');
        $noPw->actionElevate();
        expect($oauth->isElevated((int) $user->id))->toBeFalse();

        // Correct password — now it mints.
        $withPw = _herald_oauth_request(
            method: 'POST',
            bodyParams: ['password' => $password],
            url: 'https://test.invalid/oauth/elevate',
        );
        $withPw->actionElevate();
        expect($oauth->isElevated((int) $user->id))->toBeTrue();
    } finally {
        $generalConfig->elevatedSessionDuration = $originalDuration;
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));
        Craft::$app->getElements()->deleteElement($user, true);
    }
});

it('elevation does not cross users: a token bound to a different user is not elevated', function() {
    // MAJOR 4e in the user-keyed model: user A elevates, but the MCP
    // dispatcher gates on the userId the access token is bound to. A token
    // bound to user B therefore sees no elevation from A's grant.
    $userA = _herald_elevate_user('correct-horse-battery-staple-5a');
    $userB = _herald_elevate_user('correct-horse-battery-staple-5b');
    $oauth = Herald::getInstance()->oauth;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $userA->id));
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $userB->id));

    try {
        Craft::$app->getUser()->setIdentity($userA);

        $controller = _herald_oauth_request(
            method: 'POST',
            bodyParams: ['password' => 'correct-horse-battery-staple-5a'],
            url: 'https://test.invalid/oauth/elevate',
        );
        $controller->actionElevate();

        // A is elevated; B (a different token's bound user) is not.
        expect($oauth->isElevated((int) $userA->id))->toBeTrue()
            ->and($oauth->isElevated((int) $userB->id))->toBeFalse();
    } finally {
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $userA->id));
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $userB->id));
        Craft::$app->getElements()->deleteElement($userA, true);
        Craft::$app->getElements()->deleteElement($userB, true);
    }
});

it('elevate has no token query param path (Blocker 2: no token in URL)', function() {
    // A token supplied as `?token=` must be irrelevant: elevation is
    // keyed by the logged-in user, and the action never reads a token
    // param. With no password, it must not mint even with a token in the
    // query string.
    $user = _herald_elevate_user('correct-horse-battery-staple-6');
    $oauth = Herald::getInstance()->oauth;
    Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));

    try {
        Craft::$app->getUser()->setIdentity($user);

        $controller = _herald_oauth_request(
            method: 'POST',
            queryParams: ['token' => 'some-access-token-value'],
            bodyParams: [],
            url: 'https://test.invalid/oauth/elevate?token=some-access-token-value',
        );
        $controller->actionElevate();

        expect($oauth->isElevated((int) $user->id))->toBeFalse();
    } finally {
        Craft::$app->getCache()->delete($oauth->elevationCacheKey((int) $user->id));
        Craft::$app->getElements()->deleteElement($user, true);
    }
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
    Herald::getInstance()->getSettings()->httpEnabled = false;

    $controller = _herald_oauth_request(method: $method, url: $url);
    $proceeded = $controller->runBeforeAction();

    expect($proceeded)->toBeFalse();
    expect($controller->response->statusCode)->toBe(503);
    expect($controller->response->data)->toHaveKey('error');
})->with('oauth endpoints');

it('does not fire the 503 gate in beforeAction when httpEnabled is true', function() {
    Craft::$app->getUser()->setIdentity($this->admin);
    Herald::getInstance()->getSettings()->httpEnabled = true;

    $controller = _herald_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/token');
    $controller->runBeforeAction();

    // The kill switch did not populate a 503 — parent::beforeAction()
    // owns whatever status follows.
    expect($controller->response->statusCode)->not->toBe(503);
});

// -----------------------------------------------------------------------------
// Pro edition gate (Gate 9.7) — every OAuth action returns 403 on Free
// -----------------------------------------------------------------------------

it('returns 403 from beforeAction on a Free install', function(string $method, string $url) {
    Herald::getInstance()->edition = Herald::EDITION_FREE;

    $controller = _herald_oauth_request(method: $method, url: $url);
    $proceeded = $controller->runBeforeAction();

    expect($proceeded)->toBeFalse();
    expect($controller->response->statusCode)->toBe(403);
    expect($controller->response->data)
        ->toHaveKey('error', 'The HTTP transport requires the Herald Pro edition.');
})->with('oauth endpoints');

// -----------------------------------------------------------------------------
// IP throttle — anonymous /oauth/register, /token, /revoke are 429'd
// -----------------------------------------------------------------------------

it('429s anonymous /oauth/register from the same IP after the burst is exhausted', function() {
    $settings = Herald::getInstance()->getSettings();
    $settings->httpEnabled = true;
    // Tight burst so the loop trips fast; refill 1/sec so a single
    // tight loop can't be saved by an accrued refill.
    $originalBurst = $settings->rateLimitBurst;
    $originalRate = $settings->rateLimitPerSecond;
    $settings->rateLimitBurst = 3;
    $settings->rateLimitPerSecond = 1;

    $ip = '198.51.100.42';
    Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ip);

    try {
        $blocked = false;
        // Burst is 3; the 4th request inside the same wall-clock second
        // must trip the throttle.
        for ($i = 0; $i < 5; $i++) {
            $controller = _herald_oauth_request(
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
        Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ip);
        $settings->rateLimitBurst = $originalBurst;
        $settings->rateLimitPerSecond = $originalRate;
    }
});

it('does not throttle a different IP sharing the same window', function() {
    $settings = Herald::getInstance()->getSettings();
    $settings->httpEnabled = true;
    $originalBurst = $settings->rateLimitBurst;
    $settings->rateLimitBurst = 1;

    $ipA = '198.51.100.10';
    $ipB = '198.51.100.11';
    Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipA);
    Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipB);

    try {
        // Drain IP A's single-token bucket.
        $a = _herald_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipA);
        expect($a->runBeforeAction('register'))->toBeTrue();
        $a2 = _herald_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipA);
        expect($a2->runBeforeAction('register'))->toBeFalse();

        // IP B still has its own full bucket.
        $b = _herald_oauth_request(method: 'POST', url: 'https://test.invalid/oauth/register', userIp: $ipB);
        expect($b->runBeforeAction('register'))->toBeTrue();
    } finally {
        Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipA);
        Herald::getInstance()->rateLimiter->clearKey('oauth:ip:' . $ipB);
        $settings->rateLimitBurst = $originalBurst;
    }
});
