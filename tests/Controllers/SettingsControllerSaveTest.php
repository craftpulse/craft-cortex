<?php

/**
 * =========================================================================
 * SettingsController::actionSave allowed-commands fold tests.
 *
 * The Gate 9 CP rework replaced the raw `allowedCommands` editable table
 * with a grouped toggle browser. `actionSave` folds three posted sources
 * — `cortexCommandGroups` (full-group globs), `cortexCommandActions`
 * (exact ids), and `settings[allowedCommands]` (custom-pattern table) —
 * back into the flat `string[]` the settings model persists, via
 * `Allowlist::patternsFromToggleState()`.
 *
 * **SEQUENTIAL ONLY.** Each test drives `actionSave`, which calls
 * `savePluginSettings()` and writes `plugins.cortex.settings.allowedCommands`
 * to project config. Running these in parallel would race the PC surface
 * other tests read. The current Pest sequential default is safe; each test
 * restores the original patterns in a `finally`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\controllers\SettingsController;
use craftpulse\cortex\Cortex;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses the HTTP plumbing
 * (`requirePostRequest`, `requireAdmin`, `redirectToPostedUrl`) so
 * `actionSave` runs its body against a console-bootstrapped Craft.
 */
class _CortexSaveHarness extends SettingsController
{
    /** @var string[] Settings to report as locked by `config/cortex.php`. */
    public array $overriddenSettings = [];

    public function requirePostRequest(): void
    {
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
    }

    /**
     * Stub the config-file override check so the read-only branch can be
     * exercised without writing a real `config/cortex.php` mid-suite.
     */
    protected function isSettingOverridden(string $attribute): bool
    {
        return in_array($attribute, $this->overriddenSettings, true);
    }

    public function redirectToPostedUrl($object = null, ?string $default = null): \yii\web\Response
    {
        return new \yii\web\Response();
    }

    /**
     * @param array<string,mixed> $body
     */
    public function withBody(array $body): self
    {
        $this->request = new _CortexSaveRequest($body);
        $this->response = new \yii\web\Response();
        return $this;
    }
}

/**
 * Minimal request stub exposing the body-param accessors `actionSave`
 * and its fold helpers call.
 */
class _CortexSaveRequest
{
    /**
     * @param array<string,mixed> $body
     */
    public function __construct(private array $body)
    {
    }

    public function getBodyParam(string $name, mixed $default = null): mixed
    {
        return $this->body[$name] ?? $default;
    }
}

/**
 * Records the flash messages `actionSave` sets so a console-bootstrapped
 * Craft (whose real session component throws) survives the call.
 */
class _CortexSaveSession
{
    public function setError(string $message): void
    {
    }

    public function setNotice(string $message): void
    {
    }
}

/**
 * `Yii::$app` proxy delegating everything but `getSession()` to the live
 * console application, so `actionSave` can set its success flash.
 */
class _CortexSaveAppProxy
{
    public function __construct(
        public \craft\console\Application $delegate,
        public _CortexSaveSession $sessionStub,
    ) {
    }

    public function getSession(): _CortexSaveSession
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

beforeEach(function() {
    $this->plugin = Cortex::getInstance();
    $this->originalPatterns = $this->plugin->getSettings()->allowedCommands;
    $this->originalAdminPatterns = $this->plugin->getSettings()->adminLevelCommands;

    $this->originalApp = \Yii::$app;
    \Yii::$app = new _CortexSaveAppProxy($this->originalApp, new _CortexSaveSession());
});

afterEach(function() {
    \Yii::$app = $this->originalApp;

    // Restore the original allowedCommands + adminLevelCommands so the PC
    // surface is left as it started — `actionSave` now folds BOTH buckets.
    // Mute events around the restore — Craft fires
    // EVENT_BEFORE_APPLY_PLUGIN_SETTINGS on every save.
    $pc = Craft::$app->getProjectConfig();
    $originalMute = $pc->muteEvents;
    $pc->muteEvents = true;
    try {
        $settings = $this->plugin->getSettings();
        $settings->allowedCommands = $this->originalPatterns;
        $settings->adminLevelCommands = $this->originalAdminPatterns;
        Craft::$app->getPlugins()->savePluginSettings($this->plugin, $settings->toArray());
    } finally {
        $pc->muteEvents = $originalMute;
    }
});

/**
 * Drive `actionSave` against a stub request carrying the posted toggle
 * payload, with the HTTP plumbing short-circuited. Returns the persisted
 * `allowedCommands` after the save.
 *
 * @param array<string,mixed> $body
 * @return string[]
 */
function _cortexRunSave(array $body): array
{
    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->withBody($body);
    $controller->actionSave();

    return Cortex::getInstance()->getSettings()->allowedCommands;
}

/**
 * As `_cortexRunSave` but returns the persisted `adminLevelCommands` —
 * the admin-level bucket the toggle browser's second section folds into.
 *
 * @param array<string,mixed> $body
 * @return string[]
 */
function _cortexRunSaveAdmin(array $body): array
{
    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->withBody($body);
    $controller->actionSave();

    return Cortex::getInstance()->getSettings()->adminLevelCommands;
}

it('folds a full-group toggle into a single group glob', function() {
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => ['resave' => '1'],
        'cortexCommandActions' => [],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('resave/*');
    // No exact resave ids leaked in alongside the glob.
    expect($patterns)->not->toContain('resave/entries');
});

it('folds individual action toggles into exact route ids', function() {
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => [],
        'cortexCommandActions' => ['resave/entries' => '1'],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('resave/entries');
    expect($patterns)->not->toContain('resave/*');
});

it('ignores off (empty-string) toggle values from the lightswitch macro', function() {
    // Craft's lightswitch posts '' for an off switch. Those must not
    // produce a `group/*` glob or an exact id.
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => ['resave' => '', 'cache' => '1'],
        'cortexCommandActions' => ['resave/entries' => ''],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('cache/*');
    expect($patterns)->not->toContain('resave/*');
    expect($patterns)->not->toContain('resave/entries');
});

it('preserves custom patterns from the editable table verbatim', function() {
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => [],
        'cortexCommandActions' => [],
        'settings' => ['allowedCommands' => [
            ['pattern' => 'resave/ent*'],
            ['pattern' => 'no-such-plugin/do-thing'],
            ['pattern' => ''],
        ]],
    ]);

    expect($patterns)->toContain('resave/ent*');
    expect($patterns)->toContain('no-such-plugin/do-thing');
    // Empty rows are dropped.
    expect($patterns)->not->toContain('');
});

it('merges all three sources on a single save', function() {
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => ['cache' => '1'],
        'cortexCommandActions' => ['resave/entries' => '1'],
        'settings' => ['allowedCommands' => [['pattern' => 'custom/glob*']]],
    ]);

    expect($patterns)->toContain('cache/*');
    expect($patterns)->toContain('resave/entries');
    expect($patterns)->toContain('custom/glob*');
});

it('folds an admin-level toggle into adminLevelCommands, never allowedCommands', function() {
    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'cortexAdminCommandGroups' => ['migrate' => '1'],
        'cortexAdminCommandActions' => [],
        'cortexCommandGroups' => [],
        'cortexCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);
    $controller->actionSave();

    $settings = Cortex::getInstance()->getSettings();
    // The security boundary: an admin route must NOT land in the
    // always-admitted content bucket.
    expect($settings->allowedCommands)->not->toContain('migrate/*');
    expect($settings->adminLevelCommands)->toContain('migrate/*');
});

it('folds a content toggle into allowedCommands, never adminLevelCommands', function() {
    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'cortexCommandGroups' => ['cache' => '1'],
        'cortexCommandActions' => [],
        'cortexAdminCommandGroups' => [],
        'cortexAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);
    $controller->actionSave();

    $settings = Cortex::getInstance()->getSettings();
    expect($settings->allowedCommands)->toContain('cache/*');
    expect($settings->adminLevelCommands)->not->toContain('cache/*');
});

it('refuses to promote an admin route posted into the content section', function() {
    // Even if the migrate group is posted under the CONTENT toggle params
    // (a forged payload), the service drops it — it never reaches the
    // always-admitted bucket.
    $patterns = _cortexRunSave([
        'cortexCommandGroups' => ['migrate' => '1'],
        'cortexCommandActions' => ['migrate/up' => '1'],
        'cortexAdminCommandGroups' => [],
        'cortexAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);

    expect($patterns)->not->toContain('migrate/*');
    expect($patterns)->not->toContain('migrate/up');
});

it('preserves admin custom patterns from the admin editable table', function() {
    $patterns = _cortexRunSaveAdmin([
        'cortexCommandGroups' => [],
        'cortexCommandActions' => [],
        'cortexAdminCommandGroups' => [],
        'cortexAdminCommandActions' => [],
        'settings' => [
            'allowedCommands' => [],
            'adminLevelCommands' => [['pattern' => 'migrate/custom*']],
        ],
    ]);

    expect($patterns)->toContain('migrate/custom*');
});

it('leaves allowedCommands untouched when locked by config/cortex.php', function() {
    $original = Cortex::getInstance()->getSettings()->allowedCommands;

    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->overriddenSettings = ['allowedCommands'];
    $controller->withBody([
        // A content toggle that WOULD change the value if not locked.
        'cortexCommandGroups' => ['cache' => '1'],
        'cortexCommandActions' => [],
        'cortexAdminCommandGroups' => [],
        'cortexAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [['pattern' => 'should/not-persist*']]],
    ]);
    $controller->actionSave();

    $after = Cortex::getInstance()->getSettings()->allowedCommands;
    expect($after)->toBe($original);
    expect($after)->not->toContain('should/not-persist*');
});

it('leaves adminLevelCommands untouched when locked by config/cortex.php', function() {
    $original = Cortex::getInstance()->getSettings()->adminLevelCommands;

    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->overriddenSettings = ['adminLevelCommands'];
    $controller->withBody([
        'cortexCommandGroups' => [],
        'cortexCommandActions' => [],
        'cortexAdminCommandGroups' => ['migrate' => '1'],
        'cortexAdminCommandActions' => [],
        'settings' => ['adminLevelCommands' => [['pattern' => 'should/not-persist*']]],
    ]);
    $controller->actionSave();

    $after = Cortex::getInstance()->getSettings()->adminLevelCommands;
    expect($after)->toBe($original);
    expect($after)->not->toContain('should/not-persist*');
});

it('content and admin custom patterns stay in their own buckets', function() {
    $controller = new _CortexSaveHarness('settings', Cortex::getInstance());
    $controller->withBody([
        'cortexCommandGroups' => [],
        'cortexCommandActions' => [],
        'cortexAdminCommandGroups' => [],
        'cortexAdminCommandActions' => [],
        'settings' => [
            'allowedCommands' => [['pattern' => 'content/glob*']],
            'adminLevelCommands' => [['pattern' => 'admin/glob*']],
        ],
    ]);
    $controller->actionSave();

    $settings = Cortex::getInstance()->getSettings();
    expect($settings->allowedCommands)->toContain('content/glob*');
    expect($settings->allowedCommands)->not->toContain('admin/glob*');
    expect($settings->adminLevelCommands)->toContain('admin/glob*');
    expect($settings->adminLevelCommands)->not->toContain('content/glob*');
});
