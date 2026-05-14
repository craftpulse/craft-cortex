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
         * Default ships ~15 patterns covering generators, migrations,
         * project config, caches, resaves, fixtures, and a few common
         * one-shots.
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

        // ---------------------------------------------------------------------
        // HTTP transport
        // ---------------------------------------------------------------------

        /**
         * Whether the HTTP transport (`POST/GET/DELETE /cortex/mcp`)
         * accepts requests. Defaults to false so production installs
         * stay off until per-user filtering (sub-gate 7.4) and bearer-
         * token auth (sub-gates 7.2 / 7.3) land. With this flag false,
         * every request to the endpoint returns 503 Service
         * Unavailable regardless of headers or credentials.
         *
         * @var bool
         */
        // 'httpEnabled' => false,

        /**
         * Allowlist of `Origin` header values the HTTP transport will
         * accept. Empty means permissive — every Origin is accepted,
         * which is fine for local dev but unsafe for any deployed
         * environment because the MCP spec mandates Origin validation
         * as DNS-rebinding defense. When non-empty, requests whose
         * `Origin` does not match exactly are rejected with 403.
         *
         * Set this to the explicit URLs of every client that talks to
         * the endpoint — Claude Desktop's local proxy, Cursor's HTTP
         * setup, etc.
         *
         * @var string[]
         */
        // 'allowedOrigins' => [
        //     'http://localhost:6274',
        //     'https://claude.ai',
        // ],

        /**
         * Sliding TTL (in seconds) applied to HTTP-transport sessions
         * in cache. Every authenticated request resets the expiry, so
         * an active client stays alive while idle clients evict
         * naturally. Default: 1 hour. Override higher for long-
         * running coding sessions, lower for tighter session-affinity
         * rotation.
         *
         * @var int
         */
        // 'sessionTtl' => 3600,

        // ---------------------------------------------------------------------
        // Bearer tokens
        // ---------------------------------------------------------------------

        /**
         * Default TTL (in seconds) applied to a new bearer token issued
         * via `cortex/token/issue` when no explicit `--ttl=<seconds>`
         * flag is passed. Null (the default) means tokens never expire
         * — admin-issued credentials live until revoked. Set this to
         * e.g. 2592000 (30 days) to force a regular rotation cadence
         * on every newly-issued token without remembering to pass
         * `--ttl` every time.
         *
         * @var int|null
         */
        // 'tokenTtlDefault' => null,

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

    // ---------------------------------------------------------------------
    // Opting in to destructive DB commands
    // ---------------------------------------------------------------------
    //
    // `db/backup` and `db/restore` are NOT in the default allowlist. They
    // are destructive — backup writes a file to the storage volume,
    // restore replaces the entire database from a dump. Operators that
    // want them available to the `craft_command` tool should add them
    // explicitly per environment:
    //
    // 'dev' => [
    //     'allowedCommands' => [
    //         // Inherit the defaults by merging them in yourself, or list
    //         // the full set the environment needs.
    //         'resave/*', 'cache/*', 'migrate/*', 'up', 'gc',
    //         // Plus the destructive opt-ins.
    //         'db/backup',
    //         'db/restore',
    //     ],
    // ],
];
