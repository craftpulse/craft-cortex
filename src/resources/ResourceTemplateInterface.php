<?php

namespace craftpulse\cortex\resources;

/**
 * =========================================================================
 * Contract for resources that match dynamic URI templates.
 *
 * Where `ResourceInterface` exposes ONE concrete URI (the registry
 * builds an instance per address — see `SkillResource`),
 * `ResourceTemplateInterface` exposes a URI TEMPLATE that matches a
 * family of URIs at read time. Examples:
 *
 *   - `craft-element://entries/{id}` — read any entry by id
 *   - `custom-skills://{handle}` — read a Pro custom-skills element
 *   - `craft-asset://{volume}/{path}` — read an asset by volume + path
 *
 * The Free tier ships the interface and the dispatcher fallback; no
 * Free-tier resource implements it. The Pro custom-skills element is
 * the first concrete consumer.
 *
 * Templates use the RFC 6570 Level-1 simple-substitution form: literal
 * path segments plus `{name}` placeholders (no operators, no nested
 * expressions). The dispatcher's matcher resolves the template against
 * the requested URI and passes the captured parameters to `read()`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
interface ResourceTemplateInterface
{
    /**
     * The URI template the resource handles. Per RFC 6570 Level 1:
     * literal characters plus `{name}` placeholders that capture any
     * non-slash, non-empty segment. Example:
     * `craft-element://entries/{id}`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getUriTemplate(): string;

    /**
     * Display label shown in `resources/list`. Surfaced once — clients
     * see the template, not the matched form.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getName(): string;

    /**
     * Human-readable description shown in `resources/list`.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getDescription(): string;

    /**
     * MIME type of the resource content. Returned for the templated
     * URI (i.e. assumed uniform across all matched URIs). Resources
     * with variable MIME types should override at the protocol layer.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getMimeType(): string;

    /**
     * Whether the given concrete URI matches this template. Returns
     * `null` if it doesn't match; otherwise returns the captured
     * parameter map keyed by placeholder name. Empty array means a
     * match with no captures.
     *
     * @return array<string,string>|null
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function matches(string $uri): ?array;

    /**
     * Read the templated resource for the given concrete URI. Returns
     * the `resources/read` content block: `{uri, mimeType, text}`.
     * Implementations call `matches()` to obtain captured parameters
     * (or have already validated upstream — the dispatcher always
     * matches before invoking `read()`).
     *
     * @param array<string,string> $captures Captured parameters from `matches()`.
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function read(string $uri, array $captures): array;
}
