<?php

/**
 * =========================================================================
 * Pest / PHPUnit bootstrap for Cortex tests.
 *
 * Runs once before any test. Boots Craft's console application against
 * the surrounding install (the playground at /var/www/html/cms when run
 * via `ddev exec` from the playground), then leaves `Craft::$app`
 * primed so tests can call `Plugin::getInstance()->tools->...` directly.
 *
 * We deliberately use the playground's existing Craft instance rather
 * than spinning up an isolated fixtures install: cortex tools introspect
 * Craft's API surface, not user data, so tests assert on shape (keys,
 * types) rather than specific records. That makes tests portable across
 * playground states without requiring a sealed fixtures dataset.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

// __DIR__ resolves through the symlink to the cortex source repo, which has
// no Craft install — so we anchor on the working directory instead. The
// canonical invocation is `ddev exec --dir=/var/www/html/cms vendor/bin/pest
// --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist`, which
// puts cwd at the playground's Craft root.
$craftBase = getcwd();

if ($craftBase === false || !file_exists($craftBase . '/craft')) {
    fwrite(STDERR, "Cortex test bootstrap could not locate Craft at cwd={$craftBase}.\n");
    fwrite(STDERR, "Run tests via:\n");
    fwrite(STDERR, "  ddev exec --dir=/var/www/html/cms vendor/bin/pest \\\n");
    fwrite(STDERR, "    --configuration=vendor/craftpulse/craft-cortex/phpunit.xml.dist\n");
    exit(1);
}

define('CRAFT_BASE_PATH', $craftBase);
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');

require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Load the playground's .env (has CRAFT_APP_ID, security key, db creds).
if (file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();
}

if (!defined('CRAFT_ENVIRONMENT')) {
    define('CRAFT_ENVIRONMENT', getenv('CRAFT_ENVIRONMENT') ?: 'dev');
}

// Boot the console application. After this, Craft::$app is the
// ConsoleApplication, plugins are registered, and Plugin::getInstance()
// resolves cortex.
require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

// Pest's auto-discovery looks for `tests/Pest.php` relative to its working
// directory; since pest runs from the playground but our config lives in
// cortex/tests/, we load it explicitly here so `uses()` and the custom
// `expect()` extensions register before tests run.
require __DIR__ . '/Pest.php';
