<?php

namespace craftpulse\herald\services;

use Craft;
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\AuditKit;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\Bus;
use craftpulse\herald\events\LogCallEvent;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\support\AttributeReader;
use craftpulse\herald\tools\support\InvocationLogger;
use Throwable;
use yii\base\Component;

/**
 * =========================================================================
 * Audit Kit emitter — Herald's native `AuditEvent` bridge.
 *
 * Herald keeps its own audit surfaces exactly as they were — the
 * `herald_invocations` DB table (`Invocations`) and the redacted KV
 * file log (`InvocationLogger`). This service is *additive*: it emits
 * native `craftpulse\auditkit\audit\AuditEvent`s onto the Audit Kit
 * dispatch `Bus` so a recorder (Ledger) can land every AI write and
 * every OAuth / bearer-token lifecycle action in a tamper-evident
 * chain. With no sink registered the bus is a cheap no-op, so the
 * emission is safe whether or not a recorder is installed.
 *
 * Two responsibilities:
 *
 *   1. **Event-type registration.** `registerEventTypes()` contributes
 *      Herald's `AuditEventType` definitions to the kit's runtime
 *      registry (wired to `EventTypes::EVENT_REGISTER_AUDIT_EVENTS` in
 *      `PluginTrait`). Each type carries a fail-closed `allowedDetailKeys`
 *      allowlist — a recorder strips any detail key not on the list, and
 *      an unregistered event name is dropped fail-closed at record time.
 *
 *   2. **Emission.** The lifecycle services call the `record*()` methods
 *      (OAuth client register / approve / revoke, elevation grant,
 *      bearer-token issue / revoke); the `EVENT_LOG_CALL` listener
 *      (`handleToolInvocation()`) emits one `herald.tool.write_invoked`
 *      per WRITE-tool call. Read tools are never emitted — the
 *      invocation log already covers them and the volume would swamp a
 *      chain.
 *
 * Write-tool classification rides the tool's own MCP `readOnlyHint`:
 * a tool that advertises `#[IsReadOnly]` is a read tool and is skipped;
 * anything else (a mutating `#[IsDestructive]` tool, or an action tool
 * with no read-only hint) is a write. The classification is resolved
 * from the live registry, so it never drifts from the tool taxonomy.
 *
 * Secret discipline mirrors `SecretRedactor`: token and client *ids*
 * (row ids, the public OAuth `client_id`) travel in `details`; token
 * *values* (bearer plaintext, JWTs, client secrets) never do. Details
 * are scalar-only and carry no content bodies and no PII — the
 * `AuditEvent` value object enforces the scalar rule, and the
 * `allowedDetailKeys` allowlist enforces the shape.
 *
 * Fail-soft: every emission is wrapped so a bus / sink failure can
 * never break the flow that triggered it — the same soft-write contract
 * `Invocations::record()` honours for the DB audit table. The
 * `AuditKit::getInstance()` resolution returns null when the kit plugin
 * is present as a dependency but not enabled, in which case emission is
 * a silent no-op.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.1.0
 */
class Audit extends Component
{
    // Constants
    // =========================================================================

    /**
     * The emitting plugin handle stamped onto every `AuditEvent`.
     *
     * @since 5.1.0
     */
    public const EMITTER = 'herald';

    /**
     * Category for the OAuth / bearer-token lifecycle events — operator
     * and security surface, grouped with the estate's `system` events.
     *
     * @since 5.1.0
     */
    public const CATEGORY_SYSTEM = 'system';

    /**
     * Category for the per-write-tool invocation event — an AI agent
     * mutating content / schema / dev / workflow state.
     *
     * @since 5.1.0
     */
    public const CATEGORY_CONTENT = 'content';

    public const EVENT_CLIENT_REGISTERED = 'herald.oauth.client_registered';
    public const EVENT_CLIENT_APPROVED = 'herald.oauth.client_approved';
    public const EVENT_CLIENT_REVOKED = 'herald.oauth.client_revoked';
    public const EVENT_ELEVATION_GRANTED = 'herald.oauth.elevation_granted';
    public const EVENT_TOKEN_ISSUED = 'herald.token.issued';
    public const EVENT_TOKEN_REVOKED = 'herald.token.revoked';
    public const EVENT_TOOL_WRITE_INVOKED = 'herald.tool.write_invoked';

    /**
     * Neutral target-type handles the events point at. An OAuth client
     * row, a bearer / token row, a Craft user (elevation subject), and
     * the tool acted through.
     *
     * @since 5.1.0
     */
    public const TARGET_OAUTH_CLIENT = 'oauth_client';
    public const TARGET_TOKEN = 'token';
    public const TARGET_USER = 'user';
    public const TARGET_TOOL = 'tool';

    // Private Properties
    // =========================================================================

    /**
     * @var Bus|null Explicit bus override. Null in production, where the
     *              bus resolves from the installed Audit Kit plugin. Set
     *              by tests to inject a bus carrying a capturing sink so
     *              emissions can be asserted in isolation from a real
     *              recorder.
     */
    private ?Bus $_bus = null;

    // Public Methods
    // =========================================================================

    /**
     * Contributes Herald's `AuditEventType` definitions to the kit's
     * runtime registry. Wired to
     * `EventTypes::EVENT_REGISTER_AUDIT_EVENTS` in `PluginTrait`. Every
     * type declares the exact `details` keys a recorder may persist —
     * the fail-closed privacy allowlist — so a recorder strips anything
     * else and drops an unregistered event name entirely.
     *
     * @param RegisterAuditEventsEvent $event
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function registerEventTypes(RegisterAuditEventsEvent $event): void
    {
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_CLIENT_REGISTERED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'OAuth client registered'),
            allowedDetailKeys: ['client_id', 'is_public', 'approved'],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_CLIENT_APPROVED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'OAuth client approved'),
            allowedDetailKeys: ['client_id'],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_CLIENT_REVOKED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'OAuth client revoked'),
            allowedDetailKeys: ['client_id'],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_ELEVATION_GRANTED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'Elevation granted'),
            allowedDetailKeys: [],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_TOKEN_ISSUED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'Bearer token issued'),
            allowedDetailKeys: ['name', 'bound_user_id'],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_TOKEN_REVOKED,
            category: self::CATEGORY_SYSTEM,
            label: Craft::t('herald', 'Bearer token revoked'),
            allowedDetailKeys: [],
        );
        $event->eventTypes[] = new AuditEventType(
            name: self::EVENT_TOOL_WRITE_INVOKED,
            category: self::CATEGORY_CONTENT,
            label: Craft::t('herald', 'Write tool invoked'),
            allowedDetailKeys: [
                'tool', 'kind', 'transport', 'outcome', 'duration_bucket', 'client', 'token_id',
            ],
        );
    }

    /**
     * Emit `herald.oauth.client_registered` after a Dynamic Client
     * Registration succeeds. The acting party is the (unauthenticated)
     * DCR request, so there is no actor; the public `client_id` and the
     * client's posture travel as scalar details. The client secret is
     * never emitted.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordClientRegistered(int $clientRowId, string $clientId, bool $isPublic, bool $approved): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_CLIENT_REGISTERED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            targetType: self::TARGET_OAUTH_CLIENT,
            targetId: $clientRowId,
            details: [
                'client_id' => $clientId,
                'is_public' => $isPublic,
                'approved' => $approved,
            ],
        ));
    }

    /**
     * Emit `herald.oauth.client_approved` when an admin approves a
     * registered client on the Clients CP screen.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordClientApproved(int $clientRowId, string $clientId): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_CLIENT_APPROVED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            actorId: $this->_currentActorId(),
            targetType: self::TARGET_OAUTH_CLIENT,
            targetId: $clientRowId,
            details: ['client_id' => $clientId],
        ));
    }

    /**
     * Emit `herald.oauth.client_revoked` when an admin revokes a client
     * (un-approving it and cutting off its live tokens).
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordClientRevoked(int $clientRowId, string $clientId): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_CLIENT_REVOKED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            actorId: $this->_currentActorId(),
            targetType: self::TARGET_OAUTH_CLIENT,
            targetId: $clientRowId,
            details: ['client_id' => $clientId],
        ));
    }

    /**
     * Emit `herald.oauth.elevation_granted` when a Craft user completes
     * the fresh-re-auth elevation handshake. The user is both the actor
     * (they re-authenticated) and the target (the elevation is bound to
     * them).
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordElevationGranted(int $userId): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_ELEVATION_GRANTED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            actorId: $userId,
            targetType: self::TARGET_USER,
            targetId: $userId,
        ));
    }

    /**
     * Emit `herald.token.issued` when a bearer token is minted. The
     * target is the token row; the admin issuing it is the actor (null
     * on the console path). The token label and the bound subject user
     * travel as scalar details — the plaintext never does.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordTokenIssued(int $tokenId, string $name, int $boundUserId): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_TOKEN_ISSUED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            actorId: $this->_currentActorId(),
            targetType: self::TARGET_TOKEN,
            targetId: $tokenId,
            details: [
                'name' => $name,
                'bound_user_id' => $boundUserId,
            ],
        ));
    }

    /**
     * Emit `herald.token.revoked` when a bearer token is revoked.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function recordTokenRevoked(int $tokenId): void
    {
        $this->_emit(new AuditEvent(
            name: self::EVENT_TOKEN_REVOKED,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: AuditEvent::OUTCOME_SUCCESS,
            actorId: $this->_currentActorId(),
            targetType: self::TARGET_TOKEN,
            targetId: $tokenId,
        ));
    }

    /**
     * `InvocationLogger::EVENT_LOG_CALL` listener. Emits exactly one
     * `herald.tool.write_invoked` per WRITE-tool invocation, across both
     * transports — so stdio writes reach a recorder too, closing the gap
     * where stdio calls only ever hit the file log. Read-tool
     * invocations, and invocations whose tool cannot be resolved to a
     * registered tool, are skipped fail-closed.
     *
     * The event fires for every tool call; the write filter is applied
     * here so the DB audit writer and the chain emitter stay independent
     * subscribers on the same seam.
     *
     * @param LogCallEvent $event
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function handleToolInvocation(LogCallEvent $event): void
    {
        $entry = $event->entry;
        $toolName = $entry['tool'] ?? null;
        if (!is_string($toolName) || $toolName === '') {
            return;
        }

        if ($this->_isWriteTool($toolName) !== true) {
            return;
        }

        $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : InvocationLogger::KIND_SUCCESS;
        $transport = is_string($entry['transport'] ?? null) ? $entry['transport'] : '';
        $durationMs = (int) ($entry['duration_ms'] ?? 0);
        $outcome = $this->_outcomeForKind($kind);
        $client = is_string($entry['client'] ?? null) ? $entry['client'] : null;
        $tokenId = is_int($entry['token_id'] ?? null) ? $entry['token_id'] : null;
        $actorId = is_int($entry['user'] ?? null) ? $entry['user'] : null;

        $this->_emit(new AuditEvent(
            name: self::EVENT_TOOL_WRITE_INVOKED,
            category: self::CATEGORY_CONTENT,
            emitter: self::EMITTER,
            outcome: $outcome,
            actorId: $actorId,
            targetType: self::TARGET_TOOL,
            details: [
                'tool' => $toolName,
                'kind' => $kind,
                'transport' => $transport,
                'outcome' => $outcome,
                'duration_bucket' => $this->_bucketDuration($durationMs),
                'client' => $client,
                'token_id' => $tokenId,
            ],
        ));
    }

    /**
     * Inject an explicit bus. Test seam — production resolves the bus
     * from the installed Audit Kit plugin. Passing a bus that carries a
     * capturing sink lets a test assert emissions without a real
     * recorder in the loop.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public function setBus(?Bus $bus): void
    {
        $this->_bus = $bus;
    }

    // Private Methods
    // =========================================================================

    /**
     * Record one event on the Audit Kit bus, fail-soft. A missing kit
     * (present as a dependency but not enabled) resolves to a null bus
     * and the emission is a silent no-op; a throwing bus / sink is
     * caught and logged, never propagated — the emission must not break
     * the flow that triggered it.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _emit(AuditEvent $event): void
    {
        $bus = $this->_bus();
        if ($bus === null) {
            return;
        }

        try {
            $bus->record($event);
        } catch (Throwable $e) {
            Craft::error(
                sprintf(
                    'Audit Kit emission failed for "%s": %s',
                    $event->name,
                    $e->getMessage(),
                ),
                Invocations::LOG_CATEGORY,
            );
        }
    }

    /**
     * Resolve the dispatch bus — the injected override in tests, else
     * the installed Audit Kit plugin's bus, else null when the kit is
     * not enabled.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _bus(): ?Bus
    {
        if ($this->_bus !== null) {
            return $this->_bus;
        }

        return AuditKit::getInstance()?->getBus();
    }

    /**
     * Whether the named tool mutates state. Resolves the tool from the
     * live registry and reads its MCP `readOnlyHint`: a tool that
     * advertises `#[IsReadOnly]` is a read tool (`false`); anything else
     * is a write (`true`). Returns null when the name resolves to no
     * registered tool — the caller treats that as "not a write" and
     * skips, so a synthetic or unknown name never emits.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _isWriteTool(string $toolName): ?bool
    {
        $tool = Herald::getInstance()->tools->getByName($toolName);
        if ($tool === null) {
            return null;
        }

        $annotations = AttributeReader::annotationsFor($tool);
        return ($annotations['readOnlyHint'] ?? false) !== true;
    }

    /**
     * Map an invocation `kind` onto an `AuditEvent` outcome. A clean
     * call is a success; a tool-level or internal error is a failure;
     * everything else (rate-limited, cancelled, or an unknown kind) is a
     * warning.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _outcomeForKind(string $kind): string
    {
        return match ($kind) {
            InvocationLogger::KIND_SUCCESS => AuditEvent::OUTCOME_SUCCESS,
            InvocationLogger::KIND_TOOL_ERROR, InvocationLogger::KIND_INTERNAL_ERROR => AuditEvent::OUTCOME_FAILURE,
            default => AuditEvent::OUTCOME_WARNING,
        };
    }

    /**
     * Bucket a duration in milliseconds into a coarse, non-identifying
     * band. A raw duration is a fingerprinting side channel; a bucket
     * keeps the audit useful (fast vs slow) without leaking timing.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _bucketDuration(int $ms): string
    {
        return match (true) {
            $ms < 100 => '0-100ms',
            $ms < 500 => '100-500ms',
            $ms < 1000 => '500ms-1s',
            $ms < 5000 => '1-5s',
            $ms < 30000 => '5-30s',
            default => '30s+',
        };
    }

    /**
     * The acting Craft user id, or null on the console path (where a
     * token is issued or revoked without a CP identity).
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _currentActorId(): ?int
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        return Craft::$app->getUser()->getIdentity()?->id;
    }
}
