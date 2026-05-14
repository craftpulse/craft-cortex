<?php

namespace craftpulse\cortex\services;

use craft\elements\User;
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\tools\content\Assets;
use craftpulse\cortex\tools\content\Categories;
use craftpulse\cortex\tools\content\Entries;
use craftpulse\cortex\tools\content\Globals;
use craftpulse\cortex\tools\content\Tags;
use craftpulse\cortex\tools\dev\ClearCaches;
use craftpulse\cortex\tools\dev\CraftCommand;
use craftpulse\cortex\tools\dev\CraftExec;
use craftpulse\cortex\tools\dev\Resave;
use craftpulse\cortex\tools\dev\StreamingFixtureTool;
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
use craftpulse\cortex\tools\support\AttributeReader;
use craftpulse\cortex\tools\support\RegistryLog;
use craftpulse\cortex\tools\system\Config;
use craftpulse\cortex\tools\system\DatabaseSchema;
use craftpulse\cortex\tools\system\Diagnostics;
use craftpulse\cortex\tools\system\Extensibility;
use craftpulse\cortex\tools\system\InitialContext;
use craftpulse\cortex\tools\system\PermissionsAndGroups;
use craftpulse\cortex\tools\system\Plugins;
use craftpulse\cortex\tools\system\Routes;
use craftpulse\cortex\tools\system\SearchSkills;
use craftpulse\cortex\tools\system\SystemInfo;
use craftpulse\cortex\tools\ToolInterface;
use craftpulse\cortex\tools\workflow\Audit;
use craftpulse\cortex\tools\workflow\DraftsAndRevisions;
use craftpulse\cortex\tools\workflow\ImportExport;
use yii\base\Component;

/**
 * =========================================================================
 * MCP tool registry.
 *
 * Single source of truth for which tools the server exposes. Built once
 * at service init from a static list — third-party plugin tool
 * registration lands in the Pro tier. Lookups are O(n) over a small
 * array; if the registry ever grows past ~50 tools we'll switch to a
 * name-keyed map.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Tools extends Component
{
    // Constants
    // =========================================================================

    /**
     * Event fired during boot to allow third-party plugins to register
     * their own tools. Listeners append `ToolInterface` instances to
     * `RegisterToolsEvent::$tools`. See `events/RegisterToolsEvent` for
     * the contract and security guidance.
     */
    public const EVENT_REGISTER_TOOLS = 'registerTools';

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
     * @since  5.0.0
     */
    public function init(): void
    {
        parent::init();

        $event = new RegisterToolsEvent();
        $event->tools = $this->_buildRegistry();
        $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

        foreach ($event->tools as $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }
            if (!$tool::shouldRegister()) {
                // Boot-time license / edition / settings gating per
                // the static class-level contract. Per-request per-user
                // visibility is `filterFor()`'s job downstream.
                continue;
            }
            $name = $tool::getName();
            if (isset($this->_byName[$name])) {
                // First registration wins — don't let third-party tools
                // shadow bundled ones, and don't let collisions silently
                // overwrite. Surface the collision in Craft's log so the
                // third-party plugin author can see why their tool is
                // missing from `tools/list`.
                RegistryLog::collision('Tool', 'name', $name, $this->_byName[$name], $tool);
                continue;
            }
            $this->_tools[] = $tool;
            $this->_byName[$name] = $tool;
        }
    }

    /**
     * All registered tools, in registration order. Used by the MCP server
     * to build the `tools/list` response.
     *
     * @return ToolInterface[]
     *
     * @author Craftpulse
     * @since  5.0.0
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
     * @since  5.0.0
     */
    public function getByName(string $name): ?ToolInterface
    {
        return $this->_byName[$name] ?? null;
    }

    /**
     * Number of registered tools. Cheap helper for diagnostics / tests.
     *
     * @author Craftpulse
     * @since  5.0.0
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
     * stdio path. The HTTP transport calls `asListPayloadFor($user)` so
     * per-user `filterFor()` / `inputSchemaFor()` hooks fire; this
     * method is the `null`-user invariant — `asListPayloadFor(null)`
     * MUST equal `asListPayload()` so stdio output never drifts.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function asListPayload(): array
    {
        return $this->asListPayloadFor(null);
    }

    /**
     * Per-user `tools/list` payload. Tools where `filterFor($user)`
     * returns `false` are omitted entirely — the LLM never sees them.
     * Tools that survive get their `inputSchema` from
     * `inputSchemaFor($user)` so mode-gated tools can rewrite their
     * schema per the user's permissions.
     *
     * stdio passes `null` and the default-true / static-schema path
     * applies — `asListPayloadFor(null) === asListPayload()` is the
     * locked invariant (tested in `tests/Services/ToolsTest.php`).
     *
     * Locked architectural contract — see
     * `.claude/rules/architecture.md` "Per-user tool visibility" and
     * `docs/plans/gate-7.md` locked decision 3.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function asListPayloadFor(?User $user): array
    {
        $payload = [];
        foreach ($this->_tools as $tool) {
            if (!$tool->filterFor($user)) {
                continue;
            }
            $payload[] = $this->_buildListEntry($tool, $user);
        }
        return $payload;
    }

    /**
     * Per-user variant of `getByName()`. Returns the tool only when
     * it's registered AND `filterFor($user)` returns `true`. Otherwise
     * `null` — the dispatcher then converts the miss to JSON-RPC
     * `-32602 Invalid params` "Unknown tool", same as if the tool had
     * never been registered. Failing closed: a hidden tool is
     * indistinguishable from a missing tool to the caller.
     *
     * Locked architectural contract — see
     * `.claude/rules/architecture.md` "Per-user tool visibility" and
     * `docs/plans/gate-7.md` locked decision 3.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getByNameFor(string $name, ?User $user): ?ToolInterface
    {
        $tool = $this->_byName[$name] ?? null;
        if ($tool === null) {
            return null;
        }
        if (!$tool->filterFor($user)) {
            return null;
        }
        return $tool;
    }

    // Private Methods
    // =========================================================================

    /**
     * Build one `tools/list` entry for the given tool. Shared between
     * `asListPayload()` (stdio, `null` user) and `asListPayloadFor()`
     * (HTTP, resolved user). The only per-user surface is
     * `inputSchemaFor($user)` — everything else is static metadata.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildListEntry(ToolInterface $tool, ?User $user): array
    {
        $entry = [
            'name' => $tool::getName(),
            'description' => $tool::getDescription(),
            'inputSchema' => $tool->inputSchemaFor($user),
        ];

        $outputSchema = $tool::outputSchema();
        if ($outputSchema !== []) {
            $entry['outputSchema'] = $outputSchema;
        }

        $annotations = AttributeReader::annotationsFor($tool);
        if ($annotations !== []) {
            $entry['annotations'] = $annotations;
        }

        return $entry;
    }

    /**
     * Build the tool registry. Order here is the order tools appear in
     * `tools/list` — schema first, content reading next, then system,
     * GraphQL, dev actions, workflow.
     *
     * @return ToolInterface[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildRegistry(): array
    {
        return [
            // Orientation — first in the list so fresh agents see it first.
            new InitialContext(),

            // Schema & structure.
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

            // Content reading.
            new Entries(),
            new Assets(),
            new Categories(),
            new Tags(),
            new Globals(),

            // System & diagnostics.
            new SystemInfo(),
            new Config(),
            new Plugins(),
            new Routes(),
            new Diagnostics(),
            new DatabaseSchema(),
            new Extensibility(),
            new PermissionsAndGroups(),
            new SearchSkills(),

            // GraphQL & dev actions.
            new Graphql(),
            new ClearCaches(),
            new Resave(),
            new CraftCommand(),
            new CraftExec(),
            new StreamingFixtureTool(),

            // Workflow & audit (read modes).
            new DraftsAndRevisions(),
            new Audit(),
            new ImportExport(),
        ];
    }
}
