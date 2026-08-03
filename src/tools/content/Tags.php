<?php

namespace craftpulse\herald\tools\content;

use craft\elements\db\TagQuery;
use craft\elements\Tag;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `tags` tool — list / get / count tags.
 *
 * Filters: group, id, uid, slug, title, status, enabled, relatedTo,
 * search, with, orderBy, limit/offset, site, count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Tags extends AbstractTool
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
     * @var array<string,string>
     *
     * @since 5.0.0
     */
    public const SORTABLE_FIELDS = [
        'id' => 'elements.id',
        'uid' => 'elements.uid',
        'title' => 'elements_sites.title',
        'slug' => 'elements_sites.slug',
        'groupId' => 'tags.groupId',
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
        return 'tags';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List, get, or count Craft tags. Filter by group, id, slug, title, status, ' .
            'relatedTo, search. Eager-load relational fields with `with: [...]`. Supports ' .
            'orderBy, limit, offset, site filter.';
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
            'relatedTo' => Schema::any()->description('Craft relation syntax.'),
            'search' => Schema::string(),
            'with' => Schema::array(Schema::string()),
            'orderBy' => Schema::string()
                ->description(
                    'Sort expression: `<field> [asc|desc]`, comma-separated for multiple ' .
                    'fields. Sortable fields: ' . implode(', ', array_keys(self::SORTABLE_FIELDS)) . '.',
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
            $tag = $query->one();
            if (!$tag instanceof Tag) {
                throw new ToolException("No tag found with id={$id}.");
            }

            return ['tag' => $serializer->serializeElement($tag, $eagerHandles)];
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Tag[] $tags */
        $tags = $query->all();

        return [
            'tags' => array_map(
                static fn(Tag $t): array => $serializer->serializeElement($t, $eagerHandles),
                $tags,
            ),
            'count' => count($tags),
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
    private function _buildQuery(array $arguments, array $eagerHandles): TagQuery
    {
        $query = Tag::find();

        // `orderBy` is deliberately absent from this pass-through list —
        // it lands in the SQL ORDER BY clause unquoted and goes through
        // the `SORTABLE_FIELDS` allowlist below instead.
        foreach ([
            'id', 'uid', 'slug', 'title',
            'group', 'status', 'enabled',
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
