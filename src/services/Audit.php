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
use craftpulse\herald\tools\DualModeToolInterface;
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
 * Write-tool classification rides the tool's own MCP `readOnlyHint` for
 * most of the registry: a tool that advertises `#[IsReadOnly]` is a
 * read tool and is skipped; anything else (a mutating
 * `#[IsDestructive]` tool, or an action tool with no read-only hint)
 * is a write. The classification is resolved from the live registry,
 * so it never drifts from the tool taxonomy.
 *
 * A handful of dual-mode workflow tools (`content_audit`,
 * `drafts_and_revisions`, `import_export`) advertise `readOnlyHint:
 * true` at the class level — accurate for their Free-tier modes — but
 * carry Pro modes that mutate state under the same tool name. For
 * those, the class-level hint alone would misclassify every write
 * invocation as a read and it would never emit. Tools that implement
 * `craftpulse\herald\tools\DualModeToolInterface` are classified
 * per-invocation instead, from the `mode` the call actually carried
 * against the tool's own `getModeWriteMap()`. A mode the map doesn't
 * recognise (including an unresolvable `mode`) is treated as a write —
 * inverting the unresolvable-*tool* fail-closed-skip in the other
 * direction: a missed write is worse than a spurious governance
 * record.
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
 * `Invocations::record()` honours for the DB audit table.
 *
 * The bus always resolves. Audit Kit ships as a library-shipped Yii
 * module, registered from `Herald::init()` via `AuditKit::register()`,
 * and `AuditKit::getInstance()` registers it lazily on first access, so
 * there is no "present as a dependency but not enabled" state to fall
 * through to a null bus. That matters more here than the fail-soft
 * wrapper does: a null bus would let auditing stop with nothing thrown,
 * nothing logged, and a control panel that looks entirely healthy.
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
     *              bus resolves from the registered Audit Kit module. Set
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
     * Classification is per-tool for most of the registry (the class-
     * level MCP `readOnlyHint` decides), but a handful of dual-mode
     * workflow tools (`content_audit`, `drafts_and_revisions`,
     * `import_export`) advertise `readOnlyHint: true` at the class
     * level while carrying Pro modes that mutate state. For those,
     * `readOnlyHint` alone would misclassify every write invocation as
     * a read and it would never emit. Tools that implement
     * `DualModeToolInterface` are classified per-invocation instead,
     * from the `mode` argument the call actually carried (see
     * `_isWriteTool()`).
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

        if ($this->_isWriteTool($toolName, $entry) !== true) {
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
     * Record one event on the Audit Kit bus, fail-soft. A throwing bus /
     * sink is caught and logged, never propagated — the emission must
     * not break the flow that triggered it. With no sink registered the
     * bus itself is a cheap no-op.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _emit(AuditEvent $event): void
    {
        try {
            $this->_bus()->record($event);
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
     * the registered Audit Kit module's bus.
     *
     * Always a real `Bus`. Audit Kit 1.1.0 ships as a library-shipped
     * Yii module, so `AuditKit::getInstance()` registers the module on
     * first access and cannot return null; there is no longer a
     * "dependency present but not enabled" state to fall through. The
     * resolution deliberately carries no null branch: a nullable bus on
     * a security product whose value is the trail means auditing can
     * stop with nothing thrown and nothing logged.
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _bus(): Bus
    {
        return $this->_bus ?? AuditKit::getInstance()->getBus();
    }

    /**
     * Whether the named tool invocation mutates state. Resolves the
     * tool from the live registry; returns null when the name resolves
     * to no registered tool — the caller treats that as "not a write"
     * and skips, so a synthetic or unknown name never emits.
     *
     * A tool that implements `DualModeToolInterface` is classified
     * per-invocation from the call's actual `mode` (`_isWriteMode()`)
     * — its class-level MCP `readOnlyHint` reflects only the Free
     * tier and cannot see the Pro write modes. Every other tool is
     * classified once from that same `readOnlyHint`: a tool advertising
     * `#[IsReadOnly]` is a read tool (`false`); anything else is a
     * write (`true`).
     *
     * @param array<string,mixed> $entry
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _isWriteTool(string $toolName, array $entry): ?bool
    {
        $tool = Herald::getInstance()->tools->getByName($toolName);
        if ($tool === null) {
            return null;
        }

        if ($tool instanceof DualModeToolInterface) {
            return $this->_isWriteMode($tool, $entry);
        }

        $annotations = AttributeReader::annotationsFor($tool);
        return ($annotations['readOnlyHint'] ?? false) !== true;
    }

    /**
     * Per-invocation write classification for a `DualModeToolInterface`
     * tool. Reads the `mode` the call actually carried out of the
     * invocation entry's already-redacted `args` payload — no new
     * argument logging, just a decode of what `InvocationLogger` had
     * already captured for the file log / DB audit row.
     *
     * A mode absent from the tool's `getModeWriteMap()` (including an
     * unresolvable `mode` — missing, non-string, or an args payload
     * that fails to decode) is treated as a write. This deliberately
     * inverts the unresolvable-*tool* fail-closed-skip above: an
     * unknown tool name skips (nothing to attribute the write to), but
     * an unknown *mode* on a known dual-mode tool emits, because a
     * missed write is worse than a spurious governance record.
     *
     * @param array<string,mixed> $entry
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _isWriteMode(DualModeToolInterface $tool, array $entry): bool
    {
        $mode = $this->_resolveMode($entry);
        if ($mode === null) {
            return true;
        }

        $map = $tool::getModeWriteMap();
        return $map[$mode] ?? true;
    }

    /**
     * Decode the invocation entry's `args` field and pull out the
     * `mode` argument, or null when the field is missing, not valid
     * JSON, not an object, or carries no string `mode`. `args` is
     * already `SecretRedactor`-redacted by `InvocationLogger` — `mode`
     * is never a secret-shaped key, so it always survives redaction
     * intact.
     *
     * @param array<string,mixed> $entry
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    private function _resolveMode(array $entry): ?string
    {
        $argsJson = $entry['args'] ?? null;
        if (!is_string($argsJson) || $argsJson === '') {
            return null;
        }

        $decoded = json_decode($argsJson, true);
        if (!is_array($decoded)) {
            return null;
        }

        $mode = $decoded['mode'] ?? null;
        return is_string($mode) && $mode !== '' ? $mode : null;
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
