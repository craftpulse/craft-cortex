<?php

/**
 * =========================================================================
 * Tests for the capability-scope service.
 *
 * Covers the scope vocabulary, the tool→scope authority, legacy
 * expansion, and the `grantsTool()` gate that the HTTP dispatcher
 * consults. The stdio-null path (no scope gating) is asserted alongside
 * the HTTP path (explicit granted set).
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\services\Scopes;

it('advertises the full capability vocabulary', function() {
    $all = Herald::getInstance()->scopes->all();

    expect($all)->toContain(Scopes::CONTENT_READ)
        ->and($all)->toContain(Scopes::CONTENT_WRITE)
        ->and($all)->toContain(Scopes::ASSETS_WRITE)
        ->and($all)->toContain(Scopes::SCHEMA_READ)
        ->and($all)->toContain(Scopes::SYSTEM_READ)
        ->and($all)->toContain(Scopes::USERS_READ)
        ->and($all)->toContain(Scopes::USERS_WRITE);
});

it('does not advertise the dead content:publish / content:delete scopes', function() {
    // MINOR 7: `entry` maps every mode to `content:write`, so the
    // dispatcher never enforces a distinct publish / delete scope.
    // Advertising them would claim granular capabilities the consent
    // screen can't honour, so they're dropped from the vocabulary.
    $all = Herald::getInstance()->scopes->all();

    expect($all)->not->toContain('content:publish')
        ->and($all)->not->toContain('content:delete');
});

it('does not advertise the legacy coarse scopes', function() {
    $all = Herald::getInstance()->scopes->all();

    expect($all)->not->toContain('read')
        ->and($all)->not->toContain('write');
});

it('accepts capability and legacy scopes as known', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->isKnown(Scopes::CONTENT_WRITE))->toBeTrue()
        ->and($scopes->isKnown('read'))->toBeTrue()
        ->and($scopes->isKnown('write'))->toBeTrue()
        ->and($scopes->isKnown('not-a-scope'))->toBeFalse()
        ->and($scopes->isKnown(''))->toBeFalse();
});

it('maps every registered tool to an EXPLICIT scope key (no fail-open gaps)', function() {
    // MAJOR 6: assert each registered tool is an explicit key in
    // TOOL_SCOPES, not merely that scopeForTool() returns a known scope —
    // the latter passes even for an unmapped tool because the (former)
    // fallback was itself a known scope. An explicit key is the only thing
    // that proves a tool isn't silently riding the fail-closed default.
    $scopes = Herald::getInstance()->scopes;

    herald_with_pro_registry(function() use ($scopes): void {
        foreach (Herald::getInstance()->tools->getAll() as $tool) {
            $name = $tool::getName();
            expect(array_key_exists($name, Scopes::TOOL_SCOPES))->toBeTrue(
                sprintf('Tool %s is not an explicit key in Scopes::TOOL_SCOPES', $name),
            );
            // And the mapped scope is itself grantable (advertised).
            expect($scopes->isKnown($scopes->scopeForTool($name)))->toBeTrue(
                sprintf('Tool %s maps to unknown scope %s', $name, $scopes->scopeForTool($name)),
            );
        }
    });
});

it('maps content read and write tools to distinct scopes', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->scopeForTool('entries'))->toBe(Scopes::CONTENT_READ)
        ->and($scopes->scopeForTool('entry'))->toBe(Scopes::CONTENT_WRITE)
        ->and($scopes->scopeForTool('users'))->toBe(Scopes::USERS_WRITE)
        ->and($scopes->scopeForTool('sections'))->toBe(Scopes::SCHEMA_READ);
});

it('fails closed for an unmapped tool: returns the ungrantable NONE scope', function() {
    // MAJOR 6: an unmapped tool must NOT default to a read scope. It maps
    // to the NONE sentinel, which is ungrantable (isKnown() rejects it),
    // so no token can ever cover it and grantsTool() denies it over HTTP.
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->scopeForTool('totally_unknown_tool'))->toBe(Scopes::NONE)
        ->and($scopes->isKnown(Scopes::NONE))->toBeFalse()
        ->and($scopes->grantsTool('totally_unknown_tool', [Scopes::SYSTEM_READ]))->toBeFalse()
        ->and($scopes->grantsTool('totally_unknown_tool', $scopes->all()))->toBeFalse();
});

it('grants every tool when the scope set is null (stdio)', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->grantsTool('entry', null))->toBeTrue()
        ->and($scopes->grantsTool('users', null))->toBeTrue();
});

it('gates a tool whose scope is not in the granted set', function() {
    $scopes = Herald::getInstance()->scopes;

    // A read-only grant cannot reach the content:write `entry` tool.
    expect($scopes->grantsTool('entry', [Scopes::CONTENT_READ]))->toBeFalse()
        ->and($scopes->grantsTool('entries', [Scopes::CONTENT_READ]))->toBeTrue();
});

it('expands the legacy read scope to the read cluster', function() {
    $scopes = Herald::getInstance()->scopes;
    $expanded = $scopes->expandLegacyScopes(['read']);

    expect($expanded)->toContain(Scopes::CONTENT_READ)
        ->and($expanded)->toContain(Scopes::SCHEMA_READ)
        ->and($expanded)->toContain(Scopes::SYSTEM_READ)
        ->and($expanded)->toContain(Scopes::USERS_READ)
        ->and($expanded)->not->toContain(Scopes::CONTENT_WRITE);
});

it('expands the legacy write scope to every scope', function() {
    $scopes = Herald::getInstance()->scopes;
    $expanded = $scopes->expandLegacyScopes(['write']);

    foreach ($scopes->all() as $scope) {
        expect($expanded)->toContain($scope);
    }
});

it('grants a write tool via the legacy write scope', function() {
    $scopes = Herald::getInstance()->scopes;

    expect($scopes->grantsTool('entry', ['write']))->toBeTrue()
        ->and($scopes->grantsTool('entry', ['read']))->toBeFalse();
});

it('filters a requested scope list to known scopes', function() {
    $scopes = Herald::getInstance()->scopes;
    $filtered = $scopes->filterKnown([Scopes::CONTENT_READ, 'bogus', 'read', Scopes::CONTENT_READ]);

    expect($filtered)->toBe([Scopes::CONTENT_READ, 'read']);
});

it('describes every capability scope in plain English', function() {
    $scopes = Herald::getInstance()->scopes;

    foreach ($scopes->all() as $scope) {
        expect($scopes->describe($scope))->toBeString()->not->toBeEmpty();
    }
});
