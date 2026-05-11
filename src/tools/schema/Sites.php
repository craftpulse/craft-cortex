<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\models\Site;
use craft\models\SiteGroup;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `sites` tool — list / get / count Craft sites + their groups.
 *
 * Multi-site is one of the most-asked-about features when an AI
 * helps with Craft. This tool returns each site with its language,
 * primary flag, base URL, and group info; the response also includes
 * the full site groups list so the AI can correlate.
 *
 * Modes:
 *   - default: list all sites + groups.
 *   - `handle`: single site (no groups payload).
 *   - `count: true`: count of sites.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Sites extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'sites';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List all Craft sites with their groups, get a single site by handle, or count ' .
            'sites. Returns each site with language, primary flag, base URL, hasUrls, and the ' .
            'group it belongs to.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'handle' => Schema::string(),
            'count' => Schema::boolean(),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $service = Craft::$app->getSites();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $site = $service->getSiteByHandle($handle);
            if ($site === null) {
                throw new ToolException("No site found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['site' => $this->_serializeSite($site)];
        }

        $sites = $service->getAllSites();

        if ($count) {
            return ['count' => count($sites)];
        }

        return [
            'sites' => array_map(fn(Site $s): array => $this->_serializeSite($s), $sites),
            'siteGroups' => array_map(
                static fn(SiteGroup $g): array => [
                    'id' => $g->id,
                    'uid' => $g->uid,
                    'name' => $g->name,
                    'siteHandles' => array_map(
                        static fn(Site $s): string => (string) $s->handle,
                        $g->getSites(),
                    ),
                ],
                $service->getAllGroups(),
            ),
            'primarySiteHandle' => $service->getPrimarySite()->handle,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeSite(Site $site): array
    {
        $group = $site->getGroup();

        return [
            'id' => $site->id,
            'uid' => $site->uid,
            'name' => $site->getName(),
            'handle' => $site->handle,
            'language' => $site->language,
            'primary' => $site->primary,
            'enabled' => $site->enabled,
            'hasUrls' => $site->hasUrls,
            'baseUrl' => $site->getBaseUrl(),
            'sortOrder' => $site->sortOrder,
            'groupId' => $group->id ?? null,
            'groupName' => $group->name ?? null,
            'groupUid' => $group->uid ?? null,
        ];
    }
}
