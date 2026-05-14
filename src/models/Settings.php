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
 * @since  5.0.0
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
     *          allowlist; expired rows are pruned during Craft's gc
     *          cycle (see `Allowlist::pruneExpired()` wired to
     *          `Gc::EVENT_RUN` in `Plugin::init()`).
     */
    public int $runtimeOverrideTtl = 604800;

    /**
     * @var bool Whether the HTTP transport (`POST/GET/DELETE
     *          /cortex/mcp`) accepts requests. Defaults to false so
     *          production installs stay off until auth (sub-gates
     *          7.2 / 7.3) and per-user filtering (7.4) land. With
     *          this flag false, every request to the endpoint returns
     *          503 Service Unavailable regardless of headers or
     *          credentials.
     */
    public bool $httpEnabled = false;

    /**
     * @var string[] Allowlist of `Origin` header values the HTTP
     *               transport accepts. Empty means permissive — every
     *               Origin is accepted, intended for dev only. When
     *               the list is non-empty, requests whose `Origin`
     *               does not match exactly are rejected with 403
     *               Forbidden. DNS-rebinding defense per MCP 2025-06-
     *               18 ("Servers MUST validate the Origin header on
     *               all incoming connections").
     */
    public array $allowedOrigins = [];

    /**
     * @var int Sliding TTL (in seconds) applied to HTTP-transport
     *          sessions in cache. Every `Sessions::touch()` resets the
     *          expiry, so an active client stays alive while idle
     *          clients evict naturally. Default: 1 hour. Override
     *          higher for long-running coding sessions, lower for
     *          tighter session-affinity rotation.
     */
    public int $sessionTtl = 3600;

    /**
     * @var int|null Default TTL (in seconds) applied to a new bearer
     *               token when `cortex/token/issue` is invoked without
     *               an explicit `--ttl=<seconds>` flag. Null (the
     *               default) means tokens have no expiry — admin-
     *               issued credentials live until revoked. Operators
     *               with a tighter rotation policy set this to e.g.
     *               2592000 (30 days) to force regular re-issuance.
     */
    public ?int $tokenTtlDefault = null;

    /**
     * @var bool Whether RFC 7591 Dynamic Client Registration is open
     *           on `POST /oauth/register`. Default: true (open-
     *           registration variant, no initial-access-token
     *           requirement). MCP-native clients (Claude Desktop's
     *           hosted setup, etc.) self-register on first contact;
     *           operators with a stricter posture flip this to false
     *           and seed clients out-of-band via a future console
     *           command or the CP UI in Gate 9.
     */
    public bool $dcrEnabled = true;

    /**
     * @var string ISO 8601 `DateInterval` string defining the OAuth
     *             access-token TTL. Default `PT1H` (1 hour) per the
     *             OAuth 2.1 spec recommendation for short-lived
     *             access tokens. Override to `PT15M` for tighter
     *             rotation or `PT8H` for longer-lived sessions.
     *             Refresh tokens cover the gap between expiries.
     */
    public string $oauthAccessTokenTtl = 'PT1H';

    /**
     * @var string ISO 8601 `DateInterval` string defining the OAuth
     *             refresh-token TTL. Default `P30D` (30 days).
     *             Clients that go quiet for longer than this lose
     *             their refresh ability and must re-authorize
     *             through the consent screen.
     */
    public string $oauthRefreshTokenTtl = 'P30D';

    /**
     * @var int Number of bytes of the (post-redaction) JSON-encoded tool
     *          response to persist in `cortex_invocations.responseExcerpt`.
     *          The DB column is `text`, so values up to 65535 fit; the
     *          default 2048 keeps the audit table footprint small while
     *          surfacing enough payload for forensics. The full response
     *          still goes over the wire to the MCP client — this excerpt
     *          is for the audit dashboard only.
     */
    public int $auditResponseExcerptBytes = 2048;

    /**
     * @var int|null Retention window (in days) for `cortex_invocations`
     *               rows. Null (the default) means audit history is
     *               retained forever — a regulatory-friendly posture
     *               that punts the eviction decision to operators with
     *               local-policy knowledge. Set this to e.g. 90 to
     *               prune rows older than 90 days during Craft's `gc`
     *               sweep.
     */
    public ?int $auditRetentionDays = null;

    /**
     * @var int Burst capacity for the per-user HTTP rate limiter — the
     *          maximum tokens a single Craft user's bucket can hold at
     *          any one time. Each authenticated POST to
     *          `/cortex/mcp` consumes one token; refills accrue at
     *          `$rateLimitPerSecond` tokens per second. Default 60
     *          covers a multi-tool LLM conversation turn without
     *          throttling interactive use, while bounding a runaway
     *          agent loop to ~60 + sustained * elapsed.
     */
    public int $rateLimitBurst = 60;

    /**
     * @var int Sustained refill rate (tokens per second) for the per-
     *          user HTTP rate limiter. The bucket refills linearly at
     *          this rate, clamped to `$rateLimitBurst`. Default 5/sec
     *          tightens the steady-state pace a single caller can
     *          drive against the HTTP transport without throttling
     *          normal interactive use.
     */
    public int $rateLimitPerSecond = 5;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<int,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['runtimeOverrideTtl', 'sessionTtl'], 'integer', 'min' => 1];
        $rules[] = [['tokenTtlDefault'], 'integer', 'min' => 1];
        $rules[] = [['execEnabled', 'execDryRunDefault', 'httpEnabled', 'dcrEnabled'], 'boolean'];
        $rules[] = [['allowedCommands', 'allowedOrigins'], 'each', 'rule' => ['string', 'min' => 1]];
        $rules[] = [['oauthAccessTokenTtl', 'oauthRefreshTokenTtl'], 'string', 'min' => 2];
        $rules[] = [['auditResponseExcerptBytes'], 'integer', 'min' => 1, 'max' => 65535];
        $rules[] = [['auditRetentionDays'], 'integer', 'min' => 1];
        $rules[] = [['rateLimitBurst'], 'integer', 'min' => 1, 'max' => 10000];
        $rules[] = [['rateLimitPerSecond'], 'integer', 'min' => 1, 'max' => 1000];
        return $rules;
    }
}
