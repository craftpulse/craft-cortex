<?php

namespace craftpulse\cortex\oauth\repositories;

use Carbon\Carbon;
use craftpulse\cortex\oauth\entities\RefreshTokenEntity;
use craftpulse\cortex\records\OauthToken as OauthTokenRecord;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `RefreshTokenRepositoryInterface`.
 *
 * Persists opaque refresh tokens to `cortex_oauth_tokens` with
 * `tokenType = 'refresh'`. The plaintext refresh identifier is never
 * stored; we keep the SHA-256 only so revocation lookups match the
 * same shape as access-token revocation.
 *
 * Refresh tokens inherit the issuing client + user from their bound
 * access token (`$refreshTokenEntity->getAccessToken()`). They don't
 * carry their own audience — RFC 8707 audience binding is on the
 * access token only.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
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
     * @author Craftpulse
     * @since  5.0.0
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $access = $refreshTokenEntity->getAccessToken();

        $record = new OauthTokenRecord();
        $record->tokenType = 'refresh';
        $record->tokenHash = hash('sha256', $refreshTokenEntity->getIdentifier());
        $record->userId = $access->getUserIdentifier() !== null
            ? (int) $access->getUserIdentifier()
            : null;
        $record->clientId = $access->getClient()->getIdentifier();
        $record->scope = implode(' ', array_map(
            static fn($scope): string => $scope->getIdentifier(),
            $access->getScopes(),
        ));
        $record->expiresAt = Carbon::instance($refreshTokenEntity->getExpiryDateTime())->toDateTimeString();

        if (!$record->save()) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $hash = hash('sha256', $tokenId);
        OauthTokenRecord::updateAll(
            ['dateRevoked' => Carbon::now()->toDateTimeString()],
            ['tokenType' => 'refresh', 'tokenHash' => $hash, 'dateRevoked' => null],
        );
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $hash = hash('sha256', $tokenId);
        $record = OauthTokenRecord::findOne(['tokenType' => 'refresh', 'tokenHash' => $hash]);
        if (!$record instanceof OauthTokenRecord) {
            return true;
        }
        return $record->dateRevoked !== null;
    }
}
