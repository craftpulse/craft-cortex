<?php

/**
 * =========================================================================
 * Allowlist service tests — verify defaults union with runtime overrides,
 * expiry semantics, and pruning.
 *
 * Each test cleans up its overrides at the end so the table is left in
 * the same state it started in. The playground keeps cortex installed
 * across runs, so isolation matters.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\records\RuntimeOverride;
use yii\base\Exception;

beforeEach(function() {
    $this->service = Cortex::getInstance()->allowlist;
});

afterEach(function() {
    // Hard-delete every override touched by tests. Production never
    // runs against a tests/ namespace, so this is safe.
    RuntimeOverride::deleteAll([
        'or',
        ['like', 'pattern', '_test_/%', false],
        ['like', 'pattern', '_test/%', false],
    ]);
});

it('getEffective returns the bundled defaults at minimum', function() {
    $effective = $this->service->getEffective();

    // Defaults declared in Settings.
    expect($effective)->toContain('resave/*');
    expect($effective)->toContain('cache/*');
    expect($effective)->toContain('migrate/*');
});

it('add() persists a runtime override and getEffective reflects it', function() {
    $this->service->add('_test_/special-command', userId: null, note: 'unit-test');

    $effective = $this->service->getEffective();
    expect($effective)->toContain('_test_/special-command');
});

it('expired overrides do not appear in getEffective', function() {
    $override = $this->service->add('_test_/expired-command');
    // Force expiry into the past.
    $override->expiresAt = Carbon::now()->subSecond()->toDateTimeString();
    $override->save(false);

    $effective = $this->service->getEffective();
    expect($effective)->not->toContain('_test_/expired-command');
});

it('soft-deleted overrides do not appear in getEffective', function() {
    $override = $this->service->add('_test_/soft-deleted-command');
    $this->service->remove($override->id);

    $effective = $this->service->getEffective();
    expect($effective)->not->toContain('_test_/soft-deleted-command');
});

it('getActiveOverrides excludes expired and soft-deleted rows', function() {
    $live = $this->service->add('_test_/active');
    $expired = $this->service->add('_test_/expired');
    $expired->expiresAt = Carbon::now()->subSecond()->toDateTimeString();
    $expired->save(false);

    $active = $this->service->getActiveOverrides();
    $patterns = array_column($active, 'pattern');

    expect($patterns)->toContain('_test_/active');
    expect($patterns)->not->toContain('_test_/expired');
});

it('getAllOverrides returns expired rows when includeExpired = true', function() {
    $expired = $this->service->add('_test_/all-expired');
    $expired->expiresAt = Carbon::now()->subSecond()->toDateTimeString();
    $expired->save(false);

    $all = $this->service->getAllOverrides(includeExpired: true);
    $patterns = array_column($all, 'pattern');

    expect($patterns)->toContain('_test_/all-expired');
});

it('pruneExpired hard-deletes expired non-deleted rows', function() {
    $expired = $this->service->add('_test_/prune-me');
    $expired->expiresAt = Carbon::now()->subSecond()->toDateTimeString();
    $expired->save(false);

    $live = $this->service->add('_test_/keep-me');

    $pruned = $this->service->pruneExpired();
    expect($pruned)->toBeGreaterThan(0);

    $remaining = RuntimeOverride::find()
        ->where(['like', 'pattern', '_test_/%', false])
        ->asArray()
        ->all();
    $patterns = array_column($remaining, 'pattern');

    expect($patterns)->not->toContain('_test_/prune-me');
    expect($patterns)->toContain('_test_/keep-me');
});

it('add() throws when the underlying record fails validation', function() {
    // Empty pattern violates the `required` rule on RuntimeOverride;
    // save() returns false and add() must surface the failure rather
    // than silently returning an unsaved record.
    expect(fn() => $this->service->add(''))
        ->toThrow(Exception::class, 'Failed to save runtime override');
});

it('add() throws when the pattern exceeds the column length', function() {
    $tooLong = str_repeat('x', 300);
    expect(fn() => $this->service->add($tooLong))
        ->toThrow(Exception::class, 'Failed to save runtime override');
});

it('remove() throws when the soft-delete save fails', function() {
    // Persist a valid override, then blank out the pattern via
    // save(false) to bypass the required-rule check (DB accepts empty
    // strings). Calling remove() saves with validation enabled, which
    // surfaces the required-rule failure as a thrown Exception.
    $override = $this->service->add('_test_/remove-fail');
    $override->pattern = '';
    $override->save(false);

    // Confirm the DB accepted the empty pattern — this is the
    // precondition that lets the next save() fail at validation.
    $override->refresh();
    expect($override->pattern)->toBe('');

    expect(fn() => $this->service->remove($override->id))
        ->toThrow(Exception::class, 'Failed to soft-delete');
});

it('craft_command tool resolves allowlist through the service', function() {
    $this->service->add('_test_/special-route');

    $tool = Cortex::getInstance()->tools->getByName('craft_command');
    $result = $tool->execute(['mode' => 'list']);

    expect($result['patterns'])->toContain('_test_/special-route');
});

it('add() invalidates the active-overrides cache', function() {
    // Prime the cache with a baseline read.
    $before = $this->service->getEffective();
    expect($before)->not->toContain('_test_/cache-invalidation');

    // Add a new override and read again — the new pattern must be
    // visible without a service restart.
    $this->service->add('_test_/cache-invalidation');
    $after = $this->service->getEffective();

    expect($after)->toContain('_test_/cache-invalidation');
});

it('remove() invalidates the active-overrides cache', function() {
    $override = $this->service->add('_test_/cache-remove');

    // Prime the cache.
    expect($this->service->getEffective())->toContain('_test_/cache-remove');

    // Soft-delete and confirm the pattern is gone on the next read.
    $this->service->remove($override->id);
    expect($this->service->getEffective())->not->toContain('_test_/cache-remove');
});

it('pruneExpired caps the per-call delete count at PRUNE_BATCH_LIMIT', function() {
    // Insert one row beyond the cap directly so the test doesn't take
    // 15000 round-trips through the validating ::save() path. The cap
    // assertion is what matters — the rest get cleaned up by a
    // subsequent gc sweep. Use the record class directly so we can
    // bypass the rule that requires `pattern` to be non-empty for new
    // rows when we're seeding through findOne()->save(false).
    //
    // Strategy: seed PRUNE_BATCH_LIMIT + 5 expired rows, call
    // pruneExpired, assert the return is exactly PRUNE_BATCH_LIMIT
    // (the LIMIT short-circuits), then call again and assert the
    // remainder (5) is pruned.
    $cap = \craftpulse\cortex\services\Allowlist::PRUNE_BATCH_LIMIT;
    $surplus = 5;
    $past = Carbon::now()->subSecond()->toDateTimeString();

    // Bulk-insert via the query builder — fastest path to many rows.
    $rows = [];
    for ($i = 0; $i < $cap + $surplus; $i++) {
        $rows[] = [
            'pattern' => '_test/batch-' . $i,
            'note' => null,
            'createdByUserId' => null,
            'expiresAt' => $past,
            'dateDeleted' => null,
            'dateCreated' => $past,
            'dateUpdated' => $past,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ];
    }
    Craft::$app->getDb()->createCommand()
        ->batchInsert(
            RuntimeOverride::tableName(),
            ['pattern', 'note', 'createdByUserId', 'expiresAt', 'dateDeleted', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )
        ->execute();

    $firstPass = $this->service->pruneExpired();
    expect($firstPass)->toBe($cap);

    $secondPass = $this->service->pruneExpired();
    expect($secondPass)->toBeGreaterThanOrEqual($surplus);
});
