<?php

namespace craftpulse\herald\tools\system;

use Craft;
use craft\base\UtilityInterface;
use craft\web\twig\variables\CraftVariable;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use ReflectionClass;
use Twig\TwigFilter;
use Twig\TwigFunction;
use yii\base\Event;

/**
 * =========================================================================
 * `extensibility` tool — what's plugged into Craft beyond stock.
 *
 * Combined inventory of the four "what's been registered?" surfaces:
 *
 *   - **events** — Yii's class-level `Event::on()` registrations (an
 *     incomplete view: instance-level handlers and bootstrap-time
 *     subscriptions don't appear, but the static registry covers most
 *     plugin extension points).
 *   - **twig** — custom `craft.*` variable behaviours, plus user-
 *     registered Twig functions and filters.
 *   - **utilities** — registered CP utility classes.
 *   - **commands** — every console controller / action available via
 *     `php craft <name>`.
 *
 * Modes:
 *   - default: all four sections.
 *   - `mode: "events" | "twig" | "utilities" | "commands"`: just one.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Extensibility extends AbstractTool
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'extensibility';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Inventory of registered extensibility points: class-level events, Twig ' .
            'extensions (functions, filters, custom craft.* variables), CP utilities, and ' .
            'console commands. Pass `mode: "events" | "twig" | "utilities" | "commands"` ' .
            'for one section, or omit for the full map.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['events', 'twig', 'utilities', 'commands'])
                ->description('Limit to one section. Omit for all.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments);

        if ($mode === null) {
            return [
                'events' => $this->_events(),
                'twig' => $this->_twig(),
                'utilities' => $this->_utilities(),
                'commands' => $this->_commands(),
            ];
        }

        return match ($mode) {
            'events' => ['events' => $this->_events()],
            'twig' => ['twig' => $this->_twig()],
            'utilities' => ['utilities' => $this->_utilities()],
            'commands' => ['commands' => $this->_commands()],
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * Read Yii's class-level event registry via reflection. The handler
     * data lives in a private static `Event::$_events` (and its wildcard
     * sibling); we project it into a stable list shape.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _events(): array
    {
        $registry = [];

        try {
            $reflection = new ReflectionClass(Event::class);
            $prop = $reflection->getProperty('_events');
            $prop->setAccessible(true);
            $events = $prop->getValue();

            foreach ((array) $events as $eventName => $byClass) {
                if (!is_array($byClass)) {
                    continue;
                }
                foreach ($byClass as $class => $handlers) {
                    if (!is_array($handlers)) {
                        continue;
                    }
                    foreach ($handlers as $handlerEntry) {
                        $handler = $handlerEntry[0] ?? null;
                        $registry[] = [
                            'event' => $eventName,
                            'class' => $class,
                            'handler' => $this->_describeHandler($handler),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Reflection failures should not crash the tool.
            return [];
        }

        return $registry;
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _twig(): array
    {
        $env = Craft::$app->getView()->getTwig();

        $functions = [];
        foreach ($env->getFunctions() as $fn) {
            /** @var TwigFunction $fn */
            $functions[] = ['name' => $fn->getName()];
        }

        $filters = [];
        foreach ($env->getFilters() as $f) {
            /** @var TwigFilter $f */
            $filters[] = ['name' => $f->getName()];
        }

        $globals = $env->getGlobals();
        $globalNames = array_keys($globals);
        sort($globalNames);

        $craftVariableMethods = [];
        try {
            $craftVariable = $globals['craft'] ?? null;
            if ($craftVariable instanceof CraftVariable) {
                $reflection = new ReflectionClass($craftVariable);
                foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isConstructor() || $method->isStatic()) {
                        continue;
                    }
                    if ($method->getDeclaringClass()->getName() !== CraftVariable::class) {
                        // Skip parent-class methods (Component, BaseObject).
                        continue;
                    }
                    $craftVariableMethods[] = $method->getName();
                }
                sort($craftVariableMethods);
            }
        } catch (\Throwable $e) {
            // Reflection failures are non-fatal here.
        }

        return [
            'functions' => $functions,
            'filters' => $filters,
            'globals' => $globalNames,
            'craftVariableMethods' => $craftVariableMethods,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _utilities(): array
    {
        $types = Craft::$app->getUtilities()->getAllUtilityTypes();

        $out = [];
        foreach ($types as $class) {
            /** @var class-string<UtilityInterface> $class */
            $out[] = [
                'class' => $class,
                'id' => $class::id(),
                'displayName' => $class::displayName(),
                'iconPath' => $class::icon(),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _commands(): array
    {
        $app = Craft::$app;

        if (!$app instanceof \craft\console\Application) {
            // We're not in a console context — controller map only contains
            // web controllers. Return what we can without failing.
            return [];
        }

        $controllerMap = $app->controllerMap;
        $coreControllers = $app->coreCommands();

        $out = [];
        foreach ($controllerMap as $id => $config) {
            $class = is_array($config) ? ($config['class'] ?? null) : $config;
            $out[] = [
                'id' => $id,
                'class' => is_string($class) ? $class : null,
            ];
        }

        foreach ($coreControllers as $id) {
            $out[] = ['id' => $id, 'class' => null];
        }

        // Deduplicate by id.
        $seen = [];
        $deduped = [];
        foreach ($out as $entry) {
            $key = $entry['id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $entry;
        }

        return $deduped;
    }

    /**
     * Render an event-handler callable into a stable string for output.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _describeHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $target = $handler[0];
            $method = $handler[1];
            $targetName = is_object($target) ? $target::class : (string) $target;
            return $targetName . '::' . (string) $method;
        }

        if ($handler instanceof \Closure) {
            return 'Closure';
        }

        if (is_object($handler)) {
            return $handler::class;
        }

        return 'unknown';
    }
}
