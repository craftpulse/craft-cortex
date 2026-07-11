<?php

namespace craftpulse\herald\tools\schema;

use Craft;
use craft\models\TagGroup;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `tag_groups` tool — list / get / count tag groups.
 *
 * Modes:
 *   - default: list all tag groups with field-layout summary.
 *   - `handle`: single group with full field-layout summary.
 *   - `count: true`: count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class TagGroups extends AbstractTool
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
        return 'tag_groups';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List all tag groups, get a single group by handle, or count them. ' .
            'Returns each group with its field-layout summary.';
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
        $service = Craft::$app->getTags();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $group = $service->getTagGroupByHandle($handle);
            if ($group === null) {
                throw new ToolException("No tag group found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['tagGroup' => $this->_serializeGroup($group)];
        }

        $groups = $service->getAllTagGroups();

        if ($count) {
            return ['count' => count($groups)];
        }

        return [
            'tagGroups' => array_map(
                fn(TagGroup $g): array => $this->_serializeGroup($g),
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
     * @since  5.0.0
     */
    private function _serializeGroup(TagGroup $group): array
    {
        $layout = $group->getFieldLayout();

        return [
            'id' => $group->id,
            'uid' => $group->uid,
            'name' => $group->name,
            'handle' => $group->handle,
            'fieldLayout' => [
                'id' => $layout->id,
                'uid' => $layout->uid,
                'tabCount' => count($layout->getTabs()),
                'customFieldCount' => count($layout->getCustomFields()),
            ],
        ];
    }
}
