<?php

namespace craftpulse\cortex\tools\workflow;

use Craft;
use craft\elements\Entry;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * =========================================================================
 * `import_export` tool — structured-JSON export of entries (Free).
 *
 * Phase 1 ships export only. Pro adds an `import` mode (PLANNING.md
 * 4.7) for cross-environment content sync, gated behind
 * `saveEntries:{section}` permissions and dry-run-by-default.
 *
 * Output shape:
 *
 * ```
 * {
 *   "format": 1,
 *   "mode": "export",
 *   "exportedAt": "2026-05-07T15:00:00+00:00",
 *   "count": 12,
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
 *       "authorId": 5,
 *       "postDate": "2026-...",
 *       "expiryDate": null,
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
 * `format: 1` is the schema version. Future schema bumps stay
 * backwards-compatible by version detection on import.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class ImportExport extends AbstractTool
{
    // Constants
    // =========================================================================

    public const FORMAT_VERSION = 1;
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
        return 'import_export';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
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
     * @since  0.1.0
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
     * @since  0.1.0
     */
    private function _export(array $arguments): array
    {
        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

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

        return [
            'format' => self::FORMAT_VERSION,
            'mode' => 'export',
            'exportedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'count' => count($entries),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'entries' => array_map(
                fn (Entry $e): array => $this->_serializeEntry($e),
                $entries,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeEntry(Entry $entry): array
    {
        $section = $entry->getSection();
        $type = $entry->getType();

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
            'postDate' => $entry->postDate?->format(DateTimeInterface::ATOM),
            'expiryDate' => $entry->expiryDate?->format(DateTimeInterface::ATOM),
            'fields' => $entry->getSerializedFieldValues(),
        ];
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
