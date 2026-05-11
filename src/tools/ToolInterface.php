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
 *
 * The `execute()` return type is `array|\Generator` to leave room for
 * progress streaming — long-running Pro tools (resave, audit fix modes,
 * bulk_entries, import_export import) yield progress notifications
 * interleaved with the final result. The stdio dispatcher eagerly
 * consumes any Generator (final yield = result) without surfacing
 * intermediate notifications; the HTTP transport will forward yields
 * as `notifications/progress` messages.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
interface ToolInterface
{
    /**
     * The tool's MCP name. Lowercase snake_case. Used as the lookup key
     * in the registry and as the `name` field in `tools/list` /
     * `tools/call`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string;

    /**
     * Human-readable description shown to the LLM in `tools/list`. Should
     * answer "when would I call this?" in one to three sentences. Avoid
     * implementation detail; describe the user-visible effect.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string;

    /**
     * JSON Schema for the tool's `arguments` payload. Returned verbatim
     * in `tools/list` so the MCP client can validate before sending.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array;

    /**
     * JSON Schema describing the tool's structured response. Empty array
     * means "no output schema declared" — the wire payload is still the
     * usual `content[]` envelope. Pro tools and any future tools that
     * benefit from response-shape advertising override this. Defaults to
     * `[]` in `AbstractTool`; override per concrete tool when useful.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array;

    /**
     * Whether this tool should appear in the registry for the current
     * request. `AbstractTool` returns `true` by default; concrete tools
     * override to gate visibility — for example, a Pro tool checking
     * `saveEntries:{section}` so a user without the permission doesn't
     * see the corresponding mode surfaces in `tools/list`. Returning
     * `false` removes the tool entirely from the registry for that
     * build.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function shouldRegister(): bool;

    /**
     * Execute the tool against the given arguments. Returns a structured
     * result that the dispatcher wraps in MCP's `content[]` envelope.
     *
     * Long-running tools may return a `\Generator` that yields progress
     * notifications and finishes with the result via `Generator::return`
     * (or as the final yield, whichever pattern the tool prefers). The
     * stdio dispatcher eagerly consumes the generator — the final value
     * becomes the wire response. The HTTP transport will forward
     * intermediate yields as `notifications/progress` messages per MCP
     * spec 2025-06-18.
     *
     * @param array<string,mixed> $arguments Validated against `getInputSchema()` upstream.
     * @return array<int|string,mixed>|\Generator<int,mixed,mixed,array<int|string,mixed>>
     * @throws ToolException For tool-level errors (returned to client as `isError: true`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array|\Generator;
}
