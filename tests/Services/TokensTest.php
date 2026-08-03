<?php

/**
 * =========================================================================
 * Tokens service tests — verify issuance, lookup, revocation, expiry,
 * lastUsedAt touching, and per-request lookup memoization.
 *
 * Tests run against the playground's live admin user. Each test cleans
 * up the rows it created so the table stays in the shape it started in
 * — the playground keeps herald installed across runs.
 *
 * Token naming pattern: every test prefixes the human-readable name
 * with `_test_/` so the afterEach() teardown deletes only its own rows
 * by name match, never touching real operator-issued tokens.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\records\Token as TokenRecord;
use yii\base\Exception;

beforeEach(function() {
    $this->service = Herald::getInstance()->tokens;
    // The playground always has at least one admin user; we use it as
    // the userId binding for issued tokens. Resolving via the lookup
    // catches CI envs where the seeded user has shifted.
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();
    $this->userId = (int) $admin->id;

    // `Tokens::issue()` is Pro-gated: the HTTP transport that consumes a
    // bearer token refuses Free installs, so minting one there would only
    // produce a credential that can never authenticate. Pin Pro for the
    // file so the cases below exercise issuance rather than the gate.
    $this->originalEdition = Herald::getInstance()->edition;
    Herald::getInstance()->edition = Herald::EDITION_PRO;
});

afterEach(function() {
    Herald::getInstance()->edition = $this->originalEdition;
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
});

// -----------------------------------------------------------------------------
// issue()
// -----------------------------------------------------------------------------

it('issue() returns a 64-char hex plaintext token and persists a row', function() {
    $result = $this->service->issue($this->userId, '_test_/issue-basic');

    expect($result)->toHaveKeys(['token', 'model']);
    expect($result['token'])->toBeString()->toHaveLength(64);
    expect($result['token'])->toMatch('/^[0-9a-f]{64}$/');

    expect($result['model']->id)->toBeInt();
    expect($result['model']->name)->toBe('_test_/issue-basic');
    expect($result['model']->userId)->toBe($this->userId);
    expect($result['model']->tokenPrefix)->toBe(substr($result['token'], 0, 8));
    expect($result['model']->tokenHash)->toBe(hash('sha256', $result['token']));
});

it('issue() stores only the hash, never the plaintext', function() {
    $result = $this->service->issue($this->userId, '_test_/no-plaintext');

    $record = TokenRecord::findOne($result['model']->id);
    expect($record)->not->toBeNull();
    // Every column individually — none of them carry the plaintext.
    foreach ([$record->name, $record->tokenHash, $record->tokenPrefix, (string) $record->uid] as $column) {
        expect($column)->not->toBe($result['token']);
    }
});

it('issue() with a TTL writes a future expiresAt; without TTL writes null', function() {
    $withoutTtl = $this->service->issue($this->userId, '_test_/no-ttl');
    expect($withoutTtl['model']->expiresAt)->toBeNull();

    $withTtl = $this->service->issue($this->userId, '_test_/with-ttl', 3600);
    expect($withTtl['model']->expiresAt)->not->toBeNull();
    expect(Carbon::parse($withTtl['model']->expiresAt)->isFuture())->toBeTrue();
});

it('issue() defaults to Settings::$tokenTtlDefault when no TTL passed', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->tokenTtlDefault;
    $settings->tokenTtlDefault = 7200;

    try {
        $result = $this->service->issue($this->userId, '_test_/ttl-default');
        expect($result['model']->expiresAt)->not->toBeNull();
        expect(Carbon::parse($result['model']->expiresAt)->isFuture())->toBeTrue();
    } finally {
        $settings->tokenTtlDefault = $original;
    }
});

it('issue() throws when the name exceeds the column length', function() {
    $tooLong = str_repeat('x', 100);
    expect(fn() => $this->service->issue($this->userId, $tooLong))
        ->toThrow(Exception::class, 'Failed to issue bearer token');
});

it('issue() generates a fresh plaintext on every call', function() {
    $a = $this->service->issue($this->userId, '_test_/uniq-a');
    $b = $this->service->issue($this->userId, '_test_/uniq-b');
    expect($a['token'])->not->toBe($b['token']);
    expect($a['model']->tokenHash)->not->toBe($b['model']->tokenHash);
});

// -----------------------------------------------------------------------------
// lookup()
// -----------------------------------------------------------------------------

it('lookup() with a valid plaintext returns the model', function() {
    $issued = $this->service->issue($this->userId, '_test_/lookup-hit');

    // Fresh service so the per-request memoization cache is empty —
    // forces a real DB probe.
    $service = new \craftpulse\herald\services\Tokens();
    $found = $service->lookup($issued['token']);

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($issued['model']->id);
    expect($found->userId)->toBe($this->userId);
});

it('lookup() with an unknown plaintext returns null', function() {
    $service = new \craftpulse\herald\services\Tokens();
    expect($service->lookup(str_repeat('x', 64)))->toBeNull();
});

it('lookup() with an empty string returns null without hitting the DB', function() {
    $service = new \craftpulse\herald\services\Tokens();
    expect($service->lookup(''))->toBeNull();
});

it('lookup() on a soft-deleted token returns null', function() {
    $issued = $this->service->issue($this->userId, '_test_/lookup-revoked');
    $this->service->revoke($issued['model']->id);

    $service = new \craftpulse\herald\services\Tokens();
    expect($service->lookup($issued['token']))->toBeNull();
});

it('lookup() on an expired token returns null', function() {
    $issued = $this->service->issue($this->userId, '_test_/lookup-expired');
    // Force expiry into the past via the Record directly.
    $record = TokenRecord::findOne($issued['model']->id);
    $record->expiresAt = Carbon::now()->subSecond()->toDateTimeString();
    $record->save(false);

    $service = new \craftpulse\herald\services\Tokens();
    expect($service->lookup($issued['token']))->toBeNull();
});

it('lookup() touches lastUsedAt on a hit', function() {
    $issued = $this->service->issue($this->userId, '_test_/lookup-touch');
    expect($issued['model']->lastUsedAt)->toBeNull();

    $service = new \craftpulse\herald\services\Tokens();
    $found = $service->lookup($issued['token']);
    expect($found)->not->toBeNull();
    expect($found->lastUsedAt)->not->toBeNull();

    // The DB row carries the touch too.
    $record = TokenRecord::findOne($issued['model']->id);
    expect($record->lastUsedAt)->not->toBeNull();
});

it('lookup() memoizes per-request: second lookup of the same plaintext does not re-query the DB', function() {
    $issued = $this->service->issue($this->userId, '_test_/lookup-memo');

    // Fresh service so we own the cache state for the assertion.
    $service = new \craftpulse\herald\services\Tokens();

    [, $firstQueries] = herald_count_queries(fn() => $service->lookup($issued['token']));
    [, $secondQueries] = herald_count_queries(fn() => $service->lookup($issued['token']));

    expect($firstQueries)->toBeGreaterThan(0);
    expect($secondQueries)->toBe(0);
});

// -----------------------------------------------------------------------------
// revoke()
// -----------------------------------------------------------------------------

it('revoke() soft-deletes the row and returns true', function() {
    $issued = $this->service->issue($this->userId, '_test_/revoke-ok');

    $ok = $this->service->revoke($issued['model']->id);
    expect($ok)->toBeTrue();

    $record = TokenRecord::findOne($issued['model']->id);
    expect($record->dateDeleted)->not->toBeNull();
});

it('revoke() on an unknown id returns false', function() {
    $ok = $this->service->revoke(987654321);
    expect($ok)->toBeFalse();
});

it('revoke() on an already-revoked id returns false', function() {
    $issued = $this->service->issue($this->userId, '_test_/revoke-twice');
    $this->service->revoke($issued['model']->id);

    expect($this->service->revoke($issued['model']->id))->toBeFalse();
});

it('revoke() invalidates the lookup memoization cache', function() {
    $issued = $this->service->issue($this->userId, '_test_/revoke-cache');

    // Prime the cache with a hit on the same service instance.
    expect($this->service->lookup($issued['token']))->not->toBeNull();

    // Revoke through the same service.
    $this->service->revoke($issued['model']->id);

    // Subsequent lookup must miss — stale cache would return the
    // pre-revocation hit.
    expect($this->service->lookup($issued['token']))->toBeNull();
});

// -----------------------------------------------------------------------------
// getById() / getAllForUser() / getAll()
// -----------------------------------------------------------------------------

it('getById() returns null for unknown / soft-deleted ids', function() {
    expect($this->service->getById(987654321))->toBeNull();

    $issued = $this->service->issue($this->userId, '_test_/getbyid-revoked');
    $this->service->revoke($issued['model']->id);
    expect($this->service->getById($issued['model']->id))->toBeNull();
});

it('getById() returns the model for live ids', function() {
    $issued = $this->service->issue($this->userId, '_test_/getbyid-live');
    $found = $this->service->getById($issued['model']->id);
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($issued['model']->id);
});

it('getAllForUser() returns only live tokens for the given user', function() {
    $live = $this->service->issue($this->userId, '_test_/getall-live');
    $revoked = $this->service->issue($this->userId, '_test_/getall-revoked');
    $this->service->revoke($revoked['model']->id);

    $tokens = $this->service->getAllForUser($this->userId);
    $ids = array_map(fn($t) => $t->id, $tokens);

    expect($ids)->toContain($live['model']->id);
    expect($ids)->not->toContain($revoked['model']->id);
});
