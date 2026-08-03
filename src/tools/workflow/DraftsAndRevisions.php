<?php

namespace craftpulse\herald\tools\workflow;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\DualModeToolInterface;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use Throwable;

/**
 * =========================================================================
 * `drafts_and_revisions` tool — read-only inspection of entry draft and
 * revision history, plus Pro-edition apply/discard mutations.
 *
 * Free modes (always available):
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
 * Pro modes (Gate 8.8a — mode-unlock composition contract, locked
 * decision):
 *   - `apply` — applies a draft to its canonical via Craft's drafts
 *     service. Permission `saveEntries:{canonicalSectionUid}`.
 *   - `discard` — hard-deletes the draft, leaves the canonical untouched.
 *     Permission `saveEntries:{canonicalSectionUid}` (mirrors Craft's
 *     own `ElementsController::actionDeleteDraft()` semantics, which
 *     gate draft delete against the canonical's save permission).
 *
 * The tool stays Free-registered (`shouldRegister()` always true). Pro
 * unlock happens at `inputSchemaFor()` (the mode disappears from
 * `tools/list` for non-permitted callers and from any Free-edition
 * caller) and at `execute()` (defense-in-depth re-check throws
 * `ToolException` with the locked rich-format message when a Pro mode
 * is dispatched without authority). Free behaviour is bit-identical to
 * Gate 8 baseline.
 *
 * Compare mode emits scalar/array field values directly; relational and
 * other complex field types are stubbed as
 * `{_diff: 'unsupported', _class: '<fqcn>'}` so the LLM can fall back
 * to `entries({with: [...]})` for relational deep-dives.
 *
 * Overlap with `entry.apply_draft`: the existing Pro `entry` tool
 * exposes the same draft-apply operation under a different surface.
 * Both ship because they target different LLM tool-selection paths —
 * `drafts_and_revisions.apply` is the natural continuation after
 * `list_drafts`, while `entry.apply_draft` chains naturally after
 * `entry.create + draft creation`. The duplicate is deliberate.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class DraftsAndRevisions extends AbstractTool implements DualModeToolInterface
{
    use PermissionedToolTrait;

    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;

    /**
     * @var list<string>
     */
    private const FREE_MODES = ['list_drafts', 'list_revisions', 'compare'];

    /**
     * @var list<string>
     */
    private const PRO_MODES = ['apply', 'discard'];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'drafts_and_revisions';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Inspect entry drafts and revisions. Read modes: `list_drafts`, ' .
            '`list_revisions`, `compare` (field-level diff). Pro modes: `apply` ' .
            '(merge a draft into its canonical) and `discard` (hard-delete the ' .
            'draft, canonical untouched). Pro modes require `saveEntries:{section}`.';
    }

    /**
     * @inheritdoc
     *
     * Derived from `FREE_MODES` / `PRO_MODES` so there is exactly one
     * place per tool where a mode is read vs write. Consulted by
     * `services\Audit::handleToolInvocation()` for per-invocation
     * `herald.tool.write_invoked` classification — the class-level
     * `#[IsReadOnly]` MCP hint above is accurate for the Free tier but
     * cannot express the Pro `apply` / `discard` modes, so the audit
     * emitter asks this method instead of the attribute for tools that
     * implement `DualModeToolInterface`.
     *
     * @return array<string,bool>
     *
     * @author CraftPulse
     * @since  5.1.0
     */
    public static function getModeWriteMap(): array
    {
        return array_merge(
            array_fill_keys(self::FREE_MODES, false),
            array_fill_keys(self::PRO_MODES, true),
        );
    }

    /**
     * @inheritdoc
     *
     * Base schema — exposes the Free + Pro enum on Pro installs, the
     * Free-only enum on Free installs. The edition gate runs here so
     * the static surface (`tools/list`, `inputSchemaFor(null)`, and
     * the architecture invariant at
     * `tests/Mcp/ToolInterfaceInvariantTest.php`) stay aligned with the
     * runtime gate in `execute()`. HTTP callers on Pro are further
     * filtered down per Craft permissions in `inputSchemaFor($user)`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        $modes = Herald::getInstance()->is(Herald::EDITION_PRO, '>=')
            ? array_merge(self::FREE_MODES, self::PRO_MODES)
            : self::FREE_MODES;

        return Schema::object([
            'mode' => Schema::string()
                ->enum($modes)
                ->required()
                ->description('Required.'),
            'section' => Schema::string()->description('Section handle. Filters list_drafts.'),
            'canonicalId' => Schema::integer()
                ->description('Canonical entry id. Required for list_revisions; optional filter for list_drafts.'),
            'creatorId' => Schema::integer()->description('User id of draft creator. Filters list_drafts.'),
            'leftId' => Schema::integer()->description('First entry/draft/revision id for `compare` mode.'),
            'rightId' => Schema::integer()->description('Second entry/draft/revision id for `compare` mode.'),
            'id' => Schema::integer()->description('Draft id for `apply` / `discard` modes.'),
            'uid' => Schema::string()->description('Draft uid for `apply` / `discard` modes.'),
            'siteId' => Schema::integer()->description('Site id for the draft lookup in `apply` / `discard`.'),
            'siteHandle' => Schema::string()->description('Site handle for the draft lookup in `apply` / `discard`.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. stdio (`null` user) gets the full
     * static enum verbatim — the trusted local path. Filters happen
     * only for HTTP callers (non-null user). An HTTP caller on a
     * Free-edition install never sees the Pro modes regardless of
     * permission. An HTTP caller on Pro who lacks `saveEntries:*` on
     * any section is downgraded to the Free enum. `execute()` still
     * re-validates the resolved mode for security AND blocks Pro modes
     * on Free installs.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        // `getInputSchema()` already reflects the edition (Free vs
        // Pro). For stdio (null user) and Free installs, this is the
        // final answer — no per-permission filtering applies.
        $schema = static::getInputSchema();

        if ($user === null || !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            return $schema;
        }

        if ($user->admin) {
            return $schema;
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:{$section->uid}")) {
                return $schema;
            }
        }

        $schema['properties']['mode']['enum'] = self::FREE_MODES;
        return $schema;
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $arguments['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            throw new ToolException('`mode` is required (list_drafts / list_revisions / compare / apply / discard).');
        }

        if (in_array($mode, self::PRO_MODES, true) && !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ToolException("drafts_and_revisions: mode `{$mode}` is unavailable on this edition.");
        }

        return match ($mode) {
            'list_drafts' => $this->_listDrafts($arguments),
            'list_revisions' => $this->_listRevisions($arguments),
            'compare' => $this->_compare($arguments),
            'apply' => $this->_apply($arguments),
            'discard' => $this->_discard($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Per-`PermissionedToolTrait` contract: the Craft permissions the
     * given arguments imply. Returns the sentinel `saveEntries:*` when
     * the arguments don't carry a resolvable draft id/uid (e.g. when
     * `filterFor()` probes with empty args). When an id/uid is present
     * the draft is loaded so the per-canonical section UID can drive
     * the gate.
     *
     * Only Pro modes consult this method — Free modes never call
     * `_assertPermission()`. The Free-mode return is harmless because
     * stdio + admin short-circuit before reaching the permission walk.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : null;
        if (!in_array($mode, self::PRO_MODES, true)) {
            return [];
        }

        $sectionUid = $arguments['sectionUid'] ?? null;
        if (is_string($sectionUid) && $sectionUid !== '') {
            return ["saveEntries:{$sectionUid}"];
        }

        return ['saveEntries:*'];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the rich tool-specific format keyed on by 8.10's
     * `ModeErrorShapeTest`. Inherits the stdio / admin skip logic from
     * the trait.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : '?';
        $sectionUid = is_string($arguments['sectionUid'] ?? null) ? $arguments['sectionUid'] : '?';

        return sprintf(
            'permission denied: mode `%s` on section `%s` requires `%s`.',
            $mode,
            $sectionUid,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
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

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

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
     * @author CraftPulse
     * @since  5.0.0
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
            'dateCreated' => $entry->dateCreated !== null ? DateTimeHelper::toIso8601($entry->dateCreated) : null,
            'dateUpdated' => $entry->dateUpdated !== null ? DateTimeHelper::toIso8601($entry->dateUpdated) : null,
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
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

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

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
     * @author CraftPulse
     * @since  5.0.0
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
            'dateCreated' => $entry->dateCreated !== null ? DateTimeHelper::toIso8601($entry->dateCreated) : null,
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
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
     * Apply-mode dispatch (Pro). Resolves the draft by `id` or `uid`,
     * loads its canonical, gates on `saveEntries:{canonicalSectionUid}`,
     * then applies via Craft's drafts service.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _apply(array $arguments): array
    {
        $draft = $this->_resolveDraft($arguments);
        $canonical = $draft->getCanonical(anySite: true);
        if (!$canonical instanceof Entry) {
            throw new ToolException(
                "drafts_and_revisions: apply could not resolve canonical for draft id={$draft->id}."
            );
        }

        $canonicalSection = $canonical->getSection();
        if ($canonicalSection === null) {
            throw new ToolException(
                'drafts_and_revisions: apply on a sectionless (nested) entry is not supported.'
            );
        }

        $this->_assertPermission($arguments + ['sectionUid' => $canonicalSection->uid]);

        try {
            $applied = Craft::$app->getDrafts()->applyDraft($draft);
        } catch (Throwable $e) {
            throw new ToolException("drafts_and_revisions: apply failed: {$e->getMessage()}");
        }

        if (!$applied instanceof Entry) {
            throw new ToolException(
                "drafts_and_revisions: applyDraft returned non-Entry element for draft id={$draft->id}."
            );
        }

        return [
            'success' => true,
            'mode' => 'apply',
            'appliedDraftId' => (int) $draft->id,
            'canonicalId' => (int) $applied->id,
            'canonicalUid' => $applied->uid,
            'siteHandle' => $applied->getSite()->handle,
        ];
    }

    /**
     * Discard-mode dispatch (Pro). Hard-deletes the draft, leaves the
     * canonical untouched. Permission gate mirrors Craft's own
     * `ElementsController::actionDeleteDraft()` — the draft's
     * `canDelete()` ultimately consults `saveEntries` on the canonical's
     * section.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _discard(array $arguments): array
    {
        $draft = $this->_resolveDraft($arguments);
        $canonical = $draft->getCanonical(anySite: true);
        if (!$canonical instanceof Entry) {
            throw new ToolException(
                "drafts_and_revisions: discard could not resolve canonical for draft id={$draft->id}."
            );
        }

        $canonicalSection = $canonical->getSection();
        if ($canonicalSection === null) {
            throw new ToolException(
                'drafts_and_revisions: discard on a sectionless (nested) entry is not supported.'
            );
        }

        $this->_assertPermission($arguments + ['sectionUid' => $canonicalSection->uid]);

        $draftId = (int) $draft->id;
        $canonicalId = (int) $canonical->id;
        $canonicalUid = $canonical->uid;

        try {
            $deleted = Craft::$app->getElements()->deleteElement($draft, true);
        } catch (Throwable $e) {
            throw new ToolException("drafts_and_revisions: discard failed: {$e->getMessage()}");
        }

        if (!$deleted) {
            throw new ToolException(
                "drafts_and_revisions: discard failed for draft id={$draftId}. See Craft logs for details."
            );
        }

        return [
            'success' => true,
            'mode' => 'discard',
            'discardedDraftId' => $draftId,
            'canonicalId' => $canonicalId,
            'canonicalUid' => $canonicalUid,
        ];
    }

    /**
     * Resolve a draft by `id` or `uid`, optionally narrowed by
     * `siteId` / `siteHandle`. Throws `ToolException` when neither id
     * nor uid is supplied, or when no draft matches.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _resolveDraft(array $arguments): Entry
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = Entry::find()->status(null)->drafts(true)->site('*');

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException('drafts_and_revisions: `id` or `uid` is required for mode=apply / discard.');
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $draft = $query->one();
        if (!$draft instanceof Entry) {
            throw new ToolException('drafts_and_revisions: no draft found.');
        }

        return $draft;
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _extractFieldValues(Entry $entry): array
    {
        $values = [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'enabled' => $entry->enabled,
            'authorId' => $entry->authorId,
            'postDate' => $entry->postDate !== null ? DateTimeHelper::toIso8601($entry->postDate) : null,
            'expiryDate' => $entry->expiryDate !== null ? DateTimeHelper::toIso8601($entry->expiryDate) : null,
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
}
