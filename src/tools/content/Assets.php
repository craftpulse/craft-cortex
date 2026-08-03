<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\models\VolumeFolder;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `assets` tool — list / get / count assets, plus folder-tree mode.
 *
 * Modes:
 *   - default: list assets with the standard query surface.
 *   - `id`: single asset.
 *   - `count: true`: count.
 *   - `mode: "folders"` + `volume`: returns the folder hierarchy for
 *     that volume (id, name, path, parent, children). Useful for the AI
 *     to plan asset placement.
 *
 * Filters:
 *   - `volume` (handle/id), `folderId`, `kind` (image/video/pdf/json/…)
 *   - `id`, `uid`, `filename`, `title`
 *   - `relatedTo`, `search`, `with`
 *   - `orderBy`, `limit`, `offset`
 *   - `site`, `siteId`
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Assets extends AbstractTool
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
        'filename' => 'assets.filename',
        'kind' => 'assets.kind',
        'size' => 'assets.size',
        'width' => 'assets.width',
        'height' => 'assets.height',
        'volumeId' => 'assets.volumeId',
        'folderId' => 'assets.folderId',
        'dateModified' => 'assets.dateModified',
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
        return 'assets';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List, get, or count Craft assets, or with `mode: "folders"` get the folder ' .
            'tree for a volume. Filter by volume, folder, kind, filename, search, ' .
            'relatedTo. Eager-load relational fields with `with: [...]`. Returns assets ' .
            'with filename, kind, size, dimensions, url, alt, focalPoint, and custom fields.';
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
            'mode' => Schema::string()->enum(['default', 'folders']),
            'volume' => Schema::any()->description('Volume handle, id, or array.'),
            'folderId' => Schema::any()->description('Folder id or array of ids.'),
            'kind' => Schema::any()->description('Asset kind: image, video, audio, json, pdf, text, …'),
            'id' => Schema::any()->description('Single id or array of ids.'),
            'uid' => Schema::any()->description('Single uid or array.'),
            'filename' => Schema::string(),
            'title' => Schema::string(),
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
        if ($this->_mode($arguments) === 'folders') {
            return $this->_runFoldersMode($arguments);
        }

        $serializer = new ElementSerializer();
        $eagerHandles = $this->_eagerHandles($arguments);

        $query = $this->_buildQuery($arguments, $eagerHandles);

        if ($this->_isCount($arguments)) {
            return ['count' => (int) $query->count()];
        }

        $id = $arguments['id'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
            $asset = $query->one();
            if (!$asset instanceof Asset) {
                throw new ToolException("No asset found with id={$id}.");
            }

            return ['asset' => $this->_serializeAsset($asset, $eagerHandles, $serializer)];
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Asset[] $assets */
        $assets = $query->all();

        return [
            'assets' => array_map(
                fn(Asset $a): array => $this->_serializeAsset($a, $eagerHandles, $serializer),
                $assets,
            ),
            'count' => count($assets),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _runFoldersMode(array $arguments): array
    {
        $volumeArg = $arguments['volume'] ?? null;
        if ($volumeArg === null) {
            throw new ToolException('mode "folders" requires a `volume` argument (handle or id).');
        }

        $volumesService = Craft::$app->getVolumes();
        $volume = is_int($volumeArg) || (is_string($volumeArg) && ctype_digit((string) $volumeArg))
            ? $volumesService->getVolumeById((int) $volumeArg)
            : $volumesService->getVolumeByHandle((string) $volumeArg);

        if ($volume === null) {
            throw new ToolException("No volume found matching '{$volumeArg}'.");
        }

        $assetsService = Craft::$app->getAssets();
        $folders = $assetsService->findFolders(['volumeId' => $volume->id]);

        // findFolders() returns a result keyed by folder id; reindex so the
        // wire output is a JSON array, not an object.
        $folders = array_values($folders);

        return [
            'volumeHandle' => $volume->handle,
            'folders' => array_map(
                static fn(VolumeFolder $f): array => [
                    'id' => $f->id,
                    'uid' => $f->uid,
                    'name' => $f->name,
                    'path' => $f->path,
                    'parentId' => $f->parentId,
                ],
                $folders,
            ),
            'count' => count($folders),
        ];
    }

    /**
     * @param string[] $eagerHandles
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeAsset(Asset $asset, array $eagerHandles, ElementSerializer $serializer): array
    {
        $base = $serializer->serializeElement($asset, $eagerHandles);

        return [
            ...$base,
            'filename' => $asset->filename,
            'extension' => $asset->getExtension(),
            'kind' => $asset->kind,
            'mimeType' => $asset->getMimeType(),
            'size' => $asset->size,
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'alt' => $asset->alt,
            'focalPoint' => $asset->getFocalPoint(),
            'volumeHandle' => $asset->getVolume()->handle,
            'folderId' => $asset->folderId,
            'folderPath' => $asset->folderPath,
        ];
    }

    /**
     * @param string[] $eagerHandles
     * @throws ToolException from `_orderBy()` when the sort expression is not allowlisted.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildQuery(array $arguments, array $eagerHandles): AssetQuery
    {
        $query = Asset::find();

        // `orderBy` is deliberately absent from this pass-through list —
        // it lands in the SQL ORDER BY clause unquoted and goes through
        // the `SORTABLE_FIELDS` allowlist below instead.
        foreach ([
            'id', 'uid', 'filename', 'title',
            'volume', 'folderId', 'kind',
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
