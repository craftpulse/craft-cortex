<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\mcp\transport\Http;
use craftpulse\cortex\Plugin;
use yii\web\Response;

/**
 * =========================================================================
 * HTTP transport entry point — `POST/GET/DELETE /cortex/mcp`.
 *
 * Parallel to the stdio loop in `console/controllers/ServeController`,
 * but for the Streamable HTTP transport per MCP 2025-06-18. Header
 * validation, session lookup, bearer-token authentication, and
 * dispatcher construction live here; the transport-agnostic JSON-RPC
 * routing lives in `mcp/Server`.
 *
 * Sub-gate 7.2 lifts the 7.1 anonymous-allowed posture: every request
 * must carry `Authorization: Bearer <token>`, where `<token>` resolves
 * to a live row in `cortex_tokens`. Missing / malformed / unknown
 * credentials return 401 with `WWW-Authenticate: Bearer realm="cortex"`
 * per RFC 6750. The authenticated user is bound to the session at
 * initialize and validated against the bearer token on every touch —
 * mid-session token-swap (initialize with token A, then `tools/call`
 * with token B for a different user) is detected and the session is
 * terminated.
 *
 * Bearer lookup happens once per request in `beforeAction()`. In-flight
 * requests on a revoked token complete normally — revocation only
 * blocks the *next* request — per the locked decision in
 * `docs/plans/gate-7.md` item 16. The `CancellationToken` mechanism
 * on `InvocationContext` is reserved for the explicit
 * `notifications/cancelled` MCP message in sub-gate 7.7, not for
 * server-side token revocation.
 *
 * Request-level enforcement order:
 *
 *   1. `httpEnabled` flag — 503 if off (`Retry-After: 0` advisory).
 *   2. `Origin` header — 403 if the allowlist is non-empty and the
 *      header doesn't match (DNS-rebinding defense per MCP spec).
 *   3. HTTP method — POST is JSON-RPC, GET is 405 in 7.1/7.2 (SSE
 *      upgrade lands in 7.7), DELETE terminates the session and
 *      returns 204.
 *   4. `MCP-Protocol-Version` header — 400 if missing or unrecognised.
 *   5. `Authorization: Bearer <token>` — 401 with `WWW-Authenticate`
 *      if missing / malformed / unknown.
 *   6. `Mcp-Session-Id` header — required on every POST except
 *      `initialize`. Missing → 400; unknown / terminated → 404 per
 *      the spec.
 *
 * `$enableCsrfValidation = false` because this is a JSON-RPC endpoint
 * keyed by header-based auth, not a CP form keyed by Craft session.
 * `$allowAnonymous = []` because every request authenticates via
 * bearer token.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class McpController extends Controller
{
    // Protected Properties
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Gate 7.2 flips this from `['index']` to `[]` — every action on
     * this controller authenticates via bearer token in
     * `beforeAction()`.
     */
    protected array|int|bool $allowAnonymous = [];

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Private Properties
    // =========================================================================

    /**
     * @var int|null Craft user id resolved by `beforeAction()`. The
     *               OAuth path sets this from the JWT `sub` claim;
     *               the bearer path sets this from the token's bound
     *               user id. Single source of truth that
     *               `_handlePost()` reads, independent of which auth
     *               surface fired. Gate 7.5 audit log will additionally
     *               correlate the invocation to the issuing bearer-
     *               token row via a separate lookup at logging time.
     */
    private ?int $_authenticatedUserId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Five gates run before the action body sees the request:
     *
     *   1. `parent::beforeAction()` — Yii's standard pipeline.
     *   2. `httpEnabled` 503 check — kill switch.
     *   3. `Origin` allowlist — DNS-rebinding defense.
     *   4. `MCP-Protocol-Version` header.
     *   5. `Authorization: Bearer` lookup against `cortex_tokens`.
     *
     * DELETE skips the bearer check because it carries no JSON-RPC
     * payload and only references an `Mcp-Session-Id` for
     * termination — clients that have already lost their session
     * (e.g. server-side revocation, cache eviction) can still call
     * DELETE to clean up their local state without re-authenticating.
     * Every other method MUST carry a valid bearer.
     *
     * Returns true to continue with `actionIndex()`; false (with the
     * response populated) to short-circuit.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function beforeAction($action): bool
    {
        // Run the cortex transport gates BEFORE the Craft parent's
        // `beforeAction()` because the parent's `_enforceAllowAnonymous`
        // throws on guest requests for a non-CP controller with
        // `$allowAnonymous = []`. Setting the bearer-bound identity
        // here flips the guest flag before the parent sees it.
        //
        // Order:
        //   1. httpEnabled kill switch.
        //   2. Origin allowlist.
        //   3. DELETE early-exit (no bearer required — see below).
        //   4. Method check (GET = 405, non-POST = 405).
        //   5. MCP-Protocol-Version header.
        //   6. Authorization: Bearer lookup + identity binding.
        //   7. parent::beforeAction() — now sees an authenticated user.

        $settings = Plugin::getInstance()->getSettings();

        // Gate 1 — kill switch.
        if (!$settings->httpEnabled) {
            $this->_status(503, 'HTTP transport is disabled. Set Settings::$httpEnabled = true to enable.');
            return false;
        }

        // Gate 2 — Origin allowlist.
        if (!$this->_passesOrigin($settings->allowedOrigins)) {
            return false;
        }

        $method = strtoupper($this->request->getMethod());

        // Gate 3 — DELETE bypasses bearer auth: it's a side-channel
        // for session cleanup that doesn't carry a JSON-RPC payload
        // and references only the opaque session id. The session id
        // itself is unguessable (`random_bytes(16)`), and termination
        // is idempotent and harmless — the worst case for an
        // anonymous DELETE with a guessed id is the legitimate owner
        // re-initializes on their next request.
        //
        // DELETE skips the Craft parent's `_enforceAllowAnonymous`
        // entirely because we return early — that gate's guest check
        // would otherwise force authentication for a logically-
        // unauthenticated cleanup call.
        if ($method === 'DELETE') {
            return true;
        }

        // Gate 3b — GET is reserved for SSE upgrade in 7.7. Reject
        // before authenticating so we don't burn a DB probe on a
        // method that can't proceed anyway.
        if ($method === 'GET') {
            $this->_status(405, 'GET is not supported on this endpoint. SSE upgrade lands in a future release.');
            return false;
        }

        if ($method !== 'POST') {
            $this->_status(405, "Method {$method} is not supported on this endpoint.");
            return false;
        }

        // Gate 4 — MCP-Protocol-Version. Validated before auth so
        // wrong-version clients get the spec-shaped 400 they expect
        // rather than a misleading 401.
        if (!$this->_passesProtocolVersion()) {
            return false;
        }

        // Gate 5 — bearer-token authentication. 401 with
        // WWW-Authenticate: Bearer realm="cortex" on every reject
        // path per RFC 6750.
        //
        // Lookup precedence per locked decision 17:
        //   1. OAuth access tokens (short-lived, audience-bound).
        //   2. Long-lived bearer tokens (cortex_tokens).
        //
        // Disambiguation: JWTs contain dots, bearer tokens are 64-char
        // hex without dots. Probe OAuth first when dots present;
        // fall back to bearer when the OAuth lookup misses. Either
        // path resolving wins; both missing → 401.
        $bearer = $this->_extractBearer();
        if ($bearer === null) {
            $this->_unauthorized('Missing or malformed Authorization header. Expected: Authorization: Bearer <token>.');
            return false;
        }

        $resolved = $this->_resolveBearer($bearer);
        if ($resolved === null) {
            $this->_unauthorized('Invalid or revoked bearer token.');
            return false;
        }

        $this->_authenticatedUserId = $resolved['userId'];

        // Bind the resolved user onto Craft's auth surface so the
        // parent `_enforceAllowAnonymous` gate sees a non-guest, and
        // downstream permission checks (Gate 7.4 per-user filtering
        // and Pro tools that gate on Craft permissions) see the
        // bearer's identity.
        if ($resolved['userId'] !== null) {
            $user = Craft::$app->getUsers()->getUserById($resolved['userId']);
            if ($user !== null) {
                Craft::$app->getUser()->setIdentity($user);
            }
        }

        // Gate 6 — Craft's own beforeAction pipeline. Runs CSRF
        // exemption, `_enforceAllowAnonymous`, and any further
        // base-class checks. With the identity bound above, the
        // `allowAnonymous = []` posture passes for the authenticated
        // user (i.e. no `ForbiddenHttpException` thrown on the way in).
        return parent::beforeAction($action);
    }

    /**
     * Dispatch one HTTP request to the MCP server. Branches on the HTTP
     * method:
     *
     *   - POST: parses the body, validates session affinity, hands the
     *     JSON-RPC envelope to `Server::dispatch()`, surfaces the
     *     response as `application/json`.
     *   - GET: 405 in sub-gates 7.1–7.2. SSE upgrade lands in 7.7.
     *   - DELETE: terminates the session referenced by
     *     `Mcp-Session-Id`. 204 No Content on success; 404 if the
     *     session is unknown.
     *
     * Errors surface either as HTTP status codes (header validation
     * failures, transport-level rejections) or as JSON-RPC error
     * envelopes (parse / shape errors with valid headers). The stdio-
     * only rejection for `craft_exec` is a JSON-RPC error envelope
     * carried at HTTP 200 — that's the spec-shaped client expectation
     * and the dispatcher already produces it.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function actionIndex(): Response
    {
        $method = strtoupper($this->request->getMethod());

        if ($method === 'DELETE') {
            return $this->_handleDelete();
        }

        // GET / wrong-method / header validation failures already
        // short-circuited in `beforeAction()` with the response
        // populated. By the time `actionIndex()` runs, we know the
        // request is a POST with a valid protocol version and a
        // resolved bearer token.
        return $this->_handlePost();
    }

    // Private Methods
    // =========================================================================

    /**
     * Validate the `Origin` header against the configured allowlist.
     * Empty allowlist is permissive in dev — log a warning so
     * operators that flip `httpEnabled=true` without configuring
     * `allowedOrigins` see the soft-landing diagnostic in the log.
     *
     * Returns true to continue, false (with the response populated)
     * to short-circuit.
     *
     * @param string[] $allowedOrigins
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _passesOrigin(array $allowedOrigins): bool
    {
        $origin = $this->request->getHeaders()->get(Http::HEADER_ORIGIN);

        if ($allowedOrigins === []) {
            Craft::warning(
                'cortex HTTP transport: Origin allowlist is empty and httpEnabled is true. '
                . 'Configure Settings::$allowedOrigins for any non-dev environment.',
                'cortex',
            );
            return true;
        }

        if (!is_string($origin) || $origin === '') {
            $this->_status(403, 'Origin header is required.');
            return false;
        }

        if (!in_array($origin, $allowedOrigins, true)) {
            $this->_status(403, 'Origin not permitted.');
            return false;
        }

        return true;
    }

    /**
     * Validate the `MCP-Protocol-Version` header. Returns true to
     * continue, false (with the response populated) to short-circuit.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _passesProtocolVersion(): bool
    {
        $version = $this->request->getHeaders()->get(Http::HEADER_PROTOCOL_VERSION);
        if (!is_string($version) || $version === '') {
            $this->_status(400, 'Missing MCP-Protocol-Version header.');
            return false;
        }
        if ($version !== Server::PROTOCOL_VERSION) {
            $this->_status(400, sprintf(
                'Unsupported MCP-Protocol-Version: "%s". This server supports "%s".',
                $version,
                Server::PROTOCOL_VERSION,
            ));
            return false;
        }
        return true;
    }

    /**
     * Pull the bearer token out of the `Authorization` header. Returns
     * the raw plaintext on success, null when:
     *
     *   - the header is absent,
     *   - the header value doesn't start with `Bearer ` (case-
     *     insensitive per RFC 6750),
     *   - the token portion is empty.
     *
     * The plaintext returned here is never logged. Callers hash it
     * before any persistent surface.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _extractBearer(): ?string
    {
        $header = $this->request->getHeaders()->get('Authorization');
        if (!is_string($header) || $header === '') {
            return null;
        }

        // RFC 6750 §2.1: the scheme is `Bearer` (case-insensitive)
        // followed by a single space and the token. `stripos`
        // anchored at offset 0 is the cleanest case-insensitive match.
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $bearer = trim(substr($header, 7));
        return $bearer !== '' ? $bearer : null;
    }

    /**
     * Handle a DELETE — terminate the referenced session. The spec
     * lets servers respond 405 if they don't allow client-initiated
     * termination; cortex does allow it, so we treat DELETE as a
     * first-class operation.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handleDelete(): Response
    {
        $sessionId = $this->request->getHeaders()->get(Http::HEADER_SESSION_ID);
        if (!is_string($sessionId) || $sessionId === '') {
            return $this->_status(400, 'Missing Mcp-Session-Id header.');
        }

        $sessions = Plugin::getInstance()->sessions;
        if ($sessions->get($sessionId) === null) {
            return $this->_status(404, 'Session not found.');
        }

        $sessions->terminate($sessionId);

        $this->response->setStatusCode(204);
        return $this->response;
    }

    /**
     * Handle a POST — parse the body, validate session affinity,
     * dispatch, surface the response.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handlePost(): Response
    {
        $transport = new Http();
        $body = $this->request->getRawBody();
        $request = $transport->parseRequest($body);
        if ($request === null) {
            return $this->_jsonRpcResponse($transport->parseError());
        }

        $method = is_string($request['method'] ?? null) ? $request['method'] : '';
        $isInitialize = $method === 'initialize';

        $sessions = Plugin::getInstance()->sessions;
        $sessionIdHeader = $this->request->getHeaders()->get(Http::HEADER_SESSION_ID);
        $sessionId = is_string($sessionIdHeader) && $sessionIdHeader !== '' ? $sessionIdHeader : null;

        // The authenticated user id is guaranteed set here —
        // `beforeAction()` short-circuits any path that doesn't
        // resolve one before reaching the action body.
        $authenticatedUserId = $this->_authenticatedUserId;

        // The dispatcher uses the captured client name as the session's
        // `clientName`; capture it from the request before constructing
        // the server so initialize and the session row converge on the
        // same value.
        $server = new Server(Server::TRANSPORT_HTTP);
        if ($authenticatedUserId !== null) {
            $server->setUserId($authenticatedUserId);
        }

        if ($isInitialize) {
            // Initialize starts a fresh session. Any previously-issued
            // header on this request is informational only — we ignore
            // it and mint a new id. The session binds the authenticated
            // userId at creation so subsequent touches can detect a
            // mid-session token swap.
            $clientName = $this->_clientNameFromInitialize($request['params'] ?? []);
            $session = $sessions->create(
                protocolVersion: Server::PROTOCOL_VERSION,
                clientName: $clientName,
                userId: $authenticatedUserId,
            );
        } else {
            // Every non-initialize POST MUST carry an Mcp-Session-Id
            // per the spec; missing → 400, unknown → 404.
            if ($sessionId === null) {
                return $this->_status(400, 'Missing Mcp-Session-Id header (required on every request except initialize).');
            }
            $session = $sessions->get($sessionId);
            if ($session === null) {
                return $this->_status(404, 'Session not found. Send `initialize` to start a new session.');
            }

            // Touch validates the bearer's user id against the
            // session's bound user id. Mismatch → terminate +
            // return null → we 401 to force a re-handshake.
            $touched = $sessions->touch($sessionId, $authenticatedUserId);
            if ($touched === null) {
                return $this->_unauthorized('Session does not match the presented bearer token.');
            }
        }

        $response = $server->dispatch($request);

        // Notifications get acknowledged silently per JSON-RPC 2.0 spec.
        // The MCP HTTP transport surfaces them as 202 Accepted with an
        // empty body.
        if ($response === null) {
            $this->response->setStatusCode(202);
            if ($isInitialize) {
                $this->response->headers->set(Http::HEADER_SESSION_ID, $session->id);
            }
            return $this->response;
        }

        $httpResponse = $this->_jsonRpcResponse($response);
        if ($isInitialize) {
            $httpResponse->headers->set(Http::HEADER_SESSION_ID, $session->id);
        }
        return $httpResponse;
    }

    /**
     * Pull `clientInfo.name` out of an `initialize` request's params,
     * or null when absent / malformed. The Server itself captures the
     * same name during dispatch (for audit logging); this controller
     * needs the value before dispatch to write it onto the session
     * row at creation time, so we duplicate the parse here rather
     * than depending on Server's internal capture timing.
     *
     * @param array<string,mixed>|mixed $params
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _clientNameFromInitialize(mixed $params): ?string
    {
        if (!is_array($params)) {
            return null;
        }
        $clientInfo = $params['clientInfo'] ?? null;
        if (!is_array($clientInfo)) {
            return null;
        }
        $name = $clientInfo['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        return $name;
    }

    /**
     * Write a 401 with the canonical `WWW-Authenticate: Bearer realm="cortex"`
     * challenge header and a JSON body. RFC 6750 §3 requires the
     * challenge header on every 401 from a bearer-protected resource;
     * RFC 9728 §5.1 extends it with `resource_metadata=` so
     * unauthenticated clients can discover the authorization server
     * without prior configuration.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _unauthorized(string $message): Response
    {
        $resourceMetadata = UrlHelper::siteUrl('.well-known/oauth-protected-resource');
        $this->response->headers->set(
            'WWW-Authenticate',
            sprintf('Bearer realm="cortex", resource_metadata="%s"', $resourceMetadata),
        );
        return $this->_status(401, $message);
    }

    /**
     * Resolve a presented bearer string to a Craft user id, or null
     * on miss. OAuth-first lookup per locked decision 17 — short-
     * lived audience-bound tokens win over long-lived bearers when
     * both could theoretically match. In practice the shapes are
     * disjoint (JWTs have dots; bearer tokens are 64-char hex), but
     * the precedence order is what's locked.
     *
     * Audience verification: the OAuth path verifies the `aud` claim
     * matches the canonical MCP endpoint URL. A token issued for
     * resource A presented at resource B is rejected — RFC 8707
     * confused-deputy defense.
     *
     * @return array{userId:?int}|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveBearer(string $bearer): ?array
    {
        $hasDots = str_contains($bearer, '.');
        $expectedAudience = UrlHelper::siteUrl('cortex/mcp');

        if ($hasDots) {
            $oauthHit = Plugin::getInstance()->oauth->lookupAccessToken($bearer);
            if ($oauthHit === null) {
                return null;
            }

            // Audience binding. A token's `aud` must match the URL
            // the client is presenting it at. Mismatch → reject as
            // if the token didn't exist.
            $audience = $oauthHit['audience'] ?? null;
            if (!is_string($audience) || $audience === '' || !$this->_audienceMatches($audience, $expectedAudience)) {
                return null;
            }

            return ['userId' => $oauthHit['userId']];
        }

        $token = Plugin::getInstance()->tokens->lookup($bearer);
        if ($token === null) {
            return null;
        }
        return ['userId' => $token->userId];
    }

    /**
     * Audience comparison that tolerates trailing slashes. Both
     * candidate values may carry or omit the trailing `/` depending
     * on how the client built them; we normalize both before
     * comparing.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _audienceMatches(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    /**
     * Build a plain HTTP status response with a JSON `{"error": "…"}`
     * body. Used for header / transport-level rejections. For JSON-
     * RPC-shaped errors (parse error, dispatcher rejections) see
     * `_jsonRpcResponse()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _status(int $code, string $message): Response
    {
        $this->response->format = Response::FORMAT_JSON;
        $this->response->setStatusCode($code);
        $this->response->data = ['error' => $message];
        return $this->response;
    }

    /**
     * Shape a JSON-RPC response array onto the Yii response. Status is
     * always 200 — the JSON-RPC envelope carries the error code, per
     * the spec. Body encoded by the transport adapter so the encoding
     * policy stays in one place.
     *
     * @param array<string,mixed> $envelope
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _jsonRpcResponse(array $envelope): Response
    {
        $transport = new Http();
        $this->response->format = Response::FORMAT_RAW;
        $this->response->headers->set('Content-Type', Http::CONTENT_TYPE_JSON);
        $this->response->setStatusCode(200);
        $this->response->content = $transport->encodeResponse($envelope);
        return $this->response;
    }
}
