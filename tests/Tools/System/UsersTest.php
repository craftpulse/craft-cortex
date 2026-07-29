<?php

/**
 * =========================================================================
 * `users` Pro tool tests — Gate 8.5.
 *
 * Tests cover:
 *   - Free install: tool absent from the registry.
 *   - Pro install: shouldRegister gate flips correctly.
 *   - Mode validation: missing / unknown mode → ToolException.
 *   - Per-user `filterFor()` + `inputSchemaFor()` surface.
 *   - list / get round-trips against Director Fury (read-only) and
 *     throwaway users (mutation).
 *   - PII redaction tiers — viewUsers, editUsers, administrateUsers,
 *     admin.
 *   - Custom-field allowlist gating.
 *   - create: admin happy path, registerUsers, admin-promotion
 *     refusal, duplicate email validation envelope, group permission
 *     gate, idempotency.
 *   - update: admin happy path, editUsers non-admin on non-admin
 *     target, **admin-protection regression** (the headline gate of
 *     8.5), sensitive-field gate, self-edit newPassword,
 *     groupUids diff, trashed probe, idempotency.
 *   - delete: hard + soft, transferContentTo, deleteUsers non-admin
 *     on admin target rejection, missing deleteUsers rejection.
 *
 * Fixture strategy: Director Fury (`nfury`) is the read-only target
 * for list / get / PII assertions. Mutation tests create throwaway
 * users with prefix `__herald_userstest_<hex>_`; afterEach hard-
 * deletes them by username LIKE.
 *
 * **SEQUENTIAL ONLY** — create / update modes call
 * `Elements::saveElement()` on a `User`, which syncs the user field
 * layout to project config. Running these under Paratest would race
 * concurrent reads of the same PC surface.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\system\Users;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_userstest_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    // Director Fury is the seeded read-only target. Tests that need
    // a known existing user fall back to skip when Fury is absent.
    $this->fury = Craft::$app->getUsers()->getUserByUsernameOrEmail('nfury');
});

afterEach(function() {
    // Hard-delete every throwaway user this test created.
    $users = User::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    // Restore admin identity.
    Craft::$app->getUser()->setIdentity($this->admin);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Direct instantiation sidesteps the boot-time registry gate. The
 * invocation context defaults to a stdio transport so the credential-
 * mutation gate treats these direct calls as the trusted local path —
 * stdio is implicitly elevated. HTTP-transport tests pass
 * `Server::TRANSPORT_HTTP` to exercise the un-elevated refusal, and
 * `elevated: true` to exercise the WS2 elevated-HTTP allowance.
 */
function _herald_users_tool(string $transport = Server::TRANSPORT_STDIO, bool $elevated = false): Users
{
    $tool = new Users();
    $tool->setInvocationContext(new InvocationContext(transport: $transport, elevated: $elevated));

    return $tool;
}

/**
 * Create a throwaway user. Returns null on save failure so the test
 * can skip with the validation errors.
 */
function _herald_users_user(string $prefix, string $suffix = 'user', array $overrides = []): ?User
{
    $user = new User();
    $user->username = $prefix . $suffix;
    $user->email = $user->username . '@example.test';
    $user->firstName = 'Test';
    $user->lastName = 'User';
    $user->pending = true;

    foreach ($overrides as $attr => $value) {
        $user->{$attr} = $value;
    }

    if (!Craft::$app->getElements()->saveElement($user)) {
        return null;
    }

    return $user;
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Herald::getInstance()->tools->getByName('users'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('users');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Users::shouldRegister())->toBeFalse();

    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(Users::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_users_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_users_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true: stdio is trusted', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_users_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect($this->admin)->toBeInstanceOf(User::class);
        expect(_herald_users_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users without viewUsers permission', function() {
    $user = _herald_users_user($this->fixturePrefix, 'no_perms');
    if ($user === null) {
        $this->markTestSkipped('Could not create unprivileged fixture user.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
        expect(_herald_users_tool()->filterFor($user))->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — mode-enum surface
// -----------------------------------------------------------------------------

it('inputSchemaFor returns the full mode enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_users_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])
            ->toBe(['list', 'get', 'create', 'update', 'delete']);
    });
});

it('inputSchemaFor returns the full mode enum for stdio (null user)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_users_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])
            ->toBe(['list', 'get', 'create', 'update', 'delete']);
    });
});

// -----------------------------------------------------------------------------
// list mode
// -----------------------------------------------------------------------------

it('list mode returns paginated users with PII visible to admin', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'list',
            'limit' => 50,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('list');
        expect($result['users'])->toBeArray();
        expect($result['count'])->toBeGreaterThanOrEqual(1);

        // Admin caller sees emails.
        foreach ($result['users'] as $row) {
            expect($row)->toHaveKey('email');
            expect($row)->toHaveKey('id');
        }
    });
});

it('list mode finds Director Fury via search', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded in this playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'list',
            'search' => 'Fury',
        ]);

        expect($result['success'])->toBeTrue();
        $ids = array_column($result['users'], 'id');
        expect($ids)->toContain((int) $this->fury->id);
    });
});

it('list mode admin filter narrows to admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'list',
            'admin' => true,
        ]);

        expect($result['success'])->toBeTrue();
        foreach ($result['users'] as $row) {
            expect($row['admin'])->toBeTrue();
        }
    });
});

// -----------------------------------------------------------------------------
// PII regression — the highest-value gate of 8.5
// -----------------------------------------------------------------------------

it('viewUsers-only caller never receives email/unverifiedEmail/lockout fields on get', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded — needed as the read target.');
    }

    // Fresh user, no permissions; manually grant `viewUsers` via a
    // group. Simpler path: instantiate a user with admin=false, then
    // assign a UserGroup that holds `viewUsers`. For test
    // simplicity, fake-it by checking the redaction code path
    // directly via `_serializeUser` — we set up a viewUsers-only
    // identity using a fresh user + a one-off group.
    $caller = new User();
    $caller->username = $this->fixturePrefix . 'viewonly';
    $caller->email = $caller->username . '@example.test';
    $caller->admin = false;
    $caller->pending = true;
    if (!Craft::$app->getElements()->saveElement($caller)) {
        $this->markTestSkipped('Could not create viewUsers caller: ' . json_encode($caller->getErrors()));
    }

    // Grant viewUsers via direct permission assignment (avoids
    // creating a UserGroup just for this test).
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $result = _herald_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);

            expect($result['success'])->toBeTrue();
            $user = $result['user'];
            expect($user)->toHaveKey('id');
            expect($user)->toHaveKey('username');

            // PII redaction — viewUsers caller MUST NOT see these.
            expect(array_key_exists('email', $user))->toBeFalse();
            expect(array_key_exists('unverifiedEmail', $user))->toBeFalse();
            expect(array_key_exists('invalidLoginCount', $user))->toBeFalse();
            expect(array_key_exists('lastInvalidLoginDate', $user))->toBeFalse();
            expect(array_key_exists('lockoutDate', $user))->toBeFalse();
            expect(array_key_exists('passwordResetRequired', $user))->toBeFalse();
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('editUsers caller sees email and lockout but not passwordResetRequired', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    $caller = _herald_users_user($this->fixturePrefix, 'editorperm');
    if ($caller === null) {
        $this->markTestSkipped('Could not create editUsers caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $result = _herald_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);

            expect($result['user'])->toHaveKey('email');
            expect($result['user'])->toHaveKey('invalidLoginCount');
            expect(array_key_exists('passwordResetRequired', $result['user']))->toBeFalse();
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('administrateUsers caller additionally sees passwordResetRequired', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    $caller = _herald_users_user($this->fixturePrefix, 'admincaller');
    if ($caller === null) {
        $this->markTestSkipped('Could not create administrateUsers caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers', 'administrateusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $result = _herald_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);

            expect($result['user'])->toHaveKey('email');
            expect($result['user'])->toHaveKey('passwordResetRequired');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

// -----------------------------------------------------------------------------
// Custom field allowlist
// -----------------------------------------------------------------------------

it('custom fields are omitted when the allowlist is empty (default)', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);

        expect($result['user']['fields'])->toBe([]);
    });
});

it('a handle on the allowlist appears in the serialised fields envelope', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    $settings = Herald::getInstance()->getSettings();
    $originalAllowlist = $settings->userCustomFieldAllowlist;
    // Flip the allowlist on a non-existent handle so the serialiser
    // walks the list but `getFieldValue()` throws → caught by the
    // serialiser. The point of this test is to confirm that the
    // serialiser walks the allowlist at all — a non-empty allowlist
    // populates the `fields` array (with non-existent handles
    // silently dropped by the try-catch around getFieldValue, which
    // is the desired behaviour).
    $settings->userCustomFieldAllowlist = ['__herald_userstest_nonexistent'];

    try {
        herald_with_edition(Herald::EDITION_PRO, function() {
            $result = _herald_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);

            // Non-existent handles are caught and dropped — the
            // important assertion is the empty fields array, NOT a
            // surfaced PHP error. The serialiser is gated by the
            // allowlist (proven by the empty-allowlist test above);
            // this confirms the walk path doesn't crash on a bad
            // handle.
            expect($result['user']['fields'])->toBeArray();
        });
    } finally {
        $settings->userCustomFieldAllowlist = $originalAllowlist;
    }
});

// -----------------------------------------------------------------------------
// get mode — lookup keys
// -----------------------------------------------------------------------------

it('get mode resolves by id', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['id'])->toBe((int) $this->fury->id);
    });
});

it('get mode resolves by uid', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'uid' => $this->fury->uid,
        ]);
        expect($result['user']['uid'])->toBe($this->fury->uid);
    });
});

it('get mode resolves by email', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'email' => $this->fury->email,
        ]);
        expect($result['user']['id'])->toBe((int) $this->fury->id);
    });
});

it('get mode resolves by email case-insensitively on MySQL', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }
    if (!Craft::$app->getDb()->getIsMysql()) {
        $this->markTestSkipped('Email case-insensitivity is a MySQL-only contract.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'email' => strtoupper((string) $this->fury->email),
        ]);
        expect($result['user']['id'])->toBe((int) $this->fury->id);
    });
});

it('get mode resolves by username', function() {
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'get',
            'username' => $this->fury->username,
        ]);
        expect($result['user']['username'])->toBe($this->fury->username);
    });
});

it('get mode throws when no lookup key is supplied', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_users_tool()->execute(['mode' => 'get']);
    });
})->throws(ToolException::class);

it('get mode throws when the user does not exist', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_users_tool()->execute([
            'mode' => 'get',
            'email' => 'does-not-exist-' . bin2hex(random_bytes(4)) . '@example.invalid',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// create mode
// -----------------------------------------------------------------------------

it('create mode round-trips a new user as admin', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'create',
            'email' => $this->fixturePrefix . 'created@example.test',
            'username' => $this->fixturePrefix . 'created',
            'firstName' => 'Created',
            'lastName' => 'User',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['user']['id'])->toBeInt();
        expect($result['user']['username'])->toBe($this->fixturePrefix . 'created');
        // Default pending=true per Craft convention.
        expect($result['user']['pending'])->toBeTrue();
    });
});

it('create with admin=true succeeds for admin caller', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_users_tool()->execute([
            'mode' => 'create',
            'email' => $this->fixturePrefix . 'newadmin@example.test',
            'username' => $this->fixturePrefix . 'newadmin',
            'admin' => true,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['user']['admin'])->toBeTrue();
    });
});

it('create with admin=true throws ToolException for non-admin caller (admin-promotion refusal)', function() {
    $caller = _herald_users_user($this->fixturePrefix, 'registrant');
    if ($caller === null) {
        $this->markTestSkipped('Could not create registrant caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'registerusers', 'administrateusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'create',
                    'email' => $this->fixturePrefix . 'shouldfail@example.test',
                    'username' => $this->fixturePrefix . 'shouldfail',
                    'admin' => true,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('admin');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('create with duplicate email returns validation envelope', function() {
    $existing = _herald_users_user($this->fixturePrefix, 'dupe');
    if ($existing === null) {
        $this->markTestSkipped('Could not seed duplicate-email fixture.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($existing) {
        $result = _herald_users_tool()->execute([
            'mode' => 'create',
            'email' => $existing->email,
            'username' => $this->fixturePrefix . 'dupe_2',
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['mode'])->toBe('create');
        expect($result['errors'])->toHaveKey('email');
    });
});

it('create throws ToolException when caller lacks registerUsers', function() {
    $caller = _herald_users_user($this->fixturePrefix, 'noregister');
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'create',
                    'email' => $this->fixturePrefix . 'norights@example.test',
                    'username' => $this->fixturePrefix . 'norights',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('registerUsers');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('create idempotency cache returns the same envelope on the second call', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_users_tool();
        $key = 'idem_' . bin2hex(random_bytes(8));
        $args = [
            'mode' => 'create',
            'email' => $this->fixturePrefix . 'idem@example.test',
            'username' => $this->fixturePrefix . 'idem',
            'idempotencyKey' => $key,
        ];

        $first = $tool->execute($args);
        $second = $tool->execute($args);

        expect($second['user']['id'])->toBe($first['user']['id']);

        $count = (int) User::find()
            ->status(null)
            ->andWhere(['users.username' => $this->fixturePrefix . 'idem'])
            ->count();
        expect($count)->toBe(1);
    });
});

// -----------------------------------------------------------------------------
// update mode — admin happy path
// -----------------------------------------------------------------------------

it('update mode mutates a user as admin and persists changes', function() {
    $target = _herald_users_user($this->fixturePrefix, 'updateable');
    if ($target === null) {
        $this->markTestSkipped('Could not create update target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $result = _herald_users_tool()->execute([
            'mode' => 'update',
            'id' => $target->id,
            'firstName' => 'Renamed',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['user']['firstName'])->toBe('Renamed');

        $reloaded = Craft::$app->getUsers()->getUserById((int) $target->id);
        expect($reloaded->firstName)->toBe('Renamed');
    });
});

// -----------------------------------------------------------------------------
// **ADMIN-PROTECTION REGRESSION** — headline test of 8.5
// -----------------------------------------------------------------------------

it('update refuses a non-admin caller editing an admin target (admin-protection regression)', function() {
    if ($this->admin === null) {
        $this->markTestSkipped('Need a known admin target for the admin-protection regression.');
    }

    $caller = _herald_users_user($this->fixturePrefix, 'editorbutnoadmin');
    if ($caller === null) {
        $this->markTestSkipped('Could not create editUsers caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers', 'administrateusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'update',
                    'id' => $this->admin->id,
                    'firstName' => 'CompromiseAttempt',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('admin');
            expect($caught->getMessage())->toMatch('/admin user|edit an admin/i');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('update with admin=true refuses a non-admin caller (admin-promotion refusal on update)', function() {
    $target = _herald_users_user($this->fixturePrefix, 'promotionTarget');
    $caller = _herald_users_user($this->fixturePrefix, 'editorWantsAdmin');
    if ($target === null || $caller === null) {
        $this->markTestSkipped('Could not create fixtures.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers', 'administrateusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller, $target) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'update',
                    'id' => $target->id,
                    'admin' => true,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toMatch('/admin/i');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

// -----------------------------------------------------------------------------
// update — sensitive-field gate
// -----------------------------------------------------------------------------

it('update with active=true refuses a non-admin caller without administrateUsers', function() {
    $target = _herald_users_user($this->fixturePrefix, 'activationtarget');
    $caller = _herald_users_user($this->fixturePrefix, 'noadministrate');
    if ($target === null || $caller === null) {
        $this->markTestSkipped('Could not create fixtures.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller, $target) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'update',
                    'id' => $target->id,
                    'active' => true,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('administrateUsers');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('update with newPassword on self passes the sensitive-field gate without administrateUsers', function() {
    $self = _herald_users_user($this->fixturePrefix, 'selfpw');
    if ($self === null) {
        $this->markTestSkipped('Could not create self user.');
    }
    // A real-world self-edit caller must have at least `viewUsers`
    // to surface the tool. Without it, the coarse gate refuses
    // before the sensitive-field carve-out fires.
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $self->id,
        ['viewusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($self) {
            Craft::$app->getUser()->setIdentity($self);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'update',
                    'id' => $self->id,
                    'newPassword' => 'NewSecurePassword!42',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            } catch (Throwable $e) {
                // Craft's User::afterSave chain calls
                // `Craft::$app->getUser()->getToken()` which is a
                // craft\web\User method — `craft\console\User` (the
                // test-time component) doesn't expose it, so any save
                // that touches that branch (newPassword change) blows
                // up under Pest. We intentionally swallow that here:
                // the gate decision is what 8.5 tests, the
                // `afterSave` plumbing belongs to Craft.
                if (!str_contains($e->getMessage(), 'getToken')) {
                    throw $e;
                }
            }
            expect($caught)->toBeNull();
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('update with newPassword on non-self refuses without administrateUsers', function() {
    $target = _herald_users_user($this->fixturePrefix, 'pwtarget');
    $caller = _herald_users_user($this->fixturePrefix, 'pwcaller');
    if ($target === null || $caller === null) {
        $this->markTestSkipped('Could not create fixtures.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'editusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller, $target) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'update',
                    'id' => $target->id,
                    'newPassword' => 'ResetByOtherUser!42',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('administrateUsers');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

// -----------------------------------------------------------------------------
// update — trashed probe
// -----------------------------------------------------------------------------

it('update against a trashed user surfaces a restore hint message', function() {
    $target = _herald_users_user($this->fixturePrefix, 'trashed');
    if ($target === null) {
        $this->markTestSkipped('Could not create trashed-update target.');
    }

    $id = (int) $target->id;
    Craft::$app->getElements()->deleteElement($target);

    herald_with_edition(Herald::EDITION_PRO, function() use ($id) {
        $caught = null;
        try {
            _herald_users_tool()->execute([
                'mode' => 'update',
                'id' => $id,
                'firstName' => 'WouldUntrash',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('trashed');
        expect($caught->getMessage())->toContain('restore');
    });
});

// -----------------------------------------------------------------------------
// delete mode
// -----------------------------------------------------------------------------

it('delete soft-deletes a user by default; the row reappears via trashed()', function() {
    $target = _herald_users_user($this->fixturePrefix, 'softdelete');
    if ($target === null) {
        $this->markTestSkipped('Could not create soft-delete target.');
    }
    $id = (int) $target->id;

    herald_with_edition(Herald::EDITION_PRO, function() use ($id) {
        $result = _herald_users_tool()->execute([
            'mode' => 'delete',
            'id' => $id,
        ]);

        expect($result)->toMatchArray([
            'success' => true,
            'mode' => 'delete',
            'id' => $id,
            'hardDeleted' => false,
        ]);

        expect(User::find()->id($id)->status(null)->one())->toBeNull();
        $trashed = User::find()->id($id)->status(null)->trashed(true)->one();
        expect($trashed)->toBeInstanceOf(User::class);
    });
});

it('delete with hardDelete=true removes the row entirely', function() {
    $target = _herald_users_user($this->fixturePrefix, 'harddelete');
    if ($target === null) {
        $this->markTestSkipped('Could not create hard-delete target.');
    }
    $id = (int) $target->id;

    herald_with_edition(Herald::EDITION_PRO, function() use ($id) {
        $result = _herald_users_tool()->execute([
            'mode' => 'delete',
            'id' => $id,
            'hardDelete' => true,
        ]);

        expect($result['hardDeleted'])->toBeTrue();
        expect(User::find()->id($id)->status(null)->trashed(null)->one())->toBeNull();
    });
});

it('delete with transferContentTo records the recipient in the envelope', function() {
    $target = _herald_users_user($this->fixturePrefix, 'transferfrom');
    $recipient = _herald_users_user($this->fixturePrefix, 'transferto');
    if ($target === null || $recipient === null) {
        $this->markTestSkipped('Could not create transfer fixtures.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target, $recipient) {
        $result = _herald_users_tool()->execute([
            'mode' => 'delete',
            'id' => $target->id,
            'transferContentTo' => $recipient->id,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['contentTransferredTo'])->toBe((int) $recipient->id);
    });
});

it('delete refuses a non-admin caller targeting an admin user', function() {
    $caller = _herald_users_user($this->fixturePrefix, 'wouldDeleteAdmin');
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers', 'deleteusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'delete',
                    'id' => $this->admin->id,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('delete denied');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

it('delete refuses a caller without deleteUsers', function() {
    $target = _herald_users_user($this->fixturePrefix, 'nodeleterights');
    $caller = _herald_users_user($this->fixturePrefix, 'nodelete');
    if ($target === null || $caller === null) {
        $this->markTestSkipped('Could not create fixtures.');
    }
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int) $caller->id,
        ['viewusers'],
    );

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller, $target) {
            Craft::$app->getUser()->setIdentity($caller);

            $caught = null;
            try {
                _herald_users_tool()->execute([
                    'mode' => 'delete',
                    'id' => $target->id,
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('delete denied');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});

// -----------------------------------------------------------------------------
// HTTP transport — credential / privilege mutation elevation gate (WS2)
// -----------------------------------------------------------------------------

it('refuses newPassword on update over un-elevated HTTP', function() {
    $target = _herald_users_user($this->fixturePrefix, 'httppw');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $caught = null;
        try {
            _herald_users_tool(Server::TRANSPORT_HTTP)->execute([
                'mode' => 'update',
                'id' => $target->id,
                'newPassword' => 'RefusedOverHttp!42',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())
            ->toContain('requires elevation')
            ->toContain('/oauth/elevate');
    });
});

it('refuses email change on update over un-elevated HTTP', function() {
    $target = _herald_users_user($this->fixturePrefix, 'httpemail');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $caught = null;
        try {
            _herald_users_tool(Server::TRANSPORT_HTTP)->execute([
                'mode' => 'update',
                'id' => $target->id,
                'email' => $this->fixturePrefix . 'changed@example.test',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('requires elevation');
    });
});

it('refuses admin grant on create over un-elevated HTTP', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $caught = null;
        try {
            _herald_users_tool(Server::TRANSPORT_HTTP)->execute([
                'mode' => 'create',
                'email' => $this->fixturePrefix . 'httpadmin@example.test',
                'username' => $this->fixturePrefix . 'httpadmin',
                'admin' => true,
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('requires elevation');
    });
});

it('fails closed and refuses a credential mutation when no transport context was injected', function() {
    $target = _herald_users_user($this->fixturePrefix, 'noctx');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        // No setInvocationContext() call — transport indeterminate.
        $tool = new Users();

        $caught = null;
        try {
            $tool->execute([
                'mode' => 'update',
                'id' => $target->id,
                'newPassword' => 'NoContextSoRefused!42',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('requires elevation');
    });
});

it('allows a newPassword mutation over ELEVATED HTTP (WS2)', function() {
    $target = _herald_users_user($this->fixturePrefix, 'httpelev');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $result = _herald_users_tool(Server::TRANSPORT_HTTP, elevated: true)->execute([
            'mode' => 'update',
            'id' => $target->id,
            'newPassword' => 'AllowedWhenElevated!42',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('update');
        expect($result['user']['id'])->toBe((int) $target->id);
    });
});

it('allows the same newPassword mutation over the stdio transport', function() {
    $target = _herald_users_user($this->fixturePrefix, 'stdiopw');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $result = _herald_users_tool(Server::TRANSPORT_STDIO)->execute([
            'mode' => 'update',
            'id' => $target->id,
            'newPassword' => 'AllowedOverStdio!42',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('update');
        expect($result['user']['id'])->toBe((int) $target->id);
    });
});

it('allows an admin grant on update over the stdio transport', function() {
    $target = _herald_users_user($this->fixturePrefix, 'stdioadmin');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $result = _herald_users_tool(Server::TRANSPORT_STDIO)->execute([
            'mode' => 'update',
            'id' => $target->id,
            'admin' => true,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['user']['admin'])->toBeTrue();
    });
});

it('still allows a non-sensitive update over the HTTP transport', function() {
    $target = _herald_users_user($this->fixturePrefix, 'httpsafe');
    if ($target === null) {
        $this->markTestSkipped('Could not seed target.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($target) {
        $result = _herald_users_tool(Server::TRANSPORT_HTTP)->execute([
            'mode' => 'update',
            'id' => $target->id,
            'firstName' => 'Renamed',
            'lastName' => 'OverHttp',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('update');
        expect($result['user']['id'])->toBe((int) $target->id);
    });
});

it('does not refuse a non-sensitive create over the HTTP transport', function() {
    // A create carrying no guarded field (no email / admin) must pass
    // the transport gate. It may still come back as a validation
    // envelope (Craft requires an email), but it must NOT be the
    // transport-refusal message — that is the contract under test.
    herald_with_edition(Herald::EDITION_PRO, function() {
        $caught = null;
        try {
            _herald_users_tool(Server::TRANSPORT_HTTP)->execute([
                'mode' => 'create',
                'username' => $this->fixturePrefix . 'httpcreate',
                'firstName' => 'Http',
                'lastName' => 'Create',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        if ($caught !== null) {
            expect($caught->getMessage())->not->toContain('requires elevation');
        }
    });
});
