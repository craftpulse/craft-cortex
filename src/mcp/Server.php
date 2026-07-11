<?php

namespace craftpulse\herald\mcp;

use Craft;
use craft\elements\User;
use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\ContextAwareToolInterface;
use craftpulse\herald\tools\StreamableToolInterface;
use craftpulse\herald\tools\support\AttributeReader;
use craftpulse\herald\tools\support\CancellationToken;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;
use craftpulse\herald\tools\support\SecretRedactor;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\ToolInterface;
use Generator;
use Throwable;
use yii\base\Event;

/**
 * =========================================================================
 * Transport-agnostic MCP / JSON-RPC 2.0 dispatcher.
 *
 * Knows nothing about stdio or HTTP. Takes a parsed JSON-RPC request,
 * routes it to a method handler, returns a parsed JSON-RPC response (or
 * null for notifications). Transports adapt I/O (line-delimited JSON
 * for stdio, request/response bodies for HTTP) and call `dispatch()`.
 *
 * Spec target: MCP 2025-11-25 (latest), negotiating 2025-06-18 for
 * older clients — see `SUPPORTED_PROTOCOL_VERSIONS`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Server
{
    // Constants
    // =========================================================================

    /**
     * Latest MCP protocol revision this server speaks. Advertised in
     * the `initialize` response when the client requests this version
     * or a version we don't recognise (per the spec's "respond with
     * the latest version supported by the server" rule).
     *
     * @since 5.0.0
     */
    public const PROTOCOL_VERSION = '2025-11-25';

    /**
     * Every MCP protocol revision this server can negotiate, newest
     * first. The dispatcher echoes the client's requested version when
     * it appears here (spec: "if the server supports the requested
     * protocol version, it MUST respond with the same version") and
     * falls back to `PROTOCOL_VERSION` otherwise. The HTTP transport's
     * `MCP-Protocol-Version` header is validated against this same set.
     *
     * 2025-11-25 and 2025-06-18 are wire-compatible for herald's
     * surface — the 2025-11-25 deltas that touch a server are version
     * negotiation (handled here), the HTTP-403-on-bad-Origin rule
     * (already enforced in `McpController::_passesOrigin`), and
     * SEP-1303 "input-validation errors are tool-execution errors, not
     * protocol errors" (already herald's behaviour: tool-level failures
     * return `isError: true` envelopes, never JSON-RPC error codes).
     * The remaining 2025-11-25 additions (icons, OIDC discovery, CIMD,
     * tasks, elicitation) are optional capabilities herald does not
     * advertise, so honouring them is not required to speak the
     * revision.
     *
     * @var string[]
     *
     * @since 5.0.0
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18'];

    public const SERVER_NAME = 'herald';

    public const SERVER_VERSION = '5.0.0';

    public const TRANSPORT_STDIO = 'stdio';

    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORT_UNKNOWN = 'unknown';

    /**
     * Cache key prefix for in-flight cancellation signals. Each entry
     * is keyed by `herald:cancel:{sessionId}:{requestId}` and stores
     * `true` for one hour after a `notifications/cancelled` arrives.
     * The streaming dispatcher's `CancellationToken` reads through
     * this slot between yields so the running tool can short-circuit.
     *
     * Sessions without an `Mcp-Session-Id` (which would be unusual on
     * the streaming path — initialize never streams) write under the
     * `herald:cancel:-:{requestId}` key; the dash placeholder matches
     * the `_orDash()` rendering used throughout the audit log.
     *
     * TTL of one hour is the proposal — long enough that a delayed
     * cancellation arriving after a network blip still lands on a
     * running call, short enough that abandoned slots don't linger.
     * Cache eviction handles the rest.
     *
     * @since 5.0.0
     */
    public const CANCEL_CACHE_KEY_PREFIX = 'herald:cancel:';

    /**
     * TTL in seconds for cancellation cache slots. Bounds how long a
     * cancellation signal stays "armed" against the streaming
     * dispatcher's polling token. Set high enough that a delayed
     * `notifications/cancelled` POSTing seconds before the streaming
     * call finishes still flips the flag.
     *
     * @since 5.0.0
     */
    public const CANCEL_CACHE_TTL = 3600;

    // JSON-RPC 2.0 standard error codes — locked by the spec, not by us.
    // Wire values are stable across MCP revisions, so promoting to
    // named constants is a pure readability win.

    public const ERR_PARSE = -32700;

    public const ERR_INVALID_REQUEST = -32600;

    public const ERR_METHOD_NOT_FOUND = -32601;

    public const ERR_INVALID_PARAMS = -32602;

    public const ERR_INTERNAL = -32603;

    // Private Properties
    // =========================================================================

    /**
     * @var string Transport this server instance is bound to. Used to
     *             reject stdio-only tools (e.g. `craft_exec`) on HTTP.
     */
    private string $_transport;

    /**
     * @var string|null Client name from the most recent `initialize`
     *                  handshake's `clientInfo.name`. Stamped onto every
     *                  invocation context so audit log lines carry which
     *                  MCP client made the call. Null until initialize
     *                  fires.
     */
    private ?string $_clientName = null;

    /**
     * @var int|null Craft user id resolved by the HTTP transport's
     *               `beforeAction()` from the `Authorization: Bearer`
     *               header. Stamped onto every invocation context so
     *               audit log lines carry `user=<id>`. Request-scoped
     *               — each `new Server()` instance owns its own slot
     *               because the controller mints one per request.
     *               Always null on stdio (single-process, no
     *               per-request Craft identity).
     */
    private ?int $_userId = null;

    /**
     * @var int|null Row id from `herald_tokens` when authentication
     *               was via a long-lived bearer token. Set by the HTTP
     *               controller's `beforeAction()` once the bearer
     *               lookup resolves. Null for stdio (no bearer auth)
     *               and for OAuth-authenticated HTTP requests (OAuth
     *               correlation lives implicitly on the audit row's
     *               `userId + clientName + dateCreated` triple
     *               because we have two source tables for OAuth-
     *               issued tokens; the `tokenId` FK is bearer-only).
     */
    private ?int $_tokenId = null;

    /**
     * @var string|null Value of the `Mcp-Session-Id` request header,
     *                  set by the HTTP controller for non-initialize
     *                  requests. Threaded onto every invocation
     *                  context so audit log lines can group every
     *                  call in the same HTTP session. Null on stdio
     *                  (no sessions) and on the `initialize` call
     *                  itself (the session id is minted in the
     *                  response, not the request).
     */
    private ?string $_sessionId = null;

    /**
     * @var int|null Post-consume rate-limit bucket headroom for the
     *               authenticated user, threaded onto every invocation
     *               context so audit log lines carry the throttle
     *               pressure each call landed at. Null on stdio (no
     *               rate-limiting surface) and on HTTP requests that
     *               pre-date the controller's `RateLimiter::consume()`
     *               call (in practice never — the controller always
     *               consumes before `actionIndex()` dispatches, but
     *               the slot stays nullable for forward compatibility
     *               with future paths that bypass the limiter).
     */
    private ?int $_rateLimitRemaining = null;

    /**
     * @var bool Whether this request carries a live WS2 elevation
     *           marker. Set by `McpController::beforeAction()` from the
     *           per-token elevation cache. stdio leaves it at the
     *           constructor default (true for stdio — the trusted local
     *           transport is implicitly elevated; the elevation gate is
     *           an HTTP-transport concern). Threaded onto every
     *           `InvocationContext` so high-stakes tools can gate.
     */
    private bool $_elevated;

    /**
     * @var string[]|null Capability scopes carried by the authenticating
     *                    OAuth access token, or null for stdio / bearer-
     *                    token requests (which are not scope-gated). Set
     *                    by the HTTP controller after the bearer lookup
     *                    resolves an OAuth token's `scope` claim. Threaded
     *                    to `Tools::asListPayloadFor()` / `getByNameFor()`
     *                    so a tool surfaces over HTTP only when its
     *                    required scope is granted.
     */
    private ?array $_grantedScopes = null;

    /**
     * @var User|null Memoized result of resolving `$_userId` through
     *                `Users::getUserById()`. Lazy — populated on the
     *                first call to `_resolveUser()` for the request,
     *                then re-used across `tools/list` and every
     *                `tools/call` so the per-user filter never re-
     *                queries the DB more than once. Cleared via
     *                `$_userResolved` to distinguish "not yet
     *                resolved" from "resolved to null".
     */
    private ?User $_user = null;

    /**
     * @var bool Whether `_resolveUser()` has been called yet for this
     *           dispatcher instance. Separate slot so the memoization
     *           treats `null` (stdio / unauthenticated) as a valid
     *           cached result rather than re-resolving on every call.
     */
    private bool $_userResolved = false;

    /**
     * @var CancellationToken|null Token for the currently-streaming
     *                             tool call, surfaced through
     *                             `getInFlightCancellationToken()` so
     *                             the HTTP controller can flip it
     *                             directly on a real TCP-disconnect
     *                             (sub-gate 7.7.5). Set when
     *                             `_streamToolCall()` enters its yield
     *                             loop, cleared in a `finally` after
     *                             the loop terminates so the slot
     *                             never leaks across requests.
     *
     *                             Null on every non-streaming path —
     *                             `dispatch()` (JSON mode) and the
     *                             single-frame degradation branch
     *                             inside `dispatchStreaming()` for
     *                             non-streamable tools both leave it
     *                             untouched.
     */
    private ?CancellationToken $_inFlightCancellationToken = null;

    // Public Methods
    // =========================================================================

    /**
     * @param string $transport `Server::TRANSPORT_STDIO` (default) or
     *                          `Server::TRANSPORT_HTTP`. Set per
     *                          transport adapter — the stdio adapter is
     *                          `console/controllers/ServeController`,
     *                          the HTTP adapter is
     *                          `controllers/McpController`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(string $transport = self::TRANSPORT_STDIO)
    {
        $this->_transport = $transport;
        // stdio is the trusted local transport — implicitly elevated,
        // since the WS2 elevation gate exists only to compensate for the
        // absence of a re-auth handshake over HTTP. HTTP requests start
        // un-elevated until the controller flips this from the per-token
        // elevation cache.
        $this->_elevated = $transport === self::TRANSPORT_STDIO;
    }

    /**
     * Bind the authenticated Craft user id for this dispatcher's
     * lifetime. Called by `McpController::beforeAction()` once the
     * bearer-token lookup resolves. Subsequent `_invocationContext()`
     * builds carry the id forward into the audit log.
     *
     * Request-scoped — the HTTP controller mints a fresh `Server`
     * per request, so no static state ever leaks between requests.
     * stdio leaves this null and the dispatcher never calls this
     * setter.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setUserId(?int $userId): void
    {
        $this->_userId = $userId;
    }

    /**
     * Bind the capability scope set the authenticating OAuth access
     * token carries. Called by `McpController::beforeAction()` after
     * the bearer lookup resolves an OAuth token. Null (the default)
     * marks the request as not scope-gated — stdio and long-lived
     * bearer tokens take this path and see every tool their user
     * permission + edition allow.
     *
     * @param string[]|null $scopes
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setGrantedScopes(?array $scopes): void
    {
        $this->_grantedScopes = $scopes;
    }

    /**
     * Bind whether this request carries a live WS2 elevation marker.
     * Called by `McpController::beforeAction()` after checking the
     * per-token elevation cache. Threaded onto every invocation context
     * so high-stakes tools can gate. stdio never calls this — the
     * constructor leaves stdio implicitly elevated.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setElevated(bool $elevated): void
    {
        $this->_elevated = $elevated;
    }

    /**
     * Bind the issuing bearer-token row id for this dispatcher's
     * lifetime. Parallel to `setUserId()`. Called by
     * `McpController::beforeAction()` after the bearer lookup resolves
     * to a `herald_tokens` row. OAuth-authenticated requests leave
     * this null — OAuth correlation flows through the (userId,
     * clientName, dateCreated) tuple on the audit row instead.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setTokenId(?int $tokenId): void
    {
        $this->_tokenId = $tokenId;
    }

    /**
     * Bind a `clientInfo.name` captured outside the `initialize`
     * handshake. The HTTP controller calls this on non-initialize
     * dispatches by pulling the client name off the resolved `Session`
     * row — the dispatcher is fresh per request, but the session row
     * remembers the name across the lifetime of the MCP session, so
     * subsequent `tools/list` / `tools/call` audit lines carry the
     * same client identifier the initial handshake established.
     *
     * Initialize itself overwrites this slot via `_initializeResult()`;
     * the controller's pre-dispatch hint is the fall-through for
     * non-initialize calls.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setClientName(?string $clientName): void
    {
        $this->_clientName = $clientName;
    }

    /**
     * Bind the `Mcp-Session-Id` for this dispatcher's lifetime.
     * Parallel to `setUserId()` and `setTokenId()`. Called by
     * `McpController::beforeAction()` once the session id is
     * extracted from the request headers. Null for stdio and for
     * `initialize` (the session id is minted in the response).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setSessionId(?string $sessionId): void
    {
        $this->_sessionId = $sessionId;
    }

    /**
     * Bind the post-consume rate-limit headroom for this dispatcher's
     * lifetime. Parallel to `setUserId()` / `setTokenId()` /
     * `setSessionId()`. Called by `McpController::beforeAction()`
     * after `RateLimiter::consume()` returns a non-throwing
     * `RateLimitStatus`. Threaded onto every invocation context so
     * the Gate 7.6 audit log carries throttle pressure per row.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setRateLimitRemaining(?int $value): void
    {
        $this->_rateLimitRemaining = $value;
    }

    /**
     * Most-recently captured client name from the `initialize`
     * handshake's `clientInfo.name`, or null when no handshake has
     * yet occurred (or the handshake omitted `clientInfo`). Exposed
     * publicly so the HTTP controller can stamp the same name onto
     * the Session row without re-parsing the request body — single
     * source of truth across `_initializeResult()` and the session
     * write.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getClientName(): ?string
    {
        return $this->_clientName;
    }

    /**
     * The `CancellationToken` for the currently-streaming `tools/call`,
     * or null when this dispatcher is between streaming invocations.
     * Exposed publicly so `McpController::_streamPost()` can flip the
     * token directly on a real HTTP TCP-disconnect (sub-gate 7.7.5) —
     * the controller polls `connection_aborted()` between frames and
     * calls `cancel('client disconnected')` on a flipped socket.
     *
     * Returns null on every non-streaming path. The JSON-mode
     * `dispatch()` doesn't touch the slot; the single-frame
     * degradation branch inside `dispatchStreaming()` for non-
     * streamable tools doesn't either. Only `_streamToolCall()`
     * populates the slot, and only for the lifetime of its yield
     * loop.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getInFlightCancellationToken(): ?CancellationToken
    {
        return $this->_inFlightCancellationToken;
    }

    /**
     * Dispatch one JSON-RPC request and return the response (or null for
     * notifications, which MUST NOT receive a reply per spec).
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function dispatch(array $request): ?array
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->_errorResponse(
                $request['id'] ?? null,
                self::ERR_INVALID_REQUEST,
                'Invalid Request: jsonrpc must be "2.0"',
            );
        }

        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request);

        if (!is_string($method) || $method === '') {
            return $isNotification
                ? null
                : $this->_errorResponse($id, self::ERR_INVALID_REQUEST, 'Invalid Request: missing method');
        }

        if ($isNotification) {
            // MCP notifications/cancelled — write a cache slot so any
            // streaming generator on the same session+requestId observes
            // the flip on its next `isCancelled()` check. Everything
            // else is silently acknowledged per JSON-RPC 2.0 §6.
            if ($method === 'notifications/cancelled') {
                $this->_recordCancellation($request['params'] ?? []);
            }
            return null;
        }

        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: must be an array or object');
        }

        return match ($method) {
            'initialize' => $this->_successResponse($id, $this->_initializeResult($params)),
            'tools/list' => $this->_successResponse($id, $this->_toolsList()),
            'tools/call' => $this->_handleToolsCall($id, $params),
            'prompts/list' => $this->_successResponse($id, $this->_promptsList()),
            'prompts/get' => $this->_handlePromptsGet($id, $params),
            'resources/list' => $this->_successResponse($id, $this->_resourcesList()),
            'resources/read' => $this->_handleResourcesRead($id, $params),
            'ping' => $this->_successResponse($id, new \stdClass()),
            default => $this->_errorResponse($id, self::ERR_METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    /**
     * Streaming counterpart to `dispatch()`. Yields one JSON-RPC
     * envelope per SSE frame the transport should emit:
     *
     *   - For tools implementing `StreamableToolInterface` AND on the
     *     HTTP transport: one `notifications/progress` envelope per
     *     `stream()` yield, then one terminal `tools/call` response
     *     envelope carrying the generator's return value.
     *   - For non-streamable tools, or any non-`tools/call` method:
     *     one envelope, identical in shape to what `dispatch()` would
     *     have returned in JSON mode. The transport wraps it in a
     *     single SSE frame — the MCP spec permits one-frame SSE for
     *     any POST that the server chooses to upgrade.
     *
     * Cancellation: between every yield, the streaming dispatcher
     * checks the `CancellationToken` on the running invocation
     * context. The token's `isCancelled()` callback reads through the
     * `herald:cancel:{session}:{requestId}` cache slot the
     * `notifications/cancelled` arrival populated; on flip, the
     * dispatcher yields one final `notifications/cancelled` envelope
     * and returns, leaving the generator drained but no terminal
     * response written. The audit row carries `kind=cancelled`.
     *
     * Audit log: exactly one row per stream completion, written after
     * the generator finishes (success, cancellation, or error) — never
     * per progress frame. `durationMs` is wall-clock from stream start
     * to stream end.
     *
     * @param array<string,mixed> $request
     * @return Generator<int,array<string,mixed>,mixed,void>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function dispatchStreaming(array $request): Generator
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            yield $this->_errorResponse(
                $request['id'] ?? null,
                self::ERR_INVALID_REQUEST,
                'Invalid Request: jsonrpc must be "2.0"',
            );
            return;
        }

        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request);

        if (!is_string($method) || $method === '') {
            if (!$isNotification) {
                yield $this->_errorResponse($id, self::ERR_INVALID_REQUEST, 'Invalid Request: missing method');
            }
            return;
        }

        if ($isNotification) {
            // Streaming side observes the same cancellation surface as
            // the JSON-mode dispatch path — write the cache slot, no
            // wire response.
            if ($method === 'notifications/cancelled') {
                $this->_recordCancellation($request['params'] ?? []);
            }
            return;
        }

        // Methods other than `tools/call` collapse to single-frame SSE
        // — same wire shape `dispatch()` would have returned in JSON
        // mode, just framed as one SSE event. The MCP spec lets us
        // upgrade any POST.
        if ($method !== 'tools/call') {
            $response = $this->dispatch($request);
            if ($response !== null) {
                yield $response;
            }
            return;
        }

        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            yield $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: must be an array or object');
            return;
        }

        $resolution = $this->_validateToolCall($id, $params);
        if ($resolution['error'] !== null) {
            yield $resolution['error'];
            return;
        }
        $tool = $resolution['tool'];
        $name = $resolution['name'];
        $arguments = $resolution['arguments'];
        assert($tool !== null);

        // Non-streamable tools degrade to single-frame SSE — call the
        // existing JSON-mode dispatch and yield its envelope as the
        // lone frame. The wire is what the client asked for; one
        // frame is correct per spec.
        if (!$tool instanceof StreamableToolInterface) {
            yield $this->_handleToolsCall($id, $params);
            return;
        }

        yield from $this->_streamToolCall($id, $tool, $name, $arguments, $params);
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the `initialize` response and side-effect: capture the
     * `clientInfo.name` so subsequent invocation contexts carry it
     * through the audit log. The MCP spec lets clients reconnect /
     * re-handshake; we just overwrite from the latest handshake.
     *
     * @param array<string,mixed> $params Initialize request params per
     *                                    MCP 2025-06-18 — typically
     *                                    `{protocolVersion, capabilities,
     *                                    clientInfo: {name, version}}`.
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _initializeResult(array $params): array
    {
        // Reset on every handshake. MCP 2025-06-18 §5.1 requires
        // `clientInfo` on initialize; if it's absent, that's a malformed
        // request from the client. We accept it gracefully but clear the
        // captured name so a stale value from a prior handshake doesn't
        // leak into subsequent audit-log lines.
        $this->_clientName = null;

        $clientInfo = $params['clientInfo'] ?? null;
        if (is_array($clientInfo)) {
            $name = $clientInfo['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $this->_clientName = $name;
            }
        }

        return [
            'protocolVersion' => $this->_negotiateProtocolVersion($params['protocolVersion'] ?? null),
            'capabilities' => [
                'tools' => new \stdClass(),
                'resources' => new \stdClass(),
                'prompts' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    /**
     * Resolve the protocol version to advertise in the `initialize`
     * response. Per MCP lifecycle version negotiation: echo the
     * client's requested version when this server supports it, else
     * respond with the latest version this server supports.
     *
     * A missing or non-string request value falls back to the latest —
     * a malformed handshake still gets a usable version rather than an
     * error, matching the lenient posture the rest of the dispatcher
     * takes toward client input.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _negotiateProtocolVersion(mixed $requested): string
    {
        if (is_string($requested) && in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return $requested;
        }
        return self::PROTOCOL_VERSION;
    }

    /**
     * Build the `tools/list` payload. On the HTTP transport with a
     * resolved user, route through `Tools::asListPayloadFor($user)` so
     * each tool's `filterFor()` / `inputSchemaFor()` hooks fire — Pro
     * tools without the relevant permission get omitted, mode-gated
     * tools get a schema-rewritten enum. On stdio (or HTTP with no
     * resolved user — pre-auth or anonymous-allowed paths) the user
     * resolves to `null` and the call goes to `asListPayloadFor(null)`,
     * which by locked invariant equals the legacy `asListPayload()`.
     *
     * Off-stdio, stdio-only tools (`craft_exec`) are dropped from the
     * list as well (Gate 9.7): advertising a tool every call to which
     * is hard-rejected just burns the LLM's tool-selection budget. The
     * `tools/call` reject in `_resolveToolCall()` remains the security
     * boundary — this filter is UX, defense stays in depth.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _toolsList(): array
    {
        $tools = Herald::getInstance()->tools->asListPayloadFor($this->_resolveUser(), $this->_grantedScopes);

        if ($this->_transport !== self::TRANSPORT_STDIO) {
            $registry = Herald::getInstance()->tools;
            $tools = array_values(array_filter(
                $tools,
                static function(array $entry) use ($registry): bool {
                    $tool = $registry->getByName((string) ($entry['name'] ?? ''));
                    return $tool === null || !AttributeReader::isStdioOnly($tool);
                },
            ));
        }

        return [
            'tools' => $tools,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _promptsList(): array
    {
        return [
            'prompts' => Herald::getInstance()->prompts->asListPayload(),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resourcesList(): array
    {
        return [
            'resources' => Herald::getInstance()->resources->asListPayload(),
        ];
    }

    /**
     * Handle a `prompts/get` request. Returns the prompt's render
     * envelope on success; JSON-RPC -32602 if the name is missing,
     * malformed, or unknown; -32603 if rendering raises an
     * unexpected exception (e.g. the backing skill became unreadable
     * after registry init).
     *
     * @param int|string|null     $id
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handlePromptsGet(int|string|null $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: prompts/get requires `name` (string)');
        }

        $prompt = Herald::getInstance()->prompts->getByName($name);
        if ($prompt === null) {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, "Unknown prompt: {$name}");
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: prompts/get `arguments` must be an object');
        }

        try {
            $result = $prompt->render($arguments);
        } catch (Throwable $e) {
            return $this->_internalError($id, $e, sprintf('Internal error rendering prompt "%s".', $name));
        }

        return $this->_successResponse($id, $result);
    }

    /**
     * Handle a `resources/read` request. Wraps the resource's content
     * block in `{contents: [...]}` per spec; -32602 for missing or
     * unknown URI; -32603 if the backing file became unreadable
     * after registry init.
     *
     * @param int|string|null     $id
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handleResourcesRead(int|string|null $id, array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: resources/read requires `uri` (string)');
        }

        $resource = Herald::getInstance()->resources->getByUri($uri);
        if ($resource !== null) {
            try {
                $block = $resource->read();
            } catch (Throwable $e) {
                return $this->_internalError($id, $e, sprintf('Internal error reading resource "%s".', $uri));
            }

            return $this->_successResponse($id, ['contents' => [$block]]);
        }

        // Fall back to URI templates — Pro custom skills and any
        // future per-element resource use these. Concrete URIs are
        // tried first so a templated resource never shadows a bundled
        // entry.
        $match = Herald::getInstance()->resources->matchTemplate($uri);
        if ($match !== null) {
            [$template, $captures] = $match;
            try {
                $block = $template->read($uri, $captures);
            } catch (Throwable $e) {
                return $this->_internalError($id, $e, sprintf('Internal error reading resource "%s".', $uri));
            }
            return $this->_successResponse($id, ['contents' => [$block]]);
        }

        return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, "Unknown resource: {$uri}");
    }

    /**
     * Handle a `tools/call` request. Tool-level errors come back as a
     * successful JSON-RPC response with `isError: true` (per MCP spec);
     * protocol-level errors (unknown tool, missing name, etc.) come back
     * as JSON-RPC error responses.
     *
     * @param int|string|null $id
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _handleToolsCall(int|string|null $id, array $params): array
    {
        $resolution = $this->_validateToolCall($id, $params);
        if ($resolution['error'] !== null) {
            return $resolution['error'];
        }
        $tool = $resolution['tool'];
        $name = $resolution['name'];
        $arguments = $resolution['arguments'];
        // `_validateToolCall()` guarantees `tool` is non-null on the
        // happy path; PHPStan can't follow the through-array branch
        // so the assertion narrows the type.
        assert($tool !== null);

        $context = $this->_invocationContext($id);

        // Hand the dispatch context to non-streaming tools that need it
        // inside `execute()` (transport-dependent gating, etc.). The
        // context carries the real `$_transport` — never inferred from
        // a proxy. Streaming tools receive their context via `stream()`
        // instead, so this opt-in covers only the `execute()` path.
        if ($tool instanceof ContextAwareToolInterface) {
            $tool->setInvocationContext($context);
        }

        $startNs = hrtime(true);
        try {
            $result = $tool->execute($arguments);
            if ($result instanceof Generator) {
                $result = $this->_consumeGenerator($result);
            }
        } catch (ToolException $e) {
            InvocationLogger::logCall($name, $arguments, $e, $this->_elapsedMs($startNs), $context);
            return $this->_successResponse($id, $this->_toolErrorEnvelope($e->getMessage()));
        } catch (Throwable $e) {
            InvocationLogger::logCall($name, $arguments, $e, $this->_elapsedMs($startNs), $context);
            return $this->_internalError($id, $e, sprintf('Internal error executing tool "%s".', $name));
        }

        // Serialize the tool result for the audit log's response excerpt.
        // The wire payload to the MCP client is built separately by
        // `_toolResultEnvelope()` and is unaffected by this — the
        // excerpt is for forensics only. Belt-and-suspenders redaction
        // of secret-keyed result fields before they land in the
        // persisted excerpt, so any future HTTP-reachable tool that
        // doesn't redact its own result is still covered.
        $responsePayload = (string) json_encode(SecretRedactor::redactArray($result), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        InvocationLogger::logCall($name, $arguments, null, $this->_elapsedMs($startNs), $context, $responsePayload);

        return $this->_successResponse($id, $this->_toolResultEnvelope($tool, $result));
    }

    /**
     * Shared pre-dispatch validation for `tools/call`. Returns either
     * an `['error' => <envelope>]` slot the caller should yield/return,
     * or an `['tool' => ToolInterface, 'name' => string, 'arguments' =>
     * array]` slot the caller can drive into either the JSON-mode or
     * the streaming path.
     *
     * Centralises the four checks both dispatch paths need: name
     * presence, registry lookup with per-user filtering, stdio-only
     * enforcement, and argument-shape validation. Pulled out of
     * `_handleToolsCall()` so the streaming dispatcher shares the
     * exact same gate ordering.
     *
     * Returns a uniform shape with `error` always present (null when
     * validation passed) so PHPStan can narrow without union gymnastics
     * at the call sites.
     *
     * @param array<string,mixed> $params
     * @return array{error: array<string,mixed>|null, tool: ToolInterface|null, name: string, arguments: array<string,mixed>}
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validateToolCall(int|string|null $id, array $params): array
    {
        $miss = [
            'error' => null,
            'tool' => null,
            'name' => '',
            'arguments' => [],
        ];

        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $miss['error'] = $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: tools/call requires `name` (string)');
            return $miss;
        }

        $tool = Herald::getInstance()->tools->getByNameFor($name, $this->_resolveUser(), $this->_grantedScopes);
        if ($tool === null) {
            // Indistinguishable from "tool not registered" on the wire —
            // a tool the user lacks permission for fails closed as
            // "Unknown tool", same JSON-RPC error code, same message
            // shape. Security boundary: `tools/list` filtering hides
            // the existence of the tool; `tools/call` filtering refuses
            // the call.
            $miss['error'] = $this->_errorResponse($id, self::ERR_INVALID_PARAMS, "Unknown tool: {$name}");
            return $miss;
        }

        if (AttributeReader::isStdioOnly($tool) && $this->_transport !== self::TRANSPORT_STDIO) {
            // Hard reject. stdio-only enforcement happens at the
            // transport boundary regardless of caller permissions or
            // token scope, never config-driven.
            $miss['error'] = $this->_errorResponse(
                $id,
                self::ERR_METHOD_NOT_FOUND,
                "Tool '{$name}' is stdio-only and cannot be invoked over the HTTP transport.",
            );
            return $miss;
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            $miss['error'] = $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: tools/call `arguments` must be an object');
            return $miss;
        }

        return [
            'error' => null,
            'tool' => $tool,
            'name' => $name,
            'arguments' => $arguments,
        ];
    }

    /**
     * Drive a `StreamableToolInterface` through its `stream()` entry
     * point. Yields one `notifications/progress` JSON-RPC envelope per
     * progress frame the tool produces, plus one terminal envelope
     * carrying the generator's return value (or a `notifications/
     * cancelled` envelope when the cooperative cancellation flag
     * fires).
     *
     * The streaming pipeline owns its own audit-log line — one
     * `InvocationLogger::logCall()` per stream completion, never per
     * frame — so the durationMs reflects wall-clock from stream start
     * to stream end. Cancellation surfaces as `kind=cancelled` on the
     * audit row; ToolException as `kind=tool_error`; any other
     * Throwable as `kind=internal_error`. The locked invariant from
     * Gate 7.5 (KV ⊆ DB columns) stays satisfied.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $callParams Original `tools/call` params (carries `_meta.progressToken`).
     * @return Generator<int,array<string,mixed>,mixed,void>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _streamToolCall(
        int|string|null $id,
        StreamableToolInterface $tool,
        string $name,
        array $arguments,
        array $callParams,
    ): Generator {
        $progressToken = $this->_progressToken($callParams);
        $context = $this->_invocationContextWithCancellation($id);
        $cancellationToken = $context->getCancellationToken();

        // Surface the in-flight token through `getInFlightCancellationToken()`
        // so the HTTP controller can flip it on a real TCP-disconnect
        // (sub-gate 7.7.5). Cleared in `finally` below — the slot must
        // never outlive the streaming call.
        $this->_inFlightCancellationToken = $cancellationToken;

        $startNs = hrtime(true);
        $gen = null;
        $finalResult = null;
        $cancelledMidStream = false;

        try {
            $gen = $tool->stream($arguments, $context);

            while ($gen->valid()) {
                if ($cancellationToken->isCancelled()) {
                    $cancelledMidStream = true;
                    break;
                }

                $frame = $gen->current();
                if (is_array($frame)) {
                    yield $this->_progressEnvelope($progressToken, $frame);
                }
                $gen->next();
            }

            // Generator may exit between yields by checking the
            // cancellation flag itself and returning early. In that
            // case the loop's mid-yield check never fires (no more
            // yields to gate), but the token IS flipped — surface the
            // cancellation regardless. The terminal envelope must
            // reflect the wire-level outcome, not the generator's
            // private exit shape.
            if (!$cancelledMidStream && $cancellationToken->isCancelled()) {
                $cancelledMidStream = true;
            }

            if (!$cancelledMidStream) {
                $finalResult = $gen->getReturn();
            }
        } catch (ToolException $e) {
            InvocationLogger::logCall($name, $arguments, $e, $this->_elapsedMs($startNs), $context);
            yield $this->_successResponse($id, $this->_toolErrorEnvelope($e->getMessage()));
            return;
        } catch (Throwable $e) {
            InvocationLogger::logCall($name, $arguments, $e, $this->_elapsedMs($startNs), $context);
            yield $this->_internalError($id, $e, sprintf('Internal error executing tool "%s".', $name));
            return;
        } finally {
            $this->_inFlightCancellationToken = null;
        }

        if ($cancelledMidStream) {
            // Audit-log with `kind=cancelled`. The KIND_CANCELLED
            // sentinel rides on a synthetic ToolException carrier so
            // the existing `logCall(..., $error, ...)` flow surfaces
            // it as a tool-side outcome rather than an internal error.
            // The KV line's `kind=cancelled` comes from the explicit
            // `$kindOverride` parameter on `formatEntry()` /
            // `buildEntry()`; we drive both directly here so the
            // logger doesn't have to special-case cancellation.
            $this->_logCancelled($name, $arguments, $context, $this->_elapsedMs($startNs));
            yield $this->_cancelledEnvelope($id, $progressToken);
            return;
        }

        // Final frame — the terminal `tools/call` response. The audit
        // row writes the response excerpt the same way the JSON-mode
        // dispatcher does so DB rows for streamed and non-streamed
        // calls are indistinguishable in their forensic shape.
        if (!is_array($finalResult)) {
            // Fallback when the streamable tool's generator omits an
            // explicit `return` — defensive only; tools SHOULD always
            // return their terminal payload.
            $finalResult = ['mode' => 'streamed', 'note' => 'Streamable tool generator finished without an explicit return.'];
        }
        // Redact secret-keyed fields in the persisted excerpt only — the
        // wire envelope below is built separately from the unredacted
        // `$finalResult`, preserving the locked "execute() drains stream()
        // for identical terminal envelope" invariant.
        $responsePayload = (string) json_encode(SecretRedactor::redactArray($finalResult), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        InvocationLogger::logCall($name, $arguments, null, $this->_elapsedMs($startNs), $context, $responsePayload);

        yield $this->_successResponse($id, $this->_toolResultEnvelope($tool, $finalResult));
    }

    /**
     * Build an `InvocationContext` whose `CancellationToken` polls the
     * cache slot the HTTP transport's `notifications/cancelled` arrival
     * writes to. Only relevant on the streaming HTTP path — stdio
     * never streams over the wire, so the JSON-mode
     * `_invocationContext()` keeps the default-unfired token.
     *
     * The token's `isCancelled()` is overridden via a callback-backed
     * subclass so the polling is lazy — we don't hit the cache on
     * construction, only when the streaming loop checks the flag
     * between yields.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _invocationContextWithCancellation(string|int|null $requestId): InvocationContext
    {
        $sessionId = $this->_sessionId ?? '-';
        $requestIdStr = $requestId !== null ? (string) $requestId : '-';
        $cacheKey = self::CANCEL_CACHE_KEY_PREFIX . $sessionId . ':' . $requestIdStr;

        $token = new CancellationToken(static function() use ($cacheKey): bool {
            $cache = Craft::$app->getCache();
            return $cache !== null && $cache->get($cacheKey) === true;
        });

        return new InvocationContext(
            transport: $this->_transport,
            requestId: $requestId,
            userId: $this->_userId,
            clientName: $this->_clientName,
            tokenId: $this->_tokenId,
            sessionId: $this->_sessionId,
            rateLimitRemaining: $this->_rateLimitRemaining,
            elevated: $this->_elevated,
            cancellationToken: $token,
        );
    }

    /**
     * Persist a cancellation signal so the streaming dispatcher's
     * `CancellationToken` observes the flip on its next
     * `isCancelled()` check. Notifications without an `Mcp-Session-Id`
     * (which would be unusual on the streaming path — initialize
     * never streams) write under the dash-placeholder session slot.
     *
     * Per MCP cancellation spec, malformed / unknown requestId values
     * are silently ignored — the notification is fire-and-forget.
     *
     * @param array<string,mixed>|mixed $params
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _recordCancellation(mixed $params): void
    {
        if (!is_array($params)) {
            return;
        }
        $requestId = $params['requestId'] ?? null;
        if (!is_string($requestId) && !is_int($requestId)) {
            return;
        }

        $sessionId = $this->_sessionId ?? '-';
        if ($sessionId === '-') {
            // Per MCP spec: ignore-able. We still write the cache slot
            // so an in-process streaming test that drives both the
            // streaming dispatcher and the cancellation notification
            // against the same Server instance observes the flip.
            Craft::info(
                sprintf('herald: notifications/cancelled arrived without an Mcp-Session-Id (requestId=%s)', (string) $requestId),
                'herald',
            );
        }

        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return;
        }
        $cache->set(
            self::CANCEL_CACHE_KEY_PREFIX . $sessionId . ':' . (string) $requestId,
            true,
            self::CANCEL_CACHE_TTL,
        );
    }

    /**
     * Build a `notifications/progress` JSON-RPC envelope from a tool's
     * yielded frame. Per MCP 2025-06-18 §progress:
     *
     *   - `progressToken` MUST be the value the client supplied in the
     *     original request's `_meta.progressToken`. When the client
     *     omitted it the server still emits progress frames (the
     *     client may ignore them), so the envelope falls back to the
     *     request id — that's a sensible default that preserves a
     *     1:1 mapping between request and progress stream.
     *   - `progress` is required, monotonically non-decreasing. The
     *     tool is responsible for the increment; we pass through what
     *     it yielded.
     *   - `total` and `message` are optional and pass through verbatim
     *     when present in the frame.
     *
     * @param array<string,mixed> $frame
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _progressEnvelope(string|int|null $progressToken, array $frame): array
    {
        $params = [];
        if ($progressToken !== null) {
            $params['progressToken'] = $progressToken;
        }
        if (array_key_exists('progress', $frame)) {
            $params['progress'] = $frame['progress'];
        }
        if (array_key_exists('total', $frame)) {
            $params['total'] = $frame['total'];
        }
        if (array_key_exists('message', $frame)) {
            $params['message'] = $frame['message'];
        }

        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => $params,
        ];
    }

    /**
     * Build the terminal `notifications/cancelled` envelope for a
     * mid-stream cancellation. References the cancelled request id so
     * the client can correlate it back to the original `tools/call`.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _cancelledEnvelope(int|string|null $id, string|int|null $progressToken): array
    {
        $params = ['requestId' => $id];
        if ($progressToken !== null) {
            $params['progressToken'] = $progressToken;
        }
        return [
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => $params,
        ];
    }

    /**
     * Pull `_meta.progressToken` out of a `tools/call` params blob.
     * Per MCP §progress the token MUST be a string or integer; any
     * other shape (or absence) returns null and the dispatcher emits
     * progress envelopes without a `progressToken` field. Spec-aware
     * clients ignore those; non-spec clients see no frames.
     *
     * @param array<string,mixed> $callParams
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _progressToken(array $callParams): string|int|null
    {
        $meta = $callParams['_meta'] ?? null;
        if (!is_array($meta)) {
            return null;
        }
        $token = $meta['progressToken'] ?? null;
        if (is_string($token) || is_int($token)) {
            return $token;
        }
        return null;
    }

    /**
     * Emit a `kind=cancelled` audit row for a mid-stream cancellation.
     * Uses `buildEntry()` + `formatEntry()` directly to override the
     * kind without manufacturing a synthetic exception — keeps the
     * `error_class` / `error_message` columns null for the cancelled
     * row, which is the semantic shape we want (cancellation isn't
     * an error).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _logCancelled(string $name, array $arguments, InvocationContext $context, int $durationMs): void
    {
        $line = InvocationLogger::formatEntry(
            toolName: $name,
            arguments: $arguments,
            error: null,
            durationMs: $durationMs,
            context: $context,
            kindOverride: InvocationLogger::KIND_CANCELLED,
        );
        Craft::info($line, InvocationLogger::CATEGORY);

        $entry = InvocationLogger::buildEntry(
            toolName: $name,
            arguments: $arguments,
            error: null,
            durationMs: $durationMs,
            context: $context,
            kindOverride: InvocationLogger::KIND_CANCELLED,
        );
        $event = new LogCallEvent();
        $event->entry = $entry;
        $event->line = $line;
        Event::trigger(InvocationLogger::class, InvocationLogger::EVENT_LOG_CALL, $event);
    }

    /**
     * Build an `InvocationContext` for the current dispatch. The
     * stdio transport populates transport, request id, and client
     * name (when captured from initialize); user is always null.
     * The HTTP transport calls `setUserId()` after bearer-token
     * lookup resolves, so the context here carries the authenticated
     * user id forward into the audit log.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _invocationContext(string|int|null $requestId): InvocationContext
    {
        return new InvocationContext(
            transport: $this->_transport,
            requestId: $requestId,
            userId: $this->_userId,
            clientName: $this->_clientName,
            tokenId: $this->_tokenId,
            sessionId: $this->_sessionId,
            rateLimitRemaining: $this->_rateLimitRemaining,
            elevated: $this->_elevated,
        );
    }

    /**
     * Resolve the bound `$_userId` to a `User` element, or `null` when
     * the dispatcher has no bound user (stdio, or HTTP requests that
     * arrive before / outside the bearer-token lookup). Memoized per
     * dispatcher instance so `tools/list` followed by N `tools/call`
     * requests within the same HTTP session execute one
     * `getUserById()` at most.
     *
     * The `$_userResolved` flag distinguishes "not yet resolved" from
     * "resolved to null" — a missing/disabled user id should not
     * trigger a fresh lookup on every dispatched method.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveUser(): ?User
    {
        if ($this->_userResolved) {
            return $this->_user;
        }
        $this->_userResolved = true;

        if ($this->_userId === null) {
            $this->_user = null;
            return null;
        }

        $this->_user = Craft::$app->getUsers()->getUserById($this->_userId);
        return $this->_user;
    }

    /**
     * Convert an `hrtime(true)` start mark into elapsed milliseconds,
     * capped at non-negative. `hrtime` returns nanoseconds; integer
     * division loses sub-millisecond resolution which is fine for the
     * invocation-log granularity.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _elapsedMs(int $startNs): int
    {
        $elapsed = (hrtime(true) - $startNs);
        if ($elapsed < 0) {
            return 0;
        }
        return (int) ($elapsed / 1_000_000);
    }

    /**
     * Eagerly drain a tool's `Generator` and return the final result.
     * The stdio dispatcher does NOT forward intermediate yields as MCP
     * progress notifications — that's transport-layer work for the
     * HTTP / SSE adapter. The interface change is forward-compatible:
     * tools can already return `Generator`, but only the final value is
     * surfaced to the client.
     *
     * The final value is taken from the generator's return value
     * (`$gen->getReturn()`) when present; otherwise from the last
     * yielded value. Tools should prefer the explicit `return` form so
     * the contract is unambiguous.
     *
     * @param Generator<int,mixed,mixed,array<int|string,mixed>> $gen
     * @return array<int|string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _consumeGenerator(Generator $gen): array
    {
        $last = null;
        foreach ($gen as $value) {
            $last = $value;
        }

        $return = $gen->getReturn();
        if (is_array($return)) {
            return $return;
        }

        if (is_array($last)) {
            return $last;
        }

        return [
            'mode' => 'streamed',
            'note' => 'Tool returned a Generator with no terminal array value. HTTP-transport streaming will surface yields.',
        ];
    }

    /**
     * MCP-compliant tool result envelope. The structured result is
     * serialised as JSON and returned in a single text-content block
     * — that's the convention every shipping MCP server uses.
     *
     * When the tool declares a non-empty `outputSchema()`, the envelope
     * additionally carries the parsed object under `structuredContent`
     * per MCP 2025-06-18 §6.2 — clients with schema-validation support
     * read that, older clients fall back to the text block. Tools with
     * no output schema declared emit the text block only.
     *
     * @param array<int|string,mixed> $result
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _toolResultEnvelope(ToolInterface $tool, array $result): array
    {
        $envelope = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode(
                        $result,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                    ),
                ],
            ],
            'isError' => false,
        ];

        if ($tool::outputSchema() !== []) {
            $envelope['structuredContent'] = $result;
        }

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _toolErrorEnvelope(string $message): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $message],
            ],
            'isError' => true,
        ];
    }

    /**
     * @param int|string|null $id
     * @param array<string,mixed>|object $result
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successResponse(int|string|null $id, array|object $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @param int|string|null $id
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _errorResponse(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * Log an unexpected exception and return a JSON-RPC -32603 envelope
     * with a generic message. The full message + trace go to Craft's
     * logger under the `herald` category; the wire response stays
     * generic so untrusted-client transports don't leak internal paths
     * or SQL fragments.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _internalError(int|string|null $id, Throwable $e, string $message): array
    {
        Craft::error($e->getMessage() . "\n" . $e->getTraceAsString(), 'herald');
        return $this->_errorResponse($id, self::ERR_INTERNAL, $message);
    }
}
