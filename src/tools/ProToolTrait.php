<?php

namespace craftpulse\cortex\tools;

use craftpulse\cortex\Plugin;

/**
 * =========================================================================
 * Pro-tier registration gate for MCP tools.
 *
 * Single-line opt-in for any tool that should only register on Pro
 * installs. Tools `use ProToolTrait;` immediately after
 * `extends AbstractTool;` to inherit the Pro-only `shouldRegister()`
 * default — no manual edition check inside the tool body.
 *
 * Why a trait, not a base class:
 * - Pro tools still extend `AbstractTool` to inherit the schema/lookup
 *   helpers, the default `outputSchema()`, and the per-user
 *   `filterFor()` / `inputSchemaFor()` defaults. PHP has no multiple
 *   inheritance — a trait is the only way to layer one concrete method
 *   over the parent without a separate `AbstractProTool` parallel
 *   hierarchy.
 *
 * Comparison-operator semantics:
 * - `Plugin::editions()` returns `['free', 'pro']` in ascending order
 *   (see `src/Plugin.php`).
 * - `is(EDITION_PRO, '>=')` walks the array by index. The check
 *   passes on Pro installs and any hypothetical higher tier we might
 *   add later (e.g. Commerce). On Free it returns false and the tool
 *   never registers — invisible from `tools/list`, unresolvable via
 *   `tools/call`, never instantiated by `Tools::_buildRegistry()`.
 *
 * Interaction with the Gate 7.4 three-method contract:
 * - `shouldRegister()` (this trait) is the boot-time gate — runs once
 *   per server boot, gates whole-tool registration on edition.
 * - `filterFor(?User $user)` is the per-request gate — runs on every
 *   `tools/list` and `tools/call`, gates visibility on Craft
 *   permissions. Pro tools override it to consult `User::can()`
 *   against the matrix from `docs/plans/gate-8.md` locked decision 4.
 * - `inputSchemaFor(?User $user)` is the per-request schema rewriter —
 *   filters the `mode` enum based on the resolved user's permissions.
 *
 * Per-test mutation pattern (see `tests/Plugin/EditionTest.php`):
 *   $projectConfig = Craft::$app->getProjectConfig();
 *   $original = $projectConfig->muteEvents;
 *   $projectConfig->muteEvents = true;
 *   try {
 *       Plugin::getInstance()->edition = Plugin::EDITION_PRO;
 *       // ... assertions against ProToolTrait::shouldRegister() ...
 *   } finally {
 *       Plugin::getInstance()->edition = Plugin::EDITION_FREE;
 *       $projectConfig->muteEvents = $original;
 *   }
 *
 * The mute is required because changing `$plugin->edition` mid-request
 * is otherwise interpreted as an out-of-band project-config edit and
 * fires `EVENT_BEFORE_APPLY_PLUGIN_SETTINGS` listeners. Mute around
 * the mutation only — never globally for the whole test.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
trait ProToolTrait
{
    /**
     * Whether this tool should register on this install. Returns
     * `true` only when the active edition is Pro or higher.
     *
     * Overrides `AbstractTool::shouldRegister()` per the
     * `ToolInterface` static contract — the boot loop in
     * `Tools::_buildRegistry()` honours the per-tool decision.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function shouldRegister(): bool
    {
        return Plugin::getInstance()->is(Plugin::EDITION_PRO, '>=');
    }
}
