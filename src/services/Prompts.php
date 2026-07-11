<?php

namespace craftpulse\herald\services;

use craftpulse\herald\events\RegisterPromptsEvent;
use craftpulse\herald\prompts\PromptInterface;
use craftpulse\herald\prompts\SkillPrompt;
use craftpulse\herald\tools\support\RegistryLog;
use Michtio\CraftCmsClaudeSkills\Skills;
use yii\base\Component;

/**
 * =========================================================================
 * MCP prompt registry.
 *
 * One prompt per bundled skill in `michtio/craftcms-claude-skills`.
 * The mapping from on-disk skill name (e.g. `craftcms`) to public MCP
 * name (e.g. `craftcms_extending`) plus its one-line description lives
 * in `PROMPT_MAP` below — that's intentionally explicit so adding a
 * skill requires a deliberate registry update, not implicit wiring.
 *
 * Lookups are O(1) via a name-keyed map; the registry is built once at
 * service init and never mutated. Same shape as `Tools` for symmetry.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Prompts extends Component
{
    // Constants
    // =========================================================================

    /**
     * Event fired during boot to allow third-party plugins to register
     * their own prompts. See `events/RegisterPromptsEvent`.
     */
    public const EVENT_REGISTER_PROMPTS = 'registerPrompts';

    // Private Properties
    // =========================================================================

    /**
     * Public MCP name + description per bundled skill. Skills not listed
     * here are silently skipped — that's deliberate. Adding a new skill
     * requires both the upstream skills package release AND an entry
     * here so we own the public surface area on herald's side.
     *
     * **Element-stored skills are NOT auto-promoted to prompts** (Gate
     * 8.6, locked decision 17). An element-stored skill with a handle
     * matching a `PROMPT_MAP` entry DOES override the bundled body
     * when its prompt renders — see `SkillPrompt::render()` — but a
     * new handle that is not in `PROMPT_MAP` surfaces as a resource
     * (`resources/list`) only, not as a prompt. The whitelist stays
     * authoritative because new prompts are a curated public surface
     * area, not an implicit one driven by editor authoring.
     *
     * @var array<string,array{name: string, description: string}>
     */
    private const PROMPT_MAP = [
        // Tier 1 — moat content (knowledge that exists nowhere else).
        'craftcms' => [
            'name' => 'craftcms_extending',
            'description' => 'Reverse-engineered Craft CMS internals: 15-step element save lifecycle, four-layer authorization model, dual-layer session architecture, plus the full plugin/module extension surface (services, controllers, queue jobs, project config, GraphQL). Far beyond official docs.',
        ],
        'craft-site' => [
            'name' => 'craftcms_templates',
            'description' => 'Complete Craft CMS front-end framework and methodology — content modeling, Twig templating, component architecture, headless setup, and 22 plugin integration guides distilled from full plugin documentation.',
        ],
        'craft-garnish' => [
            'name' => 'craftcms_cp_javascript',
            'description' => 'The only written documentation for Garnish, Craft CMS\'s built-in Control Panel JavaScript toolkit — the class system, UI widgets (Modal, HUD, DisclosureMenu, Select), drag interactions, form components, and accessibility helpers.',
        ],

        // Tier 2 — supporting reference material.
        'craft-content-modeling' => [
            'name' => 'craftcms_content_modeling',
            'description' => 'Content modeling principles for Craft CMS — sections, entry types, fields, matrices, relations, propagation, and how to design schemas that scale across editors and sites.',
        ],
        'craft-php-guidelines' => [
            'name' => 'craftcms_php_standards',
            'description' => 'PHP coding standards for Craft CMS plugin and module development — PHPDocs, section headers, naming conventions, class organization, and ECS/PHPStan configuration.',
        ],
        'craft-twig-guidelines' => [
            'name' => 'craftcms_twig_standards',
            'description' => 'Twig coding standards for Craft CMS templates — template structure, naming, accessibility, performance, and how to keep templates clean as a project grows.',
        ],
        'ddev' => [
            'name' => 'craftcms_ddev',
            'description' => 'DDEV usage and troubleshooting for Craft CMS development — local environment setup, container management, common commands, and integration with composer/npm/craft tooling.',
        ],
        'craft-project-setup' => [
            'name' => 'craftcms_setup',
            'description' => 'Standard project setup playbook for new Craft CMS sites — DDEV bootstrap, plugin selection, project config conventions, and getting from zero to a working dev environment.',
        ],
        'servd' => [
            'name' => 'craftcms_servd',
            'description' => 'Servd managed hosting for Craft CMS — git push-to-deploy with servd.yaml, the asset-storage plugin and CDN, static caching alongside Blitz, the local → staging → production workflow with uni-directional project config sync, and how Servd differs from Craft Cloud.',
        ],
        'craft-cloud' => [
            'name' => 'craftcms_cloud',
            'description' => 'Craft Cloud, Pixel & Tonic\'s serverless hosting for Craft CMS — craft-cloud.yaml configuration, the Build → Migrate → Release deploy pipeline, edge image transforms and static caching, the ephemeral Cloud filesystem, plugin Cloud-compatibility requirements, and self-hosted → Cloud migration.',
        ],
    ];

    /**
     * @var PromptInterface[] Registered prompts, in registration order.
     */
    private array $_prompts = [];

    /**
     * @var array<string,PromptInterface> Name-keyed lookup map.
     */
    private array $_byName = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        $event = new RegisterPromptsEvent();
        $event->prompts = $this->_buildRegistry();
        $this->trigger(self::EVENT_REGISTER_PROMPTS, $event);

        foreach ($event->prompts as $prompt) {
            if (!$prompt instanceof PromptInterface) {
                continue;
            }
            $name = $prompt->getName();
            if (isset($this->_byName[$name])) {
                // First registration wins. See `services/Tools::init` for
                // the rationale; same shape on the Prompts surface.
                RegistryLog::collision('Prompt', 'name', $name, $this->_byName[$name], $prompt);
                continue;
            }
            $this->_prompts[] = $prompt;
            $this->_byName[$name] = $prompt;
        }
    }

    /**
     * All registered prompts, in registration order.
     *
     * @return PromptInterface[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getAll(): array
    {
        return $this->_prompts;
    }

    /**
     * Look up a prompt by its public MCP name. Returns null if no
     * prompt with that name is registered — the dispatcher converts
     * that to JSON-RPC error -32602 (Invalid params).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getByName(string $name): ?PromptInterface
    {
        return $this->_byName[$name] ?? null;
    }

    /**
     * Number of registered prompts.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getCount(): int
    {
        return count($this->_prompts);
    }

    /**
     * `prompts/list` payload — an array of `{name, description, arguments?}`
     * dicts ready to JSON-encode. The `arguments` key is omitted when
     * a prompt declares none (per spec: optional).
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function asListPayload(): array
    {
        return array_map(
            static function(PromptInterface $p): array {
                $entry = [
                    'name' => $p->getName(),
                    'description' => $p->getDescription(),
                ];

                $arguments = $p->getArguments();
                if ($arguments !== []) {
                    $entry['arguments'] = $arguments;
                }

                return $entry;
            },
            $this->_prompts,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the prompt registry by joining the bundled-skills inventory
     * with the static `PROMPT_MAP`. Skills present on disk but absent
     * from the map are skipped (logged-by-omission); skills in the map
     * but missing on disk are skipped as well — both situations indicate
     * a version drift that the test suite catches.
     *
     * @return PromptInterface[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildRegistry(): array
    {
        $registry = [];

        foreach (Skills::skillNames() as $skill) {
            $entry = self::PROMPT_MAP[$skill] ?? null;
            if ($entry === null) {
                continue;
            }

            $registry[] = new SkillPrompt(
                name: $entry['name'],
                skill: $skill,
                description: $entry['description'],
            );
        }

        return $registry;
    }
}
