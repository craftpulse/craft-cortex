<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\elements\GlobalSet;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

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
 * Read-only. Writes (and the Pro-only `update_global_set`) land in
 * the Pro tier.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Globals extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'globals';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'handle' => Schema::string()->description('Global set handle. Omit to read all.'),
            'site' => Schema::any()->description('Site handle or id. Defaults to primary.'),
            'with' => Schema::array(Schema::string()),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $serializer = new ElementSerializer();
        $eagerHandles = $this->_eagerHandles($arguments);
        $siteId = $this->_resolveSiteIdForGlobals($arguments);

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
                function(GlobalSet $g) use ($siteId, $eagerHandles, $serializer): array {
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _resolveSiteIdForGlobals(array $arguments): int
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
}
