<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craftpulse\auditkit\helpers\PluginAdoption;

/**
 * =========================================================================
 * Adopts the plugin-era Audit Kit registration into the module world.
 *
 * Audit Kit 1.0.x shipped as a Craft plugin (`type: craft-plugin`); 1.1.0
 * ships the same package as a library-shipped Yii module. After the
 * Composer bump Craft can no longer discover it, which leaves two
 * orphans behind on an existing install: the `audit-kit` row in the
 * `plugins` table and the `plugins.audit-kit` project config entry, both
 * describing a package Craft cannot resolve. Nothing removes them on its
 * own.
 *
 * `PluginAdoption::adopt()` is the kit's own idempotent helper and does
 * all of it, in this order: it re-tracks plugin-era migration history from
 * `plugin:audit-kit` onto `module:audit-kit`, removes the project config
 * entry with project config events muted and read-only temporarily lifted
 * (mirroring Craft's own forced plugin uninstall), and only then deletes
 * the `plugins` row. The row goes last so a failure part-way through
 * leaves a registration the next adoption call retries from, rather than
 * an install with no `plugins` row and a project config that still names
 * the plugin. It never touches kit or consumer tables, so audit chains,
 * exports and anchors are unaffected.
 *
 * Since kit 1.1.2 the call ends by pumping the kit migrator, which makes
 * it a strict superset of the bare
 * `AuditKit::getInstance()->getMigrator()->up()`. Herald's own explicit
 * pump in `Install::safeUp()` is therefore redundant on a fresh install
 * and is kept anyway: an applied migration is never a candidate to apply
 * again, so the second pump finds nothing to do.
 *
 * Every step is guarded, so the same one-liner ships in every consumer:
 * the first migration to run does the work and the rest find nothing to
 * do. On an install that never carried the plugin, including a fresh
 * install, the removal steps all find nothing and the call degrades to
 * the migrator pump.
 *
 * This has to be a plugin migration on Herald's own track rather than an
 * addition to `Install`, because it has to run on update.
 *
 * The project config removal is durable before `adopt()` returns, and
 * nothing needs wiring here to make it so. Since kit 1.1.1 the helper
 * writes it through `set(null, force: true)` and flushes explicitly,
 * rather than through `ProjectConfig::remove()`, whose write is deferred
 * to `Application::EVENT_AFTER_REQUEST`. A migration cannot count on
 * reaching that event: a console process that exits early, or a harness
 * that boots Craft without a request lifecycle, would drop the change and
 * leave the registration behind. Forcing the set also catches an entry
 * that survives in the YAML alone, which `remove()` compares against the
 * loaded config, reads as unchanged, and skips. Where Craft has
 * deliberately turned automatic YAML writing off because external changes
 * are pending, the flush writes the stored config only and the
 * developer's YAML is left alone, so there is no interleaving with an
 * in-flight apply; `project-config/diff` after the update shows whatever
 * is left to remove by hand.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class m260803_123946_adopt_audit_kit_module extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @throws \Throwable from `PluginAdoption::adopt()` if one of its
     *         database writes fails, if the project config removal fails,
     *         or if a pending kit migration fails to apply.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function safeUp(): bool
    {
        PluginAdoption::adopt();

        return true;
    }

    /**
     * @inheritdoc
     *
     * Irreversible by design. Reverting would mean reinstating a plugin
     * registration for a package that is no longer a plugin, which is
     * not a state worth being able to return to.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        echo "m260803_123946_adopt_audit_kit_module cannot be reverted.\n";

        return false;
    }
}
