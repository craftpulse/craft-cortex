<?php

namespace craftpulse\herald\oauth\repositories;

use craftpulse\herald\Herald;
use craftpulse\herald\oauth\entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `ScopeRepositoryInterface`.
 *
 * Herald's scope vocabulary is the capability set owned by the `Scopes`
 * service (`content:read`, `content:write`, `assets:write`,
 * `schema:read`, `system:read`, `users:read`, `users:write`). The
 * legacy coarse `read` / `write`
 * scopes are still accepted at this boundary for back-compat — they're
 * expanded to their capability clusters at grant / dispatch time by
 * `Scopes::expandLegacyScopes()`.
 *
 * Unknown scope identifiers return null. League then rejects the
 * authorization request with `invalid_scope`, which clients are
 * expected to handle by retrying with the listed scopes.
 *
 * `finalizeScopes()` is the per-request rewrite hook: league calls it
 * with the scopes the client requested + the grant type + the client
 * entity + the user. Herald expands any legacy coarse scope to its
 * capability cluster here so the issued token carries fine-grained
 * scopes from the moment it's minted.
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
     * Back-compat alias for the capability scope vocabulary. Callers
     * that still reference the constant resolve the live `Scopes`
     * service list; the constant itself is kept for the legacy
     * `read` / `write` shorthand. Prefer `Herald::getInstance()->scopes->all()`
     * in new code.
     *
     * @var string[]
     *
     * @since 5.0.0
     */
    public const SUPPORTED_SCOPES = [
        'content:read',
        'content:write',
        'assets:write',
        'schema:read',
        'system:read',
        'users:read',
        'users:write',
    ];

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
        if ($identifier === '' || !Herald::getInstance()->scopes->isKnown($identifier)) {
            return null;
        }

        $entity = new ScopeEntity();
        $entity->setIdentifier($identifier);
        return $entity;
    }

    /**
     * @inheritdoc
     *
     * Expands legacy `read` / `write` scopes to capability clusters so
     * the issued token carries fine-grained scopes. Capability scopes
     * pass through unchanged.
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
        $identifiers = array_map(
            static fn(ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $scopes,
        );

        $expanded = Herald::getInstance()->scopes->expandLegacyScopes($identifiers);

        $finalized = [];
        foreach ($expanded as $identifier) {
            if ($identifier === '') {
                continue;
            }
            $entity = new ScopeEntity();
            $entity->setIdentifier($identifier);
            $finalized[] = $entity;
        }
        return $finalized;
    }
}
