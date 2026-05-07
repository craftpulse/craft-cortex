<?php

/**
 * =========================================================================
 * Registry-level invariants for the Tools service.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;
use craftpulse\cortex\tools\ToolInterface;

it('registers all Phase-1 tools by name', function () {
    $expected = [
        // Schema (Gate 2)
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

        // Content reading (Gate 3)
        'entries',
        'assets',
        'categories',
        'tags',
        'globals',

        // System & diagnostics (Gate 4)
        'system_info',
        'config',
        'plugins',
        'routes',
        'diagnostics',
        'database_schema',
        'extensibility',
        'permissions_and_groups',

        // GraphQL & Dev Actions (Gate 5)
        'graphql',
        'clear_caches',
        'resave',
        'craft_command',
        'craft_exec',

        // Workflow & Audit — read modes (Gate 6.5)
        'drafts_and_revisions',
    ];

    $tools = Plugin::getInstance()->tools;

    expect($tools->getCount())->toBe(count($expected));

    foreach ($expected as $name) {
        $tool = $tools->getByName($name);
        expect($tool)->toBeInstanceOf(ToolInterface::class);
        expect($tool::getName())->toBe($name);
    }
});

it('returns null for unknown tool names', function () {
    expect(Plugin::getInstance()->tools->getByName('nope'))->toBeNull();
});

it('builds an MCP-shaped tools/list payload', function () {
    $payload = Plugin::getInstance()->tools->asListPayload();

    expect($payload)->toBeArray()->toHaveCount(Plugin::getInstance()->tools->getCount());

    foreach ($payload as $item) {
        expect($item)->toBeMcpToolListItem();
    }
});

it('exposes every tool with a non-empty description and a JSON Schema input shape', function () {
    foreach (Plugin::getInstance()->tools->getAll() as $tool) {
        expect($tool::getDescription())
            ->toBeString()
            ->not->toBeEmpty();

        $schema = $tool::getInputSchema();
        expect($schema)
            ->toHaveKey('type', 'object')
            ->toHaveKey('properties');
    }
});
