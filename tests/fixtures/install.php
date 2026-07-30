<?php

/**
 * =========================================================================
 * Content-fixtures CLI.
 *
 * Installs the named content Herald's test suite asserts against into the
 * Craft install `HERALD_TEST_CRAFT_BASE` points at. Run from the plugin
 * root, the same contract the Pest step uses:
 *
 *     HERALD_TEST_CRAFT_BASE=/var/www/html/cms composer test:fixtures
 *     HERALD_TEST_CRAFT_BASE=/var/www/html/cms php tests/fixtures/install.php --report
 *
 * `--report` is read-only: it prints the invariant table and exits 0 when
 * everything is satisfied, 1 when anything is missing. Without it, missing
 * entities are installed and the post-install report is printed.
 *
 * Deliberately a standalone script rather than a plugin console command:
 * `herald/fixtures/install` would ship test-only schema-writing code into
 * every production install of a plugin whose entire thesis is a minimal,
 * audited write surface.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\tests\fixtures\FixtureInstaller;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tests/fixtures/install.php is a CLI script.\n");
    exit(1);
}

require __DIR__ . '/../boot-craft.php';

$reportOnly = in_array('--report', $argv, true);

$installer = new FixtureInstaller(static function(string $line): void {
    fwrite(STDOUT, "  $line\n");
});

/**
 * Print the invariant table.
 *
 * @param array{
 *     satisfied: bool,
 *     checks: list<array{key: string, label: string, satisfied: bool, detail: string}>,
 *     missing: list<string>,
 * } $report
 */
$printReport = static function(array $report): void {
    $width = 0;
    foreach ($report['checks'] as $check) {
        $width = max($width, strlen($check['label']));
    }

    foreach ($report['checks'] as $check) {
        fwrite(STDOUT, sprintf(
            "  [%s] %s  %s\n",
            $check['satisfied'] ? 'ok  ' : 'MISS',
            str_pad($check['label'], $width),
            $check['detail'],
        ));
    }

    fwrite(STDOUT, sprintf(
        "\n  %d of %d invariants satisfied.\n",
        count($report['checks']) - count($report['missing']),
        count($report['checks']),
    ));
};

$craftRoot = Craft::getAlias('@root');
fwrite(STDOUT, "\nHerald content fixtures: " . (is_string($craftRoot) ? $craftRoot : 'unknown Craft root') . "\n\n");

$preflight = $installer->preflight();

foreach ($preflight['warnings'] as $warning) {
    fwrite(STDOUT, "  WARNING: $warning\n");
}

if ($preflight['warnings'] !== []) {
    fwrite(STDOUT, "\n");
}

if ($preflight['errors'] !== []) {
    foreach ($preflight['errors'] as $error) {
        fwrite(STDERR, "  REFUSED: $error\n");
    }
    exit(1);
}

if ($reportOnly) {
    $report = $installer->report();
    $printReport($report);
    exit($report['satisfied'] ? 0 : 1);
}

if ($installer->isSatisfied()) {
    fwrite(STDOUT, "  Already satisfied; nothing written.\n\n");
    exit(0);
}

try {
    $installer->install();
} catch (Throwable $e) {
    fwrite(STDERR, "\n  FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "\n");
$report = $installer->report();
$printReport($report);

exit($report['satisfied'] ? 0 : 1);
