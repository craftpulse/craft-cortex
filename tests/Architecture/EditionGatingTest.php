<?php

/**
 * =========================================================================
 * Edition-gating invariants — Gate 8 architecture test sweep.
 *
 * Walks the list of Pro tool classes and asserts each one uses
 * `ProToolTrait` (locked decision 2 of `docs/plans/gate-8.md`). The
 * list grows as Gate 8 sub-gates land — 8.2 ships `Entry`; 8.3 adds
 * `Category`, `Tag`, `GlobalSet`; 8.4 adds `Address`; 8.5 adds
 * `Users`; 8.6 adds `BulkEntries`. Each builder appends to the list
 * in the same commit that ships the tool, so the invariant catches
 * a Pro tool that drifted from the trait.
 *
 * Additional Pro-tool invariants the test sweep enforces:
 *   - On a Free install, every Pro tool's `shouldRegister()` returns
 *     false, and `Tools::getByName($name)` returns null.
 *   - On a Pro install, every Pro tool's `shouldRegister()` returns
 *     true (locked decision 13 — sub-gate sequencing depends on
 *     this).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\content\Address;
use craftpulse\cortex\tools\content\BulkEntries;
use craftpulse\cortex\tools\content\Category;
use craftpulse\cortex\tools\content\Entry;
use craftpulse\cortex\tools\content\GlobalSet;
use craftpulse\cortex\tools\content\ScaffoldEntries;
use craftpulse\cortex\tools\content\Tag;
use craftpulse\cortex\tools\ProToolTrait;
use craftpulse\cortex\tools\system\Skill;
use craftpulse\cortex\tools\system\Users;

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * The list of Pro tool classes. Append to this list as Gate 8
 * sub-gates land — the invariants below iterate it and fail on a
 * tool that's not edition-gated.
 *
 * @return array<class-string>
 */
function _cortex_pro_tool_classes(): array
{
    return [
        Entry::class,
        Category::class,
        Tag::class,
        GlobalSet::class,
        Address::class,
        Users::class,
        Skill::class,
        BulkEntries::class,
        ScaffoldEntries::class,
    ];
}

// -----------------------------------------------------------------------------
// ProToolTrait invariant
// -----------------------------------------------------------------------------

it('every Pro tool uses ProToolTrait', function() {
    $violations = [];
    foreach (_cortex_pro_tool_classes() as $class) {
        $traits = class_uses($class);
        if ($traits === false) {
            $violations[] = "{$class}: class_uses() failed";
            continue;
        }
        if (!isset($traits[ProToolTrait::class])) {
            $violations[] = "{$class}: missing ProToolTrait";
        }
    }
    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// shouldRegister gating
// -----------------------------------------------------------------------------

it('every Pro tool shouldRegister() returns false on Free', function() {
    $violations = [];
    foreach (_cortex_pro_tool_classes() as $class) {
        if ($class::shouldRegister() !== false) {
            $violations[] = "{$class}::shouldRegister() returned true on Free";
        }
    }
    expect($violations)->toBe([]);
});

it('every Pro tool shouldRegister() returns true on Pro', function() {
    cortex_with_edition(Cortex::EDITION_PRO, function() {
        $violations = [];
        foreach (_cortex_pro_tool_classes() as $class) {
            if ($class::shouldRegister() !== true) {
                $violations[] = "{$class}::shouldRegister() returned false on Pro";
            }
        }
        expect($violations)->toBe([]);
    });
});

// -----------------------------------------------------------------------------
// Registry absence on Free
// -----------------------------------------------------------------------------

it('every Pro tool is absent from Tools::getByName() on Free', function() {
    $violations = [];
    foreach (_cortex_pro_tool_classes() as $class) {
        $name = $class::getName();
        if (Cortex::getInstance()->tools->getByName($name) !== null) {
            $violations[] = "{$name}: resolved on Free install (should be null)";
        }
    }
    expect($violations)->toBe([]);
});

it('every Pro tool is absent from asListPayload() on Free', function() {
    $listed = array_map(
        static fn(array $entry): string => $entry['name'],
        Cortex::getInstance()->tools->asListPayload(),
    );
    $violations = [];
    foreach (_cortex_pro_tool_classes() as $class) {
        $name = $class::getName();
        if (in_array($name, $listed, true)) {
            $violations[] = "{$name}: present in asListPayload() on Free";
        }
    }
    expect($violations)->toBe([]);
});
