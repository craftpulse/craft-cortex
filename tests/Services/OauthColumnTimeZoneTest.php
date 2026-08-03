<?php

/**
 * =========================================================================
 * OAuth column-timezone round-trip tests.
 *
 * `herald_oauth_tokens` and `herald_oauth_codes` hold **naive** UTC
 * datetime strings: `dateCreated` / `dateUpdated` are stamped in UTC by
 * `craft\db\ActiveRecord`, and nothing in a stored value records an
 * offset. The OAuth layer's own columns (`expiresAt`, `dateRevoked`,
 * `consumedAt`) have to land in the same zone or a row disagrees with
 * itself.
 *
 * Every case here pins a non-UTC process timezone first, because that is
 * the only condition under which the skew is observable: on a UTC install
 * the process default and the column's zone agree and a wrong write looks
 * right.
 *
 * The file deliberately covers BOTH halves of each round trip:
 *
 *   - The write half asserts the stored string, parsed as UTC, is the
 *     same *instant* the caller asked for. This is what fails when a
 *     write renders local wall-clock time.
 *   - The read half drives `Oauth::pruneExpired()` against rows the
 *     repositories themselves persisted. This is what fails if the
 *     writes are corrected and the prune's `now` is left local — a live
 *     token would be pruned and an expired one kept. Asserting only the
 *     writes would let that half-fix through.
 *
 * Rows carry the `_tz_test` client / audience markers so the teardown
 * purges only its own. The per-test transaction rolls everything back
 * anyway; the markers keep a failed mid-test abort from leaking.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\oauth\entities\AccessTokenEntity;
use craftpulse\herald\oauth\entities\AuthCodeEntity;
use craftpulse\herald\oauth\entities\ClientEntity;
use craftpulse\herald\oauth\entities\RefreshTokenEntity;
use craftpulse\herald\oauth\repositories\AccessTokenRepository;
use craftpulse\herald\oauth\repositories\AuthCodeRepository;
use craftpulse\herald\oauth\repositories\RefreshTokenRepository;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use craftpulse\herald\records\OauthCode as OauthCodeRecord;
use craftpulse\herald\records\OauthToken as OauthTokenRecord;

const HERALD_TZ_TEST_CLIENT = '_tz_test_client';
const HERALD_TZ_TEST_AUDIENCE = 'https://tz-test.invalid/mcp';

/**
 * A zone AHEAD of UTC. Rendering a UTC instant as local wall clock here
 * moves the stored string forward, so an expiry that has already passed
 * reads as still-live.
 */
const HERALD_TZ_AHEAD = 'Europe/Brussels';

/**
 * A zone BEHIND UTC. The mirror failure: a live expiry reads as already
 * passed, so a working credential's row gets pruned.
 */
const HERALD_TZ_BEHIND = 'America/Los_Angeles';

/**
 * Seconds either side of `now` used for the live / dead fixtures. Small
 * enough to sit inside the smallest offset the zones above ever carry
 * (Brussels is +1 at minimum), so a zone mistake always flips the
 * classification rather than sometimes flipping it.
 */
const HERALD_TZ_WINDOW = 1800;

/**
 * Run a closure with the process timezone pinned to `$zone`, restoring
 * whatever was set before on the way out.
 */
function herald_tz_in_zone(string $zone, callable $fn): void
{
    $original = Craft::$app->getTimeZone();
    Craft::$app->setTimeZone($zone);

    try {
        $fn();
    } finally {
        Craft::$app->setTimeZone($original);
    }
}

/**
 * Parse a stored naive datetime string as UTC and return its Unix
 * timestamp — the instant the row actually claims.
 */
function herald_tz_utc_timestamp(string $stored): int
{
    return (new DateTimeImmutable($stored, new DateTimeZone('UTC')))->getTimestamp();
}

/**
 * Assert a stored naive datetime string denotes `$expected` when read as
 * UTC, within a two-second tolerance for the clock moving during the
 * test.
 */
function herald_tz_expect_utc_instant(?string $stored, int $expected): void
{
    expect($stored)->not->toBeNull();
    expect(abs(herald_tz_utc_timestamp((string) $stored) - $expected))->toBeLessThanOrEqual(2);
}

/**
 * A league client entity bound to the fixture client row.
 */
function herald_tz_client_entity(): ClientEntity
{
    $entity = new ClientEntity();
    $entity->setIdentifier(HERALD_TZ_TEST_CLIENT);
    $entity->setName('_test_/tz-client');
    $entity->setRedirectUri('https://tz-test.invalid/cb');
    $entity->setIsConfidential(false);
    return $entity;
}

/**
 * Mint an access-token entity expiring at `$expiry`, stamped with the
 * fixture audience so the teardown can find its row.
 */
function herald_tz_access_entity(DateTimeImmutable $expiry): AccessTokenEntity
{
    $entity = new AccessTokenEntity();
    $entity->setClient(herald_tz_client_entity());
    $entity->setIdentifier(bin2hex(random_bytes(20)));
    $entity->setExpiryDateTime($expiry);
    $entity->setAudience(HERALD_TZ_TEST_AUDIENCE);
    return $entity;
}

/**
 * Persist an access token through the repository under test and return
 * its row id.
 */
function herald_tz_persist_access(DateTimeImmutable $expiry): int
{
    $entity = herald_tz_access_entity($expiry);
    (new AccessTokenRepository())->persistNewAccessToken($entity);

    $record = OauthTokenRecord::findOne(['tokenHash' => hash('sha256', $entity->getIdentifier())]);
    expect($record)->not->toBeNull();
    return (int) $record->id;
}

/**
 * Insert a live token row directly, bypassing the repositories. Used by
 * the service-level revoke cases, which are about the revocation columns
 * rather than about how the row got there. Returns the row id.
 */
function herald_tz_token_row(string $type, ?string $familyId = null, ?string $plaintext = null): int
{
    $record = new OauthTokenRecord();
    $record->tokenType = $type;
    $record->tokenHash = hash('sha256', $plaintext ?? bin2hex(random_bytes(20)));
    $record->clientId = HERALD_TZ_TEST_CLIENT;
    $record->familyId = $familyId;
    $record->audience = HERALD_TZ_TEST_AUDIENCE;
    $record->expiresAt = (new DateTimeImmutable('+86400 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $record->save(false);
    return (int) $record->id;
}

/**
 * Persist an authorization code through the repository under test and
 * return its code identifier.
 */
function herald_tz_persist_code(DateTimeImmutable $expiry): string
{
    $entity = new AuthCodeEntity();
    $entity->setClient(herald_tz_client_entity());
    $entity->setIdentifier('tz_' . bin2hex(random_bytes(16)));
    $entity->setExpiryDateTime($expiry);
    $entity->setRedirectUri('https://tz-test.invalid/cb');

    (new AuthCodeRepository())->persistNewAuthCode($entity);

    return $entity->getIdentifier();
}

beforeEach(function() {
    // Both OAuth tables carry an ON DELETE CASCADE FK on clientId, so a
    // client row must exist before any token / code row inserts.
    if (OauthClientRecord::findOne(['clientId' => HERALD_TZ_TEST_CLIENT]) === null) {
        $client = new OauthClientRecord();
        $client->clientId = HERALD_TZ_TEST_CLIENT;
        $client->clientName = '_test_/tz-client';
        $client->redirectUris = '["https://tz-test.invalid/cb"]';
        $client->isPublic = true;
        $client->approved = true;
        $client->save();
    }
});

afterEach(function() {
    // Dropping the client cascades its tokens and codes.
    OauthClientRecord::deleteAll(['clientId' => HERALD_TZ_TEST_CLIENT]);
    Herald::getInstance()->oauth->setPendingFamilyId(null);
});

// -----------------------------------------------------------------------------
// Write half — expiresAt
// -----------------------------------------------------------------------------

it('persistNewAccessToken writes expiresAt as the same instant on a non-UTC install', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        // Built AFTER the zone is pinned so it carries the local zone,
        // exactly as league's `(new DateTimeImmutable())->add($ttl)` does.
        $expiry = new DateTimeImmutable('+3600 seconds');
        $id = herald_tz_persist_access($expiry);

        $record = OauthTokenRecord::findOne($id);
        herald_tz_expect_utc_instant($record->expiresAt, $expiry->getTimestamp());

        // Tie it back to the row's own UTC siblings: `dateCreated` is
        // stamped in UTC by `craft\db\ActiveRecord`, so the gap between
        // the two columns must read as the TTL, not `TTL + offset`.
        expect(herald_tz_utc_timestamp((string) $record->expiresAt) - herald_tz_utc_timestamp((string) $record->dateCreated))
            ->toBeGreaterThanOrEqual(3598)
            ->toBeLessThanOrEqual(3602);
    });
});

it('persistNewRefreshToken writes expiresAt as the same instant on a non-UTC install', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        $access = herald_tz_access_entity(new DateTimeImmutable('+3600 seconds'));
        $expiry = new DateTimeImmutable('+86400 seconds');

        $refresh = new RefreshTokenEntity();
        $refresh->setIdentifier(bin2hex(random_bytes(20)));
        $refresh->setExpiryDateTime($expiry);
        $refresh->setAccessToken($access);

        (new RefreshTokenRepository())->persistNewRefreshToken($refresh);

        $record = OauthTokenRecord::findOne(['tokenHash' => hash('sha256', $refresh->getIdentifier())]);
        expect($record)->not->toBeNull();
        herald_tz_expect_utc_instant($record->expiresAt, $expiry->getTimestamp());
    });
});

it('persistNewAuthCode writes expiresAt as the same instant on a non-UTC install', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        $expiry = new DateTimeImmutable('+300 seconds');
        $code = herald_tz_persist_code($expiry);

        $record = OauthCodeRecord::findOne(['code' => $code]);
        expect($record)->not->toBeNull();
        herald_tz_expect_utc_instant($record->expiresAt, $expiry->getTimestamp());
    });
});

// -----------------------------------------------------------------------------
// Write half — dateRevoked / consumedAt
// -----------------------------------------------------------------------------

it('the token repositories stamp dateRevoked and consumedAt in UTC on a non-UTC install', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        $accessEntity = herald_tz_access_entity(new DateTimeImmutable('+3600 seconds'));
        (new AccessTokenRepository())->persistNewAccessToken($accessEntity);

        $refreshPlaintext = bin2hex(random_bytes(20));
        $refresh = new RefreshTokenEntity();
        $refresh->setIdentifier($refreshPlaintext);
        $refresh->setExpiryDateTime(new DateTimeImmutable('+86400 seconds'));
        $refresh->setAccessToken($accessEntity);
        (new RefreshTokenRepository())->persistNewRefreshToken($refresh);

        $now = time();
        (new AccessTokenRepository())->revokeAccessToken($accessEntity->getIdentifier());
        (new RefreshTokenRepository())->revokeRefreshToken($refreshPlaintext);

        $accessRow = OauthTokenRecord::findOne(['tokenHash' => hash('sha256', $accessEntity->getIdentifier())]);
        $refreshRow = OauthTokenRecord::findOne(['tokenHash' => hash('sha256', $refreshPlaintext)]);

        herald_tz_expect_utc_instant($accessRow->dateRevoked, $now);
        herald_tz_expect_utc_instant($refreshRow->dateRevoked, $now);
        herald_tz_expect_utc_instant($refreshRow->consumedAt, $now);
    });
});

it('the Oauth service stamps dateRevoked in UTC on every revoke path', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        $service = Herald::getInstance()->oauth;
        $familyId = $service->newFamilyId();
        $opaque = bin2hex(random_bytes(20));

        $byToken = herald_tz_token_row('refresh', plaintext: $opaque);
        $byFamily = herald_tz_token_row('access', familyId: $familyId);
        $byClient = herald_tz_token_row('access');

        $now = time();

        // revokeToken() — RFC 7009, keyed on the opaque plaintext hash.
        expect($service->revokeToken($opaque))->toBeTrue();
        // revokeFamily() — the refresh-token-theft response.
        expect($service->revokeFamily($familyId, 'timezone round-trip test'))->toBeGreaterThanOrEqual(1);
        // revokeClient() cuts off every STILL-LIVE token the client
        // issued, so it runs last and only reaches `$byClient`.
        $clientRow = OauthClientRecord::findOne(['clientId' => HERALD_TZ_TEST_CLIENT]);
        expect($service->revokeClient((int) $clientRow->id))->toBeTrue();

        herald_tz_expect_utc_instant(OauthTokenRecord::findOne($byToken)->dateRevoked, $now);
        herald_tz_expect_utc_instant(OauthTokenRecord::findOne($byFamily)->dateRevoked, $now);
        herald_tz_expect_utc_instant(OauthTokenRecord::findOne($byClient)->dateRevoked, $now);
    });
});

// -----------------------------------------------------------------------------
// Read half — pruneExpired() against rows the repositories wrote
//
// These are the cases a write-only fix breaks: correct the writes and
// leave `pruneExpired()`'s `now` local, and the prune starts deleting
// live credentials on one zone and keeping dead rows on the other.
// -----------------------------------------------------------------------------

it('pruneExpired classifies repository-written rows correctly in a zone ahead of UTC', function() {
    herald_tz_in_zone(HERALD_TZ_AHEAD, function() {
        $liveToken = herald_tz_persist_access(new DateTimeImmutable('+' . HERALD_TZ_WINDOW . ' seconds'));
        $deadToken = herald_tz_persist_access(new DateTimeImmutable('-' . HERALD_TZ_WINDOW . ' seconds'));
        $liveCode = herald_tz_persist_code(new DateTimeImmutable('+' . HERALD_TZ_WINDOW . ' seconds'));
        $deadCode = herald_tz_persist_code(new DateTimeImmutable('-' . HERALD_TZ_WINDOW . ' seconds'));

        Herald::getInstance()->oauth->pruneExpired();

        expect(OauthTokenRecord::findOne($liveToken))->not->toBeNull();
        expect(OauthTokenRecord::findOne($deadToken))->toBeNull();
        expect(OauthCodeRecord::findOne(['code' => $liveCode]))->not->toBeNull();
        expect(OauthCodeRecord::findOne(['code' => $deadCode]))->toBeNull();
    });
});

it('pruneExpired classifies repository-written rows correctly in a zone behind UTC', function() {
    herald_tz_in_zone(HERALD_TZ_BEHIND, function() {
        $liveToken = herald_tz_persist_access(new DateTimeImmutable('+' . HERALD_TZ_WINDOW . ' seconds'));
        $deadToken = herald_tz_persist_access(new DateTimeImmutable('-' . HERALD_TZ_WINDOW . ' seconds'));
        $liveCode = herald_tz_persist_code(new DateTimeImmutable('+' . HERALD_TZ_WINDOW . ' seconds'));
        $deadCode = herald_tz_persist_code(new DateTimeImmutable('-' . HERALD_TZ_WINDOW . ' seconds'));

        Herald::getInstance()->oauth->pruneExpired();

        expect(OauthTokenRecord::findOne($liveToken))->not->toBeNull();
        expect(OauthTokenRecord::findOne($deadToken))->toBeNull();
        expect(OauthCodeRecord::findOne(['code' => $liveCode]))->not->toBeNull();
        expect(OauthCodeRecord::findOne(['code' => $deadCode]))->toBeNull();
    });
});
