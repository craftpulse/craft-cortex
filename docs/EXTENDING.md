# Extending Herald

Herald is built around a stable, versioned extension surface. Third-party plugins can register their own tools, prompts, and resources and have them appear alongside the bundled ones in `tools/list`, `prompts/list`, and `resources/list`.

This document covers everything you need to ship an extension. The interfaces, attributes, events, and Schema DSL listed here are part of Herald's public surface, and they don't change without a deprecation cycle or a major version bump.

- [Quick start](#quick-start)
- [The generator](#the-generator)
- [Registering a tool](#registering-a-tool)
- [Registering a prompt](#registering-a-prompt)
- [Registering a resource](#registering-a-resource)
- [Resource templates (dynamic URIs)](#resource-templates-dynamic-uris)
- [The Schema DSL](#the-schema-dsl)
- [Tool annotations (attributes)](#tool-annotations-attributes)
- [Edition gating and conditional registration](#edition-gating-and-conditional-registration)
- [Observing invocations](#observing-invocations)
- [Naming conventions](#naming-conventions)
- [Collision behaviour](#collision-behaviour)
- [Testing your extension](#testing-your-extension)

## Quick start

The fastest path: scaffold a tool with the generator, then register it from your plugin's `init()`.

```bash
ddev craft make herald-tool
```

Answer the prompts (class name, namespace, MCP tool name) and the generator drops a stub class with the right attributes and Schema DSL boilerplate. It also prints the registration snippet for your plugin's `init()`.

Then in your plugin's main class:

```php
use craftpulse\herald\events\RegisterToolsEvent;
use craftpulse\herald\services\Tools;
use yii\base\Event;

public function init(): void
{
    parent::init();

    Event::on(
        Tools::class,
        Tools::EVENT_REGISTER_TOOLS,
        function (RegisterToolsEvent $event): void {
            $event->tools[] = new \mywishingwell\plugin\tools\GetWish();
        },
    );
}
```

That's the whole extension surface. Herald picks up the tool at boot, validates it implements `ToolInterface`, and exposes it to MCP clients.

## The generator

Herald hooks into Craft's `make` command via `craftcms/generator`'s `EVENT_REGISTER_GENERATORS`. Run from any Craft project where Herald is installed:

```bash
ddev craft make herald-tool
```

The generator prompts for:

- **Class name**: e.g. `GetWish`
- **Namespace**: defaults to your plugin's `tools/` directory
- **MCP tool name**: the `name` field MCP clients see (e.g. `get_wish`)

It writes a stub class extending `AbstractTool` with the right attributes (`#[IsReadOnly]`, `#[IsIdempotent]`) and a Schema DSL skeleton, plus a comment block telling you exactly where to add your registration. It does NOT auto-register the tool. That's deliberate, so you keep full control over which event listener owns the registration.

`craftcms/generator` is a `require-dev` dependency on Herald but ships with `craftcms/cms`, so it's always available in dev environments.

## Registering a tool

A tool is a class implementing `craftpulse\herald\tools\ToolInterface`. The interface:

```php
use craft\elements\User;

interface ToolInterface
{
    // Static metadata + boot-time gating.
    public static function getName(): string;
    public static function getDescription(): string;
    public static function getInputSchema(): array;
    public static function outputSchema(): array;
    public static function shouldRegister(): bool;

    // Per-request, per-user gating (HTTP transport).
    public function filterFor(?User $user = null): bool;
    public function inputSchemaFor(?User $user = null): array;

    // Invocation.
    public function execute(array $arguments): array|\Generator;
}
```

`AbstractTool` provides defaults for `getInputSchema` (object with no properties), `outputSchema` (`[]`, no schema declared), `shouldRegister` (`true`), `filterFor` (`true`, visible to everyone), and `inputSchemaFor` (delegates to the static `getInputSchema()`). Subclassing it leaves you to implement `getName`, `getDescription`, and `execute`; override the three gating methods only when your tool needs edition or permission gating.

### The three-method gating contract

`shouldRegister`, `filterFor`, and `inputSchemaFor` form the locked tool-visibility contract. Each runs at a different point in the request lifecycle:

| Method | When | Scope | Purpose |
|--------|------|-------|---------|
| `shouldRegister(): bool` (static) | Once at boot | Whole install | Edition / license / settings gating. Returns `false` to remove the tool from the registry entirely, so no user ever sees it. A Free install returns `false` for Pro tools; `craft_exec` registers but rejects at `execute()` when `execEnabled` is off. |
| `filterFor(?User $user): bool` | Every `tools/list` and `tools/call` | Per request, per user | Whether this user sees / can resolve the tool. Default `true`. Pro tools override to consult Craft permissions. |
| `inputSchemaFor(?User $user): array` | Every `tools/list` | Per request, per user | The schema this user sees. Default delegates to `getInputSchema()`. Mode-gated tools override to filter the `mode` enum by permission. |

**stdio vs HTTP.** stdio is a single trusted local process with no per-request identity, so the dispatcher passes `null` to `filterFor()` / `inputSchemaFor()` and the default-true / static-schema path applies, so `asListPayloadFor(null)` is identical to `asListPayload()`. The HTTP transport resolves the bearer/OAuth user and passes it through, so per-user filtering and schema rewriting fire.

**`execute()` re-checks regardless.** `filterFor()` filtering exists for the LLM's tool-selection UX; it is *not* the security boundary. `execute()` performs its own permission check on every call (defense in depth), and both layers fail closed. A tool that is hidden from a user's `tools/list` and also rejected on direct `tools/call` is the correct, redundant outcome.

Minimal example:

```php
<?php

namespace mywishingwell\plugin\tools;

use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;

#[Title('Get Wish')]
#[IsReadOnly]
#[IsIdempotent]
class GetWish extends AbstractTool
{
    public static function getName(): string
    {
        return 'get_wish';
    }

    public static function getDescription(): string
    {
        return 'Return the wish the user has been holding back. ' .
            'Returns the wish text and the date it was made.';
    }

    public static function getInputSchema(): array
    {
        return Schema::object([
            'wisher' => Schema::string()
                ->description('The user handle whose wish to fetch.')
                ->required(),
        ])->toArray();
    }

    public function execute(array $arguments): array
    {
        // … fetch and return.
        return ['wish' => '…', 'made_at' => '…'];
    }
}
```

Register from your plugin's `init()`:

```php
use craftpulse\herald\events\RegisterToolsEvent;
use craftpulse\herald\services\Tools;
use yii\base\Event;

Event::on(
    Tools::class,
    Tools::EVENT_REGISTER_TOOLS,
    function (RegisterToolsEvent $event): void {
        $event->tools[] = new GetWish();
    },
);
```

The event fires once during herald's boot, after the bundled registry is built. Listeners append `ToolInterface` instances to `$event->tools`. **First registration wins** on name collision, so bundled herald tools always trump shadowing attempts. Collisions surface a `Craft::warning()` line on the `herald` log channel so a third-party author can spot when their tool is being shadowed.

## Registering a prompt

Prompts implement `craftpulse\herald\prompts\PromptInterface`:

```php
interface PromptInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getArguments(): array;
    public function render(array $arguments): array;
}
```

`AbstractPrompt` provides a default `getArguments(): []`. Concrete prompts implement the rest.

Register on `services\Prompts::EVENT_REGISTER_PROMPTS`:

```php
use craftpulse\herald\events\RegisterPromptsEvent;
use craftpulse\herald\services\Prompts;

Event::on(
    Prompts::class,
    Prompts::EVENT_REGISTER_PROMPTS,
    function (RegisterPromptsEvent $event): void {
        $event->prompts[] = new MyPlugin\Prompts\BestPractices();
    },
);
```

Bundled prompts live under the `craftcms_*` namespace. Third-party prompts must use a different prefix to avoid future collisions. See [Naming conventions](#naming-conventions) below.

## Registering a resource

Resources implement `craftpulse\herald\resources\ResourceInterface`:

```php
interface ResourceInterface
{
    public function getUri(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getMimeType(): string;
    public function read(): array;
}
```

`AbstractResource` is provided. Each resource exposes ONE concrete URI.

Register on `services\Resources::EVENT_REGISTER_RESOURCES`:

```php
use craftpulse\herald\events\RegisterResourcesEvent;
use craftpulse\herald\services\Resources;
use yii\base\Event;

Event::on(
    Resources::class,
    Resources::EVENT_REGISTER_RESOURCES,
    function (RegisterResourcesEvent $event): void {
        $resources = $event->resources;

        $event->resources[] = new MyPlugin\Resources\StyleGuide();
    },
);
```

`$event->resources` accepts both `ResourceInterface` and `ResourceTemplateInterface` instances; the registry routes by `instanceof` at boot, so one listener can contribute concrete resources and templates together.

Bundled resources use the `craft-skills://` URI scheme, and `custom-skills://` is reserved for Herald's own Skill element type. Third-party plugins must use a plugin-specific scheme (`seo://`, `commerce-docs://`, `<vendor>-<topic>://`) to avoid collisions.

## Resource templates (dynamic URIs)

For URI families rather than concrete URIs (e.g. "any entry by id"), implement `craftpulse\herald\resources\ResourceTemplateInterface`:

```php
interface ResourceTemplateInterface
{
    public function getUriTemplate(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getMimeType(): string;
    public function matches(string $uri): ?array;
    public function read(string $uri, array $captures): array;
}
```

URI templates use RFC 6570 Level-1 simple substitution: literal characters plus `{name}` placeholders. Example: `craft-element://entries/{id}`.

Register through the same event as concrete resources: `RegisterResourcesEvent::$resources` accepts both `ResourceInterface` and `ResourceTemplateInterface` instances. The Herald registry routes by `instanceof` at boot.

`matches()` returns the captured-parameter map for a successful match (or null on miss). `read()` is invoked with the same map after the dispatcher confirms a match. Concrete-URI resources are checked first; templates only fire on miss, so a template can't shadow a concrete resource.

## The Schema DSL

Herald includes a fluent JSON Schema builder for tool input schemas. It generates the same JSON Schema array MCP clients expect, just nicer to author than raw arrays.

Static entry points on `craftpulse\herald\tools\support\Schema`:

| Entry point | Purpose |
|------------|---------|
| `Schema::string()` | String type. |
| `Schema::integer()` | Integer type. |
| `Schema::number()` | Number (int or float). |
| `Schema::boolean()` | Boolean. |
| `Schema::null()` | Null. |
| `Schema::array(?$items)` | Array. Optional items schema. |
| `Schema::object($properties = [])` | Object with properties. |
| `Schema::any()` | Any type; the escape hatch. |
| `Schema::constant($value)` | Literal value. |
| `Schema::anyOf(...$schemas)` | Union: at least one matches. |
| `Schema::oneOf(...$schemas)` | Exclusive union: exactly one matches. |
| `Schema::allOf(...$schemas)` | Intersection: all match. |
| `Schema::not($schema)` | Negation. |

Fluent setters apply to any schema:

| Setter | Purpose |
|--------|---------|
| `description(string)` | Human-readable field description. |
| `required()` | When called on a property of an object, adds the property to the parent's `required` array. |
| `default($value)` | Default value. |
| `enum($array)` | Restrict to a value list. |
| `format(string)` | JSON Schema format hint (`email`, `date-time`, …). |
| `pattern(string)` | Regex pattern (strings). |
| `minLength`/`maxLength`/`minimum`/`maximum`/`minItems`/`maxItems`/`uniqueItems` | Standard constraints. |
| `items(self)` | Items schema (arrays). |
| `properties(array)` | Property schemas (objects). |
| `additionalProperties(bool|self)` | Whether extra properties are allowed. |
| `examples(array)` | Example values for documentation. |

Output: `toArray(): array` returns the JSON Schema array.

Example:

```php
Schema::object([
    'handle' => Schema::string()
        ->description('Section handle.')
        ->required(),
    'mode' => Schema::string()
        ->description('How to interpret the request.')
        ->enum(['list', 'get', 'count'])
        ->default('list'),
    'limit' => Schema::integer()
        ->description('Max items to return.')
        ->minimum(1)
        ->maximum(1000)
        ->default(100),
])->toArray();
```

The DSL is part of the locked public surface. Adding new keywords is additive (backwards-compatible); the `toArray()` wire shape stays stable.

## Tool annotations (attributes)

Herald uses PHP 8 attributes to declare MCP tool annotations at the class level. These map to the `annotations` field in `tools/list` per MCP spec 2025-06-18.

| Attribute | MCP key | Default | Purpose |
|-----------|---------|---------|---------|
| `#[IsReadOnly]` | `readOnlyHint` | `true` | Tool doesn't modify any state. |
| `#[IsDestructive]` | `destructiveHint` | `true` | Tool may delete or destroy data. |
| `#[IsIdempotent]` | `idempotentHint` | `true` | Re-running with the same args produces the same effect. |
| `#[IsOpenWorld]` | `openWorldHint` | `true` | Tool reaches outside the local system (network, etc.). |
| `#[IsStdioOnly]` | (herald-specific) | `true` | Tool is rejected on the HTTP transport regardless of token scope. |
| `#[Title('…')]` | `title` | (none) | Human-readable label shown in client UIs. |

Pass `false` to override the default explicitly: `#[IsIdempotent(false)]`. The `Is*` prefix mirrors Laravel MCP's pattern and avoids collision with PHP's `readonly` keyword.

Defaults are `true` because the common case is "this tool IS read-only" / "this tool IS idempotent". Pass `false` only when you mean it.

## Edition gating and conditional registration

`shouldRegister(): bool` is a **static** method that runs once at boot. It gates whole-tool registration on edition / license / settings, *not* on the current user (there is no user at boot). A Pro-only tool returns `false` on Free and never enters the registry; `AbstractTool` returns `true` so the default is "always register".

The bundled Pro tools never hand-write the edition check. They `use ProToolTrait`, which supplies a Pro-gated `shouldRegister()`:

```php
trait ProToolTrait
{
    public static function shouldRegister(): bool
    {
        return Herald::getInstance()->is(Herald::EDITION_PRO, '>=');
    }
}
```

Per-user visibility is a *separate* concern, handled per-request by `filterFor()` (and schema-rewriting by `inputSchemaFor()`). See [the three-method gating contract](#the-three-method-gating-contract) above. The bundled `PermissionedToolTrait` carries the in-`execute()` permission re-check.

A real Pro tool composes both traits. Abridged from `craftpulse\herald\tools\content\Category`:

```php
use craft\elements\User;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\ToolException;

class Category extends AbstractTool
{
    use PermissionedToolTrait; // _assertPermission(): in-execute() re-check.
    use ProToolTrait;          // shouldRegister(): Pro-only registration.

    // Per-user visibility: hide the tool from users with no
    // save/delete permission on any category group. stdio (null) and
    // admins always see it.
    public function filterFor(?User $user = null): bool
    {
        if ($user === null || $user->admin) {
            return true;
        }
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($user->can("saveCategories:{$group->uid}")
                || $user->can("deleteCategories:{$group->uid}")) {
                return true;
            }
        }
        return false;
    }

    // The permissions the resolved arguments imply. PermissionedToolTrait
    // walks this list in _assertPermission() and throws ToolException on a
    // miss. The wildcard sentinel (e.g. `saveCategories:*`) is used by
    // filterFor()-style probing only and must be resolved to a concrete
    // group UID before execute().
    protected function _requiredPermissions(array $arguments): array
    {
        $groupUid = $this->_resolveGroupUid($arguments);
        return $groupUid === null
            ? ['saveCategories:*']
            : ["saveCategories:{$groupUid}"];
    }

    public function execute(array $arguments): array
    {
        // Re-check after resolving the per-resource UID: defense in
        // depth, independent of filterFor()'s tools/list filtering.
        $this->_assertPermission($arguments);
        // … resolve, mutate, save.
    }
}
```

### Error contract

Herald distinguishes **protocol errors** from **tool errors**, and they take different wire shapes:

- **Protocol errors** (unknown tool name, missing `name`, malformed params) come back as a JSON-RPC error response. The code is `-32602` (Invalid params) for an unknown / hidden tool, `-32601` for an unknown method, `-32600` for a malformed envelope, `-32603` for an internal error. A tool that `filterFor()` hides is indistinguishable from a missing one: `tools/call` against it returns `-32602`, failing closed.
- **Tool errors**: a `ToolException` thrown from `execute()` (permission denial, bad arguments, not-found) comes back as a **successful** JSON-RPC response carrying the MCP tool-error envelope:

  ```json
  {
    "content": [{ "type": "text", "text": "permission denied: …" }],
    "isError": true
  }
  ```

  There is **no** `-32002` code anywhere in this path. The locked decision routes every tool-level failure through the `isError: true` envelope so the LLM can read the message and self-correct rather than treating it as a transport fault. Throw `ToolException` for anything the caller could fix; let other exceptions propagate (the dispatcher converts them to a `-32603` internal error and logs the real cause).

## Observing invocations

Herald provides a collection of events for extending its functionality. Modules and plugins can register event listeners, typically in their `init()` methods, to modify Herald's behavior.

### The `logCall` event

Fired by `craftpulse\herald\tools\support\InvocationLogger::logCall()` after the formatted log line has been written to Craft's logger, once per tool invocation on both transports. Subscribe to mirror invocations into an alternate audit surface: a SIEM forwarder, a metrics counter, a per-tenant usage table. Herald's own `herald_invocations` table is a subscriber to this event, so anything you build sits alongside it rather than under it.

Treat the handler as soft. A thrown exception is caught by the dispatcher's wrapper, but a listener that raises on logging activity still costs the dispatch hot path, so keep the work small and defer anything expensive to a queue job.

```php
use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\tools\support\InvocationLogger;
use yii\base\Event;

Event::on(
    InvocationLogger::class,
    InvocationLogger::EVENT_LOG_CALL,
    function (LogCallEvent $event): void {
        $entry = $event->entry;
        $line = $event->line;
        // ...
    },
);
```

`$entry` is the structured field map, and `$line` is the formatted key-value string exactly as it was written to the logger, provided so a forwarder pinned to the wire format does not have to re-run the formatter.

The keys on `$entry`:

| Key | Description |
|---|---|
| `tool` | The invoked tool's MCP name. |
| `kind` | Outcome: `success`, `tool_error`, `internal_error`, `cancelled`, or `rate_limited`. |
| `duration_ms` | Wall-clock duration of the invocation, as an integer. |
| `transport` | `stdio` or `http`. |
| `request_id` | The JSON-RPC request id, or `-` when absent. |
| `user` | The authenticated Craft user id over HTTP, or `-` on stdio. |
| `client` | The client name from the MCP `initialize` handshake, or `-`. |
| `args` | The tool's arguments, secret-redacted and JSON-encoded. |
| `error_class` | The exception class for a failed invocation. |
| `error_message` | The exception message, control-character-stripped and collapsed to one line. |
| `token_id` | The credential the request authenticated with, for audit correlation. |
| `session_id` | The MCP session the invocation belongs to. |
| `response_excerpt` | The redacted, length-bounded response excerpt. |

Adding a key is backward-compatible, because subscribers ignore keys they do not recognise. Renaming or removing one is a contract bump.

## Naming conventions

The locked tool/prompt/resource namespaces:

- **Tool names**: bundled tools follow a deliberate pattern. Listing tools use plural nouns (`sections`, `entries`); multi-mode introspection tools use the most descriptive name (`system_diagnostics`, `content_audit`); workflow tools use combined direction (`drafts_and_revisions`, `import_export`). Action verbs for write tools (`resave`, `clear_caches`). Third-party tools should choose a vendor-prefixed handle (`<vendor>_<purpose>`) to avoid future collisions, especially for additions Herald itself might make.
- **Prompt names**: bundled prompts use the `craftcms_*` prefix (`craftcms_extending`, `craftcms_templates`, …). The `custom_*` prefix is reserved for Herald's own Skill element type. Third-party plugins should use a plugin-specific prefix (`<vendor>_<purpose>`).
- **Resource URI schemes**: `craft-skills://` is bundled; `custom-skills://` is reserved for Herald's own Skill element type. Third-party plugins should use a plugin-specific scheme.

## Collision behaviour

When a name / URI collides between two registrations, **first registration wins**. Herald registers bundled tools / prompts / resources before firing the registration events, so third-party plugins cannot shadow built-ins.

Collisions are not silent. The registry logs `Craft::warning()` on the `herald` channel:

```
Tool name collision on "sections": first registration (craftpulse\herald\tools\schema\Sections) wins; ignoring mywishingwell\plugin\tools\Sections.
```

If you see this in your logs while developing, rename your tool. Suppressing the collision (e.g. running before bundled tools register) is unsupported, because bundled herald registrations are load-bearing for the tool catalogue.

## Testing your extension

Herald exposes the same testing helpers it uses internally. The recommended setup: Pest tests against a real Craft instance with Herald installed.

A minimal test for a custom tool:

```php
use craftpulse\herald\Herald;

it('registers and dispatches my custom tool', function () {
    $tool = Herald::getInstance()->tools->getByName('get_wish');
    expect($tool)->not->toBeNull();

    $result = $tool->execute(['wisher' => 'pest']);
    expect($result)->toHaveKey('wish');
});
```

For tools that wrap MCP-spec details (annotations, output schemas), assert against `Tools::asListPayload()` to verify the wire shape clients see.

```php
it('appears in the registry with the right annotations', function () {
    $payload = Herald::getInstance()->tools->asListPayload();
    $entry = collect($payload)->firstWhere('name', 'get_wish');

    expect($entry)
        ->toBeMcpToolListItem()
        ->and($entry['annotations']['readOnlyHint'])->toBeTrue();
});
```

If you're testing `craft_command`-allowlist-aware tools or anything stdio-vs-HTTP, the full set of `mcp/Server` test helpers (transport-aware dispatch, error envelopes, etc.) lives in the herald test suite, and your plugin can mimic the patterns there.
