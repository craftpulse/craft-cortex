<?php

namespace craftpulse\cortex\mcp;

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\support\AttributeReader;
use craftpulse\cortex\tools\ToolException;
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
 * @since  0.1.0
 */
class Server
{
    // Constants
    // =========================================================================

    public const PROTOCOL_VERSION = '2025-06-18';

    public const SERVER_NAME = 'cortex';

    public const SERVER_VERSION = '0.1.0';

    public const TRANSPORT_STDIO = 'stdio';

    public const TRANSPORT_HTTP = 'http';

    // Private Properties
    // =========================================================================

    /**
     * @var string Transport this server instance is bound to. Used to
     *             reject stdio-only tools (e.g. `craft_exec`) on HTTP.
     */
    private string $_transport;

    // Public Methods
    // =========================================================================

    /**
     * @param string $transport `Server::TRANSPORT_STDIO` (default) or
     *                          `Server::TRANSPORT_HTTP`. Set per
     *                          transport adapter. Phase 2 adds the HTTP
     *                          adapter; Phase 1 only constructs stdio.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function __construct(string $transport = self::TRANSPORT_STDIO)
    {
        $this->_transport = $transport;
    }

    /**
     * Dispatch one JSON-RPC request and return the response (or null for
     * notifications, which MUST NOT receive a reply per spec).
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function dispatch(array $request): ?array
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->_errorResponse(
                $request['id'] ?? null,
                -32600,
                'Invalid Request: jsonrpc must be "2.0"',
            );
        }

        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $isNotification = !array_key_exists('id', $request);

        if (!is_string($method) || $method === '') {
            return $isNotification
                ? null
                : $this->_errorResponse($id, -32600, 'Invalid Request: missing method');
        }

        if ($isNotification) {
            // Notifications get acknowledged silently.
            return null;
        }

        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->_errorResponse($id, -32602, 'Invalid params: must be an array or object');
        }

        return match ($method) {
            'initialize' => $this->_successResponse($id, $this->_initializeResult()),
            'tools/list' => $this->_successResponse($id, $this->_toolsList()),
            'tools/call' => $this->_handleToolsCall($id, $params),
            'prompts/list' => $this->_successResponse($id, $this->_promptsList()),
            'prompts/get' => $this->_handlePromptsGet($id, $params),
            'resources/list' => $this->_successResponse($id, $this->_resourcesList()),
            'resources/read' => $this->_handleResourcesRead($id, $params),
            'ping' => $this->_successResponse($id, new \stdClass()),
            default => $this->_errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _initializeResult(): array
    {
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
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _toolsList(): array
    {
        return [
            'tools' => Plugin::getInstance()->tools->asListPayload(),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
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
     * @since  0.1.0
     */
    private function _handlePromptsGet(int|string|null $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->_errorResponse($id, -32602, 'Invalid params: prompts/get requires `name` (string)');
        }

        $prompt = Plugin::getInstance()->prompts->getByName($name);
        if ($prompt === null) {
            return $this->_errorResponse($id, -32602, "Unknown prompt: {$name}");
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->_errorResponse($id, -32602, 'Invalid params: prompts/get `arguments` must be an object');
        }

        try {
            $result = $prompt->render($arguments);
        } catch (Throwable $e) {
            return $this->_errorResponse(
                $id,
                -32603,
                sprintf('Internal error rendering prompt "%s": %s', $name, $e->getMessage()),
            );
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
     * @since  0.1.0
     */
    private function _handleResourcesRead(int|string|null $id, array $params): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return $this->_errorResponse($id, -32602, 'Invalid params: resources/read requires `uri` (string)');
        }

        $resource = Plugin::getInstance()->resources->getByUri($uri);
        if ($resource === null) {
            return $this->_errorResponse($id, -32602, "Unknown resource: {$uri}");
        }

        try {
            $block = $resource->read();
        } catch (Throwable $e) {
            return $this->_errorResponse(
                $id,
                -32603,
                sprintf('Internal error reading resource "%s": %s', $uri, $e->getMessage()),
            );
        }

        return $this->_successResponse($id, ['contents' => [$block]]);
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
     * @since  0.1.0
     */
    private function _handleToolsCall(int|string|null $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->_errorResponse($id, -32602, 'Invalid params: tools/call requires `name` (string)');
        }

        $tool = Plugin::getInstance()->tools->getByName($name);
        if ($tool === null) {
            return $this->_errorResponse($id, -32602, "Unknown tool: {$name}");
        }

        if (AttributeReader::isStdioOnly($tool) && $this->_transport !== self::TRANSPORT_STDIO) {
            // Hard reject. PLANNING.md 4.9: stdio-only is enforced at the
            // transport boundary regardless of caller permissions or token
            // scope, never config-driven.
            return $this->_errorResponse(
                $id,
                -32601,
                "Tool '{$name}' is stdio-only and cannot be invoked over the HTTP transport.",
            );
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->_errorResponse($id, -32602, 'Invalid params: tools/call `arguments` must be an object');
        }

        try {
            $result = $tool->execute($arguments);
        } catch (ToolException $e) {
            return $this->_successResponse($id, $this->_toolErrorEnvelope($e->getMessage()));
        } catch (Throwable $e) {
            // Unexpected exception — surface as JSON-RPC internal error.
            return $this->_errorResponse(
                $id,
                -32603,
                sprintf('Internal error executing tool "%s": %s', $name, $e->getMessage()),
            );
        }

        return $this->_successResponse($id, $this->_toolResultEnvelope($result));
    }

    /**
     * MCP-compliant tool result envelope. The structured result is
     * serialised as JSON and returned in a single text-content block
     * — that's the convention every shipping MCP server uses.
     *
     * @param array<int|string,mixed> $result
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _toolResultEnvelope(array $result): array
    {
        return [
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
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
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
     * @since  0.1.0
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
}
