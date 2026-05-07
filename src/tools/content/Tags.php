<?php

namespace craftpulse\cortex\tools\content;

use craft\elements\db\TagQuery;
use craft\elements\Tag;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `tags` tool — list / get / count tags.
 *
 * Filters: group, id, uid, slug, title, status, enabled, relatedTo,
 * search, with, orderBy, limit/offset, site, count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Tags extends AbstractTool
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
        return 'tags';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
            $tag = $query->one();
            if (!$tag instanceof Tag) {
                throw new ToolException("No tag found with id={$id}.");
            }

            return ['tag' => $serializer->serializeElement($tag, $eagerHandles)];
        }

        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Tag[] $tags */
        $tags = $query->all();

        return [
            'tags' => array_map(
                static fn (Tag $t): array => $serializer->serializeElement($t, $eagerHandles),
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
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildQuery(array $arguments, array $eagerHandles): TagQuery
    {
        $query = Tag::find();

        foreach ([
            'id', 'uid', 'slug', 'title',
            'group', 'status', 'enabled',
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
