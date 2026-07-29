<?php

/**
 * =========================================================================
 * SettingsController — Clients tab tests (WS3).
 *
 * Covers the endpoints behind the Clients tab's VueAdminTable + inline
 * approve / revoke:
 *
 *   - `actionClientsTableData` — VueAdminTable data feed + locked row tuple.
 *   - `actionApproveClient`    — JSON happy path + 404.
 *   - `actionRevokeClient`     — JSON happy path + 404.
 *   - Pro gating — every action 403s on Free (Gate 9.7 posture).
 *
 * The harness bypasses HTTP plumbing the same way the Tokens test does;
 * `_requirePro()` is private so the no-op `requireAdmin` cannot bypass
 * the edition gate.
 *
 * Client rows never write to project config — safe under parallel
 * execution.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\Herald;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

class _HeraldClientsHarness extends SettingsController
{
    /** @var array<string,mixed> */
    public array $params = [];

    public function requirePostRequest(): void
    {
    }

    public function requireAcceptsJson(): void
    {
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function withParams(array $params): self
    {
        $this->params = $params;
        $this->request = new _HeraldClientsRequest($params);
        $this->response = new Response();
        $this->response->formatters[Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

/**
 * Variant harness that stubs ONLY the HTTP plumbing
 * (`requirePostRequest` / `requireAcceptsJson`) and leaves the REAL
 * `requireAdmin(requireAdminChanges: true)` gate in place, so the
 * admin-only denial is genuinely exercised rather than asserted by
 * inspection.
 */
class _HeraldClientsRealAdminHarness extends SettingsController
{
    public function requirePostRequest(): void
    {
    }

    public function requireAcceptsJson(): void
    {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function withParams(array $params): self
    {
        $this->request = new _HeraldClientsRequest($params);
        $this->response = new Response();
        $this->response->formatters[Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

class _HeraldClientsRequest
{
    public bool $isCpRequest = true;

    /** @param array<string,mixed> $params */
    public function __construct(private array $params)
    {
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        if (!array_key_exists($name, $this->params)) {
            throw new \yii\web\BadRequestHttpException("Missing required body param: {$name}");
        }
        return $this->params[$name];
    }

    public function getBodyParam(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function getParam(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function getAcceptsJson(): bool
    {
        return true;
    }

    public function getIsCpRequest(): bool
    {
        return true;
    }

    public function getCsrfToken(): string
    {
        return 'test-csrf-token';
    }
}

// -----------------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------------

/**
 * Persist a client row for the controller tests.
 */
function _heraldMakeClient(string $name, bool $approved): OauthClientRecord
{
    $record = new OauthClientRecord();
    $record->clientId = bin2hex(random_bytes(16));
    $record->clientName = $name;
    $record->redirectUris = '["https://example.com/cb"]';
    $record->isPublic = true;
    $record->approved = $approved;
    $record->save();
    return $record;
}

beforeEach(function() {
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
    $this->originalEdition = Herald::getInstance()->edition;
    Herald::getInstance()->edition = Herald::EDITION_PRO;
});

afterEach(function() {
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
    Herald::getInstance()->edition = $this->originalEdition;
});

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('declares the Clients endpoints', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionClients', 'actionClientsTableData', 'actionApproveClient', 'actionRevokeClient'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

it('every Clients action is Pro-gated: 403 on Free', function(string $method, array $params) {
    Herald::getInstance()->edition = Herald::EDITION_FREE;

    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $controller->withParams($params);

    expect(fn() => $controller->{$method}())
        ->toThrow(\yii\web\ForbiddenHttpException::class);
})->with([
    'table-data' => ['actionClientsTableData', []],
    'approve' => ['actionApproveClient', ['id' => 1]],
    'revoke' => ['actionRevokeClient', ['id' => 1]],
]);

// -----------------------------------------------------------------------------
// Real admin-gate denial (MAJOR 5)
// -----------------------------------------------------------------------------

it('approve / revoke deny a non-admin via the REAL requireAdmin gate', function(string $method) {
    // A non-admin, logged-in user must be refused by
    // `requireAdmin(requireAdminChanges: true)`. The harness here leaves
    // the real gate in place (only HTTP plumbing is stubbed), so this
    // proves the admin-only posture rather than asserting it by reading
    // the source.
    $original = Craft::$app->getUser()->getIdentity();

    $nonAdmin = new \craft\elements\User();
    $nonAdmin->username = '_test_clients_nonadmin_' . bin2hex(random_bytes(4));
    $nonAdmin->email = $nonAdmin->username . '@example.test';
    $nonAdmin->admin = false;
    expect(Craft::$app->getElements()->saveElement($nonAdmin))->toBeTrue();

    try {
        Craft::$app->getUser()->setIdentity($nonAdmin);
        expect(Craft::$app->getUser()->getIsAdmin())->toBeFalse();

        $controller = new _HeraldClientsRealAdminHarness('settings', Herald::getInstance());
        $controller->withParams(['id' => 1]);

        expect(fn() => $controller->{$method}())
            ->toThrow(\yii\web\ForbiddenHttpException::class);
    } finally {
        Craft::$app->getUser()->setIdentity($original);
        Craft::$app->getElements()->deleteElement($nonAdmin, true);
    }
})->with([
    'approve' => ['actionApproveClient'],
    'revoke' => ['actionRevokeClient'],
]);

// -----------------------------------------------------------------------------
// Table data
// -----------------------------------------------------------------------------

it('clients-table-data returns the locked row tuple', function() {
    _heraldMakeClient('_test_/row-shape', false);

    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $response = $controller->withParams([])->actionClientsTableData();

    expect($response->data)->toHaveKeys(['pagination', 'data']);
    $rows = array_values(array_filter(
        $response->data['data'],
        static fn(array $row): bool => str_starts_with((string) $row['clientName'], '_test_/'),
    ));
    expect($rows)->not->toBeEmpty();

    $row = $rows[0];
    expect($row)->toHaveKeys([
        'id', 'clientName', 'clientId', 'type', 'approved', 'approveAction', 'redirectUris', 'dateCreated',
    ]);
    expect($row['type'])->toBe('public')
        ->and($row['approved'])->toBeFalse()
        ->and($row['redirectUris'])->toBeArray();
});

// -----------------------------------------------------------------------------
// Approve / revoke
// -----------------------------------------------------------------------------

it('approve-client flips the client to approved', function() {
    $client = _heraldMakeClient('_test_/approve-controller', false);

    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['id' => $client->id])->actionApproveClient();

    expect($response->getStatusCode())->toBe(200);

    $reloaded = OauthClientRecord::findOne(['id' => $client->id]);
    expect((bool) $reloaded->approved)->toBeTrue();
});

it('approve-client 404s for an unknown id', function() {
    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['id' => 999999999])->actionApproveClient();

    expect($response->getStatusCode())->toBe(404);
});

it('revoke-client flips the client back to unapproved', function() {
    $client = _heraldMakeClient('_test_/revoke-controller', true);

    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['id' => $client->id])->actionRevokeClient();

    expect($response->getStatusCode())->toBe(200);

    $reloaded = OauthClientRecord::findOne(['id' => $client->id]);
    expect((bool) $reloaded->approved)->toBeFalse();
});

it('revoke-client 404s for an unknown id', function() {
    $controller = new _HeraldClientsHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['id' => 999999999])->actionRevokeClient();

    expect($response->getStatusCode())->toBe(404);
});
