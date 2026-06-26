<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\elements\User;
use craft\web\View;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\oauth\entities\UserEntity;
use craftpulse\cortex\records\OauthClient as OauthClientRecord;
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
 * `$enableCsrfValidation = false` by default because `token`,
 * `register`, and `revoke` are anonymous PSR-7 / token-proof endpoints
 * with no Craft session — a CSRF token would be meaningless there.
 * `authorize` is the exception: its consent POST is a session-backed
 * "approve this client for *your* Craft account" form, so it IS
 * CSRF-vulnerable (PKCE protects the code→token exchange, not the
 * consent grant). `beforeAction()` re-enables CSRF validation for that
 * one action; league's `validateAuthorizationRequest()` reads the OAuth
 * parameters from the query string (not the body), so the consent
 * GET→POST round-trip still resolves with CSRF on.
 *
 * Extends `AbstractOauthController` for the shared `httpEnabled` kill
 * switch — every action returns 503 before any DB work or DCR insert
 * when `Settings::$httpEnabled` is false.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthController extends AbstractOauthController
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = ['token', 'register', 'revoke'];

    // (`elevate` is intentionally absent — it requires a live Craft
    // session, like `authorize`.)

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * The three unauthenticated OAuth endpoints get the IP-keyed
     * throttle — `register` first (an unauthenticated bcrypt-per-call
     * plus a row insert), `token` and `revoke` alongside. `authorize`
     * is excluded: it requires a live Craft session, so the per-user
     * surface already covers it.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _throttledActionIds(): array
    {
        return ['register', 'token', 'revoke'];
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Re-enables CSRF validation for the `authorize` action only. The
     * consent POST is session-backed, so it must carry a valid CSRF
     * token — the template at `oauth/authorize.twig` already embeds one.
     * `token` / `register` / `revoke` keep `$enableCsrfValidation =
     * false`: they're anonymous token-proof endpoints with no session.
     *
     * The toggle is set before delegating to
     * `AbstractOauthController::beforeAction()`, which runs the
     * `httpEnabled` kill switch and IP throttle and then hands off to
     * Craft's pipeline — where Yii performs the CSRF check on unsafe
     * methods.
     *
     * @throws \yii\web\BadRequestHttpException When the `authorize`
     *         consent POST is missing or carries an invalid CSRF token.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function beforeAction($action): bool
    {
        // `authorize` and `elevate` are the two session-backed consent
        // POSTs — they must carry a valid CSRF token. The token-proof
        // endpoints (`token` / `register` / `revoke`) keep CSRF off.
        if ($action->id === 'authorize' || $action->id === 'elevate') {
            $this->enableCsrfValidation = true;
        }

        return parent::beforeAction($action);
    }

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

        $oauth = Cortex::getInstance()->oauth;

        // DCR approval gate (WS3): an unapproved client is invisible to
        // league's `ClientRepository`, so `validateAuthorizationRequest`
        // would surface a generic `invalid_client`. Detect the
        // unapproved-but-registered case first and render a clear
        // "pending admin approval" page so the operator knows the
        // client exists and just needs approving on the Clients CP
        // screen.
        $pendingResponse = $this->_rejectIfClientPendingApproval();
        if ($pendingResponse !== null) {
            return $pendingResponse;
        }

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

        $oauth = Cortex::getInstance()->oauth;
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

        if (!Cortex::getInstance()->getSettings()->dcrEnabled) {
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
            $response = Cortex::getInstance()->oauth->registerClient($payload);
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
            Cortex::getInstance()->oauth->revokeToken($token);
        }

        $this->response->setStatusCode(200);
        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = '';
        return $this->response;
    }

    /**
     * GET / POST `/oauth/elevate` — in-band high-stakes elevation (WS2).
     *
     * High-stakes operations over the HTTP transport (credential / email
     * / admin-status mutations on `users`, and content publish / delete)
     * require a *fresh* re-authentication that is genuinely independent of
     * Craft's ambient elevated-session state. `requireElevatedSession()`
     * is deliberately NOT used: it never prompts for a password (it only
     * throws when the session isn't already elevated), and Craft treats a
     * recent CP login — or any session when `elevatedSessionDuration ===
     * 0` — as already elevated. Relying on it would mint elevation off an
     * ambient session with no fresh challenge.
     *
     * The flow:
     *
     *   1. Requires a live Craft CP session — anonymous visitors are
     *      redirected to the CP login (with a return URL) by Craft's
     *      standard pipeline, since `elevate` is not in `$allowAnonymous`.
     *   2. GET renders a CP-context page with a password field + CSRF.
     *   3. POST verifies the submitted password against the *currently
     *      logged-in* user via `User::authenticate()` — the same
     *      credential path `users/login` uses, which also runs the
     *      account status checks (locked / cooldown / suspended). When the
     *      account has an active 2FA method, password-only elevation is
     *      refused and the operator is told to elevate via Craft's native
     *      elevated-session flow in the CP first (Cortex does not
     *      re-implement the full WebAuthn / TOTP challenge here).
     *   4. Only after that verification succeeds does it mint the per-user
     *      elevation marker for `Settings::$elevationTtl` seconds.
     *
     * Elevation is keyed by the logged-in user's id — never by the access
     * token, and the access token never appears in the URL. The MCP
     * dispatcher checks the marker against the userId the presented access
     * token is bound to, so the logged-in user who elevates and the token
     * owner are the same account. Per-user elevation within the short TTL
     * is acceptable and mirrors Craft's own (per-user) session-elevation
     * model.
     *
     * @throws \yii\base\InvalidConfigException When the OAuth keys are
     *         not yet generated.
     * @throws \Throwable                       from template rendering.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionElevate(): Response
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;
        $identity = $app->getUser()->getIdentity();
        if (!$identity instanceof User) {
            throw new \yii\web\ForbiddenHttpException('OAuth elevation requires an authenticated Craft user.');
        }

        if ($identity->id === null) {
            throw new \yii\web\ForbiddenHttpException('OAuth elevation requires a saved Craft user.');
        }

        if (strtoupper($this->request->getMethod()) === 'POST') {
            return $this->_completeElevation($identity);
        }

        return $this->_renderElevateScreen($identity);
    }

    // Private Methods
    // =========================================================================

    /**
     * Verify the submitted password against the logged-in user and mint
     * the per-user elevation marker on success. Re-renders the elevate
     * screen with an error on failure. Password-only elevation is refused
     * for accounts with an active 2FA method — those must use Craft's
     * native elevated-session flow in the CP first.
     *
     * @throws \Throwable from template rendering.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _completeElevation(User $identity): Response
    {
        // Accounts with active 2FA can't be safely re-challenged with a
        // bare password field — refuse and point at Craft's native flow
        // rather than mint a weaker elevation than the account's own
        // login posture.
        if ($this->_userHasActiveMfa($identity)) {
            return $this->_renderElevateScreen(
                $identity,
                Craft::t(
                    'cortex',
                    'Your account uses two-step verification. Start an elevated session from the Craft control panel first, then retry.',
                ),
            );
        }

        $password = $this->request->getBodyParam('password');
        if (!is_string($password) || $password === '') {
            return $this->_renderElevateScreen(
                $identity,
                Craft::t('cortex', 'Enter your password to confirm elevated access.'),
            );
        }

        // `authenticate()` validates the password AND runs the account
        // status checks (locked / cooldown / suspended) — the same path
        // `users/login` uses. A fresh challenge, independent of any
        // ambient elevated-session state.
        if (!$identity->authenticate($password)) {
            return $this->_renderElevateScreen(
                $identity,
                Craft::t('cortex', 'Incorrect password.'),
            );
        }

        Cortex::getInstance()->oauth->grantElevation((int) $identity->id);

        return $this->_renderElevateTemplate('cortex/oauth/elevated', [
            'ttl' => Cortex::getInstance()->getSettings()->elevationTtl,
        ]);
    }

    /**
     * Render the elevate confirm screen (password challenge). Optionally
     * carries an error message after a failed POST.
     *
     * @throws \Throwable from template rendering.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renderElevateScreen(User $identity, ?string $error = null): Response
    {
        return $this->_renderElevateTemplate('cortex/oauth/elevate', [
            'username' => $identity->username ?? $identity->email,
            'error' => $error,
        ]);
    }

    /**
     * Render a CP-context elevate template onto the response. Isolated as
     * a protected seam so the auth + mint decision in `_completeElevation`
     * can be exercised in isolation from CP Twig rendering, which needs a
     * live web request the console-bootstrapped test harness can't supply.
     *
     * @param array<string,mixed> $variables
     * @throws \Throwable from template rendering.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _renderElevateTemplate(string $template, array $variables): Response
    {
        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = Craft::$app->getView()->renderTemplate(
            $template,
            $variables,
            View::TEMPLATE_MODE_CP,
        );
        return $this->response;
    }

    /**
     * Whether the user has an active two-step-verification method (and
     * 2FA is not globally disabled). Mirrors the `users/login` check.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _userHasActiveMfa(User $identity): bool
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;
        if ($app->getConfig()->getGeneral()->disable2fa) {
            return false;
        }
        return $app->getAuth()->hasActiveMethod($identity);
    }

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
     * Rendered with `View::TEMPLATE_MODE_CP` explicitly — `/oauth/
     * authorize` is a SITE request, and the `cortex/...` plugin
     * template root only resolves in CP template mode (a site-mode
     * render throws `TemplateLoaderException`; caught by the gate-9
     * browser smoke).
     *
     * @throws \Throwable from template rendering.
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

        // Build a {scope => plain-English description} map so the
        // consent screen renders one capability line per requested
        // scope without baking the vocabulary into the template.
        $scopesService = Cortex::getInstance()->scopes;
        $scopeDescriptions = [];
        foreach ($scopes as $scope) {
            $scopeDescriptions[$scope] = $scopesService->describe($scope);
        }

        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = Craft::$app->getView()->renderTemplate(
            'cortex/oauth/authorize',
            [
                'clientName' => $authRequest->getClient()->getName(),
                'clientId' => $authRequest->getClient()->getIdentifier(),
                'scopes' => $scopes,
                'scopeDescriptions' => $scopeDescriptions,
                'username' => $user->username ?? $user->email,
                'query' => $this->request->getQueryParams(),
            ],
            View::TEMPLATE_MODE_CP,
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
            $psrResponse = Cortex::getInstance()
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
     * Render a "pending admin approval" page when the `client_id` on
     * the authorize request resolves to a registered-but-unapproved
     * client. Returns null when the client is approved, unknown, or
     * the `client_id` param is absent — in every one of those cases
     * league's own validation is left to run and produce the right
     * OAuth error.
     *
     * The plaintext-safe `clientName` is rendered through the consent
     * template's escaping; no client-controlled value is emitted raw.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _rejectIfClientPendingApproval(): ?Response
    {
        $clientId = $this->request->getQueryParam('client_id');
        if (!is_string($clientId) || $clientId === '') {
            return null;
        }

        $record = OauthClientRecord::findOne(['clientId' => $clientId]);
        if (!$record instanceof OauthClientRecord || (bool) $record->approved) {
            return null;
        }

        $this->response->setStatusCode(403);
        $this->response->format = Response::FORMAT_HTML;
        $this->response->content = Craft::$app->getView()->renderTemplate(
            'cortex/oauth/pending-approval',
            ['clientName' => (string) $record->clientName],
            View::TEMPLATE_MODE_CP,
        );
        return $this->response;
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
