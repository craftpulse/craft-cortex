<?php

namespace craftpulse\herald\tools\schema;

use Craft;
use craft\models\ImageTransform;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `image_transforms` tool — list / get / count named image transforms.
 *
 * Modes:
 *   - default: list all named transforms.
 *   - `handle`: single transform.
 *   - `count: true`: count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class ImageTransforms extends AbstractTool
{
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
        return 'image_transforms';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List named image transforms, get a single transform by handle, or count them. ' .
            'Returns each transform with its dimensions, mode, position, format, quality, ' .
            'interlace, fill, and upscale settings.';
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
            'handle' => Schema::string(),
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
        $service = Craft::$app->getImageTransforms();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $transform = $service->getTransformByHandle($handle);
            if ($transform === null) {
                throw new ToolException("No image transform found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['imageTransform' => $this->_serializeTransform($transform)];
        }

        $transforms = $service->getAllTransforms();

        if ($count) {
            return ['count' => count($transforms)];
        }

        return [
            'imageTransforms' => array_map(
                fn(ImageTransform $t): array => $this->_serializeTransform($t),
                $transforms,
            ),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeTransform(ImageTransform $transform): array
    {
        return [
            'id' => $transform->id,
            'uid' => $transform->uid,
            'name' => $transform->name,
            'handle' => $transform->handle,
            'mode' => $transform->mode,
            'position' => $transform->position,
            'width' => $transform->width,
            'height' => $transform->height,
            'format' => $transform->format,
            'quality' => $transform->quality,
            'interlace' => $transform->interlace,
            'fill' => $transform->fill,
            'upscale' => $transform->upscale,
        ];
    }
}
