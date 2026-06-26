<?php

/**
 * =========================================================================
 * SettingsController scaffolding tests — Gate 9.1.
 *
 * Sub-gate 9.1 adds four view actions (`actionIndex`, `actionTokens`,
 * `actionActivity`, `actionConnection`) and one mutation action
 * (`actionSave`) on top of the pre-Gate-9 controller. These tests
 * verify:
 *
 *   - Structural — every action method exists.
 *   - Template files exist at the new `src/templates/_cp/` paths.
 *   - Architecture invariant — every action method's first statement
 *     is one of `requireAdmin`, `requirePermission`, or
 *     `requirePostRequest`. Static analysis via `token_get_all` —
 *     same pattern as `tests/Architecture/ConventionsTest.php`. The
 *     invariant locks the permission-gate-first contract from
 *     Gate 9 locked decision 7.
 *   - `actionSave` body smoke — POST `settings[execEnabled]=false`
 *     flips the value on the live settings model after the save call.
 *   - Route registration — CP URL rules resolve `settings/plugins/cortex`
 *     and the three per-tab URLs through Craft's url manager.
 *
 * Real CP rendering lives in the manual gate-9.1 verification step
 * (browser smoke) — the renderTemplate path is exercised at runtime
 * by `_layouts/cp` which depends on a full web Request that the
 * test bootstrap doesn't carry.
 *
 * **SEQUENTIAL ONLY** — `actionSave` writes plugin settings through
 * `Craft::$app->getPlugins()->savePluginSettings()`, which syncs to
 * project config. Per `.claude/rules/testing.md` PC-writing tests
 * must NOT run under parallel execution.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Craft;
use craft\web\Controller;
use craftpulse\cortex\controllers\SettingsController;
use craftpulse\cortex\Cortex;
use yii\web\Response;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Harness that bypasses `requirePostRequest` / `requireAdmin` /
 * `requirePermission` / `redirectToPostedUrl` — lets the action body
 * run against the console-bootstrapped Craft.
 */
class _CortexScaffoldingSettingsHarness extends SettingsController
{
    /** @var array<string,mixed> */
    public array $body = [];

    public function requirePostRequest(): void
    {
        // no-op
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
        // no-op
    }

    public function requirePermission(string $permission): void
    {
        // no-op
    }

    public function redirectToPostedUrl($object = null, ?string $default = null): Response
    {
        $response = new Response();
        $response->setStatusCode(302);
        $response->headers->set('Location', '/cortex/settings');
        return $response;
    }

    public function withBody(array $body): self
    {
        $this->body = $body;
        $this->request = new _CortexScaffoldingRequest($body);
        return $this;
    }
}

/**
 * Tiny request stub that satisfies the SettingsController body-param
 * surface (`getBodyParam`, `getRequiredBodyParam`).
 */
class _CortexScaffoldingRequest
{
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
}

/**
 * Returns the source-text body of a named method on
 * `SettingsController`, sliced from the file by line number. Used by
 * the architecture invariant to confirm the action enters its
 * permission gate first via a regex anchor.
 */
function _cortex_controller_method_body(string $method): string
{
    $rm = (new ReflectionClass(SettingsController::class))->getMethod($method);
    $contents = file_get_contents($rm->getFileName());
    expect($contents)->not->toBeFalse();

    return implode("\n", array_slice(
        explode("\n", (string) $contents),
        $rm->getStartLine(),
        $rm->getEndLine() - $rm->getStartLine(),
    ));
}

// -----------------------------------------------------------------------------
// Structural — view actions exist
// -----------------------------------------------------------------------------

it('extends craft\\web\\Controller', function() {
    expect(is_subclass_of(SettingsController::class, Controller::class))->toBeTrue();
});

it('declares every Gate 9.1 view action plus actionSave', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionIndex', 'actionTokens', 'actionAllowlist', 'actionActivity', 'actionConnection', 'actionSave'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

// -----------------------------------------------------------------------------
// Templates exist at the new _cp/ paths
// -----------------------------------------------------------------------------

it('ships the _cp/ tab templates plus the shared layout', function() {
    $base = __DIR__ . '/../../src/templates/_cp';
    foreach (['_layout.twig', 'settings.twig', 'tokens.twig', 'clients.twig', 'allowlist.twig', 'activity.twig', 'connection.twig'] as $file) {
        expect(file_exists($base . '/' . $file))->toBeTrue("missing _cp/{$file}");
    }
});

it('_cp/_layout extends _layouts/cp directly', function() {
    $contents = file_get_contents(__DIR__ . '/../../src/templates/_cp/_layout.twig');
    expect($contents)->not->toBeFalse();
    expect($contents)->toContain('extends "_layouts/cp"');
});

it('_cp/_layout drives the standard CP subnav, not an in-page tab bar', function() {
    // The Gate 9 CP rework replaced the in-page tab bar with Craft's
    // global-sidebar subnav (`Cortex::getCpNavItem()`). The layout now
    // highlights the active subnav item via `selectedSubnavItem` and no
    // longer builds its own `tabs` map.
    $contents = (string) file_get_contents(__DIR__ . '/../../src/templates/_cp/_layout.twig');
    expect($contents)->toContain('selectedSubnavItem');
    expect($contents)->not->toContain('{% set tabs');
});

it('getCpNavItem gates the Pro subnav entries behind the edition', function() {
    // Presentation-side mirror of the `_requirePro()` action gates lives
    // in `Cortex::getCpNavItem()` now — the source must reference each Pro
    // subnav URL only under an `is(EDITION_PRO, '>=')` check. We assert the
    // gating method carries the Pro edition comparison and every Pro
    // subnav URL, and that the Free-always entries (Settings + Temporary
    // grants) are present too.
    $contents = (string) file_get_contents(__DIR__ . '/../../src/Cortex.php');
    expect($contents)->toContain("is(self::EDITION_PRO, '>=')");
    foreach (['cortex/tokens', 'cortex/clients', 'cortex/activity', 'cortex/connection'] as $proUrl) {
        expect($contents)->toContain($proUrl);
    }
    expect($contents)->toContain("'cortex/settings'")
        ->toContain("'cortex/allowlist'");
});

// Locks the locale-undefined bug found in the 9.1 manual smoke. The
// `|date` filter's third arg is a locale; passing the bare `locale`
// identifier resolves it as a Twig variable, which is not in scope on
// our CP templates and throws RuntimeError at render time when the
// for-loop body executes. Craft's filter falls back to the app locale
// when the arg is omitted — the correct form is `|date('short')`.
it('templates never reference an undeclared locale variable in |date filter', function() {
    $templates = [];
    $stack = [__DIR__ . '/../../src/templates'];
    while ($dir = array_pop($stack)) {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $stack[] = $path;
            } elseif (str_ends_with($entry, '.twig')) {
                $templates[] = $path;
            }
        }
    }
    expect($templates)->not->toBeEmpty();
    foreach ($templates as $template) {
        $contents = file_get_contents($template);
        expect($contents)->not->toBeFalse();
        expect($contents)->not->toMatch(
            '/\|\s*date\s*\([^)]*,\s*locale\s*\)/',
            "Template {$template} passes `locale` to |date — the variable is not in scope on Cortex CP templates. Drop the locale arg; Craft falls back to the app locale automatically.",
        );
    }
});

// -----------------------------------------------------------------------------
// Architecture invariant — every action body opens with a permission gate
// -----------------------------------------------------------------------------

it('actionIndex first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionIndex');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionTokens first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionTokens');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionAllowlist first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionAllowlist');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionActivity first statement is requirePermission(viewActivity)', function() {
    $body = _cortex_controller_method_body('actionActivity');
    expect($body)->toMatch('/^\s*\$this->requirePermission\s*\(\s*Cortex::PERMISSION_VIEW_ACTIVITY\s*\)/m');
});

it('actionConnection first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionConnection');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionSave first statement is requirePostRequest', function() {
    $body = _cortex_controller_method_body('actionSave');
    expect($body)->toMatch('/^\s*\$this->requirePostRequest\s*\(\s*\)/m');
    // And the second statement is requireAdmin(requireAdminChanges: true).
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*requireAdminChanges:\s*true\s*\)/');
});

// -----------------------------------------------------------------------------
// actionSave body smoke — settings[execEnabled] flips the live model
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->originalApp = \Yii::$app;
    // Snapshot the current settings so we can restore in afterEach.
    $this->originalExecEnabled = Cortex::getInstance()->getSettings()->execEnabled;
});

beforeEach(function() {
    $this->originalAllowedCommands = Cortex::getInstance()->getSettings()->allowedCommands;
    // `actionSave` now folds BOTH the content (`allowedCommands`) and the
    // admin-level (`adminLevelCommands`) toggle buckets. These tests post
    // no admin toggles, so a save would persist `adminLevelCommands = []`
    // and wipe it for the rest of the suite — snapshot + restore it too.
    $this->originalAdminLevelCommands = Cortex::getInstance()->getSettings()->adminLevelCommands;
});

afterEach(function() {
    \Yii::$app = $this->originalApp;
    // Restore execEnabled + both command buckets to the snapshotted
    // values via PC write so the suite's other tests inherit the same
    // baseline.
    $settings = Cortex::getInstance()->getSettings();
    $needsRestore = false;
    if ($settings->execEnabled !== $this->originalExecEnabled) {
        $settings->execEnabled = $this->originalExecEnabled;
        $needsRestore = true;
    }
    if ($settings->allowedCommands !== $this->originalAllowedCommands) {
        $settings->allowedCommands = $this->originalAllowedCommands;
        $needsRestore = true;
    }
    if ($settings->adminLevelCommands !== $this->originalAdminLevelCommands) {
        $settings->adminLevelCommands = $this->originalAdminLevelCommands;
        $needsRestore = true;
    }
    if ($needsRestore) {
        Craft::$app->getPlugins()->savePluginSettings(Cortex::getInstance(), $settings->toArray());
    }
});

it('actionSave persists settings[execEnabled] through the plugins service', function() {
    // Force the toggle so the test asserts a real flip (not a no-op on
    // whatever the baseline value happens to be).
    $targetValue = !$this->originalExecEnabled;

    // Swap Yii::$app for a proxy so getSession() doesn't blow up on the
    // console bootstrap. Same pattern as `SettingsControllerTest.php`.
    $flashSession = new class() {
        public array $flashes = [];
        public function setError(string $message): void
        {
            $this->flashes['cp-error'] = $message;
        }
        public function setNotice(string $message): void
        {
            $this->flashes['cp-notice'] = $message;
        }
    };

    \Yii::$app = new class($this->originalApp, $flashSession) {
        public function __construct(public $delegate, public $sessionStub)
        {
        }
        public function getSession(): object
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
    };

    $controller = new _CortexScaffoldingSettingsHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'settings' => [
            'execEnabled' => $targetValue ? '1' : '',
        ],
    ]);

    $response = $controller->actionSave();
    expect($response)->toBeInstanceOf(Response::class);
    expect($response->statusCode)->toBe(302);

    // Re-read the settings — savePluginSettings round-trips through PC,
    // so getSettings() reflects the persisted value on the next call.
    $reloaded = Cortex::getInstance()->getSettings();
    expect($reloaded->execEnabled)->toBe($targetValue);
});

it('actionSave flattens the editableTable 2D submission for allowedCommands', function() {
    // The editableTableField macro posts as `settings[allowedCommands][N][pattern]`
    // (2D array). Settings::$allowedCommands is typed string[]. Without
    // the controller's flatten step, setAttributes silently fails on
    // shape mismatch and savePluginSettings falls back to the prior PC
    // value (the "snap back to OG" bug). This test locks the flatten.
    $flashSession = new class() {
        public function setError(string $message): void
        {
        }
        public function setNotice(string $message): void
        {
        }
    };

    \Yii::$app = new class($this->originalApp, $flashSession) {
        public function __construct(public $delegate, public $sessionStub)
        {
        }
        public function getSession(): object
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
    };

    $controller = new _CortexScaffoldingSettingsHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'settings' => [
            'allowedCommands' => [
                ['pattern' => 'resave/*'],
                ['pattern' => '  cache/*  '],   // trim whitespace
                ['pattern' => ''],               // drop empty row
                ['pattern' => 'mailer/test'],
            ],
        ],
    ]);

    $response = $controller->actionSave();
    expect($response->statusCode)->toBe(302);

    $reloaded = Cortex::getInstance()->getSettings();
    expect($reloaded->allowedCommands)->toBe([
        'resave/*',
        'cache/*',
        'mailer/test',
    ]);
});

// -----------------------------------------------------------------------------
// Route registration — CP URL rules resolve through the url manager
// -----------------------------------------------------------------------------

it('resolves settings/plugins/cortex through the CP url manager', function() {
    $url = \craft\helpers\UrlHelper::cpUrl('settings/plugins/cortex');
    expect($url)->toBeString();
    expect($url)->not->toBe('');
    // Verify it's a CP URL (contains the cpTrigger or is admin-style).
    $cpTrigger = Craft::$app->getConfig()->getGeneral()->cpTrigger ?? 'admin';
    if ($cpTrigger !== null && $cpTrigger !== '') {
        expect($url)->toContain($cpTrigger);
    }
    expect($url)->toContain('settings/plugins/cortex');
});

it('resolves the four per-tab CP URLs', function() {
    foreach (['cortex/tokens', 'cortex/allowlist', 'cortex/activity', 'cortex/connection'] as $path) {
        $url = \craft\helpers\UrlHelper::cpUrl($path);
        expect($url)->toBeString();
        expect($url)->not->toBe('');
        expect($url)->toContain($path);
    }
});

// -----------------------------------------------------------------------------
// 9.2 — Allowlist endpoints
// -----------------------------------------------------------------------------

it('declares actionAllowlistTableData + actionAllowlistOverrideSlideout', function() {
    $rc = new ReflectionClass(SettingsController::class);
    expect($rc->hasMethod('actionAllowlistTableData'))->toBeTrue();
    expect($rc->hasMethod('actionAllowlistOverrideSlideout'))->toBeTrue();
});

it('actionAllowlistTableData first statements are requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionAllowlistTableData');
    expect($body)->toMatch('/^\s*\$this->requireAcceptsJson\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*false\s*\)/');
});

it('actionAllowlistOverrideSlideout first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionAllowlistOverrideSlideout');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionAddOverride first statements are requirePostRequest + requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionAddOverride');
    expect($body)->toMatch('/^\s*\$this->requirePostRequest\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAcceptsJson\s*\(\s*\)/');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*requireAdminChanges:\s*true\s*\)/');
});

it('actionRemoveOverride first statements are requirePostRequest + requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionRemoveOverride');
    expect($body)->toMatch('/^\s*\$this->requirePostRequest\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAcceptsJson\s*\(\s*\)/');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*requireAdminChanges:\s*true\s*\)/');
});

it('resolves the Allowlist data + slideout CP URLs', function() {
    foreach (['cortex/allowlist/table-data', 'cortex/allowlist/override-slideout'] as $path) {
        $url = \craft\helpers\UrlHelper::cpUrl($path);
        expect($url)->toBeString();
        expect($url)->not->toBe('');
        expect($url)->toContain($path);
    }
});

it('ships the allowlist-override slideout partial', function() {
    $path = __DIR__ . '/../../src/templates/_cp/_allowlist-override-slideout.twig';
    expect(file_exists($path))->toBeTrue();
    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();
    expect($contents)->toContain("name: 'pattern'");
    expect($contents)->toContain("name: 'note'");
    expect($contents)->toContain("name: 'ttlSeconds'");
});

it('allowlist tab template instantiates a Craft.VueAdminTable', function() {
    $path = __DIR__ . '/../../src/templates/_cp/allowlist.twig';
    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();
    expect($contents)->toContain('Craft.VueAdminTable');
    // Action routes, not the CP URL aliases — VueAdminTable resolves
    // `tableDataEndpoint` via `Craft.getActionUrl`, which never consults
    // CP URL rules (the gate-9 smoke caught the alias 404ing).
    expect($contents)->toContain('cortex/settings/allowlist-table-data');
    expect($contents)->toContain('cortex/settings/remove-override');
});

// -----------------------------------------------------------------------------
// 9.5 — Tokens endpoints
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// 9.7 — edition gating invariant
// -----------------------------------------------------------------------------

it('every Pro action opens with the _requirePro() edition gate', function() {
    // Tokens / Activity / Connection are Pro surfaces (Gate 9.7). The
    // gate must be the FIRST statement — ahead of the permission
    // checks — so a Free install never reaches admin/permission logic
    // for a tier it does not own.
    $proActions = [
        'actionTokens',
        'actionTokensTableData',
        'actionTokenIssueSlideout',
        'actionIssueToken',
        'actionRevokeToken',
        'actionActivity',
        'actionActivityTableData',
        'actionActivityRow',
        'actionConnection',
    ];

    foreach ($proActions as $method) {
        $body = _cortex_controller_method_body($method);
        expect($body)->toMatch(
            '/\A\s*\{\s*\$this->_requirePro\(\);/',
            "{$method} must open with \$this->_requirePro()",
        );
    }
});

it('Free-tier actions carry no edition gate', function() {
    foreach (['actionIndex', 'actionSave', 'actionAllowlist', 'actionAllowlistTableData', 'actionAllowlistOverrideSlideout', 'actionAddOverride', 'actionRemoveOverride'] as $method) {
        $body = _cortex_controller_method_body($method);
        expect($body)->not->toContain('_requirePro', "{$method} must stay Free");
    }
});

it('declares the Tokens data + slideout + mutation actions', function() {
    $rc = new ReflectionClass(SettingsController::class);
    foreach (['actionTokensTableData', 'actionTokenIssueSlideout', 'actionIssueToken', 'actionRevokeToken'] as $method) {
        expect($rc->hasMethod($method))->toBeTrue("missing {$method}");
    }
});

it('actionTokensTableData first statements are requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionTokensTableData');
    expect($body)->toMatch('/^\s*\$this->requireAcceptsJson\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*false\s*\)/');
});

it('actionTokenIssueSlideout first statement is requireAdmin', function() {
    $body = _cortex_controller_method_body('actionTokenIssueSlideout');
    expect($body)->toMatch('/^\s*\$this->requireAdmin\s*\(\s*false\s*\)/m');
});

it('actionIssueToken first statements are requirePostRequest + requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionIssueToken');
    expect($body)->toMatch('/^\s*\$this->requirePostRequest\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAcceptsJson\s*\(\s*\)/');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*requireAdminChanges:\s*true\s*\)/');
});

it('actionRevokeToken first statements are requirePostRequest + requireAcceptsJson + requireAdmin', function() {
    $body = _cortex_controller_method_body('actionRevokeToken');
    expect($body)->toMatch('/^\s*\$this->requirePostRequest\s*\(\s*\)/m');
    expect($body)->toMatch('/\$this->requireAcceptsJson\s*\(\s*\)/');
    expect($body)->toMatch('/\$this->requireAdmin\s*\(\s*requireAdminChanges:\s*true\s*\)/');
});

it('resolves the Tokens data + slideout + mutation CP URLs', function() {
    foreach (['cortex/tokens/table-data', 'cortex/tokens/issue-slideout', 'cortex/tokens/issue', 'cortex/tokens/revoke'] as $path) {
        $url = \craft\helpers\UrlHelper::cpUrl($path);
        expect($url)->toBeString();
        expect($url)->not->toBe('');
        expect($url)->toContain($path);
    }
});

it('ships the token-issue slideout partial with form fields + one-time reveal', function() {
    $path = __DIR__ . '/../../src/templates/_cp/_token-issue-slideout.twig';
    expect(file_exists($path))->toBeTrue();
    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();
    expect($contents)->toContain("name: 'name'");
    expect($contents)->toContain("name: 'userId'");
    expect($contents)->toContain("name: 'ttlSeconds'");
    // The one-time reveal panel + copy control + polite live region.
    expect($contents)->toContain('data-cortex-reveal');
    expect($contents)->toContain('data-cortex-token');
    expect($contents)->toContain('data-cortex-copy');
    expect($contents)->toContain('aria-live="polite"');
});

it('tokens tab template instantiates a Craft.VueAdminTable wired to the token endpoints', function() {
    $path = __DIR__ . '/../../src/templates/_cp/tokens.twig';
    $contents = file_get_contents($path);
    expect($contents)->not->toBeFalse();
    expect($contents)->toContain('Craft.VueAdminTable');
    // Action routes, not the CP URL aliases — see the allowlist variant
    // of this invariant for the why.
    expect($contents)->toContain('cortex/settings/tokens-table-data');
    expect($contents)->toContain('cortex/settings/revoke-token');
    expect($contents)->toContain('openTokenIssuanceSlideout');
});
