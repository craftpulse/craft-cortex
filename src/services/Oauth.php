<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use Craft;
use craft\helpers\App;
use craft\helpers\StringHelper;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\oauth\repositories\AccessTokenRepository;
use craftpulse\herald\oauth\repositories\AuthCodeRepository;
use craftpulse\herald\oauth\repositories\ClientRepository;
use craftpulse\herald\oauth\repositories\RefreshTokenRepository;
use craftpulse\herald\oauth\repositories\ScopeRepository;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use craftpulse\herald\records\OauthCode as OauthCodeRecord;
use craftpulse\herald\records\OauthToken as OauthTokenRecord;
use craftpulse\herald\tools\support\InvocationLogger;
use DateInterval;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * =========================================================================
 * OAuth 2.1 orchestration service — wraps league/oauth2-server.
 *
 * Responsibilities:
 *
 *   1. **Server construction.** Builds the `AuthorizationServer` (with
 *      AuthCode + Refresh grants) and the `ResourceServer` lazily,
 *      memoized per-request. The AuthCode grant is configured for
 *      PKCE-required, S256-only — `plain` PKCE is rejected at the
 *      controller layer (league enables both verifiers; we filter at
 *      our boundary).
 *
 *   2. **Audience binding (RFC 8707).** Every access token's `aud`
 *      claim carries the resource URI passed in the `resource=` query
 *      parameter at authorize / token time. `lookupAccessToken()`
 *      verifies the `aud` matches the MCP endpoint URL before
 *      accepting the token. Without this, a token issued for resource
 *      A could be replayed against resource B — RFC 8707 is the MCP-
 *      spec-required defense.
 *
 *   3. **Dynamic Client Registration (RFC 7591).** `registerClient()`
 *      mints a fresh `clientId`, validates redirect URIs (HTTPS only,
 *      no localhost wildcards, no fragments), and persists. Open-
 *      registration variant — no initial-access-token requirement,
 *      matching MCP-native client expectations.
 *
 *   4. **Bearer-token lookup for the HTTP transport.**
 *      `lookupAccessToken()` is what `McpController::beforeAction()`
 *      calls to resolve an OAuth access token to an authenticated
 *      identity. Returns the resolved slot (`userId`, `clientId`,
 *      `scope`, `audience`) on success; null on any failure path
 *      (parse error, expired token, revoked token, mismatched
 *      audience).
 *
 *   5. **Phase 1 scope vocabulary.** Two scopes ship — `read` and
 *      `write`. `write` is accepted at registration / authorize time
 *      so DCR clients asking for it don't break, but no Phase 1 tool
 *      honours it; the per-tool `filterFor()` contract in Gate 7.4
 *      gates write tools out independently. Phase 3 expansion will
 *      add fine-grained scopes via `ScopeRepository` without touching
 *      this service.
 *
 * Key path: `storage/herald/oauth-keys/{private,public}.key`. The
 * `herald/oauth/init-keys` console action generates them (0600 on
 * private). Service throws `InvalidConfigException` if either is
 * missing — operators run init-keys once per install.
 *
 * Carbon over `DateTimeHelper` here per the services rule.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Oauth extends Component
{
    // Constants
    // =========================================================================

    /**
     * Directory under Craft's storage path where the JWT key pair
     * lives. Gitignored. Operators that don't want to ship keys with
     * the storage volume can mount this path separately.
     *
     * @since 5.0.0
     */
    public const KEYS_SUBDIR = 'herald/oauth-keys';

    /**
     * Filename for the RSA private key. League's CryptKey expects a
     * `file://` URI; the directory + filename are joined at use site.
     *
     * @since 5.0.0
     */
    public const PRIVATE_KEY_FILE = 'private.key';

    /**
     * Filename for the matching RSA public key.
     *
     * @since 5.0.0
     */
    public const PUBLIC_KEY_FILE = 'public.key';

    /**
     * Default scope assigned when a client asks for none. Aligned
     * with league's `$defaultScope = ''` slot — empty string means
     * "no scope" which fails validation, so we ship `read` as the
     * floor.
     *
     * @since 5.0.0
     */
    public const DEFAULT_SCOPE = 'read';

    /**
     * Length of the random `clientId` minted at DCR time. 32 hex
     * chars = 128 bits of entropy, well-padded against collisions
     * across a corpus of millions of clients.
     *
     * @since 5.0.0
     */
    public const CLIENT_ID_BYTES = 16;

    /**
     * Length of the confidential-client secret minted at DCR time.
     * 48 hex chars = 192 bits of entropy; stored as
     * `password_hash`-derived `clientSecretHash`.
     *
     * @since 5.0.0
     */
    public const CLIENT_SECRET_BYTES = 24;

    // Private Properties
    // =========================================================================

    /**
     * @var AuthorizationServer|null Lazy memoization of the league
     *                               `AuthorizationServer`. Built once
     *                               per-request on first access.
     */
    private ?AuthorizationServer $_authorizationServer = null;

    /**
     * @var ResourceServer|null Lazy memoization of the league
     *                          `ResourceServer`.
     */
    private ?ResourceServer $_resourceServer = null;

    /**
     * @var string|null Audience to stamp onto the next access token
     *                  the grant issues. Set by the controller from
     *                  the `resource=` query parameter; consumed by
     *                  the `AccessTokenRepository`'s persist hook.
     *                  Request-scoped because each Server instance
     *                  is per-request — no cross-request bleed.
     */
    private ?string $_pendingAudience = null;

    /**
     * @var string|null Family id to stamp onto the next access + refresh
     *                  token pair the grant issues. On the auth-code
     *                  flow this stays null and `AccessTokenRepository`
     *                  mints a fresh family. On the refresh flow the
     *                  `RefreshTokenRepository` sets it from the
     *                  presented refresh token's row so the rotated
     *                  pair inherits the lineage. Request-scoped.
     */
    private ?string $_pendingFamilyId = null;

    // Public Methods
    // =========================================================================

    /**
     * Set the RFC 8707 resource indicator the next-issued access
     * token will carry as its `aud` claim. Called by the controller
     * after parsing the `resource=` query parameter at authorize /
     * token time. Cleared after league hands the entity back to the
     * repository for persistence.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setPendingAudience(?string $audience): void
    {
        $this->_pendingAudience = $audience;
    }

    /**
     * Read the pending audience back. Consumed by the
     * `AccessTokenRepository` when stamping the entity's `aud`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getPendingAudience(): ?string
    {
        return $this->_pendingAudience;
    }

    /**
     * Set the refresh-rotation lineage id the next-issued token pair
     * inherits. Called by `RefreshTokenRepository::isRefreshTokenRevoked()`
     * when it observes a live (non-consumed) refresh token being
     * exchanged, so the rotated access + refresh tokens stay in the
     * same family. Cleared after the pair is persisted.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setPendingFamilyId(?string $familyId): void
    {
        $this->_pendingFamilyId = $familyId;
    }

    /**
     * Read the pending family id back. Consumed by
     * `AccessTokenRepository` and `RefreshTokenRepository` when
     * stamping the rotated token rows. Null on the auth-code flow,
     * where `AccessTokenRepository` mints a fresh family instead.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getPendingFamilyId(): ?string
    {
        return $this->_pendingFamilyId;
    }

    /**
     * Revoke every live (non-revoked) access + refresh token in a
     * rotation family, and emit a security event + audit row.
     *
     * Called by `RefreshTokenRepository::isRefreshTokenRevoked()` when
     * an already-consumed refresh token is replayed — the canonical
     * stolen-refresh-token signal per RFC 6819 §5.2.2.3 / the OAuth
     * 2.1 refresh-rotation BCP. Revoking the whole family invalidates
     * the legitimate client's in-flight credentials too, forcing a
     * fresh consent flow — the safe response to a suspected theft.
     *
     * Returns the number of token rows revoked.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function revokeFamily(string $familyId, string $reason): int
    {
        $count = (int) OauthTokenRecord::updateAll(
            ['dateRevoked' => Carbon::now()->toDateTimeString()],
            ['familyId' => $familyId, 'dateRevoked' => null],
        );

        Craft::warning(
            sprintf(
                'herald OAuth refresh-token theft detected. Revoked %d token(s) in family %s. Reason: %s',
                $count,
                $familyId,
                $reason,
            ),
            'herald.oauth',
        );

        // Audit row so the Activity dashboard surfaces the security
        // event alongside tool invocations. Soft-write: a DB failure
        // inside the audit path cannot break the revoke response.
        Herald::getInstance()->invocations->record([
            'tool' => '_oauth_token_theft',
            'kind' => InvocationLogger::KIND_SECURITY,
            'duration_ms' => 0,
            'transport' => Server::TRANSPORT_HTTP,
            'request_id' => null,
            'user' => null,
            'client' => null,
            'token_id' => null,
            'session_id' => null,
            'rate_limit_remaining' => null,
            'args' => null,
            'response_excerpt' => null,
            'error_class' => null,
            'error_message' => sprintf(
                'Refresh-token replay detected; revoked %d token(s) in family %s. %s',
                $count,
                $familyId,
                $reason,
            ),
        ]);

        return $count;
    }

    /**
     * The family id of the token row a hashed refresh / access token
     * belongs to, or null when no row matches. Used during rotation
     * to inherit the lineage and during theft detection to target the
     * family-wide revoke.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function familyIdForTokenHash(string $tokenHash): ?string
    {
        $record = OauthTokenRecord::findOne(['tokenHash' => $tokenHash]);
        if (!$record instanceof OauthTokenRecord) {
            return null;
        }
        return $record->familyId;
    }

    /**
     * Mint a fresh rotation-family identifier. A UUID keeps the column
     * a fixed 36 chars and is collision-free across the install.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function newFamilyId(): string
    {
        return StringHelper::UUID();
    }

    /**
     * Lazy-build the league `AuthorizationServer`. Wires AuthCode
     * grant + Refresh grant, configures access / refresh TTLs from
     * `Settings`. Memoized per-request.
     *
     * @throws InvalidConfigException When the JWT key pair is
     *                                missing — operators run
     *                                `herald/oauth/init-keys` once per
     *                                install.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getAuthorizationServer(): AuthorizationServer
    {
        if ($this->_authorizationServer !== null) {
            return $this->_authorizationServer;
        }

        $settings = Herald::getInstance()->getSettings();
        $accessTtl = new DateInterval($settings->oauthAccessTokenTtl);
        $refreshTtl = new DateInterval($settings->oauthRefreshTokenTtl);

        $server = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository(),
            'file://' . $this->getPrivateKeyPath(),
            $this->_encryptionKey(),
        );

        $authCodeGrant = new AuthCodeGrant(
            new AuthCodeRepository(),
            new RefreshTokenRepository(),
            new DateInterval('PT5M'),
        );
        $authCodeGrant->setRefreshTokenTTL($refreshTtl);
        $server->enableGrantType($authCodeGrant, $accessTtl);

        $refreshGrant = new RefreshTokenGrant(new RefreshTokenRepository());
        $refreshGrant->setRefreshTokenTTL($refreshTtl);
        $server->enableGrantType($refreshGrant, $accessTtl);

        $this->_authorizationServer = $server;
        return $server;
    }

    /**
     * Lazy-build the league `ResourceServer`. Validates incoming
     * access tokens against the public key. Memoized per-request.
     *
     * @throws InvalidConfigException When the JWT key pair is
     *                                missing.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getResourceServer(): ResourceServer
    {
        if ($this->_resourceServer !== null) {
            return $this->_resourceServer;
        }

        $this->_resourceServer = new ResourceServer(
            new AccessTokenRepository(),
            'file://' . $this->getPublicKeyPath(),
        );
        return $this->_resourceServer;
    }

    /**
     * Resolve an OAuth access token (raw JWT string) to an
     * authenticated identity slot, or null if the token is invalid /
     * expired / revoked / has a mismatched audience.
     *
     * Returns an associative array:
     *   - `userId`   — Craft user id (int) or null for service-only
     *                  tokens (not used in Phase 1).
     *   - `clientId` — the issuing OAuth client identifier.
     *   - `scope`    — space-delimited scope string from the JWT.
     *   - `audience` — the `aud` claim (RFC 8707 resource indicator).
     *
     * Audience binding: the caller (`McpController::beforeAction()`)
     * checks `audience === <mcp endpoint url>` after this method
     * returns. Herald does *not* enforce a specific audience inside
     * the service because the controller knows the canonical endpoint
     * URL — keeping that check at the boundary lets unit tests vary
     * the audience without monkey-patching.
     *
     * The `jti` (JWT token id) is also returned for revocation
     * correlation. (WS2 elevation is keyed by the bound `userId`, not
     * the `jti` — see `elevationCacheKey()`.)
     *
     * @return array{userId:?int,clientId:string,scope:string,audience:?string,jti:string}|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function lookupAccessToken(string $jwt): ?array
    {
        if ($jwt === '') {
            return null;
        }

        try {
            $parsed = $this->_parseAndValidateJwt($jwt);
        } catch (Throwable) {
            return null;
        }

        if ($parsed === null) {
            return null;
        }

        $claims = $parsed->claims();

        // `jti` is league's token identifier — that's what
        // `AccessTokenRepository::isAccessTokenRevoked()` keys on.
        $jti = $claims->get('jti');
        if (!is_string($jti) || $jti === '') {
            return null;
        }

        $accessRepo = new AccessTokenRepository();
        if ($accessRepo->isAccessTokenRevoked($jti)) {
            return null;
        }

        $userId = $claims->get('sub');
        $rawClientId = $claims->get('cid');
        if (!is_string($rawClientId) || $rawClientId === '') {
            // `cid` is absent — token was mis-issued or predates the
            // custom claim. Log for operator visibility; return empty
            // string per the array contract.
            Craft::warning(
                'OAuth access token presented without a `cid` claim. Token jti=' . $jti,
                'herald.oauth',
            );
            $rawClientId = '';
        }
        $audience = $this->_audienceFromClaims($claims->get('aud'));
        $scopes = $claims->get('scopes');

        return [
            'userId' => is_string($userId) || is_numeric($userId) ? (int) $userId : null,
            'clientId' => $rawClientId,
            'scope' => $this->_renderScopes($scopes),
            'audience' => $audience,
            'jti' => $jti,
        ];
    }

    /**
     * Cache key for the WS2 elevation marker bound to a Craft user.
     *
     * Elevation is keyed by the bound user's id, not the access token's
     * `jti`. The `/oauth/elevate` flow never carries the access token in
     * a URL — it elevates the *logged-in CP user* after a fresh password
     * (and 2FA) re-auth, and the dispatcher consults the marker for the
     * userId the access token is bound to. Per-user elevation within the
     * short `Settings::$elevationTtl` window is acceptable and mirrors
     * Craft's own session-elevation model (which is also per-user, not
     * per-credential).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function elevationCacheKey(int $userId): string
    {
        return 'herald:elevation:' . hash('sha256', (string) $userId);
    }

    /**
     * Mint an elevated marker for the Craft user identified by `$userId`,
     * valid for `Settings::$elevationTtl` seconds. Called by the
     * `/oauth/elevate` flow AFTER a fresh Craft re-authentication
     * (password + 2FA) has been verified for that exact user. Tracked
     * server-side in the cache — never a client-supplied claim.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function grantElevation(int $userId): void
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return;
        }
        $ttl = Herald::getInstance()->getSettings()->getElevationTtl();
        $cache->set($this->elevationCacheKey($userId), true, $ttl);

        Herald::getInstance()->audit->recordElevationGranted($userId);
    }

    /**
     * Whether the Craft user identified by `$userId` currently holds a
     * live elevation marker. Read by `McpController::beforeAction()` on
     * every HTTP request — against the userId the access token is bound
     * to — so high-stakes tools can gate on the elevated state threaded
     * through `InvocationContext`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function isElevated(int $userId): bool
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return false;
        }
        return $cache->get($this->elevationCacheKey($userId)) === true;
    }

    /**
     * RFC 7591 Dynamic Client Registration. Mints a fresh `clientId`,
     * validates the redirect URIs, and persists. Returns the
     * registration response shape RFC 7591 §3.2.1 mandates.
     *
     * Validation:
     *   - `client_name` required (max 255 chars).
     *   - `redirect_uris` required, must be a non-empty list.
     *   - Each redirect URI must be HTTPS, OR a localhost http URI
     *     (`http://localhost`, `http://127.0.0.1`) — the carve-out
     *     RFC 7591 §5 and OAuth 2.1 §5.1 explicitly bless for native
     *     and IDE clients.
     *   - URIs must not carry a fragment per RFC 6749 §3.1.2.
     *   - `token_endpoint_auth_method=none` marks the client public
     *     (PKCE-only, no secret); anything else is confidential.
     *
     * @param array<string,mixed> $payload Decoded JSON request body.
     * @return array{client_id:string,client_secret?:string,client_name:string,redirect_uris:array<int,string>,token_endpoint_auth_method:string,grant_types:array<int,string>,response_types:array<int,string>}
     *
     * @throws \InvalidArgumentException When the payload fails
     *                                   validation. The controller
     *                                   catches and surfaces as a
     *                                   400 with `error: invalid_client_metadata`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function registerClient(array $payload): array
    {
        $name = $this->_requireString($payload, 'client_name', maxLength: 255);
        $redirectUris = $this->_requireRedirectUriList($payload);
        $authMethod = $payload['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (!is_string($authMethod)) {
            throw new \InvalidArgumentException('`token_endpoint_auth_method` must be a string.');
        }

        $isPublic = $authMethod === 'none';

        $clientId = bin2hex(random_bytes(self::CLIENT_ID_BYTES));
        $secret = null;
        $secretHash = null;
        if (!$isPublic) {
            $secret = bin2hex(random_bytes(self::CLIENT_SECRET_BYTES));
            $secretHash = password_hash($secret, PASSWORD_DEFAULT);
        }

        // Capability scope vocabulary — let the client list which
        // scopes it wants but persist as space-delimited so it's a
        // single column. Unknown scope strings are dropped silently
        // rather than rejected — the scope filter on `/authorize` is
        // the enforcement point. Legacy `read` / `write` are accepted
        // here and expanded at grant time by `Scopes`.
        $scope = '';
        if (isset($payload['scope']) && is_string($payload['scope'])) {
            $requested = preg_split('/\s+/', trim($payload['scope']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $scope = implode(' ', Herald::getInstance()->scopes->filterKnown($requested));
        }

        $record = new OauthClientRecord();
        $record->clientId = $clientId;
        $record->clientName = $name;
        $record->redirectUris = json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES) ?: '[]';
        $record->scope = $scope !== '' ? $scope : null;
        $record->isPublic = $isPublic;
        // DCR approval gate (WS3): new clients land UNAPPROVED unless
        // the operator has opted into auto-approval for a trusted /
        // dev install. The authorize + token flows reject an
        // unapproved client until an admin approves it on the Clients
        // CP screen.
        $record->approved = Herald::getInstance()->getSettings()->dcrAutoApprove;
        $record->clientSecretHash = $secretHash;

        if (!$record->save()) {
            throw new \InvalidArgumentException(sprintf(
                'Failed to register client: %s',
                implode(', ', $record->getFirstErrors()),
            ));
        }

        Herald::getInstance()->audit->recordClientRegistered(
            (int) $record->id,
            $clientId,
            $isPublic,
            (bool) $record->approved,
        );

        $response = [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => $isPublic ? 'none' : 'client_secret_basic',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
        if ($scope !== '') {
            $response['scope'] = $scope;
        }
        if ($secret !== null) {
            $response['client_secret'] = $secret;
        }

        return $response;
    }

    /**
     * Every registered OAuth client, newest first. Drives the Clients
     * CP screen's VueAdminTable. Returns the raw records so the
     * controller can serialise the columns it needs (approval status,
     * redirect URIs, etc.).
     *
     * @return OauthClientRecord[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getAllClients(): array
    {
        /** @var OauthClientRecord[] $records */
        $records = OauthClientRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
        return $records;
    }

    /**
     * Approve a registered client by its row id so its authorize +
     * token flows resolve. Returns true when a row was matched and
     * flipped, false when the id is unknown. Idempotent: approving an
     * already-approved client is a no-op that still returns true.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function approveClient(int $id): bool
    {
        $record = OauthClientRecord::findOne(['id' => $id]);
        if (!$record instanceof OauthClientRecord) {
            return false;
        }
        if ((bool) $record->approved) {
            return true;
        }
        $record->approved = true;
        $saved = $record->save();
        if ($saved) {
            Herald::getInstance()->audit->recordClientApproved((int) $record->id, (string) $record->clientId);
        }
        return $saved;
    }

    /**
     * Revoke a registered client: flip it back to unapproved AND revoke
     * every live access + refresh token it issued, so an approved
     * client an admin no longer trusts is cut off immediately rather
     * than only being blocked from re-authorizing. Returns true when a
     * row was matched, false when the id is unknown.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function revokeClient(int $id): bool
    {
        $record = OauthClientRecord::findOne(['id' => $id]);
        if (!$record instanceof OauthClientRecord) {
            return false;
        }

        $record->approved = false;
        $saved = $record->save();

        // Cut off in-flight credentials too — un-approving a client
        // should not leave its already-issued access tokens live until
        // they expire.
        OauthTokenRecord::updateAll(
            ['dateRevoked' => Carbon::now()->toDateTimeString()],
            ['clientId' => $record->clientId, 'dateRevoked' => null],
        );

        Herald::getInstance()->audit->recordClientRevoked((int) $record->id, (string) $record->clientId);

        return $saved;
    }

    /**
     * RFC 7009 token revocation. Looks the token up by SHA-256 hash
     * (matching access + refresh both — the spec lets callers revoke
     * either type with the same endpoint), flips `dateRevoked` if
     * present, and returns whether a row was actually updated.
     *
     * Per spec, an unknown token returns success too — `revoke()`'s
     * boolean is for herald internal use, not for shaping the HTTP
     * response. The controller always returns 200.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function revokeToken(string $plaintext): bool
    {
        if ($plaintext === '') {
            return false;
        }

        // For JWT access tokens, the revocation lookup keys on the
        // `jti` claim's SHA-256. Try parsing as a JWT first — if it
        // parses, hash the jti; otherwise hash the plaintext directly
        // (refresh tokens are opaque).
        $hash = $this->_hashRevocationKey($plaintext);
        if ($hash === null) {
            return false;
        }

        $count = OauthTokenRecord::updateAll(
            ['dateRevoked' => Carbon::now()->toDateTimeString()],
            ['tokenHash' => $hash, 'dateRevoked' => null],
        );

        return $count > 0;
    }

    /**
     * Prune dead OAuth rows during Craft's gc sweep. Deletes:
     *
     *   - authorization codes whose `expiresAt` has passed
     *     (`herald_oauth_codes`), and
     *   - access / refresh tokens that are either expired (`expiresAt`
     *     in the past) or revoked (`dateRevoked` set)
     *     (`herald_oauth_tokens`).
     *
     * Both deletes are fail-closed-safe. Every repository
     * (`AccessTokenRepository`, `AuthCodeRepository`,
     * `RefreshTokenRepository`) treats a missing row as
     * revoked/invalid, so dropping an already-expired or already-
     * revoked row can never resurrect a credential that should be
     * rejected — the validation path returns the same answer whether
     * the dead row is present or gone. Active refresh-rotation chains
     * (non-expired, non-revoked tokens) are never touched, so a quiet
     * client's refresh ability survives the sweep.
     *
     * Pruned unconditionally — expired/revoked rows are always safe to
     * drop, so there's no retention knob to honour (unlike the audit
     * log's `auditRetentionDays`). Returns the total rows deleted
     * across both tables.
     *
     * Wired to `Gc::EVENT_RUN` in `PluginTrait::_registerGcListener()`
     * alongside the runtime-override and invocation prunes.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function pruneExpired(): int
    {
        $now = Carbon::now()->toDateTimeString();

        $codes = (int) OauthCodeRecord::deleteAll(['<', 'expiresAt', $now]);

        $tokens = (int) OauthTokenRecord::deleteAll([
            'or',
            ['<', 'expiresAt', $now],
            ['not', ['dateRevoked' => null]],
        ]);

        return $codes + $tokens;
    }

    /**
     * Absolute filesystem path to the private key. Resolves through
     * Craft's storage alias so multi-host installs that share storage
     * stay coherent.
     *
     * @throws InvalidConfigException When the file is missing.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getPrivateKeyPath(): string
    {
        $path = $this->_keysDir() . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILE;
        if (!is_file($path)) {
            throw new InvalidConfigException(sprintf(
                'OAuth private key not found at %s. Run `craft herald/oauth/init-keys` to generate.',
                $path,
            ));
        }
        return $path;
    }

    /**
     * Absolute filesystem path to the public key.
     *
     * @throws InvalidConfigException When the file is missing.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getPublicKeyPath(): string
    {
        $path = $this->_keysDir() . DIRECTORY_SEPARATOR . self::PUBLIC_KEY_FILE;
        if (!is_file($path)) {
            throw new InvalidConfigException(sprintf(
                'OAuth public key not found at %s. Run `craft herald/oauth/init-keys` to generate.',
                $path,
            ));
        }
        return $path;
    }

    /**
     * Directory holding the JWT key pair. Resolves through Craft's
     * storage path so the keys live alongside other long-lived
     * server-side state.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getKeysDirectory(): string
    {
        return $this->_keysDir();
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolve the JWT keys directory. Builds it from
     * `Craft::$app->getPath()->getStoragePath()` + the herald
     * subdir.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _keysDir(): string
    {
        return Craft::$app->getPath()->getStoragePath() . DIRECTORY_SEPARATOR . self::KEYS_SUBDIR;
    }

    /**
     * Resolve league's symmetric encryption key. Derived
     * deterministically from Craft's app-level `securityKey` so the
     * encryption key rotates only when Craft's security key does —
     * which is exactly the rotation cadence operators already manage.
     *
     * Returned as a base64-encoded 32-byte string per league's
     * documented contract.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _encryptionKey(): string
    {
        $security = Craft::$app->getConfig()->getGeneral();
        $seed = is_string($security->securityKey) && $security->securityKey !== ''
            ? $security->securityKey
            : (string) App::env('CRAFT_SECURITY_KEY');

        if ($seed === '') {
            throw new InvalidConfigException(
                'Craft security key is not configured; herald OAuth cannot derive an encryption key. Set CRAFT_SECURITY_KEY in .env.',
            );
        }

        // 32 bytes derived via HMAC-SHA256 of a stable label against
        // the security key — keeps it deterministic across processes
        // without storing a second secret.
        return base64_encode(hash_hmac('sha256', 'herald:oauth:enc', $seed, true));
    }

    /**
     * Parse + signature-verify a JWT against the install's public key.
     * Returns the parsed token, or null if anything (signature, expiry,
     * `nbf`, or structure) fails.
     *
     * Uses lcobucci's standard asymmetric-signer + Validator pipeline:
     *
     *   - `SignedWith` — verifies the RS256 signature against the public key.
     *   - `StrictValidAt` — validates `iat`, `nbf`, and `exp` in a single
     *     constraint, rejecting future-`nbf` tokens and already-expired tokens.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _parseAndValidateJwt(string $jwt): ?UnencryptedToken
    {
        if ($jwt === '') {
            return null;
        }

        $publicKeyContent = file_get_contents($this->getPublicKeyPath());
        if ($publicKeyContent === false || $publicKeyContent === '') {
            return null;
        }

        $signer = new Sha256();
        $publicKey = InMemory::plainText($publicKeyContent);

        $config = Configuration::forAsymmetricSigner(
            $signer,
            InMemory::plainText('not-used-for-verification'),
            $publicKey,
        );

        try {
            $token = $config->parser()->parse($jwt);
        } catch (Throwable) {
            return null;
        }

        if (!$token instanceof UnencryptedToken) {
            return null;
        }

        $constraints = [
            new SignedWith($signer, $publicKey),
            new StrictValidAt(SystemClock::fromUTC()),
        ];

        if (!$config->validator()->validate($token, ...$constraints)) {
            return null;
        }

        return $token;
    }

    /**
     * Render a list of scope identifiers as a space-delimited string.
     * League stores them as a list of `ScopeEntity` objects on the
     * JWT; this method handles both raw strings and entity-shaped
     * arrays so the lookup is robust against either claim shape.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _renderScopes(mixed $scopes): string
    {
        if (is_string($scopes)) {
            return $scopes;
        }
        if (!is_array($scopes)) {
            return '';
        }
        $rendered = [];
        foreach ($scopes as $scope) {
            if (is_string($scope)) {
                $rendered[] = $scope;
            } elseif (is_array($scope) && isset($scope['identifier']) && is_string($scope['identifier'])) {
                $rendered[] = $scope['identifier'];
            }
        }
        return implode(' ', $rendered);
    }

    /**
     * Pull the audience string out of the JWT's `aud` claim. League's
     * builder writes `aud` as either a string or a single-element
     * list depending on shape; we handle both.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _audienceFromClaims(mixed $aud): ?string
    {
        if (is_string($aud)) {
            return $aud;
        }
        if (is_array($aud) && isset($aud[0]) && is_string($aud[0])) {
            return $aud[0];
        }
        return null;
    }

    /**
     * Validate + extract a required string field from the DCR
     * payload.
     *
     * @param array<string,mixed> $payload
     *
     * @throws \InvalidArgumentException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _requireString(array $payload, string $key, int $maxLength = 255): string
    {
        if (!isset($payload[$key])) {
            throw new \InvalidArgumentException("Missing required field: `{$key}`.");
        }
        $value = $payload[$key];
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("`{$key}` must be a non-empty string.");
        }
        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException("`{$key}` exceeds the {$maxLength}-char limit.");
        }
        return $value;
    }

    /**
     * Validate the redirect URI list from the DCR payload. RFC 7591
     * §2 requires a non-empty list; OAuth 2.1 §5.1 + RFC 7591 §5
     * carve out localhost http URIs for IDE / native clients.
     *
     * @param array<string,mixed> $payload
     * @return array<int,string>
     *
     * @throws \InvalidArgumentException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _requireRedirectUriList(array $payload): array
    {
        $uris = $payload['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === []) {
            throw new \InvalidArgumentException('`redirect_uris` must be a non-empty array.');
        }
        $validated = [];
        foreach ($uris as $uri) {
            if (!is_string($uri) || $uri === '') {
                throw new \InvalidArgumentException('Each redirect URI must be a non-empty string.');
            }
            $parsed = parse_url($uri);
            if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
                throw new \InvalidArgumentException("Invalid redirect URI: {$uri}");
            }
            if (isset($parsed['fragment'])) {
                throw new \InvalidArgumentException("Redirect URI must not contain a fragment: {$uri}");
            }
            $scheme = strtolower($parsed['scheme']);
            $host = strtolower($parsed['host']);
            $isLoopback = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]';
            if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
                throw new \InvalidArgumentException(
                    "Redirect URI must be HTTPS (or http://localhost for native clients): {$uri}",
                );
            }
            $validated[] = $uri;
        }
        return $validated;
    }

    /**
     * Hash the value the revocation `tokenHash` column keys on. For
     * a JWT access token, that's the `jti` claim; for an opaque
     * refresh token, the plaintext itself. The distinction matters
     * because RFC 7009 lets callers revoke either type with the same
     * endpoint without indicating which type was presented.
     *
     * Returns null when the plaintext is malformed (empty, not a JWT
     * and not a recognisable opaque format).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _hashRevocationKey(string $plaintext): ?string
    {
        if ($plaintext === '') {
            return null;
        }

        // Naive heuristic: JWTs contain two dots. Opaque refresh
        // tokens from league are 80-char base64-url strings — no dots.
        if (substr_count($plaintext, '.') === 2) {
            try {
                $token = (Configuration::forSymmetricSigner(
                    new Sha256(),
                    InMemory::plainText('empty', 'empty'),
                ))->parser()->parse($plaintext);
            } catch (Throwable) {
                return null;
            }
            if (!$token instanceof UnencryptedToken) {
                return null;
            }
            $jti = $token->claims()->get('jti');
            if (!is_string($jti) || $jti === '') {
                return null;
            }
            return hash('sha256', $jti);
        }

        return hash('sha256', $plaintext);
    }
}
