<?php

namespace craftpulse\herald\services;

use craftpulse\herald\events\RegisterResourcesEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\resources\AgentResource;
use craftpulse\herald\resources\ResourceInterface;
use craftpulse\herald\resources\ResourceTemplateInterface;
use craftpulse\herald\resources\SkillResource;
use craftpulse\herald\tools\support\RegistryLog;
use Michtio\CraftCmsClaudeSkills\Skills;
use yii\base\Component;

/**
 * =========================================================================
 * MCP resource registry.
 *
 * Built in two passes at service init. Pass 1 (`getBundled()`) is one
 * resource per addressable URI the package ships: each bundled skill's
 * SKILL.md router plus every reference document under `references/`,
 * then one per bundled agent. For 8 skills with N references each, that
 * pass holds `8 + sum(N)` skill entries. Pass 2 appends one resource per
 * element-authored skill handle with no bundled counterpart, which is the
 * install's own content.
 *
 * `getAll()` is both passes plus third-party event registrations, and is
 * what `resources/list` serves. `getBundled()` is pass 1 alone, for the
 * docs generator.
 *
 * Lookups are O(1) via a URI-keyed map; the registry is built once at
 * service init and never mutated. Same shape as `Tools` and `Prompts`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
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
     * @var ResourceInterface[] The subset of `$_resources` that the
     *     package itself ships: one per bundled-skill document, one per
     *     bundled agent. Captured before the registration event fires, so
     *     it carries neither element-authored skills nor third-party
     *     registrations. Read by the docs generator.
     */
    private array $_bundled = [];

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
     * @author CraftPulse
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        $this->_bundled = $this->_bundledResources();

        $event = new RegisterResourcesEvent();
        $event->resources = array_merge($this->_bundled, $this->_elementOnlyResources());
        $this->trigger(self::EVENT_REGISTER_RESOURCES, $event);

        foreach ($event->resources as $resource) {
            if ($resource instanceof ResourceInterface) {
                $uri = $resource->getUri();
                if (isset($this->_byUri[$uri])) {
                    // First registration wins. See `services/Tools::init`
                    // for the rationale; same shape on the Resources
                    // surface, keyed by URI rather than name.
                    RegistryLog::collision('Resource', 'URI', $uri, $this->_byUri[$uri], $resource);
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
                    RegistryLog::collision('Resource template', 'URI', $template, $this->_byTemplate[$template], $resource);
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
     * @author CraftPulse
     * @since  5.0.0
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
     * Registered URI templates in registration order. Read by
     * `Server::_resourceTemplatesList()` for the
     * `resources/templates/list` payload, and by `matchTemplate()` for
     * the `resources/read` fallback.
     *
     * @return ResourceTemplateInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getTemplates(): array
    {
        return $this->_templates;
    }

    /**
     * All registered resources, in registration order. This is the
     * install's live surface: bundled resources, the resources for any
     * element-authored skill, and anything a third-party plugin added
     * through `EVENT_REGISTER_RESOURCES`. `resources/list` serves it.
     *
     * @return ResourceInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAll(): array
    {
        return $this->_resources;
    }

    /**
     * The resources the package itself ships, in registration order: one
     * per document in the bundled skills corpus, one per bundled agent.
     * Derived from the filesystem alone.
     *
     * Captured before `EVENT_REGISTER_RESOURCES` fires and before the
     * element-stored pass, so it excludes both an operator's own
     * element-authored skills and third-party registrations. That is the
     * point: `docs/RESOURCES.md` is committed package documentation, and
     * regenerating it on a real install must never write that install's
     * content into it. Use `getAll()` for anything describing what an
     * install currently exposes.
     *
     * @return ResourceInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getBundled(): array
    {
        return $this->_bundled;
    }

    /**
     * Look up a resource by URI. Returns null if no resource with that
     * URI is registered — the dispatcher converts that to JSON-RPC
     * error -32602 (Invalid params).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getByUri(string $uri): ?ResourceInterface
    {
        return $this->_byUri[$uri] ?? null;
    }

    /**
     * Number of registered resources.
     *
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
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

    /**
     * `resources/templates/list` payload — an array of
     * `{uriTemplate, name, description, mimeType}` dicts ready to
     * JSON-encode.
     *
     * `uriTemplate` and `name` are the only fields MCP 2025-11-25
     * requires on a `ResourceTemplate`; `description` and `mimeType` are
     * optional and always present here because
     * `ResourceTemplateInterface` mandates both. The optional `title`,
     * `icons` and `annotations` fields are not emitted — the interface
     * carries no source for them, and inventing one would fabricate
     * display metadata the registering plugin never supplied.
     *
     * No `nextCursor`: pagination is optional at this revision and
     * neither `resources/list` nor `prompts/list` paginates, so
     * advertising a cursor would promise a page the server never serves.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function asTemplateListPayload(): array
    {
        return array_map(
            static fn(ResourceTemplateInterface $t): array => [
                'uriTemplate' => $t->getUriTemplate(),
                'name' => $t->getName(),
                'description' => $t->getDescription(),
                'mimeType' => $t->getMimeType(),
            ],
            $this->_templates,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Registry pass 1: everything the package ships. One resource per
     * bundled skill's SKILL.md, then that skill's references in the order
     * `Skills::references()` returns them (alphabetical), then one per
     * bundled agent.
     *
     * Depends only on the installed `michtio/craftcms-claude-skills`
     * version, never on database state, which is what makes
     * `getBundled()` safe to render into committed documentation.
     *
     * @return ResourceInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _bundledResources(): array
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
        // no-op in that case, so herald still boots cleanly against an
        // older install.
        foreach (Skills::agentNames() as $agent) {
            $registry[] = new AgentResource(agent: $agent);
        }

        return $registry;
    }

    /**
     * Registry pass 2 (Gate 8.6): one resource per element-stored skill
     * handle that has no bundled counterpart. This is the operator's own
     * content — tone of voice, brand and copywriting guidance authored as
     * `herald_skills` elements. The merged-corpus `read()` path
     * synthesises markdown for these on demand; registering them here is
     * what makes the URI surface in `resources/list` and resolve on
     * `resources/read`.
     *
     * The boot-time element query is fail-soft: if the `herald_skills`
     * table doesn't exist yet (fresh install pre-migration) the call
     * returns an empty array. A defensive try/catch lets the registry
     * boot cleanly in that window.
     *
     * @return ResourceInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _elementOnlyResources(): array
    {
        try {
            $elementHandles = Herald::getInstance()->skills->allHandles();
        } catch (\Throwable) {
            return [];
        }

        $bundledSet = array_flip(Skills::skillNames());
        $registry = [];

        foreach ($elementHandles as $handle) {
            if (isset($bundledSet[$handle])) {
                continue;
            }
            $registry[] = new SkillResource(skill: $handle);
        }

        return $registry;
    }
}
