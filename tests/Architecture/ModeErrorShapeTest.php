<?php

/**
 * =========================================================================
 * Mode-denial message-shape invariants — Gate 8.10 architecture sweep.
 *
 * Locks the **message-text prefix uniformity** across every Pro tool's
 * permission-denied path AND every Free-tool-with-Pro-modes' edition-
 * denial path. The MCP wire envelope for tool-level errors is
 * `{content: [{type: 'text', text: '...'}], isError: true}` — there is
 * NO numeric `code` field on the envelope (see `mcp/Server.php`
 * `_toolErrorEnvelope()`), so the invariant we lock is the *text*
 * prefix the message-builder emits, not a JSON-RPC error code.
 *
 * Two canonical templates, locked:
 *
 *   Template A — edition denial (Free tools with Pro modes):
 *     `<tool>: mode `<mode>` is unavailable on this edition.`
 *   Diagnostics variant (schema field is `type`, not `mode`):
 *     `system_diagnostics: type `<type>` is unavailable on this edition.`
 *
 *   Template B — permission denial (Pro tools + Pro-mode Free tools).
 *   All shapes start with the literal 9-char prefix `permission denied: `,
 *   then a tool-specific enrichment, then ` requires `<perm>`.` (with a
 *   trailing period). Three accepted enrichments:
 *     - mode-on-resource: ``mode `<mode>` on <resource> `<uid>` requires `<perm>`.``
 *     - mode-only:        ``mode `<mode>` requires `<perm>`.``
 *     - scaffold-only:    ``scaffold_entries requires `<perm>`.``
 *
 * Two locked deviations (per locked decision 3 of the gate-8.10 plan):
 *
 *   - `tag` — whole-tool admin gate (no mode-keyed message):
 *     ``permission denied: tag operations require admin status. ...``
 *   - `global_set` — fixed `update` mode (one mode only):
 *     ``permission denied: global_set update on set `<uid>` requires `<perm>`.``
 *
 * **Invocation strategy** — reflection over the protected
 * `_buildPermissionDeniedMessage()` method on each tool. The Pest test
 * fabricates a permission string and a minimal arguments payload, then
 * asserts the returned string matches the canonical template regex.
 *
 * Edition-denial coverage is integration-flavoured: invoke `execute()`
 * with a Pro-mode argument set on a Free edition (via
 * `herald_with_edition(EDITION_FREE, ...)`) and match the thrown
 * `ToolException::getMessage()` against the edition-denial template.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\content\Address;
use craftpulse\herald\tools\content\BulkEntries;
use craftpulse\herald\tools\content\Category;
use craftpulse\herald\tools\content\Entry;
use craftpulse\herald\tools\content\GlobalSet;
use craftpulse\herald\tools\content\ScaffoldEntries;
use craftpulse\herald\tools\content\Tag;
use craftpulse\herald\tools\system\Diagnostics;
use craftpulse\herald\tools\system\Skill;
use craftpulse\herald\tools\system\Users;
use craftpulse\herald\tools\ToolException;
use craftpulse\herald\tools\workflow\Audit;
use craftpulse\herald\tools\workflow\DraftsAndRevisions;
use craftpulse\herald\tools\workflow\ImportExport;

// -----------------------------------------------------------------------------
// Helpers — reflection invocation of the protected message builder
// -----------------------------------------------------------------------------

/**
 * Invoke a tool's protected `_buildPermissionDeniedMessage()` via
 * reflection and return the produced message string. PHPStan needs a
 * narrowed assertion because reflection-invoked method returns are
 * typed `mixed`.
 *
 * @param array<string,mixed> $arguments
 */
function _herald_invoke_permission_denied_message(
    object $tool,
    string $missingPermission,
    array $arguments,
): string {
    $method = new ReflectionMethod($tool, '_buildPermissionDeniedMessage');
    /** @var string $message */
    $message = $method->invoke($tool, $missingPermission, $arguments);
    expect($message)->toBeString();
    return $message;
}

// -----------------------------------------------------------------------------
// Permission-denial shape — Template B (per locked decision 3 of the plan)
// -----------------------------------------------------------------------------

it('entry permission-denied message matches `permission denied: mode `<mode>` on section `<uid>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new Entry(),
            'saveEntries:xxxx-yyyy-zzzz',
            ['mode' => 'create', 'sectionUid' => 'xxxx-yyyy-zzzz'],
        );
        // sectionUid resolution returns null for a fabricated UID
        // (no matching Section) — `?` placeholder is the contract.
        expect($message)->toBe(
            'permission denied: mode `create` on section `?` requires `saveEntries:xxxx-yyyy-zzzz`.',
        );
    });
});

it('category permission-denied message matches `permission denied: mode `<mode>` on group `<uid>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new Category(),
            'saveCategories:xxxx-yyyy-zzzz',
            ['mode' => 'update', 'groupUid' => 'xxxx-yyyy-zzzz'],
        );
        expect($message)->toBe(
            'permission denied: mode `update` on group `?` requires `saveCategories:xxxx-yyyy-zzzz`.',
        );
    });
});

it('global_set permission-denied message matches `permission denied: global_set update on set `<uid>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new GlobalSet(),
            'editGlobalSet:xxxx-yyyy-zzzz',
            ['globalSetUid' => 'xxxx-yyyy-zzzz'],
        );
        expect($message)->toBe(
            'permission denied: global_set update on set `?` requires `editGlobalSet:xxxx-yyyy-zzzz`.',
        );
    });
});

it('address permission-denied message matches `permission denied: mode `<mode>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new Address(),
            'editUsers',
            ['mode' => 'update'],
        );
        expect($message)->toBe('permission denied: mode `update` requires `editUsers`.');
    });
});

it('users permission-denied message matches `permission denied: mode `<mode>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new Users(),
            'editUsers',
            ['mode' => 'update'],
        );
        expect($message)->toBe('permission denied: mode `update` requires `editUsers`.');
    });
});

it('skill permission-denied message matches `permission denied: mode `<mode>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new Skill(),
            'herald:manage-skills',
            ['mode' => 'create'],
        );
        expect($message)->toBe('permission denied: mode `create` requires `herald:manage-skills`.');
    });
});

it('bulk_entries permission-denied message matches `permission denied: mode `<mode>` requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new BulkEntries(),
            'saveEntries:xxxx-yyyy-zzzz',
            ['mode' => 'set_status'],
        );
        expect($message)->toBe(
            'permission denied: mode `set_status` requires `saveEntries:xxxx-yyyy-zzzz`.',
        );
    });
});

it('scaffold_entries permission-denied message matches `permission denied: scaffold_entries requires `<perm>`.`', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $message = _herald_invoke_permission_denied_message(
            new ScaffoldEntries(),
            'utility:project-config',
            [],
        );
        expect($message)->toBe('permission denied: scaffold_entries requires `utility:project-config`.');
    });
});

it('drafts_and_revisions permission-denied message matches `permission denied: mode `<mode>` on section `<uid>` requires `<perm>`.`', function() {
    $message = _herald_invoke_permission_denied_message(
        new DraftsAndRevisions(),
        'saveEntries:xxxx-yyyy-zzzz',
        ['mode' => 'apply', 'sectionUid' => 'xxxx-yyyy-zzzz'],
    );
    expect($message)->toBe(
        'permission denied: mode `apply` on section `xxxx-yyyy-zzzz` requires `saveEntries:xxxx-yyyy-zzzz`.',
    );
});

it('content_audit (section) permission-denied message matches `permission denied: mode `<mode>` on section `<uid>` requires `<perm>`.`', function() {
    $message = _herald_invoke_permission_denied_message(
        new Audit(),
        'saveEntries:xxxx-yyyy-zzzz',
        ['mode' => 'fix_relations', 'sectionUid' => 'xxxx-yyyy-zzzz'],
    );
    expect($message)->toBe(
        'permission denied: mode `fix_relations` on section `xxxx-yyyy-zzzz` requires `saveEntries:xxxx-yyyy-zzzz`.',
    );
});

it('content_audit (volume) permission-denied message uses the `volume` resource type', function() {
    $message = _herald_invoke_permission_denied_message(
        new Audit(),
        'saveAssets:xxxx-yyyy-zzzz',
        ['mode' => 'prune_unused_assets', 'volumeUid' => 'xxxx-yyyy-zzzz'],
    );
    expect($message)->toBe(
        'permission denied: mode `prune_unused_assets` on volume `xxxx-yyyy-zzzz` requires `saveAssets:xxxx-yyyy-zzzz`.',
    );
});

it('content_audit (group) permission-denied message uses the `group` resource type', function() {
    $message = _herald_invoke_permission_denied_message(
        new Audit(),
        'saveCategories:xxxx-yyyy-zzzz',
        ['mode' => 'fix_relations', 'groupUid' => 'xxxx-yyyy-zzzz'],
    );
    expect($message)->toBe(
        'permission denied: mode `fix_relations` on group `xxxx-yyyy-zzzz` requires `saveCategories:xxxx-yyyy-zzzz`.',
    );
});

it('import_export permission-denied message matches `permission denied: mode `import` on section `<uid>` requires `<perm>`.`', function() {
    $message = _herald_invoke_permission_denied_message(
        new ImportExport(),
        'saveEntries:xxxx-yyyy-zzzz',
        ['mode' => 'import', 'sectionUid' => 'xxxx-yyyy-zzzz'],
    );
    expect($message)->toBe(
        'permission denied: mode `import` on section `xxxx-yyyy-zzzz` requires `saveEntries:xxxx-yyyy-zzzz`.',
    );
});

it('system_diagnostics permission-denied message matches `permission denied: type `<type>` requires `<perm>`.`', function() {
    $message = _herald_invoke_permission_denied_message(
        new Diagnostics(),
        'utility:queue-manager',
        ['type' => 'manage_queue'],
    );
    expect($message)->toBe(
        'permission denied: type `manage_queue` requires `utility:queue-manager`.',
    );
});

// -----------------------------------------------------------------------------
// Tag — locked deviation (whole-tool admin gate, no mode-keyed message)
// -----------------------------------------------------------------------------

it('tag admin gate emits the whole-tool admin-status message', function() {
    // Tag doesn't use PermissionedToolTrait — it raises ToolException
    // directly in `_assertAdmin()`. Drive the path by executing the
    // tool as a non-admin user and assert the locked deviation message.
    $caller = new \craft\elements\User();
    $caller->username = '__herald_modeerrshape_tag_' . bin2hex(random_bytes(4));
    $caller->email = $caller->username . '@example.test';
    $caller->admin = false;
    $caller->pending = true;
    if (!Craft::$app->getElements()->saveElement($caller)) {
        $this->markTestSkipped('Could not create non-admin fixture user.');
    }

    try {
        $caught = null;
        herald_with_edition(Herald::EDITION_PRO, function() use ($caller, &$caught) {
            Craft::$app->getUser()->setIdentity($caller);
            try {
                (new Tag())->execute(['mode' => 'create', 'groupHandle' => 'doesnotmatter', 'title' => 'x']);
            } catch (ToolException $e) {
                $caught = $e;
            }
        });

        expect($caught)->toBeInstanceOf(ToolException::class);
        expect($caught->getMessage())->toStartWith('permission denied: tag operations require admin status.');
    } finally {
        Craft::$app->getUser()->setIdentity(null);
        Craft::$app->getElements()->deleteElement($caller, hardDelete: true);
    }
});

// -----------------------------------------------------------------------------
// Edition-denial shape — Template A (Free tools with Pro modes)
// -----------------------------------------------------------------------------

it('drafts_and_revisions emits the edition-denial template on Free for a Pro mode', function() {
    $caught = null;
    herald_with_edition(Herald::EDITION_FREE, function() use (&$caught) {
        try {
            (new DraftsAndRevisions())->execute(['mode' => 'apply']);
        } catch (ToolException $e) {
            $caught = $e;
        }
    });
    expect($caught)->toBeInstanceOf(ToolException::class);
    expect($caught->getMessage())->toBe('drafts_and_revisions: mode `apply` is unavailable on this edition.');
});

it('content_audit emits the edition-denial template on Free for a Pro mode', function() {
    $caught = null;
    herald_with_edition(Herald::EDITION_FREE, function() use (&$caught) {
        try {
            (new Audit())->execute(['mode' => 'fix_relations']);
        } catch (ToolException $e) {
            $caught = $e;
        }
    });
    expect($caught)->toBeInstanceOf(ToolException::class);
    expect($caught->getMessage())->toBe('content_audit: mode `fix_relations` is unavailable on this edition.');
});

it('import_export emits the edition-denial template on Free for the import mode', function() {
    $caught = null;
    herald_with_edition(Herald::EDITION_FREE, function() use (&$caught) {
        try {
            (new ImportExport())->execute(['mode' => 'import']);
        } catch (ToolException $e) {
            $caught = $e;
        }
    });
    expect($caught)->toBeInstanceOf(ToolException::class);
    expect($caught->getMessage())->toBe('import_export: mode `import` is unavailable on this edition.');
});

it('system_diagnostics emits the edition-denial template on Free for a Pro type', function() {
    $caught = null;
    herald_with_edition(Herald::EDITION_FREE, function() use (&$caught) {
        try {
            (new Diagnostics())->execute(['type' => 'manage_queue']);
        } catch (ToolException $e) {
            $caught = $e;
        }
    });
    expect($caught)->toBeInstanceOf(ToolException::class);
    // Diagnostics is the only Free-with-Pro-modes tool whose schema
    // field is `type`, not `mode` — verified at Diagnostics.php:215-216.
    expect($caught->getMessage())->toBe('system_diagnostics: type `manage_queue` is unavailable on this edition.');
});
