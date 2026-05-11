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
