<?php

namespace craftpulse\cortex\tools;

/**
 * =========================================================================
 * Contract every MCP tool implements.
 *
 * Tools are stateless, registered once per server boot, and looked up by
 * name. They expose their JSON Schema so the MCP client (and the LLM) can
 * validate arguments before invocation. They MUST throw `ToolException`
 * for structured tool-level errors and let other exceptions propagate
 * (the dispatcher converts them to JSON-RPC internal errors).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
interface ToolInterface
{
    /**
     * The tool's MCP name. Lowercase snake_case. Used as the lookup key
     * in the registry and as the `name` field in `tools/list` /
     * `tools/call`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string;

    /**
     * Human-readable description shown to the LLM in `tools/list`. Should
     * answer "when would I call this?" in one to three sentences. Avoid
     * implementation detail; describe the user-visible effect.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string;

    /**
     * JSON Schema for the tool's `arguments` payload. Returned verbatim
     * in `tools/list` so the MCP client can validate before sending.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array;

    /**
     * Execute the tool against the given arguments. Returns a structured
     * result that the dispatcher wraps in MCP's `content[]` envelope.
     *
     * @param array<string,mixed> $arguments Validated against `getInputSchema()` upstream.
     * @return array<int|string,mixed>
     * @throws ToolException For tool-level errors (returned to client as `isError: true`).
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array;
}
