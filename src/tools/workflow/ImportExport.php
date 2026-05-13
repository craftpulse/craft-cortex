<?php

namespace craftpulse\cortex\tools\workflow;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use DateTimeImmutable;
use Throwable;

/**
 * =========================================================================
 * `import_export` tool — structured-JSON export of entries (Free).
 *
 * The Free tier ships export only. The Pro tier adds an `import` mode
 * for cross-environment content sync, gated behind
 * `saveEntries:{section}` permissions and dry-run-by-default.
 *
 * Output shape (format 2 — see `FORMAT_VERSION` const for version
 * lifecycle):
 *
 * ```
 * {
 *   "format": 2,
 *   "mode": "export",
 *   "exportedAt": "2026-05-07T15:00:00+00:00",
 *   "craftVersion": "5.x.x",
 *   "craftEdition": "Pro",
 *   "schemaVersion": "...",
 *   "count": 12,
 *   "totalCount": 87,
 *   "limit": 100,
 *   "offset": 0,
 *   "entries": [
 *     {
 *       "uid": "...",
 *       "id": 42,
 *       "title": "...",
 *       "slug": "...",
 *       "section": "blog",
 *       "type": "post",
 *       "site": "default",
 *       "enabled": true,
 *       "enabledForSite": true,
 *       "authorId": 5,
 *       "authorIds": ["uid-of-author"],
 *       "parentUid": null,
 *       "level": 1,
 *       "postDate": "2026-...",
 *       "expiryDate": null,
 *       "dateCreated": "2026-...",
 *       "dateUpdated": "2026-...",
 *       "fields": { ... }
 *     },
 *     ...
 *   ]
 * }
 * ```
 *
 * Field values use Craft's `getSerializedFieldValues()` so the output
 * round-trips into Craft's own serialisation contract — relational
 * fields appear as id arrays, Matrix as block-structure arrays, etc.
 * The Pro importer will consume this exact shape, so what's exported
 * here is what gets imported there.
 *
 * Filters:
 *   - `section` — restrict to one section (handle)
 *   - `id` — single entry id or array of ids (overrides `section`)
 *   - `site` — site handle (defaults to primary)
 *
 * The envelope includes Craft fingerprints (`craftVersion`,
 * `craftEdition`, `schemaVersion`) so the importer can refuse a
 * cross-major or cross-edition payload before walking the entries.
 * Future schema bumps stay backwards-compatible via the `format`
 * version field; format 1 existed only briefly pre-release and is
 * not expected in the wild.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class ImportExport extends AbstractTool
{
    // Constants
    // =========================================================================

    /**
     * Envelope format version. Format 2 carries the full round-trip
     * surface (authorIds for multi-author entries, parentUid + level
     * for Structure hierarchies, enabledForSite for the per-site
     * enabled bit, plus craftVersion / schemaVersion / edition
     * fingerprints on the envelope). Format 1 existed only briefly
     * pre-release — no published consumers, no back-compat layer.
     */
    public const FORMAT_VERSION = 2;
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 1000;

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
        return 'import_export';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Export entries to a structured JSON envelope suitable for cross-environment ' .
            'sync. Modes: `export` returns the envelope. Filter by `section`, `id`, or `site`. ' .
            'The same envelope shape is consumed by the Pro `import` mode (which unlocks ' .
            'create / update / dry-run import behind save permissions).';
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
            'mode' => Schema::string()
                ->enum(['export'])
                ->required()
                ->description('Required. Pro adds `import`.'),
            'section' => Schema::string()->description('Section handle filter.'),
            'id' => Schema::any()->description('Single entry id or array of ids. Overrides section filter.'),
            'site' => Schema::string()->description('Site handle. Defaults to primary site.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
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
        $mode = $arguments['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            throw new ToolException('`mode` is required (export).');
        }

        return match ($mode) {
            'export' => $this->_export($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'. (Pro adds `import`.)"),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _export(array $arguments): array
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $query = Entry::find()->status(null);

        $site = $arguments['site'] ?? null;
        if (is_string($site) && $site !== '') {
            $query->site($site);
        }

        if (isset($arguments['id'])) {
            $query->id($arguments['id']);
        } elseif (isset($arguments['section']) && is_string($arguments['section']) && $arguments['section'] !== '') {
            $query->section($arguments['section']);
        }

        $totalCount = (int) (clone $query)->count();
        $query->limit($limit)->offset($offset);

        /** @var Entry[] $entries */
        $entries = $query->all();

        $info = Craft::$app->getInfo();

        return [
            'format' => self::FORMAT_VERSION,
            'mode' => 'export',
            'exportedAt' => DateTimeHelper::toIso8601(new DateTimeImmutable()),
            'craftVersion' => $info->version,
            'craftEdition' => Craft::$app->getEditionName(),
            'schemaVersion' => $info->schemaVersion,
            'count' => count($entries),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'entries' => array_map(
                fn(Entry $e): array => $this->_serializeEntry($e),
                $entries,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeEntry(Entry $entry): array
    {
        $section = $entry->getSection();
        $type = $entry->getType();
        $parentUid = $this->_parentUid($entry);

        return [
            'uid' => $entry->uid,
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'section' => $section?->handle,
            'type' => $type->handle,
            'site' => $entry->getSite()->handle,
            'enabled' => $entry->enabled,
            'enabledForSite' => $entry->getEnabledForSite(),
            'authorId' => $entry->authorId,
            'authorIds' => $this->_authorIds($entry),
            'parentUid' => $parentUid,
            'level' => $entry->level,
            'postDate' => $entry->postDate !== null ? DateTimeHelper::toIso8601($entry->postDate) : null,
            'expiryDate' => $entry->expiryDate !== null ? DateTimeHelper::toIso8601($entry->expiryDate) : null,
            'dateCreated' => $entry->dateCreated !== null ? DateTimeHelper::toIso8601($entry->dateCreated) : null,
            'dateUpdated' => $entry->dateUpdated !== null ? DateTimeHelper::toIso8601($entry->dateUpdated) : null,
            'fields' => $entry->getSerializedFieldValues(),
        ];
    }

    /**
     * Pull the entry's full author list as a uid array. Craft 5.0+
     * supports multi-author entries via `getAuthorIds()` on the entry.
     * Returns `[]` for entries with no authors. We export uids (not ids)
     * so cross-environment import can resolve users by uid first.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _authorIds(Entry $entry): array
    {
        try {
            $authors = $entry->getAuthors();
        } catch (Throwable) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($author): ?string => is_object($author) && property_exists($author, 'uid') ? (string) $author->uid : null,
            $authors,
        )));
    }

    /**
     * Look up the canonical parent entry's uid for Structure-aware
     * sections. Returns null for top-level entries or non-Structure
     * sections. We expose uid (not id) so a cross-environment import
     * can re-link the hierarchy without depending on auto-incrementing
     * primary keys.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _parentUid(Entry $entry): ?string
    {
        try {
            $parent = $entry->getParent();
        } catch (Throwable) {
            return null;
        }
        return $parent?->uid;
    }
}
