<?php

namespace craftpulse\cortex\values;

use DateTimeImmutable;

/**
 * =========================================================================
 * Immutable bucket-state snapshot returned by `RateLimiter`.
 *
 * Carries the post-operation view of a user's token bucket so callers
 * (the HTTP controller, CP widgets in Gate 9, ops introspection) can
 * project headroom without re-hitting the cache. The single return
 * shape across `check()` / `consume()` / `getStatus()` keeps call sites
 * uniform — a caller that only needs `remaining` reads the same field
 * regardless of which entry point produced the value.
 *
 * Constructor promotion locks every field as `public readonly`; the
 * value object is pure data with no setters, no `toArray()`, no
 * derived helpers. Callers that need to serialize the shape project
 * the fields explicitly so the wire shape is owned by the caller, not
 * the value object.
 *
 * `refilledAt` is a `DateTimeImmutable` rather than a `Carbon` because
 * value objects sit at the API boundary — anything that consumes a
 * status (controller-layer responses, Gate-9 CP widgets, future
 * GraphQL resolvers) reads immutable PHP-native datetimes. The
 * `RateLimiter` service uses Carbon internally per the services rule;
 * the conversion to `DateTimeImmutable` happens at this boundary.
 *
 * `retryAfter` is precomputed at construction so callers don't have to
 * reach for the refill rate to derive it. Zero when the bucket has at
 * least one token available; otherwise the ceil of
 * `(needed_tokens - remaining) / refillRate` so a `Retry-After` HTTP
 * header carries a sensible integer second count.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class RateLimitStatus
{
    // Public Methods
    // =========================================================================

    /**
     * @param int               $remaining  Tokens left in the bucket after
     *                                      the operation that produced this
     *                                      status. Floor of the bucket's
     *                                      fractional balance — the
     *                                      RateLimiter tracks fractional
     *                                      tokens internally for refill
     *                                      accuracy, but exposes whole
     *                                      tokens to callers.
     * @param int               $limit      Bucket capacity. Matches
     *                                      `Settings::$rateLimitBurst` at
     *                                      the time the status was
     *                                      produced.
     * @param DateTimeImmutable $refilledAt Instant the bucket was last
     *                                      (logically) refilled. The
     *                                      RateLimiter ticks the bucket
     *                                      forward on every call, so this
     *                                      is the call timestamp itself —
     *                                      effectively a "snapshot taken
     *                                      at" stamp.
     * @param int               $retryAfter Seconds the caller should wait
     *                                      before retrying. Zero when
     *                                      `remaining >= 1`; positive
     *                                      integer ceil of the refill
     *                                      delta otherwise.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function __construct(
        public readonly int $remaining,
        public readonly int $limit,
        public readonly DateTimeImmutable $refilledAt,
        public readonly int $retryAfter,
    ) {
    }
}
