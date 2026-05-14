<?php

namespace craftpulse\cortex\oauth\entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * =========================================================================
 * OAuth refresh token entity — opaque (non-JWT) token bound to a
 * sibling access token.
 *
 * League's `RefreshTokenGrant` calls `setAccessToken()` to associate a
 * newly-issued access token with this refresh token; when the client
 * exchanges the refresh for a new access, league validates the
 * association before minting.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}
