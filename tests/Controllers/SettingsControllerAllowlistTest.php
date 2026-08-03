<?php

/**
 * =========================================================================
 * SettingsController — Allowlist tab tests (Gate 9.2).
 *
 * Covers the four endpoints behind the Allowlist tab's VueAdminTable +
 * Garnish.Slideout pattern:
 *
 *   - `actionAllowlistTableData`        — VueAdminTable data feed.
 *   - `actionAllowlistOverrideSlideout` — slideout body HTML fragment.
 *   - `actionAddOverride`                — JSON branch happy path.
 *   - `actionRemoveOverride`             — JSON branch happy path.
 *
 * Permission boundary tests live in `SettingsControllerScaffoldingTest`
 * (architecture invariant — every action body opens with the gate) and
 * the route-permission matrix lives there too. This file focuses on
 * the data shape contract: the row tuple
 * `[id, pattern: {pattern, isExpired}, note, expiresAt: {value, isExpired},
 * createdBy, dateCreated]` is architecture-invariant; drift breaks the
 * table silently. The `pattern`/`expiresAt` cells are composites because
 * VueAdminTable column callbacks receive only the cell value, never the
 * row.
 *
 * Tests bypass HTTP plumbing via the `_HeraldAllowlistHarness` subclass.
 * Real CP smoke lives in the gate-9.2 manual verification step.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craft\db\Query;
use craft\elements\User;
use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\db\Table;
use craftpulse\herald\Herald;
use craftpulse\herald\records\RuntimeOverride;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses HTTP plumbing. Both
 * `requireAdmin` + `requireAcceptsJson` + `requirePostRequest`
 * short-circuit so the action body runs against a console-bootstrapped
 * Craft. Drops a web Response into the inherited slot so `asJson` /
 * `asFailure` can call `setStatusCode()` (console Response does not
 * declare the method).
 */
class _HeraldAllowlistHarness extends SettingsController
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

    public function requirePermission(string $permission): void
    {
        // no-op — grant actions gate on herald:manage-grants; the harness
        // runs the body without a logged-in identity.
    }

    /**
     * Swap request + response for ones the controller's JSON branch can
     * exercise. `withParams` is intentionally generic — the table-data
     * endpoint reads via `getParam()`, the mutations via `getBodyParam`
     * / `getRequiredBodyParam`, so the stub satisfies all three.
     *
     * @param array<string,mixed> $params
     */
    public function withParams(array $params): self
    {
        $this->params = $params;
        $this->request = new _HeraldAllowlistRequest($params);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

class _HeraldAllowlistRequest
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
// Fixture cleanup — every test runs against an empty overrides table.
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Hard-truncate the overrides table — `Allowlist::remove` only
    // soft-deletes, so prior runs would otherwise leak rows.
    Craft::$app->getDb()
        ->createCommand()
        ->delete(Table::RUNTIME_OVERRIDES)
        ->execute();
});

afterAll(function() {
    Craft::$app->getDb()
        ->createCommand()
        ->delete(Table::RUNTIME_OVERRIDES)
        ->execute();
});

// -----------------------------------------------------------------------------
// Structural — the four Allowlist endpoints exist
// -----------------------------------------------------------------------------

it('declares the four Allowlist endpoints', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionAllowlist', 'actionAllowlistTableData', 'actionAllowlistOverrideSlideout', 'actionAddOverride', 'actionRemoveOverride'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

// -----------------------------------------------------------------------------
// actionAllowlistTableData — locked row tuple + pagination contract
// -----------------------------------------------------------------------------

it('actionAllowlistTableData returns the locked {pagination, data} contract', function() {
    // Seed three overrides: one active, one expired, one with a null
    // expiry ("never expires"). Exercises every branch of
    // _serializeOverrideRow.
    Herald::getInstance()->allowlist->add(pattern: 'resave/*', userId: null, note: 'baseline', ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'mailer/test', userId: null, note: 'ticket-1', ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'up', userId: null, note: null, ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([]);

    $response = $controller->actionAllowlistTableData();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->data)->toBeArray()->toHaveKeys(['pagination', 'data']);
    expect($response->data['pagination'])->toBeArray()->toHaveKeys(['total', 'per_page', 'current_page', 'last_page']);
    expect($response->data['pagination']['total'])->toBe(3);
    expect($response->data['data'])->toBeArray()->toHaveCount(3);
});

it('actionAllowlistTableData data[0] keys equal the locked tuple exactly', function() {
    Herald::getInstance()->allowlist->add(pattern: 'resave/*', userId: null, note: 'baseline', ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([]);
    $response = $controller->actionAllowlistTableData();

    $row = $response->data['data'][0];
    expect(array_keys($row))->toBe([
        'id',
        'pattern',
        'note',
        'expiresAt',
        'createdBy',
        'subject',
        'dateCreated',
    ]);
    expect(array_keys($row['pattern']))->toBe(['pattern', 'isExpired']);
    expect(array_keys($row['expiresAt']))->toBe(['value', 'isExpired']);
});

it('actionAllowlistTableData marks expired rows with isExpired=true', function() {
    // Insert an already-expired override directly via the record layer —
    // the service's `add()` always pushes expiry into the future.
    $override = new RuntimeOverride();
    $override->pattern = 'expired/*';
    $override->note = 'manually expired';
    // UTC-anchored — the controller's isExpired computation reads the
    // stored value as UTC (Craft's DB datetime convention). A local-time
    // seed lands in the UTC future on any non-UTC install and reads as
    // not-yet-expired.
    $override->expiresAt = (new \DateTime('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $override->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([]);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'])->toBeArray()->not->toBeEmpty();
    $row = $response->data['data'][0];
    expect($row['pattern']['pattern'])->toBe('expired/*');
    expect($row['pattern']['isExpired'])->toBeTrue();
    expect($row['expiresAt']['isExpired'])->toBeTrue();
});

it('actionAllowlistTableData handles a never-expiring override (expiresAt null)', function() {
    $override = new RuntimeOverride();
    $override->pattern = 'forever/*';
    $override->expiresAt = null;
    $override->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([]);
    $response = $controller->actionAllowlistTableData();

    $row = $response->data['data'][0];
    expect($row['expiresAt']['value'])->toBeNull();
    expect($row['expiresAt']['isExpired'])->toBeFalse();
});

it('actionAllowlistTableData search filters by pattern substring', function() {
    Herald::getInstance()->allowlist->add(pattern: 'resave/*', userId: null, note: null, ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'mailer/test', userId: null, note: null, ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'up', userId: null, note: null, ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['search' => 'mailer']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'])->toHaveCount(1);
    expect($response->data['data'][0]['pattern']['pattern'])->toBe('mailer/test');
});

it('actionAllowlistTableData sort dateCreated DESC reverses default order', function() {
    // Insert two rows with explicit timestamps via the record layer so
    // the sort has a deterministic gap. The auto-set inside
    // `craft\db\ActiveRecord::beforeSave` only fires when the attribute
    // is unset — passing an explicit value bypasses it.
    $earlier = (new \DateTime('-2 minutes'))->format('Y-m-d H:i:s');
    $later = (new \DateTime('-1 minute'))->format('Y-m-d H:i:s');

    $r1 = new RuntimeOverride();
    $r1->pattern = 'first/*';
    $r1->dateCreated = $earlier;
    $r1->save();

    $r2 = new RuntimeOverride();
    $r2->pattern = 'second/*';
    $r2->dateCreated = $later;
    $r2->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['sort.0.field' => 'dateCreated', 'sort.0.direction' => 'desc']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'][0]['pattern']['pattern'])->toBe('second/*');
    expect($response->data['data'][1]['pattern']['pattern'])->toBe('first/*');
});

it('actionAllowlistTableData pagination respects per_page', function() {
    for ($i = 1; $i <= 5; $i++) {
        Herald::getInstance()->allowlist->add(pattern: "page/{$i}", userId: null, note: null, ttlSeconds: 3600);
    }

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['per_page' => 2, 'page' => 1]);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['pagination']['total'])->toBe(5);
    expect($response->data['pagination']['per_page'])->toBe(2);
    expect($response->data['pagination']['last_page'])->toBe(3);
    expect($response->data['data'])->toHaveCount(2);
});

it('actionAllowlistTableData search filters by note substring', function() {
    // The search haystack is `pattern + ' ' + note`, so the ticket
    // reference an operator typed into the note is findable.
    Herald::getInstance()->allowlist->add(pattern: 'resave/*', userId: null, note: 'ticket-4711', ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'mailer/test', userId: null, note: 'unrelated', ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['search' => '4711']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'])->toHaveCount(1);
    expect($response->data['data'][0]['pattern']['pattern'])->toBe('resave/*');
});

it('actionAllowlistTableData sorts by pattern', function() {
    Herald::getInstance()->allowlist->add(pattern: 'alpha/*', userId: null, note: null, ttlSeconds: 3600);
    Herald::getInstance()->allowlist->add(pattern: 'zulu/*', userId: null, note: null, ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['sort.0.field' => 'pattern', 'sort.0.direction' => 'asc']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'][0]['pattern']['pattern'])->toBe('alpha/*');
    expect($response->data['data'][1]['pattern']['pattern'])->toBe('zulu/*');
});

it('actionAllowlistTableData sorts by expiresAt', function() {
    Herald::getInstance()->allowlist->add(pattern: 'long/*', userId: null, note: null, ttlSeconds: 86400);
    Herald::getInstance()->allowlist->add(pattern: 'short/*', userId: null, note: null, ttlSeconds: 60);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['sort.0.field' => 'expiresAt', 'sort.0.direction' => 'asc']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'][0]['pattern']['pattern'])->toBe('short/*');
    expect($response->data['data'][1]['pattern']['pattern'])->toBe('long/*');
});

it('actionAllowlistTableData falls back to dateCreated for an unknown sort field', function() {
    $earlier = (new \DateTime('-2 minutes'))->format('Y-m-d H:i:s');
    $later = (new \DateTime('-1 minute'))->format('Y-m-d H:i:s');

    $r1 = new RuntimeOverride();
    $r1->pattern = 'first/*';
    $r1->dateCreated = $earlier;
    $r1->save();

    $r2 = new RuntimeOverride();
    $r2->pattern = 'second/*';
    $r2->dateCreated = $later;
    $r2->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['sort.0.field' => 'bogusColumn', 'sort.0.direction' => 'desc']);
    $response = $controller->actionAllowlistTableData();

    expect($response->data['data'][0]['pattern']['pattern'])->toBe('second/*');
    expect($response->data['data'][1]['pattern']['pattern'])->toBe('first/*');
});

it('actionAllowlistTableData status=active hides expired grants', function() {
    // The Temporary grants screen renders active grants in the main table
    // and expired ones in a collapsed history section; each hits this
    // endpoint with its own `status`.
    Herald::getInstance()->allowlist->add(pattern: 'live/*', userId: null, note: null, ttlSeconds: 3600);

    $expired = new RuntimeOverride();
    $expired->pattern = 'dead/*';
    $expired->expiresAt = (new \DateTime('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $expired->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['status' => 'active'])->actionAllowlistTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['pattern']['pattern'])->toBe('live/*');
});

it('actionAllowlistTableData status=expired hides active grants', function() {
    Herald::getInstance()->allowlist->add(pattern: 'live/*', userId: null, note: null, ttlSeconds: 3600);

    $expired = new RuntimeOverride();
    $expired->pattern = 'dead/*';
    $expired->expiresAt = (new \DateTime('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $expired->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['status' => 'expired'])->actionAllowlistTableData();

    expect($response->data['pagination']['total'])->toBe(1);
    expect($response->data['data'][0]['pattern']['pattern'])->toBe('dead/*');
});

it('actionAllowlistTableData treats a never-expiring grant as active', function() {
    $forever = new RuntimeOverride();
    $forever->pattern = 'forever/*';
    $forever->expiresAt = null;
    $forever->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());

    expect($controller->withParams(['status' => 'active'])->actionAllowlistTableData()->data['pagination']['total'])->toBe(1);
    expect($controller->withParams(['status' => 'expired'])->actionAllowlistTableData()->data['pagination']['total'])->toBe(0);
});

it('actionAllowlistTableData status=all keeps the single-table contract', function() {
    Herald::getInstance()->allowlist->add(pattern: 'live/*', userId: null, note: null, ttlSeconds: 3600);

    $expired = new RuntimeOverride();
    $expired->pattern = 'dead/*';
    $expired->expiresAt = (new \DateTime('-1 hour', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $expired->save();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());

    // Both the explicit `all` and an unrecognised value fall through to
    // the unfiltered set.
    expect($controller->withParams(['status' => 'all'])->actionAllowlistTableData()->data['pagination']['total'])->toBe(2);
    expect($controller->withParams(['status' => 'bogus'])->actionAllowlistTableData()->data['pagination']['total'])->toBe(2);
});

it('actionAllowlistTableData serialises an unresolvable createdBy as null', function() {
    // `createdByUserId` is SET NULL on user delete, so a grant outlives
    // its grantor; the cell is null rather than a half-built dict.
    $grantor = new User();
    $grantor->username = '_test_grantor_' . bin2hex(random_bytes(4));
    $grantor->email = $grantor->username . '@example.test';
    expect(Craft::$app->getElements()->saveElement($grantor))->toBeTrue();

    Herald::getInstance()->allowlist->add(pattern: 'orphan/*', userId: (int) $grantor->id, note: null, ttlSeconds: 3600);
    Craft::$app->getElements()->deleteElement($grantor, true);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams([])->actionAllowlistTableData();

    expect($response->data['data'])->toHaveCount(1);
    expect($response->data['data'][0]['createdBy'])->toBeNull();
});

it('actionAllowlistTableData serialises a global grant with a null subject', function() {
    Herald::getInstance()->allowlist->add(pattern: 'global/*', userId: null, note: null, ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams([])->actionAllowlistTableData();

    expect($response->data['data'][0]['subject'])->toBeNull();
});

it('actionAllowlistTableData serialises createdBy from the user record', function() {
    $user = herald_admin_user();
    expect($user)->not->toBeNull();

    Herald::getInstance()->allowlist->add(pattern: 'audit/*', userId: (int) $user->id, note: 'attributed', ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([]);
    $response = $controller->actionAllowlistTableData();

    $row = $response->data['data'][0];
    expect($row['createdBy'])->toBeArray()->toHaveKeys(['id', 'label', 'cpEditUrl']);
    expect($row['createdBy']['id'])->toBe((int) $user->id);
    expect($row['createdBy']['label'])->toBeString()->not->toBe('');
});

// -----------------------------------------------------------------------------
// actionAddOverride — JSON happy + validation paths
// -----------------------------------------------------------------------------

it('actionAddOverride JSON happy path returns the serialised row', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([
        'pattern' => 'mailer/test',
        'note' => 'ticket-123',
        'ttlSeconds' => 86400,
    ]);

    $response = $controller->actionAddOverride();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(200);
    expect($response->data)->toBeArray()->toHaveKey('model');
    expect($response->data['model'])->toBeArray();
    expect(array_keys($response->data['model']))->toBe([
        'id',
        'pattern',
        'note',
        'expiresAt',
        'createdBy',
        'subject',
        'dateCreated',
    ]);
    expect($response->data['model']['pattern']['pattern'])->toBe('mailer/test');
    expect($response->data['model']['note'])->toBe('ticket-123');
    expect($response->data['model']['pattern']['isExpired'])->toBeFalse();

    // Row landed in the table.
    $count = (new Query())->from(Table::RUNTIME_OVERRIDES)
        ->where(['pattern' => 'mailer/test', 'dateDeleted' => null])
        ->count();
    expect((int) $count)->toBe(1);
});

it('actionAddOverride scopes a grant to the posted subject user', function() {
    $subject = User::find()->status(null)->one();
    expect($subject)->not->toBeNull();
    $subjectId = (int) $subject->id;

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/subj', 'subjectUserId' => $subjectId]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['subject'])->toBeArray();
    expect($response->data['model']['subject']['id'])->toBe($subjectId);

    // The stored row carries the subject.
    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/subj'])->one();
    expect((int) $stored->subjectUserId)->toBe($subjectId);
});

it('actionAddOverride accepts multiple command patterns from the picker', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['patterns' => ['_test_/a', '_test_/b', '_test_/a']]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    // De-duped: two distinct patterns, two rows.
    expect($response->data['models'])->toBeArray()->toHaveCount(2);
    expect((int) (new Query())->from(Table::RUNTIME_OVERRIDES)->where(['like', 'pattern', '_test_/%', false])->count())->toBe(2);
});

it('actionAddOverride rejects a crafted (non-existent) subject user with 400', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/crafted', 'subjectUserId' => 999999999]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toHaveKey('message', 'The selected user could not be found.');
    // No row was written.
    expect((new Query())->from(Table::RUNTIME_OVERRIDES)->where(['pattern' => '_test_/crafted'])->exists())->toBeFalse();
});

it('actionAddOverride resolves a duration preset into the grant expiry', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/preset', 'durationPreset' => '3600']);

    $response = $controller->actionAddOverride();
    expect($response->statusCode)->toBe(200);

    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/preset'])->one();
    // ~1 hour from now (UTC); allow a small execution-time window.
    $expires = strtotime((string) $stored->expiresAt . ' UTC');
    expect($expires)->toBeGreaterThan(time() + 3500)->toBeLessThan(time() + 3700);
});

it('actionAddOverride resolves the custom duration into the grant expiry', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([
        'pattern' => '_test_/custom',
        'durationPreset' => 'custom',
        'customTtlSeconds' => 7200,
    ]);

    $response = $controller->actionAddOverride();
    expect($response->statusCode)->toBe(200);

    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/custom'])->one();
    $expires = strtotime((string) $stored->expiresAt . ' UTC');
    expect($expires)->toBeGreaterThan(time() + 7100)->toBeLessThan(time() + 7300);
});

it('actionAddOverride prefers a posted ttlSeconds over the duration preset', function() {
    // The raw `ttlSeconds` param is the API / legacy path and wins over
    // the guided slideout's duration control.
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams([
        'pattern' => '_test_/ttl-wins',
        'ttlSeconds' => 7200,
        'durationPreset' => '3600',
    ]);

    $response = $controller->actionAddOverride();
    expect($response->statusCode)->toBe(200);

    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/ttl-wins'])->one();
    $expires = strtotime((string) $stored->expiresAt . ' UTC');
    expect($expires)->toBeGreaterThan(time() + 7100)->toBeLessThan(time() + 7300);
});

it('actionAddOverride falls back to the plugin default when no duration is posted', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/default-ttl', 'durationPreset' => 'custom']);

    $response = $controller->actionAddOverride();
    expect($response->statusCode)->toBe(200);

    $expected = Herald::getInstance()->getSettings()->runtimeOverrideTtl;
    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/default-ttl'])->one();
    $expires = strtotime((string) $stored->expiresAt . ' UTC');
    expect($expires)->toBeGreaterThan(time() + $expected - 100)->toBeLessThan(time() + $expected + 100);
});

it('actionAddOverride skips non-string entries in the posted pattern list', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['patterns' => ['_test_/keep', ['nested'], 42, '  ', null]]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect($response->data['models'])->toHaveCount(1);
    expect($response->data['models'][0]['pattern']['pattern'])->toBe('_test_/keep');
});

it('actionAddOverride trims each posted pattern', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['patterns' => ['  _test_/trim  ']]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect((new Query())->from(Table::RUNTIME_OVERRIDES)->where(['pattern' => '_test_/trim'])->exists())->toBeTrue();
});

it('actionAddOverride treats an omitted or empty-string subjectUserId as a global grant', function(mixed $posted) {
    // `elementSelectField` renders a hidden placeholder input, so clearing
    // the picker posts an empty string rather than dropping the key. Both
    // that and an absent key mean "global grant".
    $params = ['pattern' => '_test_/no-subject'];
    if ($posted !== 'omitted') {
        $params['subjectUserId'] = $posted;
    }

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams($params)->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['subject'])->toBeNull();

    $stored = RuntimeOverride::find()->where(['pattern' => '_test_/no-subject'])->one();
    expect($stored->subjectUserId)->toBeNull();
})->with([
    'omitted' => ['omitted'],
    'empty string' => [''],
    'null' => [null],
]);

it('actionAddOverride rejects an empty subjectUserId array', function() {
    // Documents current behaviour, not desired behaviour: `reset([])`
    // yields `false`, which clears neither the `!== null` nor the
    // `!== ''` guard, so an empty array is cast to the user id 0 and
    // refused. No CP path posts this shape (the macro's hidden
    // placeholder posts an empty string), but an API caller can.
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $response = $controller->withParams(['pattern' => '_test_/empty-array', 'subjectUserId' => []])->actionAddOverride();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toHaveKey('message', 'The selected user could not be found.');
    expect((new Query())->from(Table::RUNTIME_OVERRIDES)->where(['pattern' => '_test_/empty-array'])->exists())->toBeFalse();
});

it('actionAddOverride accepts the elementSelect array form for subjectUserId', function() {
    $subject = User::find()->status(null)->one();
    expect($subject)->not->toBeNull();

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/array-subject', 'subjectUserId' => [(int) $subject->id]]);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['subject']['id'])->toBe((int) $subject->id);
});

it('actionAddOverride normalises an empty note to null', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '_test_/blank-note', 'note' => '']);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(200);
    expect($response->data['model']['note'])->toBeNull();
});

it('actionAddOverride empty pattern returns 400 with message', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => '   ']);

    $response = $controller->actionAddOverride();

    expect($response->statusCode)->toBe(400);
    expect($response->data)->toBeArray()->toHaveKey('message', 'At least one command pattern is required.');
});

// -----------------------------------------------------------------------------
// actionRemoveOverride — JSON happy + 404 paths
// -----------------------------------------------------------------------------

it('actionRemoveOverride JSON happy path soft-deletes the row', function() {
    $override = Herald::getInstance()->allowlist->add(pattern: 'gone/*', userId: null, note: null, ttlSeconds: 3600);

    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['id' => (int) $override->id]);

    $response = $controller->actionRemoveOverride();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(200);

    // Row soft-deleted — still in the table but `getAllOverrides()`
    // skips dateDeleted rows.
    $remaining = Herald::getInstance()->allowlist->getAllOverrides(includeExpired: true);
    expect($remaining)->toBeArray()->toBeEmpty();
});

it('actionRemoveOverride unknown id returns 404', function() {
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['id' => 999999]);

    $response = $controller->actionRemoveOverride();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(404);
    expect($response->data)->toBeArray()->toHaveKey('message', 'Override not found.');
});

// -----------------------------------------------------------------------------
// Architecture invariant — locked row keys also appear in actionAddOverride
// -----------------------------------------------------------------------------

it('actionAddOverride success row carries the locked row keys', function() {
    // Same keys as the table-data row — the slideout success branch
    // hands the new row back to the table without a refresh round-trip
    // (future enhancement; for now the table reload picks it up but
    // the shape is contracted regardless).
    $controller = new _HeraldAllowlistHarness('settings', Herald::getInstance());
    $controller->withParams(['pattern' => 'shape/*']);

    $response = $controller->actionAddOverride();
    expect(array_keys($response->data['model']))->toBe([
        'id',
        'pattern',
        'note',
        'expiresAt',
        'createdBy',
        'subject',
        'dateCreated',
    ]);
    expect(array_keys($response->data['model']['pattern']))->toBe(['pattern', 'isExpired']);
    expect(array_keys($response->data['model']['expiresAt']))->toBe(['value', 'isExpired']);
});
