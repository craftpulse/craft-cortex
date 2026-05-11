<?php

/**
 * =========================================================================
 * Cortex configuration reference.
 *
 * Copy this file to your Craft project's `config/cortex.php` to override
 * the defaults baked into `Settings` and the project-config layer. Craft
 * loads this file automatically and merges values into the plugin
 * settings model at boot.
 *
 * Multi-environment overrides work via the standard Craft pattern: top-
 * level keys apply everywhere, `*` keys apply everywhere with lower
 * precedence than environment-specific keys, and environment-specific
 * keys (e.g. `production`, `staging`, `dev`) override the wildcard.
 *
 * Precedence (highest -> lowest):
 *   1. `config/cortex.php` environment-specific keys
 *   2. `config/cortex.php` wildcard (`*`) keys
 *   3. Project config — `plugins.cortex.settings.*`
 *   4. Defaults from `models/Settings`
 *
 * Runtime DB overrides (admin-issued via the CP, auto-expiring) layer
 * on top of `allowedCommands` only — they don't override the toggles
 * or TTL config.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

return [
    '*' => [

        // ---------------------------------------------------------------------
        // Allowlist
        // ---------------------------------------------------------------------

        /**
         * The set of glob patterns the `craft_command` tool may dispatch.
         * Patterns use fnmatch semantics: `resave/*` matches any
         * `resave/<x>` route; `up` matches the literal `up` command.
         *
         * Set this to your own list to lock the allowlist down per
         * environment, or extend the defaults via the CP runtime
         * overrides UI for short-lived grants (auto-expiring).
         *
         * Default ships ~17 patterns covering generators, migrations,
         * project config, caches, resaves, fixtures, and a few common
         * one-shots (db/backup, mailer/test).
         *
         * @var string[]
         */
        // 'allowedCommands' => [
        //     'resave/*',
        //     'project-config/*',
        //     'cache/*',
        //     'invalidate-tags/*',
        //     'migrate/*',
        //     'up',
        //     'index-assets/*',
        //     'gc',
        //     'make/*',
        //     'fixture/*',
        //     'sections/*',
        //     'fields/*',
        //     'users/create',
        //     'entrify/*',
        //     'db/backup',
        //     'db/restore',
        //     'utils/*',
        //     'clear-deprecations',
        //     'mailer/test',
        // ],

        // ---------------------------------------------------------------------
        // craft_exec
        // ---------------------------------------------------------------------

        /**
         * Whether the `craft_exec` tool is enabled. The tool is stdio-
         * only and runs through six security gates (dry-run default,
         * structured output, secret redaction, destructive-op guard,
         * HTTP rejection, dangerous annotation). Disable to remove it
         * from `tools/list` entirely.
         *
         * @var bool
         */
        // 'execEnabled' => true,

        /**
         * Whether `craft_exec` defaults to dry-run. With dry-run on,
         * callers must pass `confirm: true` to actually evaluate.
         * Destructive expressions still need explicit `dangerous: true`
         * regardless of this setting.
         *
         * @var bool
         */
        // 'execDryRunDefault' => true,

        // ---------------------------------------------------------------------
        // Runtime overrides
        // ---------------------------------------------------------------------

        /**
         * Default expiry (in seconds) applied to a new runtime
         * allowlist override when none is supplied at creation time.
         * Default 7 days (604800). Use shorter for tighter security
         * posture, longer for less friction.
         *
         * @var int
         */
        // 'runtimeOverrideTtl' => 604800,

    ],

    // 'production' => [
    //     'allowedCommands' => [
    //         // Production typically wants a tighter allowlist.
    //         'cache/flush',
    //         'cache/flush-all',
    //         'invalidate-tags/*',
    //         'gc',
    //         'mailer/test',
    //     ],
    //     'execEnabled' => false,
    // ],

    // 'staging' => [
    //     'execEnabled' => true,
    //     'runtimeOverrideTtl' => 86400, // 1 day on staging.
    // ],
];
