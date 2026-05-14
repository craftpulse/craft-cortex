<?php

namespace craftpulse\cortex\controllers;

use Craft;
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
 * validation, session lookup, and dispatcher construction live here;
 * the transport-agnostic JSON-RPC routing lives in `mcp/Server`.
 *
 * Sub-gate 7.1 scope: skeleton only. No auth — `$allowAnonymous` lifts
 * in 7.2 when bearer tokens land. No rate limit (7.6). No SSE upgrade
 * (7.7). The endpoint is gated behind `Settings::$httpEnabled = false`
 * by default so production installs stay off until per-user filtering
 * (7.4) ships.
 *
 * Request-level enforcement order:
 *
 *   1. `httpEnabled` flag — 503 if off (`Retry-After: 0` advisory).
 *   2. `Origin` header — 403 if the allowlist is non-empty and the
 *      header doesn't match (DNS-rebinding defense per MCP spec).
 *   3. HTTP method — POST is JSON-RPC, GET is 405 in 7.1 (SSE upgrade
 *      lands in 7.7), DELETE terminates the session and returns 204.
 *   4. `MCP-Protocol-Version` header — 400 if missing or unrecognised.
 *   5. `Mcp-Session-Id` header — required on every POST except
 *      `initialize`. Missing → 400; unknown / terminated → 404 per the
 *      spec ("Servers MAY terminate sessions at any time, after which
 *      they MUST respond to requests containing that session ID with
 *      HTTP 404 Not Found").
 *
 * `$enableCsrfValidation = false` because this is a JSON-RPC endpoint
 * keyed by header-based auth (in 7.2+), not a CP form keyed by Craft
 * session. Anonymous access is allowed in 7.1 only; 7.2 flips
 * `$allowAnonymous` to `[]` and `beforeAction()` enforces bearer
 * authentication.
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
     */
    protected array|int|bool $allowAnonymous = ['index'];

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * Dispatch one HTTP request to the MCP server. Branches on the HTTP
     * method:
     *
     *   - POST: parses the body, validates session affinity, hands the
     *     JSON-RPC envelope to `Server::dispatch()`, surfaces the
     *     response as `application/json`.
     *   - GET: 405 in sub-gate 7.1. SSE upgrade lands in 7.7.
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
        $settings = Plugin::getInstance()->getSettings();

        // Gate 1 — kill switch. 503 Service Unavailable signals the
        // endpoint exists but the operator hasn't enabled it.
        if (!$settings->httpEnabled) {
            return $this->_status(503, 'HTTP transport is disabled. Set Settings::$httpEnabled = true to enable.');
        }

        // Gate 2 — Origin allowlist (DNS-rebinding defense).
        $originResult = $this->_validateOrigin($settings->allowedOrigins);
        if ($originResult !== null) {
            return $originResult;
        }

        $method = strtoupper($this->request->getMethod());

        // Gate 3 — DELETE is a side-channel for session termination
        // and doesn't carry a JSON-RPC body. Handle it before falling
        // through to the body-bearing flow.
        if ($method === 'DELETE') {
            return $this->_handleDelete();
        }

        // Gate 3b — GET is reserved for SSE upgrade in 7.7. Reject for
        // now per MCP spec ("The server MAY respond to this request
        // with HTTP 405 Method Not Allowed").
        if ($method === 'GET') {
            return $this->_status(405, 'GET is not supported on this endpoint. SSE upgrade lands in a future release.');
        }

        if ($method !== 'POST') {
            return $this->_status(405, "Method {$method} is not supported on this endpoint.");
        }

        // Gate 4 — MCP-Protocol-Version header. Required on every
        // request including initialize.
        $protocolError = $this->_validateProtocolVersion();
        if ($protocolError !== null) {
            return $protocolError;
        }

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
     * Returns a 403 response on rejection, null on accept.
     *
     * @param string[] $allowedOrigins
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validateOrigin(array $allowedOrigins): ?Response
    {
        $origin = $this->request->getHeaders()->get(Http::HEADER_ORIGIN);

        if ($allowedOrigins === []) {
            Craft::warning(
                'cortex HTTP transport: Origin allowlist is empty and httpEnabled is true. '
                . 'Configure Settings::$allowedOrigins for any non-dev environment.',
                'cortex',
            );
            return null;
        }

        if (!is_string($origin) || $origin === '') {
            return $this->_status(403, 'Origin header is required.');
        }

        if (!in_array($origin, $allowedOrigins, true)) {
            return $this->_status(403, 'Origin not permitted.');
        }

        return null;
    }

    /**
     * Validate the `MCP-Protocol-Version` header. Returns a 400
     * response on missing / unrecognised version, null on accept.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validateProtocolVersion(): ?Response
    {
        $version = $this->request->getHeaders()->get(Http::HEADER_PROTOCOL_VERSION);
        if (!is_string($version) || $version === '') {
            return $this->_status(400, 'Missing MCP-Protocol-Version header.');
        }
        if ($version !== Server::PROTOCOL_VERSION) {
            return $this->_status(400, sprintf(
                'Unsupported MCP-Protocol-Version: "%s". This server supports "%s".',
                $version,
                Server::PROTOCOL_VERSION,
            ));
        }
        return null;
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

        if ($isInitialize) {
            // Initialize starts a fresh session. Any previously-issued
            // header on this request is informational only — we ignore
            // it and mint a new id.
            $session = $sessions->create(
                protocolVersion: Server::PROTOCOL_VERSION,
                clientName: $this->_extractClientName($request['params'] ?? []),
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
            $sessions->touch($sessionId);
        }

        $server = new Server(Server::TRANSPORT_HTTP);
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
     * or null when absent / malformed. Centralised so the controller
     * and the stdio dispatcher converge on the same extraction shape.
     *
     * @param array<string,mixed>|mixed $params
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _extractClientName(mixed $params): ?string
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
