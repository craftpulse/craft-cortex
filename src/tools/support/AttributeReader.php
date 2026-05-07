<?php

namespace craftpulse\cortex\tools\support;

use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsOpenWorld;
use craftpulse\cortex\attributes\IsReadOnly;
use craftpulse\cortex\attributes\IsStdioOnly;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\tools\ToolInterface;
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
 *   - `isStdioOnly()` — the cortex-specific transport-gating flag the
 *     dispatcher checks before invoking a tool over HTTP.
 *
 * Both methods accept either a `ToolInterface` instance or a
 * fully-qualified class string — the registry has instances; the
 * dispatcher already resolved one too. Reflection costs are negligible
 * at server boot (~30 attribute reads, once per process).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
final class AttributeReader
{
    // Public Methods
    // =========================================================================

    /**
     * Build the MCP `ToolAnnotations` payload from a tool's attribute
     * declarations. Absent attributes are simply not emitted; explicit
     * `#[Is*(false)]` emits the corresponding hint as `false`.
     *
     * @param ToolInterface|class-string<ToolInterface> $toolOrClass
     * @return array<string,bool|string>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function annotationsFor(ToolInterface|string $toolOrClass): array
    {
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

        return $annotations;
    }

    /**
     * Whether the tool is restricted to the stdio transport. Returns
     * `true` if `#[IsStdioOnly]` is present (or `#[IsStdioOnly(true)]`),
     * `false` otherwise.
     *
     * @param ToolInterface|class-string<ToolInterface> $toolOrClass
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function isStdioOnly(ToolInterface|string $toolOrClass): bool
    {
        $rc = new ReflectionClass($toolOrClass);
        $attrs = $rc->getAttributes(IsStdioOnly::class);
        if ($attrs === []) {
            return false;
        }
        return $attrs[0]->newInstance()->value;
    }
}
