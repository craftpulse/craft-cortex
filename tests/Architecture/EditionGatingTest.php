<?php

/**
 * =========================================================================
 * Edition-gating invariants — Gate 8 architecture test sweep.
 *
 * Walks the list of Pro tool classes and asserts each one uses
 * `ProToolTrait` (locked decision). The
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
use craftpulse\herald\tools\dev\Resave;
use craftpulse\herald\tools\dev\StreamingFixtureTool;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\StreamableToolInterface;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\system\Skill;
use craftpulse\herald\tools\system\Users;
use craftpulse\herald\tools\workflow\Audit;
use craftpulse\herald\tools\workflow\ImportExport;

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
function _herald_pro_tool_classes(): array
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
    foreach (_herald_pro_tool_classes() as $class) {
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
    foreach (_herald_pro_tool_classes() as $class) {
        if ($class::shouldRegister() !== false) {
            $violations[] = "{$class}::shouldRegister() returned true on Free";
        }
    }
    expect($violations)->toBe([]);
});

it('every Pro tool shouldRegister() returns true on Pro', function() {
    herald_with_edition(Herald::EDITION_PRO, function() {
        $violations = [];
        foreach (_herald_pro_tool_classes() as $class) {
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
    foreach (_herald_pro_tool_classes() as $class) {
        $name = $class::getName();
        if (Herald::getInstance()->tools->getByName($name) !== null) {
            $violations[] = "{$name}: resolved on Free install (should be null)";
        }
    }
    expect($violations)->toBe([]);
});

it('every Pro tool is absent from asListPayload() on Free', function() {
    $listed = array_map(
        static fn(array $entry): string => $entry['name'],
        Herald::getInstance()->tools->asListPayload(),
    );
    $violations = [];
    foreach (_herald_pro_tool_classes() as $class) {
        $name = $class::getName();
        if (in_array($name, $listed, true)) {
            $violations[] = "{$name}: present in asListPayload() on Free";
        }
    }
    expect($violations)->toBe([]);
});

// -----------------------------------------------------------------------------
// Streaming-tool axis — Gate 8.10 invariant sweep
// -----------------------------------------------------------------------------

/**
 * The list of streaming tool classes. Append as new streaming tools
 * land — the invariants below iterate it and fail on a tool that
 * drifted from the `StreamableToolInterface` contract.
 *
 * Mixed Free / Pro registration: `BulkEntries` and `ScaffoldEntries`
 * are Pro (registered only on Pro installs); `Resave`, `Audit`,
 * `ImportExport`, and the env-gated `StreamingFixtureTool` are Free-
 * registered with optional Pro modes.
 *
 * @return array<class-string>
 */
function _herald_streaming_tool_classes(): array
{
    return [
        BulkEntries::class,
        ScaffoldEntries::class,
        Resave::class,
        Audit::class,
        ImportExport::class,
        StreamingFixtureTool::class,
    ];
}

it('every streaming tool implements StreamableToolInterface', function() {
    $violations = [];
    foreach (_herald_streaming_tool_classes() as $class) {
        $reflect = new ReflectionClass($class);
        if (!$reflect->implementsInterface(StreamableToolInterface::class)) {
            $violations[] = "{$class}: missing StreamableToolInterface";
        }
    }
    expect($violations)->toBe([]);
});

it('every streaming tool declares stream(): Generator with (array, InvocationContext) parameters', function() {
    $violations = [];
    foreach (_herald_streaming_tool_classes() as $class) {
        $reflect = new ReflectionClass($class);
        if (!$reflect->hasMethod('stream')) {
            $violations[] = "{$class}: missing stream() method";
            continue;
        }

        $method = $reflect->getMethod('stream');

        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== Generator::class) {
            $violations[] = "{$class}::stream() return type is not Generator";
        }

        $params = $method->getParameters();
        if (count($params) !== 2) {
            $violations[] = sprintf('%s::stream() expected 2 parameters, got %d', $class, count($params));
            continue;
        }

        $firstType = $params[0]->getType();
        if (!$firstType instanceof ReflectionNamedType || $firstType->getName() !== 'array') {
            $violations[] = "{$class}::stream() first parameter is not `array`";
        }

        $secondType = $params[1]->getType();
        if (
            !$secondType instanceof ReflectionNamedType
            || $secondType->getName() !== InvocationContext::class
        ) {
            $violations[] = "{$class}::stream() second parameter is not InvocationContext";
        }
    }
    expect($violations)->toBe([]);
});

it('every streaming tool declares execute(): array', function() {
    // The non-streaming entry point collapses `stream()` via
    // `getReturn()` to surface a single terminal envelope on the
    // JSON-mode dispatch path. Each streaming tool's `execute()` body
    // implementation varies (locked decision 13 of gate-8.10.md
    // documents the BulkEntries reference pattern), but the signature
    // must consistently declare an array return.
    $violations = [];
    foreach (_herald_streaming_tool_classes() as $class) {
        $reflect = new ReflectionClass($class);
        if (!$reflect->hasMethod('execute')) {
            $violations[] = "{$class}: missing execute() method";
            continue;
        }

        $method = $reflect->getMethod('execute');
        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType || $returnType->getName() !== 'array') {
            $violations[] = "{$class}::execute() return type is not array";
        }
    }
    expect($violations)->toBe([]);
});

it('Free-registered streaming tools remain registered on a Free install', function() {
    // Per locked decision 13 of gate-8.10.md: `Resave`, `Audit`,
    // `ImportExport`, `StreamingFixtureTool` (env-gated) are Free-
    // registered with optional Pro modes; only `BulkEntries` and
    // `ScaffoldEntries` are whole-tool Pro-gated.
    $freeRegistered = [
        Resave::class,
        Audit::class,
        ImportExport::class,
        // StreamingFixtureTool is gated by HERALD_STREAMING_FIXTURE
        // env var — skip the registry check (locked decision 13).
    ];

    $tools = Herald::getInstance()->tools;
    $violations = [];
    foreach ($freeRegistered as $class) {
        $name = $class::getName();
        if ($tools->getByName($name) === null) {
            $violations[] = "{$name}: absent from Free registry";
        }
    }
    expect($violations)->toBe([]);
});

it('Pro-gated streaming tools are absent from the Free registry', function() {
    $proGated = [
        BulkEntries::class,
        ScaffoldEntries::class,
    ];

    $tools = Herald::getInstance()->tools;
    $violations = [];
    foreach ($proGated as $class) {
        $name = $class::getName();
        if ($tools->getByName($name) !== null) {
            $violations[] = "{$name}: present in Free registry (should be Pro-only)";
        }
    }
    expect($violations)->toBe([]);
});
