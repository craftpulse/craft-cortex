<?php

/**
 * =========================================================================
 * Architecture invariant tests for the per-user tool filtering contract
 * (Gate 7.4 — `ToolInterface::filterFor()` + `inputSchemaFor()`).
 *
 * Iterates every registered tool and asserts the locked default-true
 * / static-schema behaviour. A future Pro tool that overrides one of
 * these methods without preserving the contract for `null` users
 * trips the assertion here before it ships.
 *
 * Locked contract: per-user tool visibility.
 *
 *   - `filterFor(null)` must return `true` for every Free tool.
 *     Stdio passes `null`; the stdio registry must surface every
 *     bundled tool without exception.
 *   - `inputSchemaFor(null)` must equal `static::getInputSchema()` —
 *     the stdio path is the static-schema path; mode-gated rewrites
 *     only fire for non-null users.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolInterface;

it('every registered tool returns filterFor(null) === true', function() {
    $violations = [];
    foreach (Herald::getInstance()->tools->getAll() as $tool) {
        if (!$tool->filterFor(null)) {
            $violations[] = sprintf(
                '%s (%s) returned false for filterFor(null) — stdio invariant broken',
                $tool::getName(),
                $tool::class,
            );
        }
    }

    expect($violations)->toBe([]);
});

it('every registered tool returns inputSchemaFor(null) === static::getInputSchema()', function() {
    $violations = [];
    foreach (Herald::getInstance()->tools->getAll() as $tool) {
        $perUser = $tool->inputSchemaFor(null);
        $static = $tool::getInputSchema();

        // Structural equality, not identity. Both calls produce fresh
        // `stdClass` instances from `(object) []` casts in many schema
        // definitions; identity comparison would never hold even for
        // a correct implementation that returns the static schema
        // verbatim. JSON-encoding both sides gives the byte-form the
        // wire sees and removes the identity noise.
        if (json_encode($perUser) !== json_encode($static)) {
            $violations[] = sprintf(
                '%s (%s) returned a different schema from inputSchemaFor(null) than static::getInputSchema()',
                $tool::getName(),
                $tool::class,
            );
        }
    }

    expect($violations)->toBe([]);
});

it('every registered tool implements both filterFor and inputSchemaFor', function() {
    // Reflection-level check — guards against a future tool implementing
    // ToolInterface directly (bypassing AbstractTool defaults) and
    // forgetting the new contract methods. ToolInterface declares both
    // as abstract; this test exercises the resolve-via-reflection path
    // so a missing override is detected at the test layer rather than
    // at autoload time.
    $missing = [];
    foreach (Herald::getInstance()->tools->getAll() as $tool) {
        $rc = new ReflectionClass($tool);

        if (!$rc->hasMethod('filterFor')) {
            $missing[] = sprintf('%s missing filterFor()', $tool::class);
        }
        if (!$rc->hasMethod('inputSchemaFor')) {
            $missing[] = sprintf('%s missing inputSchemaFor()', $tool::class);
        }
    }

    expect($missing)->toBe([]);
});

it('the count of tools surfacing for null-user matches the stdio registry size', function() {
    // Sanity bridge between the unit-level invariant test and the
    // service-level one: the full count of asListPayloadFor(null) must
    // equal getCount(). If a tool returned false for filterFor(null)
    // the first invariant test catches it; this test catches a future
    // bug where filterFor returns true but the tool drops out of the
    // payload for some other reason.
    $service = Herald::getInstance()->tools;
    $payload = $service->asListPayloadFor(null);

    expect($payload)->toHaveCount($service->getCount());
});

it('every registered tool implements ToolInterface', function() {
    // Belt-and-braces against an extension-event listener appending
    // something that isn't a ToolInterface. The registry skips those
    // during init() — this test guards that the skip-logic worked.
    foreach (Herald::getInstance()->tools->getAll() as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});
