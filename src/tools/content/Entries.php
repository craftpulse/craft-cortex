<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `entries` tool — list / get / count Craft entries with full query surface.
 *
 * Designed as a single thick tool with parameters
 * instead of separate `list_entries`, `get_entry`, `count_entries`.
 * Modes:
 *   - default: list matching entries.
 *   - `id` (single int) → returns one entry under `entry`.
 *   - `count: true` → returns `{count: int}`.
 *
 * Filters supported (all optional, all combinable):
 *   - `id`, `uid`, `slug`, `title`
 *   - `section` (handle / id / array), `type` (entry-type handle / id / array)
 *   - `status` (live / pending / expired / disabled / etc.), `enabled`
 *   - `authorId`
 *   - `relatedTo` — full Craft syntax (single id, array of ids, hash with
 *     `targetElement` / `sourceElement` / `field`, AND/OR forms)
 *   - `search`
 *   - `with: ["fieldHandle"]` — eager-load relational fields so serialised
 *     output includes related elements without triggering N+1
 *   - `orderBy`, `limit`, `offset`, `before`, `after`
 *   - Structure params: `level`, `hasDescendants`, `leaves`, `descendantOf`,
 *     `ancestorOf`, `siblingOf`
 *   - Site filter: `site` (handle, id, or "*" for all)
 *
 * Pagination defaults: `limit: 100`, hard-cap 1000. Past that callers
 * should narrow the filter (or page via `offset`).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Entries extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    /**
     * Sortable fields for the `orderBy` argument, mapped to the
     * fully-qualified column each alias resolves to.
     *
     * Nothing outside this map reaches the SQL `ORDER BY` clause — see
     * `AbstractTool::_orderBy()` for why a value allowlist rather than a
     * type declaration is the control here. Columns are qualified the
     * way Craft's own `EntryQuery::$defaultOrderBy` qualifies them, so
     * `id` and `dateCreated` cannot come out ambiguous across the
     * `elements` / `elements_sites` / `entries` joins.
     *
     * Structure order is intentionally absent: `structureelements` is
     * only joined for structure sections, so exposing it would turn a
     * sort request on a channel section into a SQL error. Structure
     * sections already come back in structure order by default, which
     * is what a caller asking for it actually wants.
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
        'postDate' => 'entries.postDate',
        'expiryDate' => 'entries.expiryDate',
        'sectionId' => 'entries.sectionId',
        'typeId' => 'entries.typeId',
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
        return 'entries';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List, get, or count Craft entries with the full element-query surface: ' .
            'filter by section / type / status / author / related-to, search, eager-load ' .
            'relational fields with `with: [...]`, paginate via limit + offset, sort via ' .
            'orderBy, and use structure params (level, hasDescendants, leaves, ' .
            'descendantOf, etc.). Pass `id` for a single entry. Pass `count: true` to ' .
            'return a count instead of full results.';
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
            'id' => Schema::any()->description('Single entry id, or array of ids.'),
            'uid' => Schema::any()->description('Single uid, or array of uids.'),
            'slug' => Schema::string(),
            'title' => Schema::string(),
            'section' => Schema::any()->description('Section handle, id, array, or `null` for sectionless (nested) entries.'),
            'type' => Schema::any()->description('Entry-type handle, id, or array.'),
            'status' => Schema::any()->description('Status string or array (live, pending, expired, disabled, …).'),
            'enabled' => Schema::boolean(),
            'authorId' => Schema::any()->description('Author user id, or array of ids.'),
            'relatedTo' => Schema::any()->description('Craft relation syntax: single id, array of ids, or hash {targetElement|sourceElement|field}. AND/OR also supported as nested arrays.'),
            'search' => Schema::string(),
            'with' => Schema::array(Schema::string())
                ->description('Eager-loaded relational field handles. Without this, relational fields appear as `{loaded: false}` stubs to prevent N+1.'),
            'orderBy' => Schema::string()
                ->description(
                    'Sort expression: `<field> [asc|desc]`, comma-separated for multiple ' .
                    'fields. Sortable fields: ' . implode(', ', array_keys(self::SORTABLE_FIELDS)) . '. ' .
                    'Structure sections are returned in structure order when no sort is given.',
                ),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
            'before' => Schema::any()->description('postDate < this value (Craft date string).'),
            'after' => Schema::any()->description('postDate >= this value.'),
            'level' => Schema::any()->description('Structure level (int or comparison string like ">2").'),
            'hasDescendants' => Schema::boolean(),
            'leaves' => Schema::boolean(),
            'descendantOf' => Schema::any()->description('Element id or instance.'),
            'ancestorOf' => Schema::any()->description('Element id or instance.'),
            'siblingOf' => Schema::any()->description('Element id or instance.'),
            'site' => Schema::any()->description('Site handle, id, or "*" for all sites.'),
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

        // Single-id shortcut: caller passed exactly one int id.
        $id = $arguments['id'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
            $entry = $query->one();
            if (!$entry instanceof Entry) {
                throw new ToolException("No entry found with id={$id}.");
            }

            return ['entry' => $serializer->serializeElement($entry, $eagerHandles)];
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();

        $query->limit($limit)->offset($offset);

        /** @var Entry[] $entries */
        $entries = $query->all();

        return [
            'entries' => array_map(
                static fn(Entry $e): array => $serializer->serializeElement($e, $eagerHandles),
                $entries,
            ),
            'count' => count($entries),
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
    private function _buildQuery(array $arguments, array $eagerHandles): EntryQuery
    {
        $query = Entry::find();

        // Apply optional filters in lock-step with the schema. Every Craft
        // setter accepts strings/ints/arrays where appropriate.
        //
        // `orderBy` is deliberately NOT in this pass-through list — it
        // lands in the SQL ORDER BY clause unquoted and goes through the
        // `SORTABLE_FIELDS` allowlist below instead.
        foreach ([
            'id', 'uid', 'slug', 'title',
            'section', 'type', 'status', 'enabled', 'authorId',
            'relatedTo', 'search',
            'before', 'after',
            'level', 'hasDescendants', 'leaves',
            'descendantOf', 'ancestorOf', 'siblingOf',
        ] as $param) {
            if (array_key_exists($param, $arguments)) {
                $query->{$param}($arguments[$param]);
            }
        }

        $orderBy = $this->_orderBy($arguments, self::SORTABLE_FIELDS);
        if ($orderBy !== null) {
            $query->orderBy($orderBy);
        }

        // `site` is a thin alias DSL: '*' = every site, else handle/id.
        if (isset($arguments['site'])) {
            $query->site($arguments['site']);
        }

        if ($eagerHandles !== []) {
            $query->with($eagerHandles);
        }

        return $query;
    }
}
