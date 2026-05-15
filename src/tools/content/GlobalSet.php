<?php

namespace craftpulse\cortex\tools\content;

use Craft;
use craft\elements\GlobalSet as GlobalSetElement;
use craft\elements\User;
use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ProToolTrait;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;

/**
 * =========================================================================
 * `global_set` Pro tool — update global set field values.
 *
 * Single-mode tool. Global sets aren't created or deleted at runtime —
 * their schema (name, handle, field layout) lives in project config and
 * is managed through Craft's CP / `project-config` console. This tool
 * only updates the field VALUES of an existing global set.
 *
 * The `mode` field defaults to `'update'` and is the only allowed
 * value; an explicit `'update'` is accepted for forward compatibility
 * (future modes can be added additively without breaking existing
 * payloads). Any other mode value throws.
 *
 * Permission contract — `editGlobalSet:{globalSetUid}` per locked
 * decision 4 of `docs/plans/gate-8.md`.
 *
 * Lookup: a global set is identifiable by `id`, `uid`, OR `handle`.
 * Operators usually know the handle; `id` and `uid` are accepted for
 * machine-driven callers.
 *
 * Validation envelope: when `saveElement(runValidation: true)` returns
 * false, the tool returns `{success: false, errors, mode, id}` rather
 * than throwing.
 *
 * Field-value coercion: pass-through to `GlobalSet::setFieldValues()`.
 *
 * Idempotency contract: `idempotencyKey` is server-side dedup.
 * Cache prefix: `cortex:global_set:idem:`. TTL 24h. Skipped on stdio.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Global Set — update field values')]
class GlobalSet extends AbstractTool
{
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * JSON-RPC error code emitted on permission denial.
     *
     * @since 5.0.0
     */
    public const ERROR_PERMISSION_DENIED = -32002;

    /**
     * Idempotency cache key prefix.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'cortex:global_set:idem:';

    /**
     * TTL applied to cached idempotency envelopes. 24 hours.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_TTL = 86400;

    /**
     * Max length for `idempotencyKey`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 64;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'global_set';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft global sets — updates field values on an existing set. ' .
            'Global sets are created / deleted in project config, not at runtime; this ' .
            'tool only mutates field values. Permission required: ' .
            'editGlobalSet:{globalSetUid}. Identify the set by `handle` (most common), ' .
            '`id`, or `uid`. Returns the serialised set on success; on validation failure ' .
            'returns {success: false, errors: {handle: [messages]}, mode, id}. The `mode` ' .
            'field defaults to `update` (the only allowed value today; reserved for ' .
            'forward compatibility). `idempotencyKey` caches the result for 24h. Pro ' .
            'edition only.';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'mode' => Schema::string()
                ->enum(['update'])
                ->default('update')
                ->description('Operation to perform. Currently only `update` is supported.'),

            // Element resolution — one of id / uid / handle required.
            'id' => Schema::integer()->description('Global set id. One of id / uid / handle required.'),
            'uid' => Schema::string()->description('Global set uid. Alternative to id / handle.'),
            'handle' => Schema::string()->description('Global set handle. Most common identifier — operators usually know the handle.'),

            // Site targeting.
            'siteId' => Schema::integer()->description('Target site id. Defaults to the primary site.'),
            'siteHandle' => Schema::string()->description('Target site handle. Alternative to `siteId`.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->required()
                ->description('Custom field values keyed by handle. Forwarded verbatim to GlobalSet::setFieldValues(); Craft normalises per field type.'),

            // Idempotency.
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('Server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user visibility check. Returns `true` for stdio (trusted)
     * and for any HTTP caller with `editGlobalSet:{globalSetUid}` on
     * at least one global set. `execute()` performs its own per-set
     * re-check.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            // stdio path — trusted local user, no per-request identity.
            return true;
        }

        if ($user->admin) {
            return true;
        }

        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            if ($user->can("editGlobalSet:{$set->uid}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $this->_mode($arguments) ?? 'update';

        if ($mode !== 'update') {
            throw new ToolException(
                "global_set: unknown mode `{$mode}`. Allowed: update."
            );
        }

        return $this->_update($arguments);
    }

    // Protected Methods
    // =========================================================================

    /**
     * The Craft permissions the given arguments imply. Returns the
     * sentinel `editGlobalSet:*` when set UID resolution isn't possible
     * from the arguments alone.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $globalSetUid = $this->_resolveGlobalSetUid($arguments);
        if ($globalSetUid === null) {
            return ['editGlobalSet:*'];
        }
        return ["editGlobalSet:{$globalSetUid}"];
    }

    // Private Methods
    // =========================================================================

    /**
     * Update-mode dispatch. Resolves the set by id / uid / handle,
     * checks permission, applies field values, saves.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _update(array $arguments): array
    {
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $element = $this->_resolveGlobalSet($arguments);
        $this->_assertPermission($element->uid);

        $this->_applyFields($element, $arguments);

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element);
        }

        $envelope = $this->_successEnvelope($element);
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Permission re-check on the resolved set UID. Throws the Gate 8
     * standard JSON-RPC `-32002` envelope on miss.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertPermission(?string $globalSetUid): void
    {
        if ($globalSetUid === null) {
            throw new ToolException('global_set: cannot resolve set UID for permission check.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            // stdio path — trusted local user. Skip the check.
            return;
        }

        if ($user->admin) {
            return;
        }

        $permission = "editGlobalSet:{$globalSetUid}";
        if (!$user->can($permission)) {
            throw new ToolException(sprintf(
                'permission denied — global_set update on set `%s` requires `%s`.',
                $globalSetUid,
                $permission,
            ));
        }
    }

    /**
     * Resolve the target global set by id / uid / handle in that
     * precedence order. Throws when no identifier is supplied or the
     * identifier doesn't resolve.
     *
     * Lookups use the Globals service for id / handle (cached, faster)
     * and fall back to `GlobalSet::find()->uid()` for uid (no service
     * accessor exists).
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveGlobalSet(array $arguments): GlobalSetElement
    {
        $globals = Craft::$app->getGlobals();
        $siteId = $this->_resolveSiteId($arguments);

        $id = $arguments['id'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $set = $globals->getSetById((int) $id, $siteId);
            if (!$set instanceof GlobalSetElement) {
                throw new ToolException("global_set: id={$id} not found.");
            }
            return $set;
        }

        $uid = $arguments['uid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $set = GlobalSetElement::find()
                ->uid($uid)
                ->siteId($siteId)
                ->one();
            if (!$set instanceof GlobalSetElement) {
                throw new ToolException("global_set: uid={$uid} not found.");
            }
            return $set;
        }

        $handle = $arguments['handle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            $set = $globals->getSetByHandle($handle, $siteId);
            if (!$set instanceof GlobalSetElement) {
                throw new ToolException("global_set: handle=`{$handle}` not found.");
            }
            return $set;
        }

        throw new ToolException('global_set: one of `id`, `uid`, or `handle` is required.');
    }

    /**
     * Lighter-weight variant of `_resolveGlobalSet()` that returns the
     * UID directly without throwing — used by `_requiredPermissions()`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveGlobalSetUid(array $arguments): ?string
    {
        try {
            $set = $this->_resolveGlobalSet($arguments);
        } catch (ToolException) {
            return null;
        }
        return $set->uid;
    }

    /**
     * Resolve the target site id from siteId / siteHandle, falling back
     * to the primary site.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveSiteId(array $arguments): int
    {
        $siteId = $arguments['siteId'] ?? null;
        if (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) {
            $site = Craft::$app->getSites()->getSiteById((int) $siteId);
            if ($site === null) {
                throw new ToolException("global_set: site id={$siteId} not found.");
            }
            return (int) $site->id;
        }

        $siteHandle = $arguments['siteHandle'] ?? null;
        if (is_string($siteHandle) && $siteHandle !== '') {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site === null) {
                throw new ToolException("global_set: site handle=`{$siteHandle}` not found.");
            }
            return (int) $site->id;
        }

        return (int) Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Forward `fields: {handle: value}` to Craft's setFieldValues().
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyFields(GlobalSetElement $element, array $arguments): void
    {
        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return;
        }
        $element->setFieldValues($fields);
    }

    /**
     * Success envelope shape.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(GlobalSetElement $element): array
    {
        $serializer = new ElementSerializer();
        return [
            'success' => true,
            'mode' => 'update',
            'globalSet' => $serializer->serializeElement($element),
        ];
    }

    /**
     * Validation envelope shape returned when `saveElement(runValidation:
     * true)` returns false.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validationEnvelope(GlobalSetElement $element): array
    {
        return [
            'success' => false,
            'mode' => 'update',
            'id' => $element->id !== null ? (int) $element->id : null,
            'uid' => $element->uid,
            'errors' => $element->getErrors(),
        ];
    }

    /**
     * Look up a cached idempotency envelope.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _idempotencyCacheHit(array $arguments): ?array
    {
        $key = $this->_idempotencyCacheKey($arguments);
        if ($key === null) {
            return null;
        }

        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return null;
        }
        $cached = $cache->get($key);
        if (!is_array($cached)) {
            return null;
        }

        /** @var array<string,mixed> $cached */
        return $cached;
    }

    /**
     * Cache the success envelope.
     *
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $envelope
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _cacheIdempotencyEnvelope(array $arguments, array $envelope): void
    {
        $key = $this->_idempotencyCacheKey($arguments);
        if ($key === null) {
            return;
        }
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return;
        }
        $cache->set($key, $envelope, self::IDEMPOTENCY_TTL);
    }

    /**
     * Build the idempotency cache key.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _idempotencyCacheKey(array $arguments): ?string
    {
        $idempotencyKey = $arguments['idempotencyKey'] ?? null;
        if (!is_string($idempotencyKey) || $idempotencyKey === '') {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            return null;
        }

        return self::IDEMPOTENCY_CACHE_PREFIX . $user->id . ':' . $idempotencyKey;
    }
}
