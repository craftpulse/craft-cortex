<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\models\Token;
use craftpulse\herald\records\Token as TokenRecord;
use yii\base\Component;
use yii\base\Exception;

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
 * Carbon over `DateTimeHelper` here per the services rule.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Tokens extends Component
{
    // Constants
    // =========================================================================

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
     * Implementation note: `bin2hex(random_bytes(32))` produces a
     * 64-char lowercase hex string. The SHA-256 hex digest of that
     * string is also 64 chars by coincidence — the lookup compares
     * the hash of the caller-supplied plaintext against the stored
     * `tokenHash`, never the plaintext itself.
     *
     * @return array{token: string, model: Token}
     * @throws Exception when the underlying record fails validation or save.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function issue(int $userId, string $name, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds ?? Herald::getInstance()->getSettings()->tokenTtlDefault;

        $plaintext = $this->_generatePlaintext();
        $hash = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 8);

        $record = new TokenRecord();
        $record->name = $name;
        $record->tokenHash = $hash;
        $record->tokenPrefix = $prefix;
        $record->userId = $userId;
        $record->scope = null;
        $record->expiresAt = $ttl !== null
            ? Carbon::now()->addSeconds($ttl)->toDateTimeString()
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
     * @author Craftpulse
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
        if ($record->expiresAt !== null && Carbon::parse($record->expiresAt)->isPast()) {
            return $this->_lookupCache[$hash] = null;
        }

        $record->lastUsedAt = Carbon::now()->toDateTimeString();
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
     * @author Craftpulse
     * @since  5.0.0
     */
    public function revoke(int $id): bool
    {
        $record = TokenRecord::findOne(['id' => $id, 'dateDeleted' => null]);
        if (!$record instanceof TokenRecord) {
            return false;
        }

        $record->dateDeleted = Carbon::now()->toDateTimeString();
        if (!$record->save()) {
            throw new Exception(sprintf(
                'Failed to revoke bearer token #%d: %s',
                $id,
                implode(', ', $record->getFirstErrors()),
            ));
        }

        $this->_lookupCache = [];
        return true;
    }

    /**
     * Fetch a token by id. Returns null when the id is unknown or the
     * row is soft-deleted. Used by the console controller's
     * `revoke <id>` flow to surface the row before / after for the
     * operator's confirmation.
     *
     * @author Craftpulse
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
     * @author Craftpulse
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
     * @author Craftpulse
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
     * Generate a fresh plaintext token. 32 bytes from the CSPRNG →
     * 64-char lowercase hex (256 bits of entropy). No shell exec, no
     * userspace PRNG.
     *
     * @author Craftpulse
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
     * @author Craftpulse
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
