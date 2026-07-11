<?php

/**
 * =========================================================================
 * `system_diagnostics` Pro type — manage_queue — Gate 8.8a.
 *
 * Exercises:
 *   - The `inputSchemaFor()` type-enum filter across Free/Pro editions
 *     and stdio/admin/permitted/non-permitted users.
 *   - Each `manage_queue` sub-action (`retry`, `retry_all`, `release`,
 *     `release_all`) against fixture jobs pushed via Craft's queue.
 *   - The required-`jobId` guard on `retry` / `release`.
 *   - The permission-denied error-message contract — the locked rich
 *     format `permission denied — type `manage_queue` requires
 *     `utility:queue-manager`.` keyed on by the 8.10 invariant test.
 *   - The cross-edition guard.
 *
 * Fixture strategy: push real `Announcement` jobs to the default queue
 * channel. For `retry` we flip the queue row's `fail` flag via Db
 * directly — the alternative is running the queue worker until the job
 * fails, which is too slow for a per-test fixture. Each test wipes the
 * fixture rows in `afterEach()`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\db\Table;
use craft\elements\User;
use craft\helpers\Db;
use craft\queue\jobs\Announcement;
use craft\queue\Queue;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\system\Diagnostics;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    $this->fixturePrefix = '__herald_mq_' . bin2hex(random_bytes(4)) . '_';
});

afterEach(function() {
    // Wipe any fixture queue rows the test pushed. We tag fixture jobs
    // via the `description` column (Announcement::getDescription()
    // returns the heading), so the cleanup query is precise.
    Db::delete(Table::QUEUE, ['like', 'description', $this->fixturePrefix]);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_diag_tool(): Diagnostics
{
    return new Diagnostics();
}

/**
 * Push a fixture `Announcement` job onto the default queue channel and
 * return its row id (the queue's `id` column, stringified — Craft's
 * `Queue` API takes ids as strings).
 */
function _herald_diag_push(string $heading): string
{
    $queue = Craft::$app->getQueue();
    expect($queue)->toBeInstanceOf(Queue::class);

    $jobId = $queue->push(new Announcement([
        'heading' => $heading,
        'body' => 'fixture',
    ]));

    expect($jobId)->not->toBeNull();
    return (string) $jobId;
}

/**
 * Flip a fixture job row to the failed state without running it through
 * a worker. Mirrors what `Queue::handleError()` writes after a job
 * throws.
 */
function _herald_diag_mark_failed(string $jobId): void
{
    Db::update(
        Table::QUEUE,
        ['fail' => true, 'dateFailed' => Db::prepareDateForDb(new DateTime())],
        ['id' => $jobId],
    );
}

// -----------------------------------------------------------------------------
// inputSchemaFor — stdio invariant
// -----------------------------------------------------------------------------

it('inputSchemaFor(null) returns the Free enum on Free (edition gate beats stdio trust)', function() {
    // The runtime execute()-time gate refuses Pro types on Free
    // regardless of caller (including stdio); the schema mirrors that
    // so an LLM is never advertised a type the runtime would reject.
    // Pro-install stdio still sees the full enum (next test).
    $schema = _herald_diag_tool()->inputSchemaFor(null);
    expect($schema['properties']['type']['enum'])->toBe([
        'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff',
    ]);
});

it('inputSchemaFor(null) returns the full static enum on Pro (stdio invariant)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_diag_tool()->inputSchemaFor(null);
        expect($schema['properties']['type']['enum'])->toBe([
            'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff', 'manage_queue',
        ]);
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Free edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Free hides manage_queue from admins (HTTP path)', function() {
    // Free-edition HTTP callers don't see manage_queue regardless of
    // permission — the type doesn't exist on this install.
    $schema = _herald_diag_tool()->inputSchemaFor($this->admin);
    expect($schema['properties']['type']['enum'])->toBe([
        'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff',
    ]);
});

// -----------------------------------------------------------------------------
// inputSchemaFor — Pro edition + HTTP user
// -----------------------------------------------------------------------------

it('inputSchemaFor on Pro returns the full enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_diag_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['type']['enum'])->toBe([
            'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff', 'manage_queue',
        ]);
    });
});

it('inputSchemaFor on Pro hides manage_queue from users without utility:queue-manager', function() {
    $user = new User();
    $user->username = '__herald_mq_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $schema = _herald_diag_tool()->inputSchemaFor($user);
            expect($schema['properties']['type']['enum'])->toBe([
                'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff',
            ]);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

it('inputSchemaFor on Pro exposes manage_queue to users with utility:queue-manager', function() {
    $user = new User();
    $user->username = '__herald_mq_perm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }
    // accessCp is required so the utility permission isn't dropped as
    // an orphan by saveUserPermissions().
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $user->id,
        ['accessCp', 'utility:queue-manager'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $reloaded = Craft::$app->getUsers()->getUserById((int) $user->id);
            expect($reloaded)->toBeInstanceOf(User::class);
            $schema = _herald_diag_tool()->inputSchemaFor($reloaded);
            expect($schema['properties']['type']['enum'])->toBe([
                'logs', 'last_error', 'deprecations', 'queue', 'project_config_diff', 'manage_queue',
            ]);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// manage_queue — argument validation
// -----------------------------------------------------------------------------

it('manage_queue throws when action is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_diag_tool()->execute(['type' => 'manage_queue']);
    });
})->throws(ToolException::class, '`action` is required');

it('manage_queue throws when action is unknown', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_diag_tool()->execute(['type' => 'manage_queue', 'action' => 'cancel']);
    });
})->throws(ToolException::class, '`action` is required');

it('manage_queue retry throws when jobId is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_diag_tool()->execute(['type' => 'manage_queue', 'action' => 'retry']);
    });
})->throws(ToolException::class, '`jobId` is required');

it('manage_queue release throws when jobId is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_diag_tool()->execute(['type' => 'manage_queue', 'action' => 'release']);
    });
})->throws(ToolException::class, '`jobId` is required');

// -----------------------------------------------------------------------------
// manage_queue — happy paths
// -----------------------------------------------------------------------------

it('manage_queue release deletes the row and returns the post-action envelope', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $jobId = _herald_diag_push($this->fixturePrefix . 'release-target');

        // Sanity: row exists.
        $before = (int) (new \craft\db\Query())
            ->from(Table::QUEUE)
            ->where(['id' => $jobId])
            ->count();
        expect($before)->toBe(1);

        $result = _herald_diag_tool()->execute([
            'type' => 'manage_queue',
            'action' => 'release',
            'jobId' => $jobId,
        ]);

        expect($result)->toHaveKeys(['success', 'type', 'action', 'jobId', 'affected', 'queueAfter']);
        expect($result['success'])->toBeTrue();
        expect($result['type'])->toBe('manage_queue');
        expect($result['action'])->toBe('release');
        expect($result['jobId'])->toBe($jobId);
        expect($result['affected'])->toBe(1);
        expect($result['queueAfter'])->toHaveKeys(['waiting', 'delayed', 'reserved', 'failed', 'total']);

        // Row is gone.
        $after = (int) (new \craft\db\Query())
            ->from(Table::QUEUE)
            ->where(['id' => $jobId])
            ->count();
        expect($after)->toBe(0);
    });
});

it('manage_queue retry clears the failed state for a previously-failed job', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $jobId = _herald_diag_push($this->fixturePrefix . 'retry-target');
        _herald_diag_mark_failed($jobId);

        // Sanity: row is flagged failed.
        $row = (new \craft\db\Query())
            ->from(Table::QUEUE)
            ->where(['id' => $jobId])
            ->one();
        expect($row)->not->toBeFalse();
        expect((bool) $row['fail'])->toBeTrue();

        $result = _herald_diag_tool()->execute([
            'type' => 'manage_queue',
            'action' => 'retry',
            'jobId' => $jobId,
        ]);

        expect($result['action'])->toBe('retry');
        expect($result['affected'])->toBe(1);
        expect($result['jobId'])->toBe($jobId);

        // Fail flag should be cleared.
        $rowAfter = (new \craft\db\Query())
            ->from(Table::QUEUE)
            ->where(['id' => $jobId])
            ->one();
        expect($rowAfter)->not->toBeFalse();
        expect((bool) $rowAfter['fail'])->toBeFalse();
    });
});

it('manage_queue retry_all clears all failed rows on the channel', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $jobId = _herald_diag_push($this->fixturePrefix . "retry-all-{$i}");
            _herald_diag_mark_failed($jobId);
            $ids[] = $jobId;
        }

        $result = _herald_diag_tool()->execute([
            'type' => 'manage_queue',
            'action' => 'retry_all',
        ]);

        expect($result['action'])->toBe('retry_all');
        expect($result)->not->toHaveKey('jobId');

        // No failed rows remain on the channel.
        foreach ($ids as $id) {
            $row = (new \craft\db\Query())
                ->from(Table::QUEUE)
                ->where(['id' => $id])
                ->one();
            expect($row)->not->toBeFalse();
            expect((bool) $row['fail'])->toBeFalse();
        }
    });
});

it('manage_queue release_all wipes the channel and reports the delta', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = _herald_diag_push($this->fixturePrefix . "release-all-{$i}");
        }

        $before = (int) (new \craft\db\Query())->from(Table::QUEUE)->count();
        expect($before)->toBeGreaterThanOrEqual(4);

        $result = _herald_diag_tool()->execute([
            'type' => 'manage_queue',
            'action' => 'release_all',
        ]);

        expect($result['action'])->toBe('release_all');
        expect($result)->not->toHaveKey('jobId');
        expect($result['affected'])->toBeGreaterThanOrEqual(4);

        $after = (int) (new \craft\db\Query())->from(Table::QUEUE)->count();
        expect($after)->toBe(0);
    });
});

// -----------------------------------------------------------------------------
// manage_queue — permission gating
// -----------------------------------------------------------------------------

it('manage_queue throws permission-denied with the locked rich-format message for non-permitted users', function() {
    $user = new User();
    $user->username = '__herald_mq_denied_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user.');
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            $jobId = _herald_diag_push($this->fixturePrefix . 'denied-target');

            Craft::$app->getUser()->setIdentity($user);

            $caught = null;
            try {
                _herald_diag_tool()->execute([
                    'type' => 'manage_queue',
                    'action' => 'release',
                    'jobId' => $jobId,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toMatch(
                "/^permission denied — type `manage_queue` requires `utility:queue-manager`\\.\$/",
            );
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Cross-edition guard
// -----------------------------------------------------------------------------

it('manage_queue throws "unavailable on this edition" on a Free install', function() {
    _herald_diag_tool()->execute(['type' => 'manage_queue', 'action' => 'retry_all']);
})->throws(ToolException::class, 'unavailable on this edition');
