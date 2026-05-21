<?php

/**
 * =========================================================================
 * User custom-field allowlist invariants — Gate 8.10.
 *
 * Service-level test that locks the cross-permission allowlist
 * invariant per `docs/plans/gate-8.10.md` locked decision 9:
 *
 *   - Default empty allowlist → custom fields absent regardless of
 *     caller permission tier (admin, editUsers, viewUsers, stdio).
 *   - Allowlist = [`<handle>`] → only that handle appears in the
 *     serialised `fields` envelope, regardless of caller permission.
 *   - Allowlist behaviour is INDEPENDENT of caller permission — a
 *     custom field NOT on the allowlist is never returned, even to
 *     admins.
 *   - Allowlisted handles that DO NOT exist on the user's field layout
 *     get silently dropped (already covered by `UsersTest`; not
 *     duplicated here).
 *
 * Setup seeds a real custom field (`__cortex_allowlist_test`) on the
 * User field layout via `Fields::saveField()` + `Users::saveLayout()`.
 * The User field layout is project-config-stored — muting PC events
 * during the save stalls the `Fields::saveLayout()` chain that
 * `Users::saveLayout()` triggers internally, so the test writes
 * through the live PC sync path. afterEach drops the field and
 * restores the original layout via the same path so subsequent tests
 * aren't poisoned.
 *
 * **SEQUENTIAL ONLY**: this test writes to project config (and on
 * disk: `cms/config/project/users/fieldLayouts/*.yaml` updates during
 * setup, reverts during teardown). Do NOT enable Paratest or any
 * parallel test runner for this suite without first isolating the
 * test's PC mutations — concurrent reads of the user field layout
 * during the test's setup/teardown window would race and assert on
 * the wrong shape. The sequential Pest default is safe; parallelism
 * would require per-worker `project.yaml` isolation, which Craft's
 * test harness doesn't ship.
 *
 * This is the strong companion to the weak existing
 * `UsersTest::a handle on the allowlist appears...` case at
 * `tests/Tools/System/UsersTest.php:393-427`, which only proved the
 * serialiser doesn't crash on a non-existent handle.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User as UserElement;
use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\system\Users;

// -----------------------------------------------------------------------------
// Setup — seed a real custom field on the User field layout
// -----------------------------------------------------------------------------

beforeEach(function() {
    // Admin identity. Same fallback pattern as UsersTest.
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    Craft::$app->getUser()->setIdentity($admin);
    $this->admin = $admin;

    $this->fury = Craft::$app->getUsers()->getUserByUsernameOrEmail('nfury');
    if ($this->fury === null) {
        $this->markTestSkipped('Director Fury not seeded.');
    }

    // Create the test custom field. Craft requires a valid handle —
    // must start with a letter (lowercase a-z), only letters / digits
    // / underscores allowed. Field saves write to project config; we
    // don't mute because the User layout save below also goes through
    // PC and the two writes need to land cleanly in the same epoch.
    $this->testFieldHandle = 'cortexAlwlTest' . bin2hex(random_bytes(3));
    $field = new PlainText();
    $field->name = 'Cortex Allowlist Test';
    $field->handle = $this->testFieldHandle;
    if (!Craft::$app->getFields()->saveField($field)) {
        $this->markTestSkipped('Could not save the test custom field: ' . json_encode($field->getErrors()));
    }
    $this->testField = $field;

    // Snapshot the existing User layout so we can restore it. Uses
    // the user-side `saveLayout()` path because Craft's User layout
    // is project-config-stored — direct `Fields::saveLayout()` works
    // for the underlying record but doesn't sync the PC entry, so
    // subsequent reads via `getLayoutByType()` would return stale
    // data.
    $fieldsService = Craft::$app->getFields();
    $existingLayout = $fieldsService->getLayoutByType(UserElement::class, false);
    $this->originalLayoutConfig = $existingLayout?->getConfig();
    $this->originalLayoutUid = $existingLayout?->uid;

    // Build a layout that includes the test field. If a layout
    // exists, append the field to its first tab; otherwise build a
    // fresh single-tab layout containing the field.
    $layout = $existingLayout ?? new FieldLayout(['type' => UserElement::class]);
    if ($layout->uid === null) {
        $layout->uid = \craft\helpers\StringHelper::UUID();
    }
    $tabs = $layout->getTabs();
    if ($tabs === []) {
        $tab = new FieldLayoutTab(['name' => 'Cortex Test', 'layout' => $layout]);
        $tab->setElements([new CustomField($this->testField)]);
        $layout->setTabs([$tab]);
    } else {
        $firstTab = $tabs[0];
        $elements = $firstTab->getElements();
        $elements[] = new CustomField($this->testField);
        $firstTab->setElements($elements);
        $layout->setTabs($tabs);
    }
    if (!Craft::$app->getUsers()->saveLayout($layout)) {
        $fieldsService->deleteField($this->testField);
        $this->markTestSkipped('Could not save the patched User field layout.');
    }
    $this->testLayout = $layout;

    // Snapshot original allowlist; tests will mutate then restore.
    $this->originalAllowlist = Cortex::getInstance()->getSettings()->userCustomFieldAllowlist;
});

afterEach(function() {
    // Restore the allowlist setting.
    if (isset($this->originalAllowlist)) {
        Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = $this->originalAllowlist;
    }

    // Restore admin identity.
    if (isset($this->admin)) {
        Craft::$app->getUser()->setIdentity($this->admin);
    }

    // Restore the original User field layout via the same PC path so
    // subsequent tests aren't poisoned. Layouts are stored under
    // `users.fieldLayouts.<uid>`; setting to `[]` (the empty layout)
    // is sufficient when there was no prior layout.
    if (isset($this->originalLayoutConfig, $this->originalLayoutUid)) {
        $restoreLayout = FieldLayout::createFromConfig($this->originalLayoutConfig);
        $restoreLayout->type = UserElement::class;
        $restoreLayout->uid = $this->originalLayoutUid;
        Craft::$app->getUsers()->saveLayout($restoreLayout);
    } elseif (isset($this->testLayout)) {
        // No prior layout existed; delete the one we created.
        Craft::$app->getFields()->deleteLayoutsByType(UserElement::class);
    }

    // Drop the test custom field.
    if (isset($this->testField) && $this->testField->id !== null) {
        Craft::$app->getFields()->deleteField($this->testField);
    }
});

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Create a non-admin caller with the supplied Craft permission strings.
 * Returns the saved User, or null on save failure.
 *
 * @param string[] $permissions
 */
function _cortex_allowlist_caller(string $prefix, array $permissions): ?UserElement
{
    $caller = new UserElement();
    $caller->username = $prefix . '_' . bin2hex(random_bytes(3));
    $caller->email = $caller->username . '@example.test';
    $caller->admin = false;
    $caller->pending = true;
    if (!Craft::$app->getElements()->saveElement($caller)) {
        return null;
    }
    Craft::$app->getUserPermissions()->saveUserPermissions((int) $caller->id, $permissions);
    return $caller;
}

/**
 * Hand-rolled instantiation of the Users tool — sidesteps the boot-time
 * registry gate (Pro tool absent on Free installs).
 */
function _cortex_allowlist_users_tool(): Users
{
    return new Users();
}

// -----------------------------------------------------------------------------
// Default empty allowlist — custom fields absent for every caller tier
// -----------------------------------------------------------------------------

it('default empty allowlist hides custom fields from an admin caller', function() {
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [];

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity($this->admin);
        $result = _cortex_allowlist_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['fields'])->toBe([]);
    });
});

it('default empty allowlist hides custom fields from an editUsers caller', function() {
    $caller = _cortex_allowlist_caller('cxa_edit', ['viewusers', 'editusers']);
    if ($caller === null) {
        $this->markTestSkipped('Could not create editUsers caller.');
    }

    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [];

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);
            $result = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect($result['user']['fields'])->toBe([]);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($caller, hardDelete: true);
    }
});

it('default empty allowlist hides custom fields from a viewUsers-only caller', function() {
    $caller = _cortex_allowlist_caller('cxa_view', ['viewusers']);
    if ($caller === null) {
        $this->markTestSkipped('Could not create viewUsers caller.');
    }

    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [];

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);
            $result = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect($result['user']['fields'])->toBe([]);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($caller, hardDelete: true);
    }
});

it('default empty allowlist hides custom fields from stdio (null user)', function() {
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [];

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity(null);
        $result = _cortex_allowlist_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['fields'])->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// Allowlist with the test handle — appears regardless of caller permission
// -----------------------------------------------------------------------------

it('allowlist with the test handle surfaces it to an admin caller', function() {
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [$this->testFieldHandle];

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity($this->admin);
        $result = _cortex_allowlist_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['fields'])->toHaveKey($this->testFieldHandle);
    });
});

it('allowlist with the test handle surfaces it to an editUsers caller', function() {
    $caller = _cortex_allowlist_caller('cxa_edit', ['viewusers', 'editusers']);
    if ($caller === null) {
        $this->markTestSkipped('Could not create editUsers caller.');
    }

    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [$this->testFieldHandle];

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);
            $result = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect($result['user']['fields'])->toHaveKey($this->testFieldHandle);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($caller, hardDelete: true);
    }
});

it('allowlist with the test handle surfaces it to a viewUsers-only caller', function() {
    $caller = _cortex_allowlist_caller('cxa_view', ['viewusers']);
    if ($caller === null) {
        $this->markTestSkipped('Could not create viewUsers caller.');
    }

    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [$this->testFieldHandle];

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($caller) {
            Craft::$app->getUser()->setIdentity($caller);
            $result = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect($result['user']['fields'])->toHaveKey($this->testFieldHandle);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($caller, hardDelete: true);
    }
});

it('allowlist with the test handle surfaces it to stdio (null user)', function() {
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [$this->testFieldHandle];

    // Sanity check: the field should actually be on the user layout
    // we just saved. If not, the test setup didn't work and the
    // field-absence assertion below is meaningless.
    $layout = Craft::$app->getFields()->getLayoutByType(UserElement::class, false);
    expect($layout)->not->toBeNull();
    $handles = array_map(static fn($f) => $f->handle, $layout->getCustomFields());
    expect($handles)->toContain($this->testFieldHandle);

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity(null);
        $result = _cortex_allowlist_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['fields'])->toHaveKey($this->testFieldHandle);
    });
});

// -----------------------------------------------------------------------------
// Permission-independence — only allowlisted handles surface, never others
// -----------------------------------------------------------------------------

it('allowlist with a different handle never surfaces the test handle, regardless of caller', function() {
    // Pick a handle that's NOT on the layout. The allowlist is set
    // to that handle only; the test handle MUST be absent.
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = ['__cortex_nonexistent_handle'];

    cortex_with_edition(Cortex::EDITION_PRO, function() {
        Craft::$app->getUser()->setIdentity($this->admin);
        $result = _cortex_allowlist_users_tool()->execute([
            'mode' => 'get',
            'id' => $this->fury->id,
        ]);
        expect($result['user']['fields'])->not->toHaveKey($this->testFieldHandle);
    });
});

it('native PII gating is unaffected by the custom-field allowlist', function() {
    // Setting the allowlist must NOT affect native PII gating —
    // `editUsers` still controls whether `email` / `invalidLoginCount`
    // are visible. Cross-check by setting the allowlist and walking
    // both permission tiers.
    Cortex::getInstance()->getSettings()->userCustomFieldAllowlist = [$this->testFieldHandle];

    $viewOnly = _cortex_allowlist_caller('cxa_pii_view', ['viewusers']);
    if ($viewOnly === null) {
        $this->markTestSkipped('Could not create viewUsers caller.');
    }
    $editAlso = _cortex_allowlist_caller('cxa_pii_edit', ['viewusers', 'editusers']);
    if ($editAlso === null) {
        Craft::$app->getElements()->deleteElement($viewOnly, hardDelete: true);
        $this->markTestSkipped('Could not create editUsers caller.');
    }

    try {
        cortex_with_edition(Cortex::EDITION_PRO, function() use ($viewOnly, $editAlso) {
            // viewUsers — no email.
            Craft::$app->getUser()->setIdentity($viewOnly);
            $viewResult = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect(array_key_exists('email', $viewResult['user']))->toBeFalse();
            // Custom field still surfaces — allowlist is permission-independent.
            expect($viewResult['user']['fields'])->toHaveKey($this->testFieldHandle);

            // editUsers — email visible.
            Craft::$app->getUser()->setIdentity($editAlso);
            $editResult = _cortex_allowlist_users_tool()->execute([
                'mode' => 'get',
                'id' => $this->fury->id,
            ]);
            expect($editResult['user'])->toHaveKey('email');
            expect($editResult['user']['fields'])->toHaveKey($this->testFieldHandle);
        });
    } finally {
        Craft::$app->getElements()->deleteElement($viewOnly, hardDelete: true);
        Craft::$app->getElements()->deleteElement($editAlso, hardDelete: true);
    }
});
