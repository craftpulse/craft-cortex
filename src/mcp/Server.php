<?php

namespace craftpulse\cortex\mcp;

use Craft;
use craft\elements\User;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\support\AttributeReader;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\InvocationLogger;
use craftpulse\cortex\tools\ToolException;
use craftpulse\cortex\tools\ToolInterface;
use Generator;
use Throwable;

/**
 * =========================================================================
 * Transport-agnostic MCP / JSON-RPC 2.0 dispatcher.
 *
 * Knows nothing about stdio or HTTP. Takes a parsed JSON-RPC request,
 * routes it to a method handler, returns a parsed JSON-RPC response (or
 * null for notifications). Transports adapt I/O (line-delimited JSON
 * for stdio, request/response bodies for HTTP) and call `dispatch()`.
 *
 * Spec target: 2025-06-18.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Server
{
    // Constants
    // =========================================================================

    public const PROTOCOL_VERSION = '2025-06-18';

    public const SERVER_NAME = 'cortex';

    public const SERVER_VERSION = '5.0.0';

    public const TRANSPORT_STDIO = 'stdio';

    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORT_UNKNOWN = 'unknown';

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
     * @var int|null Row id from `cortex_tokens` when authentication
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

    // Public Methods
    // =========================================================================

    /**
     * @param string $transport `Server::TRANSPORT_STDIO` (default) or
     *                          `Server::TRANSPORT_HTTP`. Set per
     *                          transport adapter — only the stdio
     *                          adapter ships today; the HTTP adapter
     *                          will land in a future release.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(string $transport = self::TRANSPORT_STDIO)
    {
        $this->_transport = $transport;
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
     * Bind the issuing bearer-token row id for this dispatcher's
     * lifetime. Parallel to `setUserId()`. Called by
     * `McpController::beforeAction()` after the bearer lookup resolves
     * to a `cortex_tokens` row. OAuth-authenticated requests leave
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
            // Notifications get acknowledged silently.
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
            'protocolVersion' => self::PROTOCOL_VERSION,
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
     * Build the `tools/list` payload. On the HTTP transport with a
     * resolved user, route through `Tools::asListPayloadFor($user)` so
     * each tool's `filterFor()` / `inputSchemaFor()` hooks fire — Pro
     * tools without the relevant permission get omitted, mode-gated
     * tools get a schema-rewritten enum. On stdio (or HTTP with no
     * resolved user — pre-auth or anonymous-allowed paths) the user
     * resolves to `null` and the call goes to `asListPayloadFor(null)`,
     * which by locked invariant equals the legacy `asListPayload()`.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _toolsList(): array
    {
        return [
            'tools' => Plugin::getInstance()->tools->asListPayloadFor($this->_resolveUser()),
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
            'prompts' => Plugin::getInstance()->prompts->asListPayload(),
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
            'resources' => Plugin::getInstance()->resources->asListPayload(),
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

        $prompt = Plugin::getInstance()->prompts->getByName($name);
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

        $resource = Plugin::getInstance()->resources->getByUri($uri);
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
        $match = Plugin::getInstance()->resources->matchTemplate($uri);
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
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: tools/call requires `name` (string)');
        }

        $tool = Plugin::getInstance()->tools->getByNameFor($name, $this->_resolveUser());
        if ($tool === null) {
            // Indistinguishable from "tool not registered" on the wire —
            // a tool the user lacks permission for fails closed as
            // "Unknown tool", same JSON-RPC error code, same message
            // shape. Security boundary: `tools/list` filtering hides
            // the existence of the tool; `tools/call` filtering refuses
            // the call.
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, "Unknown tool: {$name}");
        }

        if (AttributeReader::isStdioOnly($tool) && $this->_transport !== self::TRANSPORT_STDIO) {
            // Hard reject. stdio-only enforcement happens at the
            // transport boundary regardless of caller permissions or
            // token scope, never config-driven.
            return $this->_errorResponse(
                $id,
                self::ERR_METHOD_NOT_FOUND,
                "Tool '{$name}' is stdio-only and cannot be invoked over the HTTP transport.",
            );
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->_errorResponse($id, self::ERR_INVALID_PARAMS, 'Invalid params: tools/call `arguments` must be an object');
        }

        $context = $this->_invocationContext($id);

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
        // excerpt is for forensics only.
        $responsePayload = (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        InvocationLogger::logCall($name, $arguments, null, $this->_elapsedMs($startNs), $context, $responsePayload);

        return $this->_successResponse($id, $this->_toolResultEnvelope($tool, $result));
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
     * logger under the `cortex` category; the wire response stays
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
        Craft::error($e->getMessage() . "\n" . $e->getTraceAsString(), 'cortex');
        return $this->_errorResponse($id, self::ERR_INTERNAL, $message);
    }
}
