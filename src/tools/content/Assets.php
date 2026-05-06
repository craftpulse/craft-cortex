<?php

namespace craftpulse\cortex\tools\content;

use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\models\VolumeFolder;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\ToolException;

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
 * @since  0.1.0
 */
class Assets extends AbstractTool
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
        return 'assets';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => ['default', 'folders']],
                'volume' => ['description' => 'Volume handle, id, or array.'],
                'folderId' => ['description' => 'Folder id or array of ids.'],
                'kind' => ['description' => 'Asset kind: image, video, audio, json, pdf, text, …'],
                'id' => ['description' => 'Single id or array of ids.'],
                'uid' => ['description' => 'Single uid or array.'],
                'filename' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'relatedTo' => ['description' => 'Craft relation syntax.'],
                'search' => ['type' => 'string'],
                'with' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'orderBy' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                'offset' => ['type' => 'integer', 'minimum' => 0],
                'site' => ['description' => 'Site handle, id, or "*".'],
                'count' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
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

        $limit = $this->_limit($arguments);
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        $totalQuery = clone $query;
        $totalCount = (int) $totalQuery->count();
        $query->limit($limit)->offset($offset);

        /** @var Asset[] $assets */
        $assets = $query->all();

        return [
            'assets' => array_map(
                fn (Asset $a): array => $this->_serializeAsset($a, $eagerHandles, $serializer),
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
     * @since  0.1.0
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
                static fn (VolumeFolder $f): array => [
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
     * @since  0.1.0
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
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildQuery(array $arguments, array $eagerHandles): AssetQuery
    {
        $query = Asset::find();

        foreach ([
            'id', 'uid', 'filename', 'title',
            'volume', 'folderId', 'kind',
            'relatedTo', 'search',
            'orderBy',
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
