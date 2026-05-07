<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\models\Section_SiteSettings;
use craft\models\CategoryGroup_SiteSettings;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * `routes` tool — combined route map.
 *
 * Resolves the four sources of URI matching in Craft into one read:
 *   - **config-file routes** — `config/routes.php`
 *   - **project-config routes** — admin-edited routes stored in YAML
 *   - **section URI formats** — per-site, per-section URL templates
 *   - **category-group URIs** — per-site, per-group URL templates
 *
 * Together these tell the AI "what URL does THIS pattern resolve to,
 * and where would I add a new one?". No parameters.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Routes extends AbstractTool
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
        return 'routes';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Combined route map: config-file routes (config/routes.php), project-config ' .
            'routes (admin-edited), section URI formats per site, and category-group URIs ' .
            'per site. Tells you what URL patterns exist on the install and where to add ' .
            'a new one.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $routesService = Craft::$app->getRoutes();
        $sites = Craft::$app->getSites()->getAllSites();
        $entries = Craft::$app->getEntries();
        $categories = Craft::$app->getCategories();

        $sectionRoutes = [];
        foreach ($entries->getAllSections() as $section) {
            foreach ($section->getSiteSettings() as $settings) {
                /** @var Section_SiteSettings $settings */
                if (!$settings->hasUrls) {
                    continue;
                }

                $siteId = (int) $settings->siteId;
                $sectionRoutes[] = [
                    'sectionHandle' => $section->handle,
                    'sectionType' => $section->type,
                    'siteId' => $siteId,
                    'siteHandle' => $this->_siteHandle($siteId),
                    'uriFormat' => $settings->uriFormat,
                    'template' => $settings->template,
                ];
            }
        }

        $categoryRoutes = [];
        foreach ($categories->getAllGroups() as $group) {
            foreach ($group->getSiteSettings() as $settings) {
                /** @var CategoryGroup_SiteSettings $settings */
                if (!$settings->hasUrls) {
                    continue;
                }

                $siteId = (int) $settings->siteId;
                $categoryRoutes[] = [
                    'groupHandle' => $group->handle,
                    'siteId' => $siteId,
                    'siteHandle' => $this->_siteHandle($siteId),
                    'uriFormat' => $settings->uriFormat,
                    'template' => $settings->template,
                ];
            }
        }

        return [
            'configFileRoutes' => $this->_normalizeRoutes($routesService->getConfigFileRoutes()),
            'projectConfigRoutes' => $this->_normalizeRoutes($routesService->getProjectConfigRoutes()),
            'sectionRoutes' => $sectionRoutes,
            'categoryGroupRoutes' => $categoryRoutes,
            'siteCount' => count($sites),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Yii's route lists come back as `pattern => target` maps. Project them
     * into a list of `{pattern, target}` dicts so JSON encoding produces a
     * stable array (and so the AI doesn't have to special-case map shapes).
     *
     * @param array<string,mixed> $routes
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _normalizeRoutes(array $routes): array
    {
        $out = [];
        foreach ($routes as $pattern => $target) {
            $out[] = [
                'pattern' => is_string($pattern) ? $pattern : (string) $pattern,
                'target' => $target,
            ];
        }

        return $out;
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _siteHandle(int $siteId): ?string
    {
        return Craft::$app->getSites()->getSiteById($siteId)?->handle;
    }
}
