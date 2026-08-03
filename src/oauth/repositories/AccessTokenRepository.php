<?php

namespace craftpulse\herald\oauth\repositories;

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\oauth\entities\AccessTokenEntity;
use craftpulse\herald\records\OauthToken as OauthTokenRecord;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `AccessTokenRepositoryInterface`.
 *
 * Persists JWT access tokens. The plaintext JWT is never stored; we
 * persist the SHA-256 of the JWT's `jti` claim so revocation can flip
 * the row without touching the secret-bearing JWT itself.
 *
 * `getNewToken()` mints a fresh `AccessTokenEntity` and stamps the
 * audience indicator onto it via `setAudience()` — the audience comes
 * from the herald `Oauth` service's per-request slot, which the
 * controller populated from the `resource=` query parameter.
 * `isAccessTokenRevoked()` returns true iff `dateRevoked IS NOT NULL`.
 *
 * Note: herald's audience binding is forwarded via the entity (not
 * through league's request object) because `getNewToken()`'s signature
 * doesn't carry the request. The `Oauth` service stamps the audience
 * onto each newly-issued token via the entity's `setAudience()`
 * after league returns it from `issueAccessToken()`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param ClientEntityInterface $clientEntity
     * @param array<int,\League\OAuth2\Server\Entities\ScopeEntityInterface> $scopes
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null,
    ): AccessTokenEntityInterface {
        $entity = new AccessTokenEntity();
        $entity->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $entity->addScope($scope);
        }
        if ($userIdentifier !== null && $userIdentifier !== '') {
            $entity->setUserIdentifier($userIdentifier);
        }

        // Stamp the pending RFC 8707 resource indicator onto the
        // entity. The herald `Oauth` service set it from the
        // controller's `resource=` parsing before invoking the grant;
        // this is where it gets baked into the JWT.
        $entity->setAudience(Herald::getInstance()->oauth->getPendingAudience());

        return $entity;
    }

    /**
     * @inheritdoc
     *
     * @throws UniqueTokenIdentifierConstraintViolationException When
     *         the SHA-256 of the freshly-minted JTI collides — never
     *         happens in practice given 40 chars of entropy.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $audience = null;
        if ($accessTokenEntity instanceof AccessTokenEntity) {
            $audience = $accessTokenEntity->getAudience();
        }

        // Rotation lineage. On the refresh flow the
        // `RefreshTokenRepository` stamped the presented refresh
        // token's family onto the `Oauth` service before this runs, so
        // the rotated access token inherits it. On the auth-code flow
        // the pending slot is null and we mint a fresh family — the
        // root of a new lineage.
        $oauth = Herald::getInstance()->oauth;
        $familyId = $oauth->getPendingFamilyId();
        if ($familyId === null) {
            $familyId = $oauth->newFamilyId();
            // Stash it so the sibling refresh token persisted moments
            // later in the same grant inherits the freshly-minted
            // family rather than minting a second one.
            $oauth->setPendingFamilyId($familyId);
        }

        $record = new OauthTokenRecord();
        $record->tokenType = 'access';
        $record->tokenHash = hash('sha256', $accessTokenEntity->getIdentifier());
        $record->userId = $accessTokenEntity->getUserIdentifier() !== null
            ? (int) $accessTokenEntity->getUserIdentifier()
            : null;
        $record->clientId = $accessTokenEntity->getClient()->getIdentifier();
        $record->familyId = $familyId;
        $record->scope = implode(' ', array_map(
            static fn($scope): string => $scope->getIdentifier(),
            $accessTokenEntity->getScopes(),
        ));
        $record->audience = $audience;
        $record->expiresAt = Carbon::instance($accessTokenEntity->getExpiryDateTime())->toDateTimeString();

        if (!$record->save()) {
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function revokeAccessToken(string $tokenId): void
    {
        $hash = hash('sha256', $tokenId);
        OauthTokenRecord::updateAll(
            ['dateRevoked' => Carbon::now()->toDateTimeString()],
            ['tokenType' => 'access', 'tokenHash' => $hash, 'dateRevoked' => null],
        );
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $hash = hash('sha256', $tokenId);
        $record = OauthTokenRecord::findOne(['tokenType' => 'access', 'tokenHash' => $hash]);
        if (!$record instanceof OauthTokenRecord) {
            // Missing row → treat as revoked. The grant only asks
            // this question for tokens we minted, so a miss means the
            // row was hand-deleted (operator action) which is
            // semantically equivalent to revoked.
            return true;
        }
        return $record->dateRevoked !== null;
    }
}
