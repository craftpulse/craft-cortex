<?php

namespace craftpulse\herald\mcp\transport;

use craftpulse\herald\mcp\Server;
use JsonException;

/**
 * =========================================================================
 * HTTP transport adapter for the MCP `Server` dispatcher.
 *
 * Parallel to the stdio loop in `console/controllers/ServeController`,
 * but for the Streamable HTTP transport. The MCP 2025-06-18 spec
 * requires single JSON-RPC message per POST (an SSE upgrade for
 * streaming is deferred to sub-gate 7.7).
 *
 * Responsibilities:
 *
 *   - Parse the raw POST body into a JSON-RPC request array; surface
 *     parse / shape errors as the spec-shaped envelopes
 *     (`parseError()` / `invalidRequest()`).
 *   - Shape one JSON-RPC response into the body string emitted on the
 *     wire (`encodeResponse()`).
 *
 * The transport stays stateless across requests — the controller
 * (`controllers/McpController`) owns the lifecycle: header validation,
 * session lookup via `services/Sessions`, dispatcher construction.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class Http
{
    // Constants
    // =========================================================================

    /**
     * Header names. The MCP spec is explicit about casing on the wire,
     * though HTTP itself is case-insensitive on header names — pinning
     * the canonical form here keeps logs and `curl -H '…'` examples
     * stable.
     *
     * @since 5.0.0
     */
    public const HEADER_PROTOCOL_VERSION = 'MCP-Protocol-Version';

    public const HEADER_SESSION_ID = 'Mcp-Session-Id';

    public const HEADER_ORIGIN = 'Origin';

    public const CONTENT_TYPE_JSON = 'application/json';

    // Public Methods
    // =========================================================================

    /**
     * Decode a raw POST body into a JSON-RPC request array. Returns
     * the decoded array on success, or null on any of:
     *
     *   - empty body
     *   - non-JSON body (parse failure)
     *   - JSON that decodes to something other than an object (string,
     *     number, array — the MCP spec requires a single JSON-RPC
     *     message per POST, not a batch)
     *
     * The caller is responsible for turning null into the appropriate
     * JSON-RPC error envelope; this method stays pure so it can be
     * exercised in isolation by tests.
     *
     * @return array<string,mixed>|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function parseRequest(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        // Single JSON-RPC message per POST per MCP 2025-06-18. A JSON
        // array body would be a batch — rejecting batches here keeps
        // the contract tight; reintroduce batch handling alongside the
        // SSE upgrade in sub-gate 7.7 if the client ecosystem warrants
        // it.
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Encode a JSON-RPC response array to the wire body. Pretty-
     * printing is deliberately off — the response body is meant to be
     * consumed by clients, not eyeballed in a terminal, and pretty-
     * printing adds bytes without value.
     *
     * @param array<string,mixed> $response
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function encodeResponse(array $response): string
    {
        $body = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            // Defensive — `json_encode` can only fail on encoding-level
            // hazards (recursion, malformed UTF-8) which a JSON-RPC
            // response shaped by the dispatcher will never hit. Surface
            // a minimal valid envelope so the client at least gets the
            // shape it expects.
            return '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error: failed to encode response"}}';
        }
        return $body;
    }

    /**
     * Build a JSON-RPC parse-error envelope (-32700). For bodies that
     * fail `parseRequest()`.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function parseError(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => Server::ERR_PARSE,
                'message' => 'Parse error: request body is not a valid JSON object',
            ],
        ];
    }
}
