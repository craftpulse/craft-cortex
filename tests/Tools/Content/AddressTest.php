<?php

/**
 * =========================================================================
 * `address` Pro tool tests — Gate 8.4.
 *
 * Mirrors `CategoryTest`'s structure adapted to per-owner permissions
 * via Craft's `Elements::canSave / canView / canDelete`. Tests cover:
 *
 *   - Free install: tool absent from the registry.
 *   - Pro install: shouldRegister gate flips correctly.
 *   - Mode validation: missing / unknown mode → ToolException.
 *   - Per-user `filterFor()` + `inputSchemaFor()` surface.
 *   - Round-trip create / get / update / delete / list against a test
 *     user. Skip when no fixture user is available.
 *   - Argument validation: missing ownerId, bad ownerType, bad
 *     countryCode (validation envelope), ownership change attempts.
 *   - Idempotency cache hit.
 *   - Trashed-on-update refusal with hint message.
 *   - Permission denial for users without `editUsers`.
 *
 * Fixture strategy: create a throwaway user for round-trips, then
 * hard-delete the user (which cascades to their addresses). Address
 * rows themselves are also hard-deleted in `afterEach` keyed by the
 * test-run prefix, defense in depth.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Address as AddressElement;
use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\content\Address;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_addresstest_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    // Hard-delete any addresses we created. Match on addressLine1
    // because that's the field tests stamp with the prefix. AddressQuery's
    // typed `addressLine1()` filter accepts only a single string, so
    // we drop to `andWhere` for the LIKE-pattern path.
    $rows = AddressElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'addresses.addressLine1', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }

    // Hard-delete any throwaway users this test created (cascades to
    // their addresses via Craft's normal delete chain).
    $users = User::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Direct instantiation sidesteps the boot-time registry gate.
 */
function _herald_address_tool(): Address
{
    return new Address();
}

/**
 * Create a throwaway user for address round-trips. Returns null when
 * user creation fails (the test marks itself skipped).
 */
function _herald_address_user(string $prefix): ?User
{
    $user = new User();
    $user->username = $prefix . 'owner';
    $user->email = $user->username . '@example.test';
    $user->firstName = 'Address';
    $user->lastName = 'Owner';
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        return null;
    }

    return $user;
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Herald::getInstance()->tools->getByName('address'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('address');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Address::shouldRegister())->toBeFalse();

    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(Address::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_address_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_address_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true: stdio is trusted', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_address_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect($this->admin)->toBeInstanceOf(User::class);
        expect(_herald_address_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users without editUsers permission', function() {
    $user = new User();
    $user->username = $this->fixturePrefix . 'no_perms';
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
        expect(_herald_address_tool()->filterFor($user))->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — mode-enum surface
// -----------------------------------------------------------------------------

it('inputSchemaFor returns the full mode enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_address_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])
            ->toBe(['list', 'get', 'create', 'update', 'delete']);
    });
});

it('inputSchemaFor returns the full mode enum for stdio (null user)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_address_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])
            ->toBe(['list', 'get', 'create', 'update', 'delete']);
    });
});

// -----------------------------------------------------------------------------
// create — happy path
// -----------------------------------------------------------------------------

it('create mode round-trips against a test user as admin', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for address round-trip.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $line1 = $this->fixturePrefix . '100 Test Street';
        $result = _herald_address_tool()->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $line1,
            'locality' => 'Brooklyn',
            'administrativeArea' => 'NY',
            'postalCode' => '11201',
        ]);

        expect($result)->toHaveKeys(['success', 'mode', 'address']);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['address']['id'])->toBeInt();

        $reloaded = AddressElement::find()->id($result['address']['id'])->status(null)->one();
        expect($reloaded)->toBeInstanceOf(AddressElement::class);
        expect($reloaded->addressLine1)->toBe($line1);
        expect($reloaded->countryCode)->toBe('US');
    });
});

// -----------------------------------------------------------------------------
// create — argument validation
// -----------------------------------------------------------------------------

it('create mode throws when ownerId is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_address_tool()->execute([
            'mode' => 'create',
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'noowner',
        ]);
    });
})->throws(ToolException::class);

it('create mode throws when ownerType is not user', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $caught = null;
        try {
            _herald_address_tool()->execute([
                'mode' => 'create',
                'ownerId' => $this->admin->id,
                'ownerType' => 'commerce-customer',
                'countryCode' => 'US',
                'addressLine1' => $this->fixturePrefix . 'commerce',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }
        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('commerce-customer');
        expect($caught->getMessage())->toContain('not supported');
    });
});

// -----------------------------------------------------------------------------
// country code validation — validation envelope, not thrown
// -----------------------------------------------------------------------------

it('country code validation surfaces in the validation envelope', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for country-code validation.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $result = _herald_address_tool()->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'XX',
            'addressLine1' => $this->fixturePrefix . 'badcountry',
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['mode'])->toBe('create');
        expect($result['errors'])->toHaveKey('countryCode');
    });
});

// -----------------------------------------------------------------------------
// update — happy path
// -----------------------------------------------------------------------------

it('update mode mutates an existing address and persists changes', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for update round-trip.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();

        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'pre-update',
        ]);
        $id = $created['address']['id'];

        $updated = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'addressLine1' => $this->fixturePrefix . 'post-update',
        ]);

        expect($updated['success'])->toBeTrue();
        expect($updated['mode'])->toBe('update');

        // addressLine1 is a native attribute, not surfaced by
        // ElementSerializer (which emits identity headers + custom
        // fields only). Round-trip the change through a reload.
        $reloaded = AddressElement::find()->id($id)->status(null)->one();
        expect($reloaded->addressLine1)->toBe($this->fixturePrefix . 'post-update');
    });
});

// -----------------------------------------------------------------------------
// update — ownership change rejected
// -----------------------------------------------------------------------------

it('update mode rejects ownerId changes via validation envelope', function() {
    $ownerA = _herald_address_user($this->fixturePrefix . 'A_');
    $ownerB = _herald_address_user($this->fixturePrefix . 'B_');
    if ($ownerA === null || $ownerB === null) {
        $this->markTestSkipped('Could not create fixture users for ownership-change test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($ownerA, $ownerB) {
        $tool = _herald_address_tool();

        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $ownerA->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'ownership',
        ]);
        $id = $created['address']['id'];

        $result = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'ownerId' => $ownerB->id,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('ownerId');
    });
});

// -----------------------------------------------------------------------------
// update — trashed refusal
// -----------------------------------------------------------------------------

it('update mode refuses a trashed address with a hardDelete-hint message', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for trashed-update test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'trashed-update',
        ]);
        $id = $created['address']['id'];

        $tool->execute(['mode' => 'delete', 'id' => $id]);

        $caught = null;
        try {
            $tool->execute([
                'mode' => 'update',
                'id' => $id,
                'addressLine1' => $this->fixturePrefix . 'would-untrash',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('is trashed');
        expect($caught->getMessage())->toContain('hardDelete=true');
    });
});

// -----------------------------------------------------------------------------
// get — happy path
// -----------------------------------------------------------------------------

it('get mode returns the serialised address', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for get test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'BE',
            'addressLine1' => $this->fixturePrefix . 'get',
            'locality' => 'Antwerp',
        ]);
        $id = $created['address']['id'];

        $result = $tool->execute([
            'mode' => 'get',
            'id' => $id,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('get');
        expect($result['address']['id'])->toBe($id);
        // addressLine1 isn't in the serializer's identity headers —
        // confirm the round-trip via direct reload instead.
        $reloaded = AddressElement::find()->id($id)->status(null)->one();
        expect($reloaded->addressLine1)->toBe($this->fixturePrefix . 'get');
    });
});

// -----------------------------------------------------------------------------
// list — happy path
// -----------------------------------------------------------------------------

it('list mode returns user-owned addresses', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for list test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();

        // Seed two addresses on the same owner.
        $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'list-1',
        ]);
        $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'BE',
            'addressLine1' => $this->fixturePrefix . 'list-2',
        ]);

        $result = $tool->execute([
            'mode' => 'list',
            'ownerId' => $owner->id,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('list');
        expect($result['addresses'])->toBeArray();
        expect($result['count'])->toBeGreaterThanOrEqual(2);
    });
});

it('list mode throws when ownerId is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_address_tool()->execute([
            'mode' => 'list',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// delete — soft + hard
// -----------------------------------------------------------------------------

it('delete mode soft-deletes by default; address reappears with trashed()', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for soft-delete test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'soft',
        ]);
        $id = $created['address']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect($deleted)->toMatchArray([
            'success' => true,
            'mode' => 'delete',
            'id' => $id,
            'hardDeleted' => false,
        ]);

        expect(AddressElement::find()->id($id)->status(null)->one())->toBeNull();
        $trashed = AddressElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($trashed)->toBeInstanceOf(AddressElement::class);
    });
});

it('delete mode with hardDelete=true removes the row entirely', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for hard-delete test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'hard',
        ]);
        $id = $created['address']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id, 'hardDelete' => true]);
        expect($deleted['hardDeleted'])->toBeTrue();

        expect(AddressElement::find()->id($id)->status(null)->trashed(null)->one())->toBeNull();
    });
});

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for idempotency test.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($owner) {
        $tool = _herald_address_tool();
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'label',
            'ownerId' => $owner->id,
            'countryCode' => 'US',
            'addressLine1' => $this->fixturePrefix . 'idem',
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstId = $first['address']['id'];

        $second = $tool->execute($args);
        expect($second['address']['id'])->toBe($firstId);

        $count = (int) AddressElement::find()
            ->status(null)
            ->ownerId($owner->id)
            ->addressLine1($this->fixturePrefix . 'idem')
            ->count();
        expect($count)->toBe(1);
    });
});

// -----------------------------------------------------------------------------
// Permission gating
// -----------------------------------------------------------------------------

it('create mode throws ToolException for a user without editUsers permission', function() {
    $owner = _herald_address_user($this->fixturePrefix);
    if ($owner === null) {
        $this->markTestSkipped('Could not create fixture user for permission-denied test.');
    }

    $unprivileged = new User();
    $unprivileged->username = $this->fixturePrefix . 'denied';
    $unprivileged->email = $unprivileged->username . '@example.test';
    $unprivileged->admin = false;
    $unprivileged->pending = true;

    if (!Craft::$app->getElements()->saveElement($unprivileged)) {
        $this->markTestSkipped('Could not create unprivileged user: ' . json_encode($unprivileged->getErrors()));
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($owner, $unprivileged) {
            Craft::$app->getUser()->setIdentity($unprivileged);

            $caught = null;
            try {
                _herald_address_tool()->execute([
                    'mode' => 'create',
                    'ownerId' => $owner->id,
                    'countryCode' => 'US',
                    'addressLine1' => $this->fixturePrefix . 'denied',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('permission denied');
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
    }
});
