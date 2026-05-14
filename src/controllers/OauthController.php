<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use craftpulse\cortex\oauth\entities\UserEntity;
use craftpulse\cortex\Plugin;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use yii\web\Response;

/**
 * =========================================================================
 * OAuth 2.1 endpoints — `/oauth/authorize`, `/oauth/token`,
 * `/oauth/register`, `/oauth/revoke`.
 *
 * Bridges Yii's request/response cycle to league's PSR-7 surface.
 * Every action:
 *
 *   1. Builds a `Nyholm\Psr7\ServerRequest` from the live Yii
 *      request.
 *   2. Hands it to league's `AuthorizationServer` (authorize / token).
 *   3. Pipes league's PSR-7 response back through `_emitPsr7Response()`
 *      onto Yii's `Response`.
 *
 * The `actionAuthorize()` flow is the only one that breaks pure PSR-
 * 7 round-tripping: GET renders a Twig consent screen; POST captures
 * the user's approval and resumes league's flow.
 *
 * **Authentication on each endpoint:**
 *
 *   - `actionAuthorize` (GET): requires Craft CP login. Anonymous
 *     visitors get redirected to `/admin/login` with a `return` URL
 *     so they land back here after authenticating.
 *   - `actionAuthorize` (POST): same — the consent form requires a
 *     live Craft session.
 *   - `actionToken` (POST): anonymous-allowed. The token endpoint
 *     authenticates via client credentials (confidential) or PKCE
 *     proof (public).
 *   - `actionRegister` (POST): anonymous-allowed. RFC 7591 DCR.
 *     Settings-gated by `dcrEnabled`.
 *   - `actionRevoke` (POST): anonymous-allowed. RFC 7009 lets
 *     callers revoke tokens with no further auth — the token itself
 *     is the proof.
 *
 * `$enableCsrfValidation = false` because every endpoint is either
 * PSR-7-bridged (auth handled by league) or revocation-style (token
 * is the proof).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = ['token', 'register', 'revoke'];

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * GET / POST authorize. GET validates the authorization request
     * + renders the consent screen; POST captures the user's
     * approval and resumes league's flow.
     *
     * Login is enforced by Craft's standard pipeline — `authorize` is
     * not listed in `$allowAnonymous`, so unauthenticated visitors are
     * redirected to the CP login page (with a return URL) automatically
     * before this action body runs.
     *
     * @throws \yii\base\InvalidConfigException When the OAuth keys
     *         are not yet generated. Operators run
     *         `cortex/oauth/init-keys` once per install.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionAuthorize(): Response
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;
        $identity = $app->getUser()->getIdentity();
        if (!$identity instanceof User) {
            // Hard-impossible: Craft's pipeline requires a valid
            // session before this action body runs (authorize is not
            // in $allowAnonymous). A non-User identity here is a
            // misconfigured install or a test harness gap.
            throw new \yii\web\ForbiddenHttpException('OAuth authorize requires an authenticated Craft user.');
        }
        $currentUser = $identity;

        $oauth = Plugin::getInstance()->oauth;
        $psrRequest = $this->_buildPsrRequest();

        try {
            $authRequest = $oauth->getAuthorizationServer()->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return $this->_emitPsr7Response($e->generateHttpResponse((new Psr17Factory())->createResponse()));
        }

        // Phase 1 policy: reject `plain` PKCE method even though
        // league enables it by default. Surfaces as `invalid_request`
        // on the redirect-back error so clients can see the
        // rejection.
        $challengeMethod = $authRequest->getCodeChallengeMethod();
        if ($challengeMethod !== null && $challengeMethod !== 'S256') {
            $oauthException = OAuthServerException::invalidRequest(
                'code_challenge_method',
                'Only S256 PKCE is supported. `plain` is rejected.',
            );
            return $this->_emitPsr7Response($oauthException->generateHttpResponse((new Psr17Factory())->createResponse()));
        }

        // Stamp the audience indicator onto the cortex Oauth service
        // slot so the access-token entity picks it up at issuance.
        // RFC 8707 — the resource indicator that ends up in `aud`.
        $resource = $this->_resourceParam();
        if ($resource !== null) {
            $oauth->setPendingAudience($resource);
        }

        if (strtoupper($this->request->getMethod()) === 'POST') {
            $approved = $this->request->getBodyParam('approve') === '1';
            return $this->_completeAuthorization($authRequest, $currentUser, $approved);
        }

        return $this->_renderConsentScreen($authRequest, $currentUser);
    }

    /**
     * POST `/oauth/token` — code-exchange and refresh.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionToken(): Response
    {
        $this->requirePostRequest();

        $oauth = Plugin::getInstance()->oauth;
        $psrRequest = $this->_buildPsrRequest();

        // Audience stamp again — refresh + token-exchange both need
        // the resource indicator on the new access token.
        $resource = $this->_resourceParam();
        if ($resource !== null) {
            $oauth->setPendingAudience($resource);
        }

        $psrResponse = (new Psr17Factory())->createResponse();
        try {
            $psrResponse = $oauth->getAuthorizationServer()->respondToAccessTokenRequest($psrRequest, $psrResponse);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse($psrResponse);
        } catch (Throwable $e) {
            Craft::error("cortex OAuth token endpoint internal error: {$e->getMessage()}\n{$e->getTraceAsString()}", 'cortex');
            $psrResponse = $psrResponse->withStatus(500);
            $psrResponse->getBody()->write('{"error":"server_error"}');
        }

        return $this->_emitPsr7Response($psrResponse);
    }

    /**
     * POST `/oauth/register` — RFC 7591 DCR.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRegister(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getSettings()->dcrEnabled) {
            $this->response->setStatusCode(404);
            $this->response->format = Response::FORMAT_JSON;
            $this->response->data = ['error' => 'Dynamic Client Registration is disabled on this install.'];
            return $this->response;
        }

        $body = $this->request->getRawBody();
        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->_dcrError(400, 'invalid_client_metadata', 'Request body is not valid JSON.');
        }

        if (!is_array($payload)) {
            return $this->_dcrError(400, 'invalid_client_metadata', 'Request body must be a JSON object.');
        }

        try {
            $response = Plugin::getInstance()->oauth->registerClient($payload);
        } catch (\InvalidArgumentException $e) {
            return $this->_dcrError(400, 'invalid_client_metadata', $e->getMessage());
        } catch (Throwable $e) {
            Craft::error("cortex OAuth DCR error: {$e->getMessage()}\n{$e->getTraceAsString()}", 'cortex');
            return $this->_dcrError(500, 'server_error', 'An internal error occurred during client registration.');
        }

        $this->response->setStatusCode(201);
        $this->response->format = Response::FORMAT_JSON;
        $this->response->data = $response;
        return $this->response;
    }

    /**
     * POST `/oauth/revoke` — RFC 7009 token revocation.
     *
     * Per RFC 7009 §2.2, the response is 200 OK for both successful
     * revocation and a request against an unknown token (no
     * information leakage). The body is empty.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionRevoke(): Response
    {
        $this->requirePostRequest();

        $token = $this->request->getBodyParam('token');
        if (is_string($token) && $token !== '') {
            Plugin::getInstance()->oauth->revokeToken($token);
        }

        $this->response->setStatusCode(200);
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';
        return $this->response;
    }

    // Private Methods
    // =========================================================================

    /**
     * Build a Nyholm PSR-7 ServerRequest from the live Yii request.
     * Carries headers, query string, parsed body, and method.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildPsrRequest(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $method = strtoupper($this->request->getMethod());
        $uri = $factory->createUri($this->request->getAbsoluteUrl());

        $psr = $factory->createServerRequest($method, $uri, $_SERVER);

        // Headers — copy them across so league sees Authorization,
        // Content-Type, etc.
        foreach ($this->request->getHeaders() as $name => $values) {
            $psr = $psr->withHeader((string) $name, $values);
        }

        $psr = $psr->withQueryParams($this->request->getQueryParams());

        // Parsed body for POST. For GET, league only reads the query
        // string.
        $bodyParams = $this->request->getBodyParams();
        if ($bodyParams !== []) {
            $psr = $psr->withParsedBody($bodyParams);
        }

        $rawBody = $this->request->getRawBody();
        if ($rawBody !== '') {
            $psr->getBody()->write($rawBody);
            $psr->getBody()->rewind();
        }

        return $psr;
    }

    /**
     * Pipe a league PSR-7 response back onto Yii's response object.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _emitPsr7Response(ResponseInterface $psr): Response
    {
        $this->response->setStatusCode($psr->getStatusCode());
        foreach ($psr->getHeaders() as $name => $values) {
            $this->response->headers->set((string) $name, implode(', ', $values));
        }
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = (string) $psr->getBody();
        return $this->response;
    }

    /**
     * Render the consent screen. Twig template at
     * `oauth/authorize.twig`; receives the client name, scopes,
     * username, and a hidden form payload that POSTs back to this
     * action with the user's decision.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renderConsentScreen(AuthorizationRequestInterface $authRequest, User $user): Response
    {
        $scopes = array_map(
            static fn($scope): string => $scope->getIdentifier(),
            $authRequest->getScopes(),
        );

        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = Craft::$app->getView()->renderTemplate(
            'cortex/oauth/authorize',
            [
                'clientName' => $authRequest->getClient()->getName(),
                'clientId' => $authRequest->getClient()->getIdentifier(),
                'scopes' => $scopes,
                'username' => $user->username ?? $user->email,
                'query' => $this->request->getQueryParams(),
            ],
        );
        return $this->response;
    }

    /**
     * Complete the authorization request after POST. Approved →
     * league redirects back to the client with the code; denied →
     * league redirects back with `error=access_denied`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _completeAuthorization(
        AuthorizationRequestInterface $authRequest,
        User $user,
        bool $approved,
    ): Response {
        $userIdentifier = (string) $user->id;
        if ($userIdentifier === '') {
            // Hard-impossible — the user came from
            // `Craft::$app->getUser()->getIdentity()` and is logged in.
            throw new \LogicException('Cannot complete OAuth authorization for a user with an empty id.');
        }
        $userEntity = new UserEntity();
        $userEntity->setIdentifier($userIdentifier);
        $authRequest->setUser($userEntity);
        $authRequest->setAuthorizationApproved($approved);

        $psrResponse = (new Psr17Factory())->createResponse();
        try {
            $psrResponse = Plugin::getInstance()
                ->oauth
                ->getAuthorizationServer()
                ->completeAuthorizationRequest($authRequest, $psrResponse);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse($psrResponse);
        }

        return $this->_emitPsr7Response($psrResponse);
    }

    /**
     * Read the `resource=` request parameter (query for GET on
     * authorize, body for POST on token / authorize). Returns null
     * when absent.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resourceParam(): ?string
    {
        $resource = $this->request->getQueryParam('resource');
        if (!is_string($resource) || $resource === '') {
            $resource = $this->request->getBodyParam('resource');
        }
        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    /**
     * Shape an RFC 7591 §3.2.2 error response.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _dcrError(int $status, string $error, string $description): Response
    {
        $this->response->setStatusCode($status);
        $this->response->format = Response::FORMAT_JSON;
        $this->response->data = [
            'error' => $error,
            'error_description' => $description,
        ];
        return $this->response;
    }
}
