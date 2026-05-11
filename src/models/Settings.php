<?php

namespace craftpulse\cortex\models;

use craft\base\Model;

/**
 * =========================================================================
 * Cortex plugin settings.
 *
 * Loaded by Craft from `plugins.cortex.settings.*` in project config and
 * merged with overrides from `config/cortex.php`. The settings model is
 * the single source of truth for tool-level configuration that should
 * sync across environments — currently the `craft_command` allowlist
 * and `craft_exec` toggles.
 *
 * Runtime overrides (admin-editable, auto-expiring) live in the
 * `cortex_runtime_overrides` DB table and are layered on top at lookup
 * time, driven by the CP settings UI.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string[] Default allowlist of command-route glob patterns the
     *               `craft_command` tool may dispatch. Override via
     *               project config or `config/cortex.php`.
     */
    public array $allowedCommands = [
        'resave/*',
        'project-config/*',
        'cache/*',
        'invalidate-tags/*',
        'migrate/*',
        'up',
        'index-assets/*',
        'gc',
        'make/*',
        'fixture/*',
        'sections/*',
        'fields/*',
        'users/create',
        'entrify/*',
        'db/backup',
        'db/restore',
        'utils/*',
        'clear-deprecations',
        'mailer/test',
    ];

    /**
     * @var bool Whether the `craft_exec` tool is enabled. Defaults to
     *           true; flip to false to remove `craft_exec` from
     *           `tools/list` entirely (the registry honours it). Exec
     *           is treated as a stdio-only opt-in fallback — operators
     *           that want a stricter posture can disable it without
     *           losing the rest of the dev surface.
     */
    public bool $execEnabled = true;

    /**
     * @var bool Whether `craft_exec` defaults to dry-run. Even with
     *           dry-run on, callers can still set `confirm: true` per
     *           call to actually evaluate. Flip to false to make
     *           evaluation the default — the destructive-op guard
     *           still applies and still requires explicit `dangerous: true`.
     */
    public bool $execDryRunDefault = true;

    /**
     * @var int Default TTL (in seconds) applied to a new runtime
     *          allowlist override when none is supplied at creation
     *          time. Default: 7 days. Expired overrides are still
     *          stored but no longer count toward the effective
     *          allowlist; the cleanup queue job prunes them in the
     *          background.
     */
    public int $runtimeOverrideTtl = 604800;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['runtimeOverrideTtl'], 'integer', 'min' => 1];
        $rules[] = [['execEnabled', 'execDryRunDefault'], 'boolean'];
        $rules[] = [['allowedCommands'], 'each', 'rule' => ['string', 'min' => 1]];
        return $rules;
    }
}
