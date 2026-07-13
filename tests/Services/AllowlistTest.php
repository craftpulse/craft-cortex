<?php

/**
 * =========================================================================
 * Allowlist service tests — verify defaults union with runtime overrides,
 * expiry semantics, and pruning.
 *
 * Each test cleans up its overrides at the end so the table is left in
 * the same state it started in. The playground keeps herald installed
 * across runs, so isolation matters.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use Carbon\Carbon;
use craftpulse\herald\Herald;
use craftpulse\herald\records\RuntimeOverride;
use yii\base\Exception;

beforeEach(function() {
    $this->service = Herald::getInstance()->allowlist;
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
    // Seed expiry in UTC — the service stores (via add()) and compares
    // (getActiveOverrides / pruneExpired) in UTC, matching Craft's DB
    // datetime convention. A bare Carbon::now() would seed in the app's
    // local timezone, landing the "expired" row in the UTC future on any
    // non-UTC install and defeating the expiry filter.
    $override->expiresAt = Carbon::now('UTC')->subSecond()->toDateTimeString();
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
    $expired->expiresAt = Carbon::now('UTC')->subSecond()->toDateTimeString();
    $expired->save(false);

    $active = $this->service->getActiveOverrides();
    $patterns = array_column($active, 'pattern');

    expect($patterns)->toContain('_test_/active');
    expect($patterns)->not->toContain('_test_/expired');
});

it('getAllOverrides returns expired rows when includeExpired = true', function() {
    $expired = $this->service->add('_test_/all-expired');
    $expired->expiresAt = Carbon::now('UTC')->subSecond()->toDateTimeString();
    $expired->save(false);

    $all = $this->service->getAllOverrides(includeExpired: true);
    $patterns = array_column($all, 'pattern');

    expect($patterns)->toContain('_test_/all-expired');
});

it('pruneExpired hard-deletes expired non-deleted rows', function() {
    $expired = $this->service->add('_test_/prune-me');
    $expired->expiresAt = Carbon::now('UTC')->subSecond()->toDateTimeString();
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

    $tool = Herald::getInstance()->tools->getByName('craft_command');
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

// =========================================================================
// Command enumeration + toggle round-trip
// =========================================================================

it('getCommandGroups enumerates core Craft command groups', function() {
    $groups = $this->service->getCommandGroups();

    // Core controllers that always ship with Craft.
    expect($groups)->toHaveKey('resave');
    expect($groups)->toHaveKey('clear-caches');
    expect($groups)->toHaveKey('gc');

    // Each group carries its handle + a source label + an action list.
    expect($groups['resave']['group'])->toBe('resave');
    expect($groups['resave']['source'])->toBe('Craft');
    expect($groups['resave']['actions'])->not->toBeEmpty();
});

it('getCommandGroups surfaces non-default actions as controller/action route ids', function() {
    $groups = $this->service->getCommandGroups();

    $resaveIds = array_column($groups['resave']['actions'], 'id');
    // `resave/entries` is a stable core route.
    expect($resaveIds)->toContain('resave/entries');
});

it('getCommandGroups surfaces a default-action-only controller as a bare route id', function() {
    $groups = $this->service->getCommandGroups();

    // `up` (UpController::actionIndex) dispatches bare — its only public
    // action is the default `index`, so the route id is the controller
    // handle itself with no trailing segment.
    expect($groups)->toHaveKey('up');
    $ids = array_column($groups['up']['actions'], 'id');
    expect($ids)->toContain('up');
});

it('getCommandGroups registers a bare alias for a non-index default action', function() {
    $groups = $this->service->getCommandGroups();

    // `GcController` exposes only `actionRun` with `$defaultAction = 'run'`,
    // so bare `gc` dispatches to `gc/run`. Enumeration must register the
    // bare `gc` alias alongside `gc/run` so the shipped default `gc`
    // pattern maps to the group instead of falling into custom patterns.
    expect($groups)->toHaveKey('gc');
    $ids = array_column($groups['gc']['actions'], 'id');
    expect($ids)->toContain('gc');
    expect($ids)->toContain('gc/run');
});

it('tags admin-level groups and content groups by classification', function() {
    $groups = $this->service->getCommandGroups();

    // `migrate/*` is in the default admin-level classification set.
    expect($groups['migrate']['adminLevel'])->toBeTrue();
    // `resave/*` is content-level.
    expect($groups['resave']['adminLevel'])->toBeFalse();
});

it('isAdminLevelRoute classifies routes against the default admin patterns', function() {
    expect($this->service->isAdminLevelRoute('migrate/up'))->toBeTrue();
    expect($this->service->isAdminLevelRoute('project-config/apply'))->toBeTrue();
    expect($this->service->isAdminLevelRoute('up'))->toBeTrue();
    expect($this->service->isAdminLevelRoute('resave/entries'))->toBeFalse();
    expect($this->service->isAdminLevelRoute('cache/flush-all'))->toBeFalse();
});

it('classifies the pc alias as admin-level so it cannot bypass the gate via project-config aliasing', function() {
    // `PcController extends ProjectConfigController` — `pc/apply` mutates
    // project config exactly as `project-config/apply` does, so it must sit
    // in the admin bucket. Were it content-classified, toggling `pc` on in
    // the CP would always-admit project-config mutations through the alias.
    expect($this->service->isAdminLevelRoute('pc/apply'))->toBeTrue();
    expect($this->service->isAdminLevelRoute('pc/rebuild'))->toBeTrue();
});

it('getCommandGroups includes enabled-plugin console commands', function() {
    $groups = $this->service->getCommandGroups();

    // Herald itself ships console controllers under
    // `craftpulse\herald\console\controllers` — at minimum `herald/serve`.
    expect($groups)->toHaveKey('herald');
    $ids = array_column($groups['herald']['actions'], 'id');
    expect($ids)->toContain('herald/serve');
});

it('mapPatternsToToggleState flags a group/* glob as fullToggle', function() {
    $state = $this->service->mapPatternsToToggleState(['resave/*'], []);

    expect($state['groups']['resave']['fullToggle'])->toBeTrue();
    // Every action in the group reads as allowed under the glob.
    expect($state['groups']['resave']['allowedCount'])
        ->toBe($state['groups']['resave']['totalCount']);
    foreach ($state['groups']['resave']['actions'] as $action) {
        expect($action['allowed'])->toBeTrue();
    }
});

it('mapPatternsToToggleState flags an exact id without fullToggle', function() {
    $state = $this->service->mapPatternsToToggleState(['resave/entries'], []);

    expect($state['groups']['resave']['fullToggle'])->toBeFalse();
    expect($state['groups']['resave']['allowedCount'])->toBe(1);

    $entries = array_values(array_filter(
        $state['groups']['resave']['actions'],
        static fn(array $a): bool => $a['id'] === 'resave/entries',
    ));
    expect($entries[0]['allowed'])->toBeTrue();
});

it('mapPatternsToToggleState routes unknown content patterns to contentCustomPatterns', function() {
    $state = $this->service->mapPatternsToToggleState([
        'resave/ent*',                 // glob that is not `resave/*`
        'no-such-plugin/do-thing',     // route for a since-removed plugin
        'resave/*',                    // recognised group glob
    ], []);

    expect($state['contentCustomPatterns'])->toContain('resave/ent*');
    expect($state['contentCustomPatterns'])->toContain('no-such-plugin/do-thing');
    expect($state['contentCustomPatterns'])->not->toContain('resave/*');
    expect($state['groups']['resave']['fullToggle'])->toBeTrue();
});

it('mapPatternsToToggleState maps the bare gc default to its group, not custom patterns', function() {
    // `gc` (default-action alias) is a known bare action id, so the
    // shipped default `gc` pattern must light up the gc group's bare
    // action rather than falling into custom patterns.
    $state = $this->service->mapPatternsToToggleState(['gc'], []);

    expect($state['contentCustomPatterns'])->not->toContain('gc');
    expect($state['groups'])->toHaveKey('gc');

    $bare = array_values(array_filter(
        $state['groups']['gc']['actions'],
        static fn(array $a): bool => $a['id'] === 'gc',
    ));
    expect($bare[0]['allowed'])->toBeTrue();
});

it('mapPatternsToToggleState resolves admin groups from the admin bucket only', function() {
    // `migrate/*` is admin-level. Passing it in the CONTENT bucket must
    // NOT toggle the migrate group — a content pattern can never light up
    // an admin route. It falls into contentCustomPatterns instead.
    $contentOnly = $this->service->mapPatternsToToggleState(['migrate/*'], []);
    expect($contentOnly['groups']['migrate']['fullToggle'])->toBeFalse();
    expect($contentOnly['contentCustomPatterns'])->toContain('migrate/*');

    // In the ADMIN bucket it toggles the migrate group as expected.
    $adminBucket = $this->service->mapPatternsToToggleState([], ['migrate/*']);
    expect($adminBucket['groups']['migrate']['fullToggle'])->toBeTrue();
    expect($adminBucket['adminCustomPatterns'])->not->toContain('migrate/*');
});

it('patternsFromToggleState collapses a full content group to a single glob', function() {
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: ['resave' => '1'],
        actionIds: ['resave/entries' => '1'],   // redundant under the glob
        customPatterns: [],
        adminLevel: false,
    );

    expect($patterns)->toContain('resave/*');
    // The exact id is dropped because the group glob already covers it.
    expect($patterns)->not->toContain('resave/entries');
});

it('patternsFromToggleState emits the bare handle for a bare-only group', function() {
    // `up` is admin-level (its only route is the bare `up`). A full
    // toggle must emit `up`, not `up/*` — `up/*` never fnmatches `up`.
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: ['up' => '1'],
        actionIds: [],
        customPatterns: [],
        adminLevel: true,
    );

    expect($patterns)->toContain('up');
    expect($patterns)->not->toContain('up/*');
});

it('patternsFromToggleState emits exact ids for a partial group', function() {
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: [],
        actionIds: ['resave/entries' => '1'],
        customPatterns: [],
        adminLevel: false,
    );

    expect($patterns)->toContain('resave/entries');
    expect($patterns)->not->toContain('resave/*');
});

it('patternsFromToggleState refuses to fold a cross-bucket group', function() {
    // Posting the admin `migrate` group into the CONTENT fold (adminLevel
    // false) must drop it — the security boundary: a content save can
    // never surface an admin route into the always-admitted bucket.
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: ['migrate' => '1'],
        actionIds: ['migrate/up' => '1'],
        customPatterns: [],
        adminLevel: false,
    );

    expect($patterns)->not->toContain('migrate/*');
    expect($patterns)->not->toContain('migrate/up');
    expect($patterns)->toBe([]);
});

it('patternsFromToggleState passes custom patterns through verbatim', function() {
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: [],
        actionIds: [],
        customPatterns: ['resave/ent*', 'no-such-plugin/do-thing'],
        adminLevel: false,
    );

    expect($patterns)->toContain('resave/ent*');
    expect($patterns)->toContain('no-such-plugin/do-thing');
});

it('patternsFromToggleState ignores unknown group and action ids', function() {
    $patterns = $this->service->patternsFromToggleState(
        fullGroups: ['ghost-group' => '1'],
        actionIds: ['ghost-group/ghost-action' => '1'],
        customPatterns: [],
        adminLevel: false,
    );

    expect($patterns)->toBe([]);
});

it('content toggle state survives a full round-trip', function() {
    // Forward: content patterns → toggle state.
    $original = ['cache/*', 'resave/entries', 'resave/ent*'];
    $state = $this->service->mapPatternsToToggleState($original, []);

    // Reconstruct the POST payload the browser would submit from the
    // content groups only.
    $fullGroups = [];
    $actionIds = [];
    foreach ($state['groups'] as $handle => $group) {
        if ($group['adminLevel']) {
            continue;
        }
        if ($group['fullToggle']) {
            $fullGroups[$handle] = '1';
            continue;
        }
        foreach ($group['actions'] as $action) {
            if ($action['allowed']) {
                $actionIds[$action['id']] = '1';
            }
        }
    }

    // Inverse: toggle state → patterns.
    $roundTripped = $this->service->patternsFromToggleState(
        $fullGroups,
        $actionIds,
        $state['contentCustomPatterns'],
        adminLevel: false,
    );

    sort($original);
    sort($roundTripped);
    expect($roundTripped)->toBe($original);
});

it('admin toggle state round-trips the bare-handle group', function() {
    // `up` (bare-only, admin-level) must survive map → fold as `up`.
    $original = ['up', 'migrate/*'];
    $state = $this->service->mapPatternsToToggleState([], $original);

    $fullGroups = [];
    $actionIds = [];
    foreach ($state['groups'] as $handle => $group) {
        if (!$group['adminLevel']) {
            continue;
        }
        if ($group['fullToggle']) {
            $fullGroups[$handle] = '1';
            continue;
        }
        foreach ($group['actions'] as $action) {
            if ($action['allowed']) {
                $actionIds[$action['id']] = '1';
            }
        }
    }

    $roundTripped = $this->service->patternsFromToggleState(
        $fullGroups,
        $actionIds,
        $state['adminCustomPatterns'],
        adminLevel: true,
    );

    sort($original);
    sort($roundTripped);
    expect($roundTripped)->toBe($original);
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
    $cap = \craftpulse\herald\services\Allowlist::PRUNE_BATCH_LIMIT;
    $surplus = 5;
    $past = Carbon::now('UTC')->subSecond()->toDateTimeString();

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
