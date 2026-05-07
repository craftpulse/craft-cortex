<?php

/**
 * =========================================================================
 * InstallController snippet shape tests.
 *
 * Locks the per-client snippet shape against regression — bad snippets
 * are a Phase 1 ship blocker because they're the user's first encounter
 * with cortex. Each test asserts the strings that have to appear (and,
 * for the high-risk ones, the strings that MUST NOT appear).
 *
 * The controller is exercised through its `buildSnippet()` helper, which
 * returns the per-client copy-paste body as a string — bypasses the
 * Yii console output capture entirely.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */

use craftpulse\cortex\console\controllers\InstallController;

beforeEach(function () {
    $this->controller = new InstallController('install', Craft::$app);
    $this->command = 'docker exec -i ddev-myproject-web php /var/www/html/craft cortex/serve';
});

it('claude-code snippet uses the -- separator (otherwise -i parses as a Claude flag)', function () {
    $body = $this->controller->buildSnippet('claude-code', $this->command);

    expect($body)
        ->toContain('claude mcp add --transport stdio cortex -- docker exec -i ')
        ->and($body)->not->toContain('claude mcp add cortex docker'); // pre-fix shape
});

it('continue.dev snippet uses YAML and the modern mcpServers key', function () {
    $body = $this->controller->buildSnippet('continue', $this->command);

    expect($body)
        ->toContain('config.yaml')
        ->toContain('mcpServers:')
        ->toContain('- name: cortex')
        ->toContain('command: docker')
        ->and($body)->not->toContain('experimental.modelContextProtocolServers') // deprecated
        ->and($body)->not->toContain('config.json'); // JSON form is legacy / different file
});

it('zed snippet uses context_servers at the top level', function () {
    $body = $this->controller->buildSnippet('zed', $this->command);

    expect($body)
        ->toContain('context_servers')
        ->and($body)->not->toContain('assistant.mcp_servers'); // wrong key
});

it('claude-desktop snippet has all three platform paths and the mcpServers shape', function () {
    $body = $this->controller->buildSnippet('claude-desktop', $this->command);

    expect($body)
        ->toContain('claude_desktop_config.json')
        ->toContain('mcpServers')
        ->toContain('"command": "docker"');
});

it('cursor snippet references the home + project mcp.json paths', function () {
    $body = $this->controller->buildSnippet('cursor', $this->command);

    expect($body)
        ->toContain('~/.cursor/mcp.json')
        ->toContain('mcpServers')
        ->toContain('"command": "docker"');
});

it('cline snippet references the VS Code globalStorage path', function () {
    $body = $this->controller->buildSnippet('cline', $this->command);

    expect($body)
        ->toContain('saoudrizwan.claude-dev')
        ->toContain('mcpServers');
});

it('windsurf snippet references the codeium config path', function () {
    $body = $this->controller->buildSnippet('windsurf', $this->command);

    expect($body)
        ->toContain('~/.codeium/windsurf/mcp_config.json')
        ->toContain('mcpServers');
});

it('returns empty string for an unknown client', function () {
    $body = $this->controller->buildSnippet('unknown-client', $this->command);

    expect($body)->toBe('');
});
