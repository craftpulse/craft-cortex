<?php

namespace craftpulse\cortex\tools;

use Craft;
use craft\elements\User;

/**
 * =========================================================================
 * Server-side idempotency cache for Pro mutation tools.
 *
 * Extracted in Gate 8.4 once the Rule of 3+ was met (Entry, Category,
 * Tag, GlobalSet, Address — five consumers). All Pro content tools that
 * accept an `idempotencyKey` argument now share this implementation.
 *
 * Cache key shape: `{IDEMPOTENCY_CACHE_PREFIX}{userId}:{idempotencyKey}`.
 * Each consuming tool declares its own `IDEMPOTENCY_CACHE_PREFIX`
 * constant (`cortex:entry:idem:`, `cortex:category:idem:`, etc.) so the
 * cache namespace stays grep-able and the per-user scope is uniform.
 *
 * TTL is 24h (mirrors Stripe / GitHub idempotency conventions) without
 * being so long the cache fills up. Beyond TTL, the same key re-runs
 * the save — the LLM gets a fresh attempt.
 *
 * stdio path: no per-request identity, so no cache key, so no cache.
 * stdio is single-process and the LLM-driven retry path is unlikely to
 * fire there. Validation failures are NOT cached — the LLM may want to
 * retry with corrected field values.
 *
 * Trait contract:
 *   - Consuming class MUST declare a `IDEMPOTENCY_CACHE_PREFIX` string
 *     constant. PHP 8.2 supports class constants on traits at the
 *     `const` keyword level, but enforcing per-tool prefixes via
 *     `static::IDEMPOTENCY_CACHE_PREFIX` keeps the cache namespace
 *     readable in `cache/flush` output and avoids cross-tool collision.
 *   - Consuming class MUST extend `AbstractTool` (the trait's
 *     `static::` calls assume the interface but don't enforce it via
 *     `use` — PHP traits can't `extends`).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
trait IdempotencyTrait
{
    // Constants
    // =========================================================================

    /**
     * TTL applied to cached idempotency envelopes. 24 hours mirrors
     * common API conventions (Stripe, GitHub) without being so long
     * the cache table fills up. Beyond TTL, the same key re-runs the
     * save — the LLM gets a fresh attempt.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_TTL = 86400;

    /**
     * Max length for `idempotencyKey`. Matches the schema's
     * `maxLength` constraint upstream — execute() does not re-check.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    // Protected Methods
    // =========================================================================

    /**
     * Look up a cached idempotency envelope for the
     * `{userId, idempotencyKey}` pair. Returns the cached value (so
     * the caller can `return` it directly) or `null` when no cache
     * entry exists or the request didn't carry an idempotencyKey at
     * all.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _idempotencyCacheHit(array $arguments): ?array
    {
        $key = $this->_idempotencyCacheKey($arguments);
        if ($key === null) {
            return null;
        }

        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return null;
        }
        $cached = $cache->get($key);
        if (!is_array($cached)) {
            return null;
        }

        /** @var array<string,mixed> $cached */
        return $cached;
    }

    /**
     * Cache the success envelope under the `{userId, idempotencyKey}`
     * pair with the standard 24h TTL. No-op when no idempotencyKey was
     * supplied.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $envelope
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _cacheIdempotencyEnvelope(array $arguments, array $envelope): void
    {
        $key = $this->_idempotencyCacheKey($arguments);
        if ($key === null) {
            return;
        }
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return;
        }
        $cache->set($key, $envelope, self::IDEMPOTENCY_TTL);
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the cache key for an idempotent request, scoping by the
     * current user id so two users with the same key don't collide.
     * Returns null when no idempotencyKey was supplied or when the
     * user isn't resolved (stdio path falls into this case — stdio is
     * single-process and doesn't need server-side dedup).
     *
     * Uses `static::IDEMPOTENCY_CACHE_PREFIX` so each consuming tool
     * declares its own prefix and the cache namespace stays grep-able.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _idempotencyCacheKey(array $arguments): ?string
    {
        $idempotencyKey = $arguments['idempotencyKey'] ?? null;
        if (!is_string($idempotencyKey) || $idempotencyKey === '') {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            // stdio path — no per-request identity. Skip caching;
            // stdio is single-process and the LLM-driven retry path
            // is unlikely to fire there.
            return null;
        }

        return static::IDEMPOTENCY_CACHE_PREFIX . $user->id . ':' . $idempotencyKey;
    }
}
