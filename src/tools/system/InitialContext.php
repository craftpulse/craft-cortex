<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\base\ElementInterface;
use craft\enums\CmsEdition;
use craft\models\Section;
use craft\models\Site;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;

/**
 * =========================================================================
 * `get_initial_context` tool — bootstrap snapshot for the AI agent.
 *
 * The MCP `tools/list` payload tells a client every tool that exists,
 * but it doesn't tell the LLM **what kind of Craft install it's looking
 * at** or which of cortex's other surfaces are worth invoking first. A
 * model picking up a fresh conversation often wastes turns calling
 * `system_info`, `sites`, `sections`, and `permissions_and_groups` just
 * to orient itself before doing real work.
 *
 * This tool returns one consolidated payload covering exactly that
 * orientation surface — Craft version + edition + environment, the
 * primary site, a thin sites/sections/element-types index, the
 * bundled skill prompts so the model knows the moat-content exists,
 * the `craft_exec` posture (enabled / dry-run-default), and the
 * effective command allowlist. It is the tool a fresh model should
 * call first.
 *
 * Naming intentionally matches Contentful's `get_initial_context` —
 * some clients have hardcoded heuristics around that exact name, and
 * adopting the convention costs nothing.
 *
 * No parameters. Output shape is stable, so we declare an outputSchema
 * and spec-aware clients read structuredContent directly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[Title('Get Initial Context')]
#[IsReadOnly]
#[IsIdempotent]
class InitialContext extends AbstractTool
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
        return 'get_initial_context';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Bootstrap snapshot for an AI agent picking up a fresh conversation against ' .
            'this Craft install. Returns Craft version + edition + environment, the primary ' .
            'site handle, a thin sites/sections/element-types index, the bundled cortex ' .
            'skill prompts (the moat content the LLM should consult when authoring against ' .
            'Craft), the `craft_exec` posture, and the effective command allowlist. Call ' .
            'this first — it replaces three or four orientation tool calls with one.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function outputSchema(): array
    {
        return Schema::object([
            'craft' => Schema::object([
                'version' => Schema::string()->required(),
                'edition' => Schema::string()->required(),
                'schemaVersion' => Schema::string()->required(),
                'environment' => Schema::string()->required(),
                'devMode' => Schema::boolean()->required(),
                'primarySiteHandle' => Schema::string()->required(),
            ])->required(),
            'sites' => Schema::array(Schema::object([
                'handle' => Schema::string()->required(),
                'name' => Schema::string()->required(),
                'language' => Schema::string()->required(),
                'primary' => Schema::boolean()->required(),
            ]))->required(),
            'sections' => Schema::array(Schema::object([
                'handle' => Schema::string()->required(),
                'name' => Schema::string()->required(),
                'type' => Schema::string()->required(),
                'entryTypeCount' => Schema::integer()->required(),
            ]))->required(),
            'elementTypes' => Schema::array(Schema::object([
                'handle' => Schema::string()->required(),
                'refHandle' => Schema::string(),
                'displayName' => Schema::string()->required(),
                'class' => Schema::string()->required(),
            ]))->required(),
            'skillPrompts' => Schema::array(Schema::object([
                'name' => Schema::string()->required(),
                'description' => Schema::string()->required(),
            ]))->required()->description('Bundled cortex prompts that return authored Craft expertise. Invoke `prompts/get` with one of these names when authoring against Craft.'),
            'exec' => Schema::object([
                'enabled' => Schema::boolean()->required(),
                'dryRunDefault' => Schema::boolean()->required(),
            ])->required(),
            'allowlist' => Schema::array(Schema::string())->required()
                ->description('Effective allowlist of command-route glob patterns the `craft_command` tool may dispatch. Union of project-config defaults plus active runtime overrides.'),
            'hints' => Schema::array(Schema::string())->required()
                ->description('Operating notes for the agent — when to invoke which prompts, which tools are stdio-only, etc.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $sitesService = Craft::$app->getSites();
        $entriesService = Craft::$app->getEntries();
        $info = Craft::$app->getInfo();
        $settings = Cortex::getInstance()->getSettings();

        return [
            'craft' => [
                'version' => Craft::$app->getVersion(),
                'edition' => $this->_editionName(Craft::$app->edition),
                'schemaVersion' => $info->schemaVersion,
                'environment' => Craft::$app->env,
                'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
                'primarySiteHandle' => $sitesService->getPrimarySite()->handle,
            ],
            'sites' => array_map(
                static fn(Site $s): array => [
                    'handle' => (string) $s->handle,
                    'name' => $s->getName(),
                    'language' => $s->language,
                    'primary' => $s->primary,
                ],
                $sitesService->getAllSites(),
            ),
            'sections' => array_map(
                static fn(Section $s): array => [
                    'handle' => (string) $s->handle,
                    'name' => $s->name,
                    'type' => $s->type,
                    'entryTypeCount' => count($s->getEntryTypes()),
                ],
                $entriesService->getAllSections(),
            ),
            'elementTypes' => $this->_elementTypes(),
            'skillPrompts' => $this->_skillPrompts(),
            'exec' => [
                'enabled' => $settings->execEnabled,
                'dryRunDefault' => $settings->execDryRunDefault,
            ],
            'allowlist' => Cortex::getInstance()->allowlist->getEffective(),
            'hints' => $this->_hints(),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Map Craft's element-type FQCN list into the orientation summary.
     * Each element class exposes `displayName()` and `refHandle()`
     * statically; `pluralHandle()` becomes the conventional list handle
     * the AI is most likely to reach for (`entries`, `assets`, …).
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _elementTypes(): array
    {
        $types = [];
        /** @var class-string<ElementInterface> $class */
        foreach (Craft::$app->getElements()->getAllElementTypes() as $class) {
            $types[] = [
                'handle' => (string) $class::pluralLowerDisplayName(),
                'refHandle' => $class::refHandle(),
                'displayName' => (string) $class::displayName(),
                'class' => $class,
            ];
        }
        return $types;
    }

    /**
     * Snapshot the bundled-skills prompt registry so the AI sees the
     * moat content from the bootstrap response without a separate
     * `prompts/list` call. We pull straight from the Prompts registry
     * — cortex authoritative, no string duplication.
     *
     * @return array<int,array<string,string>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _skillPrompts(): array
    {
        return array_map(
            static fn(array $entry): array => [
                'name' => (string) $entry['name'],
                'description' => (string) $entry['description'],
            ],
            Cortex::getInstance()->prompts->asListPayload(),
        );
    }

    /**
     * Static operating notes the LLM benefits from on first turn —
     * which prompt routes where, which tools are stdio-only, the
     * dry-run-default posture of `craft_exec`. Kept short because
     * tool-call output sits in the model's working context.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _hints(): array
    {
        return [
            'Invoke the `craftcms_extending` prompt before authoring plugin or module PHP — it carries reverse-engineered internals you will not find in the public docs.',
            'Invoke `craftcms_templates` for Twig / front-end work; `craftcms_php_standards` and `craftcms_twig_standards` carry the coding conventions cortex itself enforces.',
            'Use `search_skills` to find specific guidance across the bundled corpus without listing every resource.',
            '`craft_exec` is stdio-only and dry-run-by-default — pass `confirm: true` to actually evaluate, plus `dangerous: true` for destructive expressions.',
            '`craft_command` only dispatches commands matching the effective allowlist (see `allowlist` above). Adding patterns requires admin access via the CP settings.',
            'Read-only tools (`sections`, `entries`, `system_info`, etc.) are safe to invoke speculatively; mutating tools are stdio-only or Pro-gated.',
        ];
    }

    /**
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _editionName(CmsEdition $edition): string
    {
        return $edition->name;
    }
}
