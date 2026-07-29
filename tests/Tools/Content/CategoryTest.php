<?php

/**
 * =========================================================================
 * `category` Pro tool tests — Gate 8.3.
 *
 * Mirrors `EntryTest`'s structure adapted to per-category-group
 * permissions. Tests cover:
 *
 *   - Free install: tool absent from the registry.
 *   - Pro install: every mode round-trips against a test group.
 *   - Per-user `filterFor()` + `inputSchemaFor()` surface.
 *   - Per-group permission denial path (`-32002`).
 *   - Validation envelope shape on save failures.
 *   - Cross-group `parentId` rejection via validation envelope.
 *   - Trashed-on-update refusal with mode-aware hint.
 *   - Idempotency cache hit + cross-user isolation.
 *
 * Fixture strategy: write to whichever category group the playground
 * carries first; clean up by hard-deleting whatever the test created.
 * Tests skip when no category group exists.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Category as CategoryElement;
use craft\elements\User;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\content\Category;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__herald_categorytest_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = CategoryElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'elements_sites.title', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Direct instantiation sidesteps the boot-time registry gate.
 */
function _herald_category_tool(): Category
{
    return new Category();
}

/**
 * Pick the first category group available in the playground. Returns
 * null when none exist.
 */
function _herald_category_group(): ?\craft\models\CategoryGroup
{
    $groups = Craft::$app->getCategories()->getAllGroups();
    return $groups[0] ?? null;
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Herald::getInstance()->tools->getByName('category'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('category');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Category::shouldRegister())->toBeFalse();

    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(Category::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_category_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_category_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true: stdio is trusted', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_category_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect($this->admin)->toBeInstanceOf(User::class);
        expect(_herald_category_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users with no category permissions on any group', function() {
    $user = new User();
    $user->username = '__herald_no_perms_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
            expect(_herald_category_tool()->filterFor($user))->toBeFalse();
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// inputSchemaFor — mode-enum filtering
// -----------------------------------------------------------------------------

it('inputSchemaFor returns the full mode enum for admins', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_category_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])->toBe(['create', 'update', 'delete']);
    });
});

it('inputSchemaFor returns the full mode enum for stdio (null user)', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_category_tool()->inputSchemaFor(null);
        expect($schema['properties']['mode']['enum'])->toBe(['create', 'update', 'delete']);
    });
});

// -----------------------------------------------------------------------------
// create — happy path
// -----------------------------------------------------------------------------

it('create mode round-trips against a test group as admin', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $title = $this->fixturePrefix . 'happy';
        $result = _herald_category_tool()->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $title,
        ]);

        expect($result)->toHaveKeys(['success', 'mode', 'category']);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['category']['title'])->toBe($title);
        expect($result['category']['id'])->toBeInt();

        $reloaded = CategoryElement::find()->id($result['category']['id'])->status(null)->one();
        expect($reloaded)->toBeInstanceOf(CategoryElement::class);
        expect($reloaded->title)->toBe($title);
    });
});

// -----------------------------------------------------------------------------
// create — argument validation
// -----------------------------------------------------------------------------

it('create mode throws when no group-identifying argument is given', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_category_tool()->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'nogroup',
        ]);
    });
})->throws(ToolException::class);

it('create mode throws when groupHandle does not resolve', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_category_tool()->execute([
            'mode' => 'create',
            'groupHandle' => '__not_a_real_group_handle_x9z__',
            'title' => $this->fixturePrefix . 'bogus',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// create — cross-group parent validation
// -----------------------------------------------------------------------------

it('create mode rejects a parent from a different group via validation envelope', function() {
    $groups = Craft::$app->getCategories()->getAllGroups();
    if (count($groups) < 2) {
        $this->markTestSkipped('Need at least two category groups in the playground to exercise cross-group parent.');
    }

    [$groupA, $groupB] = $groups;

    herald_with_edition(Herald::EDITION_PRO, function() use ($groupA, $groupB) {
        $tool = _herald_category_tool();

        // Create a parent in group B.
        $parent = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $groupB->handle,
            'title' => $this->fixturePrefix . 'parent-B',
        ]);
        expect($parent['success'])->toBeTrue();
        $parentId = $parent['category']['id'];

        // Now try to create a child in group A with a parent from B.
        $result = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $groupA->handle,
            'title' => $this->fixturePrefix . 'child-A',
            'parentId' => $parentId,
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['mode'])->toBe('create');
        expect($result['errors'])->toHaveKey('parentId');
    });
});

// -----------------------------------------------------------------------------
// update — happy path
// -----------------------------------------------------------------------------

it('update mode mutates an existing category and persists changes', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $tool = _herald_category_tool();

        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'pre-update',
        ]);
        $id = $created['category']['id'];

        $updated = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'title' => $this->fixturePrefix . 'post-update',
        ]);

        expect($updated['success'])->toBeTrue();
        expect($updated['mode'])->toBe('update');
        expect($updated['category']['title'])->toBe($this->fixturePrefix . 'post-update');

        $reloaded = CategoryElement::find()->id($id)->status(null)->one();
        expect($reloaded->title)->toBe($this->fixturePrefix . 'post-update');
    });
});

it('update mode throws when the category does not exist', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_category_tool()->execute([
            'mode' => 'update',
            'id' => 99999999,
            'title' => $this->fixturePrefix . 'phantom',
        ]);
    });
})->throws(ToolException::class);

it('update mode refuses a trashed category with a hardDelete-hint message', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $tool = _herald_category_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'trashed-update',
        ]);
        $id = $created['category']['id'];

        $tool->execute(['mode' => 'delete', 'id' => $id]);

        $caught = null;
        try {
            $tool->execute([
                'mode' => 'update',
                'id' => $id,
                'title' => $this->fixturePrefix . 'would-untrash',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        expect($caught->getMessage())->toContain('is trashed');
        expect($caught->getMessage())->toContain('hardDelete=true');

        $stillTrashed = CategoryElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($stillTrashed)->toBeInstanceOf(CategoryElement::class);
        expect($stillTrashed->title)->toBe($this->fixturePrefix . 'trashed-update');
    });
});

// -----------------------------------------------------------------------------
// delete — soft + hard
// -----------------------------------------------------------------------------

it('delete mode soft-deletes by default; category reappears with trashed()', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $tool = _herald_category_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'soft',
        ]);
        $id = $created['category']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect($deleted)->toMatchArray([
            'success' => true,
            'mode' => 'delete',
            'id' => $id,
            'hardDeleted' => false,
        ]);

        expect(CategoryElement::find()->id($id)->status(null)->one())->toBeNull();
        $trashed = CategoryElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($trashed)->toBeInstanceOf(CategoryElement::class);
    });
});

it('delete mode with hardDelete=true removes the row entirely', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $tool = _herald_category_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'hard',
        ]);
        $id = $created['category']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id, 'hardDelete' => true]);
        expect($deleted['hardDeleted'])->toBeTrue();

        expect(CategoryElement::find()->id($id)->status(null)->trashed(null)->one())->toBeNull();
    });
});

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group) {
        $tool = _herald_category_tool();
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'idem',
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstId = $first['category']['id'];
        $firstDateUpdated = $first['category']['dateUpdated'];

        $second = $tool->execute($args);
        expect($second['category']['id'])->toBe($firstId);
        expect($second['category']['dateUpdated'])->toBe($firstDateUpdated);

        $count = (int) CategoryElement::find()
            ->status(null)
            ->title($this->fixturePrefix . 'idem')
            ->count();
        expect($count)->toBe(1);
    });
});

// -----------------------------------------------------------------------------
// Permission gating
// -----------------------------------------------------------------------------

it('create mode throws ToolException for a user without saveCategories permission', function() {
    $group = _herald_category_group();
    if ($group === null) {
        $this->markTestSkipped('No category groups in the playground.');
    }

    $user = new User();
    $user->username = '__herald_noperm_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        herald_with_edition(Herald::EDITION_PRO, function() use ($group, $user) {
            Craft::$app->getUser()->setIdentity($user);

            try {
                _herald_category_tool()->execute([
                    'mode' => 'create',
                    'groupHandle' => $group->handle,
                    'title' => $this->fixturePrefix . 'denied',
                ]);
                $this->fail('Expected ToolException for non-permitted user');
            } catch (ToolException $e) {
                expect($e->getMessage())->toContain('permission denied');
                expect($e->getMessage())->toContain("saveCategories:{$group->uid}");
            }
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});
