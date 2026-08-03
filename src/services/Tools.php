<?php

namespace craftpulse\herald\services;

use craft\elements\User;
use craftpulse\herald\events\RegisterToolsEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\content\Address;
use craftpulse\herald\tools\content\Assets;
use craftpulse\herald\tools\content\BulkEntries;
use craftpulse\herald\tools\content\Categories;
use craftpulse\herald\tools\content\Category;
use craftpulse\herald\tools\content\Entries;
use craftpulse\herald\tools\content\Entry;
use craftpulse\herald\tools\content\Globals;
use craftpulse\herald\tools\content\GlobalSet;
use craftpulse\herald\tools\content\ScaffoldEntries;
use craftpulse\herald\tools\content\Tag;
use craftpulse\herald\tools\content\Tags;
use craftpulse\herald\tools\dev\ClearCaches;
use craftpulse\herald\tools\dev\CraftCommand;
use craftpulse\herald\tools\dev\CraftExec;
use craftpulse\herald\tools\dev\Resave;
use craftpulse\herald\tools\dev\StreamingFixtureTool;
use craftpulse\herald\tools\graphql\Graphql;
use craftpulse\herald\tools\schema\CategoryGroups;
use craftpulse\herald\tools\schema\ElementTypes;
use craftpulse\herald\tools\schema\EntryTypes;
use craftpulse\herald\tools\schema\Fields;
use craftpulse\herald\tools\schema\FieldTypes;
use craftpulse\herald\tools\schema\ImageTransforms;
use craftpulse\herald\tools\schema\Sections;
use craftpulse\herald\tools\schema\Sites;
use craftpulse\herald\tools\schema\TagGroups;
use craftpulse\herald\tools\schema\VolumesAndFilesystems;
use craftpulse\herald\tools\support\AttributeReader;
use craftpulse\herald\tools\support\RegistryLog;
use craftpulse\herald\tools\system\Config;
use craftpulse\herald\tools\system\DatabaseSchema;
use craftpulse\herald\tools\system\Diagnostics;
use craftpulse\herald\tools\system\Extensibility;
use craftpulse\herald\tools\system\InitialContext;
use craftpulse\herald\tools\system\PermissionsAndGroups;
use craftpulse\herald\tools\system\Plugins;
use craftpulse\herald\tools\system\Routes;
use craftpulse\herald\tools\system\SearchSkills;
use craftpulse\herald\tools\system\Skill;
use craftpulse\herald\tools\system\SystemInfo;
use craftpulse\herald\tools\system\Users;
use craftpulse\herald\tools\ToolInterface;
use craftpulse\herald\tools\workflow\Audit;
use craftpulse\herald\tools\workflow\DraftsAndRevisions;
use craftpulse\herald\tools\workflow\ImportExport;
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
 * @author CraftPulse
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

    /**
     * @var ToolInterface[] Every tool the registry considered, in
     *     registration order, *before* `shouldRegister()` gating — so it
     *     holds the Pro write tools on a Free install and the streaming
     *     fixture on an install that never set its env var.
     */
    private array $_allUnfiltered = [];

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

        // Idempotent: Yii's constructor already calls `init()`, and a
        // handful of tests re-call it on a freshly constructed instance to
        // rebuild the registry after flipping the edition. Without the
        // reset those runs would double every list — the name-keyed map
        // dedupes itself, but the ordered arrays do not, and
        // `getAllUnfiltered()` is recorded ahead of the collision guard.
        $this->_tools = [];
        $this->_byName = [];
        $this->_allUnfiltered = [];

        $event = new RegisterToolsEvent();
        $event->tools = $this->_buildRegistry();
        $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

        foreach ($event->tools as $tool) {
            if (!$tool instanceof ToolInterface) {
                continue;
            }
            // Recorded before the gate so `getAllUnfiltered()` describes
            // the whole shipped surface rather than this install's slice
            // of it. Dispatch never reads this list.
            $this->_allUnfiltered[] = $tool;
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
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAll(): array
    {
        return $this->_tools;
    }

    /**
     * Every tool the registry considered, in registration order,
     * regardless of what `shouldRegister()` decided for this install.
     *
     * Documentation and diagnostics only. This is deliberately NOT a
     * dispatch surface: `getByName()` / `getByNameFor()` keep reading
     * the gated map, so a tool skipped at boot stays unresolvable over
     * both transports. The one consumer is `console/controllers/
     * DocsController`, which has to describe the whole shipped tool
     * surface — a `docs/TOOLS.md` regenerated on a Free install would
     * otherwise silently drop the nine Pro write tools, and the
     * committed reference would be a function of whoever ran the
     * generator rather than of the source.
     *
     * @return ToolInterface[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAllUnfiltered(): array
    {
        return $this->_allUnfiltered;
    }

    /**
     * Look up a tool by its MCP name. Returns null if no tool with that
     * name is registered — the dispatcher converts that to JSON-RPC
     * error -32602 (Invalid params).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getByName(string $name): ?ToolInterface
    {
        return $this->_byName[$name] ?? null;
    }

    /**
     * Number of registered tools. Cheap helper for diagnostics / tests.
     *
     * @author CraftPulse
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
     * @author CraftPulse
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
     * Locked architectural contract: per-user tool visibility.
     *
     * @param string[]|null $grantedScopes OAuth capability scopes carried
     *                                     by the access token, or null for
     *                                     stdio / bearer (not scope-gated).
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function asListPayloadFor(?User $user, ?array $grantedScopes = null): array
    {
        $scopes = Herald::getInstance()->scopes;
        $payload = [];
        foreach ($this->_tools as $tool) {
            if (!$tool->filterFor($user)) {
                continue;
            }
            if (!$scopes->grantsTool($tool::getName(), $grantedScopes)) {
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
     * Locked architectural contract: per-user tool visibility.
     *
     * @param string[]|null $grantedScopes OAuth capability scopes carried
     *                                     by the access token, or null for
     *                                     stdio / bearer (not scope-gated).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getByNameFor(string $name, ?User $user, ?array $grantedScopes = null): ?ToolInterface
    {
        $tool = $this->_byName[$name] ?? null;
        if ($tool === null) {
            return null;
        }
        if (!$tool->filterFor($user)) {
            return null;
        }
        if (!Herald::getInstance()->scopes->grantsTool($tool::getName(), $grantedScopes)) {
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
     * @author CraftPulse
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
     * @author CraftPulse
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

            // Content writing (Pro). `Entry::shouldRegister()` returns
            // false on Free installs so the registration loop skips it
            // before the instance is exposed to `tools/list`. Same for
            // the Gate 8.3 siblings (`Category`, `Tag`, `GlobalSet`) and
            // the Gate 8.4 sibling (`Address`).
            new Entry(),
            new Category(),
            new Tag(),
            new GlobalSet(),
            new Address(),
            new Users(), // system-namespaced but shares the Pro-write registration block
            new Skill(), // Gate 8.6 — Herald's first owned element type
            new BulkEntries(), // Gate 8.7 — first streaming Pro tool
            new ScaffoldEntries(), // Gate 8.7 — template-driven create-many sibling

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
