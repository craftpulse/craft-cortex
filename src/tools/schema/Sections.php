<?php

namespace craftpulse\cortex\tools\schema;

use Craft;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `sections` tool — list / get / count Craft sections.
 *
 * Modes:
 *   - default (no `handle`): returns every section with its site settings,
 *     URI formats, propagation method, and entry-type count.
 *   - `handle: "<sectionHandle>"`: returns that single section in the
 *     same shape.
 *   - `count: true`: returns `{count: int}` instead of full results.
 *
 * Errors:
 *   - `handle` provided but no section matches it -> ToolException.
 *
 * No N+1: section -> site settings + entry types are already eager-loaded
 * by Craft's Sections service when calling `getAllSections()` /
 * `getSectionByHandle()`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Sections extends AbstractTool
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
        return 'sections';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'List all sections, get a single section by handle, or count sections. ' .
            'Returns each section with its type (channel/structure/single), site settings, ' .
            'URI formats, propagation method, and number of entry types.';
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
                'handle' => [
                    'type' => 'string',
                    'description' => 'Section handle. Omit to list all sections.',
                ],
                'count' => [
                    'type' => 'boolean',
                    'description' => 'Return only the count of matching sections.',
                ],
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
        $entriesService = Craft::$app->getEntries();
        $handle = $this->_handle($arguments);
        $count = $this->_isCount($arguments);

        if ($handle !== null) {
            $section = $entriesService->getSectionByHandle($handle);
            if ($section === null) {
                throw new ToolException("No section found with handle '{$handle}'.");
            }

            if ($count) {
                return ['count' => 1];
            }

            return ['section' => $this->_serializeSection($section)];
        }

        $sections = $entriesService->getAllSections();

        if ($count) {
            return ['count' => count($sections)];
        }

        return [
            'sections' => array_map(fn (Section $s): array => $this->_serializeSection($s), $sections),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Project a Section model into a stable JSON shape.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeSection(Section $section): array
    {
        return [
            'id' => $section->id,
            'uid' => $section->uid,
            'name' => $section->name,
            'handle' => $section->handle,
            'type' => $section->type,
            'enableVersioning' => $section->enableVersioning,
            'propagationMethod' => $section->propagationMethod->value,
            'maxLevels' => $section->maxLevels,
            'maxAuthors' => $section->maxAuthors,
            'defaultPlacement' => $section->defaultPlacement,
            'previewTargets' => $section->previewTargets,
            'entryTypeCount' => count($section->getEntryTypes()),
            'entryTypeHandles' => array_map(
                static fn ($entryType): string => (string) $entryType->handle,
                $section->getEntryTypes(),
            ),
            'siteSettings' => array_map(
                fn (Section_SiteSettings $settings): array => $this->_serializeSiteSettings($settings),
                array_values($section->getSiteSettings()),
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _serializeSiteSettings(Section_SiteSettings $settings): array
    {
        $siteId = (int) $settings->siteId;
        return [
            'siteId' => $siteId,
            'siteUid' => Craft::$app->getSites()->getSiteById($siteId)?->uid,
            'enabledByDefault' => $settings->enabledByDefault,
            'hasUrls' => $settings->hasUrls,
            'uriFormat' => $settings->uriFormat,
            'template' => $settings->template,
        ];
    }
}
