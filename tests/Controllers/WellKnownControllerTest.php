<?php

/**
 * =========================================================================
 * WellKnownController tests — verify the RFC 8414 + RFC 9728
 * metadata endpoints return spec-shaped JSON.
 *
 * Endpoints are anonymous and GET-only; we exercise the action
 * methods directly against the playground's Craft to assert on the
 * response payload shape without spinning up a real HTTP server.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\controllers\WellKnownController;
use craftpulse\cortex\Cortex;
use yii\web\HeaderCollection;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

class _CortexWellKnownRequest
{
    public HeaderCollection $headers;

    public function __construct(private readonly string $method = 'GET')
    {
        $this->headers = new HeaderCollection();
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getHeaders(): HeaderCollection
    {
        return $this->headers;
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
}

class _CortexWellKnownHarness extends WellKnownController
{
    /**
     * Drive `beforeAction()` against a synthetic action so the
     * `httpEnabled` kill switch on `AbstractOauthController` fires in
     * tests. Returns the gate's boolean; the response slot carries the
     * populated 503 when it short-circuits.
     */
    public function runBeforeAction(): bool
    {
        $this->request = new _CortexWellKnownRequest('GET');
        return $this->beforeAction(new \yii\base\Action('authorization-server', $this));
    }
}

beforeEach(function() {
    $this->controller = new WellKnownController('well-known', Cortex::getInstance());
    $this->controller->response = new \yii\web\Response();

    $settings = Cortex::getInstance()->getSettings();
    $this->originalHttpEnabled = $settings->httpEnabled;
    // The discovery actions are read directly in most tests, bypassing
    // `beforeAction()`; the kill-switch tests drive `beforeAction()`
    // explicitly.
    $settings->httpEnabled = true;
});

afterEach(function() {
    Cortex::getInstance()->getSettings()->httpEnabled = $this->originalHttpEnabled;
});

// -----------------------------------------------------------------------------
// /.well-known/oauth-authorization-server — RFC 8414
// -----------------------------------------------------------------------------

it('authorization-server returns the canonical RFC 8414 key set', function() {
    $response = $this->controller->actionAuthorizationServer();
    $data = $response->data;

    expect($data)->toHaveKeys([
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'revocation_endpoint',
        'scopes_supported',
        'response_types_supported',
        'grant_types_supported',
        'code_challenge_methods_supported',
        'token_endpoint_auth_methods_supported',
    ]);
});

it('authorization-server advertises only S256 PKCE', function() {
    $response = $this->controller->actionAuthorizationServer();
    expect($response->data['code_challenge_methods_supported'])->toBe(['S256']);
});

it('authorization-server lists the Phase 1 scope vocabulary', function() {
    $response = $this->controller->actionAuthorizationServer();
    expect($response->data['scopes_supported'])->toBe(['read', 'write']);
});

it('authorization-server lists only authorization_code and refresh_token grants', function() {
    $response = $this->controller->actionAuthorizationServer();
    expect($response->data['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);
});

it('authorization-server lists response_types code only', function() {
    $response = $this->controller->actionAuthorizationServer();
    expect($response->data['response_types_supported'])->toBe(['code']);
});

it('authorization-server includes the registration_endpoint when DCR is enabled', function() {
    $settings = Cortex::getInstance()->getSettings();
    $original = $settings->dcrEnabled;
    $settings->dcrEnabled = true;

    try {
        $response = $this->controller->actionAuthorizationServer();
        expect($response->data)->toHaveKey('registration_endpoint');
        expect($response->data['registration_endpoint'])->toContain('/oauth/register');
    } finally {
        $settings->dcrEnabled = $original;
    }
});

it('authorization-server omits the registration_endpoint when DCR is disabled', function() {
    $settings = Cortex::getInstance()->getSettings();
    $original = $settings->dcrEnabled;
    $settings->dcrEnabled = false;

    try {
        $response = $this->controller->actionAuthorizationServer();
        expect($response->data)->not->toHaveKey('registration_endpoint');
    } finally {
        $settings->dcrEnabled = $original;
    }
});

it('authorization-server endpoint URLs are absolute', function() {
    $response = $this->controller->actionAuthorizationServer();
    expect($response->data['authorization_endpoint'])->toStartWith('http');
    expect($response->data['token_endpoint'])->toStartWith('http');
});

// -----------------------------------------------------------------------------
// /.well-known/oauth-protected-resource — RFC 9728
// -----------------------------------------------------------------------------

it('protected-resource returns the canonical RFC 9728 key set', function() {
    $response = $this->controller->actionProtectedResource();
    $data = $response->data;

    expect($data)->toHaveKeys([
        'resource',
        'authorization_servers',
        'scopes_supported',
        'bearer_methods_supported',
        'resource_documentation',
    ]);
});

it('protected-resource lists bearer_methods_supported as header-only', function() {
    $response = $this->controller->actionProtectedResource();
    expect($response->data['bearer_methods_supported'])->toBe(['header']);
});

it('protected-resource resource URL points at the cortex MCP endpoint', function() {
    $response = $this->controller->actionProtectedResource();
    expect($response->data['resource'])->toContain('/cortex/mcp');
});

it('protected-resource lists the Phase 1 scope vocabulary', function() {
    $response = $this->controller->actionProtectedResource();
    expect($response->data['scopes_supported'])->toBe(['read', 'write']);
});

// -----------------------------------------------------------------------------
// httpEnabled kill switch — both metadata documents 503 when off
// -----------------------------------------------------------------------------

it('returns 503 from beforeAction when httpEnabled is false', function() {
    Cortex::getInstance()->getSettings()->httpEnabled = false;

    $controller = new _CortexWellKnownHarness('well-known', Cortex::getInstance());
    $controller->response = new Response();
    $proceeded = $controller->runBeforeAction();

    expect($proceeded)->toBeFalse();
    expect($controller->response->statusCode)->toBe(503);
    expect($controller->response->data)->toHaveKey('error');
});

it('does not fire the 503 gate in beforeAction when httpEnabled is true', function() {
    Cortex::getInstance()->getSettings()->httpEnabled = true;

    $controller = new _CortexWellKnownHarness('well-known', Cortex::getInstance());
    $controller->response = new Response();
    $controller->runBeforeAction();

    expect($controller->response->statusCode)->not->toBe(503);
});
