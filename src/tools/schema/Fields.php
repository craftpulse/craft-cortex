<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\base\FieldInterface;
use craft\base\RelationalFieldInterface;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `fields` tool — list / get / count Craft custom fields, plus usage map.
 *
 * Modes:
 *   - default: list all custom fields with type, handle, instructions,
 *     translation method.
 *   - `handle`: single field with all settings.
 *   - `mode: "usage"` + `handle`: which entry types and field layouts
 *     reference this field. Helps the AI decide whether a field rename
 *     would break content modelling.
 *   - `count: true`: count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Fields extends AbstractTool
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
        return 'fields';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List all custom fields, get a single field by handle, get its usage map, or ' .
            'count fields. Returns each field with its type class, handle, instructions, ' .
            'translation method, and searchable flag. Use `mode: "usage"` with a handle to ' .
            'find which entry types and field layouts reference the field.';
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
            'handle' => Schema::string()->description('Field handle. Required for `mode: "usage"`.'),
            'mode' => Schema::string()
                ->enum(['default', 'usage'])
                ->description('"usage" returns a usage map for the given handle.'),
            'count' => Schema::boolean()->description('Return only the count.'),
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
        $fields = Craft::$app->getFields();
        $handle = $this->_handle($arguments);
        $mode = $this->_mode($arguments);
        $count = $this->_isCount($arguments);

        if ($mode === 'usage') {
            if ($handle === null) {
                throw new ToolException('mode "usage" requires a `handle`.');
            }

            $field = $fields->getFieldByHandle($handle);
            if ($field === null) {
                throw new ToolException("No field found with handle '{$handle}'.");
            }

            return ['usage' => $this->_buildUsage($field)];
        }

        if ($handle !== null) {
            $field = $fields->getFieldByHandle($handle);
            if ($field === null) {
                throw new ToolException("No field found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['field' => $this->_serializeField($field, full: true)];
        }

        $all = $fields->getAllFields();

        if ($count) {
            return ['count' => count($all)];
        }

        return [
            'fields' => array_map(
                fn (FieldInterface $f): array => $this->_serializeField($f, full: false),
                $all,
            ),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeField(FieldInterface $field, bool $full): array
    {
        $base = [
            'id' => $field->id,
            'uid' => $field->uid,
            'name' => $field->name,
            'handle' => $field->handle,
            'type' => $field::class,
            'typeDisplayName' => $field::displayName(),
            'instructions' => $field->instructions,
            'searchable' => $field->searchable,
            'translationMethod' => $field->translationMethod,
            'translationKeyFormat' => $field->translationKeyFormat,
            'isRelational' => $field instanceof RelationalFieldInterface,
        ];

        if ($full) {
            // Include the field's serialised settings — type-specific config
            // (default value, available choices, allowed entry types, etc.).
            $base['settings'] = $field->getSettings();
        }

        return $base;
    }

    /**
     * Walk the registered entry types and their field layouts, finding
     * every reference to the given field. Returns a structured map the
     * AI can correlate with `entry_types`.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildUsage(FieldInterface $field): array
    {
        $entries = Craft::$app->getEntries();
        $entryTypes = [];

        foreach ($entries->getAllEntryTypes() as $type) {
            $layout = $type->getFieldLayout();
            if ($layout === null) {
                continue;
            }

            foreach ($layout->getCustomFields() as $custom) {
                if ($custom->id === $field->id) {
                    $entryTypes[] = [
                        'entryTypeHandle' => $type->handle,
                        'entryTypeId' => $type->id,
                    ];
                    break;
                }
            }
        }

        return [
            'fieldHandle' => $field->handle,
            'fieldId' => $field->id,
            'entryTypes' => $entryTypes,
            'entryTypeCount' => count($entryTypes),
        ];
    }
}
