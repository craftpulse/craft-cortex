<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use Closure;
use Craft;
use craftpulse\herald\exceptions\RateLimitExceededException;
use craftpulse\herald\Herald;
use craftpulse\herald\values\RateLimitStatus;
use DateTimeImmutable;
use RuntimeException;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * =========================================================================
 * Per-user token-bucket rate limiter. PSR-16 cache backed.
 *
 * One bucket per Craft user id, keyed by
 * `herald:ratelimit:user:{userId}` in Craft's cache. Each request to
 * the HTTP transport's `actionIndex()` consumes one token; refills
 * accrue at `Settings::$rateLimitPerSecond` tokens per second, clamped
 * to `Settings::$rateLimitBurst`. A fresh user (or a user whose bucket
 * has aged out of cache) starts at full capacity.
 *
 * Default sizing per locked decision 9: burst 60, sustained 5/sec.
 * Tightens runaway agents (a single LLM caller cannot pin a CPU by
 * looping `tools/call` faster than ~5/sec average) without throttling
 * normal interactive use (a burst of 60 covers a multi-tool LLM
 * conversation turn).
 *
 * Atomicity caveat: the consume is a `get → recompute → set` cycle
 * against PSR-16, which doesn't natively expose CAS. Under highly
 * concurrent load against the same user id, a second request landing
 * between the `get` and the `set` can re-read the pre-consume state
 * and effectively leak a token. The worst case is bounded — a tight
 * race window at the granularity of the cache adapter's round-trip
 * latency, against a 60-token bucket refilling at 5/sec — so the
 * extra leak is operationally invisible. A `yii\mutex\Mutex`-backed
 * critical section would close the gap, but at the cost of an extra
 * round-trip on every dispatch; we've judged that overkill for the
 * sizing here and documented it for future revisit if the bucket
 * size or refill rate ever changes meaningfully.
 *
 * Bucket internal shape: `{tokens: float, lastRefillAt: float}` where
 * `tokens` is the current bucket level (fractional for refill
 * accuracy) and `lastRefillAt` is a unix timestamp of when the
 * fractional balance was last computed. On each call: compute elapsed
 * since `lastRefillAt`, add `elapsed * refillRate` (clamped to
 * burst), then optionally consume.
 *
 * Carbon over `DateTimeHelper` here per the services rule. The
 * `DateTimeImmutable` surfacing on `RateLimitStatus` is the value-
 * object boundary, not the internal time source.
 *
 * Clock injection: `$_now` is a closure returning the current
 * `DateTimeImmutable`. Defaults to a Carbon-backed thunk so the
 * service uses real wall time in production. Tests pass a controlled
 * closure to drive synthetic time forward without sleeping. The
 * default thunk re-reads Carbon on every call so `Carbon::setTestNow()`
 * is honoured if any sibling test happens to be using it.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class RateLimiter extends Component
{
    // Constants
    // =========================================================================

    /**
     * Prefix for cache keys. Namespaced under `herald:ratelimit:user:`
     * so we never collide with other component keys and so a future
     * pattern-based sweep stays cheap.
     *
     * @since 5.0.0
     */
    public const CACHE_KEY_PREFIX = 'herald:ratelimit:user:';

    // Private Properties
    // =========================================================================

    /**
     * @var Closure(): DateTimeImmutable Clock thunk. Production uses a
     *                                    Carbon-backed thunk; tests swap
     *                                    in a controlled closure to
     *                                    drive synthetic time forward.
     *                                    The closure is re-invoked on
     *                                    every call so a test that
     *                                    advances Carbon's mock clock
     *                                    via `Carbon::setTestNow()`
     *                                    sees the new value without
     *                                    re-wiring the service.
     */
    private Closure $_now;

    // Public Methods
    // =========================================================================

    /**
     * @param Closure(): DateTimeImmutable|null $now Clock thunk override.
     *                                               Defaults to a Carbon-
     *                                               backed thunk that
     *                                               reads wall time on
     *                                               every call.
     * @param array<string,mixed>               $config Yii component
     *                                                  configuration.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function __construct(?Closure $now = null, array $config = [])
    {
        $this->_now = $now ?? static fn(): DateTimeImmutable => Carbon::now()->toDateTimeImmutable();
        parent::__construct($config);
    }

    /**
     * Override the clock thunk after construction. Useful for tests
     * that need to drive synthetic time forward across multiple
     * `consume()` calls without re-resolving the component from the
     * plugin's DI container.
     *
     * @param Closure(): DateTimeImmutable $now
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function setNow(Closure $now): void
    {
        $this->_now = $now;
    }

    /**
     * Atomic token-bucket consume. Refills the bucket since
     * `lastRefillAt`, attempts to deduct `$cost` tokens, and writes
     * the resulting state back to the cache. Returns the post-consume
     * `RateLimitStatus` on success.
     *
     * Throws `RateLimitExceededException` carrying the (unchanged)
     * pre-consume status when the bucket lacks enough tokens. The
     * status's `retryAfter` field is the integer seconds the caller
     * should wait before retrying — the HTTP controller stamps this
     * onto the `Retry-After` response header.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function consume(int $userId, int $cost = 1): RateLimitStatus
    {
        return $this->_consume($userId, $cost);
    }

    /**
     * Token-bucket consume against an arbitrary string key rather than
     * a Craft user id. Backs the IP-keyed throttle on the unauthenticated
     * OAuth endpoints (`/oauth/register`, `/oauth/token`,
     * `/oauth/revoke`), which precede authentication and so cannot key
     * by user id. Callers namespace the key themselves
     * (e.g. `oauth:ip:<ip>`); the prefix below keeps it from colliding
     * with the user-keyed buckets. Shares the same bucket math, burst,
     * and refill rate as the per-user limiter.
     *
     * @throws RateLimitExceededException When the bucket lacks `$cost`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function consumeKey(string $key, int $cost = 1): RateLimitStatus
    {
        return $this->_consume($key, $cost);
    }

    /**
     * Non-persisting introspection / pre-flight ONLY. Walks the bucket
     * forward to `now` for refill accuracy but never writes the result
     * back, so successive `check()` calls each re-walk the refill arc
     * from the last persisted `lastRefillAt` and report progressively
     * more available tokens than a real `consume()` would.
     *
     * Never use `check()` as an admission gate: because it doesn't
     * persist, a `check()`-then-act flow double-counts refill against
     * the subsequent `consume()` and lets more requests through than
     * the bucket should permit. The atomic admission path is
     * `consume()` — which refills, deducts, and persists in one step.
     * `check()` exists for read-only headroom inspection and test
     * pre-flight assertions, nothing more.
     *
     * Returns a `RateLimitStatus` with the bucket's available tokens
     * floor'd to int. Throws if the bucket lacks enough for `$cost`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function check(int $userId, int $cost = 1): RateLimitStatus
    {
        $state = $this->_loadAndRefill($userId);

        if ($state['tokens'] < $cost) {
            throw new RateLimitExceededException(
                $this->_buildExhaustedStatus($state['tokens'], $cost),
            );
        }

        return $this->_buildAvailableStatus($state['tokens'] - $cost);
    }

    /**
     * Snapshot of the current bucket state without consuming. The
     * shape mirrors `consume()`'s return — same fields, same
     * semantics — so CP widgets and ops introspection can read
     * headroom uniformly regardless of whether a consume just
     * happened. Walks the bucket forward to `now` (refill is read-
     * only here) but does not persist. The next live `consume()`
     * will re-walk the same arc.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getStatus(int $userId): RateLimitStatus
    {
        $state = $this->_loadAndRefill($userId);
        return $this->_buildAvailableStatus($state['tokens']);
    }

    /**
     * Reset a user's bucket back to full capacity. Useful for tests
     * that need to start from a known state; not exposed via any
     * controller. Operators that want to "forgive" a throttled user
     * can call this through a future admin console command, but the
     * service surface is currently test-only.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function clear(int $userId): void
    {
        $this->_cache()->delete($this->_cacheKey($userId));
    }

    /**
     * Reset an arbitrary string-keyed bucket back to full capacity.
     * Companion to `clear()` for the `consumeKey()` surface — used by
     * tests that need to start the IP-keyed throttle from a known
     * state.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function clearKey(string $key): void
    {
        $this->_cache()->delete($this->_cacheKey($key));
    }

    // Private Methods
    // =========================================================================

    /**
     * Shared token-bucket consume keyed by either a Craft user id or
     * an arbitrary string. Refills the bucket since `lastRefillAt`,
     * attempts to deduct `$cost` tokens, persists the resulting state,
     * and returns the post-consume status. Throws when the bucket
     * lacks enough tokens.
     *
     * @throws RateLimitExceededException When the bucket lacks `$cost`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _consume(int|string $key, int $cost): RateLimitStatus
    {
        $state = $this->_loadAndRefill($key);

        if ($state['tokens'] < $cost) {
            // Not enough tokens — surface the pre-consume snapshot
            // (which already reflects refill up to `now`) and throw.
            // Persist the refilled state so the next call doesn't
            // re-walk the same refill arc from a stale `lastRefillAt`.
            $this->_save($key, $state);
            throw new RateLimitExceededException(
                $this->_buildExhaustedStatus($state['tokens'], $cost),
            );
        }

        $state['tokens'] -= $cost;
        $this->_save($key, $state);

        return $this->_buildAvailableStatus($state['tokens']);
    }

    /**
     * Build a `RateLimitStatus` for a bucket that has at least one
     * token available. `retryAfter` is 0 when the projected
     * `remaining` is >= 1, otherwise the ceil of one refill-second.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _buildAvailableStatus(float $tokens): RateLimitStatus
    {
        $remaining = (int) floor($tokens);
        $refillRate = $this->_refillRate();
        return new RateLimitStatus(
            remaining: $remaining,
            limit: $this->_burst(),
            refilledAt: ($this->_now)(),
            retryAfter: $remaining >= 1 ? 0 : (int) ceil(1 / max($refillRate, 0.0001)),
        );
    }

    /**
     * Build a `RateLimitStatus` for an exhausted bucket — the
     * `retryAfter` is the integer ceil of the seconds needed to
     * refill the deficit, floored at 1 so the HTTP `Retry-After`
     * header always carries a positive integer.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _buildExhaustedStatus(float $tokens, int $cost): RateLimitStatus
    {
        $refillRate = $this->_refillRate();
        $retryAfter = (int) ceil(($cost - $tokens) / max($refillRate, 0.0001));
        return new RateLimitStatus(
            remaining: (int) floor($tokens),
            limit: $this->_burst(),
            refilledAt: ($this->_now)(),
            retryAfter: max($retryAfter, 1),
        );
    }

    /**
     * Read the user's bucket from the cache (or synthesize a fresh-
     * full one) and walk it forward to `now`. Returns the refilled
     * state without persisting — callers decide whether to write
     * back. The state shape is `{tokens: float, lastRefillAt: float}`
     * where `lastRefillAt` is a unix timestamp.
     *
     * @return array{tokens: float, lastRefillAt: float}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _loadAndRefill(int|string $key): array
    {
        $burst = (float) $this->_burst();
        $refillRate = $this->_refillRate();
        $now = ($this->_now)();
        $nowTs = (float) $now->getTimestamp() + ((float) $now->format('u') / 1_000_000);

        $cached = $this->_cache()->get($this->_cacheKey($key));
        if (!is_array($cached) || !isset($cached['tokens'], $cached['lastRefillAt'])) {
            // Fresh bucket — start at burst capacity.
            return [
                'tokens' => $burst,
                'lastRefillAt' => $nowTs,
            ];
        }

        $elapsed = max($nowTs - (float) $cached['lastRefillAt'], 0.0);
        $tokens = min(
            $burst,
            (float) $cached['tokens'] + ($elapsed * $refillRate),
        );

        return [
            'tokens' => $tokens,
            'lastRefillAt' => $nowTs,
        ];
    }

    /**
     * Persist the bucket state back to the cache. TTL is sized
     * generously — once the elapsed time alone exceeds
     * `burst / refillRate` seconds, the bucket would naturally
     * refill to full anyway, so an eviction at that horizon costs
     * nothing.
     *
     * @param array{tokens: float, lastRefillAt: float} $state
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _save(int|string $key, array $state): void
    {
        // TTL = ceiling of refill-to-full time, with a floor of 60s
        // so a tight refill rate doesn't churn the cache.
        $refillToFullSeconds = (int) ceil($this->_burst() / max($this->_refillRate(), 0.0001));
        $ttl = max($refillToFullSeconds, 60);
        $this->_cache()->set($this->_cacheKey($key), $state, $ttl);
    }

    /**
     * Resolve Craft's cache component. Mirrors the safety guard used
     * by `Sessions` — a misconfigured Craft install with no cache
     * surfaces as a clean RuntimeException rather than a null-method
     * call deeper in the stack.
     *
     * @throws RuntimeException When Craft is misconfigured to the
     *                          point of having no cache component.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            throw new RuntimeException('Craft cache component is not configured; herald rate limiter cannot persist bucket state.');
        }
        return $cache;
    }

    /**
     * Build the cache key for a bucket. A bare int is a Craft user id;
     * a string is an arbitrary caller-namespaced key (e.g. an IP-keyed
     * OAuth throttle). The shared prefix keeps both under one
     * namespace so the existing user-keyed format
     * (`herald:ratelimit:user:<id>`) is preserved unchanged.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _cacheKey(int|string $key): string
    {
        return self::CACHE_KEY_PREFIX . $key;
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _burst(): int
    {
        return Herald::getInstance()->getSettings()->getRateLimitBurst();
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _refillRate(): float
    {
        return (float) Herald::getInstance()->getSettings()->getRateLimitPerSecond();
    }
}
