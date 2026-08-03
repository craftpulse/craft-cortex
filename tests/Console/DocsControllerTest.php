<?php

/**
 * =========================================================================
 * DocsController smoke tests.
 *
 * Drives each action with `--out=<tmpdir>` and asserts the generated
 * markdown carries the expected counts, headings, and entries from the
 * live registries.
 *
 * The tool-reference tests are the guard on edition independence: this
 * suite runs against a Free install (`plugins.herald.edition` is `free`
 * in `herald_fixtures`), so a `TOOLS.md` generator that read the gated
 * registry would drop every Pro write tool and fail them, and one that
 * read `getInputSchema()` instead of `docsInputSchema()` would ship four
 * shortened enums and fail the byte-comparison below. The per-tool half
 * of that contract lives in
 * `tests/Architecture/DocsSchemaInvariantTest.php`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\ToolInterface;

/**
 * Tools the generator is expected to document: every registration minus
 * internal fixtures. Mirrors `DocsController::_documentedTools()`.
 *
 * @return ToolInterface[]
 */
function herald_documented_tools(): array
{
    $tools = [];

    foreach (Herald::getInstance()->tools->getAllUnfiltered() as $tool) {
        $name = $tool::getName();
        if (str_starts_with($name, '_') || isset($tools[$name])) {
            continue;
        }
        $tools[$name] = $tool;
    }

    return array_values($tools);
}

beforeEach(function() {
    $this->tmp = sys_get_temp_dir() . '/herald-docs-' . uniqid();
    mkdir($this->tmp, 0755, true);
});

afterEach(function() {
    if (is_dir($this->tmp)) {
        foreach (glob($this->tmp . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
    }
});

it('generates TOOLS.md with the right tool count and entries', function() {
    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);

    $path = $this->tmp . '/TOOLS.md';
    expect(file_exists($path))->toBeTrue();

    $documented = herald_documented_tools();
    $pro = array_filter(
        $documented,
        static fn(ToolInterface $tool): bool => in_array(ProToolTrait::class, class_uses($tool) ?: [], true),
    );
    $expectedPro = count($pro);
    $expectedFree = count($documented) - $expectedPro;

    $contents = file_get_contents($path);
    expect($contents)->toContain(sprintf(
        '**Total tools:** %d (%d Free, %d Pro)',
        count($documented),
        $expectedFree,
        $expectedPro,
    ));
    expect($contents)->toContain('## `sections`');
    expect($contents)->toContain('## `craft_exec`');
    expect($contents)->toContain('"type": "object"');
});

it('documents every tool the source registers regardless of the install edition', function() {
    // The nine Pro write tools, by MCP name. Hardcoded rather than
    // derived: this is the locked Pro surface, and the point of the
    // assertion is that the generated reference names all of it.
    $proWriteTools = [
        'entry',
        'category',
        'tag',
        'global_set',
        'address',
        'users',
        'skill',
        'bulk_entries',
        'scaffold_entries',
    ];

    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/TOOLS.md');

    // The Pro write tools are absent from this Free install's registry;
    // the reference must still carry them, labelled.
    foreach ($proWriteTools as $name) {
        expect($contents)->toContain("## `{$name}`");
    }

    expect(substr_count($contents, '**Edition:** Pro'))->toBe(count($proWriteTools));
    expect($contents)->toContain('**Edition:** Free');

    // Internal fixtures are not product surface.
    expect($contents)->not->toContain('## `_streaming_test`');
});

it('documents the complete mode and type enums of the edition-gated tools', function() {
    // This install is Free, so `content_audit`, `drafts_and_revisions`,
    // `import_export` and `system_diagnostics` all serve a shortened enum
    // on the wire. The reference documents the whole surface anyway.
    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/TOOLS.md');

    foreach ([
        'fix_relations',
        'prune_unused_assets',
        'repair_propagation',
        'apply',
        'discard',
        'import',
        'manage_queue',
    ] as $value) {
        expect($contents)->toContain("\"{$value}\"");
    }
});

it('generates a byte-identical TOOLS.md on Free and on Pro', function() {
    // The whole point of `docsInputSchema()`: the committed reference does
    // not encode the generating install's license state. Only the
    // generated-at line legitimately differs between the two runs.
    $strip = static fn(string $md): string => (string) preg_replace(
        '/^- \*\*Generated:\*\*.*$/m',
        '',
        $md,
    );

    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);
    $onFree = $strip((string) file_get_contents($this->tmp . '/TOOLS.md'));

    herald_with_edition('pro', function() {
        Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);
    });
    $onPro = $strip((string) file_get_contents($this->tmp . '/TOOLS.md'));

    expect($onPro)->toBe($onFree);
});

it('stamps no edition caveat into the generated TOOLS.md', function() {
    // The interim Free-install note and its stderr twin are gone; the
    // reference is complete on every edition, so there is nothing to warn
    // about and nothing for a reader to have to trust.
    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/TOOLS.md');

    expect($contents)
        ->not->toContain('Generated on a Free install')
        ->not->toContain('Regenerate on Pro');
});

it('documents one heading per tool, in registration order', function() {
    Craft::$app->runAction('herald/docs/tools', ['out' => $this->tmp]);

    $contents = file_get_contents($this->tmp . '/TOOLS.md');
    preg_match_all('/^## `([a-z_]+)`$/m', $contents, $matches);

    $expected = array_map(
        static fn(ToolInterface $tool): string => $tool::getName(),
        herald_documented_tools(),
    );

    expect($matches[1])->toBe($expected);
});

it('generates PROMPTS.md with prompt headings', function() {
    Craft::$app->runAction('herald/docs/prompts', ['out' => $this->tmp]);

    $path = $this->tmp . '/PROMPTS.md';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    $expectedCount = Herald::getInstance()->prompts->getCount();
    expect($contents)->toContain("**Total prompts:** {$expectedCount}");
    expect($contents)->toContain('## `craftcms_extending`');
});

it('generates RESOURCES.md with a Markdown table', function() {
    Craft::$app->runAction('herald/docs/resources', ['out' => $this->tmp]);

    $path = $this->tmp . '/RESOURCES.md';
    expect(file_exists($path))->toBeTrue();

    // Counted against `getBundled()`, not `getCount()`: the two coincide
    // on this install only because the fixtures' `herald_skills` table is
    // empty, and an assertion that holds by coincidence would stop
    // detecting a generator that reads the whole registry.
    // `tests/Resources/BundledRegistryTest.php` seeds a row and asserts
    // the difference.
    $contents = file_get_contents($path);
    $expectedCount = count(Herald::getInstance()->resources->getBundled());
    expect($contents)->toContain("**Total resources:** {$expectedCount}");
    expect($contents)->toContain('| URI | Name | MIME | Description |');
});

it('all action runs the three generators', function() {
    Craft::$app->runAction('herald/docs/all', ['out' => $this->tmp]);

    expect(file_exists($this->tmp . '/TOOLS.md'))->toBeTrue();
    expect(file_exists($this->tmp . '/PROMPTS.md'))->toBeTrue();
    expect(file_exists($this->tmp . '/RESOURCES.md'))->toBeTrue();
});
