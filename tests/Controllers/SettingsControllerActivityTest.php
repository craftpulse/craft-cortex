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
 * sees every row; a non-admin granted `herald:view-activity` sees ONLY
 * their own rows, and cannot widen the result by posting a foreign
 * `filters[userId]`. The harness no-ops the permission gate, so scoping
 * is asserted against the controller's own identity-driven logic — the
 * thing that actually protects the data.
 *
 * Tests bypass HTTP plumbing via the `_HeraldActivityHarness` subclass.
 * Real CP smoke lives in the gate-9.3 manual verification step.
 *
 * Activity actions never write to project config — safe to run under
 * parallel execution; no **SEQUENTIAL ONLY** constraint.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\elements\User;
use craft\web\View;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\db\Table;
use craftpulse\herald\Herald;
use craftpulse\herald\records\Invocation as InvocationRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses HTTP plumbing — mirrors
 * `_HeraldTokensHarness`. `requirePermission` / `requireAcceptsJson` /
 * `requirePostRequest` short-circuit so the action body runs against a
 * console-bootstrapped Craft. The scoping logic reads the live
 * `Craft::$app->getUser()->getIdentity()`, so tests set the identity
 * directly rather than relying on the (no-op'd) permission gate.
 */
class _HeraldActivityHarness extends SettingsController
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
        $this->request = new _HeraldActivityRequest($params);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

class _HeraldActivityRequest
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
function _heraldActivityAdmin(): User
{
    $user = herald_admin_user();
    expect($user)->not->toBeNull();
    return $user;
}

/**
 * Create (or resolve) a saved non-admin user with a stable username so the
 * row-scoping tests have a real user id to bind invocation rows to.
 */
function _heraldActivityNonAdmin(string $handle): User
{
    $existing = Craft::$app->getUsers()->getUserByUsernameOrEmail($handle);
    if ($existing instanceof User) {
        return $existing;
    }

    $user = new User();
    $user->username = $handle;
    $user->email = $handle . '@herald-activity.test';
    $user->admin = false;
    $saved = Craft::$app->getElements()->saveElement($user, false);
    expect($saved)->toBeTrue();
    return $user;
}

/**
 * Insert one `herald_invocations` row through the service writer (which
 * only persists `http`-transport rows — exactly the Activity surface).
 *
 * @param array<string,mixed> $overrides
 */
function _heraldSeedInvocation(array $overrides = []): InvocationRecord
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

    $record = Herald::getInstance()->invocations->record($entry);
    expect($record)->not->toBeNull();
    return $record;
}

beforeEach(function() {
    Craft::$app->getDb()->createCommand()->delete(Table::INVOCATIONS)->execute();
    // Leave whatever identity a prior test set in a known state.
    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());

    // The Activity tab is a Pro surface (Gate 9.7) — pin Pro for the
    // file so `_requirePro()` doesn't 403 every case; the dedicated
    // Free-edition test flips it back inline.
    $this->originalEdition = Herald::getInstance()->edition;
    Herald::getInstance()->edition = Herald::EDITION_PRO;
});

afterEach(function() {
    Herald::getInstance()->edition = $this->originalEdition;
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

it('every Activity action is Pro-gated: 403 on Free (Gate 9.7)', function(string $method, array $params) {
    Herald::getInstance()->edition = Herald::EDITION_FREE;

    $controller = new _HeraldActivityHarness('settings', Herald::getInstance());
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

it('actionConnection is Pro-gated: 403 on Free (Gate 9.7)', function() {
    Herald::getInstance()->edition = Herald::EDITION_FREE;

    $controller = new _HeraldActivityHarness('settings', Herald::getInstance());
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
    $html = Craft::$app->getView()->renderTemplate('herald/_cp/_activity-detail-slideout', [
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
        ->toContain('herald-payload')      // payload dump rendered
        ->toContain('[redacted]')          // already-redacted args shown verbatim
        ->toContain('list');               // mode surfaced
});

// -----------------------------------------------------------------------------
// actionActivityTableData — locked contract + row tuple
// -----------------------------------------------------------------------------

it('actionActivityTableData returns the locked {pagination, data} contract', function() {
    _heraldSeedInvocation();
    _heraldSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toBeArray()->toHaveKeys(['pagination', 'data']);
    expect($response->data['pagination'])->toBeArray()
        ->toHaveKeys(['total', 'per_page', 'current_page', 'last_page']);
    expect($response->data['pagination']['total'])->toBe(2);
});

it('actionActivityTableData data[0] keys equal the locked tuple exactly', function() {
    _heraldSeedInvocation();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
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
    _heraldSeedInvocation();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    $row = $response->data['data'][0];
    expect($row)->not->toHaveKey('argsRedacted');
    expect($row)->not->toHaveKey('responseExcerpt');
    expect($row)->not->toHaveKey('errorMessage');
});

it('actionActivityTableData extracts the mode from redacted args', function() {
    _heraldSeedInvocation(['args' => json_encode(['mode' => 'create', 'secret' => '[redacted]'])]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['mode'])->toBe('create');
});

// -----------------------------------------------------------------------------
// actionActivityTableData — PERMISSION SCOPING (the headline invariant)
// -----------------------------------------------------------------------------

it('admin sees rows for every user', function() {
    $admin = _heraldActivityAdmin();
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $userB = _heraldActivityNonAdmin('herald-activity-b');

    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => (int) $userB->id]);
    _heraldSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity($admin);
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(3);
});

it('non-admin sees only their own rows', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $userB = _heraldActivityNonAdmin('herald-activity-b');

    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => (int) $userB->id]);
    _heraldSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(2);
    foreach ($response->data['data'] as $row) {
        expect($row['user']['id'])->toBe((int) $userA->id);
    }
});

it('non-admin cannot widen scope via a foreign filters[userId]', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $userB = _heraldActivityNonAdmin('herald-activity-b');

    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => (int) $userB->id]);
    _heraldSeedInvocation(['user' => (int) $userB->id]);

    // userA attempts to view userB's rows by spoofing the filter.
    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
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
    _heraldSeedInvocation(['kind' => 'success']);
    _heraldSeedInvocation(['kind' => 'tool_error', 'error_class' => 'RuntimeException', 'error_message' => 'boom']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['kind' => 'tool_error']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['kind'])->toBe('tool_error');
});

it('filters by toolName', function() {
    _heraldSeedInvocation(['tool' => 'entry']);
    _heraldSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['toolName' => 'entry']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('entry');
});

it('filters by date range', function() {
    $old = _heraldSeedInvocation(['tool' => 'old']);
    $new = _heraldSeedInvocation(['tool' => 'new']);

    // Backdate the first row a week.
    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-7 days'))->format('Y-m-d H:i:s')],
        ['id' => $old->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['from' => (new DateTime('-1 day'))->format('Y-m-d')]])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('new');
});

it('search matches the tool name', function() {
    _heraldSeedInvocation(['tool' => 'entry']);
    _heraldSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['search' => 'ent'])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('entry');
});

it('search matches the client name', function() {
    _heraldSeedInvocation(['tool' => 'entry', 'client' => 'claude-desktop']);
    _heraldSeedInvocation(['tool' => 'asset', 'client' => 'cursor']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['search' => 'cursor'])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('asset');
});

it('search matches the error message', function() {
    // Searching the error text is how an operator finds every row that hit
    // the same failure, so the haystack spans three columns.
    _heraldSeedInvocation(['tool' => 'entry']);
    _heraldSeedInvocation([
        'tool' => 'asset',
        'kind' => 'tool_error',
        'error_class' => 'RuntimeException',
        'error_message' => 'volume unreachable',
    ]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['search' => 'unreachable'])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('asset');
});

it('treats an empty filter value as no filter at all', function(string $key) {
    // The filter bar posts every control on every submit, so a cleared
    // dropdown arrives as an empty string. Reading that as a literal value
    // would match nothing and empty the table.
    _heraldSeedInvocation(['tool' => 'entry']);
    _heraldSeedInvocation(['tool' => 'asset']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => [$key => '']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(2);
})->with([
    'kind' => ['kind'],
    'toolName' => ['toolName'],
    'userId' => ['userId'],
    'from' => ['from'],
    'to' => ['to'],
]);

it('ignores a non-array filters param', function() {
    _heraldSeedInvocation(['tool' => 'entry']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => 'not-an-array'])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
});

it('ignores a non-numeric filters[userId] for an admin', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['userId' => 'abc']])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(2);
});

it('admin can narrow to a single user with filters[userId]', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $userB = _heraldActivityNonAdmin('herald-activity-b');
    _heraldSeedInvocation(['user' => (int) $userA->id]);
    _heraldSeedInvocation(['user' => (int) $userB->id]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['userId' => (int) $userB->id]])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['user']['id'])->toBe((int) $userB->id);
});

it('filters by an upper date bound', function() {
    $old = _heraldSeedInvocation(['tool' => 'old']);
    _heraldSeedInvocation(['tool' => 'new']);

    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-7 days'))->format('Y-m-d H:i:s')],
        ['id' => $old->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['filters' => ['to' => (new DateTime('-1 day'))->format('Y-m-d')]])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['tool']['tool'])->toBe('old');
});

it('serialises a null userId row with a null user cell', function() {
    // `herald_invocations.userId` is SET NULL on user delete so audit
    // history outlives the user; the cell is null, not a half-built dict.
    _heraldSeedInvocation(['user' => null]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['user'])->toBeNull();
});

it('sorts ascending when the caller asks for it', function() {
    $first = _heraldSeedInvocation(['tool' => 'first']);
    _heraldSeedInvocation(['tool' => 'second']);

    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
        ['id' => $first->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['sort.0.field' => 'dateCreated', 'sort.0.direction' => 'asc'])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['tool'])->toBe('first');
});

it('sorts by the mapped column for each sortable field', function(string $field) {
    _heraldSeedInvocation(['tool' => 'alpha', 'kind' => 'success', 'duration_ms' => 5]);
    _heraldSeedInvocation(['tool' => 'zulu', 'kind' => 'tool_error', 'duration_ms' => 9, 'error_message' => 'x']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['sort.0.field' => $field, 'sort.0.direction' => 'asc'])
        ->actionActivityTableData();

    // `tool`, `kind` and `durationMs` all order alpha before zulu here, so
    // one expectation pins every mapping without a per-field fixture.
    expect($response->data['data'][0]['tool']['tool'])->toBe('alpha');
})->with([
    'tool' => ['tool'],
    'kind' => ['kind'],
    'durationMs' => ['durationMs'],
]);

it('falls back to dateCreated for an unknown sort field', function() {
    $first = _heraldSeedInvocation(['tool' => 'first']);
    _heraldSeedInvocation(['tool' => 'second']);

    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
        ['id' => $first->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['sort.0.field' => 'bogusColumn', 'sort.0.direction' => 'asc'])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['tool'])->toBe('first');
});

it('pagination respects per_page', function() {
    for ($i = 1; $i <= 5; $i++) {
        _heraldSeedInvocation(['tool' => "tool-{$i}"]);
    }

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['per_page' => 2, 'page' => 1])
        ->actionActivityTableData();

    expect($response->data['pagination']['total'])->toBe(5);
    expect($response->data['pagination']['per_page'])->toBe(2);
    expect($response->data['pagination']['last_page'])->toBe(3);
    expect($response->data['data'])->toHaveCount(2);
});

it('caps per_page at 100', function() {
    _heraldSeedInvocation();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['per_page' => 5000])
        ->actionActivityTableData();

    expect($response->data['pagination']['per_page'])->toBe(100);
});

it('leaves the mode null when the redacted args carry none', function(mixed $args) {
    _heraldSeedInvocation(['args' => $args]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['mode'])->toBeNull();
})->with([
    'no mode key' => ['{"other":1}'],
    'empty mode' => ['{"mode":""}'],
    'non-string mode' => ['{"mode":42}'],
    'not json' => ['not json at all'],
    'json scalar' => ['"just a string"'],
    'empty string' => [''],
]);

it('defaults to dateCreated DESC', function() {
    $first = _heraldSeedInvocation(['tool' => 'first']);
    $second = _heraldSeedInvocation(['tool' => 'second']);

    Craft::$app->getDb()->createCommand()->update(
        Table::INVOCATIONS,
        ['dateCreated' => (new DateTime('-1 hour', new DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
        ['id' => $first->id],
    )->execute();

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams([])
        ->actionActivityTableData();

    expect($response->data['data'][0]['tool']['tool'])->toBe('second');
});

// -----------------------------------------------------------------------------
// actionActivityRow — detail + fail-closed scoping
// -----------------------------------------------------------------------------

it('admin can view any row detail', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $row = _heraldSeedInvocation(['user' => (int) $userA->id]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toHaveKey('html');
    expect($response->data['html'])->toBeString()->not->toBe('');
});

it('non-admin can view their own row detail', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $row = _heraldSeedInvocation(['user' => (int) $userA->id]);

    Craft::$app->getUser()->setIdentity($userA);
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
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
    $row = _heraldSeedInvocation(['response_excerpt' => $truncated]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data)->toHaveKey('html');
    expect($response->data['html'])->toBeString()->not->toBe('');
});

it('row detail pretty-prints the redacted args without escaping slashes', function() {
    // `Json::encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)` — the
    // pretty-print puts a space after each colon, and unescaped slashes keep
    // URLs readable instead of rendering as `https:\/\/`.
    $row = _heraldSeedInvocation([
        'args' => json_encode(['mode' => 'list', 'url' => 'https://example.test/cb']),
    ]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('&quot;mode&quot;: &quot;list&quot;');
    expect($response->data['html'])->toContain('https://example.test/cb');
    expect($response->data['html'])->not->toContain('https:\/\/');
});

it('row detail falls back to raw text for a non-array JSON payload', function() {
    // A JSON scalar decodes fine but is not an array, so it is echoed
    // verbatim rather than re-encoded.
    $row = _heraldSeedInvocation(['response_excerpt' => '"a bare string"']);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('a bare string');
});

it('row detail resolves the bound user for the slideout header', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $row = _heraldSeedInvocation(['user' => (int) $userA->id]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain($userA->getName());
});

it('row detail rejects a missing or non-positive id with 404', function(mixed $id) {
    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());

    expect(function() use ($id) {
        (new _HeraldActivityHarness('settings', Herald::getInstance()))
            ->withParams($id === 'omitted' ? [] : ['id' => $id])
            ->actionActivityRow();
    })->toThrow(NotFoundHttpException::class);
})->with([
    'omitted' => ['omitted'],
    'zero' => [0],
    'negative' => [-1],
    'non-numeric' => ['abc'],
]);

it('non-admin requesting a foreign row gets 404, not 403', function() {
    $userA = _heraldActivityNonAdmin('herald-activity-a');
    $userB = _heraldActivityNonAdmin('herald-activity-b');
    $foreign = _heraldSeedInvocation(['user' => (int) $userB->id]);

    Craft::$app->getUser()->setIdentity($userA);

    expect(function() use ($foreign) {
        (new _HeraldActivityHarness('settings', Herald::getInstance()))
            ->withParams(['id' => (int) $foreign->id])
            ->actionActivityRow();
    })->toThrow(NotFoundHttpException::class);
});

it('missing row id throws 404', function() {
    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());

    expect(function() {
        (new _HeraldActivityHarness('settings', Herald::getInstance()))
            ->withParams(['id' => 999999])
            ->actionActivityRow();
    })->toThrow(NotFoundHttpException::class);
});

it('error row detail includes the error class and message', function() {
    $row = _heraldSeedInvocation([
        'kind' => 'tool_error',
        'error_class' => 'RuntimeException',
        'error_message' => 'something exploded',
    ]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('RuntimeException');
    expect($response->data['html'])->toContain('something exploded');
});

it('cancelled row detail surfaces the cancellation reason from errorMessage', function() {
    $row = _heraldSeedInvocation([
        'kind' => 'cancelled',
        'error_message' => 'client disconnected',
    ]);

    Craft::$app->getUser()->setIdentity(_heraldActivityAdmin());
    $response = (new _HeraldActivityHarness('settings', Herald::getInstance()))
        ->withParams(['id' => (int) $row->id])
        ->actionActivityRow();

    expect($response->data['html'])->toContain('client disconnected');
});
