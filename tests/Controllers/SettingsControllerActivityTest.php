<?php

/**
 * =========================================================================
 * SettingsController — Activity tab tests (Gate 9.3).
 *
 * Covers the endpoints behind the Activity tab's VueAdminTable +
 * Garnish.Slideout detail pattern:
 *
 *   - `actionActivityTableData` — VueAdminTable data feed + filters.
 *   - `actionActivityRow`        — redacted-detail slideout payload.
 *
 * The headline invariant is the fail-closed permission scope: an admin
 * sees every row; a non-admin granted `cortex:viewActivity` sees ONLY
 * their own rows, and cannot widen the result by posting a foreign
 * `filters[userId]`. The harness no-ops the permission gate, so scoping
 * is asserted against the controller's own identity-driven logic — the
 * thing that actually protects the data.
 *
 * Tests bypass HTTP plumbing via the `_CortexActivityHarness` subclass.
 * Real CP smoke lives in the gate-9.3 manual verification step.
 *
 * Activity actions never write to project config — safe to run under
 * parallel execution; no **SEQUENTIAL ONLY** constraint.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craft\web\View;
use craftpulse\cortex\controllers\SettingsController;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\db\Table;
use craftpulse\cortex\records\Invocation as InvocationRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses HTTP plumbing — mirrors
 * `_CortexTokensHarness`. `requirePermission` / `requireAcceptsJson` /
 * `requirePostRequest` short-circuit so the action body runs against a
 * console-bootstrapped Craft. The scoping logic reads the live
 * `Craft::$app->getUser()->getIdentity()`, so tests set the identity
 * directly rather than relying on the (no-op'd) permission gate.
 */
class _CortexActivityHarness extends SettingsController
{
    /** @var array<string,mixed> */
    public array $params = [];

    public function requirePostRequest(): void
    {
    }

    public function requireAcceptsJson(): void
    {
    }

    public function requirePermission(string $permission): void
    {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function withParams(array $params): self
    {
        $this->params = $params;
        $this->request = new _CortexActivityRequest($params);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

class _CortexActivityRequest
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
}

// -----------------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------------

/**
 * Resolve the seeded admin user.
 */
function _cortexActivityAdmin(): User
{
    $user = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($user)->not->toBeNull();
    return $user;
}

/**
 * Create (or resolve) a saved non-admin user with a stable username so the
 * row-scoping tests have a real user id to bind invocation rows to.
 */
function _cortexActivityNonAdmin(string $handle): User
{
    $existing = Craft::$app->getUsers()->getUserByUsernameOrEmail($handle);
    if ($existing instanceof User) {
        return $existing;
    }

    $user = new User();
    $user->username = $handle;
    $user->email = $handle . '@cortex-activity.test';
    $user->admin = false;
    $saved = Craft::$app->getElements()->saveElement($user, false);
    expect($saved)->toBeTrue();
    return $user;
}

/**
 * Insert one `cortex_invocations` row through the service writer (which
 * only persists `http`-transport rows — exactly the Activity surface).
 *
 * @param array<string,mixed> $overrides
 */
function _cortexSeedInvocation(array $overrides = []): InvocationRecord
{
    $entry = array_merge([
        'transport' => 'http',
        'tool' => 'entry',
        'kind' => 'success',
        'duration_ms' => 42,
        'args' => json_encode(['mode' => 'list']),
        'response_excerpt' => json_encode(['ok' => true]),
        'user' => null,
        'client' => 'claude-desktop',
    ], $overrides);

    $record = Cortex::getInstance()->invocations->record($entry);
    expect($record)->not->toBeNull();
    return $record;
}

beforeEach(function() {
    Craft::$app->getDb()->createCommand()->delete(Table::INVOCATIONS)->execute();
    // Leave whatever identity a prior test set in a known state.
    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());

    // The Activity tab is a Pro surface (Gate 9.7) — pin Pro for the
    // file so `_requirePro()` doesn't 403 every case; the dedicated
    // Free-edition test flips it back inline.
    $this->originalEdition = Cortex::getInstance()->edition;
    Cortex::getInstance()->edition = Cortex::EDITION_PRO;
});

afterEach(function() {
    Cortex::getInstance()->edition = $this->originalEdition;
});

afterAll(function() {
    Craft::$app->getDb()->createCommand()->delete(Table::INVOCATIONS)->execute();
});

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('declares the Activity endpoints', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionActivity', 'actionActivityTableData', 'actionActivityRow'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

it('every Activity action is Pro-gated — 403 on Free (Gate 9.7)', function(string $method, array $params) {
    Cortex::getInstance()->edition = Cortex::EDITION_FREE;

    $controller = new _CortexActivityHarness('settings', Cortex::getInstance());
    $controller->withParams($params);

    // `_requirePro()` is private, so the harness's permission no-ops
    // cannot accidentally bypass it — exactly the point.
    expect(fn() => $controller->{$method}())
        ->toThrow(\yii\web\ForbiddenHttpException::class);
})->with([
    'activity view' => ['actionActivity', []],
    'table data' => ['actionActivityTableData', []],
    'row detail' => ['actionActivityRow', ['id' => 1]],
]);

it('actionConnection is Pro-gated — 403 on Free (Gate 9.7)', function() {
    Cortex::getInstance()->edition = Cortex::EDITION_FREE;

    $controller = new _CortexActivityHarness('settings', Cortex::getInstance());
    $controller->withParams([]);

    expect(fn() => $controller->actionConnection())
        ->toThrow(\yii\web\ForbiddenHttpException::class);
});

it('the activity detail slideout partial compiles and renders the redacted columns', function() {
    // The slideout partial is the console-renderable surface (no
    // `_layouts/cp` chrome). `mode` / `argsPretty` / `responsePretty`
    // are pre-computed by `actionActivityRow` (PHP-side tolerant decode
    // — see `_prettyRedactedColumn`), so they are passed here the same
    // way. The full-page index template is browser-smoke-verified per
    // the gate-9.3 plan (it depends on `_layouts/cp`, which needs a web
    // request the console harness cannot supply).
    $html = Craft::$app->getView()->renderTemplate('cortex/_cp/_activity-detail-slideout', [
        'row' => [
            'id' => 1,
            'toolName' => 'entry',
            'kind' => 'success',
            'durationMs' => 42,
            'transport' => 'http',
            'clientName' => 'claude-desktop',
            'argsRedacted' => json_encode(['mode' => 'list', 'apiKey' => '[redacted]']),
            'responseExcerpt' => json_encode(['ok' => true]),
            'dateCreated' => '2026-06-10 12:00:00',
            'rateLimitRemaining' => 59,
        ],
        'user' => null,
        'mode' => 'list',
        'argsPretty' => json_encode(['mode' => 'list', 'apiKey' => '[redacted]'], JSON_PRETTY_PRINT),
        'responsePretty' => json_encode(['ok' => true], JSON_PRETTY_PRINT),
    ], View::TEMPLATE_MODE_CP);

    expect($html)->toBeString()
        ->toContain('cortex-payload')      // payload dump rendered
        ->toContain('[redacted]')          // already-redacted args shown verbatim
        ->toContain('list');               // mode surfaced
});

// -----------------------------------------------------------------------------
// actionActivityTableData — locked contract + row tuple
// -----------------------------------------------------------------------------

it('actionActivityTableData returns the locked {pagination, data} contract', function() {
    _cortexSeedInvocation();
    _cortexSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toBeArray()->toHaveKeys(['pagination', 'data']);
    expect($response->data['pagination'])->toBeArray()
        ->toHaveKeys(['total', 'per_page', 'current_page', 'last_page']);
    expect($response->data['pagination']['total'])->toBe(2);
});

it('actionActivityTableData data[0] keys equal the locked tuple exactly', function() {
    _cortexSeedInvocation();

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    $row = $response->data['data'][0];
    expect(array_keys($row))->toBe([
        'id',
        'tool',
        'kind',
        'user',
        'durationMs',
        'dateCreated',
    ]);
    // The tool cell is a `{id, tool, mode}` composite — VueAdminTable
    // column callbacks receive only the cell value, never the row, so
    // the detail-trigger id and mode suffix travel inside the value.
    expect(array_keys($row['tool']))->toBe(['id', 'tool', 'mode']);
    expect($row['tool']['id'])->toBe($row['id']);
});

it('actionActivityTableData never surfaces redacted payload columns in the table', function() {
    _cortexSeedInvocation();

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    $row = $response->data['data'][0];
    expect($row)->not->toHaveKey('argsRedacted');
    expect($row)->not->toHaveKey('responseExcerpt');
    expect($row)->not->toHaveKey('errorMessage');
});

it('actionActivityTableData extracts the mode from redacted args', function() {
    _cortexSeedInvocation(['args' => json_encode(['mode' => 'create', 'secret' => '[redacted]'])]);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['mode'])->toBe('create');
});

// -----------------------------------------------------------------------------
// actionActivityTableData — PERMISSION SCOPING (the headline invariant)
// -----------------------------------------------------------------------------

it('admin sees rows for every user', function() {
    $admin = _cortexActivityAdmin();
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $userB = _cortexActivityNonAdmin('cortex-activity-b');

    _cortexSeedInvocation(['user' => (int) $userA->id]);
    _cortexSeedInvocation(['user' => (int) $userB->id]);
    _cortexSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity($admin);
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(3);
});

it('non-admin sees only their own rows', function() {
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $userB = _cortexActivityNonAdmin('cortex-activity-b');

    _cortexSeedInvocation(['user' => (int) $userA->id]);
    _cortexSeedInvocation(['user' => (int) $userA->id]);
    _cortexSeedInvocation(['user' => (int) $userB->id]);
    _cortexSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(2);
    foreach ($response->data['data'] as $row) {
        expect($row['user']['id'])->toBe((int) $userA->id);
    }
});

it('non-admin cannot widen scope via a foreign filters[userId]', function() {
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $userB = _cortexActivityNonAdmin('cortex-activity-b');

    _cortexSeedInvocation(['user' => (int) $userA->id]);
    _cortexSeedInvocation(['user' => (int) $userB->id]);
    _cortexSeedInvocation(['user' => (int) $userB->id]);

    // userA attempts to view userB's rows by spoofing the filter.
    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['filters' => ['userId' => (int) $userB->id]])
        ->actionActivityTableData();

    // The spoofed foreign filter is ignored — a non-admin stays pinned to
    // their own rows. userA owns exactly one row, and it is theirs, not
    // userB's. The widen attempt cannot reach userB's two rows.
    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['user']['id'])->toBe((int) $userA->id);
});

// -----------------------------------------------------------------------------
// actionActivityTableData — filters
// -----------------------------------------------------------------------------

it('filters by kind', function() {
    _cortexSeedInvocation(['kind' => 'success']);
    _cortexSeedInvocation(['kind' => 'tool_error', 'error_class' => 'RuntimeException', 'error_message' => 'boom']);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['filters' => ['kind' => 'tool_error']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['kind'])->toBe('tool_error');
});

it('filters by toolName', function() {
    _cortexSeedInvocation(['tool' => 'entry']);
    _cortexSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['filters' => ['toolName' => 'entry']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('entry');
});

it('filters by date range', function() {
    $old = _cortexSeedInvocation(['tool' => 'old']);
    $new = _cortexSeedInvocation(['tool' => 'new']);

    // Backdate the first row a week.
    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-7 days'))->format('Y-m-d H:i:s')],
        ['id' => $old->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['filters' => ['from' => (new DateTime('-1 day'))->format('Y-m-d')]])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('new');
});

it('search matches the tool name', function() {
    _cortexSeedInvocation(['tool' => 'entry']);
    _cortexSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['search' => 'ent'])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('entry');
});

it('defaults to dateCreated DESC', function() {
    $first = _cortexSeedInvocation(['tool' => 'first']);
    $second = _cortexSeedInvocation(['tool' => 'second']);

    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-1 hour'))->format('Y-m-d H:i:s')],
        ['id' => $first->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['tool'])->toBe('second');
});

// -----------------------------------------------------------------------------
// actionActivityRow — detail + fail-closed scoping
// -----------------------------------------------------------------------------

it('admin can view any row detail', function() {
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $row = _cortexSeedInvocation(['user' => (int) $userA->id]);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toHaveKey('html');
    expect($response->data['html'])->toBeString()->not->toBe('');
});

it('non-admin can view their own row detail', function() {
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $row = _cortexSeedInvocation(['user' => (int) $userA->id]);

    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toBeString()->not->toBe('');
});

it('row detail survives a truncated (non-JSON) response excerpt', function() {
    // The logger clips `responseExcerpt` to a fixed length, so a real
    // excerpt is frequently cut mid-document — NOT valid JSON. The
    // detail render must fall back to the raw text instead of letting
    // `Json::decode` throw (a truncated excerpt 500'd the slideout in
    // the gate-9 browser smoke).
    $truncated = substr(json_encode(['data' => "multi\nline value", 'big' => str_repeat('x', 50)]), 0, 40);
    $row = _cortexSeedInvocation(['response_excerpt' => $truncated]);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data)->toHaveKey('html');
    expect($response->data['html'])->toBeString()->not->toBe('');
});

it('non-admin requesting a foreign row gets 404, not 403', function() {
    $userA = _cortexActivityNonAdmin('cortex-activity-a');
    $userB = _cortexActivityNonAdmin('cortex-activity-b');
    $foreign = _cortexSeedInvocation(['user' => (int) $userB->id]);

    Craft::$app->getUser()->setIdentity($userA);

    expect(function() use ($foreign) {
        (new _CortexActivityHarness('settings', Cortex::getInstance()))
            ->withParams(['id' => (int) $foreign->id])
            ->actionActivityRow();
    })->toThrow(NotFoundHttpException::class);
});

it('missing row id throws 404', function() {
    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());

    expect(function() {
        (new _CortexActivityHarness('settings', Cortex::getInstance()))
            ->withParams(['id' => 999999])
            ->actionActivityRow();
    })->toThrow(NotFoundHttpException::class);
});

it('error row detail includes the error class and message', function() {
    $row = _cortexSeedInvocation([
        'kind' => 'tool_error',
        'error_class' => 'RuntimeException',
        'error_message' => 'something exploded',
    ]);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('RuntimeException');
    expect($response->data['html'])->toContain('something exploded');
});

it('cancelled row detail surfaces the cancellation reason from errorMessage', function() {
    $row = _cortexSeedInvocation([
        'kind' => 'cancelled',
        'error_message' => 'client disconnected',
    ]);

    Craft::$app->getUser()->setIdentity(_cortexActivityAdmin());
    $response = (new _CortexActivityHarness('settings', Cortex::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('client disconnected');
});
