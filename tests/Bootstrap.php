<?php

/**
 * =========================================================================
 * Pest / PHPUnit bootstrap for Herald tests.
 *
 * Runs once before any test. Delegates the Craft boot to `boot-craft.php`
 * (shared with the content-fixtures CLI), then loads the Pest config.
 *
 * The suite runs against a *surrounding* Craft install rather than an
 * isolated fixtures install: herald tools introspect Craft's live API
 * surface, so tests assert on shape (keys, types) rather than on specific
 * records wherever they can. Where they cannot, the content-fixtures
 * harness under `tests/fixtures/` provides the named entities — see
 * `FixtureInstaller`'s docblock for the contract, and note that it is an
 * explicit step (`composer test:fixtures`), never a side effect of running
 * tests.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

require __DIR__ . '/boot-craft.php';

// Belt to the database pin's braces: no test can write project-config YAML
// into the surrounding install. `ProjectConfig::flush()` writes the *booted
// database's* config into `<install>/config/project/`, and with the database
// pinned to a test schema while the install still supplies the playground's
// `config/`, that would overwrite version-controlled YAML with test values.
// The `saveModifiedConfigData()` half of a flush still runs, so entities that
// live only in the config store are unaffected.
//
// Deliberately here rather than in `boot-craft.php`: the content-fixtures CLI
// shares that file and *must* keep writing YAML. Its entities are created
// through project config, so suppressing the write leaves the database ahead
// of the YAML, and the first time anything in a later run applies external
// changes, Craft treats the YAML as truth and deletes the whole fixture set
// out from under the suite.
Craft::$app->getProjectConfig()->writeYamlAutomatically = false;

// Pest's auto-discovery looks for `tests/Pest.php` relative to its working
// directory; under the legacy cms-root invocation that misses our config in
// herald/tests/, so we load it explicitly here so `uses()` and the custom
// `expect()` extensions register before tests run. `require_once`, not
// `require`: under the plugin-local invocation pest's own BootFiles has
// already include_once'd this file, and a plain require would fatally
// redeclare every helper function.
require_once __DIR__ . '/Pest.php';
