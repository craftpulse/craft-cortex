<?php

/**
 * =========================================================================
 * Cross-tool permission boundary invariants — Gate 8.10.
 *
 * Locks the uniform contract across the Pro write surface: when
 * `_assertPermission()` denies a non-admin caller, every Pro tool
 *
 *   1. Raises `ToolException` with a message matching the canonical
 *      prefix locked in `docs/plans/gate-8.10.md` decision 3.
 *      `ModeErrorShapeTest` locks the precise full-message shape via
 *      reflection over the protected message builder; this test
 *      locks the end-to-end **dispatch-style** path: the exception
 *      class, the audit-row shape, the kind sentinel.
 *
 *   2. Stdio (`null` user identity) is trusted local and the
 *      permission check skips entirely (locked decision 3 of
 *      `gate-7.md`).
 *
 * Test boundaries — what this test does and does NOT cover:
 *
 *   - **In scope**: cross-tool shape consistency on the denial path.
 *     Same exception class, same audit-row `kind=tool_error`, same
 *     `errorClass=ToolException`.
 *   - **Out of scope**: tool-specific happy paths, output envelopes,
 *     per-mode validation, idempotency, multi-site, drafts/revisions.
 *     Those are per-tool test territory.
 *   - **In scope (post-8.10 follow-up)**: a single canonical wire-
 *     envelope assertion driving one Pro tool through
 *     `Server::dispatch()` via the `herald_with_pro_registry()` helper
 *     (`tests/Pest.php`). The `_toolErrorEnvelope()` shape
 *     (`{content: [{type: 'text', text}], isError: true}` per
 *     `src/mcp/Server.php:1327-1335`) is universal across Pro tools;
 *     one representative case locks the contract for all. Per-tool
 *     cases below stay on the cheaper direct `execute()` + manual
 *     `logCall()` path per locked decision 11 of gate-8.10.md.
 *   - **Out of scope**: per-row routing semantics (BulkEntries-style
 *     `onPermissionDenied=skip/fail`). Per-tool test territory.
 *
 * Per locked decision 5 of `gate-8.10.md` the dataset narrows to ONE
 * representative mode per Pro tool. Streaming-tool rows test the
 * pre-yield throw path (locked decision 10).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User as UserElement;
use craftpulse\herald\Herald;
use craftpulse\herald\records\Invocation as InvocationRecord;
use craftpulse\herald\tools\content\Address;
use craftpulse\herald\tools\content\BulkEntries;
use craftpulse\herald\tools\content\Category;
use craftpulse\herald\tools\content\Entry;
use craftpulse\herald\tools\content\GlobalSet;
use craftpulse\herald\tools\content\ScaffoldEntries;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;
use craftpulse\herald\tools\system\Skill;
use craftpulse\herald\tools\system\Users;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\ToolInterface;

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------

beforeEach(function() {
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    $this->fixturePrefix = '__herald_permbound_' . bin2hex(random_bytes(4)) . '_';
    $this->touchedAuditRowIds = [];
});

afterEach(function() {
    $users = UserElement::find()
        ->status(null)
        ->trashed(null)
        ->site('*')
        ->andWhere(['like', 'users.username', $this->fixturePrefix . '%', false])
        ->all();
    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, hardDelete: true);
    }

    if (!empty($this->touchedAuditRowIds)) {
        InvocationRecord::deleteAll(['id' => $this->touchedAuditRowIds]);
    }

    Craft::$app->getUser()->setIdentity($this->admin);
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Create a non-admin user with the supplied permission strings.
 * Returns the saved User or null on failure.
 *
 * @param string[] $permissions
 */
function _herald_permbound_user(string $prefix, string $label, array $permissions = []): ?UserElement
{
    $user = new UserElement();
    $user->username = $prefix . $label;
    $user->email = $user->username . '@example.test';
    $user->admin = false;
    $user->pending = true;
    if (!Craft::$app->getElements()->saveElement($user)) {
        return null;
    }
    if ($permissions !== []) {
        Craft::$app->getUserPermissions()->saveUserPermissions(
            (int) $user->id,
            array_map('strtolower', $permissions),
        );
    }
    return $user;
}

/**
 * Drive a tool's `execute()` directly + mirror the dispatcher's audit-
 * log write by calling `InvocationLogger::logCall()` with an HTTP
 * context. Returns the captured exception (or null on success) plus
 * the audit-log row.
 *
 * Reference pattern: `tests/Tools/Dev/CraftCommandAdminChangesTest.php`
 * lines 200-253. Per locked decision 11 of `gate-8.10.md`, this is
 * the cheap alternative to dispatcher-path testing for cases where
 * the dispatcher's registry would short-circuit (e.g. Pro tools on a
 * Free-edition playground).
 *
 * @param array<string,mixed> $arguments
 * @return array{exception: ToolException|null, auditRow: InvocationRecord|null}
 */
function _herald_permbound_execute(
    object $testCase,
    ToolInterface $tool,
    array $arguments,
): array {
    $caught = null;
    try {
        $tool->execute($arguments);
    } catch (ToolException $e) {
        $caught = $e;
    }

    // Mirror the dispatcher's audit-log write. HTTP transport context
    // because that's the path that exercises DB writes — the
    // `Invocations::record()` listener short-circuits on non-HTTP
    // transports.
    $beforeIds = InvocationRecord::find()->select('id')->column();
    InvocationLogger::logCall(
        toolName: $tool::getName(),
        arguments: $arguments,
        error: $caught,
        durationMs: 1,
        context: new InvocationContext(
            transport: 'http',
            requestId: 'permbound-' . bin2hex(random_bytes(4)),
            userId: Craft::$app->getUser()->getIdentity()?->id !== null
                ? (int) Craft::$app->getUser()->getIdentity()->id
                : null,
            clientName: 'pest',
        ),
    );

    $afterIds = InvocationRecord::find()->select('id')->column();
    $newIds = array_diff($afterIds, $beforeIds);
    $auditRow = null;
    if ($newIds !== []) {
        $latestId = max($newIds);
        $auditRow = InvocationRecord::findOne(['id' => $latestId]);
        $testCase->touchedAuditRowIds = array_merge($testCase->touchedAuditRowIds, $newIds);
    }

    return ['exception' => $caught, 'auditRow' => $auditRow];
}

// -----------------------------------------------------------------------------
// Permission-denial sweep — one representative mode per Pro tool
// -----------------------------------------------------------------------------

it('Entry denies a non-permitted user with ToolException + tool_error audit row', function() {
    $section = Craft::$app->getEntries()->getSectionByHandle('heroes')
        ?? Craft::$app->getEntries()->getSectionByHandle('minorHeroes');
    if ($section === null) {
        $this->markTestSkipped('No section in playground.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'entry', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new Entry(), [
            'mode' => 'create',
            'sectionHandle' => $section->handle,
            'title' => $this->fixturePrefix . 'denied',
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `create` on section `' . $section->uid . '`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->toolName)->toBe('entry');
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
        expect($result['auditRow']->transport)->toBe('http');
    });
});

it('Category denies a non-permitted user with ToolException + tool_error audit row', function() {
    $group = Craft::$app->getCategories()->getGroupByHandle('factions');
    if ($group === null) {
        $this->markTestSkipped('factions category group not seeded.');
    }
    $category = \craft\elements\Category::find()->groupId($group->id)->status(null)->one();
    if ($category === null) {
        $this->markTestSkipped('No category seeded in factions group.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'cat', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($group, $category, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new Category(), [
            'mode' => 'update',
            'id' => $category->id,
            'title' => $this->fixturePrefix . 'denied',
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `update` on group `' . $group->uid . '`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('GlobalSet denies a non-permitted user with ToolException + tool_error audit row', function() {
    $set = Craft::$app->getGlobals()->getAllSets()[0] ?? null;
    if ($set === null) {
        $this->markTestSkipped('No global sets in playground.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'gset', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($set, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new GlobalSet(), [
            'handle' => $set->handle,
            'fields' => [],
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — global_set update on set `' . $set->uid . '`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('Address denies a non-permitted user with ToolException + tool_error audit row', function() {
    $caller = _herald_permbound_user($this->fixturePrefix, 'addr', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new Address(), [
            'mode' => 'create',
            'ownerId' => (int) $this->admin->id,
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `create` requires `editUsers`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('Users denies a non-permitted user with ToolException + tool_error audit row', function() {
    $caller = _herald_permbound_user($this->fixturePrefix, 'usr', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new Users(), [
            'mode' => 'update',
            'id' => (int) $this->admin->id,
            'username' => 'tampered',
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `update` requires `');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('Skill denies a non-permitted user with ToolException + tool_error audit row', function() {
    $caller = _herald_permbound_user($this->fixturePrefix, 'skl', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new Skill(), [
            'mode' => 'create',
            'name' => $this->fixturePrefix . 'skill',
            'handle' => 'permboundSkill',
            'kind' => 'skill',
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `create` requires `herald:manage-skills`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('ScaffoldEntries denies a non-permitted user with ToolException + tool_error audit row', function() {
    $section = Craft::$app->getEntries()->getSectionByHandle('minorHeroes')
        ?? Craft::$app->getEntries()->getSectionByHandle('heroes');
    if ($section === null) {
        $this->markTestSkipped('No section in playground.');
    }
    $entryType = $section->getEntryTypes()[0] ?? null;
    if ($entryType === null) {
        $this->markTestSkipped('No entry type on section.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'scf', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section, $entryType, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new ScaffoldEntries(), [
            'sectionUid' => $section->uid,
            'entryTypeUid' => $entryType->uid,
            'count' => 1,
            'template' => ['title' => $this->fixturePrefix . 'scaffold'],
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — scaffold_entries requires `saveEntries:' . $section->uid);

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

it('BulkEntries (streaming) denies a non-permitted user with ToolException + tool_error audit row', function() {
    // Streaming-tool boundary: denial fires inside `stream()` (or its
    // `execute()` collapse) BEFORE the first yield. Per locked
    // decision 10 of `gate-8.10.md` the boundary contract is
    // identical to the non-streaming surface.
    $section = Craft::$app->getEntries()->getSectionByHandle('minorHeroes');
    if ($section === null) {
        $this->markTestSkipped('minorHeroes section not seeded.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'blk', []);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_edition(Herald::EDITION_PRO, function() use ($section, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $result = _herald_permbound_execute($this, new BulkEntries(), [
            'mode' => 'set_status',
            'status' => 'disabled',
            'query' => ['section' => $section->handle],
        ]);

        expect($result['exception'])->toBeInstanceOf(ToolException::class);
        expect($result['exception']->getMessage())
            ->toStartWith('permission denied — mode `set_status` requires `saveEntries:' . $section->uid . '`');

        expect($result['auditRow'])->not->toBeNull();
        expect($result['auditRow']->kind)->toBe('tool_error');
        expect($result['auditRow']->errorClass)->toBe(ToolException::class);
    });
});

// -----------------------------------------------------------------------------
// Stdio (null user) — trusted local, permission check skips
// -----------------------------------------------------------------------------

it('stdio (null user) does not raise the permission-denial path for any Pro tool', function() {
    // Single sweep across the same Pro tools the denial-shape sweep
    // exercises. The contract is: when identity is null, every
    // `_assertPermission()` returns early without throwing. The
    // boundary check is the absence of the denial path — the tool
    // may still throw for other reasons (no section, validation, etc.)
    // but NOT with the `permission denied — ` prefix.
    herald_with_edition(Herald::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity(null);

        $tools = [
            new Entry(),
            new Category(),
            new GlobalSet(),
            new Address(),
            new Users(),
            new Skill(),
            new ScaffoldEntries(),
            new BulkEntries(),
        ];

        foreach ($tools as $tool) {
            try {
                // Empty args trigger non-permission errors (missing
                // mode, missing required fields, etc.). The contract
                // we assert is that the error is NOT a permission-
                // denial.
                $tool->execute([]);
            } catch (ToolException $e) {
                // Non-permission ToolException is OK; what we don't
                // want to see is the denial prefix.
                expect($e->getMessage())->not->toStartWith('permission denied — ');
            }
        }
    });
});

// -----------------------------------------------------------------------------
// Dispatcher wire-envelope (post-8.10 follow-up)
// -----------------------------------------------------------------------------
//
// One representative Pro tool driven through `Server::dispatch()` to
// lock the `_toolErrorEnvelope()` shape end-to-end. The envelope is
// universal across Pro tools — see `src/mcp/Server.php:739-741` — so a
// single canonical case covers the contract. The per-tool tests above
// continue to verify tool-specific message prefixes via direct
// `execute()`.
//
// Uses `herald_with_pro_registry()` (added to `tests/Pest.php`) which
// flips the edition to Pro AND rebuilds the tool registry so
// `Tools::getByNameFor()` resolves Pro tools. Production never flips
// edition mid-process — this helper is test-only.

it('Server::dispatch() wraps Pro tool permission denial in the locked {content, isError} envelope', function() {
    // Use minorHeroes as the target (caller has no permission there)
    // and grant saveEntries on a different section so filterFor()
    // passes and the dispatcher routes to execute(). The per-section
    // _assertPermission() then throws ToolException, which the
    // dispatcher catches at Server.php:739 and wraps via
    // _toolErrorEnvelope() before returning.
    $target = Craft::$app->getEntries()->getSectionByHandle('minorHeroes');
    $other = Craft::$app->getEntries()->getSectionByHandle('heroes');
    if ($target === null || $other === null) {
        $this->markTestSkipped('minorHeroes + heroes sections not seeded.');
    }

    $caller = _herald_permbound_user($this->fixturePrefix, 'wire', [
        "viewEntries:{$other->uid}",
        "saveEntries:{$other->uid}",
    ]);
    if ($caller === null) {
        $this->markTestSkipped('Could not create caller.');
    }

    herald_with_pro_registry(function() use ($target, $caller) {
        Craft::$app->getUser()->setIdentity($caller);

        $beforeAuditIds = InvocationRecord::find()->select('id')->column();

        $server = new \craftpulse\herald\mcp\Server(\craftpulse\herald\mcp\Server::TRANSPORT_HTTP);
        $server->setUserId((int) $caller->id);
        $server->setSessionId('permbound-wire-' . bin2hex(random_bytes(4)));

        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'entry',
                'arguments' => [
                    'mode' => 'create',
                    'sectionHandle' => $target->handle,
                    'title' => $this->fixturePrefix . 'wire',
                ],
            ],
        ]);

        // JSON-RPC frame.
        expect($response)
            ->toHaveKey('jsonrpc', '2.0')
            ->toHaveKey('id', 1)
            ->toHaveKey('result');

        // The locked tool-error envelope shape — no numeric `code`
        // field, `isError: true`, message text inside `content[0].text`.
        $result = $response['result'];
        expect($result)
            ->toHaveKey('isError', true)
            ->toHaveKey('content');
        expect($result)->not->toHaveKey('code');
        expect($result['content'])->toBeArray()->toHaveCount(1);
        expect($result['content'][0])
            ->toHaveKey('type', 'text')
            ->toHaveKey('text');
        expect($result['content'][0]['text'])
            ->toStartWith('permission denied — mode `create` on section `' . $target->uid . '`');

        // Audit row written by the dispatcher's catch block, NOT by
        // any manual `logCall()` — proves the EVENT_LOG_CALL listener
        // wiring (PluginTrait::_registerAuditLogListener) is intact.
        $afterAuditIds = InvocationRecord::find()->select('id')->column();
        $newIds = array_diff($afterAuditIds, $beforeAuditIds);
        $this->touchedAuditRowIds = array_merge($this->touchedAuditRowIds, $newIds);

        expect($newIds)->toHaveCount(1);
        $auditRow = InvocationRecord::findOne(['id' => array_values($newIds)[0]]);
        expect($auditRow)->not->toBeNull();
        expect($auditRow->toolName)->toBe('entry');
        expect($auditRow->kind)->toBe('tool_error');
        expect($auditRow->errorClass)->toBe(ToolException::class);
        expect($auditRow->transport)->toBe('http');
    });
});
