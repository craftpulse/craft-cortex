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
use craftpulse\cortex\Plugin;

beforeEach(function() {
    $this->controller = new WellKnownController('well-known', Plugin::getInstance());
    $this->controller->response = new \yii\web\Response();
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
    $settings = Plugin::getInstance()->getSettings();
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
    $settings = Plugin::getInstance()->getSettings();
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
