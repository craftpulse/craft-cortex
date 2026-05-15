<?php

/**
 * =========================================================================
 * Tools service tests — per-user filtering surface (Gate 7.4).
 *
 * Asserts the locked architectural contract from
 * `.claude/rules/architecture.md` "Per-user tool visibility":
 *
 *   - `asListPayloadFor(null) === asListPayload()` — stdio invariant.
 *   - `asListPayloadFor($user)` omits tools where `filterFor()` is false.
 *   - `getByNameFor($name, $user)` returns null when `filterFor()` is
 *     false (treat as missing).
 *   - `inputSchemaFor($user)` is consulted for the surviving tools.
 *
 * Uses anonymous-class stub tools registered through the public
 * `EVENT_REGISTER_TOOLS` event surface, mirroring the fixture pattern
 * in `tests/Mcp/ServerTest.php`. No new helper layer — the existing
 * extension contract is the test fixture.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */

use craft\elements\User;
use craftpulse\cortex\Cortex;
use craftpulse\cortex\events\RegisterToolsEvent;
use craftpulse\cortex\services\Tools;
use craftpulse\cortex\tools\AbstractTool;

beforeEach(function() {
    // Resolve a playground admin user once — used as the "permitted"
    // caller in filterFor stubs. Same lookup as TokensTest /
    // OauthTest so test envs stay aligned.
    $this->admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com');
    expect($this->admin)->not->toBeNull();
});

/**
 * Register a stub tool through the public extension event and return
 * a freshly-initialised `Tools` service that includes it. Resets the
 * plugin's `tools` service so subsequent dispatcher calls (if any)
 * see the fixture. The caller is responsible for restoring the
 * original service in a `finally` block and detaching the listener.
 *
 * @return array{0: Tools, 1: \Closure, 2: Tools} fresh service,
 *                                                listener handle for
 *                                                detach, original
 *                                                service for restore.
 */
function _cortex_register_stub_tool(callable $factory): array
{
    $listener = function(RegisterToolsEvent $event) use ($factory): void {
        $event->tools[] = $factory();
    };

    \yii\base\Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);

    $original = Cortex::getInstance()->tools;
    $fresh = new Tools();
    $fresh->init();
    Cortex::getInstance()->set('tools', $fresh);

    return [$fresh, $listener, $original];
}

/**
 * Restore the original `tools` service and detach the test listener.
 * Always called from a `finally` block to keep the playground's
 * registry intact across tests.
 */
function _cortex_restore_tools(Tools $original, \Closure $listener): void
{
    Cortex::getInstance()->set('tools', $original);
    \yii\base\Event::off(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);
}

// -----------------------------------------------------------------------------
// Invariant: asListPayloadFor(null) === asListPayload()
// -----------------------------------------------------------------------------

it('asListPayloadFor(null) equals asListPayload() — stdio invariant', function() {
    $service = Cortex::getInstance()->tools;

    $stdio = $service->asListPayload();
    $nullUser = $service->asListPayloadFor(null);

    // Structural equality, not identity. Both payloads contain fresh
    // `stdClass` instances from `(object) []` casts in
    // `getInputSchema()` results, so `===` comparison would fail on
    // identity alone. The invariant is shape-equivalence: the LLM's
    // tools/list response is byte-identical under `json_encode`.
    expect($nullUser)->toEqual($stdio);
    expect(json_encode($nullUser))->toBe(json_encode($stdio));
});

// -----------------------------------------------------------------------------
// asListPayloadFor — filterFor false omits the tool
// -----------------------------------------------------------------------------

it('asListPayloadFor returns every tool when filterFor defaults to true for all', function() {
    // The shipping Free registry has no tool that overrides filterFor.
    // The full payload for the admin should match the stdio payload —
    // structural equality (fresh `stdClass` instances from
    // `(object) []` casts mean identity-equality would never hold).
    $service = Cortex::getInstance()->tools;

    $stdio = $service->asListPayload();
    $admin = $service->asListPayloadFor($this->admin);

    expect($admin)->toEqual($stdio);
    expect(json_encode($admin))->toBe(json_encode($stdio));
    expect($admin)->toHaveCount($service->getCount());
});

it('asListPayloadFor omits tools whose filterFor returns false for the user', function() {
    [$service, $listener, $original] = _cortex_register_stub_tool(fn() => new class() extends AbstractTool {
        public static function getName(): string
        {
            return '_test_/admin-only-tool';
        }

        public static function getDescription(): string
        {
            return 'Fixture — visible only when filterFor receives an admin.';
        }

        public function filterFor(?User $user = null): bool
        {
            return $user !== null && $user->admin === true;
        }

        public function execute(array $arguments): array
        {
            return ['ok' => true];
        }
    });

    try {
        // Admin sees the tool.
        $forAdmin = $service->asListPayloadFor($this->admin);
        $adminNames = array_column($forAdmin, 'name');
        expect($adminNames)->toContain('_test_/admin-only-tool');

        // The null-user (stdio path) does not — the stub's filterFor
        // returns false for null.
        $forNull = $service->asListPayloadFor(null);
        $nullNames = array_column($forNull, 'name');
        expect($nullNames)->not->toContain('_test_/admin-only-tool');
    } finally {
        _cortex_restore_tools($original, $listener);
    }
});

// -----------------------------------------------------------------------------
// getByNameFor — filterFor false returns null
// -----------------------------------------------------------------------------

it('getByNameFor returns the tool when filterFor is true', function() {
    [$service, $listener, $original] = _cortex_register_stub_tool(fn() => new class() extends AbstractTool {
        public static function getName(): string
        {
            return '_test_/always-on-tool';
        }

        public static function getDescription(): string
        {
            return 'Fixture — filterFor always true.';
        }

        public function execute(array $arguments): array
        {
            return ['ok' => true];
        }
    });

    try {
        $tool = $service->getByNameFor('_test_/always-on-tool', $this->admin);
        expect($tool)->not->toBeNull();
        expect($tool::getName())->toBe('_test_/always-on-tool');
    } finally {
        _cortex_restore_tools($original, $listener);
    }
});

it('getByNameFor returns null when filterFor is false for the user', function() {
    [$service, $listener, $original] = _cortex_register_stub_tool(fn() => new class() extends AbstractTool {
        public static function getName(): string
        {
            return '_test_/hidden-tool';
        }

        public static function getDescription(): string
        {
            return 'Fixture — filterFor always false.';
        }

        public function filterFor(?User $user = null): bool
        {
            return false;
        }

        public function execute(array $arguments): array
        {
            return ['ok' => true];
        }
    });

    try {
        $tool = $service->getByNameFor('_test_/hidden-tool', $this->admin);
        expect($tool)->toBeNull();

        // Also null for the stdio (null user) path.
        $stdioTool = $service->getByNameFor('_test_/hidden-tool', null);
        expect($stdioTool)->toBeNull();
    } finally {
        _cortex_restore_tools($original, $listener);
    }
});

it('getByNameFor returns null for an unknown tool name regardless of user', function() {
    $service = Cortex::getInstance()->tools;
    expect($service->getByNameFor('_test_/no-such-tool', $this->admin))->toBeNull();
    expect($service->getByNameFor('_test_/no-such-tool', null))->toBeNull();
});

// -----------------------------------------------------------------------------
// inputSchemaFor is consulted in the list payload
// -----------------------------------------------------------------------------

it('asListPayloadFor uses inputSchemaFor for the surviving tools', function() {
    [$service, $listener, $original] = _cortex_register_stub_tool(fn() => new class() extends AbstractTool {
        public static function getName(): string
        {
            return '_test_/mode-gated-tool';
        }

        public static function getDescription(): string
        {
            return 'Fixture — schema differs per user.';
        }

        public static function getInputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => (object) [],
                'additionalProperties' => false,
                'x-marker' => 'static-schema',
            ];
        }

        public function inputSchemaFor(?User $user = null): array
        {
            if ($user !== null && $user->admin === true) {
                return [
                    'type' => 'object',
                    'properties' => (object) [],
                    'additionalProperties' => false,
                    'x-marker' => 'admin-schema',
                ];
            }
            return static::getInputSchema();
        }

        public function execute(array $arguments): array
        {
            return ['ok' => true];
        }
    });

    try {
        // Admin sees the rewritten schema.
        $forAdmin = $service->asListPayloadFor($this->admin);
        $adminEntry = current(array_filter(
            $forAdmin,
            static fn(array $e): bool => $e['name'] === '_test_/mode-gated-tool',
        ));
        expect($adminEntry)->not->toBeFalse();
        expect($adminEntry['inputSchema']['x-marker'])->toBe('admin-schema');

        // null user (stdio path) sees the static fallback.
        $forNull = $service->asListPayloadFor(null);
        $nullEntry = current(array_filter(
            $forNull,
            static fn(array $e): bool => $e['name'] === '_test_/mode-gated-tool',
        ));
        expect($nullEntry)->not->toBeFalse();
        expect($nullEntry['inputSchema']['x-marker'])->toBe('static-schema');
    } finally {
        _cortex_restore_tools($original, $listener);
    }
});
