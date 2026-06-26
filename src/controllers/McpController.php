<?php

namespace craftpulse\cortex\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\exceptions\RateLimitExceededException;
use craftpulse\cortex\mcp\Server;
use craftpulse\cortex\mcp\transport\Http;
use craftpulse\cortex\mcp\transport\SseEmitter;
use craftpulse\cortex\tools\support\InvocationLogger;
use craftpulse\cortex\values\RateLimitStatus;
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
 *   6. Per-user rate limit — 429 + `Retry-After` when the token bucket
 *      is exhausted (Gate 7.6). Burst 60 / sustained 5/sec by default.
 *      DELETE bypasses; consume only runs on the POST dispatch path.
 *   7. `Mcp-Session-Id` header — required on every POST except
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
     *               surface fired.
     */
    private ?int $_authenticatedUserId = null;

    /**
     * @var int|null Row id from `cortex_tokens` when the request was
     *               authenticated via a long-lived bearer token. Null
     *               for OAuth-authenticated requests — OAuth
     *               correlation flows through the `(userId, clientName,
     *               dateCreated)` tuple on the audit row, not via FK,
     *               because the OAuth surface has its own source table
     *               and the audit table carries only one `tokenId` FK
     *               slot (bearer-only). Threaded onto the dispatcher
     *               via `Server::setTokenId()` so the Gate 7.5 audit
     *               log can correlate every HTTP invocation back to
     *               the issuing bearer.
     */
    private ?int $_authenticatedTokenId = null;

    /**
     * @var string[]|null Capability scopes carried by the OAuth access
     *                    token that authenticated this request, or null
     *                    when authentication was via a (non-scope-gated)
     *                    long-lived bearer token. Threaded onto the
     *                    dispatcher in `_handlePost()` so `tools/list` and
     *                    `tools/call` gate on the granted scope set.
     */
    private ?array $_grantedScopes = null;

    /**
     * @var bool Whether this request's bound user carries a live WS2
     *           elevation marker (`cortex:elevation:{userIdHash}`).
     *           Resolved in `beforeAction()` from the per-user elevation
     *           cache; threaded onto the dispatcher in `_handlePost()` so
     *           high-stakes tools (credential / admin mutations, content
     *           publish / delete) can gate. Bearer-token requests leave
     *           this false — elevation rides on OAuth access tokens only,
     *           and bearer tokens have no re-auth handshake to elevate
     *           through.
     */
    private bool $_elevated = false;

    /**
     * @var int|null Post-consume rate-limit headroom for the request,
     *               captured in `beforeAction()` after
     *               `RateLimiter::consume()` succeeds. Threaded onto
     *               the dispatcher in `_handlePost()` so every audit
     *               row carries the bucket pressure for the call.
     *               Null on DELETE (no consume runs), and on the rare
     *               path where `beforeAction()` short-circuits before
     *               reaching the consume.
     */
    private ?int $_rateLimitRemaining = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Seven gates run before the action body sees the request:
     *
     *   1. `parent::beforeAction()` — Yii's standard pipeline.
     *   2. `httpEnabled` 503 check — kill switch.
     *   3. Pro edition 403 check — the HTTP transport is a Pro
     *      surface (PLANNING.md §4: Free ships stdio only).
     *   4. `Origin` allowlist — DNS-rebinding defense.
     *   5. `MCP-Protocol-Version` header.
     *   6. `Authorization: Bearer` lookup against `cortex_tokens`.
     *   7. Per-user rate limit consume (Gate 7.6).
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
        //   2. Pro edition gate.
        //   3. Origin allowlist.
        //   4. DELETE early-exit (no bearer required — see below).
        //   5. Method check (GET = 405, non-POST = 405).
        //   6. MCP-Protocol-Version header.
        //   7. Authorization: Bearer lookup + identity binding.
        //   8. Per-user rate limit consume (Gate 7.6).
        //   9. parent::beforeAction() — now sees an authenticated user.

        $settings = Cortex::getInstance()->getSettings();

        // Gate 1 — kill switch.
        if (!$settings->httpEnabled) {
            $this->_status(503, 'HTTP transport is disabled. Set Settings::$httpEnabled = true to enable.');
            return false;
        }

        // Gate 2 — edition. The Streamable HTTP transport is Pro-only
        // (PLANNING.md §4: the Free tier ships stdio only). 403, not
        // 503 — the kill switch means "configured off"; this means
        // "not licensed". Runs before Origin/bearer work so Free
        // installs spend nothing on requests they will never serve.
        if (!Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
            $this->_status(403, 'The HTTP transport requires the Cortex Pro edition.');
            return false;
        }

        // Gate 3 — Origin allowlist.
        if (!$this->_passesOrigin($settings->allowedOrigins)) {
            return false;
        }

        $method = strtoupper($this->request->getMethod());

        // Gate 4 — DELETE bypasses bearer auth: it's a side-channel
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

        // Gate 4b — GET is reserved for SSE upgrade in 7.7. Reject
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

        // Gate 5 — MCP-Protocol-Version. Validated before auth so
        // wrong-version clients get the spec-shaped 400 they expect
        // rather than a misleading 401.
        if (!$this->_passesProtocolVersion()) {
            return false;
        }

        // Gate 6 — bearer-token authentication. 401 with
        // WWW-Authenticate: Bearer realm="cortex" on every reject
        // path per RFC 6750.
        //
        // Lookup precedence per locked decision 17:
        //   1. OAuth access tokens (short-lived, audience-bound).
        //   2. Long-lived bearer tokens (cortex_tokens).
        //
        // Disambiguation: OAuth JWTs contain dots; opaque bearer tokens
        // are 64-char hex with no dots (`bin2hex(random_bytes(32))` —
        // see `Tokens::issue()`). The shapes are disjoint, so the token
        // format selects exactly one lookup path: a dotted token is
        // treated as an OAuth JWT and is NOT retried as an opaque
        // bearer when the OAuth lookup misses; a dotless token goes
        // straight to the bearer table. Whichever path matches wins;
        // a miss on the selected path → 401.
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
        $this->_authenticatedTokenId = $resolved['tokenId'] ?? null;
        $this->_grantedScopes = $resolved['scopes'] ?? null;
        // WS2 elevation: an OAuth request is elevated only when the bound
        // user holds a live marker (minted by the `/oauth/elevate` fresh-
        // re-auth flow, keyed by user id). Resolved here server-side —
        // never from a client claim, and never from a URL-borne token.
        $this->_elevated = $this->_authenticatedUserId !== null
            && Cortex::getInstance()->oauth->isElevated($this->_authenticatedUserId);

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

        // Gate 6b — per-user rate limit. One token per authenticated
        // POST dispatch. DELETE already returned above, so the consume
        // only runs on the dispatch path. The HTTP rate limit is the
        // backstop against runaway agents that loop `tools/call`
        // faster than the install can serve — burst 60, sustained
        // 5/sec by default. On exhaustion, write a `kind=rate_limited`
        // audit row and 429 the caller with `Retry-After`.
        if ($this->_authenticatedUserId !== null) {
            try {
                $status = Cortex::getInstance()->rateLimiter->consume($this->_authenticatedUserId);
                $this->_rateLimitRemaining = $status->remaining;
            } catch (RateLimitExceededException $e) {
                $this->_writeRateLimitedAuditRow($e->status);
                $this->_rateLimited($e->status);
                return false;
            }
        }

        // Gate 7 — Craft's own beforeAction pipeline. Runs CSRF
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

    // Protected Methods
    // =========================================================================

    /**
     * Construct the SSE emitter used for streaming responses. Pulled
     * out of `_streamPost()` as a seam so test harnesses can inject a
     * capturing writer without standing up a real HTTP socket — the
     * production path returns a default-constructed emitter that
     * writes to PHP's output stream via `echo` + `flush()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildSseEmitter(): SseEmitter
    {
        return new SseEmitter();
    }

    // Private Methods
    // =========================================================================

    /**
     * Validate the `Origin` header against the configured allowlist.
     *
     * An empty allowlist fails CLOSED outside `devMode` — flipping
     * `httpEnabled=true` in production without naming any origin is a
     * DNS-rebinding hole, so a 403 forces the operator to configure
     * `Settings::$allowedOrigins` first. In `devMode` the empty list
     * keeps the warn-and-allow soft-landing so local development isn't
     * blocked.
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
            /** @var \craft\web\Application $app */
            $app = Craft::$app;
            if (!$app->getConfig()->getGeneral()->devMode) {
                $this->_status(
                    403,
                    'HTTP transport refused: configure Settings::$allowedOrigins before enabling '
                    . 'the HTTP transport in production. An empty Origin allowlist is rejected outside devMode.',
                );
                return false;
            }

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
        if (!in_array($version, Server::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $this->_status(400, sprintf(
                'Unsupported MCP-Protocol-Version: "%s". This server supports: %s.',
                $version,
                implode(', ', Server::SUPPORTED_PROTOCOL_VERSIONS),
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

        $sessions = Cortex::getInstance()->sessions;
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

        $sessions = Cortex::getInstance()->sessions;
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
        if ($this->_authenticatedTokenId !== null) {
            $server->setTokenId($this->_authenticatedTokenId);
        }
        // Thread the OAuth capability scope set onto the dispatcher so
        // tools/list and tools/call gate on the granted scopes. Null
        // for bearer-token requests (not scope-gated).
        $server->setGrantedScopes($this->_grantedScopes);
        // Thread the WS2 elevation state so high-stakes tools can gate.
        $server->setElevated($this->_elevated);
        // Thread the session id onto the dispatcher so the audit log
        // can group every invocation in the same HTTP session. Null
        // for the initialize call (session id is minted in the
        // response). Set after dispatch produces the new id below.
        $server->setSessionId($sessionId);
        // Thread the post-consume rate-limit headroom captured in
        // `beforeAction()` onto the dispatcher so every audit row
        // carries the bucket pressure the call landed at.
        $server->setRateLimitRemaining($this->_rateLimitRemaining);

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

            // The dispatcher is fresh per request, so the
            // `clientInfo.name` captured during the original
            // `initialize` doesn't survive to subsequent calls in the
            // same session. Replay it from the session row so audit
            // lines on `tools/list` / `tools/call` carry the same
            // identifier as the initialize line.
            if ($session->clientName !== null) {
                $server->setClientName($session->clientName);
            }
        }

        // SSE upgrade: when the client's `Accept` header advertises
        // `text/event-stream` AND the dispatched method is `tools/call`,
        // route through the streaming dispatcher and pipe each yielded
        // envelope through `SseEmitter`. Non-`tools/call` methods stay
        // on the JSON path even when the client asks for SSE — the spec
        // only mandates the upgrade option on tools/call.
        //
        // Streamable tools yield N progress frames + 1 terminal
        // response; non-streamable tools collapse to one frame
        // (`Server::dispatchStreaming()` handles both paths uniformly).
        $isToolsCall = $method === 'tools/call';
        $clientWantsSse = $this->_acceptsEventStream();
        if ($isToolsCall && $clientWantsSse) {
            return $this->_streamPost($server, $request, $isInitialize, $session->id);
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
     * Whether the client's `Accept` header advertises support for
     * `text/event-stream`. Per the MCP 2025-06-18 spec §"Sending
     * Messages to the Server", clients on the Streamable HTTP
     * transport MUST send `Accept: application/json, text/event-stream`
     * — so most well-behaved clients always pass this check. The
     * controller still gates on it explicitly so JSON-only clients
     * (curl without `-H 'Accept: text/event-stream'`) keep getting
     * single JSON responses.
     *
     * Match is substring on the raw Accept value — the spec doesn't
     * require q-value parsing for this binary "client supports SSE"
     * decision.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _acceptsEventStream(): bool
    {
        $accept = $this->request->getHeaders()->get('Accept');
        if (!is_string($accept) || $accept === '') {
            return false;
        }
        return stripos($accept, SseEmitter::CONTENT_TYPE_SSE) !== false;
    }

    /**
     * Drive a `tools/call` through `Server::dispatchStreaming()` and
     * write each yielded JSON-RPC envelope through `SseEmitter` as one
     * SSE frame on the wire.
     *
     * Yii response framing is bypassed: `SseEmitter::start()` sends
     * the response headers and disables output buffering directly via
     * `header()` + `flush()`, so by the time `actionIndex()` returns
     * Yii's `Response::send()` step finds an empty `FORMAT_RAW` body
     * and is effectively a no-op. The wire has already received every
     * frame.
     *
     * The `Mcp-Session-Id` response header lands on the underlying
     * `$this->response->headers` collection on the initialize path so
     * subsequent calls reference the same session id — but in practice
     * initialize never streams (no `tools/call`), so this defensive
     * branch is unreachable today. Kept for forward-compatibility with
     * a future spec revision that permits initialize-time streaming.
     *
     * **Real TCP-disconnect cooperation (sub-gate 7.7.5).** PHP's
     * default `ignore_user_abort` is `false`, which means the very
     * next write to a closed socket terminates the script abruptly —
     * the streaming dispatcher's post-loop audit-log write would never
     * run and the `kind=cancelled` row would silently disappear. We
     * flip `ignore_user_abort(true)` so the script survives the
     * disconnect, then poll `connection_aborted()` after each emitted
     * frame: on a dead socket we flip the in-flight
     * `CancellationToken` (via `Server::getInFlightCancellationToken()`)
     * with reason `'client disconnected'` and stop emitting further
     * frames — but we keep draining the outer generator so the
     * dispatcher's own cancellation body (`_logCancelled` +
     * `_cancelledEnvelope` yield) runs to completion. Abandoning the
     * generator here would short-circuit the audit write and leave an
     * orphaned row.
     *
     * @param array<string,mixed> $request
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _streamPost(Server $server, array $request, bool $isInitialize, string $sessionId): Response
    {
        // Survive client TCP-disconnects so the post-loop audit-log
        // write runs even after the wire is gone. Paired with the
        // `connection_aborted()` poll after each emit below — this
        // flag alone keeps the script alive, the poll detects the
        // condition and flips the token cooperatively.
        ignore_user_abort(true);

        $emitter = $this->_buildSseEmitter();

        if ($isInitialize) {
            $this->response->headers->set(Http::HEADER_SESSION_ID, $sessionId);
        }
        // SSE response headers go on the underlying Yii response too so
        // Yii's pipeline doesn't append a competing Content-Type. The
        // emitter itself calls `header()` directly — Yii's headers
        // collection is the fallback for whatever the runtime layer
        // sees after the emitter has already written the wire.
        $this->response->headers->set('Content-Type', SseEmitter::CONTENT_TYPE_SSE);
        $this->response->headers->set('Cache-Control', 'no-cache, no-transform');
        $this->response->headers->set('X-Accel-Buffering', 'no');

        $emitter->start();

        $clientGone = false;
        foreach ($server->dispatchStreaming($request) as $envelope) {
            if (!$clientGone) {
                $emitter->emit(SseEmitter::EVENT_NAME, $envelope);

                // Real TCP-disconnect detection. `connection_aborted()`
                // returns 1 once PHP has observed a write-side failure
                // on the response socket; we flip the streaming
                // generator's `CancellationToken` so the dispatcher's
                // inner loop short-circuits through the cancellation
                // path (terminal `notifications/cancelled` envelope +
                // `kind=cancelled` audit row). We keep draining the
                // outer generator so its `_streamToolCall` cancellation
                // body runs to completion — abandoning the generator
                // here would skip the audit-log write and leave an
                // orphaned row.
                if (connection_aborted() === 1) {
                    $clientGone = true;
                    $server->getInFlightCancellationToken()?->cancel('client disconnected');
                }
            }
        }

        $emitter->end();

        // Terminate Yii's pipeline. The emitter has already written
        // every byte to the SAPI via `header()` + `echo` + `flush()`;
        // letting Yii's `Response::send()` run after the fact raises
        // `HeadersAlreadySentException` because the headers have
        // physically left. We mark the response as already sent so
        // Yii's send() turns into a no-op and exit the controller
        // pipeline cleanly.
        //
        // The test harness leaves `_writer` set on the emitter, so
        // headers were NOT physically sent — in that case the
        // `isSent` short-circuit also avoids Yii double-writing into
        // the test's response object, keeping the test's view of the
        // status code + headers consistent with what would land on
        // the wire in production.
        $this->response->format = Response::FORMAT_RAW;
        $this->response->setStatusCode(200);
        $this->response->content = '';
        $this->response->isSent = true;
        return $this->response;
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
     * The bearer path carries the resolved `cortex_tokens.id` so the
     * Gate 7.5 audit log can FK-correlate the invocation back to the
     * issuing bearer row. The OAuth path leaves `tokenId` null —
     * OAuth tokens live in a separate table (`cortex_oauth_tokens`)
     * and the audit table's single `tokenId` FK slot is bearer-only
     * per the locked schema decision in the migration's docblock.
     *
     * The OAuth path additionally carries the token's capability
     * `scopes` (parsed from the space-delimited `scope` claim) so the
     * dispatcher can gate `tools/list` / `tools/call` on the granted
     * set. The bearer path leaves `scopes` null — long-lived bearer
     * tokens are admin-issued and not scope-gated (their gating is the
     * user-permission + edition path).
     *
     * The OAuth path also carries the token's `jti` so the controller
     * can check the WS2 elevation cache; the bearer path leaves it null
     * (bearer tokens have no elevation handshake).
     *
     * @return array{userId:?int, tokenId:?int, scopes:?array<int,string>, jti:?string}|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveBearer(string $bearer): ?array
    {
        $hasDots = str_contains($bearer, '.');
        $expectedAudience = UrlHelper::siteUrl('cortex/mcp');

        if ($hasDots) {
            $oauthHit = Cortex::getInstance()->oauth->lookupAccessToken($bearer);
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

            $scopeClaim = is_string($oauthHit['scope'] ?? null) ? $oauthHit['scope'] : '';
            $scopes = preg_split('/\s+/', trim($scopeClaim), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'userId' => $oauthHit['userId'],
                'tokenId' => null,
                'scopes' => $scopes,
                'jti' => $oauthHit['jti'] ?? null,
            ];
        }

        $token = Cortex::getInstance()->tokens->lookup($bearer);
        if ($token === null) {
            return null;
        }
        return ['userId' => $token->userId, 'tokenId' => $token->id, 'scopes' => null, 'jti' => null];
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

    /**
     * Write a 429 with the canonical `Retry-After: <seconds>` header
     * and a JSON body describing the rate-limit exhaustion. Parallel
     * to `_unauthorized()` but for the Gate 7.6 rate-limit reject
     * path. The status's `retryAfter` field carries the integer
     * second count the caller should wait — stamped onto the header
     * directly so well-behaved clients self-throttle.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _rateLimited(RateLimitStatus $status): Response
    {
        $this->response->headers->set('Retry-After', (string) $status->retryAfter);
        return $this->_status(429, sprintf(
            'Rate limit exceeded; retry after %ds.',
            $status->retryAfter,
        ));
    }

    /**
     * Write a `kind=rate_limited` row to `cortex_invocations` so the
     * audit dashboard captures the throttle event with the same
     * forensic shape every successful invocation uses. The row's
     * `toolName` is a sentinel (`_rate_limited`) because the request
     * never reaches the dispatcher — the body might not be valid
     * JSON, the tool name might not even be present, so the throttle
     * never depends on body parsing for correctness. Operators
     * filtering the audit table by `kind=rate_limited` pick up the
     * event regardless.
     *
     * The write goes through `Invocations::record()` so the soft-
     * write contract applies — a DB failure inside the audit path
     * cannot break the throttle response. Failures log to the
     * `cortex.audit` category and surface to operators tailing
     * the file log.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _writeRateLimitedAuditRow(RateLimitStatus $status): void
    {
        $sessionIdHeader = $this->request->getHeaders()->get(Http::HEADER_SESSION_ID);
        $sessionId = is_string($sessionIdHeader) && $sessionIdHeader !== '' ? $sessionIdHeader : null;

        Cortex::getInstance()->invocations->record([
            'tool' => '_rate_limited',
            'kind' => InvocationLogger::KIND_RATE_LIMITED,
            'duration_ms' => 0,
            'transport' => Server::TRANSPORT_HTTP,
            'request_id' => null,
            'user' => $this->_authenticatedUserId,
            'client' => null,
            'token_id' => $this->_authenticatedTokenId,
            'session_id' => $sessionId,
            'rate_limit_remaining' => 0,
            'args' => null,
            'response_excerpt' => null,
            'error_class' => RateLimitExceededException::class,
            'error_message' => sprintf('Rate limit exceeded; retry after %ds', $status->retryAfter),
        ]);
    }
}
