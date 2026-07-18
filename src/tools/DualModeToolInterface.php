<?php

namespace craftpulse\herald\tools;

/**
 * =========================================================================
 * Opt-in contract for tools whose `mode` argument spans both read and
 * Pro write operations under a single MCP tool name.
 *
 * `content_audit`, `drafts_and_revisions`, and `import_export` each
 * advertise a class-level `#[IsReadOnly]` MCP hint — accurate for the
 * Free tier, where every registered mode is a report or an inspection.
 * On Pro they gain mutating modes (`fix_relations` /
 * `prune_unused_assets` / `repair_propagation`, `apply` / `discard`,
 * `import`) behind the same tool name. `readOnlyHint` is a single
 * class-level flag — it cannot express "read, except when `mode` is
 * one of these" — so `services\Audit::handleToolInvocation()` cannot
 * tell a `content_audit(mode: relations)` call (a read) from a
 * `content_audit(mode: fix_relations)` call (a write) by attribute
 * alone. Both invocations carry the identical `readOnlyHint: true`
 * annotation, so classification silently skips the write and the
 * governance chain never records it.
 *
 * A tool implements this interface to self-describe its per-mode
 * write posture instead of forcing the audit emitter to maintain a
 * parallel tool-to-mode lookup table that drifts from the tool's own
 * `FREE_MODES` / `PRO_MODES` constants. `getModeWriteMap()` returns
 * every mode the tool recognises, mapped to whether that mode mutates
 * state; implementations derive the map from their existing mode
 * constants (`array_fill_keys(self::FREE_MODES, false) +
 * array_fill_keys(self::PRO_MODES, true)`) so there is exactly one
 * place per tool where a mode is Free-or-Pro / read-or-write, and it's
 * the same place `execute()` and `inputSchemaFor()` already consult.
 *
 * Future dual-mode tools that mix read and Pro-write operations under
 * one name implement this interface and gain correct audit
 * classification automatically — no change to `services\Audit`
 * required.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.1.0
 */
interface DualModeToolInterface extends ToolInterface
{
    /**
     * Every `mode` value this tool recognises, mapped to whether that
     * mode mutates state (`true`) or is a pure read (`false`). A mode
     * absent from the returned map is unresolvable by the caller and
     * MUST be treated as a write — the audit-safe direction is a
     * spurious governance record over a silently missed write.
     *
     * @return array<string,bool>
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public static function getModeWriteMap(): array;
}
