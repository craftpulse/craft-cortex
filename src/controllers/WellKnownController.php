<?php

namespace craftpulse\cortex\controllers;

use craft\helpers\UrlHelper;
use craftpulse\cortex\Cortex;
use yii\web\Response;

/**
 * =========================================================================
 * `.well-known/oauth-authorization-server` (RFC 8414) and
 * `.well-known/oauth-protected-resource` (RFC 9728) metadata
 * endpoints.
 *
 * Both are open, anonymous-allowed reads. Both return static JSON
 * derived from cortex's settings + Craft's absolute-URL helper —
 * no DB hits, no session, no auth. CSRF stays disabled because the
 * endpoints are GET-only and the responses carry no client state.
 *
 * RFC 8414 §3 and RFC 9728 §3 both mandate that the metadata sit at
 * the root of the issuer URL — under `/.well-known/...`, not under
 * any path prefix. The plugin URL rules register them at site root
 * accordingly (`Cortex::init()`).
 *
 * Extends `AbstractOauthController` for the shared `httpEnabled` kill
 * switch — both metadata documents return 503 when the HTTP transport
 * is disabled, so a default install exposes no discovery surface.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class WellKnownController extends AbstractOauthController
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = ['authorization-server', 'protected-resource'];

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * RFC 8414 Authorization Server Metadata. Static JSON describing
     * cortex's OAuth surface — issuer URL, supported scopes, grant
     * types, PKCE methods, etc. Clients use this for discovery
     * before initiating the AuthCode flow.
     *
     * Capability vocabulary:
     *   - `scopes_supported`: the capability set from `Scopes::all()`
     *     (`content:read`, `content:write`, `assets:write`,
     *     `schema:read`, `system:read`, `users:read`, `users:write`).
     *   - `response_types_supported`: `["code"]`
     *   - `grant_types_supported`: `["authorization_code", "refresh_token"]`
     *   - `code_challenge_methods_supported`: `["S256"]` only —
     *     `plain` is rejected at the authorize endpoint regardless of
     *     what the metadata advertises, but listing S256-only
     *     advertises the policy clearly.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAuthorizationServer(): Response
    {
        $settings = Cortex::getInstance()->getSettings();
        $issuer = UrlHelper::baseSiteUrl();
        $issuer = rtrim($issuer, '/');

        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => UrlHelper::siteUrl('oauth/authorize'),
            'token_endpoint' => UrlHelper::siteUrl('oauth/token'),
            'revocation_endpoint' => UrlHelper::siteUrl('oauth/revoke'),
            'scopes_supported' => Cortex::getInstance()->scopes->all(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
                'none',
            ],
        ];

        if ($settings->dcrEnabled) {
            $metadata['registration_endpoint'] = UrlHelper::siteUrl('oauth/register');
        }

        return $this->asJson($metadata);
    }

    /**
     * RFC 9728 Protected Resource Metadata. Advertises the MCP
     * endpoint as a protected resource and points clients at the
     * authorization server that can issue tokens for it. The 401
     * `WWW-Authenticate: Bearer` challenge header from
     * `McpController::beforeAction()` carries
     * `resource_metadata=<this URL>` so unauthenticated clients can
     * follow the trail to discovery without prior knowledge.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionProtectedResource(): Response
    {
        $metadata = [
            'resource' => UrlHelper::siteUrl('cortex/mcp'),
            'authorization_servers' => [rtrim(UrlHelper::baseSiteUrl(), '/')],
            'scopes_supported' => Cortex::getInstance()->scopes->all(),
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => 'https://github.com/craftpulse/craft-cortex',
        ];

        return $this->asJson($metadata);
    }
}
