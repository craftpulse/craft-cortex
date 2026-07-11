<?php

/**
 * =========================================================================
 * Scope-gating tests at the registry boundary.
 *
 * `Tools::asListPayloadFor()` / `getByNameFor()` are the seam the HTTP
 * dispatcher consults — a tool surfaces over HTTP only when its required
 * capability scope is in the granted set (and the user permission +
 * edition gates also pass). stdio passes a null scope set and sees
 * everything its permissions allow.
 *
 * Uses `herald_with_pro_registry()` so the Pro write tools (`entry`,
 * `users`) are present in the registry under test.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\services\Scopes;

/**
 * Collect the tool names from a tools/list payload.
 *
 * @param array<int,array<string,mixed>> $payload
 * @return string[]
 */
function herald_scope_tool_names(array $payload): array
{
    return array_map(static fn(array $entry): string => (string) $entry['name'], $payload);
}

it('null scope set surfaces read and write tools (stdio path)', function() {
    herald_with_pro_registry(function(): void {
        $payload = Herald::getInstance()->tools->asListPayloadFor(null, null);
        $names = herald_scope_tool_names($payload);

        expect($names)->toContain('entries')
            ->and($names)->toContain('entry');
    });
});

it('a content:read grant hides content:write tools from tools/list', function() {
    herald_with_pro_registry(function(): void {
        $payload = Herald::getInstance()->tools->asListPayloadFor(null, [Scopes::CONTENT_READ]);
        $names = herald_scope_tool_names($payload);

        expect($names)->toContain('entries')
            ->and($names)->not->toContain('entry');
    });
});

it('a content:write grant surfaces the entry write tool', function() {
    herald_with_pro_registry(function(): void {
        $payload = Herald::getInstance()->tools->asListPayloadFor(null, [Scopes::CONTENT_WRITE]);
        $names = herald_scope_tool_names($payload);

        expect($names)->toContain('entry');
    });
});

it('getByNameFor returns null when the granted scope lacks the tool scope', function() {
    herald_with_pro_registry(function(): void {
        $tool = Herald::getInstance()->tools->getByNameFor('entry', null, [Scopes::CONTENT_READ]);
        expect($tool)->toBeNull();
    });
});

it('getByNameFor resolves the tool when the granted scope covers it', function() {
    herald_with_pro_registry(function(): void {
        $tool = Herald::getInstance()->tools->getByNameFor('entry', null, [Scopes::CONTENT_WRITE]);
        expect($tool)->not->toBeNull()
            ->and($tool::getName())->toBe('entry');
    });
});

it('getByNameFor resolves any tool when the scope set is null', function() {
    herald_with_pro_registry(function(): void {
        $tool = Herald::getInstance()->tools->getByNameFor('entry', null, null);
        expect($tool)->not->toBeNull();
    });
});

it('a legacy write scope grants write tools through expansion', function() {
    herald_with_pro_registry(function(): void {
        $tool = Herald::getInstance()->tools->getByNameFor('entry', null, ['write']);
        expect($tool)->not->toBeNull();
    });
});
