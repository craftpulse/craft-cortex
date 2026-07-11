<?php

/**
 * =========================================================================
 * DCR client-approval gate tests (WS3).
 *
 * A DCR-registered client starts UNAPPROVED, and an unapproved client
 * is invisible to both `ClientRepository::getClientEntity()` (authorize
 * flow) and `validateClient()` (token flow). The `Oauth` service's
 * approve / revoke surface flips the gate.
 *
 * The `dcrAutoApprove` setting governs whether `registerClient()`
 * stamps a fresh client approved or pending.
 *
 * Client names are prefixed `_test_/` so the teardown matches by name.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\oauth\repositories\ClientRepository;
use craftpulse\herald\records\OauthClient as OauthClientRecord;

beforeEach(function() {
    $this->service = Herald::getInstance()->oauth;
    $this->originalAutoApprove = Herald::getInstance()->getSettings()->dcrAutoApprove;
});

afterEach(function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = $this->originalAutoApprove;
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
});

it('registerClient() lands a new DCR client unapproved by default', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = false;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-default',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect((bool) $record->approved)->toBeFalse();
});

it('registerClient() auto-approves when dcrAutoApprove is on', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = true;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-auto',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect((bool) $record->approved)->toBeTrue();
});

it('an unapproved client is invisible to getClientEntity (authorize flow)', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = false;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-authorize',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $repo = new ClientRepository();
    expect($repo->getClientEntity($response['client_id']))->toBeNull();
});

it('an unapproved confidential client fails validateClient (token flow)', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = false;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-token',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    $repo = new ClientRepository();
    expect($repo->validateClient($response['client_id'], $response['client_secret'], 'authorization_code'))
        ->toBeFalse();
});

it('approveClient() makes the client visible to both flows', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = false;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-flip',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect($this->service->approveClient((int) $record->id))->toBeTrue();

    $repo = new ClientRepository();
    expect($repo->getClientEntity($response['client_id']))->not->toBeNull()
        ->and($repo->validateClient($response['client_id'], null, 'authorization_code'))->toBeTrue();
});

it('approveClient() returns false for an unknown id', function() {
    expect($this->service->approveClient(999999999))->toBeFalse();
});

it('revokeClient() flips an approved client back to unapproved', function() {
    Herald::getInstance()->getSettings()->dcrAutoApprove = true;

    $response = $this->service->registerClient([
        'client_name' => '_test_/approval-revoke',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'none',
    ]);

    $record = OauthClientRecord::findOne(['clientId' => $response['client_id']]);
    expect((bool) $record->approved)->toBeTrue();

    expect($this->service->revokeClient((int) $record->id))->toBeTrue();

    $reloaded = OauthClientRecord::findOne(['id' => $record->id]);
    expect((bool) $reloaded->approved)->toBeFalse();

    // And it's invisible to the authorize flow again.
    $repo = new ClientRepository();
    expect($repo->getClientEntity($response['client_id']))->toBeNull();
});

it('revokeClient() returns false for an unknown id', function() {
    expect($this->service->revokeClient(999999999))->toBeFalse();
});
