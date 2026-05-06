<?php

namespace craftpulse\cortex\services;

use craftpulse\cortex\resources\ResourceInterface;
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

        foreach ($this->_buildRegistry() as $resource) {
            $this->_resources[] = $resource;
            $this->_byUri[$resource->getUri()] = $resource;
        }
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
            static fn (ResourceInterface $r): array => [
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

        return $registry;
    }
}
