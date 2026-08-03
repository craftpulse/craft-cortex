<?php

namespace craftpulse\herald\oauth\entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * =========================================================================
 * OAuth client entity — wraps a `herald_oauth_clients` row in the
 * shape league's grants expect.
 *
 * `ClientTrait` provides `getName`, `getRedirectUri`, `isConfidential`,
 * `setName`, `setRedirectUri`, and `setIsConfidential`. `EntityTrait`
 * provides `getIdentifier` and `setIdentifier`. This entity is a thin
 * envelope — the persistence layer (`ClientRepository`) hydrates it
 * from a Record and hands it to league.
 *
 * Public clients (PKCE-only, `isPublic = 1` on the Record) set
 * `isConfidential = false`. League's AuthCode grant uses
 * `$client->isConfidential()` to decide whether to require a secret
 * at the token endpoint.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class ClientEntity implements ClientEntityInterface
{
    use ClientTrait;
    use EntityTrait;

    // Public Methods
    // =========================================================================

    /**
     * Mark the client confidential or public. Public clients have no
     * `clientSecretHash` and authenticate via PKCE only.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function setIsConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
    }

    /**
     * Set the client name (rendered on the consent screen).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Set the registered redirect URI(s). League accepts a single
     * string or a list — herald always stores a list, even for
     * single-URI clients.
     *
     * @param string|string[] $redirectUri
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function setRedirectUri(string|array $redirectUri): void
    {
        $this->redirectUri = $redirectUri;
    }
}
