<?php

/**
 * =========================================================================
 * DocsController smoke tests.
 *
 * Drives each action with `--out=<tmpdir>` and asserts the generated
 * markdown carries the expected counts, headings, and entries from the
 * live registries.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\Plugin;

beforeEach(function() {
    $this->tmp = sys_get_temp_dir() . '/cortex-docs-' . uniqid();
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
    Craft::$app->runAction('cortex/docs/tools', ['out' => $this->tmp]);

    $path = $this->tmp . '/TOOLS.md';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    $expectedCount = Plugin::getInstance()->tools->getCount();
    expect($contents)->toContain("**Total tools:** {$expectedCount}");
    expect($contents)->toContain('## `sections`');
    expect($contents)->toContain('## `craft_exec`');
    expect($contents)->toContain('"type": "object"');
});

it('generates PROMPTS.md with prompt headings', function() {
    Craft::$app->runAction('cortex/docs/prompts', ['out' => $this->tmp]);

    $path = $this->tmp . '/PROMPTS.md';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    $expectedCount = Plugin::getInstance()->prompts->getCount();
    expect($contents)->toContain("**Total prompts:** {$expectedCount}");
    expect($contents)->toContain('## `craftcms_extending`');
});

it('generates RESOURCES.md with a Markdown table', function() {
    Craft::$app->runAction('cortex/docs/resources', ['out' => $this->tmp]);

    $path = $this->tmp . '/RESOURCES.md';
    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);
    $expectedCount = Plugin::getInstance()->resources->getCount();
    expect($contents)->toContain("**Total resources:** {$expectedCount}");
    expect($contents)->toContain('| URI | Name | MIME | Description |');
});

it('all action runs the three generators', function() {
    Craft::$app->runAction('cortex/docs/all', ['out' => $this->tmp]);

    expect(file_exists($this->tmp . '/TOOLS.md'))->toBeTrue();
    expect(file_exists($this->tmp . '/PROMPTS.md'))->toBeTrue();
    expect(file_exists($this->tmp . '/RESOURCES.md'))->toBeTrue();
});
