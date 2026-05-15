<?php

/**
 * =========================================================================
 * `tag` Pro tool tests — Gate 8.3.
 *
 * Admin-only gate. Tests cover:
 *
 *   - Free install: tool absent from the registry.
 *   - Pro install: every mode round-trips against a test tag group.
 *   - `filterFor()` admin gate (admin = visible; non-admin = hidden).
 *   - Defense-in-depth `execute()` re-check (non-admin direct dispatch
 *     refused even though `filterFor` would hide the tool).
 *   - Validation envelope shape on save failures.
 *   - Idempotency cache hit.
 *
 * Fixture strategy: write to whichever tag group the playground carries
 * first; clean up by hard-deleting whatever the test created. Tests
 * skip when no tag group exists.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\Tag as TagElement;
use craft\elements\User;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\content\Tag;
use craftpulse\cortex\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $this->fixturePrefix = '__cortex_tagtest_' . bin2hex(random_bytes(4)) . '_';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = TagElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->title(['like', $this->fixturePrefix . '%'])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _cortex_tag_tool(): Tag
{
    return new Tag();
}

function _cortex_tag_group(): ?\craft\models\TagGroup
{
    $groups = Craft::$app->getTags()->getAllTagGroups();
    return $groups[0] ?? null;
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Cortex::getInstance()->tools->getByName('tag'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Cortex::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('tag');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Tag::shouldRegister())->toBeFalse();

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(Tag::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// filterFor — admin-only gate
// -----------------------------------------------------------------------------

it('filterFor(null) returns true — stdio is trusted', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect(_cortex_tag_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        expect($this->admin)->toBeInstanceOf(User::class);
        expect($this->admin->admin)->toBeTrue();
        expect(_cortex_tag_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for non-admin users', function() {
    $user = new User();
    $user->username = '__cortex_nonadmin_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($user) {
            expect(_cortex_tag_tool()->filterFor($user))->toBeFalse();
        });
    } finally {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_tag_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_tag_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// create — admin happy path
// -----------------------------------------------------------------------------

it('create mode round-trips against a test tag group as admin', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($group) {
        $title = $this->fixturePrefix . 'happy';
        $result = _cortex_tag_tool()->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $title,
        ]);

        expect($result)->toHaveKeys(['success', 'mode', 'tag']);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['tag']['title'])->toBe($title);

        $reloaded = TagElement::find()->id($result['tag']['id'])->status(null)->one();
        expect($reloaded)->toBeInstanceOf(TagElement::class);
        expect($reloaded->title)->toBe($title);
    });
});

it('create mode throws when no group-identifying argument is given', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        _cortex_tag_tool()->execute([
            'mode' => 'create',
            'title' => $this->fixturePrefix . 'nogroup',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// update — admin happy path
// -----------------------------------------------------------------------------

it('update mode mutates an existing tag', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($group) {
        $tool = _cortex_tag_tool();

        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'pre',
        ]);
        $id = $created['tag']['id'];

        $updated = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'title' => $this->fixturePrefix . 'post',
        ]);
        expect($updated['success'])->toBeTrue();
        expect($updated['tag']['title'])->toBe($this->fixturePrefix . 'post');

        $reloaded = TagElement::find()->id($id)->status(null)->one();
        expect($reloaded->title)->toBe($this->fixturePrefix . 'post');
    });
});

// -----------------------------------------------------------------------------
// delete — soft + hard
// -----------------------------------------------------------------------------

it('delete mode soft-deletes by default', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($group) {
        $tool = _cortex_tag_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'soft',
        ]);
        $id = $created['tag']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect($deleted)->toMatchArray([
            'success' => true,
            'mode' => 'delete',
            'id' => $id,
            'hardDeleted' => false,
        ]);

        expect(TagElement::find()->id($id)->status(null)->one())->toBeNull();
        $trashed = TagElement::find()->id($id)->status(null)->trashed(true)->one();
        expect($trashed)->toBeInstanceOf(TagElement::class);
    });
});

it('delete mode with hardDelete=true removes the row entirely', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($group) {
        $tool = _cortex_tag_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'hard',
        ]);
        $id = $created['tag']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id, 'hardDelete' => true]);
        expect($deleted['hardDeleted'])->toBeTrue();

        expect(TagElement::find()->id($id)->status(null)->trashed(null)->one())->toBeNull();
    });
});

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    cortex_with_edition(Cortex::EDITION_PRO, function() use ($group) {
        $tool = _cortex_tag_tool();
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'create',
            'groupHandle' => $group->handle,
            'title' => $this->fixturePrefix . 'idem',
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstId = $first['tag']['id'];
        $firstDateUpdated = $first['tag']['dateUpdated'];

        $second = $tool->execute($args);
        expect($second['tag']['id'])->toBe($firstId);
        expect($second['tag']['dateUpdated'])->toBe($firstDateUpdated);

        $count = (int) TagElement::find()
            ->status(null)
            ->title($this->fixturePrefix . 'idem')
            ->count();
        expect($count)->toBe(1);
    });
});

// -----------------------------------------------------------------------------
// Defense-in-depth: non-admin execute() refusal
// -----------------------------------------------------------------------------

it('execute() refuses a non-admin dispatch even though filterFor would hide the tool', function() {
    $group = _cortex_tag_group();
    if ($group === null) {
        $this->markTestSkipped('No tag groups in the playground.');
    }

    $user = new User();
    $user->username = '__cortex_nonadmin_exec_' . bin2hex(random_bytes(4));
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;

    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user: ' . json_encode($user->getErrors()));
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($group, $user) {
            Craft::$app->getUser()->setIdentity($user);

            try {
                _cortex_tag_tool()->execute([
                    'mode' => 'create',
                    'groupHandle' => $group->handle,
                    'title' => $this->fixturePrefix . 'denied',
                ]);
                $this->fail('Expected ToolException for non-admin dispatch');
            } catch (ToolException $e) {
                expect($e->getMessage())->toContain('permission denied');
                expect($e->getMessage())->toContain('admin');
            }
        });
    } finally {
        Craft::$app->getUser()->setIdentity($this->admin);
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }
});
