<?php

namespace craftpulse\herald\oauth\repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `UserRepositoryInterface`.
 *
 * League invokes `getUserEntityByUserCredentials()` only for the
 * Password grant — herald doesn't enable that grant, so the method
 * unconditionally returns null. If a deployment somehow enabled
 * Password grant against this repository, the returned null causes
 * league to throw `OAuthServerException::invalidGrant` and reject
 * the request.
 *
 * The AuthCode + PKCE flow doesn't go through this method; it builds
 * its `UserEntity` directly in the consent screen (`OauthController::
 * actionAuthorize()`) from the Craft session user.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class UserRepository implements UserRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Herald does not enable Password grant. Returning null forces
     * league to throw `invalidGrant` if the grant ever leaks through.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getUserEntityByUserCredentials(
        string $username,
        string $password,
        string $grantType,
        ClientEntityInterface $clientEntity,
    ): ?UserEntityInterface {
        return null;
    }
}
