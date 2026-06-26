<?php

namespace craftpulse\cortex\models;

use Craft;
use craft\base\Model;
use craftpulse\cortex\services\Allowlist;
use DateInterval;
use Throwable;

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
     * @var string[] Content-level allowlist of command-route glob
     *               patterns the `craft_command` tool may dispatch.
     *               Always admitted regardless of
     *               `allowAdminChanges` — these routes touch content,
     *               caches, queues, mail, or other non-schema state.
     *               Override via project config or
     *               `config/cortex.php`.
     *
     *               Pre-Gate-8.1 this array also carried the admin-
     *               level patterns (`migrate/*`, `make/*`, etc.).
     *               They moved to `$adminLevelCommands` per the
     *               `allowAdminChanges` policy locked in
     *               `docs/plans/gate-8.md` locked decision 14.
     *               Pre-ship, so the shape change ships without
     *               back-compat.
     */
    public array $allowedCommands = [
        'resave/*',
        'cache/*',
        'invalidate-tags/*',
        'index-assets/*',
        'gc',
        'users/create',
        'utils/*',
        'clear-deprecations',
        'mailer/test',
    ];

    /**
     * @var string[] Admin-level allowlist of command-route glob
     *               patterns the `craft_command` tool may dispatch
     *               ONLY when
     *               `Craft::$app->getConfig()->getGeneral()->allowAdminChanges`
     *               is `true`. These routes mutate admin-only state —
     *               project config, schema migrations, plugin
     *               scaffolding, section/field DDL, fixture loads.
     *
     *               When `allowAdminChanges` is `false`, a
     *               `craft_command` invocation matching a pattern
     *               here is rejected at dispatch time as a
     *               `ToolException` (surfaced as an `isError: true`
     *               tool-result envelope) naming `allowAdminChanges`
     *               as the reason. The rejection still writes a
     *               `cortex_invocations` row with `kind=tool_error`
     *               so the audit trail captures the boundary attempt.
     *
     *               Override via project config or
     *               `config/cortex.php` to tighten the admin-level
     *               surface per environment.
     *
     *               The default mirrors
     *               `Allowlist::DEFAULT_ADMIN_LEVEL_PATTERNS` — the
     *               single source of truth the CP command browser also
     *               classifies routes against. Referencing the constant
     *               keeps the two in lockstep without a literal copy.
     */
    public array $adminLevelCommands = Allowlist::DEFAULT_ADMIN_LEVEL_PATTERNS;

    /**
     * @var string[] Operator-curated allowlist of custom-field
     *               handles whose values the Pro `users` tool may
     *               return on a user envelope. Default `[]` —
     *               zero-trust posture: no custom-field values are
     *               exposed until an operator explicitly enumerates
     *               them in project config.
     *
     *               Rationale: Craft 5 has no native per-field-value
     *               permission. Field-layout-designer hiding is UX-
     *               only; values are still accessible via
     *               `getFieldValue()` regardless of layout config.
     *               Defense in depth requires Cortex providing its
     *               own gate — mirrors the command-allowlist
     *               pattern.
     *
     *               Consumed by `src/tools/system/Users.php` (ships
     *               in Gate 8.5). The setting itself lands in 8.1
     *               so operators discover the configuration surface
     *               before the tool that consumes it is ever
     *               registered.
     */
    public array $userCustomFieldAllowlist = [];

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
     *          `Gc::EVENT_RUN` in `Cortex::init()`).
     */
    public int $runtimeOverrideTtl = 604800;

    /**
     * @var bool Whether the HTTP transport (`POST/GET/DELETE
     *          /cortex/mcp`) accepts requests. Defaults to false: the
     *          HTTP transport is opt-in, so a default install exposes
     *          only the trusted local stdio transport. It is also the
     *          kill switch — with this flag false, the MCP, OAuth, and
     *          `.well-known` controllers all return 503 Service
     *          Unavailable regardless of headers or credentials.
     */
    public bool $httpEnabled = false;

    /**
     * @var string[] Allowlist of `Origin` header values the HTTP
     *               transport accepts. An empty list fails CLOSED
     *               outside `devMode` — the controller rejects the
     *               request with 403 and forces the operator to name
     *               an Origin before enabling HTTP in production; in
     *               `devMode` an empty list warns-and-allows so local
     *               work isn't blocked. When the list is non-empty,
     *               requests whose `Origin` does not match exactly are
     *               rejected with 403 Forbidden. DNS-rebinding defense per MCP 2025-06-
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
     *
     *          Invariant: `touch()` fires once per request before the
     *          (possibly streaming) `tools/call` runs, not mid-stream,
     *          so this TTL MUST exceed the longest single stream's
     *          wall-clock — otherwise a stream that outlives its one
     *          touch could evict the session while a sibling request is
     *          in flight. The 3600s default clears the realistic
     *          ceiling (streams are bounded by `max_execution_time` and
     *          cooperative client-disconnect cancel) by two orders of
     *          magnitude. Do not lower it below your longest expected
     *          stream.
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
     * @var bool Whether RFC 7591 Dynamic Client Registration auto-
     *           approves new clients. Default: false (require admin
     *           approval) — every DCR-registered client lands
     *           UNAPPROVED and the authorize + token flows reject it
     *           with a "pending admin approval" error until an admin
     *           approves it on the Clients CP screen. Flip to true for
     *           trusted / dev installs where the operator wants the
     *           zero-friction self-registration MCP clients expect.
     *           Out-of-band-seeded clients are approved directly and
     *           never face this gate.
     */
    public bool $dcrAutoApprove = false;

    /**
     * @var int Default TTL (in seconds) of an elevated marker minted by
     *          the in-band `/oauth/elevate` re-authentication flow.
     *          After fresh re-auth (Craft login + 2FA) a short-lived
     *          elevated marker is bound to the access token; high-stakes
     *          operations over HTTP (credential / email / admin-status
     *          mutations on `users`, and content publish / delete)
     *          require it. Default: 300s (5 minutes) — long enough for a
     *          burst of privileged operations, short enough that a
     *          leaked access token can't replay an elevation hours
     *          later. Elevation is tracked server-side, never trusted
     *          from a client claim.
     */
    public int $elevationTtl = 300;

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
     * @var int Maximum size (in bytes) of a single newline-delimited
     *          JSON-RPC message the stdio transport will buffer before
     *          rejecting it with a JSON-RPC `-32600` Invalid Request.
     *          The stdio reader reassembles a line in bounded chunks; if
     *          the accumulated bytes for one line exceed this cap before
     *          a newline arrives, the reader drains the rest of the line,
     *          emits the error envelope, and continues with the next
     *          message rather than buffering an unbounded payload into
     *          memory. Default 4 MiB — comfortably larger than any
     *          legitimate `tools/call` argument blob, small enough that a
     *          hostile client streaming one giant line can't OOM the
     *          long-running serve process. stdio is a trusted-local
     *          transport, but a trusted local *user* is not the same as a
     *          trusted client *implementation* (cf. the `craft_exec`
     *          threat model), so the cap holds regardless.
     */
    public int $stdioMaxMessageBytes = 4194304;

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
        $rules[] = [['runtimeOverrideTtl', 'sessionTtl', 'elevationTtl'], 'integer', 'min' => 1];
        $rules[] = [['stdioMaxMessageBytes'], 'integer', 'min' => 1024];
        $rules[] = [['tokenTtlDefault'], 'integer', 'min' => 1];
        $rules[] = [['execEnabled', 'execDryRunDefault', 'httpEnabled', 'dcrEnabled', 'dcrAutoApprove'], 'boolean'];
        $rules[] = [['allowedCommands', 'adminLevelCommands', 'userCustomFieldAllowlist', 'allowedOrigins'], 'each', 'rule' => ['string', 'min' => 1]];
        $rules[] = [['oauthAccessTokenTtl', 'oauthRefreshTokenTtl'], 'string', 'min' => 2];
        $rules[] = [['oauthAccessTokenTtl', 'oauthRefreshTokenTtl'], 'validateDateInterval'];
        $rules[] = [['auditResponseExcerptBytes'], 'integer', 'min' => 1, 'max' => 65535];
        $rules[] = [['auditRetentionDays'], 'integer', 'min' => 1];
        $rules[] = [['rateLimitBurst'], 'integer', 'min' => 1, 'max' => 10000];
        $rules[] = [['rateLimitPerSecond'], 'integer', 'min' => 1, 'max' => 1000];
        return $rules;
    }

    /**
     * Validate that an OAuth TTL attribute is a parseable ISO-8601
     * duration. `Oauth::getAuthorizationServer()` feeds these values
     * straight into `new DateInterval(...)`, which throws on a
     * malformed string (e.g. `1h` instead of `PT1H`) — without this
     * rule an operator typo in project config would surface as an
     * opaque 500 on every `/oauth/token` and `/oauth/authorize` call
     * instead of a clean settings-validation error.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function validateDateInterval(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value)) {
            return;
        }

        try {
            new DateInterval($value);
        } catch (Throwable) {
            $this->addError($attribute, Craft::t(
                'cortex',
                '“{value}” is not a valid ISO-8601 duration (e.g. PT1H, P30D).',
                ['value' => $value],
            ));
        }
    }
}
