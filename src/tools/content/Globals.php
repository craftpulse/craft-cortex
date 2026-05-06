<?php

namespace craftpulse\cortex\tools\content;

use Craft;
use craft\elements\GlobalSet;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `globals` tool — read global set field values.
 *
 * Modes:
 *   - default (no `handle`): every global set with its field values.
 *   - `handle`: a single global set.
 *
 * Site filter via `site` (handle / id) — global sets are localised
 * across the requested site.
 *
 * Read-only. Writes (and Pro-only `update_global_set` per PLANNING.md
 * 4.7) land in the Pro tier.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Globals extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'globals';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Read Craft global set field values. Pass `handle` for a single set, or omit ' .
            'for every set. Pass `site` (handle/id) to localise the read; defaults to the ' .
            'primary site. Eager-load relational fields with `with: [...]` to avoid N+1.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'handle' => ['type' => 'string', 'description' => 'Global set handle. Omit to read all.'],
                'site' => ['description' => 'Site handle or id. Defaults to primary.'],
                'with' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $serializer = new ElementSerializer();
        $eagerHandles = $this->_eagerHandles($arguments);
        $siteId = $this->_resolveSiteId($arguments);

        $handle = $this->_handle($arguments);
        if (is_string($handle)) {
            $set = $this->_loadSet($handle, $siteId, $eagerHandles);
            if ($set === null) {
                throw new ToolException("No global set found with handle '{$handle}'.");
            }

            return ['globalSet' => $this->_serializeSet($set, $eagerHandles, $serializer)];
        }

        // getAllSets() returns the canonical set list; for site-localised
        // values we re-query each set with siteId() (handled in _loadSet).
        $sets = Craft::$app->getGlobals()->getAllSets();

        return [
            'globalSets' => array_map(
                function (GlobalSet $g) use ($siteId, $eagerHandles, $serializer): array {
                    // Re-fetch each set with eager loading applied so field
                    // values respect the caller's `with: [...]` request.
                    if ($eagerHandles === [] || !is_string($g->handle)) {
                        return $this->_serializeSet($g, $eagerHandles, $serializer);
                    }
                    $reloaded = $this->_loadSet($g->handle, $siteId, $eagerHandles) ?? $g;
                    return $this->_serializeSet($reloaded, $eagerHandles, $serializer);
                },
                $sets,
            ),
            'count' => count($sets),
            'siteId' => $siteId,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string[] $eagerHandles
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _loadSet(string $handle, int $siteId, array $eagerHandles): ?GlobalSet
    {
        $query = GlobalSet::find()
            ->handle($handle)
            ->siteId($siteId);

        if ($eagerHandles !== []) {
            $query->with($eagerHandles);
        }

        $set = $query->one();
        return $set instanceof GlobalSet ? $set : null;
    }

    /**
     * @param string[] $eagerHandles
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeSet(GlobalSet $set, array $eagerHandles, ElementSerializer $serializer): array
    {
        $base = $serializer->serializeElement($set, $eagerHandles);

        return [
            'id' => $set->id,
            'uid' => $set->uid,
            'name' => $set->name,
            'handle' => $set->handle,
            'siteId' => $set->siteId,
            'fields' => $base['fields'] ?? [],
        ];
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _resolveSiteId(array $arguments): int
    {
        $sitesService = Craft::$app->getSites();
        $primaryId = (int) $sitesService->getPrimarySite()->id;
        $site = $arguments['site'] ?? null;

        if ($site === null) {
            return $primaryId;
        }

        if (is_int($site) || (is_string($site) && ctype_digit((string) $site))) {
            $resolved = $sitesService->getSiteById((int) $site);
        } else {
            $resolved = $sitesService->getSiteByHandle((string) $site);
        }

        return $resolved !== null ? (int) $resolved->id : $primaryId;
    }

    /**
     * @return string[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _eagerHandles(array $arguments): array
    {
        $with = $arguments['with'] ?? [];
        if (!is_array($with)) {
            return [];
        }

        return array_values(array_filter(
            $with,
            static fn (mixed $h): bool => is_string($h) && $h !== '',
        ));
    }
}
