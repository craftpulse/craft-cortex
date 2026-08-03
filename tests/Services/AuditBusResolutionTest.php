<?php

/**
 * =========================================================================
 * Audit bus resolution — emissions reach a sink through the production
 * resolution path.
 *
 * `Services/AuditTest` swaps a whole `Bus` onto the `Audit` service via
 * `setBus()`, which proves the emission shape but deliberately bypasses
 * the one thing the Audit Kit module retrofit changed: how `Audit` finds
 * a bus in the first place. This file is the other half. It leaves
 * `setBus()` alone at null, so every emission travels
 * `Audit::_bus()` -> `AuditKit::getInstance()->getBus()`, and appends its
 * capturing sink to whatever that module-resolved bus already carries.
 *
 * What this catches that the injected-bus tests cannot: a broken bus
 * resolution. Audit Kit 1.1.0 ships as a library-shipped Yii module, so
 * the bus does not exist until the module is registered, and `_bus()`
 * used to end in a nullsafe call that turned any resolution failure into
 * a silent no-op on a product whose value is the trail.
 *
 * Zero-skip: nothing here depends on a recorder being installed, so no
 * case is skip-guarded (the suite's CI gate runs `--fail-on-skipped`).
 * The source-level companions live in
 * `Architecture/AuditKitRetrofitTest`.
 *
 * Rows written through the real service seams use the `_test_/` name
 * prefix the rest of the suite sweeps, and roll back with the per-test
 * transaction.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */

use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\AuditKit;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\records\Token as TokenRecord;
use craftpulse\herald\services\Audit;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

beforeEach(function() {
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);

    // Production bus resolution. `setBus(null)` is the production
    // default; setting it explicitly documents that this file asserts
    // the resolution path rather than an injected bus.
    Herald::getInstance()->getAudit()->setBus(null);

    $this->sink = new class() implements AuditSinkInterface {
        /** @var AuditEvent[] */
        public array $events = [];

        public function handle(AuditEvent $event): void
        {
            $this->events[] = $event;
        }
    };

    // Append the capturing sink to whatever the module-resolved bus
    // already carries, rather than replacing the bus wholesale. Any real
    // recorder on the install keeps receiving its events.
    $bus = AuditKit::getInstance()->getBus();
    $this->originalSinks = $bus->getSinks();
    $bus->setSinks([...$this->originalSinks, $this->sink]);
});

afterEach(function() {
    AuditKit::getInstance()->getBus()->setSinks($this->originalSinks);
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
});

/**
 * The captured events whose name matches `$name`.
 *
 * @return AuditEvent[]
 */
function herald_resolved_events_named(AuditSinkInterface $sink, string $name): array
{
    /** @phpstan-ignore-next-line property.notFound (anonymous sink carries $events) */
    return array_values(array_filter($sink->events, static fn(AuditEvent $e): bool => $e->name === $name));
}

// -----------------------------------------------------------------------------
// The resolution path is live
// -----------------------------------------------------------------------------

it('resolves a sink through the registered Audit Kit module', function() {
    expect(Craft::$app->getModule(AuditKit::ID))->toBeInstanceOf(AuditKit::class);
    expect(AuditKit::getInstance()->getBus()->getSinks())->toContain($this->sink);
});

// -----------------------------------------------------------------------------
// A lifecycle action reaches the sink
// -----------------------------------------------------------------------------

it('delivers a token.issued emission through production resolution', function() {
    $admin = herald_admin_user();
    expect($admin)->not->toBeNull();

    // `Tokens::issue()` is Pro-gated: the HTTP transport that consumes a
    // bearer token refuses Free installs.
    herald_with_edition(Herald::EDITION_PRO, function() use ($admin) {
        Herald::getInstance()->getTokens()->issue(
            userId: (int) $admin->id,
            name: '_test_/bus-resolution',
        );
    });

    $events = herald_resolved_events_named($this->sink, Audit::EVENT_TOKEN_ISSUED);

    expect($events)->toHaveCount(1)
        ->and($events[0]->category)->toBe(Audit::CATEGORY_SYSTEM)
        ->and($events[0]->emitter)->toBe(Audit::EMITTER)
        ->and($events[0]->outcome)->toBe(AuditEvent::OUTCOME_SUCCESS)
        ->and($events[0]->details['name'])->toBe('_test_/bus-resolution')
        // The plaintext bearer token never travels in details.
        ->and($events[0]->details)->not->toHaveKey('token');
});

// -----------------------------------------------------------------------------
// An AI write reaches the sink; a read does not
// -----------------------------------------------------------------------------

it('delivers a write-tool invocation through production resolution', function() {
    herald_with_pro_registry(function() {
        InvocationLogger::logCall(
            'entry',
            ['mode' => 'save', 'title' => 'anything'],
            null,
            42,
            new InvocationContext(transport: Server::TRANSPORT_STDIO, clientName: 'claude-code'),
        );
    });

    $events = herald_resolved_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);

    expect($events)->toHaveCount(1)
        ->and($events[0]->category)->toBe(Audit::CATEGORY_CONTENT)
        ->and($events[0]->details['tool'])->toBe('entry')
        ->and($events[0]->details['transport'])->toBe(Server::TRANSPORT_STDIO)
        // Scalar metadata only: no redacted args and no content body.
        ->and($events[0]->details)->not->toHaveKey('args')
        ->and($events[0]->details)->not->toHaveKey('title');
});

it('delivers nothing for a read-tool invocation', function() {
    InvocationLogger::logCall(
        'entries',
        ['mode' => 'list'],
        null,
        30,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(herald_resolved_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});
