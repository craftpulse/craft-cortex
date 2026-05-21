<?php

/**
 * =========================================================================
 * Registry-level invariants for the Tools service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\ToolInterface;

it('registers every bundled tool by name', function() {
    $expected = [
        // Orientation
        'get_initial_context',

        // Schema & structure
        'sections',
        'entry_types',
        'fields',
        'field_types',
        'category_groups',
        'tag_groups',
        'volumes_and_filesystems',
        'sites',
        'image_transforms',
        'element_types',

        // Content reading
        'entries',
        'assets',
        'categories',
        'tags',
        'globals',

        // System & diagnostics
        'system_info',
        'config',
        'plugins',
        'routes',
        'system_diagnostics',
        'database_schema',
        'extensibility',
        'permissions_and_groups',
        'search_skills',

        // GraphQL & dev actions
        'graphql',
        'clear_caches',
        'resave',
        'craft_command',
        'craft_exec',

        // Workflow & audit (read modes)
        'drafts_and_revisions',
        'content_audit',
        'import_export',
    ];

    $tools = Cortex::getInstance()->tools;

    expect($tools->getCount())->toBe(count($expected));

    foreach ($expected as $name) {
        $tool = $tools->getByName($name);
        expect($tool)->toBeInstanceOf(ToolInterface::class);
        expect($tool::getName())->toBe($name);
    }
});

it('returns null for unknown tool names', function() {
    expect(Cortex::getInstance()->tools->getByName('nope'))->toBeNull();
});

it('builds an MCP-shaped tools/list payload', function() {
    $payload = Cortex::getInstance()->tools->asListPayload();

    expect($payload)->toBeArray()->toHaveCount(Cortex::getInstance()->tools->getCount());

    foreach ($payload as $item) {
        expect($item)->toBeMcpToolListItem();
    }
});

it('exposes every tool with a non-empty description and a JSON Schema input shape', function() {
    foreach (Cortex::getInstance()->tools->getAll() as $tool) {
        expect($tool::getDescription())
            ->toBeString()
            ->not->toBeEmpty();

        $schema = $tool::getInputSchema();
        expect($schema)
            ->toHaveKey('type', 'object')
            ->toHaveKey('properties');
    }
});
