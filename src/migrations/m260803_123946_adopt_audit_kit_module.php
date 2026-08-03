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
 * all of it: it re-tracks plugin-era migration history from
 * `plugin:audit-kit` onto `module:audit-kit`, deletes the `plugins` row,
 * and removes the project config entry with project config events muted
 * and read-only temporarily lifted (mirroring Craft's own forced plugin
 * uninstall). It never touches kit or consumer tables, so audit chains,
 * exports and anchors are unaffected.
 *
 * Every step is guarded, so the same one-liner ships in every consumer:
 * the first migration to run does the work and the rest find nothing to
 * do. On an install that never carried the plugin, including a fresh
 * install, it writes nothing.
 *
 * This has to be a plugin migration on Herald's own track rather than an
 * addition to `Install`, because it has to run on update.
 *
 * The project config removal is written through
 * `ProjectConfig::remove()`, whose write is deferred to
 * `Application::EVENT_AFTER_REQUEST`. That event is triggered by
 * `yii\base\Application::run()`, which both the console and web
 * applications share, so the removal persists on every path a plugin
 * migration actually runs from (`craft up`, `migrate/up`, a control panel
 * update). No explicit `flush()` is wired here on purpose: forcing a
 * mid-migration YAML write would risk interleaving with an in-flight
 * project config apply, and Craft's own migrations rely on the same
 * deferred write.
 * =========================================================================
 *
 * @author Craftpulse
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
     *         database writes fails.
     *
     * @author Craftpulse
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
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        echo "m260803_123946_adopt_audit_kit_module cannot be reverted.\n";

        return false;
    }
}
