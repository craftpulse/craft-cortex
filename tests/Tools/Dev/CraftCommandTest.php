<?php

/**
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\Herald;
use craftpulse\herald\tools\ToolException;

beforeEach(function() {
    $this->tool = Herald::getInstance()->tools->getByName('craft_command');
});

it('lists the active allowlist', function() {
    $result = $this->tool->execute(['mode' => 'list']);

    expect($result)->toHaveKey('mode', 'list');
    expect($result)->toHaveKey('patterns');
    expect($result)->toHaveKey('count');
    expect($result['count'])->toBe(count($result['patterns']));

    // Defaults from Settings::$allowedCommands must be present unless
    // the playground overrode them in project config or config/herald.php.
    expect($result['patterns'])->toContain('resave/*');
    expect($result['patterns'])->toContain('cache/*');
    expect($result['patterns'])->toContain('migrate/*');
    expect($result['patterns'])->toContain('up');
});

it('rejects a non-allowlisted command BEFORE reaching the runner', function() {
    // `serve` is not in the default allowlist. The exception comes from
    // the tool layer, not from Yii — proving the guard runs early.
    $this->tool->execute([
        'mode' => 'run',
        'command' => 'serve',
    ]);
})->throws(ToolException::class, "Command 'serve' is not in the allowlist");

it('throws when command is missing in run mode', function() {
    $this->tool->execute(['mode' => 'run']);
})->throws(ToolException::class, '`command` is required for mode=run');

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'kaboom']);
})->throws(ToolException::class, "Unknown mode: 'kaboom'");

it('strips a leading slash from the command before allowlist matching', function() {
    // `/up` should normalise to `up` and match — proving the LLM passing
    // a leading slash doesn't bypass or false-fail the allowlist.
    $result = $this->tool->execute([
        'mode' => 'run',
        'command' => '/up',
    ]);

    expect($result)->toHaveKey('mode', 'run');
    expect($result)->toHaveKey('command', 'up');
    expect($result)->toHaveKey('matchedPattern', 'up');
});

it('matches glob patterns and dispatches via the console runner', function() {
    $result = $this->tool->execute([
        'mode' => 'run',
        'command' => 'cache/flush',
    ]);

    expect($result)->toHaveKey('command', 'cache/flush');
    expect($result)->toHaveKey('matchedPattern', 'cache/*');
    expect($result)->toHaveKey('exitCode');
    expect($result)->toHaveKey('output');
});

it('exposes destructiveHint annotation', function() {
    $annotations = \craftpulse\herald\tools\support\AttributeReader::annotationsFor($this->tool);
    expect($annotations)->toHaveKey('destructiveHint', true);
});
