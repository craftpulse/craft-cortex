<?php

namespace craftpulse\cortex\tools;

use Craft;
use craft\base\Element;
use craft\elements\User;

/**
 * =========================================================================
 * Base class for MCP tools.
 *
 * Provides defaults for the input schema (object with no properties) and
 * helpers shared across schema-tool implementations: handle extraction,
 * count-mode detection, mode-param extraction. Concrete tools override
 * `getName`, `getDescription`, `getInputSchema`, and `execute`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
abstract class AbstractTool implements ToolInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Default schema: object with no required properties. Override per
     * tool to expose `handle`, `count`, `mode`, etc.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * Default: no output schema declared.
     *
     * **Override only when the response shape is stable across every
     * possible input.** Polymorphic tools (list-or-single-or-count,
     * mode-dispatched payloads) are intentionally left without an
     * output schema in v5.0 — hand-written `oneOf` schemas for those
     * drift from runtime and are a maintenance liability greater than
     * the spec-aware-client validation benefit. When a tool with a
     * non-empty `outputSchema()` runs, the MCP dispatcher dual-emits
     * the response under `structuredContent` alongside the legacy
     * text block (`Server::_toolResultEnvelope`); when it's empty the
     * envelope carries the text block only.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     *
     * Default: every tool registers. Tools with license / edition /
     * settings gating override (e.g. `craft_exec` checks
     * `Settings::$execEnabled`; future Pro tools check the edition).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function shouldRegister(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * Default: the tool is visible to every user, including the stdio
     * `null` caller. Pro tools override to consult Craft permissions
     * for the resolved user. See `ToolInterface::filterFor()` for the
     * locked contract.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * Default: delegate to the tool's static `getInputSchema()`.
     * Concrete tools that need per-user schema rewrites (mode-enum
     * filtering based on Craft permissions) override this method.
     * Tools that override the static method alone still get correct
     * behaviour: `inputSchemaFor(null)` returns the static schema
     * verbatim, preserving the stdio path.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        return static::getInputSchema();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Return the optional `handle` argument as a string, or null if not
     * present / not a string. Tools that support list-OR-single mode use
     * this to switch behaviour.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _handle(array $arguments): ?string
    {
        $handle = $arguments['handle'] ?? null;
        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Return whether the caller asked for count-only output.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _isCount(array $arguments): bool
    {
        return ($arguments['count'] ?? false) === true;
    }

    /**
     * Return the optional `mode` argument as a string, or null if not
     * present. Tools with multiple operation modes (e.g. `fields` with
     * `mode: usage`) use this to dispatch.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _mode(array $arguments): ?string
    {
        $mode = $arguments['mode'] ?? null;
        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    /**
     * Clamp the caller-supplied `limit` to the tool's `[1, $max]` band,
     * falling back to `$default` when no value was supplied. Concrete
     * tools pass their own `DEFAULT_LIMIT` / `MAX_LIMIT` constants so
     * each tool keeps tool-specific bounds.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _limit(array $arguments, int $default, int $max): int
    {
        $limit = (int) ($arguments['limit'] ?? $default);
        return max(1, min($max, $limit));
    }

    /**
     * Floor the caller-supplied `offset` to zero. Tools use this with a
     * matching `_limit()` call for paginated envelopes.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _offset(array $arguments): int
    {
        return max(0, (int) ($arguments['offset'] ?? 0));
    }

    /**
     * Return the caller-supplied `with` argument as a list of eager-load
     * handles. Strings are trimmed of empties; non-array `with` is
     * normalised to `[]`. Content tools forward this list to the
     * `ElementSerializer` so the LLM can opt in to related-field
     * expansion per call.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _eagerHandles(array $arguments): array
    {
        $with = $arguments['with'] ?? [];
        if (!is_array($with)) {
            return [];
        }

        return array_values(array_filter(
            $with,
            static fn(mixed $h): bool => is_string($h) && $h !== '',
        ));
    }

    /**
     * Resolve the target site id from `siteId` / `siteHandle`, falling
     * back to the primary site. Pro mutation tools use this for the
     * `create` path where a site is always required.
     *
     * Error messages prefix with `static::getName()` so each tool
     * emits its own naming context.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _resolveSiteId(array $arguments): int
    {
        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            return $siteId;
        }
        return (int) Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Resolve the target site id from `siteId` / `siteHandle`, returning
     * `null` when neither is supplied. Pro mutation tools use this on
     * the `update / delete / restore` paths where Craft picks the
     * default site when none is supplied.
     *
     * Error messages prefix with `static::getName()` so each tool
     * emits its own naming context.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _resolveOptionalSiteId(array $arguments): ?int
    {
        $name = static::getName();

        $siteId = $arguments['siteId'] ?? null;
        if (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) {
            $site = Craft::$app->getSites()->getSiteById((int) $siteId);
            if ($site === null) {
                throw new ToolException("{$name}: site id={$siteId} not found.");
            }
            return (int) $site->id;
        }

        $siteHandle = $arguments['siteHandle'] ?? null;
        if (is_string($siteHandle) && $siteHandle !== '') {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site === null) {
                throw new ToolException("{$name}: site handle=`{$siteHandle}` not found.");
            }
            return (int) $site->id;
        }

        return null;
    }

    /**
     * Forward `fields: {handle: value}` to the element's
     * `setFieldValues()`. The contract is pass-through — Craft
     * normalises per field type at save time. Document the
     * "we pass through; Craft validates" contract in each consuming
     * tool's PHPDoc.
     *
     * No-op when `fields` is absent, not an array, or empty.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _applyFields(Element $element, array $arguments): void
    {
        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return;
        }
        $element->setFieldValues($fields);
    }

    /**
     * Validation envelope shape returned when
     * `saveElement(runValidation: true)` returns false. Tools return
     * this iterable shape rather than throwing — the LLM iterates on
     * `errors` (keyed by field handle, listing every message a field
     * gathered) and retries with corrected values.
     *
     * Not cached against the idempotency key — retries with different
     * inputs should be free to land.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _validationEnvelope(Element $element, string $mode): array
    {
        return [
            'success' => false,
            'mode' => $mode,
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'errors' => $element->getErrors(),
        ];
    }
}
