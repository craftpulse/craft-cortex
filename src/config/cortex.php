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
        // OAuth 2.1
        // ---------------------------------------------------------------------

        /**
         * Whether Dynamic Client Registration (RFC 7591) is open. When
         * `true` (default), any caller can POST to `/oauth/register`
         * to mint a new OAuth client without an initial-access-token
         * check — the MCP-native flow Claude Desktop and Cursor use.
         * Set to `false` to require out-of-band client provisioning on
         * production installs where open DCR is not appropriate.
         *
         * @var bool
         */
        // 'dcrEnabled' => true,

        /**
         * Time-to-live for OAuth access tokens as an ISO 8601 duration
         * string (`DateInterval` format). Default `'PT1H'` — one hour.
         * Shorter values tighten the revocation window; longer values
         * reduce refresh overhead for long-running AI sessions. The
         * value is parsed via `new DateInterval($ttl)` at server boot.
         *
         * @var string
         */
        // 'oauthAccessTokenTtl' => 'PT1H',

        /**
         * Time-to-live for OAuth refresh tokens as an ISO 8601 duration
         * string. Default `'P30D'` — 30 days. Refresh tokens are
         * opaque and stored hashed at rest; this TTL controls how long
         * the client can renew access without prompting the user again.
         *
         * @var string
         */
        // 'oauthRefreshTokenTtl' => 'P30D',

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

        // ---------------------------------------------------------------------
        // Audit log (Gate 7.5)
        // ---------------------------------------------------------------------

        /**
         * Number of bytes of the (post-redaction) JSON-encoded tool
         * response persisted to `cortex_invocations.responseExcerpt`.
         * The DB column is `text`, so values up to 65535 fit; the
         * default 2048 keeps the audit table footprint small while
         * surfacing enough payload for forensics. The full response
         * still goes back to the MCP client over the wire — this
         * excerpt is for the audit dashboard only.
         *
         * @var int
         */
        // 'auditResponseExcerptBytes' => 2048,

        /**
         * Retention window (in days) for `cortex_invocations` rows.
         * Null (the default) means audit history is retained forever
         * — the compliance-friendly default that punts the eviction
         * decision to operators with local-policy knowledge. Set
         * this to e.g. 90 to prune rows older than 90 days during
         * Craft's `gc` sweep.
         *
         * @var int|null
         */
        // 'auditRetentionDays' => null,

        // ---------------------------------------------------------------------
        // Rate limit (Gate 7.6)
        // ---------------------------------------------------------------------

        /**
         * Burst capacity for the per-user HTTP rate limiter — the
         * maximum tokens a single Craft user's bucket can hold. Each
         * authenticated POST to `/cortex/mcp` consumes one token;
         * refills accrue at `rateLimitPerSecond` per second.
         * Default 60 covers a multi-tool LLM conversation turn without
         * throttling interactive use, while bounding a runaway agent
         * to ~60 + sustained * elapsed total calls per user.
         *
         * @var int
         */
        // 'rateLimitBurst' => 60,

        /**
         * Sustained refill rate (tokens per second) for the per-user
         * HTTP rate limiter. The bucket refills linearly at this rate,
         * clamped to `rateLimitBurst`. Default 5/sec tightens the
         * steady-state pace a single caller can drive against the HTTP
         * transport without throttling normal interactive use.
         *
         * @var int
         */
        // 'rateLimitPerSecond' => 5,

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
