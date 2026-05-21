# Extending Cortex

Cortex is built around a stable, versioned extension surface. Third-party plugins can register their own tools, prompts, and resources and have them appear alongside the bundled ones in `tools/list`, `prompts/list`, and `resources/list`.

This document covers everything you need to ship an extension. The interfaces, attributes, events, and Schema DSL listed here are part of Cortex's locked public surface — once Phase 1 ships, they don't change without a deprecation cycle or a major version bump.

- [Quick start](#quick-start)
- [The generator](#the-generator)
- [Registering a tool](#registering-a-tool)
- [Registering a prompt](#registering-a-prompt)
- [Registering a resource](#registering-a-resource)
- [Resource templates (dynamic URIs)](#resource-templates-dynamic-uris)
- [The Schema DSL](#the-schema-dsl)
- [Tool annotations (attributes)](#tool-annotations-attributes)
- [Edition gating and conditional registration](#edition-gating-and-conditional-registration)
- [Naming conventions](#naming-conventions)
- [Collision behaviour](#collision-behaviour)
- [Testing your extension](#testing-your-extension)

## Quick start

The fastest path: scaffold a tool with the generator, then register it from your plugin's `init()`.

```bash
ddev craft make cortex-tool
```

Answer the prompts (class name, namespace, MCP tool name) and the generator drops a stub class with the right attributes and Schema DSL boilerplate. It also prints the registration snippet for your plugin's `init()`.

Then in your plugin's main class:

```php
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\services\Tools;
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

That's the whole extension surface. Cortex picks up the tool at boot, validates it implements `ToolInterface`, and exposes it to MCP clients.

## The generator

Cortex hooks into Craft's `make` command via `craftcms/generator`'s `EVENT_REGISTER_GENERATORS`. Run from any Craft project where Cortex is installed:

```bash
ddev craft make cortex-tool
```

The generator prompts for:

- **Class name** — e.g. `GetWish`
- **Namespace** — defaults to your plugin's `tools/` directory
- **MCP tool name** — the `name` field MCP clients see (e.g. `get_wish`)

It writes a stub class extending `AbstractTool` with the right attributes (`#[IsReadOnly]`, `#[IsIdempotent]`) and a Schema DSL skeleton, plus a `// TODO` comment block telling you exactly where to add your registration. It does NOT auto-register the tool — that's deliberate, so you keep full control over which event listener owns the registration.

`craftcms/generator` is a `require-dev` dependency on Cortex but ships with `craftcms/cms`, so it's always available in dev environments.

## Registering a tool

A tool is a class implementing `craftpulse\cortex\tools\ToolInterface`. The interface:

```php
interface ToolInterface
{
    public static function getName(): string;
    public static function getDescription(): string;
    public static function getInputSchema(): array;
    public static function outputSchema(): array;
    public function shouldRegister(): bool;
    public function execute(array $arguments): array|\Generator;
}
```

`AbstractTool` provides defaults for `getInputSchema` (object with no properties), `outputSchema` (`[]` — no schema declared), and `shouldRegister` (`true`). Subclassing it leaves you to implement `getName`, `getDescription`, and `execute`.

Minimal example:

```php
<?php

namespace mywishingwell\plugin\tools;

use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\Schema;

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
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\services\Tools;
use yii\base\Event;

Event::on(
    Tools::class,
    Tools::EVENT_REGISTER_TOOLS,
    function (RegisterToolsEvent $event): void {
        $event->tools[] = new GetWish();
    },
);
```

The event fires once during cortex's boot, after the bundled registry is built. Listeners append `ToolInterface` instances to `$event->tools`. **First registration wins** on name collision — bundled cortex tools always trump shadowing attempts. Collisions surface a `Craft::warning()` line on the `cortex` log channel so a third-party author can spot when their tool is being shadowed.

## Registering a prompt

Prompts implement `craftpulse\cortex\prompts\PromptInterface`:

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
use craftpulse\cortex\events\RegisterPromptsEvent;
use craftpulse\cortex\services\Prompts;

Event::on(
    Prompts::class,
    Prompts::EVENT_REGISTER_PROMPTS,
    function (RegisterPromptsEvent $event): void {
        $event->prompts[] = new MyPlugin\Prompts\BestPractices();
    },
);
```

Bundled prompts live under the `craftcms_*` namespace. Third-party prompts must use a different prefix to avoid future collisions — see [Naming conventions](#naming-conventions) below.

## Registering a resource

Resources implement `craftpulse\cortex\resources\ResourceInterface`:

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

Register on `services\Resources::EVENT_REGISTER_RESOURCES`. Same pattern as tools and prompts.

Bundled resources use the `craft-skills://` URI scheme. The `custom-skills://` scheme is reserved for the Pro custom-skills element type (Phase 2). Third-party plugins must use a plugin-specific scheme — `seo://`, `commerce-docs://`, `<vendor>-<topic>://` — to avoid future collisions.

## Resource templates (dynamic URIs)

For URI families rather than concrete URIs (e.g. "any entry by id"), implement `craftpulse\cortex\resources\ResourceTemplateInterface`:

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

Register through the same event as concrete resources — `RegisterResourcesEvent::$resources` accepts both `ResourceInterface` and `ResourceTemplateInterface` instances. The Cortex registry routes by `instanceof` at boot.

`matches()` returns the captured-parameter map for a successful match (or null on miss). `read()` is invoked with the same map after the dispatcher confirms a match. Concrete-URI resources are checked first; templates only fire on miss, so a template can't shadow a concrete resource.

Phase 1 ships the interface; no Phase 1 resource implements it. The first concrete consumer is Pro Gate 8.5 (custom-skills element type).

## The Schema DSL

Cortex includes a fluent JSON Schema builder for tool input schemas. It generates the same JSON Schema array MCP clients expect — just nicer to author than raw arrays.

Static entry points on `craftpulse\cortex\tools\support\Schema`:

| Entry point | Purpose |
|------------|---------|
| `Schema::string()` | String type. |
| `Schema::integer()` | Integer type. |
| `Schema::number()` | Number (int or float). |
| `Schema::boolean()` | Boolean. |
| `Schema::null()` | Null. |
| `Schema::array(?$items)` | Array. Optional items schema. |
| `Schema::object($properties = [])` | Object with properties. |
| `Schema::any()` | Any type — escape hatch. |
| `Schema::constant($value)` | Literal value. |
| `Schema::anyOf(...$schemas)` | Union — at least one matches. |
| `Schema::oneOf(...$schemas)` | Exclusive union — exactly one matches. |
| `Schema::allOf(...$schemas)` | Intersection — all match. |
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

Cortex uses PHP 8 attributes to declare MCP tool annotations at the class level. These map to the `annotations` field in `tools/list` per MCP spec 2025-06-18.

| Attribute | MCP key | Default | Purpose |
|-----------|---------|---------|---------|
| `#[IsReadOnly]` | `readOnlyHint` | `true` | Tool doesn't modify any state. |
| `#[IsDestructive]` | `destructiveHint` | `true` | Tool may delete or destroy data. |
| `#[IsIdempotent]` | `idempotentHint` | `true` | Re-running with the same args produces the same effect. |
| `#[IsOpenWorld]` | `openWorldHint` | `true` | Tool reaches outside the local system (network, etc.). |
| `#[IsStdioOnly]` | (cortex-specific) | `true` | Tool is rejected on the HTTP transport regardless of token scope. |
| `#[Title('…')]` | `title` | (none) | Human-readable label shown in client UIs. |

Pass `false` to override the default explicitly: `#[IsIdempotent(false)]`. The `Is*` prefix mirrors Laravel MCP's pattern and avoids collision with PHP's `readonly` keyword.

Defaults are `true` because the common case is "this tool IS read-only" / "this tool IS idempotent" — pass `false` only when you mean it.

## Edition gating and conditional registration

`shouldRegister(): bool` lets a tool opt out of the registry per-request. Phase 1 always returns `true` from `AbstractTool`. Pro tools (Phase 2) override to gate visibility on Craft permissions:

```php
public function shouldRegister(): bool
{
    $user = Craft::$app->getUser()->getIdentity();
    if ($user === null) {
        return false;
    }
    return $user->can('saveEntries:' . $this->_section->uid);
}
```

A user without `saveEntries:{section}` doesn't see the corresponding mode surfaces in `tools/list`, and `tools/call` rejects with -32602 if they try to invoke directly.

This is the right place to gate Pro / Free tier visibility too — a Pro-only tool returns `false` on Free and never appears in the bundled registry.

## Naming conventions

The locked tool/prompt/resource namespaces:

- **Tool names** — bundled tools follow a deliberate pattern. Listing tools use plural nouns (`sections`, `entries`); multi-mode introspection tools use the most descriptive name (`system_diagnostics`, `content_audit`); workflow tools use combined direction (`drafts_and_revisions`, `import_export`). Action verbs for write tools (`resave`, `clear_caches`). Third-party tools should choose a vendor-prefixed handle (`<vendor>_<purpose>`) to avoid future collisions, especially for additions Cortex itself might make.
- **Prompt names** — bundled prompts use the `craftcms_*` prefix (`craftcms_extending`, `craftcms_templates`, …). The `custom_*` prefix is reserved for the Pro custom-skills feature. Third-party plugins should use a plugin-specific prefix (`<vendor>_<purpose>`).
- **Resource URI schemes** — `craft-skills://` is bundled; `custom-skills://` is reserved. Third-party plugins should use a plugin-specific scheme.

## Collision behaviour

When a name / URI collides between two registrations, **first registration wins**. Cortex registers bundled tools / prompts / resources before firing the registration events, so third-party plugins cannot shadow built-ins.

Collisions are not silent. The registry logs `Craft::warning()` on the `cortex` channel:

```
Tool name collision on "sections" — first registration (craftpulse\cortex\tools\schema\Sections) wins; ignoring mywishingwell\plugin\tools\Sections.
```

If you see this in your logs while developing, rename your tool. Suppressing the collision (e.g. running before bundled tools register) is unsupported — bundled cortex registrations are load-bearing for the tool catalogue.

## Testing your extension

Cortex exposes the same testing helpers it uses internally. The recommended setup: Pest tests against a real Craft instance with Cortex installed.

A minimal test for a custom tool:

```php
use craftpulse\cortex\Cortex;

it('registers and dispatches my custom tool', function () {
    $tool = Cortex::getInstance()->tools->getByName('get_wish');
    expect($tool)->not->toBeNull();

    $result = $tool->execute(['wisher' => 'pest']);
    expect($result)->toHaveKey('wish');
});
```

For tools that wrap MCP-spec details (annotations, output schemas), assert against `Tools::asListPayload()` to verify the wire shape clients see.

```php
it('appears in the registry with the right annotations', function () {
    $payload = Cortex::getInstance()->tools->asListPayload();
    $entry = collect($payload)->firstWhere('name', 'get_wish');

    expect($entry)
        ->toBeMcpToolListItem()
        ->and($entry['annotations']['readOnlyHint'])->toBeTrue();
});
```

If you're testing `craft_command`-allowlist-aware tools or anything stdio-vs-HTTP, the full set of `mcp/Server` test helpers (transport-aware dispatch, error envelopes, etc.) lives in the cortex test suite — your plugin can mimic the patterns there.
