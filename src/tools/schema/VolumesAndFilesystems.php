<?php

namespace craftpulse\herald\tools\schema;

use Craft;
use craft\base\FsInterface;
use craft\models\Volume;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;

/**
 * =========================================================================
 * `volumes_and_filesystems` tool — combined volume + filesystem + type map.
 *
 * In Craft 5 a Volume points at a Filesystem instance, and Filesystems
 * are themselves instances of registered filesystem TYPE classes (Local,
 * plus plugin-provided types like Servd, S3, GCS, Azure, …). All three
 * concerns are read together because they answer one question: "what
 * storage options exist on this install, what's actually configured, and
 * which configured filesystems aren't referenced by a volume?"
 *
 * Output sections:
 *   - `volumes` — each volume with its bound filesystem detail
 *   - `orphanFilesystems` — configured filesystems not pointed to by any
 *     volume (sometimes intentional, e.g. transform fs only)
 *   - `filesystemTypes` — registered class registry (built-in + plugin)
 *
 * No list/get split — combined views don't split cleanly. If a future
 * caller wants a single volume only, it's a search-by-handle filter we
 * can add as a parameter.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class VolumesAndFilesystems extends AbstractTool
{
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
        return 'volumes_and_filesystems';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Combined map of asset volumes, filesystem instances, and registered ' .
            'filesystem type classes. Returns each volume with its filesystem details ' .
            '(type class, hasUrls, base URL), any filesystems not referenced by a volume, ' .
            'and the full registered type registry — built-in `craft\\fs\\Local` plus ' .
            'plugin-provided types (Servd, S3, GCS, Azure, etc.). Use the type registry ' .
            'to discover what storage backends are available before configuring a new ' .
            'filesystem.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $volumesService = Craft::$app->getVolumes();
        $fsService = Craft::$app->getFs();

        $volumes = $volumesService->getAllVolumes();
        $allFs = $fsService->getAllFilesystems();
        $referencedFsHandles = [];

        $serializedVolumes = array_map(
            function(Volume $volume) use (&$referencedFsHandles): array {
                $fs = $volume->getFs();
                if ($fs !== null) {
                    $referencedFsHandles[$fs->handle] = true;
                }
                $transformFs = $volume->getTransformFs();
                if ($transformFs !== null && $transformFs !== $fs) {
                    $referencedFsHandles[$transformFs->handle] = true;
                }

                return [
                    'id' => $volume->id,
                    'uid' => $volume->uid,
                    'name' => $volume->name,
                    'handle' => $volume->handle,
                    'titleTranslationMethod' => $volume->titleTranslationMethod,
                    'altTranslationMethod' => $volume->altTranslationMethod,
                    'fs' => $this->_serializeFs($fs),
                    'transformFs' => $transformFs !== $fs ? $this->_serializeFs($transformFs) : null,
                    'transformSubpath' => $volume->transformSubpath,
                ];
            },
            $volumes,
        );

        $orphanFilesystems = array_values(array_filter(
            $allFs,
            static fn(FsInterface $fs): bool => !isset($referencedFsHandles[$fs->handle]),
        ));

        return [
            'volumes' => $serializedVolumes,
            'orphanFilesystems' => array_map(
                fn(FsInterface $fs): array => $this->_serializeFs($fs),
                $orphanFilesystems,
            ),
            'filesystemTypes' => $this->_serializeRegisteredTypes(),
            'volumeCount' => count($volumes),
            'filesystemCount' => count($allFs),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeFs(FsInterface $fs): array
    {
        return [
            'handle' => $fs->handle,
            'name' => $fs->name,
            'type' => $fs::class,
            'typeDisplayName' => $fs::displayName(),
            'hasUrls' => $fs->hasUrls,
            'baseUrl' => $fs->getRootUrl(),
        ];
    }

    /**
     * Project the registered FsInterface implementations into a flat
     * list. The first-party `craft\fs\Local` is flagged so the AI can
     * tell built-in storage from plugin-provided backends without
     * needing to parse class FQNs.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeRegisteredTypes(): array
    {
        $types = Craft::$app->getFs()->getAllFilesystemTypes();

        return array_values(array_map(
            static function(string $class): array {
                /** @var class-string<FsInterface> $class */
                return [
                    'class' => $class,
                    'displayName' => $class::displayName(),
                    'isFirstParty' => str_starts_with($class, 'craft\\'),
                ];
            },
            $types,
        ));
    }
}
