<?php

namespace craftpulse\cortex\tools\workflow;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\Section;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `audit` tool — read-only content health reports.
 *
 * Three Phase-1 modes, each producing a report the LLM can act on:
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
 * Pro will add fix modes (PLANNING.md 4.7): delete broken relations,
 * delete unused assets, force-propagate missing entries. All three
 * mutate state, so they're gated behind the relevant Craft permissions.
 *
 * Each mode returns a paginated envelope (`count`, `totalCount`,
 * `limit`, `offset`) so the LLM can drill in without overwhelming the
 * context window. Default limit 200, hard cap 1000.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Audit extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    /**
     * Per-section row cap for the propagation mode's element-fetch step.
     * Sections exceeding this row count get reported as truncated with
     * an explicit hint to narrow with the `section` filter. Bounded to
     * keep the worst-case memory cost predictable.
     */
    public const PROPAGATION_SECTION_ROW_CAP = 50000;

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
        return 'content_audit';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Read-only content health reports. Modes: ' .
            '`relations` lists broken relational references (target element missing or soft-deleted); ' .
            '`unused_assets` lists assets not referenced by any element field; ' .
            '`propagation` lists entries in multi-site sections that don\'t exist in every enabled site. ' .
            'Fix modes (delete broken relations, prune unused assets, force-propagate) unlock in Pro.';
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
                ->enum(['relations', 'unused_assets', 'propagation'])
                ->required()
                ->description('Required.'),
            'volume' => Schema::string()->description('Volume handle filter for `unused_assets`.'),
            'section' => Schema::string()->description('Section handle filter for `propagation`.'),
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
            throw new ToolException('`mode` is required (relations / unused_assets / propagation).');
        }

        return match ($mode) {
            'relations' => $this->_relations($arguments),
            'unused_assets' => $this->_unusedAssets($arguments),
            'propagation' => $this->_propagation($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
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
     * @since  0.1.0
     */
    private function _relations(array $arguments): array
    {
        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $base = (new Query())
            ->from(['relations' => Table::RELATIONS])
            ->leftJoin(['target' => Table::ELEMENTS], '[[target.id]] = [[relations.targetId]]')
            ->where(['or', ['target.id' => null], ['not', ['target.dateDeleted' => null]]]);

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
     * Find assets not referenced by any element-field relation. Subquery
     * filter — Yii's query builder parameterises the inner `select` so
     * this is safe on any volume size.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _unusedAssets(array $arguments): array
    {
        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $referencedIds = (new Query())
            ->select('targetId')
            ->from(Table::RELATIONS)
            ->distinct();

        $query = Asset::find()
            ->status(null)
            ->site('*')
            ->andWhere(['not', ['elements.id' => $referencedIds]]);

        if (isset($arguments['volume']) && is_string($arguments['volume']) && $arguments['volume'] !== '') {
            $query->volume($arguments['volume']);
        }

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
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
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
            'dateCreated' => $asset->dateCreated?->format(\DateTimeInterface::ATOM),
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
     * @since  0.1.0
     */
    private function _propagation(array $arguments): array
    {
        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

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
                $truncatedSections[] = $section->handle;
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
                    'section' => $section->handle,
                    'canonicalId' => $cid,
                    'expectedSites' => $expectedSiteIds,
                    'presentSites' => array_values(array_unique($siteIds)),
                    'missingSites' => $missing,
                ];
            }
        }

        $totalCount = count($gaps);
        $page = array_slice($gaps, $offset, $limit);

        $payload = [
            'mode' => 'propagation',
            'gaps' => $page,
            'count' => count($page),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($truncatedSections !== []) {
            $payload['truncated'] = true;
            $payload['truncatedSections'] = $truncatedSections;
            $payload['truncationCap'] = self::PROPAGATION_SECTION_ROW_CAP;
            $payload['hint'] = 'One or more sections exceeded the per-section row cap. ' .
                'Narrow the audit with the `section` filter to scan a single section without truncation.';
        }

        return $payload;
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
