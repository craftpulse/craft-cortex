<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\base\FieldInterface;
use craft\base\RelationalFieldInterface;
use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * `field_types` tool — list all registered Craft field type classes.
 *
 * Returns the 32 built-in field types plus any registered by plugins.
 * For each: class, displayName, supported translation methods,
 * isMultiInstance, and whether it implements RelationalFieldInterface.
 *
 * No list/get split here — discovering "what field types exist" is a
 * single read; per-type configuration is exposed indirectly via
 * `fields` (single mode includes `settings`).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class FieldTypes extends AbstractTool
{
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
        return 'field_types';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List every registered Craft field type class — built-in plus plugin-provided. ' .
            'For each: the class FQN, display name, supported translation methods, whether ' .
            'multi-instance, and whether relational. Use this to discover what field types ' .
            'are available before creating a new field.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $types = Craft::$app->getFields()->getAllFieldTypes();

        return [
            'fieldTypes' => array_map(
                static function (string $class): array {
                    /** @var class-string<FieldInterface> $class */
                    return [
                        'class' => $class,
                        'displayName' => $class::displayName(),
                        'icon' => method_exists($class, 'icon') ? $class::icon() : null,
                        'isMultiInstance' => $class::isMultiInstance(),
                        'isRelational' => is_subclass_of($class, RelationalFieldInterface::class),
                        'supportedTranslationMethods' => $class::supportedTranslationMethods(),
                        'dbType' => $class::dbType(),
                    ];
                },
                $types,
            ),
            'count' => count($types),
        ];
    }
}
