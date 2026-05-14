<?php

namespace craftpulse\cortex\services;

use Carbon\Carbon;
use Craft;
use craftpulse\cortex\mcp\Session;
use craftpulse\cortex\Plugin;
use yii\base\Component;

/**
 * =========================================================================
 * HTTP-transport session store. PSR-16 cache backed.
 *
 * Sessions live in Craft's PSR-16 cache (`Craft::$app->getCache()`),
 * keyed by `cortex:session:{id}`. The session id is opaque and
 * cryptographically random; clients echo it back on every POST via the
 * `Mcp-Session-Id` header. Sliding TTL — every `touch()` resets the
 * cache entry's expiry — means an active session stays alive while
 * idle clients evict naturally.
 *
 * No DB write. The audit log captures session id on each invocation
 * line (sub-gate 7.5); the session itself is purely transient session
 * affinity.
 *
 * Carbon over `DateTimeHelper` here per the services rule — services
 * use Carbon, elements/queries use DateTimeHelper. Sessions writes
 * timestamps; it's a service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Sessions extends Component
{
    // Constants
    // =========================================================================

    /**
     * Prefix for cache keys. Namespaced under `cortex:session:` so we
     * never collide with another component's keys and so a future
     * `Cache::deleteByPattern(cortex:session:*)` style sweep stays
     * cheap.
     *
     * @since 5.0.0
     */
    public const CACHE_KEY_PREFIX = 'cortex:session:';

    /**
     * Bytes of entropy when generating a session id. 16 random bytes
     * → 32-char hex string — collision-resistant, fits well within
     * typical header size budgets, easy to log without truncation.
     *
     * @since 5.0.0
     */
    public const ID_ENTROPY_BYTES = 16;

    // Public Methods
    // =========================================================================

    /**
     * Create and persist a new session. The returned `Session` carries
     * a freshly-generated opaque id which the caller surfaces back to
     * the client in the `Mcp-Session-Id` response header.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function create(string $protocolVersion, ?string $clientName, ?int $userId = null): Session
    {
        $now = Carbon::now();
        $session = new Session(
            id: $this->_generateId(),
            createdAt: $now,
            lastSeenAt: $now,
            protocolVersion: $protocolVersion,
            clientName: $clientName,
            userId: $userId,
        );

        $this->_persist($session);
        return $session;
    }

    /**
     * Fetch a session by id, or null if it's not in the cache (never
     * created, expired, or terminated).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function get(string $id): ?Session
    {
        if ($id === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        $blob = $cache->get(self::CACHE_KEY_PREFIX . $id);
        if (!is_array($blob)) {
            return null;
        }

        return Session::fromArray($blob);
    }

    /**
     * Mark the session active. Bumps `lastSeenAt` and re-writes the
     * cache entry with a fresh TTL — sliding expiry. No-op when the
     * session id is unknown.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function touch(string $id): void
    {
        $session = $this->get($id);
        if ($session === null) {
            return;
        }

        $session->lastSeenAt = Carbon::now();
        $this->_persist($session);
    }

    /**
     * Remove the session from the cache. Subsequent `get()` calls
     * return null, which the controller surfaces as a 404 to the
     * client per the MCP spec ("Servers MAY terminate sessions at any
     * time, after which they MUST respond to requests containing that
     * session ID with HTTP 404 Not Found").
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function terminate(string $id): void
    {
        if ($id === '') {
            return;
        }
        Craft::$app->getCache()->delete(self::CACHE_KEY_PREFIX . $id);
    }

    // Private Methods
    // =========================================================================

    /**
     * Cryptographically random session id. 16 bytes from
     * `random_bytes()` → 32 lowercase hex chars. Uses the CSPRNG; no
     * shell, no userspace PRNG.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _generateId(): string
    {
        return bin2hex(random_bytes(self::ID_ENTROPY_BYTES));
    }

    /**
     * Write the session to cache with the configured sliding TTL.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _persist(Session $session): void
    {
        $ttl = Plugin::getInstance()->getSettings()->sessionTtl;
        Craft::$app->getCache()->set(
            self::CACHE_KEY_PREFIX . $session->id,
            $session->toArray(),
            $ttl,
        );
    }
}
