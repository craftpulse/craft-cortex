<?php

namespace craftpulse\herald\tools;

use Exception;

/**
 * =========================================================================
 * Tool-level error.
 *
 * Throw from a tool's `execute()` to signal a structured error that the
 * dispatcher should return to the client as a successful `tools/call`
 * with `isError: true` (per MCP spec). For protocol-level errors —
 * unknown tool, parse failure, missing required argument — the
 * dispatcher emits a JSON-RPC error response instead, never a tool
 * error envelope.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class ToolException extends Exception
{
}
