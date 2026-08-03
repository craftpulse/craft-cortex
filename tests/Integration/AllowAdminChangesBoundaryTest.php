<?php

/**
 * =========================================================================
 * allowAdminChanges cross-cutting boundary invariants — Gate 8.10.
 *
 * Walks every registered tool's input schema and the allowlist's
 * effective view to prove that under
 * `Craft::$app->getConfig()->getGeneral()->allowAdminChanges = false`:
 *
 *   - `Allowlist::getEffective()` contains zero patterns from
 *     `Settings::$adminLevelCommands` — the today-state catch.
 *   - No tool's `getInputSchema()` exposes an admin-bleed enum value —
 *     the future-proof catch (locked decision 7 of gate-8.10.md).
 *
 * And under `allowAdminChanges = true`:
 *
 *   - `Allowlist::getEffective()` contains every pattern from
 *     `Settings::$adminLevelCommands` — sanity-check the union.
 *
 * The `craft_command`-specific admit/reject paths are already covered
 * exhaustively by `tests/Tools/Dev/CraftCommandAdminChangesTest.php`;
 * this test is the **cross-cutting** counterpart, not a duplicate.
 *
 * The admin-bleed predicate (locked decision 7): an enum value is an
 * admin-bleed iff it would mutate project config OR trigger a schema
 * migration. Today the only such surface is `craft_command`'s
 * free-form `command` argument (string, not enum); the enum walk is a
 * structural guard for future tools that might surface admin-level
 * state via an enum value (e.g. a `system_diagnostics.type` that
 * grows a `migrate` value, or a `content_audit.mode` that grows a
 * `rebuild_schema` value).
 *
 * Per locked decision 8: `Diagnostics.manage_queue` is content-level,
 * NOT admin-level. The enum walk crosses Diagnostics's `type` enum
 * (logs / last_error / deprecations / queue / project_config_diff /
 * manage_queue) and confirms no admin-level value.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Recursively walk a JSON Schema array and collect every `enum` field
 * value. Returns a flat list of strings — enum values may live behind
 * `properties.<key>.enum`, `items.enum`, or nested in `anyOf` /
 * `oneOf` / `allOf` schemas.
 *
 * @param array<string,mixed>|mixed $schema
 * @return string[]
 */
function _herald_collect_enum_values(mixed $schema): array
{
    if (!is_array($schema)) {
        return [];
    }

    $values = [];

    if (isset($schema['enum']) && is_array($schema['enum'])) {
        foreach ($schema['enum'] as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }
    }

    foreach ($schema as $child) {
        if (is_array($child)) {
            $values = array_merge($values, _herald_collect_enum_values($child));
        }
    }

    return $values;
}

/**
 * Predicate for an admin-bleed enum value per locked decision 7 of
 * gate-8.10.md. Matches anything that would mutate project config OR
 * trigger a schema migration:
 *
 *   - `up` (Craft's `up` console command — applies migrations + PC)
 *   - `migrate/*`, `project-config/*`, `sections/*`, `fields/*`,
 *     `entrify/*`, `make/*` (admin-level allowlist patterns)
 *
 * Today no tool's enum carries such a value; this is the future-proof
 * predicate the boundary test asserts against.
 */
function _herald_is_admin_bleed_value(string $value): bool
{
    if ($value === 'up') {
        return true;
    }
    foreach (['migrate/', 'project-config/', 'sections/', 'fields/', 'entrify/', 'make/'] as $prefix) {
        if (str_starts_with($value, $prefix)) {
            return true;
        }
    }
    return false;
}

// -----------------------------------------------------------------------------
// Effective allowlist invariants — today-state catch
// -----------------------------------------------------------------------------

it('Allowlist::getEffective() contains zero admin-level patterns under allowAdminChanges=false', function() {
    herald_with_admin_changes(false, function() {
        $effective = Herald::getInstance()->allowlist->getEffective();
        $adminPatterns = Herald::getInstance()->getSettings()->adminLevelCommands;

        $bleed = array_intersect($effective, $adminPatterns);
        expect($bleed)->toBe([]);
    });
});

it('Allowlist::getEffective() contains every admin-level pattern under allowAdminChanges=true', function() {
    herald_with_admin_changes(true, function() {
        $effective = Herald::getInstance()->allowlist->getEffective();
        $adminPatterns = Herald::getInstance()->getSettings()->adminLevelCommands;

        $missing = array_diff($adminPatterns, $effective);
        expect($missing)->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// Cross-tool enum-walk invariant — future-proof catch
// -----------------------------------------------------------------------------

it('no registered tool exposes an admin-bleed enum value under allowAdminChanges=false', function() {
    herald_with_admin_changes(false, function() {
        $payload = Herald::getInstance()->tools->asListPayload();

        $violations = [];
        foreach ($payload as $entry) {
            $name = $entry['name'] ?? '<unknown>';
            $schema = $entry['inputSchema'] ?? [];
            $values = _herald_collect_enum_values($schema);
            foreach ($values as $value) {
                if (_herald_is_admin_bleed_value($value)) {
                    $violations[] = sprintf(
                        '%s exposes admin-bleed enum value `%s` under allowAdminChanges=false',
                        $name,
                        $value,
                    );
                }
            }
        }

        expect($violations)->toBe([]);
    });
});

it('the enum walk is non-trivial: system_diagnostics.type carries the expected Free values', function() {
    // Sanity check that the walk actually traverses the schema. If
    // every tool's `getInputSchema()` suddenly returned no enums, the
    // primary invariant would pass vacuously. This crosses
    // Diagnostics's `type` enum and confirms the walker found values.
    //
    // The playground boots in Free edition, so `getInputSchema()`
    // returns just the Free types (`logs / last_error / deprecations /
    // queue / project_config_diff`). The Pro `manage_queue` type is
    // edition-gated and absent here — which is the right behaviour
    // and the reason we don't assert on it directly. The locked
    // decision 8 guarantee (`manage_queue` is content-level, not
    // admin-bleed) is independently asserted below.
    $tool = Herald::getInstance()->tools->getByName('system_diagnostics');
    expect($tool)->not->toBeNull();
    $schema = $tool::getInputSchema();
    $values = _herald_collect_enum_values($schema);
    expect($values)->toContain('logs');
    expect($values)->toContain('project_config_diff');

    // Verify the predicate — `manage_queue` is operator-level
    // mutability, not schema mutability, so it doesn't trip the
    // admin-bleed gate even when surfaced on Pro installs.
    expect(_herald_is_admin_bleed_value('manage_queue'))->toBeFalse();
});
