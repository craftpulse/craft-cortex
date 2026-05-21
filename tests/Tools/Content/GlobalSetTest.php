<?php

/**
 * =========================================================================
 * `global_set` Pro tool tests — Gate 8.3.
 *
 * Tests cover:
 *
 *   - Free install: tool absent from the registry.
 *   - Pro install: `update` mode round-trips against a test global set.
 *   - Per-user `filterFor()` (visible only when user has
 *     `editGlobalSet:` on at least one set).
 *   - Lookup by `id`, `uid`, AND `handle` all work.
 *   - Permission denial path (`-32002`).
 *   - Idempotency cache hit.
 *
 * Fixture strategy: the first global set in the playground is the
 * target. Field values are NOT mutated by the happy-path test — we
 * only assert the envelope shape. (Mutating an arbitrary set could
 * touch live playground content; the schema-validation test feeds
 * `fields: {}` so no real change lands.)
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\GlobalSet as GlobalSetElement;
use craft\elements\User;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\content\GlobalSet;
use craftpulse\cortex\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _cortex_global_set_tool(): GlobalSet
{
    return new GlobalSet();
}

function _cortex_first_global_set(): ?GlobalSetElement
{
    $sets = Craft::$app->getGlobals()->getAllSets();
    return $sets[0] ?? null;
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Cortex::getInstance()->tools->getByName('global_set'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Cortex::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('global_set');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(GlobalSet::shouldRegister())->toBeFalse();

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(GlobalSet::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException on unknown mode', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_global_set_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

it('accepts an omitted mode and defaults to update', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($set) {
        $result = _cortex_global_set_tool()->execute([
            'handle' => $set->handle,
            'fields' => (object) [],
        ]);
        expect($result['mode'])->toBe('update');
    });
});

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true — stdio is trusted', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(_cortex_global_set_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(_cortex_global_set_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users with no editGlobalSet permission on any set', function() {
    if (_cortex_first_global_set() === null) {
        $this->markTestSkipped('No global sets in the playground — filterFor() needs at least one set to evaluate.');
    }

    $user = new User();
    $user->username = '__cortex_noglob_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($user) {
            expect(_cortex_global_set_tool()->filterFor($user))->toBeFalse();
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// update — happy path
// -----------------------------------------------------------------------------

it('update mode resolves a set by handle and returns the success envelope', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($set) {
        $result = _cortex_global_set_tool()->execute([
            'mode' => 'update',
            'handle' => $set->handle,
            'fields' => (object) [],
        ]);
        expect($result)->toHaveKeys(['success', 'mode', 'globalSet']);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('update');
        expect($result['globalSet']['uid'])->toBe($set->uid);
    });
});

it('update mode resolves a set by id', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($set) {
        $result = _cortex_global_set_tool()->execute([
            'mode' => 'update',
            'id' => $set->id,
            'fields' => (object) [],
        ]);
        expect($result['globalSet']['id'])->toBe($set->id);
    });
});

it('update mode resolves a set by uid', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($set) {
        $result = _cortex_global_set_tool()->execute([
            'mode' => 'update',
            'uid' => $set->uid,
            'fields' => (object) [],
        ]);
        expect($result['globalSet']['uid'])->toBe($set->uid);
    });
});

it('update mode throws when no identifier is supplied', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_global_set_tool()->execute([
            'mode' => 'update',
            'fields' => (object) [],
        ]);
    });
})->throws(ToolException::class);

it('update mode throws when handle does not resolve', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_global_set_tool()->execute([
            'mode' => 'update',
            'handle' => '__cortex_no_such_global_set__',
            'fields' => (object) [],
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($set) {
        $tool = _cortex_global_set_tool();
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'update',
            'handle' => $set->handle,
            'fields' => (object) [],
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstDateUpdated = $first['globalSet']['dateUpdated'];

        $second = $tool->execute($args);
        expect($second['globalSet']['dateUpdated'])->toBe($firstDateUpdated);
    });
});

// -----------------------------------------------------------------------------
// Permission gating
// -----------------------------------------------------------------------------

it('update mode throws ToolException for a user without editGlobalSet permission', function() {
    $set = _cortex_first_global_set();
    if ($set === null) {
        $this->markTestSkipped('No global sets in the playground.');
    }

    $user = new User();
    $user->username = '__cortex_noperm_glob_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($set, $user) {
            Craft::$app->getUser()->setIdentity($user);

            try {
                _cortex_global_set_tool()->execute([
                    'mode' => 'update',
                    'handle' => $set->handle,
                    'fields' => (object) [],
                ]);
                $this->fail('Expected ToolException for non-permitted user');
            } catch (ToolException $e) {
                expect($e->getMessage())->toContain('permission denied');
                expect($e->getMessage())->toContain("editGlobalSet:{$set->uid}");
            }
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});
