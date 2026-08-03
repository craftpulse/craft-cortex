<?php

namespace craftpulse\herald\resources;

/**
 * =========================================================================
 * Contract every MCP resource implements.
 *
 * Resources are URI-addressed, pull-based content. Per the MCP
 * `2025-06-18` spec, `resources/list` returns each resource's
 * `{uri, name, description?, mimeType?}` and `resources/read` returns
 * the resource's content as one or more `{uri, mimeType, text|blob}`
 * blocks.
 *
 * The bundled registry surfaces skill-backed resources only — both
 * the SKILL.md router (`craft-skills://<skill>`) and each per-skill
 * reference (`craft-skills://<skill>/<reference>`).
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
interface ResourceInterface
{
    /**
     * The resource's URI. Unique across the registry. Used as the
     * lookup key for `resources/read` and as the `uri` field in the
     * `resources/list` payload.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getUri(): string;

    /**
     * Display label shown in `resources/list`. Free-form, intended for
     * humans browsing a resource picker.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getName(): string;

    /**
     * Human-readable description shown in `resources/list`. Should
     * answer "what would I find inside?" in one or two sentences.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getDescription(): string;

    /**
     * MIME type of the resource content. Skills are always Markdown
     * so this is `text/markdown` for every bundled resource.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getMimeType(): string;

    /**
     * Read the resource. Returns the `resources/read` content block
     * for this resource: `{uri, mimeType, text}`. Callers wrap the
     * single block in `{contents: [...]}` per the MCP spec.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function read(): array;
}
