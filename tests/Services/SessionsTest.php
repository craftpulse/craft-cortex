<?php

/**
 * =========================================================================
 * Sessions service tests — verify the create/get/touch/terminate round-
 * trip through Craft's PSR-16 cache.
 *
 * The cache backend is whatever the playground's `config/app.php`
 * configures (file cache by default). Each test ends with a
 * `terminate()` to evict the session it created — the bootstrap cache
 * survives across runs, so isolation matters.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\cortex\mcp\Session;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\services\Sessions;

beforeEach(function() {
    $this->service = Plugin::getInstance()->sessions;
});

it('create() generates a fresh id and round-trips through get()', function() {
    $session = $this->service->create(
        protocolVersion: '2025-06-18',
        clientName: 'pest',
    );

    expect($session)->toBeInstanceOf(Session::class);
    expect($session->id)->toBeString()->not->toBeEmpty();
    expect($session->protocolVersion)->toBe('2025-06-18');
    expect($session->clientName)->toBe('pest');
    expect($session->userId)->toBeNull();

    $fetched = $this->service->get($session->id);
    expect($fetched)->toBeInstanceOf(Session::class);
    expect($fetched->id)->toBe($session->id);
    expect($fetched->clientName)->toBe('pest');

    $this->service->terminate($session->id);
});

it('create() generates cryptographically distinct ids on consecutive calls', function() {
    $a = $this->service->create('2025-06-18', null);
    $b = $this->service->create('2025-06-18', null);

    expect($a->id)->not->toBe($b->id);
    // 16 random bytes -> 32 hex chars per `bin2hex(random_bytes(16))`.
    expect(strlen($a->id))->toBe(32);
    expect($a->id)->toMatch('/^[0-9a-f]+$/');

    $this->service->terminate($a->id);
    $this->service->terminate($b->id);
});

it('get() returns null for unknown ids', function() {
    expect($this->service->get('does-not-exist-anywhere-in-cache'))->toBeNull();
    expect($this->service->get(''))->toBeNull();
});

it('touch() bumps lastSeenAt and re-persists the session', function() {
    $session = $this->service->create('2025-06-18', 'pest');
    $originalLastSeen = $session->lastSeenAt;

    // Advance Carbon's clock far enough that the cache write's
    // resulting timestamp is clearly different from the create time.
    Carbon::setTestNow(Carbon::now()->addSeconds(5));

    $this->service->touch($session->id);

    $fetched = $this->service->get($session->id);
    expect($fetched)->toBeInstanceOf(Session::class);
    expect($fetched->lastSeenAt->getTimestamp())->toBeGreaterThan($originalLastSeen->getTimestamp());

    Carbon::setTestNow();
    $this->service->terminate($session->id);
});

it('touch() is a no-op when the session does not exist', function() {
    // No throw, no side effect.
    $this->service->touch('nonexistent-session');
    expect($this->service->get('nonexistent-session'))->toBeNull();
});

it('terminate() removes the session and subsequent get() returns null', function() {
    $session = $this->service->create('2025-06-18', null);
    expect($this->service->get($session->id))->not->toBeNull();

    $this->service->terminate($session->id);

    expect($this->service->get($session->id))->toBeNull();
});

it('terminate() handles unknown ids gracefully', function() {
    // No throw. The PSR-16 cache `delete` on an unknown key is a no-op.
    $this->service->terminate('never-existed');
    $this->service->terminate('');
    expect(true)->toBeTrue();
});

it('Session::toArray() / fromArray() round-trips with no drift', function() {
    $now = Carbon::now();
    $session = new Session(
        id: 'abc123',
        createdAt: $now,
        lastSeenAt: $now,
        protocolVersion: '2025-06-18',
        clientName: 'pest',
        userId: 42,
    );

    $row = $session->toArray();
    expect($row)->toHaveKeys(['id', 'createdAt', 'lastSeenAt', 'protocolVersion', 'clientName', 'userId']);

    $rebuilt = Session::fromArray($row);
    expect($rebuilt->id)->toBe($session->id);
    expect($rebuilt->clientName)->toBe($session->clientName);
    expect($rebuilt->userId)->toBe($session->userId);
    expect($rebuilt->protocolVersion)->toBe($session->protocolVersion);
    expect($rebuilt->createdAt->getTimestamp())->toBe($session->createdAt->getTimestamp());
});

it('the cache key prefix is namespaced under cortex:session:', function() {
    // Defensive — if the constant ever drifts, lookups will break
    // across deploys; pin the value.
    expect(Sessions::CACHE_KEY_PREFIX)->toBe('cortex:session:');
});
