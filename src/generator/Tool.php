<?php

namespace craftpulse\herald\generator;

use craft\generator\BaseGenerator;
use craft\helpers\StringHelper;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use Nette\PhpGenerator\PhpNamespace;

/**
 * =========================================================================
 * Creates a new herald MCP tool.
 *
 * Hooks into Craft's `make` system per the `EVENT_REGISTER_GENERATORS`
 * pattern so users run `ddev craft make herald-tool` (or
 * `craft make herald-tool` outside DDEV) to scaffold a new tool against
 * the herald tool surface — `AbstractTool` parent, Schema DSL for
 * input, attribute-based annotations.
 *
 * The generator prompts for:
 *   - Class name (PascalCase, e.g. `MyTool`)
 *   - Namespace (defaults to `<plugin-namespace>\tools`)
 *   - MCP tool name (snake_case; defaults to `my_tool` from class name)
 *
 * After generation the user must register the tool either through their
 * plugin's `Herald::init()` via `EVENT_REGISTER_TOOLS`, or directly in
 * herald's own `services/Tools::_buildRegistry()` if extending core.
 * The generator prints the registration snippet on success.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Tool extends BaseGenerator
{
    // Private Properties
    // =========================================================================

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private string $_className;

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private string $_namespace;

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private string $_toolName;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function name(): string
    {
        return 'herald-tool';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function description(): string
    {
        return 'Creates a new herald MCP tool.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function run(): bool
    {
        $this->_className = $this->classNamePrompt('Tool name (PascalCase, e.g. "MyTool"):', [
            'required' => true,
        ]);

        $namespace = $this->namespacePrompt('Tool namespace:', [
            'default' => "{$this->baseNamespace}\\tools",
        ]);
        if ($namespace === null) {
            return false;
        }
        $this->_namespace = $namespace;

        $defaultToolName = $this->_toSnakeCase($this->_className);
        $promptResult = $this->command->prompt(
            "MCP tool name (snake_case) [{$defaultToolName}]:",
            ['default' => $defaultToolName],
        );
        $this->_toolName = is_string($promptResult) && $promptResult !== ''
            ? $promptResult
            : $defaultToolName;

        $namespace = (new PhpNamespace($this->_namespace))
            ->addUse(IsReadOnly::class)
            ->addUse(IsIdempotent::class)
            ->addUse(AbstractTool::class)
            ->addUse(Schema::class);

        $class = $this->createClass($this->_className, AbstractTool::class, [
            self::CLASS_METHODS => ['execute'],
        ]);
        $namespace->add($class);

        $class->setComment(<<<COMMENT
=========================================================================
`{$this->_toolName}` tool — TODO: describe what this tool does in one
to three sentences. Answer "when would I call this?" — avoid
implementation detail; describe the user-visible effect.

Attributes: add `#[Title('Human Readable Name')]` for the display name
shown to clients, and swap/extend the behavioural attributes below as
the tool actually behaves — `#[IsDestructive]` if it mutates or deletes,
`#[IsOpenWorld]` if it reaches outside this Craft install,
`#[IsStdioOnly]` to forbid the HTTP transport. Defaults assume a
read-only, idempotent, closed-world tool.
=========================================================================

@author CraftPulse
@since  5.0.0
COMMENT);

        // Default attributes — most tools are read-only and idempotent.
        // Adjust per the attribute guidance in the class docblock above
        // if the tool mutates state, has side effects, or must be
        // stdio-only.
        $class->addAttribute(IsReadOnly::class);
        $class->addAttribute(IsIdempotent::class);

        $class->addMethod('getName')
            ->setStatic()
            ->setReturnType('string')
            ->setComment("@author CraftPulse\n@since  5.0.0")
            ->setBody("return '{$this->_toolName}';");

        $class->addMethod('getDescription')
            ->setStatic()
            ->setReturnType('string')
            ->setComment("@author CraftPulse\n@since  5.0.0")
            ->setBody("return 'TODO: describe this tool for the LLM.';");

        $class->addMethod('getInputSchema')
            ->setStatic()
            ->setReturnType('array')
            ->setComment("@inheritdoc\n\n@author CraftPulse\n@since  5.0.0")
            ->setBody(<<<'BODY'
return Schema::object([
    // TODO: declare your tool's input schema here.
])->toArray();
BODY);

        $class->getMethod('execute')
            ->setComment("@inheritdoc\n\n@author CraftPulse\n@since  5.0.0")
            ->setBody(<<<'BODY'
// TODO: implement. Return a JSON-serialisable associative array — the
// dispatcher wraps it in the MCP `content[]` envelope (and emits it as
// `structuredContent` when you declare an `outputSchema()`). Throw a
// `ToolException` for expected tool-level failures (bad input, missing
// entity, permission denied); the dispatcher renders those as an
// `isError: true` result the LLM can self-correct against. Let any
// other exception propagate — it becomes a JSON-RPC internal error.
// For long-running work, return a `\Generator` instead and yield
// progress frames (see `StreamableToolInterface`).
return [
    'ok' => true,
];
BODY);

        $this->writePhpClass($namespace);

        $serviceClass = '\\' . $this->_namespace . '\\' . $this->_className;
        $message = <<<MD
**Herald tool created!**

Register it in your plugin's `init()` so herald picks it up:

```
use craftpulse\\herald\\events\\RegisterToolsEvent;
use craftpulse\\herald\\services\\Tools;
use yii\\base\\Event;

Event::on(
    Tools::class,
    Tools::EVENT_REGISTER_TOOLS,
    function (RegisterToolsEvent \$event) {
        \$event->tools[] = new {$serviceClass}();
    },
);
```

Then run `ddev craft herald/serve` and your tool appears in `tools/list`.
MD;

        $this->command->success($message);
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Convert PascalCase to snake_case using Craft's StringHelper. Used
     * to derive a default MCP tool name from the user's class name.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _toSnakeCase(string $value): string
    {
        return StringHelper::toSnakeCase($value);
    }
}
