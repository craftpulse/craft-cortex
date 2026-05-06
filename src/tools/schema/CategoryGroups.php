<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `category_groups` tool — list / get / count category groups.
 *
 * Modes:
 *   - default: list all groups with site settings, max levels, default
 *     placement, field-layout summary.
 *   - `handle`: single group with full field layout + site settings.
 *   - `count: true`: count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class CategoryGroups extends AbstractTool
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
        return 'category_groups';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List all category groups, get a single group by handle, or count them. ' .
            'Returns each group with its site settings, max levels, default placement, ' .
            'and field-layout summary.';
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
                'handle' => ['type' => 'string'],
                'count' => ['type' => 'boolean'],
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
        $service = Craft::$app->getCategories();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $group = $service->getGroupByHandle($handle);
            if ($group === null) {
                throw new ToolException("No category group found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['categoryGroup' => $this->_serializeGroup($group)];
        }

        $groups = $service->getAllGroups();

        if ($count) {
            return ['count' => count($groups)];
        }

        return [
            'categoryGroups' => array_map(
                fn (CategoryGroup $g): array => $this->_serializeGroup($g),
                $groups,
            ),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeGroup(CategoryGroup $group): array
    {
        $layout = $group->getFieldLayout();

        return [
            'id' => $group->id,
            'uid' => $group->uid,
            'name' => $group->name,
            'handle' => $group->handle,
            'maxLevels' => $group->maxLevels,
            'defaultPlacement' => $group->defaultPlacement,
            'fieldLayout' => [
                'id' => $layout->id,
                'uid' => $layout->uid,
                'tabCount' => count($layout->getTabs()),
                'customFieldCount' => count($layout->getCustomFields()),
            ],
            'siteSettings' => array_map(
                static function (CategoryGroup_SiteSettings $s): array {
                    $siteId = (int) $s->siteId;
                    return [
                        'siteId' => $siteId,
                        'siteUid' => Craft::$app->getSites()->getSiteById($siteId)?->uid,
                        'hasUrls' => $s->hasUrls,
                        'uriFormat' => $s->uriFormat,
                        'template' => $s->template,
                    ];
                },
                array_values($group->getSiteSettings()),
            ),
        ];
    }
}
