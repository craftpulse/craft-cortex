<?php

namespace craftpulse\herald\tools;

use craft\elements\User;

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
 * @author CraftPulse
 * @since  5.0.0
 */
interface ToolInterface
{
    /**
     * The tool's MCP name. Lowercase snake_case. Used as the lookup key
     * in the registry and as the `name` field in `tools/list` /
     * `tools/call`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string;

    /**
     * Human-readable description shown to the LLM in `tools/list`. Should
     * answer "when would I call this?" in one to three sentences. Avoid
     * implementation detail; describe the user-visible effect.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string;

    /**
     * JSON Schema for the tool's `arguments` payload. Returned verbatim
     * in `tools/list` so the MCP client can validate before sending.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
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
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function outputSchema(): array;

    /**
     * Whether this tool should register at all on this install. Runs
     * once at boot, before per-request user filtering. License /
     * edition / settings gating belongs here — e.g. `craft_exec`
     * returning `false` when `Settings::$execEnabled` is `false`, or a
     * Pro-only tool returning `false` when the edition is Free.
     * Returns `false` to remove the tool from every user's `tools/list`
     * for the lifetime of the process.
     *
     * Per-request per-user permission gating is `filterFor()`'s job,
     * not this method's. Mirrors Craft's own static class-level
     * decision contracts (`ComponentInterface::isSelectable()`,
     * `ElementInterface::hasUris()`, `FieldInterface::isMultiInstance()`).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function shouldRegister(): bool;

    /**
     * Per-request, per-user visibility check. Returns whether the tool
     * should appear in `tools/list` for the given user, and whether
     * `tools/call` against it should resolve. Default in `AbstractTool`
     * is `true` — every tool surfaces for every caller.
     *
     * The HTTP transport consults this on every `tools/list` and
     * `tools/call`, passing the user resolved from the bearer/OAuth
     * lookup. stdio passes `null` (single-process, no per-request
     * identity) and the default-true path applies.
     *
     * Pro tools override to gate on Craft permissions (`saveEntries:*`,
     * `editUsers`, etc.) so a non-permitted user does not see the tool
     * in the registry. `execute()` performs its own permission check
     * regardless — `tools/list` filtering is for the LLM's tool-
     * selection UX; `execute()` filtering is the security boundary;
     * both fail closed.
     *
     * Locked architectural contract: per-user tool visibility.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool;

    /**
     * Per-request, per-user input-schema rewrite. Returns the JSON
     * Schema the LLM should see for this tool given the user. Default
     * in `AbstractTool` delegates to `static::getInputSchema()` so
     * existing tools that override the static method continue to work
     * unchanged.
     *
     * Tools with mode-gated permissions (`drafts_and_revisions`,
     * `content_audit`, `import_export`, `system_diagnostics`)
     * override to filter the `mode` enum based on the user's
     * permissions — a read-only user sees only the read modes; a
     * write-permitted user sees the full set. stdio passes `null`
     * and the default delegates to the static schema.
     *
     * Locked architectural contract: per-user tool visibility.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array;

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
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array|\Generator;
}
