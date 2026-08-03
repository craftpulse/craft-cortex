<?php

namespace craftpulse\herald\tools\schema;

use Craft;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `entry_types` tool — list / get / count Craft 5 entry types.
 *
 * Entry types are decoupled from sections in Craft 5 (one entry type can
 * be reused across sections, plus on Matrix/CKEditor fields). This tool
 * always returns standalone entry types — not "section X's entry types"
 * which is what you'd expose in a section detail tool.
 *
 * Modes:
 *   - default: list all entry types with field-layout summary.
 *   - `handle`: single entry type with FULL field layout (tabs, fields
 *     per tab, field conditions, UI elements: tip / warning / hr / heading
 *     / template).
 *   - `count: true`: count.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class EntryTypes extends AbstractTool
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
        return 'entry_types';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'List all Craft 5 entry types, get a single one by handle, or count them. ' .
            'In Craft 5 entry types are decoupled from sections — they can be reused across ' .
            'sections, Matrix fields, and CKEditor nested entries. Single-handle mode returns ' .
            'the full field layout (tabs, fields per tab, conditions, and UI elements).';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'handle' => Schema::string()->description('Entry type handle. Omit to list all.'),
            'count' => Schema::boolean()->description('Return only the count.'),
        ])->toArray();
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
        $entries = Craft::$app->getEntries();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $type = $entries->getEntryTypeByHandle($handle);
            if ($type === null) {
                throw new ToolException("No entry type found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['entryType' => $this->_serializeEntryType($type, full: true)];
        }

        $types = $entries->getAllEntryTypes();

        if ($count) {
            return ['count' => count($types)];
        }

        return [
            'entryTypes' => array_map(
                fn(EntryType $t): array => $this->_serializeEntryType($t, full: false),
                $types,
            ),
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
    private function _serializeEntryType(EntryType $type, bool $full): array
    {
        $layout = $type->getFieldLayout();

        return [
            'id' => $type->id,
            'uid' => $type->uid,
            'name' => $type->name,
            'handle' => $type->handle,
            'icon' => $type->icon,
            'color' => $type->color?->value,
            'hasTitleField' => $type->hasTitleField,
            'titleFormat' => $type->titleFormat,
            'titleTranslationMethod' => $type->titleTranslationMethod,
            'slugTranslationMethod' => $type->slugTranslationMethod,
            'showStatusField' => $type->showStatusField,
            'showSlugField' => $type->showSlugField,
            'fieldLayout' => $full
                ? $this->_serializeFieldLayoutFull($layout)
                : $this->_serializeFieldLayoutSummary($layout),
        ];
    }

    /**
     * @return array<string,mixed>|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeFieldLayoutSummary(?FieldLayout $layout): ?array
    {
        if ($layout === null) {
            return null;
        }

        return [
            'id' => $layout->id,
            'uid' => $layout->uid,
            'tabCount' => count($layout->getTabs()),
            'customFieldCount' => count($layout->getCustomFields()),
        ];
    }

    /**
     * @return array<string,mixed>|null
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeFieldLayoutFull(?FieldLayout $layout): ?array
    {
        if ($layout === null) {
            return null;
        }

        return [
            'id' => $layout->id,
            'uid' => $layout->uid,
            'tabs' => array_map(
                fn(FieldLayoutTab $tab): array => $this->_serializeTab($tab),
                $layout->getTabs(),
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _serializeTab(FieldLayoutTab $tab): array
    {
        return [
            'name' => $tab->name,
            'uid' => $tab->uid,
            'elements' => array_map(
                static function($element): array {
                    $base = [
                        'type' => $element::class,
                        'uid' => $element->uid,
                    ];

                    // CustomField elements wrap an actual field; include its handle
                    // so the AI can correlate with the `fields` tool.
                    if (method_exists($element, 'getField')) {
                        $field = $element->getField();
                        if ($field !== null) {
                            $base['fieldHandle'] = $field->handle;
                            $base['fieldType'] = $field::class;
                        }
                    }

                    if (property_exists($element, 'tip') && !empty($element->tip)) {
                        $base['tip'] = $element->tip;
                    }
                    if (property_exists($element, 'warning') && !empty($element->warning)) {
                        $base['warning'] = $element->warning;
                    }

                    return $base;
                },
                $tab->getElements(),
            ),
        ];
    }
}
