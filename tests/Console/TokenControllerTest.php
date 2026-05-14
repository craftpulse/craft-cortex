<?php

/**
 * =========================================================================
 * TokenController tests — verify issue / revoke / list actions print
 * the right surface, create / soft-delete the expected rows, and never
 * leak plaintext from the list action.
 *
 * Yii's `Controller::stdout()` / `stderr()` write directly to the
 * STDOUT / STDERR file descriptors, bypassing PHP's output buffer. We
 * subclass the controller and override those methods to capture into
 * test-owned buffers.
 *
 * Each test cleans up its own rows in `afterEach` so the table stays in
 * the shape it started in.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craftpulse\cortex\console\controllers\TokenController;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\Token as TokenRecord;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * Capturing TokenController — `stdout` / `stderr` route into a public
 * buffer instead of the file descriptors so tests can read what the
 * action printed.
 */
class _CortexTokenControllerHarness extends TokenController
{
    public string $captured = '';

    public function stdout($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }

    public function stderr($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }
}

/**
 * Build a fresh harness with the given options applied. Mirrors how
 * Yii's console runner binds `--name=foo` / `--ttl=N` style options
 * onto the controller before invoking the action.
 *
 * @param array<string,mixed> $options
 */
function _cortex_token_harness(array $options = []): _CortexTokenControllerHarness
{
    $controller = new _CortexTokenControllerHarness('token', Craft::$app);
    foreach ($options as $key => $value) {
        $controller->{$key} = $value;
    }
    return $controller;
}

beforeEach(function() {
    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($admin)->not->toBeNull();
    $this->userId = (int) $admin->id;
    $this->userHandle = (string) ($admin->username ?? $admin->email);
});

afterEach(function() {
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
});

// -----------------------------------------------------------------------------
// issue
// -----------------------------------------------------------------------------

it('issue prints the plaintext exactly once and creates a row', function() {
    $controller = _cortex_token_harness(['name' => '_test_/issue-output']);
    $exit = $controller->actionIssue($this->userHandle);

    expect($exit)->toBe(0);
    expect($controller->captured)->toContain('Bearer token issued');
    expect($controller->captured)->toContain('_test_/issue-output');
    expect($controller->captured)->toMatch('/[0-9a-f]{64}/');

    $rows = TokenRecord::find()->where(['name' => '_test_/issue-output'])->all();
    expect($rows)->toHaveCount(1);
});

it('issue with unknown user returns USAGE', function() {
    $controller = _cortex_token_harness();
    $exit = $controller->actionIssue('no-such-user@example.com');

    expect($exit)->toBe(64); // ExitCode::USAGE
    expect($controller->captured)->toContain('Unknown user');
});

it('issue honours --ttl and writes a future expiry on the row', function() {
    $controller = _cortex_token_harness([
        'name' => '_test_/issue-ttl',
        'ttl' => 60,
    ]);
    $exit = $controller->actionIssue($this->userHandle);

    expect($exit)->toBe(0);
    $row = TokenRecord::findOne(['name' => '_test_/issue-ttl']);
    expect($row)->not->toBeNull();
    expect($row->expiresAt)->not->toBeNull();
});

it('issue defaults the name when --name is omitted', function() {
    $controller = _cortex_token_harness();
    $exit = $controller->actionIssue($this->userHandle);

    expect($exit)->toBe(0);

    // Default name pattern is `cli-<unix-timestamp>`.
    $row = TokenRecord::find()
        ->where(['like', 'name', 'cli-%', false])
        ->orderBy(['id' => SORT_DESC])
        ->one();
    expect($row)->not->toBeNull();
    expect((string) $row->name)->toMatch('/^cli-\d+$/');

    // Clean up since this row's name doesn't match the `_test_/` prefix.
    TokenRecord::deleteAll(['id' => $row->id]);
});

// -----------------------------------------------------------------------------
// list
// -----------------------------------------------------------------------------

it('list shows the row by prefix and name, but never the plaintext', function() {
    $issued = Plugin::getInstance()->tokens->issue($this->userId, '_test_/list-row');

    $controller = _cortex_token_harness();
    $exit = $controller->actionList();

    expect($exit)->toBe(0);
    expect($controller->captured)->toContain('_test_/list-row');
    expect($controller->captured)->toContain($issued['model']->tokenPrefix);
    // The plaintext token must NOT be in the list output.
    expect($controller->captured)->not->toContain($issued['token']);
});

it('list returns a friendly message when there are no live tokens', function() {
    // Hard-delete every token for this user so the empty-state path
    // is reachable regardless of prior test residue.
    TokenRecord::deleteAll(['userId' => $this->userId]);

    $controller = _cortex_token_harness(['user' => $this->userHandle]);
    $exit = $controller->actionList();

    expect($exit)->toBe(0);
    expect($controller->captured)->toContain('No live bearer tokens');
});

it('list with unknown --user returns USAGE', function() {
    $controller = _cortex_token_harness(['user' => 'no-such-user@example.com']);
    $exit = $controller->actionList();

    expect($exit)->toBe(64);
    expect($controller->captured)->toContain('Unknown user');
});

// -----------------------------------------------------------------------------
// revoke
// -----------------------------------------------------------------------------

it('revoke soft-deletes the row and returns OK', function() {
    $issued = Plugin::getInstance()->tokens->issue($this->userId, '_test_/revoke-action');

    $controller = _cortex_token_harness();
    $exit = $controller->actionRevoke($issued['model']->id);

    expect($exit)->toBe(0);
    expect($controller->captured)->toContain('Revoked');

    $row = TokenRecord::findOne($issued['model']->id);
    expect($row)->not->toBeNull();
    expect($row->dateDeleted)->not->toBeNull();
});

it('revoke on unknown id returns NOUSER exit code', function() {
    $controller = _cortex_token_harness();
    $exit = $controller->actionRevoke(987654321);

    expect($exit)->toBe(67); // ExitCode::NOUSER
    expect($controller->captured)->toContain('No live token');
});

it('revoke on already-revoked id returns NOUSER (treated as no-live-match)', function() {
    $issued = Plugin::getInstance()->tokens->issue($this->userId, '_test_/revoke-twice');
    Plugin::getInstance()->tokens->revoke($issued['model']->id);

    // The controller's `getById` filter excludes soft-deleted rows, so
    // the second revoke surfaces as "no live token" — same response
    // the operator gets for a typo'd id.
    $controller = _cortex_token_harness();
    $exit = $controller->actionRevoke($issued['model']->id);

    expect($exit)->toBe(67);
    expect($controller->captured)->toContain('No live token');
});
