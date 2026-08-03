<?php

namespace craftpulse\herald\oauth\repositories;

use Carbon\Carbon;
use craftpulse\herald\oauth\entities\AuthCodeEntity;
use craftpulse\herald\records\OauthCode as OauthCodeRecord;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `AuthCodeRepositoryInterface`.
 *
 * Persists one-shot authorization codes. The code identifier is a
 * 80-char random string league mints in `generateUniqueIdentifier()`;
 * we store it as the primary key on `herald_oauth_codes` so
 * `isAuthCodeRevoked()` is a single equality probe.
 *
 * `revokeAuthCode()` flips the `isRevoked` bit rather than deleting
 * the row — preserves audit history for the brief 5-minute window the
 * code is alive. `Oauth::pruneExpired()`, wired to `Gc::EVENT_RUN` in
 * `PluginTrait::_registerGcListener()`, deletes rows whose `expiresAt`
 * has passed during Craft's gc sweep; a missing row is treated as
 * revoked by `isAuthCodeRevoked()` below, so the prune is fail-closed.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    /**
     * @inheritdoc
     *
     * @throws UniqueTokenIdentifierConstraintViolationException When
     *         the generated identifier collides — vanishingly
     *         unlikely given 80 chars of entropy.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $record = new OauthCodeRecord();
        $record->code = $authCodeEntity->getIdentifier();
        $record->clientId = $authCodeEntity->getClient()->getIdentifier();
        $record->userId = $authCodeEntity->getUserIdentifier() !== null
            ? (int) $authCodeEntity->getUserIdentifier()
            : null;
        $record->redirectUri = $authCodeEntity->getRedirectUri();
        $record->scope = implode(' ', array_map(
            static fn($scope): string => $scope->getIdentifier(),
            $authCodeEntity->getScopes(),
        ));
        $record->isRevoked = false;
        $record->expiresAt = Carbon::instance($authCodeEntity->getExpiryDateTime())->toDateTimeString();

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
    public function revokeAuthCode(string $codeId): void
    {
        $record = OauthCodeRecord::findOne(['code' => $codeId]);
        if (!$record instanceof OauthCodeRecord) {
            return;
        }
        $record->isRevoked = true;
        $record->save(false);
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isAuthCodeRevoked(string $codeId): bool
    {
        $record = OauthCodeRecord::findOne(['code' => $codeId]);
        if (!$record instanceof OauthCodeRecord) {
            // Treat missing rows as revoked — league only asks once
            // per token exchange and a row that vanished isn't valid.
            return true;
        }
        return (bool) $record->isRevoked;
    }
}
