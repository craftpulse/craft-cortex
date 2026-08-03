<?php

namespace craftpulse\herald\tools\support;

use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsOpenWorld;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\attributes\IsStdioOnly;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\ToolInterface;
use ReflectionClass;

/**
 * =========================================================================
 * Reflection-based reader for tool annotation attributes.
 *
 * Tools advertise behavioural metadata through PHP 8 attributes
 * (`#[IsReadOnly]`, `#[IsDestructive]`, `#[Title('...')]`, …). This
 * reader walks the attributes on a tool class and produces:
 *
 *   - `annotationsFor()` — the MCP `ToolAnnotations` payload as the
 *     server emits it in `tools/list`.
 *   - `isStdioOnly()` — the herald-specific transport-gating flag the
 *     dispatcher checks before invoking a tool over HTTP.
 *
 * Both methods accept either a `ToolInterface` instance or a
 * fully-qualified class string — the registry has instances; the
 * dispatcher already resolved one too.
 *
 * Both memoize per class for the life of the process. Tool classes are
 * immutable once loaded, so a second read can only produce the same
 * answer, and the calls sit on hot paths: `annotationsFor()` runs once
 * per tool on every `tools/list`, which is the first thing every client
 * does on connect, and again per `tools/call` through the audit emitter.
 * Un-memoized that is five `ReflectionClass` constructions and five
 * `newInstance()` calls per tool per list.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
final class AttributeReader
{
    // Private Properties
    // =========================================================================

    /**
     * Per-class memoization of `annotationsFor()`. Keyed by class name
     * and populated even when the result is empty, so a tool that
     * declares no attributes stops re-reflecting too.
     *
     * @var array<class-string,array<string,bool|string>>
     */
    private static array $_annotationsCache = [];

    /**
     * Per-class memoization of `isStdioOnly()`. Tool classes are
     * immutable per-process — once we read the attribute once the
     * answer never changes — so a static cache trims the reflection
     * cost on every tool dispatch. `Server::dispatch()` calls
     * `isStdioOnly()` for every `tools/call` and uses the result to
     * 403 HTTP requests for stdio-gated tools.
     *
     * @var array<class-string,bool>
     */
    private static array $_stdioOnlyCache = [];

    // Public Methods
    // =========================================================================

    /**
     * Build the MCP `ToolAnnotations` payload from a tool's attribute
     * declarations. Absent attributes are simply not emitted; explicit
     * `#[Is*(false)]` emits the corresponding hint as `false`.
     *
     * Memoized per class — see `$_annotationsCache`.
     *
     * @param ToolInterface|class-string<ToolInterface> $toolOrClass
     * @return array<string,bool|string>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function annotationsFor(ToolInterface|string $toolOrClass): array
    {
        $class = is_string($toolOrClass) ? $toolOrClass : $toolOrClass::class;
        if (array_key_exists($class, self::$_annotationsCache)) {
            return self::$_annotationsCache[$class];
        }

        $rc = new ReflectionClass($toolOrClass);
        $annotations = [];

        $readOnly = $rc->getAttributes(IsReadOnly::class);
        if ($readOnly !== []) {
            $annotations['readOnlyHint'] = $readOnly[0]->newInstance()->value;
        }

        $destructive = $rc->getAttributes(IsDestructive::class);
        if ($destructive !== []) {
            $annotations['destructiveHint'] = $destructive[0]->newInstance()->value;
        }

        $idempotent = $rc->getAttributes(IsIdempotent::class);
        if ($idempotent !== []) {
            $annotations['idempotentHint'] = $idempotent[0]->newInstance()->value;
        }

        $openWorld = $rc->getAttributes(IsOpenWorld::class);
        if ($openWorld !== []) {
            $annotations['openWorldHint'] = $openWorld[0]->newInstance()->value;
        }

        $title = $rc->getAttributes(Title::class);
        if ($title !== []) {
            $annotations['title'] = $title[0]->newInstance()->value;
        }

        return self::$_annotationsCache[$class] = $annotations;
    }

    /**
     * Whether the tool is restricted to the stdio transport. Returns
     * `true` if `#[IsStdioOnly]` is present (or `#[IsStdioOnly(true)]`),
     * `false` otherwise.
     *
     * @param ToolInterface|class-string<ToolInterface> $toolOrClass
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function isStdioOnly(ToolInterface|string $toolOrClass): bool
    {
        $class = is_string($toolOrClass) ? $toolOrClass : $toolOrClass::class;
        if (array_key_exists($class, self::$_stdioOnlyCache)) {
            return self::$_stdioOnlyCache[$class];
        }

        $rc = new ReflectionClass($toolOrClass);
        $attrs = $rc->getAttributes(IsStdioOnly::class);
        $value = $attrs === [] ? false : (bool) $attrs[0]->newInstance()->value;
        return self::$_stdioOnlyCache[$class] = $value;
    }
}
