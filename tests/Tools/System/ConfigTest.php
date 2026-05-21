<?php

/**
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\Cortex;
use craftpulse\cortex\tools\ToolException;

beforeEach(function() {
    $this->tool = Cortex::getInstance()->tools->getByName('config');
});

it('throws when mode is missing', function() {
    $this->tool->execute([]);
})->throws(ToolException::class);

it('returns the curated general config', function() {
    $result = $this->tool->execute(['mode' => 'general']);

    expect($result)->toHaveKey('mode', 'general');
    expect($result)->toHaveKeys(['devMode', 'cpTrigger', 'pageTrigger', 'timezone']);
    // No secrets in general — assert known sensitive keys are absent.
    expect($result)->not->toHaveKey('securityKey');
    expect($result)->not->toHaveKey('cookieValidationKey');
});

it('returns the db config without user/password/dsn', function() {
    $result = $this->tool->execute(['mode' => 'db']);

    expect($result)->toHaveKey('mode', 'db');
    expect($result)->toHaveKeys(['driver', 'server', 'database']);
    expect($result)->not->toHaveKey('user');
    expect($result)->not->toHaveKey('password');
    expect($result)->not->toHaveKey('dsn');
});

it('redacts any custom-config key whose name contains a secret needle', function() {
    $result = $this->tool->execute(['mode' => 'custom']);

    expect($result)->toHaveKey('mode', 'custom');
    expect($result)->toHaveKey('values');

    $walk = function($node) use (&$walk): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $lower = strtolower(str_replace(['_', '-'], '', $key));
                $needles = ['password', 'securitykey', 'token', 'secret', 'apikey'];
                foreach ($needles as $needle) {
                    if (str_contains($lower, $needle)) {
                        expect($value)->toBe('<redacted>');
                    }
                }
            }
            if (is_array($value)) {
                $walk($value);
            }
        }
    };

    $walk($result['values']);
});

it('returns email config', function() {
    $result = $this->tool->execute(['mode' => 'email']);

    expect($result)->toHaveKey('mode', 'email');
    expect($result)->toHaveKey('config');
});

it('returns system_messages list', function() {
    $result = $this->tool->execute(['mode' => 'system_messages']);

    expect($result)->toHaveKey('mode', 'system_messages');
    expect($result)->toHaveKeys(['messages', 'count']);
});

it('throws on unknown mode', function() {
    $this->tool->execute(['mode' => 'oops']);
})->throws(ToolException::class, 'Unknown mode');
