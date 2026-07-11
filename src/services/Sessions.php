<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Session;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * =========================================================================
 * HTTP-transport session store. PSR-16 cache backed.
 *
 * Sessions live in Craft's PSR-16 cache (`Craft::$app->getCache()`),
 * keyed by `herald:session:{id}`. The session id is opaque and
 * cryptographically random; clients echo it back on every POST via the
 * `Mcp-Session-Id` header. Sliding TTL — every `touch()` resets the
 * cache entry's expiry — means an active session stays alive while
 * idle clients evict naturally.
 *
 * No DB write. The audit log captures session id on each invocation
 * line (sub-gate 7.5); the session itself is purely transient session
 * affinity.
 *
 * Not enumerable by design. The surface is create / get / touch /
 * terminate — there is no list/scan. PSR-16 exposes no key-scan
 * primitive, so a "currently-connected" or "live streaming" view
 * cannot be built from the cache, and a global force-disconnect
 * cannot be retrofitted here. This matches the Gate 9 plan: the CP
 * Activity tab is historical-only (it reads the `herald_invocations`
 * audit log), and there is deliberately no live-session view.
 *
 * Operator-side revocation therefore runs through bearer-token
 * revocation, NOT session enumeration. Revoking a token blocks the
 * next request that presents it — the `touch()` user-id-mismatch /
 * unknown-token paths surface as a 401 and force a re-handshake. An
 * in-flight stream is not killed by token revocation mid-frame; it
 * ends when the tool completes or the client disconnects (see
 * `McpController::_streamPost()` cooperative-cancel). There is no
 * server-initiated force-disconnect of an established session.
 *
 * Sliding TTL — every `touch()` resets the cache entry's expiry, so
 * an active client stays alive while idle clients evict naturally.
 * `touch()` fires once per request at the start of dispatch, BEFORE
 * the (possibly streaming) `tools/call` runs — it is not re-fired
 * mid-stream. The session therefore must outlive the longest single
 * stream from a single touch: `Settings::$sessionTtl` MUST exceed the
 * maximum stream wall-clock. The 3600s default clears this by two
 * orders of magnitude — streams are bounded by the SAPI's
 * `max_execution_time` (tens of seconds to a few minutes) and by the
 * cooperative client-disconnect cancel in `_streamPost()`, never an
 * hour. If you ever shorten `sessionTtl` below your longest expected
 * stream, refresh the session at stream start instead.
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
     * Prefix for cache keys. Namespaced under `herald:session:` so we
     * never collide with another component's keys and so a future
     * `Cache::deleteByPattern(herald:session:*)` style sweep stays
     * cheap.
     *
     * @since 5.0.0
     */
    public const CACHE_KEY_PREFIX = 'herald:session:';

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

        $blob = $this->_cache()->get(self::CACHE_KEY_PREFIX . $id);
        if (!is_array($blob)) {
            return null;
        }

        return Session::fromArray($blob);
    }

    /**
     * Mark the session active. Bumps `lastSeenAt` and re-writes the
     * cache entry with a fresh TTL — sliding expiry. Returns the
     * touched `Session` on success, or null when the session id is
     * unknown / terminated / mismatched.
     *
     * `$currentUserId` is the user id resolved by the bearer-token
     * lookup on this request. When the stored session's `userId`
     * doesn't match the current request's bearer token, the session
     * is terminated and null is returned — that's the mid-session
     * token-swap defense locked in `docs/plans/gate-7.md` item 16.
     * Sessions stored with `userId === null` (created before auth
     * resolved) pre-bind on the first matching touch.
     *
     * `null` is a "treat as not-found" sentinel — the HTTP
     * controller maps it to a 401, which the client handles the
     * same way as any other unknown session.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function touch(string $id, ?int $currentUserId = null): ?Session
    {
        $session = $this->get($id);
        if ($session === null) {
            return null;
        }

        // Mid-session token-swap detection. Pre-bound sessions only:
        // when the session carries a `userId` and the current request's
        // bearer token resolves to a different user, terminate the
        // session and report it as gone. The controller surfaces this
        // as a 401, which forces the client to re-handshake under the
        // correct identity.
        if ($currentUserId !== null && $session->userId !== null && $session->userId !== $currentUserId) {
            $this->terminate($id);
            return null;
        }

        // Late-bind the userId on the session when the bearer token
        // resolves to a user but the session was created without one.
        // This happens when the HTTP controller mints a session before
        // the bearer-token surface lands — keeps existing sessions
        // hot across the 7.1 → 7.2 cutover.
        if ($currentUserId !== null && $session->userId === null) {
            $session->userId = $currentUserId;
        }

        $session->lastSeenAt = Carbon::now();
        $this->_persist($session);
        return $session;
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
        $this->_cache()->delete(self::CACHE_KEY_PREFIX . $id);
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
        $ttl = Herald::getInstance()->getSettings()->sessionTtl;
        $this->_cache()->set(
            self::CACHE_KEY_PREFIX . $session->id,
            $session->toArray(),
            $ttl,
        );
    }

    /**
     * Narrow `Craft::$app->getCache()` to a non-null
     * `CacheInterface`. Yii's stub returns `CacheInterface|null`
     * because the cache component is technically optional; in
     * practice Craft always ships one, so a null return is a fatal
     * misconfiguration the install never reaches. The narrow
     * helper concentrates the assertion in one spot instead of
     * scattering `assert()` calls across each call site.
     *
     * @throws \RuntimeException When Craft is misconfigured to the
     *                           point of having no cache component.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            throw new \RuntimeException('Craft cache component is not configured; herald sessions cannot persist.');
        }
        return $cache;
    }
}
