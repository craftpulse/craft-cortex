<?php

namespace craftpulse\herald\tools\content;

use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

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
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Categories extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    /**
     * Sortable fields for the `orderBy` argument, mapped to the
     * fully-qualified column each alias resolves to. Nothing outside
     * this map reaches the SQL `ORDER BY` clause — see
     * `AbstractTool::_orderBy()` for why the control is a value
     * allowlist and not a type declaration.
     *
     * Structure order is intentionally absent: category groups are
     * always structures, so Craft already returns them in structure
     * order when no sort is given, and naming `structureelements`
     * explicitly would couple the tool to a join it does not own.
     *
     * @var array<string,string>
     *
     * @since 5.0.0
     */
    public const SORTABLE_FIELDS = [
        'id' => 'elements.id',
        'uid' => 'elements.uid',
        'title' => 'elements_sites.title',
        'slug' => 'elements_sites.slug',
        'uri' => 'elements_sites.uri',
        'groupId' => 'categories.groupId',
        'enabled' => 'elements.enabled',
        'dateCreated' => 'elements.dateCreated',
        'dateUpdated' => 'elements.dateUpdated',
    ];

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
        return 'categories';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
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
     * @since  5.0.0
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
            'orderBy' => Schema::string()
                ->description(
                    'Sort expression: `<field> [asc|desc]`, comma-separated for multiple ' .
                    'fields. Sortable fields: ' . implode(', ', array_keys(self::SORTABLE_FIELDS)) . '. ' .
                    'Categories are returned in structure order when no sort is given.',
                ),
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
     * @since  5.0.0
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

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Category[] $categories */
        $categories = $query->all();

        return [
            'categories' => array_map(
                static fn(Category $c): array => $serializer->serializeElement($c, $eagerHandles),
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
     * @throws ToolException from `_orderBy()` when the sort expression is not allowlisted.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildQuery(array $arguments, array $eagerHandles): CategoryQuery
    {
        $query = Category::find();

        // `orderBy` is deliberately absent from this pass-through list —
        // it lands in the SQL ORDER BY clause unquoted and goes through
        // the `SORTABLE_FIELDS` allowlist below instead.
        foreach ([
            'id', 'uid', 'slug', 'title',
            'group', 'status', 'enabled',
            'level', 'hasDescendants', 'leaves',
            'descendantOf', 'ancestorOf', 'siblingOf',
            'relatedTo', 'search',
        ] as $param) {
            if (array_key_exists($param, $arguments)) {
                $query->{$param}($arguments[$param]);
            }
        }

        $orderBy = $this->_orderBy($arguments, self::SORTABLE_FIELDS);
        if ($orderBy !== null) {
            $query->orderBy($orderBy);
        }

        if (isset($arguments['site'])) {
            $query->site($arguments['site']);
        }

        if ($eagerHandles !== []) {
            $query->with($eagerHandles);
        }

        return $query;
    }
}
