<?php

/**
 * =========================================================================
 * Refresh-token rotation + theft-detection tests.
 *
 * Exercises the family-lineage seam directly on the token rows + the
 * `RefreshTokenRepository` theft-detection path, without standing up
 * the full league PSR-7 grant. The repository is the security boundary:
 *
 *   - A valid rotation marks the old refresh token consumed and stamps
 *     the rotated pair's family onto the `Oauth` service.
 *   - A replay of a consumed refresh token revokes the ENTIRE family
 *     (every live access + refresh token in the lineage) and emits a
 *     `kind=security` audit row.
 *
 * Token rows created here carry the `_rotation-test` family prefix /
 * audience so the teardown can purge them without touching real rows.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\oauth\repositories\RefreshTokenRepository;
use craftpulse\cortex\records\OauthClient as OauthClientRecord;
use craftpulse\cortex\records\OauthToken as OauthTokenRecord;
use craftpulse\cortex\tools\support\InvocationLogger;

const CORTEX_ROTATION_TEST_CLIENT = '_rotation_test_client';

/**
 * Persist a token row for the rotation tests. Returns the plaintext id
 * (refresh) or hash key the repository looks up by.
 *
 * @param array<string,mixed> $overrides
 */
function cortex_make_token_row(string $type, string $familyId, array $overrides = []): OauthTokenRecord
{
    $record = new OauthTokenRecord();
    $record->tokenType = $type;
    $record->tokenHash = hash('sha256', $overrides['plaintext'] ?? bin2hex(random_bytes(16)));
    $record->userId = null;
    $record->clientId = $overrides['clientId'] ?? CORTEX_ROTATION_TEST_CLIENT;
    $record->familyId = $familyId;
    $record->scope = 'content:read';
    $record->audience = 'https://rotation-test.invalid/mcp';
    $record->expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
    $record->dateRevoked = $overrides['dateRevoked'] ?? null;
    $record->consumedAt = $overrides['consumedAt'] ?? null;
    $record->save();
    return $record;
}

beforeEach(function() {
    // The tokens table has an ON DELETE CASCADE FK on clientId, so a
    // client row must exist before any token row inserts.
    if (OauthClientRecord::findOne(['clientId' => CORTEX_ROTATION_TEST_CLIENT]) === null) {
        $client = new OauthClientRecord();
        $client->clientId = CORTEX_ROTATION_TEST_CLIENT;
        $client->clientName = '_test_/rotation-client';
        $client->redirectUris = '["https://rotation-test.invalid/cb"]';
        $client->isPublic = true;
        $client->save();
    }
});

afterEach(function() {
    OauthTokenRecord::deleteAll(['like', 'audience', 'https://rotation-test.invalid/%', false]);
    // Dropping the client cascades any stragglers.
    OauthClientRecord::deleteAll(['clientId' => CORTEX_ROTATION_TEST_CLIENT]);
    Cortex::getInstance()->oauth->setPendingFamilyId(null);
});

it('revokeRefreshToken marks the row consumed and revoked', function() {
    $plaintext = bin2hex(random_bytes(20));
    $familyId = Cortex::getInstance()->oauth->newFamilyId();
    cortex_make_token_row('refresh', $familyId, ['plaintext' => $plaintext]);

    (new RefreshTokenRepository())->revokeRefreshToken($plaintext);

    $row = OauthTokenRecord::findOne(['tokenHash' => hash('sha256', $plaintext)]);
    expect($row->dateRevoked)->not->toBeNull()
        ->and($row->consumedAt)->not->toBeNull();
});

it('revokeRefreshToken stamps the family onto the Oauth service for the rotated pair', function() {
    $plaintext = bin2hex(random_bytes(20));
    $familyId = Cortex::getInstance()->oauth->newFamilyId();
    cortex_make_token_row('refresh', $familyId, ['plaintext' => $plaintext]);

    (new RefreshTokenRepository())->revokeRefreshToken($plaintext);

    expect(Cortex::getInstance()->oauth->getPendingFamilyId())->toBe($familyId);
});

it('isRefreshTokenRevoked returns false for a live refresh token', function() {
    $plaintext = bin2hex(random_bytes(20));
    $familyId = Cortex::getInstance()->oauth->newFamilyId();
    cortex_make_token_row('refresh', $familyId, ['plaintext' => $plaintext]);

    expect((new RefreshTokenRepository())->isRefreshTokenRevoked($plaintext))->toBeFalse();
});

it('isRefreshTokenRevoked returns true for a missing row (fail closed)', function() {
    expect((new RefreshTokenRepository())->isRefreshTokenRevoked('does-not-exist'))->toBeTrue();
});

it('replaying a consumed refresh token revokes the whole family', function() {
    $familyId = Cortex::getInstance()->oauth->newFamilyId();

    // The lineage: one live access token, one live refresh token, and
    // the consumed (rotated-away) refresh token being replayed.
    $liveAccess = cortex_make_token_row('access', $familyId);
    $liveRefresh = cortex_make_token_row('refresh', $familyId);

    $consumedPlaintext = bin2hex(random_bytes(20));
    $now = (new DateTime())->format('Y-m-d H:i:s');
    cortex_make_token_row('refresh', $familyId, [
        'plaintext' => $consumedPlaintext,
        'dateRevoked' => $now,
        'consumedAt' => $now,
    ]);

    $revoked = (new RefreshTokenRepository())->isRefreshTokenRevoked($consumedPlaintext);
    expect($revoked)->toBeTrue();

    // The whole family is now revoked — including the previously-live
    // access + refresh tokens.
    $liveAccessRow = OauthTokenRecord::findOne(['id' => $liveAccess->id]);
    $liveRefreshRow = OauthTokenRecord::findOne(['id' => $liveRefresh->id]);
    expect($liveAccessRow->dateRevoked)->not->toBeNull()
        ->and($liveRefreshRow->dateRevoked)->not->toBeNull();
});

it('a family-revoke writes a kind=security audit row', function() {
    $familyId = Cortex::getInstance()->oauth->newFamilyId();
    $count = Cortex::getInstance()->oauth->revokeFamily($familyId, 'test replay');

    expect($count)->toBeInt();

    $auditRow = Cortex::getInstance()->invocations->find()
        ->andWhere(['kind' => InvocationLogger::KIND_SECURITY])
        ->andWhere(['toolName' => '_oauth_token_theft'])
        ->one();

    expect($auditRow)->toBeArray();
});

it('a revoked-but-not-consumed token does not re-trigger a family revoke', function() {
    $familyId = Cortex::getInstance()->oauth->newFamilyId();

    // A live sibling that would be wiped IF the family revoke fired.
    $sibling = cortex_make_token_row('access', $familyId);

    // An operator-revoked (RFC 7009) refresh token: revoked, NOT consumed.
    $plaintext = bin2hex(random_bytes(20));
    cortex_make_token_row('refresh', $familyId, [
        'plaintext' => $plaintext,
        'dateRevoked' => (new DateTime())->format('Y-m-d H:i:s'),
    ]);

    $revoked = (new RefreshTokenRepository())->isRefreshTokenRevoked($plaintext);
    expect($revoked)->toBeTrue();

    // The sibling stays live — no family-wide burn for a plain revoke.
    $siblingRow = OauthTokenRecord::findOne(['id' => $sibling->id]);
    expect($siblingRow->dateRevoked)->toBeNull();
});
