<?php

namespace craftpulse\cortex\services;

use craftpulse\cortex\tools\content\Assets;
use craftpulse\cortex\tools\content\Categories;
use craftpulse\cortex\tools\content\Entries;
use craftpulse\cortex\tools\content\Globals;
use craftpulse\cortex\tools\content\Tags;
use craftpulse\cortex\tools\dev\ClearCaches;
use craftpulse\cortex\tools\dev\CraftCommand;
use craftpulse\cortex\tools\dev\CraftExec;
use craftpulse\cortex\tools\dev\Resave;
use craftpulse\cortex\tools\graphql\Graphql;
use craftpulse\cortex\tools\schema\CategoryGroups;
use craftpulse\cortex\tools\schema\ElementTypes;
use craftpulse\cortex\tools\schema\EntryTypes;
use craftpulse\cortex\tools\schema\Fields;
use craftpulse\cortex\tools\schema\FieldTypes;
use craftpulse\cortex\tools\schema\ImageTransforms;
use craftpulse\cortex\tools\schema\Sections;
use craftpulse\cortex\tools\schema\Sites;
use craftpulse\cortex\tools\schema\TagGroups;
use craftpulse\cortex\tools\schema\VolumesAndFilesystems;
use craftpulse\cortex\tools\system\Config;
use craftpulse\cortex\tools\system\DatabaseSchema;
use craftpulse\cortex\tools\system\Diagnostics;
use craftpulse\cortex\tools\system\Extensibility;
use craftpulse\cortex\tools\system\PermissionsAndGroups;
use craftpulse\cortex\tools\system\Plugins;
use craftpulse\cortex\tools\system\Routes;
use craftpulse\cortex\tools\system\SystemInfo;
use craftpulse\cortex\tools\support\AttributeReader;
use craftpulse\cortex\tools\ToolInterface;
use yii\base\Component;

/**
 * =========================================================================
 * MCP tool registry.
 *
 * Single source of truth for which tools the server exposes. Built once
 * at service init from a static list — no runtime registration yet
 * (third-party plugin tools land in Pro per PLANNING.md 4.14). Lookups
 * are O(n) over a small array; if the registry ever grows past ~50
 * tools we'll switch to a name-keyed map.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Tools extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var ToolInterface[] Registered tool instances, in registration order.
     */
    private array $_tools = [];

    /**
     * @var array<string,ToolInterface> Name-keyed lookup map for getByName().
     */
    private array $_byName = [];

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

        foreach ($this->_buildRegistry() as $tool) {
            $this->_tools[] = $tool;
            $this->_byName[$tool::getName()] = $tool;
        }
    }

    /**
     * All registered tools, in registration order. Used by the MCP server
     * to build the `tools/list` response.
     *
     * @return ToolInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getAll(): array
    {
        return $this->_tools;
    }

    /**
     * Look up a tool by its MCP name. Returns null if no tool with that
     * name is registered — the dispatcher converts that to JSON-RPC
     * error -32602 (Invalid params).
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getByName(string $name): ?ToolInterface
    {
        return $this->_byName[$name] ?? null;
    }

    /**
     * Number of registered tools. Cheap helper for diagnostics / tests.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getCount(): int
    {
        return count($this->_tools);
    }

    /**
     * `tools/list` payload — an array of `{name, description, inputSchema}`
     * dicts ready to JSON-encode. Pulled out of the dispatcher so a unit
     * test can assert the registry's external shape without booting the
     * JSON-RPC layer.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function asListPayload(): array
    {
        return array_map(
            static function (ToolInterface $t): array {
                $entry = [
                    'name' => $t::getName(),
                    'description' => $t::getDescription(),
                    'inputSchema' => $t::getInputSchema(),
                ];

                $annotations = AttributeReader::annotationsFor($t);
                if ($annotations !== []) {
                    $entry['annotations'] = $annotations;
                }

                return $entry;
            },
            $this->_tools,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the tool registry. Order here is the order tools appear in
     * `tools/list` — schema first, content reading next, then system,
     * GraphQL, dev actions, per the Phase 1 sequence in PLANNING.md.
     *
     * @return ToolInterface[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _buildRegistry(): array
    {
        return [
            // Gate 2 — Schema & Structure (10 tools).
            new Sections(),
            new EntryTypes(),
            new Fields(),
            new FieldTypes(),
            new CategoryGroups(),
            new TagGroups(),
            new VolumesAndFilesystems(),
            new Sites(),
            new ImageTransforms(),
            new ElementTypes(),

            // Gate 3 — Content Reading (5 tools).
            new Entries(),
            new Assets(),
            new Categories(),
            new Tags(),
            new Globals(),

            // Gate 4 — System & Diagnostics (8 tools).
            new SystemInfo(),
            new Config(),
            new Plugins(),
            new Routes(),
            new Diagnostics(),
            new DatabaseSchema(),
            new Extensibility(),
            new PermissionsAndGroups(),

            // Gate 5 — GraphQL & Dev Actions (5 tools).
            new Graphql(),
            new ClearCaches(),
            new Resave(),
            new CraftCommand(),
            new CraftExec(),
        ];
    }
}
