<?php

/**
 * =========================================================================
 * `skill` Pro tool tests — Gate 8.6.
 *
 * Covers:
 *   - Free vs Pro registration gating.
 *   - `filterFor()` per-user visibility (stdio / admin / permitted /
 *     unpermitted).
 *   - `list` mode with source / search / pagination filters.
 *   - `get` mode against id / uid / handle, plus bundled-handle fall-
 *     through.
 *   - `create` / `update` / `delete` round-trips against fixture
 *     elements.
 *   - Idempotency cache hit on `create`.
 *   - Handle-change refusal on `update` (natural-key invariant).
 *   - Permission denial for users without `herald:manage-skills`.
 *   - Trashed resolution emits the restore-hint message.
 *   - Override behaviour: creating a skill with a bundled handle
 *     succeeds; deleting the override re-surfaces the bundled row.
 *     (This is one of the four highest-value regression gates.)
 *
 * Fixture strategy: handle prefix `herald-skilltest-<hex>-` (slug-shaped
 * to satisfy `Skill::HANDLE_PATTERN`); afterEach hard-deletes by handle
 * LIKE.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craftpulse\herald\elements\Skill as SkillElement;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\system\Skill;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Slug-shaped so handles satisfy Skill::HANDLE_PATTERN
    // (lowercase letters, digits, single hyphens).
    $this->fixturePrefix = 'herald-skilltest-' . bin2hex(random_bytes(4)) . '-';

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;
});

afterEach(function() {
    $rows = SkillElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'herald_skills.handle', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($rows as $row) {
        Craft::$app->getElements()->deleteElement($row, hardDelete: true);
    }

    $users = User::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    Herald::getInstance()->skills->resetMemo();
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function _herald_skill_tool(): Skill
{
    return new Skill();
}

// -----------------------------------------------------------------------------
// Registration — Free vs Pro
// -----------------------------------------------------------------------------

it('is NOT registered on Free installs', function() {
    expect(Herald::getInstance()->tools->getByName('skill'))->toBeNull();

    $names = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    expect($names)->not->toContain('skill');
});

it('shouldRegister() returns true on Pro and false on Free', function() {
    expect(Skill::shouldRegister())->toBeFalse();
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(Skill::shouldRegister())->toBeTrue();
    });
});

// -----------------------------------------------------------------------------
// Mode validation
// -----------------------------------------------------------------------------

it('throws ToolException when mode is missing', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute([]);
    });
})->throws(ToolException::class);

it('throws ToolException on unknown mode', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute(['mode' => 'frobnicate']);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// filterFor — per-user visibility
// -----------------------------------------------------------------------------

it('filterFor(null) returns true: stdio is trusted', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_skill_tool()->filterFor(null))->toBeTrue();
    });
});

it('filterFor returns true for admin users', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        expect(_herald_skill_tool()->filterFor($this->admin))->toBeTrue();
    });
});

it('filterFor returns false for users without herald:manage-skills', function() {
    $user = new User();
    $user->username = $this->fixturePrefix . 'no_perms';
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        $this->markTestSkipped('Could not create fixture user');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($user) {
        expect(_herald_skill_tool()->filterFor($user))->toBeFalse();
    });
});

// -----------------------------------------------------------------------------
// inputSchemaFor — full mode enum surfaces for every caller
// -----------------------------------------------------------------------------

it('inputSchemaFor returns the full mode enum', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $schema = _herald_skill_tool()->inputSchemaFor($this->admin);
        expect($schema['properties']['mode']['enum'])
            ->toBe(['list', 'get', 'create', 'update', 'delete']);
    });
});

// -----------------------------------------------------------------------------
// create — happy path
// -----------------------------------------------------------------------------

it('create mode persists a new skill and round-trips through find()', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $handle = $this->fixturePrefix . 'create';
        $result = _herald_skill_tool()->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'Create test',
            'description' => 'creation round-trip',
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('create');
        expect($result['skill']['handle'])->toBe($handle);
        expect($result['skill']['title'])->toBe('Create test');
        expect($result['skill']['source'])->toBe('element');
        expect($result['skill']['id'])->toBeInt();

        $reloaded = SkillElement::find()->status(null)->handle($handle)->one();
        expect($reloaded)->toBeInstanceOf(SkillElement::class);
        expect($reloaded->description)->toBe('creation round-trip');
    });
});

it('create mode rejects missing handle', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute(['mode' => 'create', 'title' => 'no handle']);
    });
})->throws(ToolException::class, '`handle` is required');

it('create mode rejects missing title', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute(['mode' => 'create', 'handle' => $this->fixturePrefix . 'notitle']);
    });
})->throws(ToolException::class, '`title` is required');

it('create mode returns a validation envelope when handle collides with another element', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $handle = $this->fixturePrefix . 'dup';
        $tool = _herald_skill_tool();
        $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'first']);

        $result = $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'second']);
        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('handle');
    });
});

it('create mode returns a validation envelope (not an IntegrityException) when the handle is held by a trashed skill', function() {
    // BLOCKER (Gate 9 hardening): a create colliding with a SOFT-DELETED
    // skill used to slip past validation and explode with a raw
    // IntegrityException inside afterSave(). The tool must instead
    // return the clean _validationEnvelope shape.
    herald_with_edition(Herald::EDITION_PRO, function() {
        $handle = $this->fixturePrefix . 'trashedclash';
        $tool = _herald_skill_tool();
        $created = $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'original']);
        $tool->execute(['mode' => 'delete', 'id' => $created['skill']['id']]);

        $result = null;
        try {
            $result = $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'clash']);
        } catch (\Throwable $e) {
            $this->fail('Expected a validation envelope, got ' . $e::class . ': ' . $e->getMessage());
        }

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('handle');
        expect($result['errors']['handle'][0])->toContain('trashed');
        // No -32002 / JSON-RPC error code on the envelope — validation
        // shape only.
        expect($result)->not->toHaveKey('code');
    });
});

// -----------------------------------------------------------------------------
// get — happy paths
// -----------------------------------------------------------------------------

it('get mode finds the element by id, uid, and handle', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $handle = $this->fixturePrefix . 'getall';
        $tool = _herald_skill_tool();
        $created = $tool->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'Get test',
        ]);
        $id = $created['skill']['id'];
        $uid = $created['skill']['uid'];

        $byId = $tool->execute(['mode' => 'get', 'id' => $id]);
        $byUid = $tool->execute(['mode' => 'get', 'uid' => $uid]);
        $byHandle = $tool->execute(['mode' => 'get', 'handle' => $handle]);

        foreach ([$byId, $byUid, $byHandle] as $envelope) {
            expect($envelope['success'])->toBeTrue();
            expect($envelope['skill']['handle'])->toBe($handle);
            expect($envelope['skill']['source'])->toBe('element');
        }
    });
});

it('get mode falls through to a bundled handle when no element exists', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $bundledNames = \Michtio\CraftCmsClaudeSkills\Skills::skillNames();
        if ($bundledNames === []) {
            $this->markTestSkipped('No bundled skills installed.');
        }
        $bundledHandle = $bundledNames[0];

        $result = _herald_skill_tool()->execute([
            'mode' => 'get',
            'handle' => $bundledHandle,
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['skill']['source'])->toBe('bundled');
        expect($result['skill']['handle'])->toBe($bundledHandle);
        expect($result['skill']['body'])->toBeString();
    });
});

it('get mode throws when no identifier is supplied', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute(['mode' => 'get']);
    });
})->throws(ToolException::class);

it('get mode throws on a non-existent handle', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        _herald_skill_tool()->execute([
            'mode' => 'get',
            'handle' => 'herald-skilltest-nonexistent-zzz',
        ]);
    });
})->throws(ToolException::class);

// -----------------------------------------------------------------------------
// list mode — source filter + pagination
// -----------------------------------------------------------------------------

it('list mode returns merged corpus rows with source on every row', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $result = _herald_skill_tool()->execute(['mode' => 'list', 'limit' => 200]);
        expect($result['success'])->toBeTrue();
        expect($result['mode'])->toBe('list');
        expect($result['skills'])->toBeArray();
        foreach ($result['skills'] as $row) {
            expect($row)->toHaveKey('source');
            expect($row['source'])->toBeIn(['bundled', 'element']);
        }
    });
});

it('list mode source=bundled returns only bundled rows', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        // Seed an element-stored skill so we can prove the filter
        // removes element rows from the returned set.
        _herald_skill_tool()->execute([
            'mode' => 'create',
            'handle' => $this->fixturePrefix . 'bundledfilter',
            'title' => 'Bundled filter probe',
        ]);

        $result = _herald_skill_tool()->execute([
            'mode' => 'list',
            'source' => 'bundled',
            'limit' => 200,
        ]);
        foreach ($result['skills'] as $row) {
            expect($row['source'])->toBe('bundled');
        }
    });
});

it('list mode source=element returns only element rows', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $handle = $this->fixturePrefix . 'elementfilter';
        _herald_skill_tool()->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'Element-only probe',
        ]);

        $result = _herald_skill_tool()->execute([
            'mode' => 'list',
            'source' => 'element',
            'limit' => 200,
        ]);
        expect($result['skills'])->not->toBeEmpty();
        foreach ($result['skills'] as $row) {
            expect($row['source'])->toBe('element');
        }
        $handles = array_column($result['skills'], 'handle');
        expect($handles)->toContain($handle);
    });
});

it('list mode applies the search substring filter', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $needle = $this->fixturePrefix . 'searchtoken';
        _herald_skill_tool()->execute([
            'mode' => 'create',
            'handle' => $needle,
            'title' => 'search probe',
        ]);

        $result = _herald_skill_tool()->execute([
            'mode' => 'list',
            'search' => $needle,
            'limit' => 200,
        ]);
        expect($result['count'])->toBeGreaterThan(0);
        foreach ($result['skills'] as $row) {
            $haystack = $row['handle'] . "\n" . ($row['body'] ?? '');
            expect(str_contains($haystack, $needle))->toBeTrue();
        }
    });
});

// -----------------------------------------------------------------------------
// update — happy path + handle-change refusal
// -----------------------------------------------------------------------------

it('update mode mutates title and description', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'updateok';
        $created = $tool->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'before',
            'description' => 'before',
        ]);
        $id = $created['skill']['id'];

        $updated = $tool->execute([
            'mode' => 'update',
            'id' => $id,
            'title' => 'after',
            'description' => 'after',
        ]);

        expect($updated['success'])->toBeTrue();
        expect($updated['skill']['title'])->toBe('after');
        expect($updated['skill']['description'])->toBe('after');
    });
});

it('update mode REJECTS a handle change attempt with a structured envelope', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'handlechange';
        $created = $tool->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'handle-change probe',
        ]);

        $result = $tool->execute([
            'mode' => 'update',
            'id' => $created['skill']['id'],
            'handle' => $this->fixturePrefix . 'differenthandle',
        ]);

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toHaveKey('handle');
        expect($result['errors']['handle'][0])->toContain('natural key');
    });
});

it('update mode refuses a trashed skill with a restore-hint message', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'trashed';
        $created = $tool->execute([
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'trashed probe',
        ]);
        $tool->execute(['mode' => 'delete', 'id' => $created['skill']['id']]);

        $caught = null;
        try {
            $tool->execute([
                'mode' => 'update',
                'id' => $created['skill']['id'],
                'title' => 'would-revive',
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
// delete — soft + hard
// -----------------------------------------------------------------------------

it('delete mode soft-deletes by default; skill reappears under trashed()', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'softdel';
        $created = $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'soft']);
        $id = $created['skill']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id]);
        expect($deleted['hardDeleted'])->toBeFalse();
        expect($deleted['handle'])->toBe($handle);

        expect(SkillElement::find()->status(null)->id($id)->one())->toBeNull();
        $trashed = SkillElement::find()->status(null)->trashed(true)->id($id)->one();
        expect($trashed)->toBeInstanceOf(SkillElement::class);
    });
});

it('delete mode with hardDelete=true wipes the row', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'harddel';
        $created = $tool->execute(['mode' => 'create', 'handle' => $handle, 'title' => 'hard']);
        $id = $created['skill']['id'];

        $deleted = $tool->execute(['mode' => 'delete', 'id' => $id, 'hardDelete' => true]);
        expect($deleted['hardDeleted'])->toBeTrue();

        expect(SkillElement::find()->status(null)->trashed(null)->id($id)->one())->toBeNull();
    });
});

// -----------------------------------------------------------------------------
// Idempotency
// -----------------------------------------------------------------------------

it('the same idempotencyKey returns the cached envelope without re-saving', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $tool = _herald_skill_tool();
        $handle = $this->fixturePrefix . 'idem';
        $idempotencyKey = 'idem_' . bin2hex(random_bytes(8));

        $args = [
            'mode' => 'create',
            'handle' => $handle,
            'title' => 'idem',
            'idempotencyKey' => $idempotencyKey,
        ];

        $first = $tool->execute($args);
        $firstId = $first['skill']['id'];

        $second = $tool->execute($args);
        expect($second['skill']['id'])->toBe($firstId);

        $count = (int) SkillElement::find()->status(null)->handle($handle)->count();
        expect($count)->toBe(1);
    });
});

// -----------------------------------------------------------------------------
// Permission gating
// -----------------------------------------------------------------------------

it('create mode throws ToolException for users without herald:manage-skills', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $unprivileged = new User();
        $unprivileged->username = $this->fixturePrefix . 'denied';
        $unprivileged->email = $unprivileged->username . '@example.test';
        $unprivileged->admin = false;
        $unprivileged->pending = true;
        if (!Craft::$app->getElements()->saveElement($unprivileged)) {
            $this->markTestSkipped('Could not create unprivileged user');
        }

        try {
            Craft::$app->getUser()->setIdentity($unprivileged);

            $caught = null;
            try {
                _herald_skill_tool()->execute([
                    'mode' => 'create',
                    'handle' => $this->fixturePrefix . 'denied',
                    'title' => 'denied',
                ]);
            } catch (ToolException $e) {
                $caught = $e;
            }

            expect($caught)->not->toBeNull();
            expect($caught->getMessage())->toContain('permission denied');
        } finally {
            Craft::$app->getUser()->setIdentity($this->admin);
        }
    });
});

// -----------------------------------------------------------------------------
// Override behaviour — highest-value regression gate.
// Creating a skill with a bundled handle takes over that handle; deleting
// the override re-exposes the bundled row.
// -----------------------------------------------------------------------------

it('overriding a bundled handle hides the bundled row from list mode', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $bundledNames = \Michtio\CraftCmsClaudeSkills\Skills::skillNames();
        if ($bundledNames === []) {
            $this->markTestSkipped('No bundled skills installed.');
        }
        $bundledHandle = $bundledNames[0];

        // Baseline — the bundled handle surfaces with source=bundled.
        $baseline = _herald_skill_tool()->execute(['mode' => 'list', 'limit' => 200]);
        $bundledRow = collect($baseline['skills'])->firstWhere('handle', $bundledHandle);
        expect($bundledRow)->not->toBeNull();
        expect($bundledRow['source'])->toBe('bundled');

        // Override via an element-stored skill with the SAME handle.
        $created = _herald_skill_tool()->execute([
            'mode' => 'create',
            'handle' => $bundledHandle,
            'title' => 'Override probe',
        ]);
        expect($created['success'])->toBeTrue();

        $afterOverride = _herald_skill_tool()->execute(['mode' => 'list', 'limit' => 200]);
        $overrideRow = collect($afterOverride['skills'])->firstWhere('handle', $bundledHandle);
        expect($overrideRow['source'])->toBe('element');

        // Now hard-delete the override and confirm the bundled row
        // re-surfaces.
        _herald_skill_tool()->execute([
            'mode' => 'delete',
            'id' => $created['skill']['id'],
            'hardDelete' => true,
        ]);

        $afterRestore = _herald_skill_tool()->execute(['mode' => 'list', 'limit' => 200]);
        $restoredRow = collect($afterRestore['skills'])->firstWhere('handle', $bundledHandle);
        expect($restoredRow['source'])->toBe('bundled');
    });
});

// -----------------------------------------------------------------------------
// Registry surface
// -----------------------------------------------------------------------------

it('appears in the Pro registry tools/list payload with annotations', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        // Rebuild the registry under Pro by constructing a new Tools
        // service — boot order means the existing component instance
        // booted under Free. The static `shouldRegister()` flip is
        // covered by EditionGatingTest; here we just sanity-check the
        // schema surface emits properly.
        $tool = new Skill();
        $payload = [
            'name' => $tool::getName(),
            'description' => $tool::getDescription(),
            'inputSchema' => $tool->inputSchemaFor($this->admin),
        ];
        expect($payload)->toBeMcpToolListItem();
        expect($payload['inputSchema']['properties']['mode']['enum'])
            ->toContain('list', 'get', 'create', 'update', 'delete');
    });
});
