<?php

/**
 * =========================================================================
 * OauthController (console) tests — `herald/oauth/init-keys`.
 *
 * The test environment already has a key pair from earlier dev runs,
 * so the test relocates the keys directory to a unique temporary
 * subpath, runs against that, and cleans up after. Mirrors the
 * pattern in TokenControllerTest.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\console\controllers\OauthController;
use craftpulse\herald\Herald;
use craftpulse\herald\services\Oauth;

// -----------------------------------------------------------------------------
// Harness — captures stdout / stderr so assertions can introspect.
// -----------------------------------------------------------------------------

class _HeraldOauthInitKeysHarness extends OauthController
{
    public string $captured = '';

    public function stdout($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }

    public function stderr($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }
}

/**
 * Build the harness with the canonical keys path swapped for a
 * test-owned directory so we can assert on `init-keys` without
 * touching the playground's real key pair. The `Oauth` service
 * pulls the path from `Craft::$app->getPath()->getStoragePath()`,
 * so swapping at that level is the cleanest seam.
 *
 * Returns `[harness, real-test-dir]`. Caller is responsible for
 * cleaning up the test dir.
 *
 * @return array{0: _HeraldOauthInitKeysHarness, 1: string}
 */
function _herald_init_keys_harness(): array
{
    $testRoot = sys_get_temp_dir() . '/herald-test-' . bin2hex(random_bytes(6));
    $keysDir = $testRoot . '/' . Oauth::KEYS_SUBDIR;

    // Reflectively swap the storage path on Craft's `path` component.
    // The path component is mutable; we restore the original in the
    // test's `afterEach`.
    $pathSvc = Craft::$app->getPath();
    $rc = new ReflectionClass($pathSvc);
    $prop = $rc->getProperty('_storagePath');
    $prop->setAccessible(true);
    $prop->setValue($pathSvc, $testRoot);

    $controller = new _HeraldOauthInitKeysHarness('oauth', Herald::getInstance());
    return [$controller, $keysDir];
}

/**
 * Recursively remove a temp directory and restore the original
 * storage path. Idempotent on a missing dir.
 */
function _herald_init_keys_cleanup(string $testKeysDir): void
{
    $root = dirname(dirname($testKeysDir));
    if (is_dir($root)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($root);
    }
}

beforeEach(function() {
    // Force lazy initialization of the typed `_storagePath` slot before we
    // snapshot it. Craft sets it on first `getStoragePath()` access; test
    // execution order (Pest runs prior defects first) doesn't guarantee an
    // earlier test already triggered it, and reading an uninitialized typed
    // property throws.
    Craft::$app->getPath()->getStoragePath();

    // Snapshot the storage path so we can restore it after the test
    // mutates Craft's path component.
    $rc = new ReflectionClass(Craft::$app->getPath());
    $prop = $rc->getProperty('_storagePath');
    $prop->setAccessible(true);
    $this->originalStoragePath = $prop->getValue(Craft::$app->getPath());
});

afterEach(function() {
    // Restore the storage path slot.
    $rc = new ReflectionClass(Craft::$app->getPath());
    $prop = $rc->getProperty('_storagePath');
    $prop->setAccessible(true);
    $prop->setValue(Craft::$app->getPath(), $this->originalStoragePath);
});

// -----------------------------------------------------------------------------
// init-keys
// -----------------------------------------------------------------------------

it('init-keys generates a private + public key in the configured directory', function() {
    [$controller, $keysDir] = _herald_init_keys_harness();

    try {
        $exit = $controller->actionInitKeys();
        expect($exit)->toBe(0);
        expect($controller->captured)->toContain('OAuth key pair generated');

        $privatePath = $keysDir . '/' . Oauth::PRIVATE_KEY_FILE;
        $publicPath = $keysDir . '/' . Oauth::PUBLIC_KEY_FILE;

        expect(is_file($privatePath))->toBeTrue();
        expect(is_file($publicPath))->toBeTrue();

        // Private key contents look like a PEM-encoded RSA key.
        expect(file_get_contents($privatePath))->toContain('-----BEGIN');
        expect(file_get_contents($publicPath))->toContain('-----BEGIN PUBLIC KEY-----');
    } finally {
        _herald_init_keys_cleanup($keysDir);
    }
});

it('init-keys sets 0600 permissions on the private key', function() {
    [$controller, $keysDir] = _herald_init_keys_harness();

    try {
        $controller->actionInitKeys();

        $privatePath = $keysDir . '/' . Oauth::PRIVATE_KEY_FILE;
        $perms = fileperms($privatePath) & 0o777;
        expect($perms)->toBe(0o600);
    } finally {
        _herald_init_keys_cleanup($keysDir);
    }
});

it('init-keys refuses to overwrite an existing key pair without --force', function() {
    [$controller, $keysDir] = _herald_init_keys_harness();

    try {
        $first = $controller->actionInitKeys();
        expect($first)->toBe(0);

        // Second invocation without --force fails.
        $controller2 = new _HeraldOauthInitKeysHarness('oauth', Herald::getInstance());
        $second = $controller2->actionInitKeys();
        expect($second)->not->toBe(0);
        expect($controller2->captured)->toContain('already exist');
        expect($controller2->captured)->toContain('--force');
    } finally {
        _herald_init_keys_cleanup($keysDir);
    }
});

it('init-keys with --force overwrites an existing key pair', function() {
    [$controller, $keysDir] = _herald_init_keys_harness();

    try {
        $controller->actionInitKeys();

        $privatePath = $keysDir . '/' . Oauth::PRIVATE_KEY_FILE;
        $original = file_get_contents($privatePath);
        // Small sleep so the second key generation produces different
        // entropy — not strictly necessary because PKEY generation is
        // CSPRNG-driven but defensive.
        usleep(1000);

        $controller2 = new _HeraldOauthInitKeysHarness('oauth', Herald::getInstance());
        $controller2->force = true;
        $exit = $controller2->actionInitKeys();
        expect($exit)->toBe(0);

        $rotated = file_get_contents($privatePath);
        expect($rotated)->not->toBe($original);
    } finally {
        _herald_init_keys_cleanup($keysDir);
    }
});
