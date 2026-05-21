<?php

namespace craftpulse\cortex\tools\workflow;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\models\Section;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\PermissionedToolTrait;
use craftpulse\cortex\tools\StreamableToolInterface;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use Generator;
use Throwable;

/**
 * =========================================================================
 * `audit` tool — read-only content health reports.
 *
 * Three modes, each producing a report the LLM can act on:
 *
 *   - `relations` — rows in `{{%relations}}` whose target element is
 *     missing or soft-deleted. Catches broken category / asset / entry
 *     references that point to nothing.
 *
 *   - `unused_assets` — assets in any volume not referenced by any
 *     element-field relation. The classic "what can I clean up?" report.
 *     Filterable to a single volume.
 *
 *   - `propagation` — entries in multi-site sections that don't exist
 *     in every site the section is enabled for. Catches translation
 *     gaps and incomplete propagation. Filterable to a single section.
 *
 * Pro fix modes (Gate 8.8b — mode-unlock composition contract, locked
 * decision 6 of `docs/plans/gate-8.md`):
 *   - `fix_relations` — deletes the broken relation rows that the
 *     `relations` read mode lists. Per-row permission resolved from the
 *     source element's owner: `saveEntries:{sectionUid}` /
 *     `saveCategories:{groupUid}` / `saveAssets:{volumeUid}`.
 *   - `prune_unused_assets` — hard-deletes the unreferenced assets that
 *     the `unused_assets` read mode lists. Per-row permission
 *     `deleteAssets:{volumeUid}`. Read→fix race: if an asset became
 *     referenced between the read query and the fix loop, it gets
 *     deleted anyway. The read mode is the authoritative usage check.
 *   - `repair_propagation` — force-resaves entries with missing per-site
 *     copies, propagating to every site the section is enabled for.
 *     Per-canonical permission `saveEntries:{sectionUid}`.
 *
 * Each fix mode returns a top-level envelope mirroring `bulk_entries`:
 * `{success, mode, total, processed, succeeded, failed, skipped,
 * results[]}` where every `results[]` row carries `{kind: 'success' |
 * 'failure' | 'skipped', …}` plus mode-specific fields. The seam is
 * stable for Gate 8.9 to swap to streaming `Generator` returns without
 * touching the per-row helpers.
 *
 * The tool stays Free-registered (`shouldRegister()` always true). Pro
 * unlock happens at `inputSchemaFor()` (the modes disappear from
 * `tools/list` for non-permitted callers and from any Free-edition
 * caller) and at `execute()` (defense-in-depth re-check throws
 * `ToolException` with the locked rich-format message when a Pro mode
 * is dispatched without authority).
 *
 * Each mode returns a paginated envelope (`count`, `totalCount`,
 * `limit`, `offset`) so the LLM can drill in without overwhelming the
 * context window. Default limit 200, hard cap 1000.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Audit extends AbstractTool implements StreamableToolInterface
{
    use PermissionedToolTrait;

    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    /**
     * Default per-frame row interval for streaming fix modes. Mirrors
     * `BulkEntries::DEFAULT_PROGRESS_INTERVAL` — fix-mode result sets are
     * bounded by `MAX_LIMIT` and `PROPAGATION_GLOBAL_GAP_CAP`, so 100
     * rows-per-frame keeps wire overhead bounded while staying granular
     * enough for operator visibility. The `progressInterval` argument
     * overrides per-call (clamped to `>= 1`).
     *
     * @since 5.0.0
     */
    public const DEFAULT_PROGRESS_INTERVAL = 100;

    /**
     * Per-section row cap for the propagation mode's element-fetch step.
     * Sections exceeding this row count get reported as truncated with
     * an explicit hint to narrow with the `section` filter. Bounded to
     * keep the worst-case memory cost predictable.
     *
     * @since 5.0.0
     */
    public const PROPAGATION_SECTION_ROW_CAP = 50000;

    /**
     * Global cap on accumulated propagation gaps across all sections.
     * Once the gap array reaches this size the outer section loop short-
     * circuits and the payload sets `globalCapReached = true`. Picked
     * at 5000 because that's roughly an order of magnitude above what
     * an operator is willing to read in a single tool response, while
     * still leaving headroom for partial-site rollouts where every
     * canonical row shows up as a gap (one section can produce
     * thousands). The per-section cap (above) handles single-section
     * runaway; this one bounds the cumulative cost when many sections
     * each contribute a moderate number of gaps.
     *
     * @since 5.0.0
     */
    public const PROPAGATION_GLOBAL_GAP_CAP = 5000;

    /**
     * @var list<string>
     */
    private const FREE_MODES = ['relations', 'unused_assets', 'propagation'];

    /**
     * @var list<string>
     */
    private const PRO_MODES = ['fix_relations', 'prune_unused_assets', 'repair_propagation'];

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
        return 'content_audit';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Content health reports. Read modes: ' .
            '`relations` lists broken relational references (target element missing or soft-deleted); ' .
            '`unused_assets` lists assets not referenced by any element field; ' .
            '`propagation` lists entries in multi-site sections that don\'t exist in every enabled site. ' .
            'Pro fix modes: `fix_relations` deletes the broken rows, `prune_unused_assets` hard-deletes ' .
            'unreferenced assets, `repair_propagation` force-resaves entries with missing per-site copies. ' .
            'Pro modes require `saveEntries:{section}` / `deleteAssets:{volume}` per row.';
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
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        $modes = Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')
            ? array_merge(self::FREE_MODES, self::PRO_MODES)
            : self::FREE_MODES;

        return Schema::object([
            'mode' => Schema::string()
                ->enum($modes)
                ->required()
                ->description('Required.'),
            'volume' => Schema::string()->description('Volume handle filter for `unused_assets` / `prune_unused_assets`.'),
            'section' => Schema::string()->description('Section handle filter for `propagation` / `repair_propagation`.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
            'progressInterval' => Schema::integer()
                ->minimum(1)
                ->description('Streaming-only. Emit one `notifications/progress` frame every N rows processed (default 100). Ignored on non-streaming dispatch.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. stdio (`null` user) gets the
     * edition-appropriate static schema verbatim. For HTTP callers on
     * Pro, walks Craft permissions and strips any Pro modes the user
     * has no reach for. An HTTP caller on a Free-edition install never
     * sees the Pro modes regardless of permission (the static schema
     * already omitted them). `execute()` still re-validates the
     * resolved mode for security AND blocks Pro modes on Free installs.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        $schema = static::getInputSchema();

        if ($user === null || !Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
            return $schema;
        }

        if ($user->admin) {
            return $schema;
        }

        $modes = self::FREE_MODES;

        $canSaveAnyEntries = false;
        $canSaveAnyCategories = false;
        $canSaveAnyAssets = false;
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:{$section->uid}")) {
                $canSaveAnyEntries = true;
                break;
            }
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($user->can("saveCategories:{$group->uid}")) {
                $canSaveAnyCategories = true;
                break;
            }
        }
        $canDeleteAnyAssets = false;
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user->can("saveAssets:{$volume->uid}")) {
                $canSaveAnyAssets = true;
            }
            if ($user->can("deleteAssets:{$volume->uid}")) {
                $canDeleteAnyAssets = true;
            }
        }

        if ($canSaveAnyEntries || $canSaveAnyCategories || $canSaveAnyAssets) {
            $modes[] = 'fix_relations';
        }
        if ($canDeleteAnyAssets) {
            $modes[] = 'prune_unused_assets';
        }
        if ($canSaveAnyEntries) {
            $modes[] = 'repair_propagation';
        }

        $schema['properties']['mode']['enum'] = $modes;
        return $schema;
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point. Read modes (`relations`, `unused_assets`,
     * `propagation`) dispatch directly to their paged-array helpers —
     * streaming a paged read is over-engineering. Pro fix modes
     * (`fix_relations`, `prune_unused_assets`, `repair_propagation`)
     * collapse `stream()` to its terminal `getReturn()` value, mirroring
     * the `BulkEntries::execute()` precedent at
     * `src/tools/content/BulkEntries.php:339-351`. Both transports
     * surface the same terminal envelope.
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
            throw new ToolException(
                '`mode` is required (relations / unused_assets / propagation / fix_relations / ' .
                    'prune_unused_assets / repair_propagation).',
            );
        }

        if (in_array($mode, self::PRO_MODES, true) && !Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
            throw new ToolException("content_audit: mode `{$mode}` is unavailable on this edition.");
        }

        if (in_array($mode, self::PRO_MODES, true)) {
            $ctx = new InvocationContext();
            $gen = $this->stream($arguments, $ctx);

            // Drain progress frames — they're for the streaming surface.
            while ($gen->valid()) {
                $gen->next();
            }

            $return = $gen->getReturn();
            return is_array($return) ? $return : [];
        }

        return match ($mode) {
            'relations' => $this->_relations($arguments),
            'unused_assets' => $this->_unusedAssets($arguments),
            'propagation' => $this->_propagation($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
    }

    /**
     * @inheritdoc
     *
     * Streaming entry point. Only Pro fix modes are streamable — read
     * modes return paged arrays in a single shot via `execute()`.
     *
     * Per-mode progress shape per `docs/plans/gate-8.9.md` locked
     * decision 1:
     *   - `fix_relations` — frame every `progressInterval` rows;
     *     message `"Fixing broken relation src={sourceId} → target={targetId}"`.
     *   - `prune_unused_assets` — frame every `progressInterval` rows;
     *     message `"Pruning asset {id}"`.
     *   - `repair_propagation` — frame every `progressInterval` gaps;
     *     message `"Repairing canonical {canonicalId} ({sectionUid})"`.
     *
     * Cancellation polls between rows — the in-flight row's mutation
     * completes. Terminal envelope carries `cancelled: true` and the
     * partial `results[]`.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $mode = $arguments['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            throw new ToolException(
                '`mode` is required (relations / unused_assets / propagation / fix_relations / ' .
                    'prune_unused_assets / repair_propagation).',
            );
        }

        if (!in_array($mode, self::PRO_MODES, true)) {
            throw new ToolException("content_audit: mode `{$mode}` is not streamable.");
        }

        if (!Cortex::getInstance()->is(Cortex::EDITION_PRO, '>=')) {
            throw new ToolException("content_audit: mode `{$mode}` is unavailable on this edition.");
        }

        return yield from match ($mode) {
            'fix_relations' => $this->_fixRelations($arguments, $ctx),
            'prune_unused_assets' => $this->_pruneUnusedAssets($arguments, $ctx),
            'repair_propagation' => $this->_repairPropagation($arguments, $ctx),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Per-`PermissionedToolTrait` contract. Only Pro fix modes consult
     * this method — Free read modes never call `_assertPermission()`.
     *
     * Per-row permission resolution happens INSIDE the fix-mode loops
     * (each row owns a different section/volume/group UID). The
     * top-level call returns the wildcard sentinel so `filterFor()`
     * probing with empty args admits any user with at least one
     * matching permission on any resource.
     *
     * Recognised in-loop keys on `$arguments`:
     *   - `sectionUid` → `saveEntries:{uid}` for entry-owned relations
     *     and `repair_propagation`.
     *   - `groupUid` → `saveCategories:{uid}` for category-owned
     *     relations.
     *   - `volumeUid` + `mode = fix_relations` → `saveAssets:{uid}`
     *     for asset-owned relations.
     *   - `volumeUid` + `mode = prune_unused_assets` →
     *     `deleteAssets:{uid}`.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : null;
        if (!in_array($mode, self::PRO_MODES, true)) {
            return [];
        }

        $sectionUid = is_string($arguments['sectionUid'] ?? null) ? $arguments['sectionUid'] : null;
        $groupUid = is_string($arguments['groupUid'] ?? null) ? $arguments['groupUid'] : null;
        $volumeUid = is_string($arguments['volumeUid'] ?? null) ? $arguments['volumeUid'] : null;

        if ($mode === 'prune_unused_assets') {
            return $volumeUid !== null
                ? ["deleteAssets:{$volumeUid}"]
                : ['deleteAssets:*'];
        }

        if ($mode === 'repair_propagation') {
            return $sectionUid !== null
                ? ["saveEntries:{$sectionUid}"]
                : ['saveEntries:*'];
        }

        // fix_relations — owner-dependent.
        if ($sectionUid !== null) {
            return ["saveEntries:{$sectionUid}"];
        }
        if ($groupUid !== null) {
            return ["saveCategories:{$groupUid}"];
        }
        if ($volumeUid !== null) {
            return ["saveAssets:{$volumeUid}"];
        }
        return ['saveEntries:*'];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the rich tool-specific format keyed on by Gate 8.10's
     * `ModeErrorShapeTest`. Resource type is resolved from the
     * arguments — `section` / `volume` / `group`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : '?';

        $resourceType = 'section';
        $resourceUid = '?';
        if (is_string($arguments['sectionUid'] ?? null) && $arguments['sectionUid'] !== '') {
            $resourceType = 'section';
            $resourceUid = $arguments['sectionUid'];
        } elseif (is_string($arguments['volumeUid'] ?? null) && $arguments['volumeUid'] !== '') {
            $resourceType = 'volume';
            $resourceUid = $arguments['volumeUid'];
        } elseif (is_string($arguments['groupUid'] ?? null) && $arguments['groupUid'] !== '') {
            $resourceType = 'group';
            $resourceUid = $arguments['groupUid'];
        }

        return sprintf(
            'permission denied — mode `%s` on %s `%s` requires `%s`.',
            $mode,
            $resourceType,
            $resourceUid,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Find rows in `{{%relations}}` whose target element doesn't exist
     * or has been soft-deleted. Pure query-builder lookup — no element
     * materialisation, fast on large sites.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _relations(array $arguments): array
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $base = $this->_brokenRelationsQuery();

        $totalCount = (int) (clone $base)->count();

        $rows = (clone $base)
            ->select([
                'sourceId' => 'relations.sourceId',
                'targetId' => 'relations.targetId',
                'fieldId' => 'relations.fieldId',
                'sortOrder' => 'relations.sortOrder',
                'sourceSiteId' => 'relations.sourceSiteId',
                'targetDeleted' => 'target.dateDeleted',
            ])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return [
            'mode' => 'relations',
            'broken' => $rows,
            'count' => count($rows),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Shared query builder for the broken-relations report. Both the
     * read mode (`relations`) and the fix mode (`fix_relations`) iterate
     * the same set of rows — pulling the query out lets both surfaces
     * stay in lockstep without one drifting from the other.
     *
     * @return Query<array-key,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _brokenRelationsQuery(): Query
    {
        return (new Query())
            ->from(['relations' => Table::RELATIONS])
            ->leftJoin(['target' => Table::ELEMENTS], '[[target.id]] = [[relations.targetId]]')
            ->where(['or', ['target.id' => null], ['not', ['target.dateDeleted' => null]]]);
    }

    /**
     * Find assets not referenced by any element-field relation. Subquery
     * filter — Yii's query builder parameterises the inner `select` so
     * this is safe on any volume size.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _unusedAssets(array $arguments): array
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $query = $this->_unusedAssetsQuery($arguments);

        $totalCount = (int) (clone $query)->count();

        $query->limit($limit)->offset($offset);

        /** @var Asset[] $assets */
        $assets = $query->all();

        return [
            'mode' => 'unused_assets',
            'assets' => array_map(
                fn(Asset $a): array => $this->_serializeAsset($a),
                $assets,
            ),
            'count' => count($assets),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Shared query builder for the unused-assets report. Both the read
     * mode (`unused_assets`) and the fix mode (`prune_unused_assets`)
     * walk the same result set — keep them in lockstep here.
     *
     * LEFT JOIN against `relations` and filter for rows where no
     * matching `targetId` exists. This replaces the older
     * `NOT IN (SELECT targetId FROM relations)` subquery, which
     * forces MySQL to materialise the full set of referenced ids
     * before running the outer scan — slow on large installs and
     * notoriously hard for the planner to flatten. The LEFT JOIN
     * variant lets the planner use the `relations.targetId` index
     * directly and short-circuits per row.
     *
     * @param array<string,mixed> $arguments
     * @return \craft\elements\db\AssetQuery<array-key,Asset>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _unusedAssetsQuery(array $arguments): \craft\elements\db\AssetQuery
    {
        $query = Asset::find()
            ->status(null)
            ->site('*')
            ->leftJoin(
                ['cortex_relations' => Table::RELATIONS],
                '[[cortex_relations.targetId]] = [[elements.id]]',
            )
            ->andWhere(['cortex_relations.id' => null]);

        if (isset($arguments['volume']) && is_string($arguments['volume']) && $arguments['volume'] !== '') {
            $query->volume($arguments['volume']);
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeAsset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'uid' => $asset->uid,
            'title' => $asset->title,
            'filename' => $asset->getFilename(),
            'kind' => $asset->kind,
            'size' => $asset->size,
            'volumeHandle' => $asset->getVolume()->handle,
            'folderPath' => $asset->folderPath,
            'siteHandle' => $asset->getSite()->handle,
            'dateCreated' => $asset->dateCreated !== null ? DateTimeHelper::toIso8601($asset->dateCreated) : null,
        ];
    }

    /**
     * Find entries in multi-site sections that don't exist in every
     * site the section is enabled for. Iterates entries by canonical id
     * and compares present-site set against expected-site set.
     *
     * Per-section row cap (`PROPAGATION_SECTION_ROW_CAP`) bounds the
     * fetch step. Sections that exceed the cap are reported in
     * `truncatedSections` with an explicit hint to narrow via `section`.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _propagation(array $arguments): array
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        [$gaps, $truncatedSections, $globalCapReached] = $this->_collectPropagationGaps($arguments);

        $totalCount = count($gaps);
        $page = array_slice($gaps, $offset, $limit);

        $payload = [
            'mode' => 'propagation',
            'gaps' => $page,
            'count' => count($page),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'globalCapReached' => $globalCapReached,
        ];

        if ($truncatedSections !== []) {
            $payload['truncated'] = true;
            $payload['truncatedSections'] = $truncatedSections;
            $payload['truncationCap'] = self::PROPAGATION_SECTION_ROW_CAP;
            $payload['hint'] = 'One or more sections exceeded the per-section row cap. ' .
                '`totalCount` counts gaps found within the scanned subset only — ' .
                'sections that hit the cap may have additional un-scanned gaps. ' .
                'Narrow with the `section` filter for an exhaustive scan of a single section.';
        }

        if ($globalCapReached) {
            $payload['globalCapHit'] = self::PROPAGATION_GLOBAL_GAP_CAP;
            $payload['hint'] = ($payload['hint'] ?? '') !== ''
                ? $payload['hint'] . ' '
                : '';
            $payload['hint'] .= 'The global gap cap of '
                . self::PROPAGATION_GLOBAL_GAP_CAP
                . ' was reached — additional sections were not scanned. '
                . 'Narrow with the `section` filter to inspect specific sections.';
        }

        return $payload;
    }

    /**
     * Shared gap-collection helper for the propagation surfaces. The
     * read mode (`propagation`) and the fix mode (`repair_propagation`)
     * both iterate the same gap set — extracting the loop here keeps
     * them in lockstep.
     *
     * Returns `[gaps, truncatedSections, globalCapReached]`.
     *
     * @param array<string,mixed> $arguments
     * @return array{0: array<int,array<string,mixed>>, 1: array<int,string>, 2: bool}
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _collectPropagationGaps(array $arguments): array
    {
        $sections = Craft::$app->getEntries()->getAllSections();

        $sectionFilter = isset($arguments['section']) && is_string($arguments['section']) && $arguments['section'] !== ''
            ? $arguments['section']
            : null;

        $multiSiteSections = array_filter(
            $sections,
            static function(Section $s) use ($sectionFilter): bool {
                if ($sectionFilter !== null && $s->handle !== $sectionFilter) {
                    return false;
                }
                return count($s->getSiteSettings()) > 1;
            },
        );

        $gaps = [];
        $truncatedSections = [];
        $globalCapReached = false;
        foreach ($multiSiteSections as $section) {
            $expectedSiteIds = array_values(array_map(
                static fn($s): int => (int) $s->siteId,
                $section->getSiteSettings(),
            ));

            // Fetch one extra row so we can detect cap overflow without
            // a second COUNT() round-trip. If the result hits the +1
            // boundary we trim and flag the section as truncated.
            $rows = (new Query())
                ->select([
                    'canonicalId' => 'entries.id',
                    'siteId' => 'elements_sites.siteId',
                ])
                ->from(['entries' => Table::ENTRIES])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[entries.id]]')
                ->innerJoin(
                    ['elements_sites' => Table::ELEMENTS_SITES],
                    '[[elements_sites.elementId]] = [[entries.id]]',
                )
                ->where([
                    'entries.sectionId' => $section->id,
                    'elements.dateDeleted' => null,
                    'elements.draftId' => null,
                    'elements.revisionId' => null,
                ])
                ->limit(self::PROPAGATION_SECTION_ROW_CAP + 1)
                ->all();

            if (count($rows) > self::PROPAGATION_SECTION_ROW_CAP) {
                array_pop($rows);
                $truncatedSections[] = (string) $section->handle;
            }

            $byCanonical = [];
            foreach ($rows as $row) {
                $cid = (int) $row['canonicalId'];
                $byCanonical[$cid][] = (int) $row['siteId'];
            }

            foreach ($byCanonical as $cid => $siteIds) {
                $missing = array_values(array_diff($expectedSiteIds, $siteIds));
                if ($missing === []) {
                    continue;
                }
                $gaps[] = [
                    'section' => (string) $section->handle,
                    'sectionUid' => (string) $section->uid,
                    'canonicalId' => $cid,
                    'expectedSites' => $expectedSiteIds,
                    'presentSites' => array_values(array_unique($siteIds)),
                    'missingSites' => $missing,
                ];
            }

            if (count($gaps) >= self::PROPAGATION_GLOBAL_GAP_CAP) {
                $globalCapReached = true;
                break;
            }
        }

        return [$gaps, $truncatedSections, $globalCapReached];
    }

    // Private Methods — Pro fix modes
    // =========================================================================

    /**
     * Delete the broken relation rows that the `relations` read mode
     * lists. Loops the shared `_brokenRelationsQuery()` set and dispatches
     * each row to `_fixOneRelation()` for per-row permission + delete.
     *
     * Generator surface: yields one `{progress, total, message}` frame
     * every `progressInterval` rows; returns the terminal envelope. The
     * per-row mutation in `_fixOneRelation()` is unchanged from 8.8b —
     * only the outer loop gained yields + cancellation polling.
     *
     * Cancellation polled at the top of each iteration. On a flipped
     * token the loop breaks before the next row is visited; in-flight
     * rows always complete.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _fixRelations(array $arguments, InvocationContext $ctx): Generator
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);
        $progressInterval = $this->_progressInterval($arguments);
        $token = $ctx->getCancellationToken();

        $base = $this->_brokenRelationsQuery();
        $totalCount = (int) (clone $base)->count();

        $rows = (clone $base)
            ->select([
                'sourceId' => 'relations.sourceId',
                'targetId' => 'relations.targetId',
                'fieldId' => 'relations.fieldId',
                'sortOrder' => 'relations.sortOrder',
                'sourceSiteId' => 'relations.sourceSiteId',
            ])
            ->limit($limit)
            ->offset($offset)
            ->all();

        $results = [];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $cancelled = false;

        foreach ($rows as $row) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            $outcome = $this->_fixOneRelation($row);
            $results[] = $outcome;
            $processed++;
            $kind = $outcome['kind'] ?? 'failure';
            if ($kind === 'success') {
                $succeeded++;
            } elseif ($kind === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }

            if ($processed % $progressInterval === 0) {
                $sourceId = (int) ($row['sourceId'] ?? 0);
                $targetId = (int) ($row['targetId'] ?? 0);
                yield [
                    'progress' => $processed,
                    'total' => $totalCount,
                    'message' => "Fixing broken relation src={$sourceId} → target={$targetId}",
                ];
            }
        }

        return [
            'success' => $failed === 0 && !$cancelled,
            'mode' => 'fix_relations',
            'total' => $totalCount,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'cancelled' => $cancelled,
            'results' => $results,
        ];
    }

    /**
     * Per-row helper for `fix_relations`. Loads the source element to
     * resolve its owning section/group/volume, runs the permission
     * gate, then deletes the relation row.
     *
     * Independent method so Gate 8.9 can wrap `_fixRelations()` in a
     * `Generator` without touching the per-row mutation.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _fixOneRelation(array $row): array
    {
        $sourceId = (int) $row['sourceId'];
        $targetId = (int) $row['targetId'];
        $fieldId = (int) $row['fieldId'];
        $sortOrder = isset($row['sortOrder']) ? (int) $row['sortOrder'] : null;

        $source = $this->_loadAnyElement($sourceId);
        if ($source === null) {
            return [
                'kind' => 'skipped',
                'sourceId' => $sourceId,
                'targetId' => $targetId,
                'fieldId' => $fieldId,
                'reason' => 'missing',
            ];
        }

        $permArgs = $this->_relationPermissionArgs($source);
        if ($permArgs === null) {
            return [
                'kind' => 'skipped',
                'sourceId' => $sourceId,
                'targetId' => $targetId,
                'fieldId' => $fieldId,
                'reason' => 'unsupported_source',
            ];
        }

        try {
            $this->_assertPermission($permArgs);
        } catch (ToolException $e) {
            $required = $this->_requiredPermissions($permArgs);
            return [
                'kind' => 'skipped',
                'sourceId' => $sourceId,
                'targetId' => $targetId,
                'fieldId' => $fieldId,
                'reason' => 'permission_denied',
                'requiredPermission' => $required[0] ?? null,
                'message' => $e->getMessage(),
            ];
        }

        $condition = [
            'sourceId' => $sourceId,
            'targetId' => $targetId,
            'fieldId' => $fieldId,
        ];
        if ($sortOrder !== null) {
            $condition['sortOrder'] = $sortOrder;
        }

        try {
            $deleted = (int) Db::delete(Table::RELATIONS, $condition);
        } catch (Throwable $e) {
            return [
                'kind' => 'failure',
                'sourceId' => $sourceId,
                'targetId' => $targetId,
                'fieldId' => $fieldId,
                'reason' => $e->getMessage(),
            ];
        }

        return [
            'kind' => 'success',
            'sourceId' => $sourceId,
            'targetId' => $targetId,
            'fieldId' => $fieldId,
            'deletedRelationCount' => $deleted,
        ];
    }

    /**
     * Hard-delete the unreferenced assets that the `unused_assets` read
     * mode lists. Loops the shared `_unusedAssetsQuery()` set and
     * dispatches each asset to `_pruneOneAsset()`.
     *
     * Generator surface: yields one `{progress, total, message}` frame
     * every `progressInterval` rows; returns the terminal envelope. The
     * per-row mutation in `_pruneOneAsset()` is unchanged from 8.8b.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _pruneUnusedAssets(array $arguments, InvocationContext $ctx): Generator
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);
        $progressInterval = $this->_progressInterval($arguments);
        $token = $ctx->getCancellationToken();

        $query = $this->_unusedAssetsQuery($arguments);
        $totalCount = (int) (clone $query)->count();

        $query->limit($limit)->offset($offset);

        /** @var Asset[] $assets */
        $assets = $query->all();

        $results = [];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $totalSizeFreed = 0;
        $cancelled = false;

        foreach ($assets as $asset) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            $outcome = $this->_pruneOneAsset($asset);
            $results[] = $outcome;
            $processed++;
            $kind = $outcome['kind'] ?? 'failure';
            if ($kind === 'success') {
                $succeeded++;
                $totalSizeFreed += (int) ($outcome['sizeFreed'] ?? 0);
            } elseif ($kind === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }

            if ($processed % $progressInterval === 0) {
                $assetId = (int) ($asset->id ?? 0);
                yield [
                    'progress' => $processed,
                    'total' => $totalCount,
                    'message' => "Pruning asset {$assetId}",
                ];
            }
        }

        return [
            'success' => $failed === 0 && !$cancelled,
            'mode' => 'prune_unused_assets',
            'total' => $totalCount,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'totalSizeFreed' => $totalSizeFreed,
            'cancelled' => $cancelled,
            'results' => $results,
        ];
    }

    /**
     * Per-row helper for `prune_unused_assets`. Runs the
     * `deleteAssets:{volumeUid}` gate, captures `$asset->size` for the
     * `sizeFreed` aggregate, then hard-deletes the asset.
     *
     * Independent method so Gate 8.9 can wrap the outer loop without
     * touching the per-row mutation.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _pruneOneAsset(Asset $asset): array
    {
        $id = (int) $asset->id;
        $uid = $asset->uid;
        $volume = $asset->getVolume();
        $volumeUid = $volume->uid;
        $size = (int) ($asset->size ?? 0);

        $permArgs = ['mode' => 'prune_unused_assets', 'volumeUid' => $volumeUid];

        try {
            $this->_assertPermission($permArgs);
        } catch (ToolException $e) {
            $required = $this->_requiredPermissions($permArgs);
            return [
                'kind' => 'skipped',
                'id' => $id,
                'uid' => $uid,
                'volumeUid' => $volumeUid,
                'reason' => 'permission_denied',
                'requiredPermission' => $required[0] ?? null,
                'message' => $e->getMessage(),
            ];
        }

        try {
            $deleted = Craft::$app->getElements()->deleteElement($asset, true);
        } catch (Throwable $e) {
            return [
                'kind' => 'failure',
                'id' => $id,
                'uid' => $uid,
                'reason' => $e->getMessage(),
            ];
        }

        if (!$deleted) {
            return [
                'kind' => 'failure',
                'id' => $id,
                'uid' => $uid,
                'reason' => 'delete_failed',
            ];
        }

        return [
            'kind' => 'success',
            'id' => $id,
            'uid' => $uid,
            'volumeUid' => $volumeUid,
            'sizeFreed' => $size,
        ];
    }

    /**
     * Force-resave entries with missing per-site copies, propagating to
     * every site the section is enabled for. Loops the shared
     * `_collectPropagationGaps()` set and dispatches each canonical to
     * `_repairOnePropagation()`.
     *
     * Generator surface: yields one `{progress, total, message}` frame
     * every `progressInterval` gaps; returns the terminal envelope. The
     * per-gap mutation in `_repairOnePropagation()` is unchanged from 8.8b.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _repairPropagation(array $arguments, InvocationContext $ctx): Generator
    {
        $limit = $this->_limit($arguments, self::PROPAGATION_GLOBAL_GAP_CAP, self::PROPAGATION_GLOBAL_GAP_CAP);
        $offset = $this->_offset($arguments);
        $progressInterval = $this->_progressInterval($arguments);
        $token = $ctx->getCancellationToken();

        [$gaps, $truncatedSections, $globalCapReached] = $this->_collectPropagationGaps($arguments);
        $totalCount = count($gaps);
        $page = array_slice($gaps, $offset, $limit);

        $results = [];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $cancelled = false;

        foreach ($page as $gap) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            $outcome = $this->_repairOnePropagation($gap);
            $results[] = $outcome;
            $processed++;
            $kind = $outcome['kind'] ?? 'failure';
            if ($kind === 'success') {
                $succeeded++;
            } elseif ($kind === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }

            if ($processed % $progressInterval === 0) {
                $canonicalId = (int) ($gap['canonicalId'] ?? 0);
                $sectionUid = is_string($gap['sectionUid'] ?? null) ? $gap['sectionUid'] : '?';
                yield [
                    'progress' => $processed,
                    'total' => $totalCount,
                    'message' => "Repairing canonical {$canonicalId} ({$sectionUid})",
                ];
            }
        }

        $envelope = [
            'success' => $failed === 0 && !$cancelled,
            'mode' => 'repair_propagation',
            'total' => $totalCount,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'globalCapReached' => $globalCapReached,
            'cancelled' => $cancelled,
            'results' => $results,
        ];

        if ($truncatedSections !== []) {
            $envelope['truncatedSections'] = $truncatedSections;
            $envelope['truncationCap'] = self::PROPAGATION_SECTION_ROW_CAP;
        }

        return $envelope;
    }

    /**
     * Per-row helper for `repair_propagation`. Loads the canonical entry,
     * runs the `saveEntries:{sectionUid}` gate, then forces a propagating
     * save that fills in the missing per-site rows.
     *
     * The canonical's `propagationMethod` ultimately decides which sites
     * the propagating save touches — we ask Craft to propagate and trust
     * Craft's resolver to do the right thing per section settings.
     *
     * @param array<string,mixed> $gap
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _repairOnePropagation(array $gap): array
    {
        $canonicalId = (int) $gap['canonicalId'];
        $sectionUid = is_string($gap['sectionUid'] ?? null) ? $gap['sectionUid'] : '?';
        $missingSiteIds = is_array($gap['missingSites'] ?? null) ? array_map('intval', $gap['missingSites']) : [];

        $permArgs = ['mode' => 'repair_propagation', 'sectionUid' => $sectionUid];

        try {
            $this->_assertPermission($permArgs);
        } catch (ToolException $e) {
            $required = $this->_requiredPermissions($permArgs);
            return [
                'kind' => 'skipped',
                'canonicalId' => $canonicalId,
                'sectionUid' => $sectionUid,
                'reason' => 'permission_denied',
                'requiredPermission' => $required[0] ?? null,
                'message' => $e->getMessage(),
            ];
        }

        // Load the canonical from the primary site — propagation flows
        // outward from whatever site the entry exists in. `status(null)`
        // so disabled canonicals still drive the repair.
        $canonical = Entry::find()
            ->id($canonicalId)
            ->status(null)
            ->site('*')
            ->one();
        if (!$canonical instanceof Entry) {
            return [
                'kind' => 'skipped',
                'canonicalId' => $canonicalId,
                'sectionUid' => $sectionUid,
                'reason' => 'missing',
            ];
        }

        $section = $canonical->getSection();
        if ($section === null || $section->propagationMethod === PropagationMethod::None) {
            return [
                'kind' => 'skipped',
                'canonicalId' => $canonicalId,
                'sectionUid' => $sectionUid,
                'reason' => 'propagation_disabled',
            ];
        }

        // Resolve site uids for the per-row report — operator-facing
        // identity is uid-based, not id-based.
        $sitesService = Craft::$app->getSites();
        $sitesPropagated = [];
        foreach ($missingSiteIds as $sid) {
            $site = $sitesService->getSiteById($sid);
            if ($site !== null) {
                $sitesPropagated[] = $site->uid;
            }
        }

        try {
            $saved = Craft::$app->getElements()->saveElement($canonical, runValidation: true, propagate: true);
        } catch (Throwable $e) {
            return [
                'kind' => 'failure',
                'canonicalId' => $canonicalId,
                'sectionUid' => $sectionUid,
                'reason' => $e->getMessage(),
            ];
        }

        if (!$saved) {
            return [
                'kind' => 'failure',
                'canonicalId' => $canonicalId,
                'sectionUid' => $sectionUid,
                'reason' => 'validation_failed',
                'validationErrors' => $canonical->getErrors(),
            ];
        }

        return [
            'kind' => 'success',
            'canonicalId' => $canonicalId,
            'sectionUid' => $sectionUid,
            'sitesPropagated' => $sitesPropagated,
        ];
    }

    // Private Methods — fix-mode helpers
    // =========================================================================

    /**
     * Read the `progressInterval` argument with `DEFAULT_PROGRESS_INTERVAL`
     * fallback and a `>= 1` lower clamp. Same contract as
     * `BulkEntries::_progressInterval()` — keep both tools' streaming
     * surfaces in lockstep.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _progressInterval(array $arguments): int
    {
        $value = $arguments['progressInterval'] ?? self::DEFAULT_PROGRESS_INTERVAL;
        return max(1, (int) $value);
    }

    /**
     * Load any element (entry / category / asset / user / address / …)
     * by id without status / draft / site narrowing. Returns null when
     * the element can't be resolved (already deleted, mismatched type
     * registry, etc).
     *
     * Used by `_fixOneRelation()` to determine the source element's
     * owning section / group / volume for the permission gate.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _loadAnyElement(int $id): ?ElementInterface
    {
        try {
            $element = Craft::$app->getElements()->getElementById($id, null, '*'); // @phpstan-ignore-line — null elementType is the documented "discover the type" path
        } catch (Throwable) {
            return null;
        }
        return $element;
    }

    /**
     * Resolve the permission-arg shape for a relation row given its
     * source element. Returns `null` for source element types that
     * don't carry a section/group/volume — the per-row outcome surface
     * skips those with `reason: unsupported_source`.
     *
     * @return array<string,string>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _relationPermissionArgs(ElementInterface $source): ?array
    {
        if ($source instanceof Entry) {
            $section = $source->getSection();
            if ($section === null || $section->uid === null) {
                return null;
            }
            return ['mode' => 'fix_relations', 'sectionUid' => $section->uid];
        }

        if ($source instanceof Category) {
            $group = $source->getGroup();
            if ($group->uid === null) {
                return null;
            }
            return ['mode' => 'fix_relations', 'groupUid' => $group->uid];
        }

        if ($source instanceof Asset) {
            $volume = $source->getVolume();
            if ($volume->uid === null) {
                return null;
            }
            return ['mode' => 'fix_relations', 'volumeUid' => $volume->uid];
        }

        return null;
    }
}
