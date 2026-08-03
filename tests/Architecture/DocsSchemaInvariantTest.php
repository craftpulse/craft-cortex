<?php

/**
 * =========================================================================
 * Architecture invariants for `AbstractTool::docsInputSchema()` — the
 * documentation-only schema surface the `herald/docs/tools` generator
 * reads.
 *
 * Four tools gate an enum on the live edition inside `getInputSchema()`:
 * `content_audit`, `drafts_and_revisions` and `import_export` on `mode`,
 * `system_diagnostics` on `type`. That gate is correct on the wire and
 * stays. It is wrong in committed documentation, which would otherwise
 * describe a different tool surface depending on which edition the
 * generator ran on. `docsInputSchema()` is the fix, and this suite is
 * what keeps the two in step:
 *
 *   - Nothing's `docsInputSchema()` may vary by edition. Computed on
 *     Free and on Pro, it must be byte-identical.
 *   - On a Pro install, every tool's `docsInputSchema()` must equal its
 *     `getInputSchema()`. The docs schema is the complete runtime schema,
 *     never a hand-maintained copy of it, so a property or a mode added
 *     to the runtime schema cannot silently miss the reference.
 *   - The four documented enums are pinned against the locked mode/type
 *     names, so dropping one from the docs surface fails here too.
 *
 * This suite runs against a Free install (`plugins.herald.edition` is
 * `free` in `herald_fixtures`); `herald_with_edition()` supplies the Pro
 * half. No registry rebuild is needed: the assertions call static schema
 * methods on tool instances the unfiltered registry already holds.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\ToolInterface;

/**
 * Every registered tool that extends `AbstractTool`, keyed by MCP name.
 * Reads the unfiltered registry so the Pro tools absent from this Free
 * install are covered too.
 *
 * @return array<string,ToolInterface>
 */
function herald_docs_schema_tools(): array
{
    $tools = [];

    foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
        if ($tool instanceof AbstractTool) {
            $tools[$tool::getName()] = $tool;
        }
    }

    return $tools;
}

it('docsInputSchema is identical on Free and on Pro for every tool', function() {
    $onFree = [];
    foreach (herald_docs_schema_tools() as $name => $tool) {
        $onFree[$name] = json_encode($tool::docsInputSchema());
    }

    $onPro = herald_with_edition('pro', function() {
        $schemas = [];
        foreach (herald_docs_schema_tools() as $name => $tool) {
            $schemas[$name] = json_encode($tool::docsInputSchema());
        }
        return $schemas;
    });

    $drifted = [];
    foreach ($onFree as $name => $json) {
        if (($onPro[$name] ?? null) !== $json) {
            $drifted[] = $name;
        }
    }

    expect($drifted)->toBe([]);
});

it('docsInputSchema equals getInputSchema on a Pro install for every tool', function() {
    // The pin: whatever the runtime schema grows on Pro — a new property,
    // a new enum value — the documented schema grows with it, because the
    // four overrides share one schema body with `getInputSchema()`.
    $drifted = herald_with_edition('pro', function() {
        $out = [];
        foreach (herald_docs_schema_tools() as $name => $tool) {
            if (json_encode($tool::docsInputSchema()) !== json_encode($tool::getInputSchema())) {
                $out[] = $name;
            }
        }
        return $out;
    });

    expect($drifted)->toBe([]);
});

it('docsInputSchema differs from the Free getInputSchema for exactly the four edition-gated tools', function() {
    // The complement of the invariant above: on Free, the documented
    // schema is deliberately wider than the runtime one, and only for
    // these four. A fifth tool growing an edition-gated enum without a
    // `docsInputSchema()` override does not land here, it lands in the
    // "identical on Free and on Pro" failure above; this assertion keeps
    // the known set from quietly growing or shrinking.
    $wider = [];
    foreach (herald_docs_schema_tools() as $name => $tool) {
        if (json_encode($tool::docsInputSchema()) !== json_encode($tool::getInputSchema())) {
            $wider[] = $name;
        }
    }

    sort($wider);

    expect($wider)->toBe([
        'content_audit',
        'drafts_and_revisions',
        'import_export',
        'system_diagnostics',
    ]);
});

it('documents the complete locked enum for each edition-gated tool', function(string $name, string $property, array $expected) {
    $tool = herald_docs_schema_tools()[$name] ?? null;
    expect($tool)->not->toBeNull();

    $schema = $tool::docsInputSchema();

    expect($schema['properties'][$property]['enum'])->toBe($expected);
})->with([
    ['content_audit', 'mode', [
        'relations',
        'unused_assets',
        'propagation',
        'fix_relations',
        'prune_unused_assets',
        'repair_propagation',
    ]],
    ['drafts_and_revisions', 'mode', [
        'list_drafts',
        'list_revisions',
        'compare',
        'apply',
        'discard',
    ]],
    ['import_export', 'mode', [
        'export',
        'import',
    ]],
    ['system_diagnostics', 'type', [
        'logs',
        'last_error',
        'deprecations',
        'queue',
        'project_config_diff',
        'manage_queue',
    ]],
]);

it('leaves the runtime schema surface edition-gated', function() {
    // Guards the other direction: the docs hook must not have leaked into
    // the wire. On this Free install the runtime `mode` / `type` enums
    // still omit the Pro values, and `inputSchemaFor(null)` still tracks
    // `getInputSchema()`.
    $tools = herald_docs_schema_tools();

    expect($tools['content_audit']::getInputSchema()['properties']['mode']['enum'])
        ->toBe(['relations', 'unused_assets', 'propagation']);
    expect($tools['drafts_and_revisions']::getInputSchema()['properties']['mode']['enum'])
        ->toBe(['list_drafts', 'list_revisions', 'compare']);
    expect($tools['import_export']::getInputSchema()['properties']['mode']['enum'])
        ->toBe(['export']);
    expect($tools['system_diagnostics']::getInputSchema()['properties']['type']['enum'])
        ->toBe(['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff']);

    foreach (['content_audit', 'drafts_and_revisions', 'import_export', 'system_diagnostics'] as $name) {
        expect(json_encode($tools[$name]->inputSchemaFor(null)))
            ->toBe(json_encode($tools[$name]::getInputSchema()));
    }
});

it('defaults docsInputSchema to getInputSchema on AbstractTool', function() {
    // Every tool outside the four shares one schema definition between the
    // two methods by inheritance, so the default cannot rot.
    $tools = herald_docs_schema_tools();
    unset(
        $tools['content_audit'],
        $tools['drafts_and_revisions'],
        $tools['import_export'],
        $tools['system_diagnostics'],
    );

    expect($tools)->not->toBeEmpty();

    $overridden = [];
    foreach ($tools as $name => $tool) {
        $method = new ReflectionMethod($tool::class, 'docsInputSchema');
        if ($method->getDeclaringClass()->getName() !== AbstractTool::class) {
            $overridden[] = $name;
        }
    }

    expect($overridden)->toBe([]);
});
