<?php

/**
 * =========================================================================
 * craft_command secret-redaction tests — Gate 9 hardening.
 *
 * BLOCKER: `craft_command` is HTTP-reachable (only `craft_exec` carries
 * `#[IsStdioOnly]`). Default allowlist routes (`mailer/test`, `utils/*`)
 * can echo transport / config secrets to stdout, which previously landed
 * verbatim in the persisted `herald_invocations.response_excerpt` AND on
 * the wire. These tests lock the two-layer fix:
 *
 *   - TOOL LAYER: `CraftCommand` runs captured stdout/stderr through
 *     `SecretRedactor::redactString()` before returning, so the secret
 *     reaches neither the wire `output` field nor the audit excerpt.
 *   - DISPATCHER LAYER: `Mcp\Server` runs the tool result through
 *     `SecretRedactor::redactArray()` before encoding the persisted
 *     excerpt, catching secret-keyed result fields even when a future
 *     HTTP-reachable tool forgets to scrub its own result.
 *
 * Both layers are exercised so a regression in either surfaces.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\herald\events\RegisterToolsEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\records\Invocation as InvocationRecord;
use craftpulse\herald\services\Tools;
use craftpulse\herald\tests\Tools\Fixtures\SecretEmittingController;
use craftpulse\herald\tests\Tools\Fixtures\SecretLeakingTool;
use yii\base\Event;

/**
 * Register the throwaway secret-emitting console controller into
 * `Craft::$app->controllerMap` and append its route to the live
 * `allowedCommands` so `craft_command` admits it. Returns a restore
 * closure the caller invokes in a `finally` block.
 *
 * @return callable():void
 */
function _herald_register_secret_command(): callable
{
    $app = Craft::$app;
    $hadController = isset($app->controllerMap['herald-test-secret']);
    $app->controllerMap['herald-test-secret'] = SecretEmittingController::class;

    $settings = Herald::getInstance()->getSettings();
    $originalCommands = $settings->allowedCommands;
    $settings->allowedCommands = array_merge($originalCommands, ['herald-test-secret/*']);

    return static function() use ($app, $hadController, $settings, $originalCommands): void {
        if (!$hadController) {
            unset($app->controllerMap['herald-test-secret']);
        }
        $settings->allowedCommands = $originalCommands;
    };
}

// -----------------------------------------------------------------------------
// Tool layer — captured stdout is redacted before the return
// -----------------------------------------------------------------------------

it('redacts KEY=value secrets emitted to stdout in the wire output field', function() {
    $restore = _herald_register_secret_command();

    try {
        $tool = Herald::getInstance()->tools->getByName('craft_command');
        $result = $tool->execute([
            'mode' => 'run',
            'command' => 'herald-test-secret/emit',
        ]);

        expect($result)->toHaveKey('output');
        expect($result['output'])->toContain('DB_PASSWORD=<redacted>');
        expect($result['output'])->toContain('token=<redacted>');
        expect($result['output'])->not->toContain('supersecret');
        expect($result['output'])->not->toContain('abc123');
    } finally {
        $restore();
    }
});

// -----------------------------------------------------------------------------
// Tool + dispatcher — the persisted excerpt never carries the secret
// -----------------------------------------------------------------------------

it('persists a redacted response_excerpt for a craft_command stdout secret', function() {
    $restore = _herald_register_secret_command();

    try {
        $before = InvocationRecord::find()->max('[[id]]') ?? 0;

        $server = new Server(Server::TRANSPORT_HTTP);
        $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'craft_command',
                'arguments' => [
                    'mode' => 'run',
                    'command' => 'herald-test-secret/emit',
                ],
            ],
        ]);

        /** @var InvocationRecord|null $row */
        $row = InvocationRecord::find()
            ->where(['toolName' => 'craft_command'])
            ->andWhere(['>', 'id', $before])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        expect($row)->not->toBeNull();
        expect($row->responseExcerpt)->toBeString();
        expect($row->responseExcerpt)->not->toContain('supersecret');
        expect($row->responseExcerpt)->not->toContain('abc123');
        expect($row->responseExcerpt)->toContain('<redacted>');

        InvocationRecord::deleteAll(['id' => $row->id]);
    } finally {
        $restore();
    }
});

// -----------------------------------------------------------------------------
// Dispatcher layer — belt-and-suspenders for tools that DON'T self-redact
// -----------------------------------------------------------------------------

it('redacts secret-keyed result fields in the persisted excerpt even when the tool does not', function() {
    // `SecretLeakingTool` returns `password => topsecret-value` raw. The
    // dispatcher's `redactArray()` of the excerpt is the only thing
    // standing between that value and the persisted audit row.
    $listener = static function(RegisterToolsEvent $event): void {
        $event->tools[] = new SecretLeakingTool();
    };
    Event::on(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);

    $originalTools = Herald::getInstance()->tools;
    $fresh = new Tools();
    $fresh->init();
    Herald::getInstance()->set('tools', $fresh);

    try {
        $before = InvocationRecord::find()->max('[[id]]') ?? 0;

        $server = new Server(Server::TRANSPORT_HTTP);
        $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => '_secret_leaking_test'],
        ]);

        /** @var InvocationRecord|null $row */
        $row = InvocationRecord::find()
            ->where(['toolName' => '_secret_leaking_test'])
            ->andWhere(['>', 'id', $before])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        expect($row)->not->toBeNull();
        expect($row->responseExcerpt)->toBeString();
        expect($row->responseExcerpt)->not->toContain('topsecret-value');
        expect($row->responseExcerpt)->toContain('<redacted>');
        // Non-secret fields survive intact.
        expect($row->responseExcerpt)->toContain('visible');

        InvocationRecord::deleteAll(['id' => $row->id]);
    } finally {
        Herald::getInstance()->set('tools', $originalTools);
        Event::off(Tools::class, Tools::EVENT_REGISTER_TOOLS, $listener);
    }
});
