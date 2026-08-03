<?php

/**
 * =========================================================================
 * Herald configuration reference.
 *
 * Copy this file to your Craft project's `config/herald.php` to override
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
 *   1. `config/herald.php` environment-specific keys
 *   2. `config/herald.php` wildcard (`*`) keys
 *   3. Project config — `plugins.herald.settings.*`
 *   4. Defaults from `models/Settings`
 *
 * Runtime DB overrides (admin-issued via the CP, auto-expiring) layer
 * on top of `allowedCommands` only — they don't override
 * `adminLevelCommands`, the toggles, or TTL config. To grant a
 * normally-admin-level pattern at runtime, extend `allowedCommands`
 * directly via project config or `config/herald.php` for the target
 * environment.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

return [
    '*' => [

        // ---------------------------------------------------------------------
        // Allowlist — content level
        // ---------------------------------------------------------------------

        /**
         * The content-level set of glob patterns the `craft_command`
         * tool may dispatch. Always admitted regardless of the host's
         * `allowAdminChanges` flag — these routes touch content,
         * caches, queues, mail, or other non-schema state.
         *
         * Patterns use fnmatch semantics: `resave/*` matches any
         * `resave/<x>` route; `gc` matches the literal `gc` command.
         *
         * Set this to your own list to lock the allowlist down per
         * environment, or extend the defaults via the CP runtime
         * overrides UI for short-lived grants (auto-expiring).
         *
         * Default ships nine content-level patterns covering resaves,
         * caches, queue maintenance, asset indexing, user creation,
         * and a few common one-shots.
         *
         * @var string[]
         */
        // 'allowedCommands' => [
        //     'resave/*',
        //     'cache/*',
        //     'invalidate-tags/*',
        //     'index-assets/*',
        //     'gc',
        //     'users/create',
        //     'utils/*',
        //     'clear-deprecations',
        //     'mailer/test',
        // ],

        // ---------------------------------------------------------------------
        // Allowlist — admin level
        // ---------------------------------------------------------------------

        /**
         * The admin-level set of glob patterns the `craft_command`
         * tool may dispatch ONLY when
         * `Craft::$app->getConfig()->getGeneral()->allowAdminChanges`
         * is `true`. These routes mutate admin-only state — project
         * config, schema migrations, plugin scaffolding, section/field
         * DDL, fixture loads.
         *
         * When `allowAdminChanges` is `false`, a `craft_command`
         * invocation matching a pattern here is rejected at dispatch
         * with JSON-RPC code `-32002` and an error message naming
         * `allowAdminChanges` as the reason. The rejection is still
         * audit-logged so the boundary attempt survives in
         * `herald_invocations`.
         *
         * Tighten this list per environment when you want a stricter
         * posture than the defaults provide — e.g. drop `make/*` on
         * production where scaffolding has no place.
         *
         * @var string[]
         */
        // 'adminLevelCommands' => [
        //     'project-config/*',
        //     'migrate/*',
        //     'up',
        //     'make/*',
        //     'entrify/*',
        //     'sections/*',
        //     'fields/*',
        //     'fixture/*',
        // ],

        // ---------------------------------------------------------------------
        // Users tool — custom-field exposure allowlist
        // ---------------------------------------------------------------------

        /**
         * Operator-curated list of custom-field handles whose values
         * the Pro `users` tool may return on a user envelope. Default
         * `[]` — zero-trust posture: no custom-field values are
         * exposed until you explicitly enumerate them here.
         *
         * Craft 5 has no native per-field-value permission; field-
         * layout-designer hiding is UX-only. Defense in depth
         * requires Herald providing its own gate — this allowlist is
         * the gate. Native user attributes (id, username, email,
         * etc.) are NOT subject to this allowlist; per-permission
         * gates handle them. Only custom-field values are gated here.
         *
         * Consumed by the Pro `users` tool (lands in Gate 8.5). The
         * setting itself ships in Gate 8.1 so operators discover the
         * configuration surface before the tool exists.
         *
         * @var string[]
         */
        // 'userCustomFieldAllowlist' => [
        //     'phone',
        //     'department',
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
         * Whether the HTTP transport (`POST/GET/DELETE /herald/mcp`)
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
        // stdio transport
        // ---------------------------------------------------------------------

        /**
         * Maximum size (in bytes) of a single newline-delimited JSON-RPC
         * message the stdio transport will buffer before rejecting it
         * with a JSON-RPC `-32600` Invalid Request. The reader
         * reassembles each line in bounded chunks; if one line's
         * accumulated bytes exceed this cap before a newline arrives,
         * the reader drains the rest of the line, emits the error
         * envelope, and continues with the next message rather than
         * buffering an unbounded payload into memory.
         *
         * Default 4 MiB (4194304) — comfortably larger than any
         * legitimate `tools/call` argument blob, small enough that a
         * hostile client streaming one giant line can't OOM the long-
         * running serve process. stdio is a trusted-local transport, but
         * a trusted local *user* is not the same as a trusted client
         * *implementation* (cf. the `craft_exec` threat model), so the
         * cap holds regardless. Minimum 1024.
         *
         * @var int
         */
        // 'stdioMaxMessageBytes' => 4194304,

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
         * Whether a newly DCR-registered client is auto-approved.
         * Default `false`: every self-registered client lands
         * UNAPPROVED and its authorize / token flows are rejected with
         * a "pending admin approval" error until an admin approves it
         * on the Clients control-panel screen. Set `true` for trusted /
         * dev installs that want zero-friction self-registration.
         * Out-of-band-seeded clients are approved directly.
         *
         * @var bool
         */
        // 'dcrAutoApprove' => false,

        /**
         * Lifetime (in seconds) of an elevation marker minted by the
         * in-band `/oauth/elevate` re-authentication flow. Default
         * `300` (5 minutes). After a fresh Craft re-auth (password +
         * 2FA), high-stakes operations over HTTP — credential / email /
         * admin-status mutations on `users`, and content publish /
         * delete — are permitted for this window, bound to the specific
         * access token. Tracked server-side; never trusted from a
         * client claim. `craft_exec` is never unlocked by elevation.
         *
         * @var int
         */
        // 'elevationTtl' => 300,

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
         * via `herald/token/issue` when no explicit `--ttl=<seconds>`
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
         * response persisted to `herald_invocations.responseExcerpt`.
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
         * Retention window (in days) for `herald_invocations` rows.
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
         * authenticated POST to `/herald/mcp` consumes one token;
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
    //         // Production typically wants a tighter content-level allowlist.
    //         'cache/flush',
    //         'cache/flush-all',
    //         'invalidate-tags/*',
    //         'gc',
    //         'mailer/test',
    //     ],
    //     'adminLevelCommands' => [
    //         // And usually no admin-level surface at all on production.
    //         // Combined with `allowAdminChanges = false` in `config/general.php`,
    //         // this is belt-and-suspenders: even an operator who flips the
    //         // host flag back on still has nothing here to dispatch.
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
