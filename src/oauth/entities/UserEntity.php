<?php

namespace craftpulse\cortex\oauth\entities;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * =========================================================================
 * OAuth user entity — wraps a Craft user id in the shape league's
 * authorize endpoint expects.
 *
 * League's AuthCode grant calls `$authRequest->setUser($userEntity)`
 * before completing the authorization. The user identifier flows
 * through as the JWT `sub` claim on the access token, which our
 * `Oauth::lookupAccessToken()` reads back to resolve the Craft user.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class UserEntity implements UserEntityInterface
{
    use EntityTrait;
}
