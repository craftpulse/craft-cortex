<?php

/**
 * =========================================================================
 * Audit service tests — Herald's additive Audit Kit emission.
 *
 * Every test swaps a fresh `craftpulse\auditkit\services\Bus` carrying a
 * single capturing sink onto the `Audit` service via `setBus()`, so
 * emissions are asserted in isolation from any real recorder (Ledger)
 * that may be installed in the surrounding playground. `afterEach`
 * restores production bus resolution.
 *
 * Coverage:
 *   - each OAuth / bearer-token lifecycle action emits exactly one
 *     correctly-named event through its real service seam,
 *   - one WRITE-tool invocation emits exactly one
 *     `herald.tool.write_invoked` (across stdio and HTTP),
 *   - a READ-tool invocation emits nothing,
 *   - no token / client secret value ever reaches an event's details,
 *   - the three `DualModeToolInterface` workflow tools (`content_audit`,
 *     `drafts_and_revisions`, `import_export`) classify per-invocation
 *     from the call's actual `mode`: each Pro write mode emits exactly
 *     one correctly-named event, each read mode emits nothing, and an
 *     unresolvable or unrecognised `mode` on a known dual-mode tool
 *     emits (the fail-toward-write case).
 *
 * Rows written through the real service seams use the `_test_/` name
 * prefix and are swept in `beforeEach` / `afterEach`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.1.0
 */

use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditSinkInterface;
use craftpulse\auditkit\services\Bus;
use craftpulse\herald\Herald;
use craftpulse\herald\mcp\Server;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use craftpulse\herald\records\Token as TokenRecord;
use craftpulse\herald\services\Audit;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\InvocationLogger;
use craftpulse\herald\tools\support\SecretRedactor;
use craftpulse\herald\tools\ToolException;

// -----------------------------------------------------------------------------
// Harness
// -----------------------------------------------------------------------------

/**
 * A capturing sink that records every `AuditEvent` the bus fans to it.
 */
function _herald_capturing_sink(): AuditSinkInterface
{
    return new class() implements AuditSinkInterface {
        /** @var AuditEvent[] */
        public array $events = [];

        public function handle(AuditEvent $event): void
        {
            $this->events[] = $event;
        }
    };
}

/**
 * The captured events whose name matches `$name`.
 *
 * @param AuditSinkInterface $sink
 * @return AuditEvent[]
 */
function _herald_events_named(AuditSinkInterface $sink, string $name): array
{
    /** @phpstan-ignore-next-line property.notFound (anonymous sink carries $events) */
    return array_values(array_filter($sink->events, static fn(AuditEvent $e): bool => $e->name === $name));
}

beforeEach(function() {
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);

    $this->sink = _herald_capturing_sink();
    $bus = new Bus();
    $bus->setSinks([$this->sink]);
    Herald::getInstance()->audit->setBus($bus);

    $admin = Craft::$app->getUsers()->getUserByUsernameOrEmail('michtio')
        ?? Craft::$app->getUsers()->getUserByUsernameOrEmail('development@craftpulse.com')
        ?? \craft\elements\User::find()->admin()->one();
    $this->userId = $admin !== null ? (int) $admin->id : 1;
});

afterEach(function() {
    Herald::getInstance()->audit->setBus(null);
    TokenRecord::deleteAll(['like', 'name', '_test_/%', false]);
    OauthClientRecord::deleteAll(['like', 'clientName', '_test_/%', false]);
});

// -----------------------------------------------------------------------------
// Event-type registration
// -----------------------------------------------------------------------------

it('registers the seven Herald event types with fail-closed allowlists', function() {
    $event = new \craftpulse\auditkit\events\RegisterAuditEventsEvent();
    Herald::getInstance()->audit->registerEventTypes($event);

    $byName = [];
    foreach ($event->eventTypes as $type) {
        $byName[$type->name] = $type;
    }

    expect($byName)->toHaveKeys([
        Audit::EVENT_CLIENT_REGISTERED,
        Audit::EVENT_CLIENT_APPROVED,
        Audit::EVENT_CLIENT_REVOKED,
        Audit::EVENT_ELEVATION_GRANTED,
        Audit::EVENT_TOKEN_ISSUED,
        Audit::EVENT_TOKEN_REVOKED,
        Audit::EVENT_TOOL_WRITE_INVOKED,
    ]);
    expect($event->eventTypes)->toHaveCount(7);

    expect($byName[Audit::EVENT_TOOL_WRITE_INVOKED]->category)->toBe(Audit::CATEGORY_CONTENT);
    expect($byName[Audit::EVENT_CLIENT_REGISTERED]->category)->toBe(Audit::CATEGORY_SYSTEM);

    // No allowlisted key may carry a raw credential *value*. ID
    // references (`token_id`, `client_id`) and labels are blessed; a
    // key that would hold a plaintext token / secret / password is not.
    // (`SecretRedactor::isSecretKey()` deliberately errs broad and would
    // flag `token_id`, so the denylist targets value-bearing keys only.)
    $rawCredentialKeys = ['token', 'secret', 'client_secret', 'password', 'plaintext', 'jwt', 'bearer', 'apikey'];
    foreach ($event->eventTypes as $type) {
        foreach ($type->allowedDetailKeys as $key) {
            expect(in_array($key, $rawCredentialKeys, true))->toBeFalse("allowlisted key '{$key}' would carry a raw credential");
        }
    }
});

// -----------------------------------------------------------------------------
// OAuth client lifecycle
// -----------------------------------------------------------------------------

it('emits exactly one client_registered event and never leaks the secret', function() {
    $response = Herald::getInstance()->oauth->registerClient([
        'client_name' => '_test_/register',
        'redirect_uris' => ['https://example.com/cb'],
        'token_endpoint_auth_method' => 'client_secret_basic',
    ]);

    $events = _herald_events_named($this->sink, Audit::EVENT_CLIENT_REGISTERED);
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event->category)->toBe(Audit::CATEGORY_SYSTEM);
    expect($event->emitter)->toBe(Audit::EMITTER);
    expect($event->targetType)->toBe(Audit::TARGET_OAUTH_CLIENT);
    expect($event->details['client_id'])->toBe($response['client_id']);

    // The confidential-client secret must appear in NO detail value.
    $secret = $response['client_secret'] ?? null;
    expect($secret)->not->toBeNull();
    foreach ($event->details as $value) {
        expect((string) $value)->not->toBe($secret);
    }
    foreach (array_keys($event->details) as $key) {
        expect(SecretRedactor::isSecretKey($key))->toBeFalse();
    }
});

it('emits exactly one client_approved event on a real approval transition', function() {
    $client = new OauthClientRecord();
    $client->clientId = bin2hex(random_bytes(16));
    $client->clientName = '_test_/approve';
    $client->redirectUris = '["https://example.com/cb"]';
    $client->isPublic = true;
    $client->approved = false;
    $client->save();

    $this->sink->events = [];

    Herald::getInstance()->oauth->approveClient((int) $client->id);

    $events = _herald_events_named($this->sink, Audit::EVENT_CLIENT_APPROVED);
    expect($events)->toHaveCount(1);
    expect($events[0]->targetId)->toBe((int) $client->id);
    expect($events[0]->details['client_id'])->toBe($client->clientId);
});

it('emits exactly one client_revoked event', function() {
    $client = new OauthClientRecord();
    $client->clientId = bin2hex(random_bytes(16));
    $client->clientName = '_test_/revoke';
    $client->redirectUris = '["https://example.com/cb"]';
    $client->isPublic = true;
    $client->approved = true;
    $client->save();

    $this->sink->events = [];

    Herald::getInstance()->oauth->revokeClient((int) $client->id);

    $events = _herald_events_named($this->sink, Audit::EVENT_CLIENT_REVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->targetId)->toBe((int) $client->id);
});

it('emits exactly one elevation_granted event bound to the user', function() {
    Herald::getInstance()->oauth->grantElevation($this->userId);

    $events = _herald_events_named($this->sink, Audit::EVENT_ELEVATION_GRANTED);
    expect($events)->toHaveCount(1);
    expect($events[0]->targetType)->toBe(Audit::TARGET_USER);
    expect($events[0]->targetId)->toBe($this->userId);
    expect($events[0]->actorId)->toBe($this->userId);
});

// -----------------------------------------------------------------------------
// Bearer-token lifecycle
// -----------------------------------------------------------------------------

it('emits exactly one token.issued event and never leaks the plaintext', function() {
    $issued = Herald::getInstance()->tokens->issue($this->userId, '_test_/issue');
    $plaintext = $issued['token'];

    $events = _herald_events_named($this->sink, Audit::EVENT_TOKEN_ISSUED);
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event->targetType)->toBe(Audit::TARGET_TOKEN);
    expect($event->targetId)->toBe((int) $issued['model']->id);
    expect($event->details['name'])->toBe('_test_/issue');
    expect($event->details['bound_user_id'])->toBe($this->userId);

    foreach ($event->details as $value) {
        expect((string) $value)->not->toBe($plaintext);
        expect(str_contains((string) $value, $plaintext))->toBeFalse();
    }
});

it('emits exactly one token.revoked event', function() {
    $issued = Herald::getInstance()->tokens->issue($this->userId, '_test_/revoke');
    $this->sink->events = [];

    Herald::getInstance()->tokens->revoke((int) $issued['model']->id);

    $events = _herald_events_named($this->sink, Audit::EVENT_TOKEN_REVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->targetId)->toBe((int) $issued['model']->id);
});

// -----------------------------------------------------------------------------
// Write-tool invocation (herald.tool.write_invoked)
// -----------------------------------------------------------------------------

it('emits one write event for a stdio write-tool invocation', function() {
    herald_with_pro_registry(function() {
        InvocationLogger::logCall(
            'entry',
            ['mode' => 'save', 'title' => 'anything'],
            null,
            42,
            new InvocationContext(transport: Server::TRANSPORT_STDIO, clientName: 'claude-code'),
        );
    });

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);

    $details = $events[0]->details;
    expect($events[0]->category)->toBe(Audit::CATEGORY_CONTENT);
    expect($events[0]->outcome)->toBe(AuditEvent::OUTCOME_SUCCESS);
    expect($details['tool'])->toBe('entry');
    expect($details['kind'])->toBe(InvocationLogger::KIND_SUCCESS);
    expect($details['transport'])->toBe(Server::TRANSPORT_STDIO);
    expect($details['duration_bucket'])->toBe('0-100ms');
    expect($details['client'])->toBe('claude-code');

    // No redacted args, no content body — details are scalar-only metadata.
    expect($details)->not->toHaveKey('args');
    expect($details)->not->toHaveKey('title');
});

it('carries the actor and http transport for an http write-tool invocation', function() {
    herald_with_pro_registry(function() {
        InvocationLogger::logCall(
            'entry',
            ['mode' => 'save'],
            null,
            1200,
            new InvocationContext(
                transport: Server::TRANSPORT_HTTP,
                userId: $this->userId,
                clientName: 'cursor',
            ),
        );
    });

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->actorId)->toBe($this->userId);
    expect($events[0]->details['transport'])->toBe(Server::TRANSPORT_HTTP);
    expect($events[0]->details['duration_bucket'])->toBe('1-5s');
});

it('maps a tool error to a failure outcome', function() {
    herald_with_pro_registry(function() {
        InvocationLogger::logCall(
            'entry',
            ['mode' => 'save'],
            new ToolException('boom'),
            10,
            new InvocationContext(transport: Server::TRANSPORT_STDIO),
        );
    });

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->outcome)->toBe(AuditEvent::OUTCOME_FAILURE);
    expect($events[0]->details['kind'])->toBe(InvocationLogger::KIND_TOOL_ERROR);
});

it('emits nothing for a read-tool invocation', function() {
    InvocationLogger::logCall(
        'entries',
        ['mode' => 'list'],
        null,
        30,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});

// -----------------------------------------------------------------------------
// Dual-mode workflow tools — per-invocation `mode` classification
//
// `content_audit`, `drafts_and_revisions`, and `import_export` advertise
// `readOnlyHint: true` at the class level (accurate for their Free-tier
// modes) but carry Pro modes that mutate state under the same tool name.
// Each tool implements `DualModeToolInterface`, so `Audit` classifies from
// the invocation's actual `mode` argument rather than the class attribute.
// All three tools are Free-registered (`shouldRegister()` is always true),
// so no `herald_with_pro_registry()` wrapper is needed to resolve them via
// `Tools::getByName()`.
// -----------------------------------------------------------------------------

it('emits one write event for a content_audit Pro fix-mode invocation', function() {
    InvocationLogger::logCall(
        'content_audit',
        ['mode' => 'fix_relations'],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->details['tool'])->toBe('content_audit');
});

it('emits nothing for a content_audit read-mode invocation', function() {
    InvocationLogger::logCall(
        'content_audit',
        ['mode' => 'relations'],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});

it('emits one write event for a drafts_and_revisions apply invocation', function() {
    InvocationLogger::logCall(
        'drafts_and_revisions',
        ['mode' => 'apply', 'id' => 1],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->details['tool'])->toBe('drafts_and_revisions');
});

it('emits one write event for a drafts_and_revisions discard invocation', function() {
    InvocationLogger::logCall(
        'drafts_and_revisions',
        ['mode' => 'discard', 'id' => 1],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(1);
});

it('emits nothing for a drafts_and_revisions read-mode invocation', function() {
    InvocationLogger::logCall(
        'drafts_and_revisions',
        ['mode' => 'list_drafts'],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});

it('emits nothing for a drafts_and_revisions compare invocation', function() {
    InvocationLogger::logCall(
        'drafts_and_revisions',
        ['mode' => 'compare', 'leftId' => 1, 'rightId' => 2],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});

it('emits one write event for an import_export import invocation', function() {
    InvocationLogger::logCall(
        'import_export',
        ['mode' => 'import', 'payload' => ['format' => 2, 'entries' => []]],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->details['tool'])->toBe('import_export');
});

it('emits nothing for an import_export export invocation', function() {
    InvocationLogger::logCall(
        'import_export',
        ['mode' => 'export'],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    expect(_herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED))->toHaveCount(0);
});

it('emits a write event for an unknown mode on a known dual-mode tool (fail toward write)', function() {
    InvocationLogger::logCall(
        'content_audit',
        ['mode' => 'not_a_real_mode'],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->details['tool'])->toBe('content_audit');
});

it('emits a write event for a dual-mode tool invocation missing the mode argument entirely', function() {
    InvocationLogger::logCall(
        'import_export',
        [],
        null,
        15,
        new InvocationContext(transport: Server::TRANSPORT_STDIO),
    );

    $events = _herald_events_named($this->sink, Audit::EVENT_TOOL_WRITE_INVOKED);
    expect($events)->toHaveCount(1);
    expect($events[0]->details['tool'])->toBe('import_export');
});
