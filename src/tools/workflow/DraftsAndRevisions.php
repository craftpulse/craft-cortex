<?php

namespace craftpulse\cortex\tools\workflow;

use craft\elements\Entry;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use DateTimeInterface;

/**
 * =========================================================================
 * `drafts_and_revisions` tool — read-only inspection of entry draft and
 * revision history.
 *
 * Modes:
 *   - `list_drafts` — paginated list of drafts. Filterable by section
 *     handle, canonical entry id, and draft creator. Multi-site by
 *     default (`site('*')`).
 *   - `list_revisions` — paginated list of revisions for a given
 *     canonical entry id. Ordered newest-first by revision number.
 *   - `compare` — field-level diff between two entries (canonical /
 *     draft / revision in any combination). Returns a `differences`
 *     map keyed by field handle plus identity summaries of both
 *     sides.
 *
 * Pro adds `apply` / `discard` modes for drafts (PLANNING.md 4.7) —
 * those mutate state, gated behind `viewEntries:{section}` plus save
 * permissions on apply. Free is read-only.
 *
 * Compare mode emits scalar/array field values directly; relational and
 * other complex field types are stubbed as
 * `{_diff: 'unsupported', _class: '<fqcn>'}` so the LLM can fall back
 * to `entries({with: [...]})` for relational deep-dives.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class DraftsAndRevisions extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;

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
        return 'drafts_and_revisions';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Inspect entry drafts and revisions. Modes: `list_drafts` lists drafts ' .
            '(optionally for a section, canonical entry, or creator); `list_revisions` ' .
            'lists revisions of a canonical entry id; `compare` returns a field-level diff ' .
            'between two entries (canonical / draft / revision in any combination). Read-only — ' .
            'apply/discard unlocks in Pro.';
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
                ->enum(['list_drafts', 'list_revisions', 'compare'])
                ->required()
                ->description('Required.'),
            'section' => Schema::string()->description('Section handle. Filters list_drafts.'),
            'canonicalId' => Schema::integer()
                ->description('Canonical entry id. Required for list_revisions; optional filter for list_drafts.'),
            'creatorId' => Schema::integer()->description('User id of draft creator. Filters list_drafts.'),
            'leftId' => Schema::integer()->description('First entry/draft/revision id for `compare` mode.'),
            'rightId' => Schema::integer()->description('Second entry/draft/revision id for `compare` mode.'),
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
            throw new ToolException('`mode` is required (list_drafts / list_revisions / compare).');
        }

        return match ($mode) {
            'list_drafts' => $this->_listDrafts($arguments),
            'list_revisions' => $this->_listRevisions($arguments),
            'compare' => $this->_compare($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
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
    private function _listDrafts(array $arguments): array
    {
        $query = Entry::find()
            ->drafts(true)
            ->status(null)
            ->site('*');

        if (isset($arguments['section']) && is_string($arguments['section']) && $arguments['section'] !== '') {
            $query->section($arguments['section']);
        }
        if (isset($arguments['canonicalId']) && is_int($arguments['canonicalId'])) {
            $query->draftOf($arguments['canonicalId']);
        }
        if (isset($arguments['creatorId']) && is_int($arguments['creatorId'])) {
            $query->draftCreator($arguments['creatorId']);
        }

        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();

        $query->limit($limit)->offset($offset);

        /** @var Entry[] $entries */
        $entries = $query->all();

        return [
            'drafts' => array_map(
                fn(Entry $e): array => $this->_serializeDraft($e),
                $entries,
            ),
            'count' => count($entries),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeDraft(Entry $entry): array
    {
        // `draftName`, `draftNotes`, `draftCreatorId`, `isProvisionalDraft`
        // live on `craft\behaviors\DraftBehavior`, attached at runtime to
        // any Entry returned from a `drafts(true)` query. PHPStan can't
        // see through the dynamic behavior so we read them defensively
        // via `__get`-style property access — the values are present at
        // runtime even when stub-typed as undefined.
        return [
            'id' => $entry->id,
            'draftId' => $entry->draftId,
            'canonicalId' => $entry->getCanonicalId(),
            'title' => $entry->title,
            'slug' => $entry->slug,
            'sectionHandle' => $entry->getSection()?->handle,
            'siteHandle' => $entry->getSite()->handle,
            'draftName' => $entry->canGetProperty('draftName') ? $entry->draftName : null, // @phpstan-ignore-line
            'draftNotes' => $entry->canGetProperty('draftNotes') ? $entry->draftNotes : null, // @phpstan-ignore-line
            'creatorId' => $entry->canGetProperty('draftCreatorId') ? $entry->draftCreatorId : null, // @phpstan-ignore-line
            'isProvisional' => $entry->canGetProperty('isProvisionalDraft') ? (bool) $entry->isProvisionalDraft : false,
            'dateCreated' => $entry->dateCreated?->format(DateTimeInterface::ATOM),
            'dateUpdated' => $entry->dateUpdated?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _listRevisions(array $arguments): array
    {
        $canonicalId = $arguments['canonicalId'] ?? null;
        if (!is_int($canonicalId)) {
            throw new ToolException('`canonicalId` is required for mode=list_revisions.');
        }

        $query = Entry::find()
            ->revisions(true)
            ->revisionOf($canonicalId)
            ->status(null)
            ->site('*')
            ->orderBy('num desc');

        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();

        $query->limit($limit)->offset($offset);

        /** @var Entry[] $entries */
        $entries = $query->all();

        return [
            'canonicalId' => $canonicalId,
            'revisions' => array_map(
                fn(Entry $e): array => $this->_serializeRevision($e),
                $entries,
            ),
            'count' => count($entries),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeRevision(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'revisionId' => $entry->revisionId,
            'canonicalId' => $entry->getCanonicalId(),
            'num' => $entry->revisionNum ?? null,
            'notes' => $entry->revisionNotes ?? null,
            'creatorId' => $entry->revisionCreatorId ?? null,
            'title' => $entry->title,
            'siteHandle' => $entry->getSite()->handle,
            'dateCreated' => $entry->dateCreated?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _compare(array $arguments): array
    {
        $leftId = $arguments['leftId'] ?? null;
        $rightId = $arguments['rightId'] ?? null;
        if (!is_int($leftId) || !is_int($rightId)) {
            throw new ToolException('Both `leftId` and `rightId` are required for mode=compare.');
        }

        $left = $this->_loadAnyEntry($leftId);
        $right = $this->_loadAnyEntry($rightId);

        $leftValues = $this->_extractFieldValues($left);
        $rightValues = $this->_extractFieldValues($right);

        $allKeys = array_unique(array_merge(array_keys($leftValues), array_keys($rightValues)));
        $differences = [];
        foreach ($allKeys as $key) {
            $l = $leftValues[$key] ?? null;
            $r = $rightValues[$key] ?? null;
            if ($l !== $r) {
                $differences[$key] = ['left' => $l, 'right' => $r];
            }
        }

        return [
            'left' => $this->_compareSummary($left),
            'right' => $this->_compareSummary($right),
            'differences' => $differences,
            'differenceCount' => count($differences),
        ];
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _loadAnyEntry(int $id): Entry
    {
        $entry = Entry::find()
            ->id($id)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->site('*')
            ->one();
        if (!$entry instanceof Entry) {
            throw new ToolException("No entry/draft/revision found with id={$id}.");
        }
        return $entry;
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _compareSummary(Entry $entry): array
    {
        $kind = match (true) {
            $entry->getIsDraft() => 'draft',
            $entry->getIsRevision() => 'revision',
            default => 'canonical',
        };

        return [
            'id' => $entry->id,
            'canonicalId' => $entry->getCanonicalId(),
            'kind' => $kind,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'siteHandle' => $entry->getSite()->handle,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _extractFieldValues(Entry $entry): array
    {
        $values = [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'enabled' => $entry->enabled,
            'authorId' => $entry->authorId,
            'postDate' => $entry->postDate?->format(DateTimeInterface::ATOM),
            'expiryDate' => $entry->expiryDate?->format(DateTimeInterface::ATOM),
        ];

        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return $values;
        }

        foreach ($layout->getCustomFields() as $field) {
            $handle = $field->handle;
            if (!is_string($handle) || $handle === '') {
                continue;
            }
            $value = $entry->getFieldValue($handle);
            if (is_scalar($value) || $value === null || is_array($value)) {
                $values[$handle] = $value;
            } else {
                $values[$handle] = [
                    '_diff' => 'unsupported',
                    '_class' => $value::class,
                ];
            }
        }

        return $values;
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
