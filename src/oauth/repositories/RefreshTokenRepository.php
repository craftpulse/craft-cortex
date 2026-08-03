<?php

namespace craftpulse\herald\oauth\repositories;

use Carbon\Carbon;
use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\oauth\entities\RefreshTokenEntity;
use craftpulse\herald\records\OauthToken as OauthTokenRecord;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `RefreshTokenRepositoryInterface`.
 *
 * Persists opaque refresh tokens to `herald_oauth_tokens` with
 * `tokenType = 'refresh'`. The plaintext refresh identifier is never
 * stored; we keep the SHA-256 only so revocation lookups match the
 * same shape as access-token revocation.
 *
 * Refresh tokens inherit the issuing client + user from their bound
 * access token (`$refreshTokenEntity->getAccessToken()`). They don't
 * carry their own audience — RFC 8707 audience binding is on the
 * access token only.
 *
 * **Rotation TOCTOU window.** League drives rotation as two separate
 * calls — `isRefreshTokenRevoked()` (the check) then, on a fresh
 * exchange, `revokeRefreshToken()` (the consume). Herald cannot wrap
 * both league calls in one transaction from inside these adapter
 * methods, so two genuinely-concurrent exchanges of the *same* refresh
 * token could in principle both pass the `consumedAt IS NULL` check
 * before either consumes. `revokeRefreshToken()` closes the worst of
 * this by consuming atomically: a single conditional `UPDATE … WHERE
 * consumedAt IS NULL` (wrapped in a transaction) so only the first
 * writer wins the row — a second simultaneous consumer sees zero
 * affected rows and does not re-stamp the rotation lineage. The narrow
 * residual window (both readers passing the earlier
 * `isRefreshTokenRevoked()` check) is acceptable: the loser's rotation
 * still succeeds at most once, and any genuine *replay* of an
 * already-consumed token still trips family-wide revocation. The
 * practical exposure is two near-simultaneous legitimate refreshes by
 * the same client — not a theft vector.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    /**
     * @inheritdoc
     *
     * @throws UniqueTokenIdentifierConstraintViolationException When
     *         the freshly-minted identifier collides.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $access = $refreshTokenEntity->getAccessToken();

        // Inherit the rotation family the sibling access token was
        // stamped with moments ago (the `Oauth` service's pending
        // slot, set by `AccessTokenRepository::persistNewAccessToken`).
        // Clear the slot afterwards so the next unrelated grant in the
        // same process doesn't inherit a stale family.
        $oauth = Herald::getInstance()->oauth;
        $familyId = $oauth->getPendingFamilyId();

        $record = new OauthTokenRecord();
        $record->tokenType = 'refresh';
        $record->tokenHash = hash('sha256', $refreshTokenEntity->getIdentifier());
        $record->userId = $access->getUserIdentifier() !== null
            ? (int) $access->getUserIdentifier()
            : null;
        $record->clientId = $access->getClient()->getIdentifier();
        $record->familyId = $familyId;
        $record->scope = implode(' ', array_map(
            static fn($scope): string => $scope->getIdentifier(),
            $access->getScopes(),
        ));
        $record->expiresAt = Carbon::instance($refreshTokenEntity->getExpiryDateTime())->toDateTimeString();

        if (!$record->save()) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }

        $oauth->setPendingFamilyId(null);
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $hash = hash('sha256', $tokenId);
        $now = Carbon::now()->toDateTimeString();
        $db = Craft::$app->getDb();

        // Consume atomically: a single conditional UPDATE inside a
        // transaction so only the FIRST concurrent consumer of this exact
        // token wins the `consumedAt IS NULL` row. A second simultaneous
        // exchange of the same token finds zero affected rows here and
        // does not inherit the family / re-stamp the lineage — closing
        // the double-consume half of the rotation TOCTOU (see class doc).
        $familyId = $db->transaction(function() use ($db, $hash, $now): ?string {
            $row = (new \craft\db\Query())
                ->select(['id', 'familyId'])
                ->from(OauthTokenRecord::tableName())
                ->where(['tokenType' => 'refresh', 'tokenHash' => $hash])
                ->one($db);

            if ($row === false || $row === null) {
                return null;
            }

            $affected = $db->createCommand()
                ->update(
                    OauthTokenRecord::tableName(),
                    ['dateRevoked' => $now, 'consumedAt' => $now],
                    ['id' => $row['id'], 'consumedAt' => null],
                )
                ->execute();

            // Only the winner re-stamps the rotation family onto the
            // rotated access + refresh pair.
            return $affected > 0 ? ($row['familyId'] ?? null) : null;
        });

        if ($familyId !== null) {
            Herald::getInstance()->oauth->setPendingFamilyId($familyId);
        }
    }

    /**
     * @inheritdoc
     *
     * Theft-detection seam. League calls this during refresh
     * validation, before consuming the token. Three outcomes:
     *
     *   - Row missing → treated as revoked (fail closed).
     *   - Row present, not revoked → valid; returns false and the
     *     rotation proceeds.
     *   - Row present AND consumed (`consumedAt` set) → a replay of an
     *     already-rotated refresh token: the canonical stolen-token
     *     signal. Revoke the ENTIRE family (every live access + refresh
     *     token in the lineage), emit a security event + audit row,
     *     and return true so league rejects the request. Per RFC 6819
     *     §5.2.2.3 / the OAuth 2.1 refresh-rotation BCP.
     *
     * A row revoked WITHOUT `consumedAt` (an operator / RFC 7009
     * revoke, or a family already wiped by a prior theft response)
     * returns true without re-triggering the family revoke — the
     * family is already gone, so re-revoking is a no-op and emitting a
     * second security event would be noise.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $hash = hash('sha256', $tokenId);
        $record = OauthTokenRecord::findOne(['tokenType' => 'refresh', 'tokenHash' => $hash]);
        if (!$record instanceof OauthTokenRecord) {
            return true;
        }

        if ($record->consumedAt !== null) {
            // Replay of a consumed refresh token. Burn the whole family.
            if ($record->familyId !== null) {
                Herald::getInstance()->oauth->revokeFamily(
                    $record->familyId,
                    'A consumed refresh token was presented again (replay).',
                );
            }
            return true;
        }

        return $record->dateRevoked !== null;
    }
}
