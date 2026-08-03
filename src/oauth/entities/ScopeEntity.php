<?php

namespace craftpulse\herald\oauth\entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * =========================================================================
 * OAuth scope entity — a typed name for a permission set.
 *
 * Phase 1 ships two scopes: `read` (the existing Free-tier read tools)
 * and `write` (reserved for Phase 2 Pro write tools — accepted at the
 * scope-registry level so DCR + authorize flows don't break, but no
 * tool currently honours it, and the per-tool `filterFor()` contract
 * in Gate 7.4 will gate write tools out of the Free registry
 * independently).
 *
 * Phase 3 expansion will add fine-grained scopes (`saveEntries:*`,
 * `editUsers`, etc.). The seam to add them is the `ScopeRepository`'s
 * `getScopeEntityByIdentifier()` switch — drop a new case, no other
 * code changes.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;
    use ScopeTrait;
}
