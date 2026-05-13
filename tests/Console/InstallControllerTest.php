<?php

/**
 * =========================================================================
 * InstallController snippet shape tests.
 *
 * Locks the per-client snippet shape against regression — bad snippets
 * are a ship blocker because they're the user's first encounter with
 * cortex. Each test asserts the strings that have to appear (and,
 * for the high-risk ones, the strings that MUST NOT appear).
 *
 * The controller is exercised through its `buildSnippet()` helper, which
 * returns the per-client copy-paste body as a string — bypasses the
 * Yii console output capture entirely.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\console\controllers\InstallController;

beforeEach(function() {
    $this->controller = new InstallController('install', Craft::$app);
    $this->command = 'docker exec -i ddev-myproject-web php /var/www/html/craft cortex/serve';
});

it('claude-code snippet uses the -- separator (otherwise -i parses as a Claude flag)', function() {
    $body = $this->controller->buildSnippet('claude-code', $this->command);

    expect($body)
        ->toContain('claude mcp add --transport stdio cortex -- docker exec -i ')
        ->and($body)->not->toContain('claude mcp add cortex docker'); // pre-fix shape
});

it('continue.dev snippet uses YAML and the modern mcpServers key', function() {
    $body = $this->controller->buildSnippet('continue', $this->command);

    expect($body)
        ->toContain('config.yaml')
        ->toContain('mcpServers:')
        ->toContain('- name: cortex')
        ->toContain('command: docker')
        ->and($body)->not->toContain('experimental.modelContextProtocolServers') // deprecated
        ->and($body)->not->toContain('config.json'); // JSON form is legacy / different file
});

it('zed snippet uses context_servers at the top level', function() {
    $body = $this->controller->buildSnippet('zed', $this->command);

    expect($body)
        ->toContain('context_servers')
        ->and($body)->not->toContain('assistant.mcp_servers'); // wrong key
});

it('claude-desktop snippet has all three platform paths and the mcpServers shape', function() {
    $body = $this->controller->buildSnippet('claude-desktop', $this->command);

    expect($body)
        ->toContain('claude_desktop_config.json')
        ->toContain('mcpServers')
        ->toContain('"command": "docker"');
});

it('cursor snippet references the home + project mcp.json paths', function() {
    $body = $this->controller->buildSnippet('cursor', $this->command);

    expect($body)
        ->toContain('~/.cursor/mcp.json')
        ->toContain('mcpServers')
        ->toContain('"command": "docker"');
});

it('cline snippet references the VS Code globalStorage path', function() {
    $body = $this->controller->buildSnippet('cline', $this->command);

    expect($body)
        ->toContain('saoudrizwan.claude-dev')
        ->toContain('mcpServers');
});

it('windsurf snippet references the codeium config path', function() {
    $body = $this->controller->buildSnippet('windsurf', $this->command);

    expect($body)
        ->toContain('~/.codeium/windsurf/mcp_config.json')
        ->toContain('mcpServers');
});

it('returns empty string for an unknown client', function() {
    $body = $this->controller->buildSnippet('unknown-client', $this->command);

    expect($body)->toBe('');
});

// -----------------------------------------------------------------------------
// Apply action — merge semantics
// -----------------------------------------------------------------------------

it('merges a cortex entry into an empty mcpServers JSON config', function() {
    $result = $this->controller->buildMergedConfig('claude-desktop', null, $this->command);

    expect($result)->not->toBeNull();
    [$contents, $action] = $result;
    expect($action)->toContain('create file with cortex entry');

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)
        ->toHaveKey('mcpServers')
        ->and($decoded['mcpServers'])->toHaveKey('cortex')
        ->and($decoded['mcpServers']['cortex']['command'])->toBe('docker')
        ->and($decoded['mcpServers']['cortex']['args'])->toBeArray()->not->toBeEmpty();
});

it('preserves other servers when merging into a populated mcpServers JSON', function() {
    $existing = json_encode([
        'mcpServers' => [
            'filesystem' => [
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
            ],
        ],
    ]);

    $result = $this->controller->buildMergedConfig('claude-desktop', $existing, $this->command);

    expect($result)->not->toBeNull();
    [$contents] = $result;

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['mcpServers'])
        ->toHaveKey('filesystem')
        ->toHaveKey('cortex')
        ->and($decoded['mcpServers']['filesystem']['command'])->toBe('npx');
});

it('refuses to overwrite an existing cortex entry without --force', function() {
    $existing = json_encode([
        'mcpServers' => [
            'cortex' => ['command' => 'old', 'args' => []],
        ],
    ]);

    $result = $this->controller->buildMergedConfig('claude-desktop', $existing, $this->command);

    expect($result)->toBeNull();
});

it('overwrites an existing cortex entry with --force', function() {
    $existing = json_encode([
        'mcpServers' => [
            'cortex' => ['command' => 'old', 'args' => []],
        ],
    ]);

    $this->controller->force = true;
    $result = $this->controller->buildMergedConfig('claude-desktop', $existing, $this->command);

    expect($result)->not->toBeNull();
    [$contents, $action] = $result;
    expect($action)->toContain('overwrite');

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['mcpServers']['cortex']['command'])->toBe('docker');
});

it('throws when the existing JSON is malformed', function() {
    expect(fn() => $this->controller->buildMergedConfig('claude-desktop', '{ not valid json', $this->command))
        ->toThrow(RuntimeException::class);
});

it('zed merger uses the context_servers top-level key', function() {
    $result = $this->controller->buildMergedConfig('zed', null, $this->command);

    expect($result)->not->toBeNull();
    [$contents] = $result;

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    expect($decoded)->toHaveKey('context_servers');
    expect($decoded)->not->toHaveKey('mcpServers');
});

it('continue merger renders a standalone YAML with the required metadata', function() {
    $result = $this->controller->buildMergedConfig('continue', null, $this->command);

    expect($result)->not->toBeNull();
    [$contents] = $result;

    expect($contents)
        ->toContain('name: cortex')
        ->toContain('version: 0.0.1')
        ->toContain('schema: v1')
        ->toContain('mcpServers:')
        ->toContain('- name: cortex');
});

it('continue merger refuses an existing differing file without --force', function() {
    $existing = "name: cortex\nversion: 0.0.1\nschema: v1\nmcpServers:\n  - name: cortex\n    command: old\n    args:\n      - foo\n";

    $result = $this->controller->buildMergedConfig('continue', $existing, $this->command);

    expect($result)->toBeNull();
});

it('continue merger overwrites with --force', function() {
    $existing = "name: cortex\nversion: 0.0.1\nschema: v1\nmcpServers:\n  - name: cortex\n    command: old\n    args:\n      - foo\n";

    $this->controller->force = true;
    $result = $this->controller->buildMergedConfig('continue', $existing, $this->command);

    expect($result)->not->toBeNull();
    [$contents] = $result;
    expect($contents)->toContain('command: docker');
});

// -----------------------------------------------------------------------------
// Apply action — path resolution
// -----------------------------------------------------------------------------

it('resolves claude-desktop config to an OS-appropriate path', function() {
    $path = $this->controller->resolveConfigPath('claude-desktop');

    expect($path)->toBeString()->not->toBeEmpty()
        ->toContain('claude_desktop_config.json');
});

it('resolves cursor config to ~/.cursor/mcp.json', function() {
    $path = $this->controller->resolveConfigPath('cursor');

    expect($path)
        ->toBeString()
        ->toEndWith(DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'mcp.json');
});

it('resolves continue config to standalone cortex.yaml under mcpServers/', function() {
    $path = $this->controller->resolveConfigPath('continue');

    expect($path)
        ->toBeString()
        ->toContain('.continue')
        ->toEndWith('mcpServers' . DIRECTORY_SEPARATOR . 'cortex.yaml');
});

it('resolves windsurf config to ~/.codeium/windsurf/mcp_config.json', function() {
    $path = $this->controller->resolveConfigPath('windsurf');

    expect($path)
        ->toBeString()
        ->toContain('.codeium')
        ->toEndWith('windsurf' . DIRECTORY_SEPARATOR . 'mcp_config.json');
});

it('resolves claude-code config to a project-scoped .mcp.json in cwd', function() {
    $path = $this->controller->resolveConfigPath('claude-code');

    expect($path)
        ->toBeString()
        ->toEndWith(DIRECTORY_SEPARATOR . '.mcp.json');
});

it('returns null for an unknown client', function() {
    $path = $this->controller->resolveConfigPath('unknown-client');

    expect($path)->toBeNull();
});

// -----------------------------------------------------------------------------
// Detection
// -----------------------------------------------------------------------------

it('detect() returns the expected shape for every known client', function() {
    // Detection results vary by host (the test machine may or may not
    // have a given client installed), so we only assert structure here.
    // Real-machine accuracy is verified manually.
    $clients = [
        'claude-desktop', 'claude-code', 'cursor',
        'continue', 'cline', 'zed', 'windsurf',
    ];

    foreach ($clients as $client) {
        $result = $this->controller->detect($client);
        expect($result)
            ->toHaveKeys(['app', 'configured', 'configPath'])
            ->and($result['app'])->toBeBool()
            ->and($result['configured'])->toBeBool()
            ->and($result['configPath'])->toBeString();
    }
});

it('detect() always reports app=false for VS Code extension clients', function() {
    // Continue.dev and Cline are extensions, not standalone apps — we
    // never claim to detect them via /Applications, PATH, or %LOCALAPPDATA%.
    // The `configured` signal carries them; `app` is structurally locked
    // to false regardless of host state.
    foreach (['continue', 'cline'] as $client) {
        $result = $this->controller->detect($client);
        expect($result['app'])->toBeFalse();
    }
});

it('detect() returns app=false / configured=false for an unknown client', function() {
    $result = $this->controller->detect('unknown-client');

    expect($result['app'])->toBeFalse()
        ->and($result['configured'])->toBeFalse()
        ->and($result['configPath'])->toBeNull();
});

it('detect() returns configPath equal to resolveConfigPath() for the same client', function() {
    // The two methods must agree on the target path — detection that
    // doesn't share apply()'s path resolution would surface "configured"
    // signals against a file the apply pipeline never touches.
    foreach (['claude-desktop', 'cursor', 'continue', 'zed', 'windsurf'] as $client) {
        $detect = $this->controller->detect($client);
        $resolved = $this->controller->resolveConfigPath($client);
        expect($detect['configPath'])->toBe($resolved);
    }
});

// -----------------------------------------------------------------------------
// DDEV refusal — detect / auto actions
// -----------------------------------------------------------------------------

it('actionDetect refuses to run from inside DDEV', function() {
    // Yii's Controller::stderr() writes to the STDERR file descriptor,
    // not via PHP's output buffer — message content isn't capturable
    // here. Exit code is the testable contract; the hint copy lives in
    // _refuseInDdev() and is reviewable in source.
    putenv('IS_DDEV_PROJECT=true');
    try {
        $exit = @$this->controller->actionDetect();
    } finally {
        putenv('IS_DDEV_PROJECT');
    }

    expect($exit)->toBe(\yii\console\ExitCode::CONFIG);
});

it('actionAuto refuses to run from inside DDEV', function() {
    putenv('IS_DDEV_PROJECT=true');
    try {
        $exit = @$this->controller->actionAuto();
    } finally {
        putenv('IS_DDEV_PROJECT');
    }

    expect($exit)->toBe(\yii\console\ExitCode::CONFIG);
});

it('actionDetect refuses to run when the container env var is set (Podman, systemd-nspawn)', function() {
    // The `container` env var is set by Podman (native mode) and systemd-nspawn.
    // Exit code is the testable contract; message content lives in _refuseInContainer().
    putenv('container=podman');
    try {
        $exit = @$this->controller->actionDetect();
    } finally {
        putenv('container');
    }

    expect($exit)->toBe(\yii\console\ExitCode::CONFIG);
});

it('actionAuto refuses to run when the container env var is set (Podman, systemd-nspawn)', function() {
    putenv('container=podman');
    try {
        $exit = @$this->controller->actionAuto();
    } finally {
        putenv('container');
    }

    expect($exit)->toBe(\yii\console\ExitCode::CONFIG);
});
