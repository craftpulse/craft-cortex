<?php

/**
 * =========================================================================
 * Pest / PHPUnit bootstrap for Herald tests.
 *
 * Runs once before any test. Boots Craft's console application against
 * the surrounding install (the playground at /var/www/html/cms when run
 * via `ddev exec` from the playground), then leaves `Craft::$app`
 * primed so tests can call `Herald::getInstance()->tools->...` directly.
 *
 * We deliberately use the playground's existing Craft instance rather
 * than spinning up an isolated fixtures install: herald tools introspect
 * Craft's API surface, not user data, so tests assert on shape (keys,
 * types) rather than specific records. That makes tests portable across
 * playground states without requiring a sealed fixtures dataset.
 * =========================================================================
 *
 * @author Craftpulse
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

// Register the test-namespace PSR-4 mapping on the playground's
// autoloader so test fixtures under `tests/Tools/Fixtures/` (and any
// other namespaced test helpers we add later) resolve without an
// explicit `require`. The playground's `vendor/composer/autoload_psr4.php`
// only knows about `craftpulse\herald\` → `src/`; the dev autoload from
// the plugin's own composer.json never lands in the playground's
// vendor dir, so we plumb it in here.
$composerLoader = require CRAFT_VENDOR_PATH . '/autoload.php';
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\herald\\tests\\', __DIR__ . '/');
}

// Pest's auto-discovery looks for `tests/Pest.php` relative to its working
// directory; under the legacy cms-root invocation that misses our config in
// herald/tests/, so we load it explicitly here so `uses()` and the custom
// `expect()` extensions register before tests run. `require_once`, not
// `require`: under the plugin-local invocation pest's own BootFiles has
// already include_once'd this file, and a plain require would fatally
// redeclare every helper function.
require_once __DIR__ . '/Pest.php';
