<?php

/**
 * =========================================================================
 * craft_command × allowAdminChanges boundary tests — Gate 8.1.
 *
 * Locks `docs/plans/gate-8.md` locked decision 14:
 *
 *   - Content-level patterns (`Settings::$allowedCommands` ∪ runtime
 *     overrides) are always admitted by the dispatch gate.
 *   - Admin-level patterns (`Settings::$adminLevelCommands`) are
 *     admitted ONLY when
 *     `Craft::$app->getConfig()->getGeneral()->allowAdminChanges`
 *     is `true`.
 *   - When false, an admin-level invocation throws `ToolException`
 *     with a message naming `allowAdminChanges` as the reason.
 *   - The `Allowlist::getEffective()` view stays in lockstep with
 *     the dispatch gate — admin commands are in the list iff
 *     `allowAdminChanges` is true.
 *   - Audit-log fidelity: a rejected admin-level invocation is
 *     still surfaced as a `ToolException`, which the Gate 7.5
 *     `EVENT_LOG_CALL` listener writes to `cortex_invocations`
 *     with `kind=tool_error` + `errorClass=ToolException`.
 *
 * Uses the `cortex_with_admin_changes()` helper from `tests/Pest.php`
 * to flip the flag with a clean restore on success or exception. Use
 * of `make/section` (an invalid Yii route) and `migrate/up` (a no-op
 * on the playground) keeps actual dispatch cheap — the test cares
 * about the gate, not the runner.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\records\Invocation as InvocationRecord;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\InvocationLogger;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('craft_command');
});

// -----------------------------------------------------------------------------
// allowAdminChanges = true — admin-level patterns admitted
// -----------------------------------------------------------------------------

it('admits migrate/up when allowAdminChanges is true', function() {
    cortex_with_admin_changes(true, function() {
        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'migrate/up',
        ]);

        expect($result)->toHaveKey('mode', 'run');
        expect($result)->toHaveKey('command', 'migrate/up');
        expect($result)->toHaveKey('matchedPattern', 'migrate/*');
    });
});

it('admits make/section when allowAdminChanges is true', function() {
    cortex_with_admin_changes(true, function() {
        // `make/section` is an invalid Yii route — the allowlist pattern
        // `make/*` admits it, then ConsoleRunner reports the missing
        // sub-command. The tool does NOT throw a ToolException
        // because admission already happened.
        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'make/section',
        ]);

        expect($result)->toHaveKey('mode', 'run');
        expect($result)->toHaveKey('command', 'make/section');
        expect($result)->toHaveKey('matchedPattern', 'make/*');
    });
});

// -----------------------------------------------------------------------------
// allowAdminChanges = false — admin-level patterns rejected
// -----------------------------------------------------------------------------

it('rejects migrate/up when allowAdminChanges is false, naming the config flag', function() {
    cortex_with_admin_changes(false, function() {
        try {
            $this->tool->execute([
                'mode' => 'run',
                'command' => 'migrate/up',
            ]);
            $this->fail('Expected ToolException, got admission.');
        } catch (ToolException $e) {
            expect($e->getMessage())->toContain('allowAdminChanges');
            expect($e->getMessage())->toContain('migrate/*');
            expect($e->getMessage())->toContain('migrate/up');
        }
    });
});

it('rejects make/section when allowAdminChanges is false, naming the config flag', function() {
    cortex_with_admin_changes(false, function() {
        try {
            $this->tool->execute([
                'mode' => 'run',
                'command' => 'make/section',
            ]);
            $this->fail('Expected ToolException, got admission.');
        } catch (ToolException $e) {
            expect($e->getMessage())->toContain('allowAdminChanges');
            expect($e->getMessage())->toContain('make/*');
        }
    });
});

// -----------------------------------------------------------------------------
// allowAdminChanges = false — content-level patterns still admitted
// -----------------------------------------------------------------------------

it('still admits resave/entries when allowAdminChanges is false', function() {
    cortex_with_admin_changes(false, function() {
        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'resave/entries',
            'options' => ['limit' => 1],
        ]);

        expect($result)->toHaveKey('mode', 'run');
        expect($result)->toHaveKey('command', 'resave/entries');
        expect($result)->toHaveKey('matchedPattern', 'resave/*');
    });
});

it('still admits cache/flush when allowAdminChanges is false', function() {
    cortex_with_admin_changes(false, function() {
        $result = $this->tool->execute([
            'mode' => 'run',
            'command' => 'cache/flush',
        ]);

        expect($result)->toHaveKey('mode', 'run');
        expect($result)->toHaveKey('command', 'cache/flush');
        expect($result)->toHaveKey('matchedPattern', 'cache/*');
    });
});

// -----------------------------------------------------------------------------
// Effective-allowlist introspection
// -----------------------------------------------------------------------------

it('Allowlist::getEffective() includes admin commands when allowAdminChanges is true', function() {
    cortex_with_admin_changes(true, function() {
        $effective = Cortex::getInstance()->allowlist->getEffective();

        // Content-level patterns are always in.
        expect($effective)->toContain('resave/*');
        expect($effective)->toContain('cache/*');
        expect($effective)->toContain('gc');
        // Admin-level patterns are in iff allowAdminChanges is true.
        expect($effective)->toContain('migrate/*');
        expect($effective)->toContain('make/*');
        expect($effective)->toContain('project-config/*');
        expect($effective)->toContain('up');
    });
});

it('Allowlist::getEffective() excludes admin commands when allowAdminChanges is false', function() {
    cortex_with_admin_changes(false, function() {
        $effective = Cortex::getInstance()->allowlist->getEffective();

        // Content-level patterns are always in.
        expect($effective)->toContain('resave/*');
        expect($effective)->toContain('cache/*');
        expect($effective)->toContain('gc');
        // Admin-level patterns are out.
        expect($effective)->not->toContain('migrate/*');
        expect($effective)->not->toContain('make/*');
        expect($effective)->not->toContain('project-config/*');
        expect($effective)->not->toContain('up');
    });
});

it('craft_command mode=list reflects the effective allowlist in both states', function() {
    cortex_with_admin_changes(true, function() {
        $result = $this->tool->execute(['mode' => 'list']);
        expect($result['patterns'])->toContain('migrate/*');
        expect($result['patterns'])->toContain('resave/*');
    });

    cortex_with_admin_changes(false, function() {
        $result = $this->tool->execute(['mode' => 'list']);
        expect($result['patterns'])->not->toContain('migrate/*');
        expect($result['patterns'])->toContain('resave/*');
    });
});

// -----------------------------------------------------------------------------
// Audit-log fidelity — Gate 7.5 wire still catches the rejection
// -----------------------------------------------------------------------------

it('an admin-changes-denied ToolException audit-logs with kind=tool_error', function() {
    // Drive the same path the HTTP dispatcher takes: catch the
    // ToolException, hand it to InvocationLogger::logCall with an
    // HTTP context, then read back the `cortex_invocations` row the
    // Cortex::init()-wired listener wrote.
    $caught = null;
    cortex_with_admin_changes(false, function() use (&$caught) {
        try {
            $this->tool->execute([
                'mode' => 'run',
                'command' => 'migrate/up',
            ]);
        } catch (ToolException $e) {
            $caught = $e;
        }
    });

    expect($caught)->toBeInstanceOf(ToolException::class);

    // Pre-existing rows for craft_command + tool_error don't poison the
    // assertion because we snapshot the count before/after.
    $before = InvocationRecord::find()
        ->where(['toolName' => 'craft_command', 'kind' => 'tool_error'])
        ->count();

    InvocationLogger::logCall(
        toolName: 'craft_command',
        arguments: ['mode' => 'run', 'command' => 'migrate/up'],
        error: $caught,
        durationMs: 1,
        context: new InvocationContext(
            transport: 'http',
            requestId: '_test_/admin-changes',
            userId: null,
            clientName: 'pest',
        ),
    );

    $after = InvocationRecord::find()
        ->where(['toolName' => 'craft_command', 'kind' => 'tool_error'])
        ->orderBy(['id' => SORT_DESC])
        ->all();

    expect(count($after))->toBe($before + 1);
    /** @var InvocationRecord $row */
    $row = $after[0];
    expect($row->toolName)->toBe('craft_command');
    expect($row->kind)->toBe('tool_error');
    expect($row->errorClass)->toBe(ToolException::class);
    expect($row->errorMessage)->toContain('allowAdminChanges');

    // Cleanup so the test is independent of subsequent runs.
    InvocationRecord::deleteAll(['id' => $row->id]);
});
