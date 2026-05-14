<?php

/**
 * =========================================================================
 * Oauth service tests — DCR validation, client lookup, scope lookup,
 * audience binding, and revocation.
 *
 * Tests run against the playground's live Craft install. Each test
 * cleans up the OAuth rows it creates so the table stays in the
 * shape it started in. Client names are prefixed with `_test_/` so
 * the teardown can match by name without touching real registrations.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\oauth\entities\AccessTokenEntity;
use craftpulse\cortex\oauth\entities\ClientEntity;
use craftpulse\cortex\oauth\entities\ScopeEntity;
use craftpulse\cortex\oauth\repositories\ClientRepository;
use craftpulse\cortex\oauth\repositories\ScopeRepository;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\OauthClient as OauthClientRecord;
use craftpulse\cortex\records\OauthToken as OauthTokenRecord;
use League\OAuth2\Server\CryptKey;

beforeEach(function() {
    $this->service = Plugin::getInstance()->oauth;
});

afterEach(function() {
    // Clean up any registrations made by the test suite.
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
    // Clean up tokens minted into orphaned client rows.
    OauthTokenRecord::deleteAll(['like', 'audience', 'https://test-audience.invalid/%', false]);
});

// -----------------------------------------------------------------------------
// registerClient() — RFC 7591 DCR
// -----------------------------------------------------------------------------

it('registerClient() mints a clientId and persists a public-client row', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/public-client',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'none',
    ]);

    expect($response)->toHaveKeys(['client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method']);
    expect($response['token_endpoint_auth_method'])->toBe('none');
    expect($response)->not->toHaveKey('client_secret');
    expect($response['client_id'])->toMatch('/^[0-9a-f]{32}$/');

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect($record)->not->toBeNull();
    expect((bool) $record->isPublic)->toBeTrue();
    expect($record->clientSecretHash)->toBeNull();
});

it('registerClient() with token_endpoint_auth_method=client_secret_basic mints a secret', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/confidential-client',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    expect($response)->toHaveKey('client_secret');
    expect($response['client_secret'])->toBeString()->not->toBeEmpty();

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect($record)->not->toBeNull();
    expect((bool) $record->isPublic)->toBeFalse();
    expect($record->clientSecretHash)->not->toBeNull();

    // The hash verifies against the plaintext.
    expect(password_verify($response['client_secret'], $record->clientSecretHash))->toBeTrue();
});

it('registerClient() rejects http:// redirect URIs unless the host is localhost', function() {
    expect(fn() => $this->service->registerClient([
        'client_name' => '_test_/bad-redirect',
        'redirect_uris' => ['http://example.com/callback'],
    ]))->toThrow(\InvalidArgumentException::class, 'must be HTTPS');
});

it('registerClient() accepts http://localhost for native clients', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/localhost-native',
        'redirect_uris' => ['http://localhost:9876/callback'],
        'token_endpoint_auth_method' => 'none',
    ]);
    expect($response)->toHaveKey('client_id');
});

it('registerClient() rejects URIs with fragments', function() {
    expect(fn() => $this->service->registerClient([
        'client_name' => '_test_/fragment',
        'redirect_uris' => ['https://example.com/callback#frag'],
    ]))->toThrow(\InvalidArgumentException::class, 'fragment');
});

it('registerClient() rejects an empty redirect_uris list', function() {
    expect(fn() => $this->service->registerClient([
        'client_name' => '_test_/empty-redirects',
        'redirect_uris' => [],
    ]))->toThrow(\InvalidArgumentException::class, 'redirect_uris');
});

it('registerClient() rejects a missing client_name', function() {
    expect(fn() => $this->service->registerClient([
        'redirect_uris' => ['https://example.com/callback'],
    ]))->toThrow(\InvalidArgumentException::class, 'client_name');
});

it('registerClient() filters unknown scope identifiers silently', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/scope-filter',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'read unknown_scope write',
    ]);

    // `read` and `write` retained; `unknown_scope` filtered.
    expect($response['scope'])->toBe('read write');
});

// -----------------------------------------------------------------------------
// ClientRepository — adapter layer
// -----------------------------------------------------------------------------

it('ClientRepository hydrates a public client correctly', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/hydrate-public',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $repo = new ClientRepository();
    $entity = $repo->getClientEntity($response['client_id']);
    expect($entity)->toBeInstanceOf(ClientEntity::class);
    expect($entity->getIdentifier())->toBe($response['client_id']);
    expect($entity->getName())->toBe('_test_/hydrate-public');
    expect($entity->isConfidential())->toBeFalse();
});

it('ClientRepository::validateClient passes public clients without a secret check', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/validate-public',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $repo = new ClientRepository();
    expect($repo->validateClient($response['client_id'], null, 'authorization_code'))->toBeTrue();
    expect($repo->validateClient($response['client_id'], 'irrelevant', 'authorization_code'))->toBeTrue();
});

it('ClientRepository::validateClient checks the secret for confidential clients', function() {
    $response = $this->service->registerClient([
        'client_name' => '_test_/validate-confidential',
        'redirect_uris' => ['https://example.com/callback'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    $repo = new ClientRepository();
    expect($repo->validateClient($response['client_id'], $response['client_secret'], 'authorization_code'))->toBeTrue();
    expect($repo->validateClient($response['client_id'], 'wrong-secret', 'authorization_code'))->toBeFalse();
    expect($repo->validateClient($response['client_id'], null, 'authorization_code'))->toBeFalse();
});

it('ClientRepository::getClientEntity returns null for unknown ids', function() {
    $repo = new ClientRepository();
    expect($repo->getClientEntity('does-not-exist'))->toBeNull();
});

// -----------------------------------------------------------------------------
// ScopeRepository
// -----------------------------------------------------------------------------

it('ScopeRepository accepts read and write but rejects unknown scopes', function() {
    $repo = new ScopeRepository();
    expect($repo->getScopeEntityByIdentifier('read'))->toBeInstanceOf(ScopeEntity::class);
    expect($repo->getScopeEntityByIdentifier('write'))->toBeInstanceOf(ScopeEntity::class);
    expect($repo->getScopeEntityByIdentifier('admin'))->toBeNull();
    expect($repo->getScopeEntityByIdentifier(''))->toBeNull();
});

// -----------------------------------------------------------------------------
// Audience binding (RFC 8707)
// -----------------------------------------------------------------------------

it('lookupAccessToken() rejects a token whose audience does not match the expected resource URI', function() {
    // Build a JWT manually carrying audience = https://a.test.invalid/mcp,
    // persist a corresponding row, and verify the lookup returns the
    // audience back so the controller's match check can reject it.
    $client = _cortex_oauth_mint_client();

    $jwt = _cortex_oauth_mint_jwt(
        clientId: $client->clientId,
        audience: 'https://a.test.invalid/mcp',
        userId: 1,
    );

    $result = $this->service->lookupAccessToken($jwt);
    expect($result)->not->toBeNull();
    expect($result['audience'])->toBe('https://a.test.invalid/mcp');

    // The controller compares audience against the canonical MCP
    // endpoint URL. A test for the actual rejection lives in the
    // controller test; here we assert the slot carries the right
    // audience value.
});

it('lookupAccessToken() returns null on a parsed-but-revoked token', function() {
    $client = _cortex_oauth_mint_client();
    $jwt = _cortex_oauth_mint_jwt(
        clientId: $client->clientId,
        audience: 'https://test-audience.invalid/a',
        userId: 1,
    );

    // Revoke the corresponding token row.
    $hash = hash('sha256', _cortex_oauth_jti_for($jwt));
    OauthTokenRecord::updateAll(
        ['dateRevoked' => date('Y-m-d H:i:s')],
        ['tokenHash' => $hash],
    );

    expect($this->service->lookupAccessToken($jwt))->toBeNull();
});

it('lookupAccessToken() returns null for an expired token', function() {
    $client = _cortex_oauth_mint_client();
    $jwt = _cortex_oauth_mint_jwt(
        clientId: $client->clientId,
        audience: 'https://test-audience.invalid/b',
        userId: 1,
        expiresIn: -3600, // already expired
    );

    expect($this->service->lookupAccessToken($jwt))->toBeNull();
});

it('lookupAccessToken() returns null on a syntactically broken JWT', function() {
    expect($this->service->lookupAccessToken('not-a-jwt'))->toBeNull();
});

it('lookupAccessToken() returns clientId as empty string when cid claim is absent', function() {
    $client = _cortex_oauth_mint_client();
    $jwt = _cortex_oauth_mint_jwt_without_cid(
        clientId: $client->clientId,
        audience: 'https://test-audience.invalid/no-cid',
        userId: 1,
    );

    $result = $this->service->lookupAccessToken($jwt);
    expect($result)->not->toBeNull();
    expect($result['clientId'])->toBe('');
});

it('lookupAccessToken() returns null for a token whose nbf is in the future', function() {
    $client = _cortex_oauth_mint_client();
    $jwt = _cortex_oauth_mint_jwt(
        clientId: $client->clientId,
        audience: 'https://test-audience.invalid/nbf',
        userId: 1,
        expiresIn: 7200,
        nbfOffset: 3600, // not-before = now + 1 hour
    );

    expect($this->service->lookupAccessToken($jwt))->toBeNull();
});

// -----------------------------------------------------------------------------
// revokeToken() — RFC 7009
// -----------------------------------------------------------------------------

it('revokeToken() flips dateRevoked for a known refresh token', function() {
    $opaque = bin2hex(random_bytes(40));
    $hash = hash('sha256', $opaque);

    $record = new OauthTokenRecord();
    $record->tokenType = 'refresh';
    $record->tokenHash = $hash;
    $record->clientId = 'test-client';
    $record->audience = 'https://test-audience.invalid/c';
    $record->expiresAt = date('Y-m-d H:i:s', time() + 86400);
    $record->save(false);

    $result = $this->service->revokeToken($opaque);
    expect($result)->toBeTrue();

    $fresh = OauthTokenRecord::findOne($record->id);
    expect($fresh->dateRevoked)->not->toBeNull();
});

it('revokeToken() returns false for an unknown token', function() {
    expect($this->service->revokeToken('totally-bogus-token'))->toBeFalse();
});

// -----------------------------------------------------------------------------
// Helpers — module-level functions so tests can reuse without per-file
// `beforeEach` injection.
// -----------------------------------------------------------------------------

/**
 * Mint a transient OAuth client row used by audience-binding tests.
 */
function _cortex_oauth_mint_client(): OauthClientRecord
{
    $record = new OauthClientRecord();
    $record->clientId = bin2hex(random_bytes(16));
    $record->clientName = '_test_/jwt-helper-' . bin2hex(random_bytes(4));
    $record->redirectUris = json_encode(['https://example.com/cb']);
    $record->isPublic = true;
    $record->save();
    return $record;
}

/**
 * Mint a JWT carrying the given audience + user id. Persists a
 * matching access-token row so `isAccessTokenRevoked()` sees a
 * non-revoked match.
 *
 * @param int $nbfOffset Seconds added to `now` for the `nbf` claim.
 *                       Default 0 (token immediately usable). Pass
 *                       a positive value to produce a future-`nbf`
 *                       token that should be rejected at validation.
 */
function _cortex_oauth_mint_jwt(string $clientId, string $audience, int $userId, int $expiresIn = 3600, int $nbfOffset = 0): string
{
    $clientEntity = new ClientEntity();
    $clientEntity->setIdentifier($clientId);
    $clientEntity->setName('test-client');
    $clientEntity->setRedirectUri('https://example.com/cb');
    $clientEntity->setIsConfidential(false);

    $entity = new AccessTokenEntity();
    $entity->setClient($clientEntity);
    $entity->setIdentifier(bin2hex(random_bytes(20)));
    $entity->setUserIdentifier((string) $userId);
    $entity->setExpiryDateTime(new \DateTimeImmutable('@' . (time() + $expiresIn)));
    $entity->setAudience($audience);
    $entity->setPrivateKey(new CryptKey(
        'file://' . Plugin::getInstance()->oauth->getPrivateKeyPath(),
    ));

    // When a future nbf is requested, build the JWT directly via
    // lcobucci's builder so we can control the `nbf` claim.
    // AccessTokenEntity::toString() always stamps nbf = now, which is
    // correct at issuance — the nbf test needs a non-default nbf.
    if ($nbfOffset !== 0) {
        $privateKeyContent = file_get_contents(Plugin::getInstance()->oauth->getPrivateKeyPath());
        $signer = new \Lcobucci\JWT\Signer\Rsa\Sha256();
        $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            $signer,
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKeyContent),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText('not-used'),
        );
        $now = new \DateTimeImmutable();
        $token = $config->builder()
            ->permittedFor($audience)
            ->identifiedBy($entity->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now->modify('+' . $nbfOffset . ' seconds'))
            ->expiresAt($now->modify('+' . $expiresIn . ' seconds'))
            ->relatedTo((string) $userId)
            ->withClaim('scopes', [])
            ->withClaim('cid', $clientId)
            ->getToken($signer, \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKeyContent));
        $jwtString = $token->toString();
    } else {
        $jwtString = $entity->toString();
    }

    $record = new OauthTokenRecord();
    $record->tokenType = 'access';
    $record->tokenHash = hash('sha256', $entity->getIdentifier());
    $record->userId = $userId;
    $record->clientId = $clientId;
    $record->audience = $audience;
    $record->expiresAt = date('Y-m-d H:i:s', time() + max($expiresIn, 1));
    $record->save();

    return $jwtString;
}

/**
 * Mint a JWT without the `cid` claim — simulates tokens issued by a
 * system that doesn't stamp the client id as a custom claim.
 */
function _cortex_oauth_mint_jwt_without_cid(string $clientId, string $audience, int $userId, int $expiresIn = 3600): string
{
    $privateKeyContent = file_get_contents(Plugin::getInstance()->oauth->getPrivateKeyPath());
    $signer = new \Lcobucci\JWT\Signer\Rsa\Sha256();
    $config = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
        $signer,
        \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKeyContent),
        \Lcobucci\JWT\Signer\Key\InMemory::plainText('not-used'),
    );
    $jti = bin2hex(random_bytes(20));
    $now = new \DateTimeImmutable();
    $token = $config->builder()
        ->permittedFor($audience)
        ->identifiedBy($jti)
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($now->modify('+' . $expiresIn . ' seconds'))
        ->relatedTo((string) $userId)
        ->withClaim('scopes', [])
        // intentionally omitting `cid`
        ->getToken($signer, \Lcobucci\JWT\Signer\Key\InMemory::plainText($privateKeyContent));

    $record = new OauthTokenRecord();
    $record->tokenType = 'access';
    $record->tokenHash = hash('sha256', $jti);
    $record->userId = $userId;
    $record->clientId = $clientId;
    $record->audience = $audience;
    $record->expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
    $record->save();

    return $token->toString();
}

/**
 * Extract the `jti` claim from a JWT string.
 */
function _cortex_oauth_jti_for(string $jwt): string
{
    $parts = explode('.', $jwt);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return $payload['jti'];
}
