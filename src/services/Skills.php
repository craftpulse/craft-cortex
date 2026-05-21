<?php

namespace craftpulse\cortex\services;

use Craft;
use craft\base\MemoizableArray;
use craft\events\ConfigEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craftpulse\cortex\elements\Skill;
use Michtio\CraftCmsClaudeSkills\Skills as BundledSkills;
use yii\base\Component;

/**
 * =========================================================================
 * Cortex skills service.
 *
 * Three responsibilities:
 *
 *   1. **Field-layout management** for the `Skill` element. The layout
 *      is PC-stored at `plugins.cortex.skillFieldLayout` (single UID,
 *      single layout — mirroring `Addresses::handleChangedAddressFieldLayout`).
 *      `getFieldLayout()` is lazy-seeding: on first access it returns
 *      the persisted layout if one exists, or a fresh in-memory layout
 *      with one `Content` tab if not. The seeded body field is added
 *      the first time the field layout is saved through the designer
 *      or programmatically via `saveFieldLayout()`.
 *
 *   2. **Merged-corpus lookup** for `SearchSkills`, `SkillResource`,
 *      and `SkillPrompt`. `getMergedCorpus()` returns the union of
 *      bundled skills (from `michtio/craftcms-claude-skills` via the
 *      `BundledSkills` helper) and element-stored skills, with the
 *      element row winning on handle collision (locked decision 2).
 *      Result rows carry a `source: 'bundled' | 'element'` field so
 *      downstream consumers know the provenance.
 *
 *   3. **Cache invalidation**. The merged corpus is memoized at
 *      service-instance scope via `MemoizableArray`. `Skill::afterSave`
 *      / `afterDelete` / `afterRestore` and the PC field-layout change
 *      handler all call `resetMemo()` so the next `getMergedCorpus()`
 *      rebuilds against the new state.
 *
 * @author Craftpulse
 * @since  5.0.0
 * =========================================================================
 */
class Skills extends Component
{
    // Constants
    // =========================================================================

    /**
     * Project-config path that holds the (single) skill field layout.
     * Single-UID, single-layout shape — mirrors
     * `ProjectConfig::PATH_ADDRESS_FIELD_LAYOUTS`. Stored as
     * `{layoutUid: <FieldLayout::getConfig() output>}`.
     *
     * @since 5.0.0
     */
    public const CONFIG_FIELDLAYOUT_PATH = 'plugins.cortex.skillFieldLayout';

    /**
     * Default body-field handle on the seeded field layout. Plain-text
     * markdown body — bundled skills load raw markdown, the merged
     * corpus synthesises markdown for element rows, and HTML body
     * would break downstream prompt consumers.
     *
     * @since 5.0.0
     */
    public const DEFAULT_BODY_FIELD_HANDLE = 'body';

    // Private Properties
    // =========================================================================

    /**
     * Memoized merged corpus rows. Null until the first
     * `getMergedCorpus()` call; reset by every mutation lifecycle on
     * `Skill` and by the PC field-layout change handler.
     *
     * MemoizableArray's template parameter is the element type, not a
     * key/value pair — each element here is the row-shape dict
     * documented on `getMergedCorpus()`.
     *
     * @var MemoizableArray<array<string,mixed>>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?MemoizableArray $_merged = null;

    /**
     * Memoized element-stored skills keyed by handle. Separated from
     * `_merged` because the merge algorithm walks the bundled list
     * first and consults this map for overrides — keeping the map at
     * service-scope avoids a second `Skill::find()` per request.
     *
     * @var array<string,Skill>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private ?array $_elementByHandle = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the Cortex Skill field layout, seeding a default in-
     * memory layout when none exists yet. The seeded layout is NOT
     * automatically persisted to PC — callers either re-save through
     * `saveFieldLayout()` (which writes to PC) or accept the in-memory
     * layout for the current request.
     *
     * Mirrors `Addresses::getFieldLayout()`
     * (`vendor/craftcms/cms/src/services/Addresses.php:387-404`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getFieldLayout(): FieldLayout
    {
        $fieldLayout = Craft::$app->getFields()->getLayoutByType(Skill::class);

        // `Fields::getLayoutByType()` returns a fresh `FieldLayout`
        // instance even when no PC entry exists, so the null-safe
        // dereference below is defense-in-depth for the rare module-
        // strips-everything edge case (PHPStan can't narrow the
        // signature because Craft's PHPDoc declares ?FieldLayout).
        if ($fieldLayout === null) {
            $fieldLayout = new FieldLayout(['type' => Skill::class]);
        }

        // Ensure at least one tab. If a module strips all native
        // fields via EVENT_DEFINE_NATIVE_FIELDS, the layout could
        // arrive tab-less — seed a `Content` tab so the field layout
        // designer has somewhere to drop fields.
        $firstTab = $fieldLayout->getTabs()[0] ?? null;
        if (!$firstTab) {
            $firstTab = new FieldLayoutTab([
                'layout' => $fieldLayout,
                'name' => Craft::t('cortex', 'Content'),
            ]);
            $fieldLayout->setTabs([$firstTab]);
        }

        return $fieldLayout;
    }

    /**
     * Persist the Cortex Skill field layout to project config. Single
     * layout, single UID, single PC entry — same shape as Addresses.
     *
     * @param FieldLayout $layout
     * @param bool $runValidation Whether the layout should be validated.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function saveFieldLayout(FieldLayout $layout, bool $runValidation = true): bool
    {
        if ($runValidation && !$layout->validate()) {
            Craft::info('Skill field layout not saved due to validation error.', __METHOD__);
            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_FIELDLAYOUT_PATH,
            [$layout->uid => $layout->getConfig()],
            'Save the Cortex skill field layout',
        );

        return true;
    }

    /**
     * Handle PC `onAdd / onUpdate / onRemove` events for the skill
     * field layout path. Mirrors
     * `Addresses::handleChangedAddressFieldLayout`
     * (`vendor/craftcms/cms/src/services/Addresses.php:432-455`).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function handleChangedFieldLayout(ConfigEvent $event): void
    {
        $data = $event->newValue;
        $fieldsService = Craft::$app->getFields();

        if (empty($data) || empty($config = reset($data))) {
            $fieldsService->deleteLayoutsByType(Skill::class);
            $this->resetMemo();
            return;
        }

        // Ensure referenced custom fields are processed first.
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $layout = FieldLayout::createFromConfig($config);
        $layout->id = $this->getFieldLayout()->id;
        $layout->type = Skill::class;
        $layout->uid = key($data);
        $fieldsService->saveLayout($layout);

        Craft::$app->getElements()->invalidateCachesForElementType(Skill::class);
        $this->resetMemo();
    }

    /**
     * Returns the merged skills corpus, with bundled + element-stored
     * rows merged by handle (element-stored wins on collision per
     * locked decision 2). When `$kindFilter` is provided, only rows
     * with that kind are returned; otherwise the full set surfaces.
     *
     * Row shape (matches what `SearchSkills::_buildIndex()` consumes):
     * ```
     * {
     *   kind: 'skill' | 'reference' | 'agent',
     *   uri: string,
     *   skill: string,
     *   name: string|null,
     *   content: string,
     *   source: 'bundled' | 'element',
     * }
     * ```
     *
     * Element-stored rows synthesise their content by rebuilding the
     * frontmatter from the title + description, then appending the
     * field-layout body. The output is byte-shaped identically to a
     * bundled SKILL.md so consumers process both branches uniformly.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getMergedCorpus(?string $kindFilter = null): array
    {
        if ($this->_merged === null) {
            $this->_buildMerged();
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $this->_merged?->all() ?? [];
        if ($kindFilter === null) {
            return array_values($rows);
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['kind'] ?? null) === $kindFilter,
        ));
    }

    /**
     * Returns the element-stored `Skill` instance for the given handle,
     * or null when no element exists for that handle. Bundled-only
     * handles return null — consumers fall through to the
     * filesystem-backed `BundledSkills::content()` path.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getByHandle(string $handle): ?Skill
    {
        if ($this->_elementByHandle === null) {
            $this->_buildMerged();
        }

        return $this->_elementByHandle[$handle] ?? null;
    }

    /**
     * Returns the handles of all element-stored skills (excluding
     * trashed). Used by `Resources::_buildRegistry()` to register
     * resource entries for element-only handles that don't exist in
     * the bundled corpus.
     *
     * @return list<string>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function allHandles(): array
    {
        if ($this->_elementByHandle === null) {
            $this->_buildMerged();
        }

        return array_keys($this->_elementByHandle ?? []);
    }

    /**
     * Synthesise the markdown bytestream for an element-stored skill.
     * Rebuilds the YAML frontmatter from the element's title +
     * description then appends `# <title>` followed by the body field
     * value. The output shape matches a bundled SKILL.md byte-for-byte
     * so prompt / resource / search consumers can process both
     * branches uniformly.
     *
     * Public so `SkillResource::read()` and `SkillPrompt::render()`
     * share the exact same synthesis path.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function synthesizeContent(Skill $skill): string
    {
        $handle = (string) $skill->handle;
        $title = (string) ($skill->title ?? $handle);
        $description = (string) ($skill->description ?? '');
        $body = $this->_readBodyField($skill);

        return sprintf(
            "---\nname: %s\ndescription: %s\n---\n\n# %s\n\n%s",
            $handle,
            $description,
            $title,
            $body,
        );
    }

    /**
     * Reset the memoized merged corpus and the element-by-handle map.
     * Called from `Skill::afterSave / afterDelete / afterRestore` and
     * from `handleChangedFieldLayout` so the next read rebuilds
     * against the new state.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function resetMemo(): void
    {
        $this->_merged = null;
        $this->_elementByHandle = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the merged corpus and the element-by-handle map. Both
     * memoized fields are populated atomically — callers can rely on
     * either being non-null when one is non-null.
     *
     * Order of operations:
     *   1. Load every non-trashed element-stored Skill into a
     *      handle-keyed map.
     *   2. Iterate bundled skill names. For each handle:
     *      - If the element map has it, emit the element row (skip
     *        the bundled SKILL.md but still emit the bundled
     *        references for that handle).
     *      - Otherwise, emit the bundled SKILL.md row plus its
     *        references.
     *   3. After the bundled pass, iterate the element map for any
     *      handle that wasn't in the bundled set and emit it as a
     *      pure-element row.
     *   4. Iterate bundled agent names and emit them (no element-
     *      stored agents in 8.6).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildMerged(): void
    {
        // Step 1 — element-stored skills.
        $elements = Skill::find()
            ->status(null)
            ->site('*')
            ->all();

        $elementByHandle = [];
        foreach ($elements as $element) {
            if ($element->handle === null || $element->handle === '') {
                continue;
            }
            $elementByHandle[$element->handle] = $element;
        }
        $this->_elementByHandle = $elementByHandle;

        $rows = [];

        // Step 2 — bundled skills.
        $bundledHandles = BundledSkills::skillNames();
        foreach ($bundledHandles as $bundledHandle) {
            if (isset($elementByHandle[$bundledHandle])) {
                $rows[] = $this->_elementRow($elementByHandle[$bundledHandle]);
            } else {
                $rows[] = [
                    'kind' => 'skill',
                    'uri' => sprintf('craft-skills://%s', $bundledHandle),
                    'skill' => $bundledHandle,
                    'name' => null,
                    'content' => BundledSkills::content($bundledHandle),
                    'source' => 'bundled',
                ];
            }

            // Bundled references travel with the bundled SKILL.md and
            // are emitted regardless of override status — locked
            // decision 2 covers SKILL.md only.
            foreach (BundledSkills::references($bundledHandle) as $reference) {
                $rows[] = [
                    'kind' => 'reference',
                    'uri' => sprintf('craft-skills://%s/%s', $bundledHandle, $reference),
                    'skill' => $bundledHandle,
                    'name' => $reference,
                    'content' => BundledSkills::referenceContent($bundledHandle, $reference),
                    'source' => 'bundled',
                ];
            }
        }

        // Step 3 — element-only handles (not in bundled set).
        foreach ($elementByHandle as $handle => $skill) {
            if (in_array($handle, $bundledHandles, true)) {
                continue;
            }
            $rows[] = $this->_elementRow($skill);
        }

        // Step 4 — bundled agents (no element-stored agent concept).
        foreach (BundledSkills::agentNames() as $agent) {
            $rows[] = [
                'kind' => 'agent',
                'uri' => sprintf('craft-skills://agents/%s', $agent),
                'skill' => 'agents',
                'name' => $agent,
                'content' => BundledSkills::agentContent($agent),
                'source' => 'bundled',
            ];
        }

        $this->_merged = new MemoizableArray($rows);
    }

    /**
     * Read the skill's body field value tolerantly. When the field
     * layout has no `body` field (fresh install / pre-Gate-9 default)
     * we return an empty string rather than throwing — synthesis
     * still produces a well-formed bytestream with an empty body, and
     * downstream consumers handle that case as "skill metadata only,
     * no inline content yet".
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _readBodyField(Skill $skill): string
    {
        $layout = $skill->getFieldLayout();
        if ($layout === null || $layout->getFieldByHandle(self::DEFAULT_BODY_FIELD_HANDLE) === null) {
            return '';
        }
        $value = $skill->getFieldValue(self::DEFAULT_BODY_FIELD_HANDLE);
        return $value === null ? '' : (string) $value;
    }

    /**
     * Build a merged-corpus row for an element-stored skill. Synthesis
     * is delegated to `synthesizeContent()` so resource / prompt /
     * search consumers share the same bytes.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _elementRow(Skill $skill): array
    {
        return [
            'kind' => 'skill',
            'uri' => sprintf('craft-skills://%s', (string) $skill->handle),
            'skill' => (string) $skill->handle,
            'name' => null,
            'content' => $this->synthesizeContent($skill),
            'source' => 'element',
        ];
    }
}
