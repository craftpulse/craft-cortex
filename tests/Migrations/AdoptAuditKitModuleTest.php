<?php

/**
 * =========================================================================
 * Covers the Audit Kit adoption migration
 * `m260803_123946_adopt_audit_kit_module`.
 *
 * Audit Kit 1.0.x shipped as a Craft plugin; 1.1.0 ships the same package
 * as a library-shipped Yii module. After the Composer bump Craft can no
 * longer discover it, and two orphans describing an unresolvable package
 * are left behind on an existing install: the `audit-kit` row in the
 * `plugins` table and the `plugins.audit-kit` project config entry.
 * `PluginAdoption::adopt()` sheds both, re-tracks any plugin-era migration
 * history onto the module track, and never touches kit or consumer
 * tables.
 *
 * The migration ships in every consumer, so it has to converge rather than
 * assume it runs first. These cases assert the post-adoption invariants
 * and that running it again is a clean no-op, which is the state a second
 * retrofitted consumer will find.
 *
 * No fixtures are seeded. Every step of `adopt()` is guarded, so on an
 * install that carries no plugin-era registration (a fresh install, or one
 * a sibling consumer already adopted) the migration writes nothing at all
 * — which is exactly why it is safe to run inside the suite.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craftpulse\auditkit\helpers\PluginAdoption;
use craftpulse\herald\migrations\m260803_123946_adopt_audit_kit_module;

// -----------------------------------------------------------------------------
// Post-adoption invariants
// -----------------------------------------------------------------------------

it('leaves no plugin-era Audit Kit registration behind', function() {
    (new m260803_123946_adopt_audit_kit_module())->safeUp();

    $pluginRows = (new Query())
        ->from(CraftTable::PLUGINS)
        ->where(['handle' => PluginAdoption::PLUGIN_HANDLE])
        ->count();

    expect((int) $pluginRows)->toBe(0);

    $pluginTrackRows = (new Query())
        ->from(CraftTable::MIGRATIONS)
        ->where(['track' => PluginAdoption::PLUGIN_TRACK])
        ->count();

    expect((int) $pluginTrackRows)->toBe(0);

    expect(Craft::$app->getProjectConfig()->get('plugins.' . PluginAdoption::PLUGIN_HANDLE))->toBeNull();
});

it('converges when run again', function() {
    $migration = new m260803_123946_adopt_audit_kit_module();

    expect($migration->safeUp())->toBeTrue();

    $snapshot = static fn(): array => [
        'plugins' => (int) (new Query())
            ->from(CraftTable::PLUGINS)
            ->where(['handle' => PluginAdoption::PLUGIN_HANDLE])
            ->count(),
        'pluginTrack' => (int) (new Query())
            ->from(CraftTable::MIGRATIONS)
            ->where(['track' => PluginAdoption::PLUGIN_TRACK])
            ->count(),
        'moduleTrack' => (int) (new Query())
            ->from(CraftTable::MIGRATIONS)
            ->where(['track' => PluginAdoption::MODULE_TRACK])
            ->count(),
    ];

    $before = $snapshot();

    expect($migration->safeUp())->toBeTrue();
    expect($snapshot())->toBe($before);
});

// -----------------------------------------------------------------------------
// Irreversible by design
// -----------------------------------------------------------------------------

it('refuses to revert', function() {
    ob_start();
    $reverted = (new m260803_123946_adopt_audit_kit_module())->safeDown();
    $output = (string) ob_get_clean();

    expect($reverted)->toBeFalse();
    expect($output)->toContain('cannot be reverted');
});
