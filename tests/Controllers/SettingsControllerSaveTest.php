<?php

/**
 * =========================================================================
 * SettingsController::actionSave allowed-commands fold tests.
 *
 * The Gate 9 CP rework replaced the raw `allowedCommands` editable table
 * with a grouped toggle browser. `actionSave` folds three posted sources
 * — `heraldCommandGroups` (full-group globs), `heraldCommandActions`
 * (exact ids), and `settings[allowedCommands]` (custom-pattern table) —
 * back into the flat `string[]` the settings model persists, via
 * `Allowlist::patternsFromToggleState()`.
 *
 * **SEQUENTIAL ONLY.** Each test drives `actionSave`, which calls
 * `savePluginSettings()` and writes `plugins.herald.settings.allowedCommands`
 * to project config. Running these in parallel would race the PC surface
 * other tests read. The current Pest sequential default is safe; each test
 * restores the original patterns in a `finally`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\controllers\SettingsController;
use craftpulse\herald\Herald;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * SettingsController subclass that bypasses the HTTP plumbing
 * (`requirePostRequest`, `requireAdmin`, `redirectToPostedUrl`) so
 * `actionSave` runs its body against a console-bootstrapped Craft.
 */
class _HeraldSaveHarness extends SettingsController
{
    /** @var string[] Settings to report as locked by `config/herald.php`. */
    public array $overriddenSettings = [];

    public function requirePostRequest(): void
    {
    }

    public function requireAdmin(bool $requireAdminChanges = true): void
    {
    }

    public function requirePermission(string $permission): void
    {
        // no-op — actionSave now gates on the manage-settings permission;
        // the harness runs the body against a console-bootstrapped Craft
        // with no logged-in identity.
    }

    /**
     * Stub the config-file override check so the read-only branch can be
     * exercised without writing a real `config/herald.php` mid-suite.
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
        $this->request = new _HeraldSaveRequest($body);
        $this->response = new \yii\web\Response();
        return $this;
    }
}

/**
 * Minimal request stub exposing the body-param accessors `actionSave`
 * and its fold helpers call.
 */
class _HeraldSaveRequest
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
class _HeraldSaveSession
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
class _HeraldSaveAppProxy
{
    public function __construct(
        public \craft\console\Application $delegate,
        public _HeraldSaveSession $sessionStub,
    ) {
    }

    public function getSession(): _HeraldSaveSession
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
    $this->plugin = Herald::getInstance();
    $this->originalPatterns = $this->plugin->getSettings()->allowedCommands;
    $this->originalAdminPatterns = $this->plugin->getSettings()->adminLevelCommands;

    $this->originalApp = \Yii::$app;
    \Yii::$app = new _HeraldSaveAppProxy($this->originalApp, new _HeraldSaveSession());
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
function _heraldRunSave(array $body): array
{
    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->withBody($body);
    $controller->actionSave();

    return Herald::getInstance()->getSettings()->allowedCommands;
}

/**
 * As `_heraldRunSave` but returns the persisted `adminLevelCommands` —
 * the admin-level bucket the toggle browser's second section folds into.
 *
 * @param array<string,mixed> $body
 * @return string[]
 */
function _heraldRunSaveAdmin(array $body): array
{
    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->withBody($body);
    $controller->actionSave();

    return Herald::getInstance()->getSettings()->adminLevelCommands;
}

it('folds a full-group toggle into a single group glob', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => '1'],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('resave/*');
    // No exact resave ids leaked in alongside the glob.
    expect($patterns)->not->toContain('resave/entries');
});

it('folds individual action toggles into exact route ids', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => ['resave/entries' => '1'],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('resave/entries');
    expect($patterns)->not->toContain('resave/*');
});

it('ignores off (empty-string) toggle values from the lightswitch macro', function() {
    // Craft's lightswitch posts '' for an off switch. Those must not
    // produce a `group/*` glob or an exact id.
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => '', 'cache' => '1'],
        'heraldCommandActions' => ['resave/entries' => ''],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('cache/*');
    expect($patterns)->not->toContain('resave/*');
    expect($patterns)->not->toContain('resave/entries');
});

it('accepts every truthy toggle representation the macro can post', function(mixed $on) {
    // The lightswitch posts the string '1', but a JSON caller or a future
    // macro change can hand over the int or the bool. All three mean on;
    // anything else means off.
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => $on],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->toContain('resave/*');
})->with([
    "string '1'" => ['1'],
    'int 1' => [1],
    'bool true' => [true],
]);

it('treats other truthy-looking toggle values as off', function(mixed $off) {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => $off],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => []],
    ]);

    expect($patterns)->not->toContain('resave/*');
})->with([
    "string 'on'" => ['on'],
    "string 'true'" => ['true'],
    'int 2' => [2],
    'null' => [null],
    'bool false' => [false],
]);

it('ignores a non-array toggle payload', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => 'not-an-array',
        'heraldCommandActions' => 'not-an-array',
        'settings' => ['allowedCommands' => [['pattern' => 'kept/*']]],
    ]);

    expect($patterns)->toBe(['kept/*']);
});

it('ignores a non-array custom-patterns payload', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => '1'],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => 'not-an-array'],
    ]);

    expect($patterns)->toBe(['resave/*']);
});

it('ignores a non-array settings payload', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['resave' => '1'],
        'heraldCommandActions' => [],
        'settings' => 'not-an-array',
    ]);

    expect($patterns)->toBe(['resave/*']);
});

it('drops blank and whitespace-only custom patterns', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => [
            ['pattern' => '  spaced/*  '],
            ['pattern' => '   '],
            ['pattern' => ''],
            ['notPattern' => 'ignored/*'],
            'not-a-row',
        ]],
    ]);

    expect($patterns)->toBe(['spaced/*']);
});

it('preserves custom patterns from the editable table verbatim', function() {
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
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
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['cache' => '1'],
        'heraldCommandActions' => ['resave/entries' => '1'],
        'settings' => ['allowedCommands' => [['pattern' => 'custom/glob*']]],
    ]);

    expect($patterns)->toContain('cache/*');
    expect($patterns)->toContain('resave/entries');
    expect($patterns)->toContain('custom/glob*');
});

it('folds an admin-level toggle into adminLevelCommands, never allowedCommands', function() {
    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->withBody([
        'heraldAdminCommandGroups' => ['migrate' => '1'],
        'heraldAdminCommandActions' => [],
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);
    $controller->actionSave();

    $settings = Herald::getInstance()->getSettings();
    // The security boundary: an admin route must NOT land in the
    // always-admitted content bucket.
    expect($settings->allowedCommands)->not->toContain('migrate/*');
    expect($settings->adminLevelCommands)->toContain('migrate/*');
});

it('folds a content toggle into allowedCommands, never adminLevelCommands', function() {
    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->withBody([
        'heraldCommandGroups' => ['cache' => '1'],
        'heraldCommandActions' => [],
        'heraldAdminCommandGroups' => [],
        'heraldAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);
    $controller->actionSave();

    $settings = Herald::getInstance()->getSettings();
    expect($settings->allowedCommands)->toContain('cache/*');
    expect($settings->adminLevelCommands)->not->toContain('cache/*');
});

it('refuses to promote an admin route posted into the content section', function() {
    // Even if the migrate group is posted under the CONTENT toggle params
    // (a forged payload), the service drops it — it never reaches the
    // always-admitted bucket.
    $patterns = _heraldRunSave([
        'heraldCommandGroups' => ['migrate' => '1'],
        'heraldCommandActions' => ['migrate/up' => '1'],
        'heraldAdminCommandGroups' => [],
        'heraldAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [], 'adminLevelCommands' => []],
    ]);

    expect($patterns)->not->toContain('migrate/*');
    expect($patterns)->not->toContain('migrate/up');
});

it('preserves admin custom patterns from the admin editable table', function() {
    $patterns = _heraldRunSaveAdmin([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
        'heraldAdminCommandGroups' => [],
        'heraldAdminCommandActions' => [],
        'settings' => [
            'allowedCommands' => [],
            'adminLevelCommands' => [['pattern' => 'migrate/custom*']],
        ],
    ]);

    expect($patterns)->toContain('migrate/custom*');
});

it('leaves allowedCommands untouched when locked by config/herald.php', function() {
    $original = Herald::getInstance()->getSettings()->allowedCommands;

    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->overriddenSettings = ['allowedCommands'];
    $controller->withBody([
        // A content toggle that WOULD change the value if not locked.
        'heraldCommandGroups' => ['cache' => '1'],
        'heraldCommandActions' => [],
        'heraldAdminCommandGroups' => [],
        'heraldAdminCommandActions' => [],
        'settings' => ['allowedCommands' => [['pattern' => 'should/not-persist*']]],
    ]);
    $controller->actionSave();

    $after = Herald::getInstance()->getSettings()->allowedCommands;
    expect($after)->toBe($original);
    expect($after)->not->toContain('should/not-persist*');
});

it('leaves adminLevelCommands untouched when locked by config/herald.php', function() {
    $original = Herald::getInstance()->getSettings()->adminLevelCommands;

    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->overriddenSettings = ['adminLevelCommands'];
    $controller->withBody([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
        'heraldAdminCommandGroups' => ['migrate' => '1'],
        'heraldAdminCommandActions' => [],
        'settings' => ['adminLevelCommands' => [['pattern' => 'should/not-persist*']]],
    ]);
    $controller->actionSave();

    $after = Herald::getInstance()->getSettings()->adminLevelCommands;
    expect($after)->toBe($original);
    expect($after)->not->toContain('should/not-persist*');
});

it('content and admin custom patterns stay in their own buckets', function() {
    $controller = new _HeraldSaveHarness('settings', Herald::getInstance());
    $controller->withBody([
        'heraldCommandGroups' => [],
        'heraldCommandActions' => [],
        'heraldAdminCommandGroups' => [],
        'heraldAdminCommandActions' => [],
        'settings' => [
            'allowedCommands' => [['pattern' => 'content/glob*']],
            'adminLevelCommands' => [['pattern' => 'admin/glob*']],
        ],
    ]);
    $controller->actionSave();

    $settings = Herald::getInstance()->getSettings();
    expect($settings->allowedCommands)->toContain('content/glob*');
    expect($settings->allowedCommands)->not->toContain('admin/glob*');
    expect($settings->adminLevelCommands)->toContain('admin/glob*');
    expect($settings->adminLevelCommands)->not->toContain('content/glob*');
});
