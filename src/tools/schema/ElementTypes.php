<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\base\ElementInterface;
use craftpulse\cortex\tools\AbstractTool;

/**
 * =========================================================================
 * `element_types` tool — list every registered Craft element type class.
 *
 * Returns the 8 core element types (Entry, Asset, Category, Tag, User,
 * Address, GlobalSet, ContentBlock) plus any registered by plugins.
 * For each: class, displayName, refHandle, and the full capability flag
 * surface (hasTitles, hasUris, hasStatuses, hasDrafts, isLocalized,
 * trackChanges).
 *
 * No list/get split — discovering "what element types exist and what
 * they support" is one read.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class ElementTypes extends AbstractTool
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
        return 'element_types';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List every registered Craft element type — 8 core (Entry, Asset, Category, ' .
            'Tag, User, Address, GlobalSet, ContentBlock) plus plugin-provided. For each: ' .
            'class, displayName variants, refHandle, and capability flags (hasTitles, ' .
            'hasUris, hasStatuses, hasDrafts, isLocalized, trackChanges).';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $types = Craft::$app->getElements()->getAllElementTypes();

        return [
            'elementTypes' => array_map(
                static function (string $class): array {
                    /** @var class-string<ElementInterface> $class */
                    // hasContent() was removed in Craft 5 — content is part of
                    // the elements_sites content column on every element now,
                    // so the capability flag is implicit.
                    return [
                        'class' => $class,
                        'displayName' => $class::displayName(),
                        'lowerDisplayName' => $class::lowerDisplayName(),
                        'pluralDisplayName' => $class::pluralDisplayName(),
                        'pluralLowerDisplayName' => $class::pluralLowerDisplayName(),
                        'refHandle' => $class::refHandle(),
                        'hasTitles' => $class::hasTitles(),
                        'hasUris' => $class::hasUris(),
                        'hasStatuses' => $class::hasStatuses(),
                        'hasDrafts' => $class::hasDrafts(),
                        'isLocalized' => $class::isLocalized(),
                        'trackChanges' => $class::trackChanges(),
                    ];
                },
                $types,
            ),
            'count' => count($types),
        ];
    }
}
