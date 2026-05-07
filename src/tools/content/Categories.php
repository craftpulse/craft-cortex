<?php

namespace craftpulse\cortex\tools\content;

use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `categories` tool — list / get / count categories with structure params.
 *
 * Filters: group, id, uid, slug, title, status, enabled, level,
 * hasDescendants, leaves, descendantOf, ancestorOf, siblingOf, relatedTo,
 * search, with, orderBy, limit/offset, site, count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Categories extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

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
        return 'categories';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List, get, or count Craft categories. Supports group filter, structure ' .
            'params (level, hasDescendants, leaves, descendantOf, ancestorOf, siblingOf), ' .
            'relatedTo, search, eager loading via `with: [...]`, and pagination.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'id' => Schema::any()->description('Single id or array.'),
            'uid' => Schema::any()->description('Single uid or array.'),
            'slug' => Schema::string(),
            'title' => Schema::string(),
            'group' => Schema::any()->description('Group handle, id, or array.'),
            'status' => Schema::any()->description('Status string or array.'),
            'enabled' => Schema::boolean(),
            'level' => Schema::any()->description('Structure level (int or comparison string).'),
            'hasDescendants' => Schema::boolean(),
            'leaves' => Schema::boolean(),
            'descendantOf' => Schema::any()->description('Element id or instance.'),
            'ancestorOf' => Schema::any()->description('Element id or instance.'),
            'siblingOf' => Schema::any()->description('Element id or instance.'),
            'relatedTo' => Schema::any()->description('Craft relation syntax.'),
            'search' => Schema::string(),
            'with' => Schema::array(Schema::string()),
            'orderBy' => Schema::string(),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
            'site' => Schema::any()->description('Site handle, id, or "*".'),
            'count' => Schema::boolean(),
        ])->toArray();
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

        $query = $this->_buildQuery($arguments, $eagerHandles);

        if ($this->_isCount($arguments)) {
            return ['count' => (int) $query->count()];
        }

        $id = $arguments['id'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
            $category = $query->one();
            if (!$category instanceof Category) {
                throw new ToolException("No category found with id={$id}.");
            }

            return ['category' => $serializer->serializeElement($category, $eagerHandles)];
        }

        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Category[] $categories */
        $categories = $query->all();

        return [
            'categories' => array_map(
                static fn (Category $c): array => $serializer->serializeElement($c, $eagerHandles),
                $categories,
            ),
            'count' => count($categories),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
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
    private function _buildQuery(array $arguments, array $eagerHandles): CategoryQuery
    {
        $query = Category::find();

        foreach ([
            'id', 'uid', 'slug', 'title',
            'group', 'status', 'enabled',
            'level', 'hasDescendants', 'leaves',
            'descendantOf', 'ancestorOf', 'siblingOf',
            'relatedTo', 'search', 'orderBy',
        ] as $param) {
            if (array_key_exists($param, $arguments)) {
                $query->{$param}($arguments[$param]);
            }
        }

        if (isset($arguments['site'])) {
            $query->site($arguments['site']);
        }

        if ($eagerHandles !== []) {
            $query->with($eagerHandles);
        }

        return $query;
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

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
