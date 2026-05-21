<?php

/**
 * =========================================================================
 * SettingsController tests — error paths on the JSON mutation branches.
 *
 * Gate 9.2 swapped the legacy redirect-based add/remove flow for the
 * JSON-only contract the Allowlist tab's VueAdminTable + Garnish.Slideout
 * combo consumes. These tests verify the error-path behaviour against
 * the new contract:
 *
 *   - the action returns a Response with `asFailure()` (HTTP 400) +
 *     a `{message}` JSON body
 *   - the underlying exception is logged through `Craft::error()`
 *
 * Happy-path coverage moved to `SettingsControllerAllowlistTest`.
 * Full CP round-trip lives in the manual gate-9.2 verification step.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craft\web\Controller;
use craftpulse\cortex\controllers\SettingsController;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\services\Allowlist;
use yii\base\Exception;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Thin SettingsController subclass that bypasses HTTP plumbing —
 * `requirePostRequest`, `requireAdmin`, and `redirectToPostedUrl` all
 * short-circuit. Lets the action body run against a console-bootstrapped
 * Craft without standing up a full web request stack.
 */
class _CortexSettingsControllerHarness extends SettingsController
{
    /** @var array<string,mixed> */
    public array $body = [];

    public function requirePostRequest(): void
    {
        // no-op
    }

    public function requireAcceptsJson(): void
    {
        // no-op — test harness always opts into the JSON branch.
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
        // no-op
    }

    /**
     * Swap the inherited `$request` slot for whatever the test supplies.
     * Both action bodies call `$this->request->getRequiredBodyParam()` /
     * `getBodyParam()` — assigning a stub here is enough to bypass the
     * console request that ships with the test bootstrap. The response
     * slot is replaced with a web Response so `asJson`/`asFailure` can
     * call `setStatusCode()` (console Response lacks the method).
     */
    public function withBody(array $body): self
    {
        $this->body = $body;
        $this->request = new _CortexSettingsControllerRequest($body);
        $this->response = new \yii\web\Response();
        $this->response->formatters[\yii\web\Response::FORMAT_JSON] = \yii\web\JsonResponseFormatter::class;
        return $this;
    }
}

/**
 * Tiny request stub — just enough for the controller's two actions.
 */
class _CortexSettingsControllerRequest
{
    public bool $isCpRequest = true;

    public function __construct(private array $body)
    {
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        if (!array_key_exists($name, $this->body)) {
            throw new \yii\web\BadRequestHttpException("Missing required body param: {$name}");
        }
        return $this->body[$name];
    }

    public function getBodyParam(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $default;
    }

    public function getParam(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $default;
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

/**
 * Allowlist stub that always throws on add/remove.
 */
class _CortexThrowingAllowlist extends Allowlist
{
    public function add(
        string $pattern,
        ?int $userId = null,
        ?string $note = null,
        ?int $ttlSeconds = null,
    ): \craftpulse\cortex\records\RuntimeOverride {
        throw new Exception('boom: add failed');
    }

    public function remove(int $id): bool
    {
        throw new Exception('boom: remove failed');
    }
}

/**
 * Records `setError` / `setNotice` flashes so tests can read them
 * without standing up a full web session.
 */
class _CortexFlashSession
{
    /** @var array<string,mixed> */
    public array $flashes = [];

    public function setError(string $message): void
    {
        $this->flashes['cp-error'] = $message;
    }

    public function setNotice(string $message): void
    {
        $this->flashes['cp-notice'] = $message;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashes[$key] ?? $default;
    }
}

/**
 * Thin proxy that stands in as `Yii::$app` for the duration of an
 * action-error test. Delegates everything to the running console
 * application except `getSession()`, which returns the flash stub.
 * Replacing `Yii::$app` is the simplest way to intercept
 * `Craft::$app->getSession()` without subclassing Craft's bootstrap.
 */
class _CortexAppProxy
{
    public function __construct(
        public \craft\console\Application $delegate,
        public _CortexFlashSession $sessionStub,
    ) {
    }

    public function getSession(): _CortexFlashSession
    {
        return $this->sessionStub;
    }

    public function __get($name): mixed
    {
        return $this->delegate->$name;
    }

    public function __isset($name): bool
    {
        return isset($this->delegate->$name);
    }

    public function __call($name, $params): mixed
    {
        return $this->delegate->$name(...$params);
    }
}

// -----------------------------------------------------------------------------
// Structural
// -----------------------------------------------------------------------------

it('extends craft\\web\\Controller', function() {
    expect(is_subclass_of(SettingsController::class, Controller::class))->toBeTrue();
});

it('declares the add-override action', function() {
    $rc = new ReflectionClass(SettingsController::class);
    expect($rc->hasMethod('actionAddOverride'))->toBeTrue();
});

it('declares the remove-override action', function() {
    $rc = new ReflectionClass(SettingsController::class);
    expect($rc->hasMethod('actionRemoveOverride'))->toBeTrue();
});

it('ships the _cp/settings tab template', function() {
    // Full-render smoke happens via the curl-based browser smoke + the
    // SettingsControllerScaffoldingTest harness. This test only checks
    // the file ships at the canonical Gate-9 path and carries the
    // project-config-defaults markers. Runtime override UI lives on
    // the Allowlist tab from Gate 9.5 onward — see `_cp/allowlist.twig`.
    $path = __DIR__ . '/../../src/templates/_cp/settings.twig';
    expect(file_exists($path))->toBeTrue();
    $contents = file_get_contents($path);
    expect($contents)->toContain('Allowed commands');
    expect($contents)->not->toContain('Runtime overrides');
});

// -----------------------------------------------------------------------------
// Action error paths — allowlist add/remove throws
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Swap the Plugin's `allowlist` component for the throwing stub.
    $this->originalAllowlist = Cortex::getInstance()->allowlist;
    Cortex::getInstance()->set('allowlist', new _CortexThrowingAllowlist());

    // Swap Yii::$app for a proxy that delegates everything but
    // `getSession()` (which would otherwise throw on a console app)
    // to the live application.
    $this->flashSession = new _CortexFlashSession();
    $this->originalApp = \Yii::$app;
    \Yii::$app = new _CortexAppProxy($this->originalApp, $this->flashSession);

    // Snapshot the count of in-flight log messages so we can read just
    // the ones the action under test adds.
    $this->logCountBefore = count(Craft::getLogger()->messages);
});

afterEach(function() {
    // Restore the original Yii::$app and the plugin's allowlist.
    \Yii::$app = $this->originalApp;
    Cortex::getInstance()->set('allowlist', $this->originalAllowlist);
});

/**
 * Pull every log message added since the test started, filtered to the
 * `cortex` category.
 *
 * @return array<int,array{0:mixed,1:int,2:string,3:float}>
 */
function _cortexCapturedCortexLogs(int $countBefore): array
{
    $all = Craft::getLogger()->messages;
    $new = array_slice($all, $countBefore);
    return array_values(array_filter(
        $new,
        static fn(array $entry): bool => ($entry[2] ?? null) === 'cortex',
    ));
}

it('actionAddOverride catches allowlist exceptions, returns 400 JSON, and logs', function() {
    $controller = new _CortexSettingsControllerHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'pattern' => 'foo/*',
        'note' => null,
        'ttlSeconds' => null,
    ]);

    $response = $controller->actionAddOverride();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(400);

    expect($response->data)->toBeArray()->toHaveKey('message', 'Could not add override.');

    $cortexEntries = _cortexCapturedCortexLogs($this->logCountBefore);
    expect($cortexEntries)->not->toBeEmpty();
    $messages = array_map(static fn(array $entry): string => (string) $entry[0], $cortexEntries);
    expect(implode("\n", $messages))->toContain('boom: add failed');
});

it('actionRemoveOverride catches allowlist exceptions, returns 400 JSON, and logs', function() {
    $controller = new _CortexSettingsControllerHarness('settings', Cortex::getInstance());
    $controller->withBody(['id' => 123]);

    $response = $controller->actionRemoveOverride();

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(400);

    expect($response->data)->toBeArray()->toHaveKey('message', 'Could not remove override.');

    $cortexEntries = _cortexCapturedCortexLogs($this->logCountBefore);
    expect($cortexEntries)->not->toBeEmpty();
    $messages = array_map(static fn(array $entry): string => (string) $entry[0], $cortexEntries);
    expect(implode("\n", $messages))->toContain('boom: remove failed');
});
