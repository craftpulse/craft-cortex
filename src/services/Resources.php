<?php

namespace craftpulse\cortex\services;

use Craft;
use craftpulse\cortex\events\RegisterResourcesEvent;
use craftpulse\cortex\resources\AgentResource;
use craftpulse\cortex\resources\ResourceInterface;
use craftpulse\cortex\resources\ResourceTemplateInterface;
use craftpulse\cortex\resources\SkillResource;
use Michtio\CraftCmsClaudeSkills\Skills;
use yii\base\Component;

/**
 * =========================================================================
 * MCP resource registry.
 *
 * Builds one resource per addressable URI in the bundled-skills
 * package: each skill's SKILL.md (the router) plus every reference
 * document under `references/`. For 8 skills with N references each,
 * the registry holds `8 + sum(N)` entries.
 *
 * Lookups are O(1) via a URI-keyed map; the registry is built once at
 * service init and never mutated. Same shape as `Tools` and `Prompts`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Resources extends Component
{
    // Constants
    // =========================================================================

    /**
     * Event fired during boot to allow third-party plugins to register
     * their own resources. See `events/RegisterResourcesEvent`.
     */
    public const EVENT_REGISTER_RESOURCES = 'registerResources';

    // Private Properties
    // =========================================================================

    /**
     * @var ResourceInterface[] Registered resources, in registration order.
     */
    private array $_resources = [];

    /**
     * @var array<string,ResourceInterface> URI-keyed lookup map.
     */
    private array $_byUri = [];

    /**
     * @var ResourceTemplateInterface[] Registered URI templates,
     *     consulted only when a concrete-URI lookup misses. Templates
     *     don't shadow concrete resources.
     */
    private array $_templates = [];

    /**
     * @var array<string,ResourceTemplateInterface> URI-template-keyed
     *     lookup used to detect duplicate template registrations and
     *     emit a collision warning at registry build time.
     */
    private array $_byTemplate = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function init(): void
    {
        parent::init();

        $event = new RegisterResourcesEvent();
        $event->resources = $this->_buildRegistry();
        $this->trigger(self::EVENT_REGISTER_RESOURCES, $event);

        foreach ($event->resources as $resource) {
            if ($resource instanceof ResourceInterface) {
                $uri = $resource->getUri();
                if (isset($this->_byUri[$uri])) {
                    // First registration wins. See `services/Tools::init`
                    // for the rationale; same shape on the Resources
                    // surface, keyed by URI rather than name.
                    Craft::warning(
                        sprintf(
                            'Resource URI collision on "%s" — first registration (%s) wins; ignoring %s.',
                            $uri,
                            $this->_byUri[$uri]::class,
                            $resource::class,
                        ),
                        'cortex',
                    );
                    continue;
                }
                $this->_resources[] = $resource;
                $this->_byUri[$uri] = $resource;
                continue;
            }
            if ($resource instanceof ResourceTemplateInterface) {
                $template = $resource->getUriTemplate();
                if (isset($this->_byTemplate[$template])) {
                    // Same first-wins behaviour as concrete resources
                    // and the Tools registry — warn loudly so collisions
                    // surface during boot rather than as silent
                    // misroutes at request time.
                    Craft::warning(
                        sprintf(
                            'Resource template collision on "%s" — first registration (%s) wins; ignoring %s.',
                            $template,
                            $this->_byTemplate[$template]::class,
                            $resource::class,
                        ),
                        'cortex',
                    );
                    continue;
                }
                $this->_templates[] = $resource;
                $this->_byTemplate[$template] = $resource;
                continue;
            }
        }
    }

    /**
     * Resolve a URI against any registered template. Returns a
     * `[template, captures]` tuple on first match, or null. Concrete-URI
     * resources should be preferred — call `getByUri()` first.
     *
     * @return array{0: ResourceTemplateInterface, 1: array<string,string>}|null
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function matchTemplate(string $uri): ?array
    {
        foreach ($this->_templates as $template) {
            $captures = $template->matches($uri);
            if ($captures !== null) {
                return [$template, $captures];
            }
        }
        return null;
    }

    /**
     * Registered URI templates in registration order. Used by the
     * `resources/list` payload to surface templated entries to clients
     * that support `resources/templates/list` (MCP 2025-06-18).
     *
     * @return ResourceTemplateInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getTemplates(): array
    {
        return $this->_templates;
    }

    /**
     * All registered resources, in registration order.
     *
     * @return ResourceInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getAll(): array
    {
        return $this->_resources;
    }

    /**
     * Look up a resource by URI. Returns null if no resource with that
     * URI is registered — the dispatcher converts that to JSON-RPC
     * error -32602 (Invalid params).
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getByUri(string $uri): ?ResourceInterface
    {
        return $this->_byUri[$uri] ?? null;
    }

    /**
     * Number of registered resources.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getCount(): int
    {
        return count($this->_resources);
    }

    /**
     * `resources/list` payload — an array of `{uri, name, description, mimeType}`
     * dicts ready to JSON-encode.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function asListPayload(): array
    {
        return array_map(
            static fn(ResourceInterface $r): array => [
                'uri' => $r->getUri(),
                'name' => $r->getName(),
                'description' => $r->getDescription(),
                'mimeType' => $r->getMimeType(),
            ],
            $this->_resources,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the resource registry by iterating every bundled skill and
     * every reference inside it. Order: per skill, the SKILL.md router
     * first, then its references in alphabetical order (the order
     * `Skills::references()` returns them).
     *
     * @return ResourceInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildRegistry(): array
    {
        $registry = [];

        foreach (Skills::skillNames() as $skill) {
            $registry[] = new SkillResource(skill: $skill);

            foreach (Skills::references($skill) as $reference) {
                $registry[] = new SkillResource(skill: $skill, reference: $reference);
            }
        }

        // Agent resources from `michtio/craftcms-claude-skills` v1.4.2+
        // surface alongside skills. The companion package's helper
        // returns `[]` from agentNames() if the package is older than
        // 1.4.2 or the agents/ directory is absent — the loop is a
        // no-op in that case, so cortex still boots cleanly against an
        // older install.
        foreach (Skills::agentNames() as $agent) {
            $registry[] = new AgentResource(agent: $agent);
        }

        return $registry;
    }
}
