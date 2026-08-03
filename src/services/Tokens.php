<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\models\Token;
use craftpulse\herald\records\Token as TokenRecord;
use yii\base\Component;
use yii\base\Exception;
use yii\web\ForbiddenHttpException;

/**
 * =========================================================================
 * Bearer-token issuance, lookup, and revocation service.
 *
 * One row per admin-issued HTTP-transport credential. Plaintext tokens
 * are minted from `random_bytes(32)` (256 bits of entropy) rendered as
 * 64-char lowercase hex; only the SHA-256 hash of the plaintext is
 * stored. The plaintext is returned exactly once, from `issue()`. Every
 * subsequent service method round-trips the hash or the row id only;
 * the plaintext is never reconstructable.
 *
 * Per-request memoization on `lookup()` keeps the cost flat when
 * `McpController::beforeAction()` and (in 7.5) the audit-log writer
 * both need the bound user — one DB probe per request regardless of
 * how many call sites ask.
 *
 * Carbon over `DateTimeHelper` here per the services rule, always with
 * the zone named explicitly — see `COLUMN_TIME_ZONE`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Tokens extends Component
{
    // Constants
    // =========================================================================

    /**
     * Timezone every `herald_tokens` datetime column is written in and
     * read back in.
     *
     * Craft's datetime columns hold **naive** UTC strings: nothing in the
     * value records an offset, and `dateCreated` / `dateUpdated` are
     * stamped in UTC by `craft\db\ActiveRecord`. A bare `Carbon::now()`
     * follows the PHP process timezone, which Craft sets to
     * `system.timeZone` — so on any non-UTC install the columns this
     * service writes (`expiresAt`, `lastUsedAt`, `dateDeleted`) would
     * land in local wall-clock time next to UTC siblings in the same
     * row. Naming the zone rather than relying on the process default is
     * what keeps the row internally consistent.
     *
     * @since 5.0.0
     */
    public const COLUMN_TIME_ZONE = 'UTC';

    /**
     * Bytes of entropy when generating a token. 32 random bytes →
     * 64-char lowercase hex string, 256 bits of entropy — the same
     * order of magnitude as a JWT signing key. Cryptographically
     * indistinguishable from random for the purposes of bearer-token
     * lookup.
     *
     * @since 5.0.0
     */
    public const PLAINTEXT_ENTROPY_BYTES = 32;

    // Private Properties
    // =========================================================================

    /**
     * Per-request memoization of `lookup()` results keyed by token
     * hash. The HTTP controller calls `lookup()` once in
     * `beforeAction()`; subsequent call sites (audit logging in 7.5,
     * for instance) read through this cache to avoid re-querying.
     * Mutations (`revoke()`, `issue()`) reset the cache.
     *
     * @var array<string,Token|null>
     */
    private array $_lookupCache = [];

    // Public Methods
    // =========================================================================

    /**
     * Mint a new bearer token bound to a Craft user. Returns
     * `['token' => <plaintext>, 'model' => <Token>]` — the plaintext
     * is surfaced once for the caller to print, and never returned by
     * any other method on this service. `$ttlSeconds` defaults to
     * `Settings::$tokenTtlDefault` (null → no expiry) when omitted.
     *
     * `$scopes` is the capability set the token carries, drawn from
     * `Scopes::all()`. Unknown identifiers are dropped by
     * `Scopes::filterKnown()`; the surviving set is persisted
     * space-delimited in `scope`, matching the shape an OAuth access
     * token's `scope` claim uses so `McpController::_resolveBearer()`
     * parses both paths identically.
     *
     * **A token with no scopes is refused at the transport.** Passing
     * `null` or an empty list therefore mints a token that can never
     * authorise anything — deliberately, so an operator that forgets to
     * choose scopes gets a hard 403 naming the omission rather than a
     * token with silent unlimited authority. See the account-state and
     * scope gates in `McpController::beforeAction()`.
     *
     * Implementation note: `bin2hex(random_bytes(32))` produces a
     * 64-char lowercase hex string. The SHA-256 hex digest of that
     * string is also 64 chars by coincidence — the lookup compares
     * the hash of the caller-supplied plaintext against the stored
     * `tokenHash`, never the plaintext itself.
     *
     * **Pro only.** The Streamable HTTP transport is the only thing that
     * consumes a bearer token and it refuses Free installs at
     * `McpController::beforeAction()` before any bearer work, so a token
     * minted on Free could never authenticate anywhere. The CP screen and
     * its issue action are already Pro-gated; this is the service-level
     * gate, which is what the console `herald/token/issue` command hits.
     * Refusing here rather than minting a dead credential is the
     * difference between a clear error and a support ticket.
     *
     * @param string[]|null $scopes
     * @return array{token: string, model: Token}
     * @throws Exception when the underlying record fails validation or save.
     * @throws ForbiddenHttpException when the install is not licensed for the HTTP transport.
     * @throws \yii\base\InvalidConfigException from `Herald::getInstance()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function issue(int $userId, string $name, ?int $ttlSeconds = null, ?array $scopes = null): array
    {
        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ForbiddenHttpException(
                'Bearer tokens require the Herald Pro edition: the HTTP transport that consumes them is Pro-only.',
            );
        }

        $ttl = $ttlSeconds ?? Herald::getInstance()->getSettings()->getTokenTtlDefault();

        $plaintext = $this->_generatePlaintext();
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 8);

        $record = new TokenRecord();
        $record->name = $name;
        $record->tokenHash = $hash;
        $record->tokenPrefix = $prefix;
        $record->userId = $userId;
        $record->scope = $this->_normalizeScopes($scopes);
        $record->expiresAt = $ttl !== null
            ? Carbon::now(self::COLUMN_TIME_ZONE)->addSeconds($ttl)->toDateTimeString()
            : null;

        if (!$record->save()) {
            throw new Exception(sprintf(
                'Failed to issue bearer token for user #%d: %s',
                $userId,
                implode(', ', $record->getFirstErrors()),
            ));
        }

        // Reset the lookup cache so a subsequent same-request
        // `lookup()` of this token doesn't see a stale miss from a
        // pre-issuance probe.
        $this->_lookupCache = [];

        Herald::getInstance()->audit->recordTokenIssued((int) $record->id, $name, $userId);

        return [
            'token' => $plaintext,
            'model' => $this->_hydrate($record),
        ];
    }

    /**
     * Look up a token by its plaintext form. Returns the bound model
     * on success or null when:
     *
     *   - the plaintext is malformed (empty / wrong length),
     *   - no row matches the SHA-256 hash,
     *   - the row is soft-deleted (`dateDeleted` is non-null),
     *   - the row has expired (`expiresAt` is in the past).
     *
     * On a hit, `lastUsedAt` is touched so operators can spot idle
     * tokens. Per-request memoization keyed by the hash means
     * repeated lookups inside one request hit the DB once.
     *
     * The plaintext is hashed *before* the DB probe so a malicious
     * `like` injection against the lookup query is impossible — the
     * input space is `[0-9a-f]{64}` by construction.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function lookup(string $plaintext): ?Token
    {
        if ($plaintext === '') {
            return null;
        }

        $hash = hash('sha256', $plaintext);

        if (array_key_exists($hash, $this->_lookupCache)) {
            return $this->_lookupCache[$hash];
        }

        $record = TokenRecord::find()
            ->where(['tokenHash' => $hash, 'dateDeleted' => null])
            ->one();

        if (!$record instanceof TokenRecord) {
            return $this->_lookupCache[$hash] = null;
        }

        // Expiry check happens in PHP rather than as part of the SQL
        // filter so the path stays the same for the null-expiry case
        // (no expiry) and the explicit-expiry case. Comparing Carbon
        // instances avoids subtle DB-string-format mismatches.
        //
        // The zone is named on the parse for the same reason it is named
        // on the write: the column holds a naive UTC string, while the
        // process timezone is `system.timeZone`. A bare `Carbon::parse()`
        // would read the value as local time and move every expiry by the
        // install's UTC offset.
        if ($record->expiresAt !== null && Carbon::parse($record->expiresAt, self::COLUMN_TIME_ZONE)->isPast()) {
            return $this->_lookupCache[$hash] = null;
        }

        $record->lastUsedAt = Carbon::now(self::COLUMN_TIME_ZONE)->toDateTimeString();
        // `lastUsedAt` is a free-text timestamp column with no rule
        // attached on the Record; saving without validation skips the
        // FK / required-field round-trip and keeps the lookup hot
        // path single-statement.
        $record->save(false);

        return $this->_lookupCache[$hash] = $this->_hydrate($record);
    }

    /**
     * Soft-delete a token by its id. Returns true when a row was
     * matched, false when the id is unknown or already revoked.
     * Resets the lookup cache so a same-request follow-up `lookup()`
     * sees the revocation.
     *
     * @throws Exception when the underlying record fails to save.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function revoke(int $id): bool
    {
        $record = TokenRecord::findOne(['id' => $id, 'dateDeleted' => null]);
        if (!$record instanceof TokenRecord) {
            return false;
        }

        $record->dateDeleted = Carbon::now(self::COLUMN_TIME_ZONE)->toDateTimeString();
        if (!$record->save()) {
            throw new Exception(sprintf(
                'Failed to revoke bearer token #%d: %s',
                $id,
                implode(', ', $record->getFirstErrors()),
            ));
        }

        $this->_lookupCache = [];

        Herald::getInstance()->audit->recordTokenRevoked($id);

        return true;
    }

    /**
     * Fetch a token by id. Returns null when the id is unknown or the
     * row is soft-deleted. Used by the console controller's
     * `revoke <id>` flow to surface the row before / after for the
     * operator's confirmation.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getById(int $id): ?Token
    {
        $record = TokenRecord::findOne(['id' => $id, 'dateDeleted' => null]);
        if (!$record instanceof TokenRecord) {
            return null;
        }
        return $this->_hydrate($record);
    }

    /**
     * All non-deleted tokens belonging to one Craft user, newest
     * first. Drives the per-user filter on `herald/token/list`.
     *
     * @return Token[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAllForUser(int $userId): array
    {
        /** @var TokenRecord[] $records */
        $records = TokenRecord::find()
            ->where(['userId' => $userId, 'dateDeleted' => null])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
        return array_map(fn(TokenRecord $r): Token => $this->_hydrate($r), $records);
    }

    /**
     * Every non-deleted token across all users, newest first. Drives
     * the unfiltered `herald/token/list`.
     *
     * @return Token[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAll(): array
    {
        /** @var TokenRecord[] $records */
        $records = TokenRecord::find()
            ->where(['dateDeleted' => null])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
        return array_map(fn(TokenRecord $r): Token => $this->_hydrate($r), $records);
    }

    // Private Methods
    // =========================================================================

    /**
     * Normalise a caller-supplied scope list into the space-delimited
     * string the `scope` column stores, or null when nothing grantable
     * survives filtering.
     *
     * Null is the deny sentinel, not an "unscoped" one: the HTTP
     * transport refuses a token whose stored scope is null. Returning
     * null for an empty or wholly-unknown list therefore fails closed
     * instead of minting silent unlimited authority.
     *
     * @param string[]|null $scopes
     * @throws \yii\base\InvalidConfigException from `Herald::getInstance()`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _normalizeScopes(?array $scopes): ?string
    {
        if ($scopes === null) {
            return null;
        }

        $known = Herald::getInstance()->scopes->filterKnown($scopes);

        return $known === [] ? null : implode(' ', $known);
    }

    /**
     * Generate a fresh plaintext token. 32 bytes from the CSPRNG →
     * 64-char lowercase hex (256 bits of entropy). No shell exec, no
     * userspace PRNG.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _generatePlaintext(): string
    {
        return bin2hex(random_bytes(self::PLAINTEXT_ENTROPY_BYTES));
    }

    /**
     * Hydrate a `Token` model from a record. Keeps the model surface
     * isolated from ActiveRecord magic — service consumers see a
     * plain value object with typed properties.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _hydrate(TokenRecord $record): Token
    {
        $model = new Token();
        $model->id = (int) $record->id;
        $model->name = (string) $record->name;
        $model->tokenHash = (string) $record->tokenHash;
        $model->tokenPrefix = (string) $record->tokenPrefix;
        $model->userId = (int) $record->userId;
        $model->scope = $record->scope;
        $model->expiresAt = $record->expiresAt;
        $model->lastUsedAt = $record->lastUsedAt;
        $model->dateDeleted = $record->dateDeleted;
        $model->dateCreated = $record->dateCreated;
        $model->dateUpdated = $record->dateUpdated;
        $model->uid = (string) $record->uid;
        return $model;
    }
}
