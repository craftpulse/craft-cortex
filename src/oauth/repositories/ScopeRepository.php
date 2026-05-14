<?php

namespace craftpulse\cortex\oauth\repositories;

use craftpulse\cortex\oauth\entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `ScopeRepositoryInterface`.
 *
 * Cortex's Phase 1 scope vocabulary is two-valued: `read` and `write`.
 * Both pass `getScopeEntityByIdentifier()` so DCR + authorize flows
 * don't break for clients that ask for `write`, but no Phase 1 tool
 * honours `write` — the per-tool `filterFor()` contract that Gate 7.4
 * lands gates write tools out of the Free registry independently.
 *
 * Unknown scope identifiers return null. League then rejects the
 * authorization request with `invalid_scope`, which clients are
 * expected to handle by retrying with the listed scopes.
 *
 * `finalizeScopes()` is the per-request rewrite hook: league calls it
 * with the scopes the client requested + the grant type + the client
 * entity + the user. Phase 1 returns the requested scopes unchanged —
 * the Phase 3 fine-grained scope expansion will rewrite here.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    // Constants
    // =========================================================================

    /**
     * The Phase 1 scope vocabulary. Listed in metadata under
     * `scopes_supported` (RFC 8414). Phase 3 will expand; the
     * Phase 1 array is exposed publicly so the well-known metadata
     * controller stays in sync without a second source of truth.
     *
     * @var string[]
     *
     * @since 5.0.0
     */
    public const SUPPORTED_SCOPES = ['read', 'write'];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (!in_array($identifier, self::SUPPORTED_SCOPES, true)) {
            return null;
        }

        $entity = new ScopeEntity();
        $entity->setIdentifier($identifier);
        return $entity;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        return $scopes;
    }
}
