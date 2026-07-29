<?php

/**
 * =========================================================================
 * RateLimiter service tests — verify the Gate 7.6 per-user token bucket.
 *
 * Covers the locked decisions:
 *   - Per-user bucket: a fresh user starts at full burst capacity.
 *   - Consume returns a `RateLimitStatus` whose `remaining` matches the
 *     bucket state after the consume.
 *   - 61 consecutive consumes against the default-sized bucket: first 60
 *     succeed, the 61st throws `RateLimitExceededException`.
 *   - Refill: after 1 simulated second, the bucket regenerates exactly
 *     `rateLimitPerSecond` tokens (capped to burst).
 *   - `check()` and `getStatus()` are non-mutating — bucket state before
 *     and after either call is identical.
 *   - `clear()` empties the bucket back to full capacity.
 *   - `retryAfter` is 0 when at least one token is available and
 *     positive when exhausted.
 *
 * Clock injection: tests pass a controlled closure via `setNow()` so
 * synthetic time advances without sleeping. Production wires a Carbon-
 * backed thunk in the constructor.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\exceptions\RateLimitExceededException;
use craftpulse\herald\Herald;
use craftpulse\herald\services\RateLimiter;
use craftpulse\herald\values\RateLimitStatus;

beforeEach(function() {
    $this->service = Herald::getInstance()->rateLimiter;
    $this->userId = 999001;

    // Reset to defaults regardless of what a prior test poked.
    $settings = Herald::getInstance()->getSettings();
    $this->originalBurst = $settings->rateLimitBurst;
    $this->originalRate = $settings->rateLimitPerSecond;
    $settings->rateLimitBurst = 60;
    $settings->rateLimitPerSecond = 5;

    // Start each test from a known-clean bucket so we don't inherit
    // residue from a sibling test.
    $this->service->clear($this->userId);

    // Drive the clock through a single mutable reference so each test
    // can advance time without re-wiring the service.
    $this->clock = new DateTimeImmutable('2026-05-14 10:00:00');
    $this->service->setNow(fn(): DateTimeImmutable => $this->clock);
});

afterEach(function() {
    $settings = Herald::getInstance()->getSettings();
    $settings->rateLimitBurst = $this->originalBurst;
    $settings->rateLimitPerSecond = $this->originalRate;
    $this->service->clear($this->userId);

    // Restore the production clock so any cross-suite consumers don't
    // see a frozen wall.
    $this->service->setNow(fn(): DateTimeImmutable => \Carbon\Carbon::now()->toDateTimeImmutable());
});

// -----------------------------------------------------------------------------
// consume() — happy path
// -----------------------------------------------------------------------------

it('consume() returns a RateLimitStatus with remaining = burst - cost on a fresh bucket', function() {
    $status = $this->service->consume($this->userId);

    expect($status)->toBeInstanceOf(RateLimitStatus::class);
    expect($status->remaining)->toBe(59);
    expect($status->limit)->toBe(60);
    expect($status->retryAfter)->toBe(0);
});

it('60 consecutive consume() calls succeed, the 61st throws RateLimitExceededException', function() {
    for ($i = 0; $i < 60; $i++) {
        $status = $this->service->consume($this->userId);
        expect($status->remaining)->toBe(59 - $i);
    }

    $caught = null;
    try {
        $this->service->consume($this->userId);
    } catch (RateLimitExceededException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitExceededException::class);
    expect($caught->status->remaining)->toBe(0);
    expect($caught->status->retryAfter)->toBeGreaterThan(0);
});

// -----------------------------------------------------------------------------
// Refill
// -----------------------------------------------------------------------------

it('after 1 second of simulated time the bucket refills 5 tokens: 5 more consumes succeed before another throw', function() {
    // Drain to empty.
    for ($i = 0; $i < 60; $i++) {
        $this->service->consume($this->userId);
    }

    // Advance the synthetic clock 1 second — refill rate is 5/sec by
    // default, so the bucket regenerates exactly 5 tokens.
    $this->clock = $this->clock->modify('+1 second');

    // Five fresh consumes must succeed.
    for ($i = 0; $i < 5; $i++) {
        $status = $this->service->consume($this->userId);
        expect($status->remaining)->toBeGreaterThanOrEqual(0);
    }

    // The next one over the refill window must throw.
    $caught = null;
    try {
        $this->service->consume($this->userId);
    } catch (RateLimitExceededException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitExceededException::class);
});

// -----------------------------------------------------------------------------
// check() — non-mutating preview
// -----------------------------------------------------------------------------

it('check() returns the same shape consume() would without mutating the bucket', function() {
    // Walk 10 calls through consume so the bucket is at a known
    // intermediate state.
    for ($i = 0; $i < 10; $i++) {
        $this->service->consume($this->userId);
    }

    $before = $this->service->getStatus($this->userId);
    $check = $this->service->check($this->userId);
    $after = $this->service->getStatus($this->userId);

    // check() reports what the next consume WOULD leave behind, so
    // its `remaining` is one less than the unread bucket.
    expect($check->remaining)->toBe($before->remaining - 1);
    // But the bucket itself is unchanged after the dry-run.
    expect($after->remaining)->toBe($before->remaining);
});

// -----------------------------------------------------------------------------
// getStatus() — non-mutating snapshot
// -----------------------------------------------------------------------------

it('getStatus() returns the same shape consume() returns but does not mutate the bucket', function() {
    // Walk 3 consumes through, then snapshot twice.
    for ($i = 0; $i < 3; $i++) {
        $this->service->consume($this->userId);
    }

    $first = $this->service->getStatus($this->userId);
    $second = $this->service->getStatus($this->userId);

    expect($first)->toBeInstanceOf(RateLimitStatus::class);
    expect($second->remaining)->toBe($first->remaining);
    expect($first->remaining)->toBe(57);
    expect($first->limit)->toBe(60);
});

// -----------------------------------------------------------------------------
// clear()
// -----------------------------------------------------------------------------

it('clear() empties the bucket back to full capacity', function() {
    // Drain.
    for ($i = 0; $i < 30; $i++) {
        $this->service->consume($this->userId);
    }

    expect($this->service->getStatus($this->userId)->remaining)->toBe(30);

    $this->service->clear($this->userId);

    // Cleared bucket means the next read synthesizes a fresh-full one.
    expect($this->service->getStatus($this->userId)->remaining)->toBe(60);
});

// -----------------------------------------------------------------------------
// retryAfter semantics
// -----------------------------------------------------------------------------

it('retryAfter is 0 when remaining >= 1 and positive when the bucket is exhausted', function() {
    // Fresh bucket — at least one token available.
    $status = $this->service->consume($this->userId);
    expect($status->remaining)->toBe(59);
    expect($status->retryAfter)->toBe(0);

    // Drain the rest.
    for ($i = 0; $i < 59; $i++) {
        $this->service->consume($this->userId);
    }

    // Now empty — next call throws and the carried status's
    // retryAfter is positive.
    $caught = null;
    try {
        $this->service->consume($this->userId);
    } catch (RateLimitExceededException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitExceededException::class);
    expect($caught->status->retryAfter)->toBeGreaterThanOrEqual(1);
});

// -----------------------------------------------------------------------------
// consumeKey() — arbitrary string-keyed bucket (IP throttle)
// -----------------------------------------------------------------------------

it('consumeKey() shares the bucket math with consume() on a fresh string key', function() {
    $this->service->clearKey('oauth:ip:test');

    $status = $this->service->consumeKey('oauth:ip:test');

    expect($status)->toBeInstanceOf(RateLimitStatus::class);
    expect($status->remaining)->toBe(59);

    $this->service->clearKey('oauth:ip:test');
});

it('consumeKey() throws once the string-keyed bucket is exhausted', function() {
    $settings = Herald::getInstance()->getSettings();
    $settings->rateLimitBurst = 2;
    $settings->rateLimitPerSecond = 1;
    $this->service->clearKey('oauth:ip:exhaust');

    $this->service->consumeKey('oauth:ip:exhaust');
    $this->service->consumeKey('oauth:ip:exhaust');

    $caught = null;
    try {
        $this->service->consumeKey('oauth:ip:exhaust');
    } catch (RateLimitExceededException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RateLimitExceededException::class);
    expect($caught->status->retryAfter)->toBeGreaterThanOrEqual(1);

    $this->service->clearKey('oauth:ip:exhaust');
});

it('an oauth:ip-keyed bucket is independent from the same-numbered user bucket', function() {
    // The OAuth throttle namespaces its key as `oauth:ip:<ip>`, so it
    // never collides with a bare-int user bucket. Drain the IP bucket
    // and confirm the user-id 42 bucket still has its full token.
    $settings = Herald::getInstance()->getSettings();
    $settings->rateLimitBurst = 1;

    $this->service->clear(42);
    $this->service->clearKey('oauth:ip:42');

    $this->service->consumeKey('oauth:ip:42');

    $userStatus = $this->service->consume(42);
    expect($userStatus->remaining)->toBe(0);

    $this->service->clear(42);
    $this->service->clearKey('oauth:ip:42');
});

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('is registered on the plugin as the `rateLimiter` component', function() {
    expect(Herald::getInstance()->rateLimiter)->toBeInstanceOf(RateLimiter::class);
});
