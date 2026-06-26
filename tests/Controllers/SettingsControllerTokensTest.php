<?php

/**
 * =========================================================================
 * SettingsController — Tokens tab tests (Gate 9.5).
 *
 * Covers the endpoints behind the Tokens tab's VueAdminTable +
 * Garnish.Slideout pattern:
 *
 *   - `actionTokensTableData`  — VueAdminTable data feed.
 *   - `actionIssueToken`        — JSON branch happy path + one-time plaintext.
 *   - `actionRevokeToken`       — JSON branch happy path + 404.
 *
 * Permission boundary tests live in `SettingsControllerScaffoldingTest`
 * (architecture invariant — every action body opens with the gate). This
 * file focuses on the data-shape contract: the row tuple
 * `[id, name, tokenPrefix, user, expiresAt, lastUsedAt, dateCreated]` is
 * architecture-invariant; drift breaks the table silently.
 *
 * Tests bypass HTTP plumbing via the `_CortexTokensHarness` subclass.
 * Real CP smoke lives in the gate-9.5 manual verification step.
 *
 * Token actions never write to project config, so this file is safe to
 * run under parallel execution — no **SEQUENTIAL ONLY** constraint.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\controllers\SettingsController;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\db\Table;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses HTTP plumbing — mirrors
 * `_CortexAllowlistHarness`. `requireAdmin` / `requireAcceptsJson` /
 * `requirePostRequest` short-circuit so the action body runs against a
 * console-bootstrapped Craft.
 */
class _CortexTokensHarness extends SettingsController
{
    /** @var array<string,mixed> */
    public array $params = [];

    public function requirePostRequest(): void
    {
        // no-op
    }

    public function requireAcceptsJson(): void
    {
        // no-op
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
        // no-op
    }

    /**
     * @param array<string,mixed> $params
     */
    public function withParams(array $params): self
    {
        $this->params = $params;
        $this->request = new _CortexTokensRequest($params);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

/**
 * Variant harness that stubs ONLY the HTTP plumbing and leaves the REAL
 * `requireAdmin(requireAdminChanges: true)` gate in place — mirrors the
 * Clients test, so the admin-only posture on issue / revoke is genuinely
 * exercised (MAJOR 5) rather than asserted by inspection.
 */
class _CortexTokensRealAdminHarness extends SettingsController
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
        $this->request = new _CortexTokensRequest($params);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

class _CortexTokensRequest
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

    public function getIsOptions(): bool
    {
        return false;
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
// Helpers + fixture cleanup — every test runs against an empty tokens table.
// -----------------------------------------------------------------------------

/**
 * Resolve a real user to bind issued tokens to. The playground seeds an
 * admin under one of these handles.
 */
function _cortexTokenTestUser(): craft\elements\User
{
    $user = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($user)->not->toBeNull();
    return $user;
}

beforeEach(function() {
    // Hard-truncate the tokens table — `Tokens::revoke` only
    // soft-deletes, so prior runs would otherwise leak rows.
    Craft::$app->getDb()
        ->createCommand()
        ->delete(Table::TOKENS)
        ->execute();

    // The Tokens tab is a Pro surface (Gate 9.7) — pin Pro for the
    // file so `_requirePro()` doesn't 403 every case; the dedicated
    // Free-edition test flips it back inline.
    $this->originalEdition = Cortex::getInstance()->edition;
    Cortex::getInstance()->edition = Cortex::EDITION_PRO;
});

afterEach(function() {
    Cortex::getInstance()->edition = $this->originalEdition;
});

afterAll(function() {
    Craft::$app->getDb()
        ->createCommand()
        ->delete(Table::TOKENS)
        ->execute();
});

// -----------------------------------------------------------------------------
// Structural — the Tokens endpoints exist
// -----------------------------------------------------------------------------

it('declares the Tokens endpoints', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionTokens', 'actionTokensTableData', 'actionTokenIssueSlideout', 'actionIssueToken', 'actionRevokeToken'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

it('every Tokens action is Pro-gated — 403 on Free (Gate 9.7)', function(string $method, array $params) {
    Cortex::getInstance()->edition = Cortex::EDITION_FREE;

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams($params);

    // `_requirePro()` is private, so the harness's `requireAdmin`
    // no-op cannot accidentally bypass it — exactly the point.
    expect(fn() => $controller->{$method}())
        ->toThrow(\yii\web\ForbiddenHttpException::class);
})->with([
    'tokens view' => ['actionTokens', []],
    'table data' => ['actionTokensTableData', []],
    'issue slideout' => ['actionTokenIssueSlideout', []],
    'issue' => ['actionIssueToken', ['userId' => 1]],
    'revoke' => ['actionRevokeToken', ['id' => 1]],
]);

it('issue / revoke deny a non-admin via the REAL requireAdmin gate', function(string $method, array $params) {
    // MAJOR 5: a logged-in non-admin must be refused by
    // `requireAdmin(requireAdminChanges: true)`. This harness leaves the
    // real gate in place (only plumbing is stubbed), so the admin-only
    // posture is genuinely exercised, not asserted by source inspection.
    $original = Craft::$app->getUser()->getIdentity();

    $nonAdmin = new \craft\elements\User();
    $nonAdmin->username = '_test_tokens_nonadmin_' . bin2hex(random_bytes(4));
    $nonAdmin->email = $nonAdmin->username . '@example.test';
    $nonAdmin->admin = false;
    expect(Craft::$app->getElements()->saveElement($nonAdmin))->toBeTrue();

    try {
        Craft::$app->getUser()->setIdentity($nonAdmin);
        expect(Craft::$app->getUser()->getIsAdmin())->toBeFalse();

        $controller = new _CortexTokensRealAdminHarness('settings', Cortex::getInstance());
        $controller->withParams($params);

        expect(fn() => $controller->{$method}())
            ->toThrow(\yii\web\ForbiddenHttpException::class);
    } finally {
        Craft::$app->getUser()->setIdentity($original);
        Craft::$app->getElements()->deleteElement($nonAdmin, true);
    }
})->with([
    'issue' => ['actionIssueToken', ['userId' => 1]],
    'revoke' => ['actionRevokeToken', ['id' => 1]],
]);

it('actionTokenIssueSlideout returns {html, headHtml, bodyHtml} with the view JS deltas', function() {
    // The full render cannot be driven through the Pest harness — the
    // partial's `csrfInput()` needs a web request/response pair and the
    // bootstrap binds console ones (same constraint documented in
    // OauthControllerTest). Lock the contract structurally instead: the
    // action must return the JSON triple and pull the head/body deltas
    // off the view, so the element-select init that
    // `forms.elementSelectField` registers through the view reaches the
    // browser. A bare-fragment response ships the user picker dead —
    // caught by the gate-9 browser smoke.
    $method = new ReflectionMethod(SettingsController::class, 'actionTokenIssueSlideout');
    $lines = file((string) $method->getFileName());
    expect($lines)->not->toBeFalse();
    $source = implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect($source)->toContain('asJson');
    expect($source)->toContain("'html' => \$html");
    expect($source)->toContain("'headHtml' => \$view->getHeadHtml()");
    expect($source)->toContain("'bodyHtml' => \$view->getBodyHtml()");
});

// -----------------------------------------------------------------------------
// actionTokensTableData — locked row tuple + pagination contract
// -----------------------------------------------------------------------------

it('actionTokensTableData returns the locked {pagination, data} contract', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'one', 3600);
    Cortex::getInstance()->tokens->issue((int) $user->id, 'two', 3600);
    Cortex::getInstance()->tokens->issue((int) $user->id, 'three', null);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([]);

    $response = $controller->actionTokensTableData();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toBeArray()->toHaveKeys(['pagination', 'data']);
    expect($response->data['pagination'])->toBeArray()->toHaveKeys(['total', 'per_page', 'current_page', 'last_page']);
    expect($response->data['pagination']['total'])->toBe(3);
    expect($response->data['data'])->toBeArray()->toHaveCount(3);
});

it('actionTokensTableData data[0] keys equal the locked tuple exactly', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'shape', 3600);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([]);
    $response = $controller->actionTokensTableData();

    $row = $response->data['data'][0];
    expect(array_keys($row))->toBe([
        'id',
        'name',
        'tokenPrefix',
        'user',
        'expiresAt',
        'lastUsedAt',
        'dateCreated',
    ]);
});

it('actionTokensTableData never leaks the plaintext or its hash', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'secret', 3600);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([]);
    $response = $controller->actionTokensTableData();

    $row = $response->data['data'][0];
    expect($row)->not->toHaveKey('token');
    expect($row)->not->toHaveKey('tokenHash');
    // tokenPrefix is the 8-char hint, not the full secret.
    expect(strlen((string) $row['tokenPrefix']))->toBe(8);
});

it('actionTokensTableData serialises the bound user', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'attributed', 3600);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([]);
    $response = $controller->actionTokensTableData();

    $row = $response->data['data'][0];
    expect($row['user'])->toBeArray()->toHaveKeys(['id', 'label', 'cpEditUrl']);
    expect($row['user']['id'])->toBe((int) $user->id);
    expect($row['user']['label'])->toBeString()->not->toBe('');
});

it('actionTokensTableData handles a never-expiring token (expiresAt null)', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'forever', null);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([]);
    $response = $controller->actionTokensTableData();

    $row = $response->data['data'][0];
    expect($row['expiresAt'])->toBeNull();
    expect($row['lastUsedAt'])->toBeNull();
});

it('actionTokensTableData search filters by name substring', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'claude-desktop', 3600);
    Cortex::getInstance()->tokens->issue((int) $user->id, 'cursor', 3600);
    Cortex::getInstance()->tokens->issue((int) $user->id, 'chatgpt', 3600);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['search' => 'claude']);
    $response = $controller->actionTokensTableData();

    expect($response->data['data'])->toHaveCount(1);
    expect($response->data['data'][0]['name'])->toBe('claude-desktop');
});

it('actionTokensTableData sort name DESC reverses default order', function() {
    $user = _cortexTokenTestUser();
    Cortex::getInstance()->tokens->issue((int) $user->id, 'alpha', 3600);
    Cortex::getInstance()->tokens->issue((int) $user->id, 'zulu', 3600);

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['sort.0.field' => 'name', 'sort.0.direction' => 'desc']);
    $response = $controller->actionTokensTableData();

    expect($response->data['data'][0]['name'])->toBe('zulu');
    expect($response->data['data'][1]['name'])->toBe('alpha');
});

it('actionTokensTableData pagination respects per_page', function() {
    $user = _cortexTokenTestUser();
    for ($i = 1; $i <= 5; $i++) {
        Cortex::getInstance()->tokens->issue((int) $user->id, "tok-{$i}", 3600);
    }

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['per_page' => 2, 'page' => 1]);
    $response = $controller->actionTokensTableData();

    expect($response->data['pagination']['total'])->toBe(5);
    expect($response->data['pagination']['per_page'])->toBe(2);
    expect($response->data['pagination']['last_page'])->toBe(3);
    expect($response->data['data'])->toHaveCount(2);
});

// -----------------------------------------------------------------------------
// actionIssueToken — JSON happy + one-time plaintext + validation
// -----------------------------------------------------------------------------

it('actionIssueToken returns the plaintext once and creates a row', function() {
    $user = _cortexTokenTestUser();

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams([
        'userId' => (int) $user->id,
        'name' => 'integration-test',
        'ttlSeconds' => 86400,
    ]);

    $response = $controller->actionIssueToken();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(200);
    expect($response->data)->toBeArray()->toHaveKeys(['token', 'model']);

    $plaintext = $response->data['token'];
    expect($plaintext)->toBeString()->not->toBe('');

    // Row landed in the DB.
    $row = Cortex::getInstance()->tokens->getAll();
    expect($row)->toHaveCount(1);
    expect($row[0]->name)->toBe('integration-test');

    // The plaintext authenticates — lookup resolves the freshly-issued
    // model, proving the response is the live credential.
    $looked = Cortex::getInstance()->tokens->lookup($plaintext);
    expect($looked)->not->toBeNull();
    expect($looked->name)->toBe('integration-test');

    // Model row carries the locked tuple, never the plaintext.
    expect(array_keys($response->data['model']))->toBe([
        'id',
        'name',
        'tokenPrefix',
        'user',
        'expiresAt',
        'lastUsedAt',
        'dateCreated',
    ]);
});

it('actionIssueToken auto-names the token when name is omitted', function() {
    $user = _cortexTokenTestUser();

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['userId' => (int) $user->id]);

    $response = $controller->actionIssueToken();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['name'])->toStartWith('cli-');
});

it('actionIssueToken accepts the elementSelect array form for userId', function() {
    $user = _cortexTokenTestUser();

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    // The `elementSelectField` macro posts `userId[]`.
    $controller->withParams(['userId' => [(int) $user->id], 'name' => 'array-form']);

    $response = $controller->actionIssueToken();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['user']['id'])->toBe((int) $user->id);
});

it('actionIssueToken missing user returns 400', function() {
    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['name' => 'no-user']);

    $response = $controller->actionIssueToken();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toBeArray()->toHaveKey('message', 'A user is required.');
});

// -----------------------------------------------------------------------------
// actionRevokeToken — JSON happy + 404 paths
// -----------------------------------------------------------------------------

it('actionRevokeToken soft-deletes the row and invalidates the plaintext', function() {
    $user = _cortexTokenTestUser();
    $issued = Cortex::getInstance()->tokens->issue((int) $user->id, 'doomed', 3600);
    $plaintext = $issued['token'];

    // Sanity — the token authenticates before revocation.
    expect(Cortex::getInstance()->tokens->lookup($plaintext))->not->toBeNull();

    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['id' => (int) $issued['model']->id]);

    $response = $controller->actionRevokeToken();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(200);

    // Subsequent lookup of the same plaintext fails.
    expect(Cortex::getInstance()->tokens->lookup($plaintext))->toBeNull();
    expect(Cortex::getInstance()->tokens->getAll())->toBeArray()->toBeEmpty();
});

it('actionRevokeToken unknown id returns 404', function() {
    $controller = new _CortexTokensHarness('settings', Cortex::getInstance());
    $controller->withParams(['id' => 999999]);

    $response = $controller->actionRevokeToken();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(404);
    expect($response->data)->toBeArray()->toHaveKey('message', 'Token not found.');
});
