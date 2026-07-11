<?php

namespace craftpulse\herald\oauth\entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * =========================================================================
 * OAuth authorization code entity. One-shot, ~5 minute TTL, exchanged
 * for tokens at `/oauth/token` and revoked on consumption.
 *
 * `AuthCodeTrait` adds `redirectUri` accessors; `TokenEntityTrait`
 * adds the standard token bits (client, user, scopes, expiry);
 * `EntityTrait` adds the identifier accessors.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class AuthCodeEntity implements AuthCodeEntityInterface
{
    use AuthCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;
}
