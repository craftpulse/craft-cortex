<?php

/**
 * =========================================================================
 * Tests for the six layered security gates on `craft_exec`:
 *
 *   1. Dry-run default
 *   2. Structured output / typed errors
 *   3. Secret redaction
 *   4. Destructive-op guard
 *   5. stdio-only enforcement (covered in Mcp/ServerTest)
 *   6. dangerous annotation
 *
 * Plus the settings toggle (`execEnabled`) and a few sanity cases.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('craft_exec');
});

it('throws when expression is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class, '`expression` is required');

// -----------------------------------------------------------------------------
// Dry-run default
// -----------------------------------------------------------------------------

it('returns dry-run analysis without confirm: does NOT evaluate', function() {
    $result = $this->tool->execute([
        'expression' => '1 + 1',
    ]);

    expect($result)->toHaveKey('mode', 'dry_run');
    expect($result)->toHaveKey('evaluated', false);
    expect($result)->toHaveKey('blocked', false);
    expect($result)->toHaveKey('expression', '1 + 1');
    expect($result)->toHaveKey('isDestructive', false);
    expect($result)->toHaveKey('hint');
    expect($result)->not->toHaveKey('result');
});

it('evaluates only when confirm=true', function() {
    $result = $this->tool->execute([
        'expression' => '1 + 1',
        'confirm' => true,
    ]);

    expect($result)->toHaveKey('mode', 'evaluated');
    expect($result)->toHaveKey('evaluated', true);
    expect($result)->toHaveKey('hasResult', true);
    expect($result)->toHaveKey('result', 2);
});

// -----------------------------------------------------------------------------
// Structured output / typed errors
// -----------------------------------------------------------------------------

it('structured success envelope with hasResult + result fields', function() {
    $result = $this->tool->execute([
        'expression' => '["a" => 1, "b" => 2]',
        'confirm' => true,
    ]);

    expect($result)->toHaveKeys(['mode', 'evaluated', 'expression', 'hasResult', 'result', 'output']);
    expect($result['hasResult'])->toBeTrue();
    expect($result['result'])->toBe(['a' => 1, 'b' => 2]);
});

it('parse errors are typed', function() {
    $result = $this->tool->execute([
        'expression' => '$$$invalid$$$',
        'confirm' => true,
    ]);

    expect($result)->toHaveKey('error');
    expect($result['error'])->toHaveKey('type');
    expect($result['error']['type'])->toBeIn(['parse_error', 'runtime_error']);
    expect($result['error'])->toHaveKey('message');
    expect($result['error'])->toHaveKey('trace');
});

it('runtime errors include class + file + line', function() {
    $result = $this->tool->execute([
        'expression' => 'throw new \\RuntimeException("boom")',
        'confirm' => true,
    ]);

    expect($result)->toHaveKey('error');
    expect($result['error'])->toHaveKey('type', 'runtime_error');
    expect($result['error'])->toHaveKey('class', RuntimeException::class);
    expect($result['error'])->toHaveKey('message', 'boom');
    expect($result['error'])->toHaveKey('file');
    expect($result['error'])->toHaveKey('line');
    expect($result['error'])->toHaveKey('trace');
});

// -----------------------------------------------------------------------------
// Secret redaction
// -----------------------------------------------------------------------------

it('redacts secret-keyed values in array results', function() {
    $result = $this->tool->execute([
        'expression' => '["password" => "hunter2", "name" => "alice"]',
        'confirm' => true,
    ]);

    expect($result['result'])->toHaveKey('password', '<redacted>');
    expect($result['result'])->toHaveKey('name', 'alice');
});

it('redacts API_KEY=value patterns in string results', function() {
    $result = $this->tool->execute([
        'expression' => '"API_KEY=abc123 trailing"',
        'confirm' => true,
    ]);

    expect($result['result'])->toBeString();
    expect($result['result'])->toContain('API_KEY=<redacted>');
    expect($result['result'])->not->toContain('abc123');
});

it('redacts secrets surfaced via stdout (echo) too', function() {
    $result = $this->tool->execute([
        'expression' => 'echo "SECURITY_KEY=topsecret"',
        'confirm' => true,
    ]);

    expect($result)->toHaveKey('output');
    expect($result['output'])->toContain('SECURITY_KEY=<redacted>');
    expect($result['output'])->not->toContain('topsecret');
});

// -----------------------------------------------------------------------------
// Destructive-op guard
// -----------------------------------------------------------------------------

it('detects destructive patterns and refuses with confirm alone', function() {
    $result = $this->tool->execute([
        'expression' => 'Craft::$app->elements->deleteElementById(1)',
        'confirm' => true,
    ]);

    // Confirm alone is NOT enough — a destructive expression with confirm:true
    // and dangerous:false comes back as blocked, NOT evaluated.
    expect($result)->toHaveKey('mode', 'dry_run');
    expect($result)->toHaveKey('evaluated', false);
    expect($result)->toHaveKey('blocked', true);
    expect($result)->toHaveKey('isDestructive', true);
});

it('dangerous alone (without confirm) still treats as dry-run', function() {
    $result = $this->tool->execute([
        'expression' => 'Craft::$app->elements->deleteElementById(1)',
        'dangerous' => true,
    ]);

    expect($result)->toHaveKey('evaluated', false);
});

it('matches drop / truncate / migrate-down patterns', function() {
    foreach ([
        'Craft::$app->db->createCommand()->dropTable("foo")',
        'Craft::$app->db->createCommand()->truncateTable("foo")',
        'Craft::$app->runAction("migrate/down")',
    ] as $expr) {
        $result = $this->tool->execute(['expression' => $expr]);
        expect($result['isDestructive'])
            ->toBeTrue()
            ->and($result['expression'])->toBe($expr);
    }
});

it('non-destructive expressions report isDestructive=false', function() {
    $result = $this->tool->execute([
        'expression' => 'count(Craft::$app->getEntries()->getAllSections())',
    ]);

    expect($result)->toHaveKey('isDestructive', false);
});

// -----------------------------------------------------------------------------
// stdio-only (asserted at the dispatcher level too — see Mcp/ServerTest)
// -----------------------------------------------------------------------------

it('declares isStdioOnly = true via attribute', function() {
    expect(\craftpulse\herald\tools\support\AttributeReader::isStdioOnly($this->tool))->toBeTrue();
});

// -----------------------------------------------------------------------------
// Tool annotation
// -----------------------------------------------------------------------------

it('exposes destructiveHint:true via attribute reader', function() {
    $annotations = \craftpulse\herald\tools\support\AttributeReader::annotationsFor($this->tool);
    expect($annotations)->toHaveKey('destructiveHint', true);
    expect($annotations)->toHaveKey('idempotentHint', false);
});

// -----------------------------------------------------------------------------
// Settings — execDryRunDefault toggle
// -----------------------------------------------------------------------------

it('execDryRunDefault=false makes evaluation the default when confirm is absent', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->execDryRunDefault;
    $settings->execDryRunDefault = false;

    try {
        $result = $this->tool->execute([
            'expression' => '21 + 21',
        ]);

        expect($result)->toHaveKey('mode', 'evaluated');
        expect($result)->toHaveKey('evaluated', true);
        expect($result)->toHaveKey('result', 42);
    } finally {
        $settings->execDryRunDefault = $original;
    }
});

it('execDryRunDefault=false still respects explicit confirm=false (caller wins)', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->execDryRunDefault;
    $settings->execDryRunDefault = false;

    try {
        $result = $this->tool->execute([
            'expression' => '21 + 21',
            'confirm' => false,
        ]);

        expect($result)->toHaveKey('mode', 'dry_run');
        expect($result)->toHaveKey('evaluated', false);
        expect($result)->not->toHaveKey('result');
    } finally {
        $settings->execDryRunDefault = $original;
    }
});

it('execDryRunDefault=false does not bypass the destructive guard', function() {
    $settings = Herald::getInstance()->getSettings();
    $original = $settings->execDryRunDefault;
    $settings->execDryRunDefault = false;

    try {
        $result = $this->tool->execute([
            'expression' => 'Entry::find()->one()->delete()',
        ]);

        // Even with non-dry-run default, destructive expressions still
        // require explicit `dangerous: true`.
        expect($result)->toHaveKey('blocked', true);
        expect($result)->toHaveKey('isDestructive', true);
    } finally {
        $settings->execDryRunDefault = $original;
    }
});

// -----------------------------------------------------------------------------
// Sanity
// -----------------------------------------------------------------------------

it('strips leading <?php and trailing semicolons before eval', function() {
    $result = $this->tool->execute([
        'expression' => '<?php 2 * 21;',
        'confirm' => true,
    ]);

    expect($result['result'])->toBe(42);
    expect($result['expression'])->toBe('2 * 21');
});

it('returns hasResult=false when the expression is a statement (parse-error fallback)', function() {
    // `if (true) { $x = 1; }` is not a valid expression → eval as statement.
    $result = $this->tool->execute([
        'expression' => 'if (true) { $x = 1; }',
        'confirm' => true,
    ]);

    expect($result['evaluated'])->toBeTrue();
    expect($result['hasResult'])->toBeFalse();
});
