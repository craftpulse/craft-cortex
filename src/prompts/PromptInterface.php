<?php

namespace craftpulse\herald\prompts;

/**
 * =========================================================================
 * Contract every MCP prompt implements.
 *
 * Prompts are stateless, registered once per server boot, and looked up
 * by name. Per the MCP `2025-06-18` spec, `prompts/list` returns each
 * prompt's `{name, description, arguments?}`, and `prompts/get` returns
 * `{description?, messages: [{role, content}]}` for a named prompt.
 *
 * The bundled registry ships one skill-backed prompt per whitelisted
 * bundled skill (see `Prompts::PROMPT_MAP`) — none of them take arguments. The interface still
 * surfaces `getArguments()` so future prompts (e.g. parametrised
 * playbooks) can declare them without an ABI change.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
interface PromptInterface
{
    /**
     * The prompt's MCP name. Lowercase snake_case. Used as the lookup
     * key in the registry and as the `name` field in `prompts/list` /
     * `prompts/get`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getName(): string;

    /**
     * Human-readable description shown to the LLM in `prompts/list`.
     * One to three sentences answering "when would I invoke this?".
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getDescription(): string;

    /**
     * MCP `PromptArgument` declarations for the `prompts/list` payload.
     * Empty array for prompts that take no arguments — the dispatcher
     * omits the `arguments` key from the list entry in that case.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getArguments(): array;

    /**
     * Render the prompt for `prompts/get`. Returns the full result
     * envelope expected by the MCP spec — `{description, messages}` —
     * so the dispatcher can hand it back unmodified.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function render(array $arguments): array;
}
