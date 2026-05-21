<?php

/**
 * =========================================================================
 * Pest configuration for Cortex.
 *
 * Wires up:
 *   - The base TestCase used by every test (PHPUnit\Framework\TestCase by
 *     default; switch later if we adopt Codeception). The bootstrap has
 *     already booted Craft, so tests can rely on Craft::$app being set.
 *   - `expect()` extensions — `toBeMcpToolListItem`, `toBeMcpToolErrorEnvelope`,
 *     etc. Keeps assertions readable in tests.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use PHPUnit\Framework\TestCase;

uses(TestCase::class)->in(__DIR__);

// -----------------------------------------------------------------------------
// Custom expectations
// -----------------------------------------------------------------------------

/**
 * Assert the value looks like an MCP tools/list item.
 */
expect()->extend('toBeMcpToolListItem', function() {
    return $this
        ->toBeArray()
        ->toHaveKeys(['name', 'description', 'inputSchema'])
        ->and($this->value['name'])->toBeString()->not->toBeEmpty()
        ->and($this->value['description'])->toBeString()->not->toBeEmpty()
        ->and($this->value['inputSchema'])->toBeArray()->toHaveKey('type', 'object');
});

/**
 * Assert the value looks like an MCP tool result envelope (success).
 */
expect()->extend('toBeMcpSuccessEnvelope', function() {
    return $this
        ->toBeArray()
        ->toHaveKey('content')
        ->and($this->value['content'])->toBeArray()->not->toBeEmpty()
        ->and($this->value['content'][0])->toHaveKey('type', 'text')
        ->and($this->value)->toHaveKey('isError', false);
});

/**
 * Assert the value looks like an MCP tool error envelope.
 */
expect()->extend('toBeMcpErrorEnvelope', function() {
    return $this
        ->toBeArray()
        ->toHaveKey('isError', true)
        ->toHaveKey('content')
        ->and($this->value['content'])->toBeArray()->not->toBeEmpty()
        ->and($this->value['content'][0])->toHaveKey('type', 'text');
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Decode the JSON payload out of a tools/call success envelope so tests
 * can assert on the structured tool output without re-implementing the
 * unwrapping each time.
 *
 * @param array<string,mixed> $envelope
 * @return array<int|string,mixed>
 */
function cortex_unwrap(array $envelope): array
{
    expect($envelope)->toBeMcpSuccessEnvelope();

    $text = $envelope['content'][0]['text'];
    expect($text)->toBeString();

    $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * Run a callable with `Cortex::getInstance()->edition` temporarily set
 * to the given handle, then restore both the original edition and the
 * project-config `muteEvents` flag in a `finally` block so a thrown
 * exception inside the callback can't leave the plugin in a Pro state
 * for a subsequent test.
 *
 * Mutates `Cortex::getInstance()->edition` directly — Craft owns the
 * project-config storage for the edition handle and would otherwise
 * fire `EVENT_BEFORE_APPLY_PLUGIN_SETTINGS` on every assignment, so
 * the mute is required around the flip to prevent listener re-entry
 * during a test run.
 *
 * Used by every Gate 8 sub-gate from 8.1 onwards — the foundation for
 * boundary tests that need to flip between Free and Pro behaviour
 * inside a single test file.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function cortex_with_edition(string $edition, callable $fn): mixed
{
    $plugin = Cortex::getInstance();
    $original = $plugin->edition;
    $projectConfig = Craft::$app->getProjectConfig();
    $originalMute = $projectConfig->muteEvents;

    $projectConfig->muteEvents = true;
    $plugin->edition = $edition;

    try {
        return $fn();
    } finally {
        $plugin->edition = $original;
        $projectConfig->muteEvents = $originalMute;
    }
}

/**
 * Run a callable on a Pro-edition install with the tool registry
 * rebuilt so Pro tools resolve through `Tools::getByNameFor()` /
 * `Server::dispatch()`. Wraps `cortex_with_edition()` because flipping
 * `$plugin->edition` alone is not enough: the registry was built at
 * boot via `shouldRegister()` and does not auto-rebuild on edition
 * change. Production never sees a mid-process edition flip; this
 * helper is test-only.
 *
 * Use for boundary / integration tests that need the dispatcher to
 * route to Pro tools. For per-tool tests, `cortex_with_edition()` +
 * direct instantiation is cheaper and avoids the rebuild cost.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function cortex_with_pro_registry(callable $fn): mixed
{
    return cortex_with_edition(Cortex::EDITION_PRO, function() use ($fn) {
        $original = Cortex::getInstance()->tools;
        $fresh = new \craftpulse\cortex\services\Tools();
        $fresh->init();
        Cortex::getInstance()->set('tools', $fresh);

        try {
            return $fn();
        } finally {
            Cortex::getInstance()->set('tools', $original);
        }
    });
}

/**
 * Run a callable with
 * `Craft::$app->getConfig()->getGeneral()->allowAdminChanges` set to
 * the given value, then restore the original in a `finally` block.
 *
 * `allowAdminChanges` is read at dispatch time by both
 * `Allowlist::getEffective()` (for the `craft_command` tool's
 * effective allowlist) and `CraftCommand::execute()` (for the
 * admin-pattern-blocked rejection message), so flipping it via this
 * helper exercises the full boundary path without touching
 * `config/general.php`.
 *
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function cortex_with_admin_changes(bool $allow, callable $fn): mixed
{
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $original = $generalConfig->allowAdminChanges;
    $generalConfig->allowAdminChanges = $allow;

    try {
        return $fn();
    } finally {
        $generalConfig->allowAdminChanges = $original;
    }
}

/**
 * Run a callable while counting Yii's DB queries. Returns
 * `[result, queryCount]`. Use to assert that a tool stays under a fixed
 * query budget regardless of how much data lives in the playground —
 * i.e., that `with: [...]` actually eager-loads instead of N+1.
 *
 * @template T
 * @param callable(): T $fn
 * @return array{0: T, 1: int}
 */
function cortex_count_queries(callable $fn): array
{
    $db = Craft::$app->getDb();
    $logTarget = Yii::getLogger();

    // Yii's profiler captures every query when profile-level logging is on.
    // The cleanest way to count queries for a single block is to snapshot
    // the profile log before/after.
    $logTarget->flush();
    $before = count($logTarget->messages);

    $result = $fn();

    // The query profile entries Yii logs include category 'yii\db\Command::query'
    // and similar. Count just those.
    $after = count($logTarget->messages);
    $queryCount = 0;
    for ($i = $before; $i < $after; $i++) {
        $message = $logTarget->messages[$i] ?? null;
        if ($message === null) {
            continue;
        }
        $category = $message[2] ?? '';
        if (is_string($category) && str_starts_with($category, 'yii\\db\\')) {
            $queryCount++;
        }
    }

    return [$result, $queryCount];
}
