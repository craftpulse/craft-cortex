<?php

/**
 * =========================================================================
 * Craft boot for out-of-tree test tooling.
 *
 * Boots Craft's console application against the *surrounding* install (the
 * playground at /var/www/html/cms when run via `ddev exec` from the
 * playground, or whatever `HERALD_TEST_CRAFT_BASE` points at) but against a
 * *dedicated test database*, then leaves `Craft::$app` primed so callers can
 * use `Herald::getInstance()->tools->…` and the `craftpulse\herald\tests\`
 * namespace directly.
 *
 * The install supplies the code, config and storage; the database is pinned
 * to `herald_fixtures` (or `HERALD_TEST_DB`) so no run can write to the
 * development schema. Together with the per-test transaction in
 * `craftpulse\herald\tests\TestCase` and the YAML-write guard in
 * `tests/Bootstrap.php`, that is what makes the suite safe to run against a
 * shared install.
 *
 * Two consumers share this file:
 *
 *   - `tests/Bootstrap.php`   — the Pest / PHPUnit bootstrap.
 *   - `tests/fixtures/install.php` — the content-fixtures CLI. It writes
 *     committed fixtures, so it targets the same pinned test database.
 *
 * The logic lives here rather than in `Bootstrap.php` because it is neither
 * short nor obvious (Craft-base resolution order, the database pins and the
 * order they have to land in, the shared-autoloader demotion that prevents a
 * `Cannot declare class Yii` fatal, the runtime PSR-4 registration that
 * stands in for an `autoload-dev` block the surrounding install's vendor
 * never receives), and two divergent copies of it would be a slow-burning
 * maintenance trap.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

// The canonical invocation is plugin-local — from the plugin directory with
// its own vendor, so pest plugins that other harnesses install into the
// shared cms vendor (e.g. craft-pest-core) can never hijack this suite:
//   ddev exec --dir=<plugin dir> "[ -d vendor ] || composer install; vendor/bin/pest"
// The surrounding Craft install is located via `HERALD_TEST_CRAFT_BASE` when
// set, then the working directory (legacy cms-root invocation), then the
// playground's in-container default.
$craftBase = getenv('HERALD_TEST_CRAFT_BASE') ?: getcwd();

if ($craftBase === false || !file_exists($craftBase . '/craft')) {
    $craftBase = '/var/www/html/cms';
}

if (!file_exists($craftBase . '/craft')) {
    fwrite(STDERR, "Herald test bootstrap could not locate a Craft install (tried HERALD_TEST_CRAFT_BASE, cwd, /var/www/html/cms).\n");
    fwrite(STDERR, "Run tests via:\n");
    fwrite(STDERR, "  ddev exec --dir=<plugin dir> \"vendor/bin/pest\"\n");
    fwrite(STDERR, "or point HERALD_TEST_CRAFT_BASE at a Craft root.\n");
    exit(1);
}

define('CRAFT_BASE_PATH', $craftBase);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

$sharedLoader = require_once CRAFT_VENDOR_PATH . '/autoload.php';

// The shared Craft install's autoloader (just loaded above) registers
// itself with `prepend: true`, so it jumps ahead of this plugin's own
// (already-registered) loader in the SPL autoload queue. If another
// symlinked plugin in the playground still requires an older Pest or
// PHPUnit, the shared copy now wins for any class not yet resolved —
// e.g. Pest lazily autoloads PHPUnit's internals mid-run, silently
// swapping in a stale `PHPUnit\Runner\ErrorHandler` and the like.
// Re-registering the identical loader object with `prepend: true` is a
// silent no-op (PHP dedupes autoload callables by identity and leaves
// the existing position alone), so the fix is to demote it: unregister,
// then re-register at the back of the queue. This plugin's own vendor
// keeps priority for anything it ships; the shared loader still serves
// as a fallback for Craft/Yii and everything else only it has.
if (is_object($sharedLoader) && method_exists($sharedLoader, 'unregister')) {
    $sharedLoader->unregister();
    $sharedLoader->register(false);
}

// =========================================================================
// Database isolation. MUST come before the .env load and the Craft boot.
//
// The surrounding install's `.env` names the *development* database (`db`
// in the DDEV playground), and DDEV additionally exports `CRAFT_DB_DATABASE`
// into the container shell, so a suite that boots that install connects to
// the live development schema and every write it makes commits there
// permanently.
//
// `craft\helpers\App::env()` reads `$_SERVER` first, then `$_ENV`, then
// `getenv()`, and PHP's CLI SAPI copies the whole container environment into
// `$_SERVER` — so PHPUnit's own `<env force="true">` entries (which only
// touch `putenv()` and `$_ENV`, see `PHPUnit\TextUI\Configuration\
// PhpHandler::handleEnvVariables()`) cannot beat DDEV's ambient value on
// their own. All three stores are pinned here, which is the half of the
// belt-and-braces that works no matter how the suite was invoked, including
// from the `tests/fixtures/install.php` CLI, which PHPUnit never touches.
//
// The pins land before `Dotenv::createUnsafeImmutable()` on purpose: an
// immutable Dotenv repository skips any name that is already set, so the
// surrounding install's `.env` cannot take the database back.
//
// Connection *coordinates* (driver, host, port, user, password) are
// deliberately NOT pinned anywhere. They differ per environment and the
// surrounding install's `.env` is their source of truth; because that load
// is immutable, anything set here would win over `.env` and break the
// coordinates on any host whose database is not reachable the same way as
// the local one (a CI runner reaching MySQL on 127.0.0.1, for one).
//
// `HERALD_TEST_DB` overrides the default database for an operator or a CI
// job that provisions its own. Keep the default in step with the
// `CRAFT_DB_DATABASE` entry in `phpunit.xml.dist`; if the two ever drift,
// the assertion after the boot below aborts the run rather than letting it
// write somewhere unexpected.
// =========================================================================
$heraldTestDatabase = getenv('HERALD_TEST_DB') ?: 'herald_fixtures';

foreach (['CRAFT_DB_DATABASE' => $heraldTestDatabase, 'CRAFT_DB_TABLE_PREFIX' => ''] as $name => $value) {
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

// Load the playground's .env (has CRAFT_APP_ID, security key, db creds).
if (file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}

if (!defined('CRAFT_ENVIRONMENT')) {
    define('CRAFT_ENVIRONMENT', getenv('CRAFT_ENVIRONMENT') ?: 'dev');
}

// Boot the console application. After this, Craft::$app is the
// ConsoleApplication, plugins are registered, and Herald::getInstance()
// resolves herald.
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// Craft's init just called `date_default_timezone_set()` with
// `system.timeZone`, which is whatever the booted install was created with
// (Craft's own install migration seeds `America/Los_Angeles`). Craft stores
// datetimes in UTC, so every DateTime a test constructs after this point
// would otherwise sit a full UTC offset away from the values it compares
// against, and expiry assertions would pass or fail on which side of the
// offset the fixture happened to land. Re-pin after app creation, not
// before: init would overwrite an earlier pin.
date_default_timezone_set('UTC');

// Fail closed. If the pins above are ever edited out, drift from
// `phpunit.xml.dist`, or get beaten by an environment nobody anticipated,
// abort before a single test runs rather than committing to the development
// database. The check asks the connection itself rather than re-reading the
// environment, so it holds regardless of how the value was resolved.
$connectedDatabase = (string)Craft::$app->getDb()->createCommand('SELECT DATABASE()')->queryScalar();

if ($connectedDatabase !== $heraldTestDatabase) {
    fwrite(STDERR, "Herald test bootstrap refused to run: Craft connected to '{$connectedDatabase}', not the test database '{$heraldTestDatabase}'.\n");
    fwrite(STDERR, "Check the CRAFT_DB_DATABASE pins in tests/boot-craft.php and phpunit.xml.dist, or set HERALD_TEST_DB.\n");
    exit(1);
}

// Install the plugin under test. Nothing else does: the surrounding install
// may or may not carry Herald, and a fresh test database certainly does not,
// in which case every test would fail on a null `Herald::getInstance()`
// rather than on the behaviour it exercises. Idempotent, and deliberately
// here rather than in a test lifecycle hook: `installPlugin()` writes project
// config, which must not happen inside the per-test transaction that
// `craftpulse\herald\tests\TestCase` rolls back.
if (Craft::$app->getIsInstalled(true) && !Craft::$app->getPlugins()->isPluginInstalled('herald')) {
    Craft::$app->getPlugins()->installPlugin('herald');
}

// Register the test-namespace PSR-4 mapping on the playground's
// autoloader so test fixtures under `tests/Tools/Fixtures/` (and the
// content-fixtures installer under `tests/fixtures/`) resolve without an
// explicit `require`. The playground's `vendor/composer/autoload_psr4.php`
// only knows about `craftpulse\herald\` → `src/`; the dev autoload from
// the plugin's own composer.json never lands in the playground's
// vendor dir, so we plumb it in here.
$composerLoader = require CRAFT_VENDOR_PATH . '/autoload.php';
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\herald\\tests\\', __DIR__ . '/');
}

// Materialise Herald's tool registry now, at the install's real edition.
//
// Herald's services are lazy Yii component definitions, so the registry
// is built on FIRST access and memoized for the rest of the process —
// and `Tools::init()` bakes in the edition it saw, because
// `shouldRegister()` gates Pro tools. If the first access happens inside
// a test that has pinned Pro (`McpControllerTest` pins it for the whole
// file), every later test in the run inherits a Pro registry, including
// the ones asserting that Pro tools are absent on Free. The suite was
// order-dependent as a result: green or red depending on which file Pest
// collected first.
//
// Touching it here makes "the registry is built at boot" literally true
// under test, matching what the dispatcher already assumes. Tests that
// need a Pro registry still opt in via `herald_with_pro_registry()`.
\craftpulse\herald\Herald::getInstance()?->tools->getCount();
