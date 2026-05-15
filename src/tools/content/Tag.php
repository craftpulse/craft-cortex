<?php

namespace craftpulse\cortex\tools\content;

use Craft;
use craft\elements\Tag as TagElement;
use craft\elements\User;
use craft\models\TagGroup;
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
 * `tag` Pro tool — create / update / delete tags. Admin-only.
 *
 * Write-side counterpart to the Free `tags` read tool. Three modes
 * dispatched off the `mode` argument:
 *
 *   - `create` — instantiate a fresh `Tag`, set attributes + custom
 *     fields, save via `Craft::$app->getElements()->saveElement()`.
 *   - `update` — load an existing tag by `id` or `uid`, mutate, save.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes the
 *     row entirely.
 *
 * Permission contract — admin only.
 * Craft 5 has no per-tag-group permission (confirmed at
 * `vendor/craftcms/cms/src/elements/Tag.php:257-284` — Tag's `canView`,
 * `canSave`, and `canDelete` all return `true` unconditionally; the
 * permission system simply does not register a `saveTags:*` or
 * `deleteTags:*` permission). This is a Craft limitation, not a Cortex
 * one. To surface tag management to non-admins, a future Pro mode would
 * need to layer Cortex-specific permissions on top — out of scope for
 * Gate 8.3.
 *
 * `filterFor()` returns `$user->admin === true` (or `true` for stdio).
 * `_requiredPermissions()` returns `[]` because the gate is whole-tool,
 * not per-mode. `execute()` re-asserts admin status before any mutation
 * — defense in depth.
 *
 * Validation envelope and idempotency: same shape as `category` and
 * `entry`. Idempotency cache prefix: `cortex:tag:idem:`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Tag — create / update / delete (admin only)')]
class Tag extends AbstractTool
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
    public const IDEMPOTENCY_CACHE_PREFIX = 'cortex:tag:idem:';

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
        return 'tag';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft tags. Modes: create / update / delete. Admin only — ' .
            'Craft 5 has no per-tag-group permission, so tag management is gated to admin ' .
            'users. Returns the serialised tag on success; on field-level validation ' .
            'failure returns {success: false, errors: {handle: [messages]}, mode, id} ' .
            'rather than throwing. Throws only for permission denial, missing arguments, ' .
            'mode misuse, or tag-not-found. Pass `fields: {handle: value}`; Craft ' .
            'normalises per field type at save time. `idempotencyKey` (create / update ' .
            'only) caches the result for 24h. Pro edition only.';
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
                ->enum(['create', 'update', 'delete'])
                ->required()
                ->description('Operation to perform.'),

            // Element resolution (update / delete).
            'id' => Schema::integer()->description('Element id. Required for update / delete unless `uid` is supplied.'),
            'uid' => Schema::string()->description('Element uid. Alternative to `id` for update / delete.'),

            // Site targeting.
            'siteId' => Schema::integer()->description('Target site id. Defaults to the primary site.'),
            'siteHandle' => Schema::string()->description('Target site handle. Alternative to `siteId`.'),

            // Group resolution (create only).
            'groupId' => Schema::integer()->description('Tag group id (create only — one of groupId / groupUid / groupHandle required).'),
            'groupUid' => Schema::string()->description('Tag group uid (create only).'),
            'groupHandle' => Schema::string()->description('Tag group handle (create only).'),

            // Native attributes.
            'title' => Schema::string(),
            'slug' => Schema::string()->description('Auto-generated by Craft if omitted on create.'),
            'enabled' => Schema::boolean()->description('Defaults to true on create.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to Tag::setFieldValues(); Craft normalises per field type.'),

            // Mode-specific flags.
            'hardDelete' => Schema::boolean()->description('delete only: when true, removes the row entirely (no restore possible). Default false.'),

            // Idempotency.
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('create / update only: server-side dedup token. Same key issued twice within 24h returns the cached envelope without re-saving.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Admin-only whole-tool gate. Returns `true` for stdio (no
     * per-request identity) and for admin users. Non-admin users
     * never see the tool — Craft 5 has no per-tag-group permission,
     * so there's no granular path that could authorise tag writes for
     * a non-admin.
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

        return $user->admin === true;
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
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('tag: `mode` is required.');
        }

        $this->_assertAdmin();

        return match ($mode) {
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            default => throw new ToolException(
                "tag: unknown mode `{$mode}`. Allowed: create / update / delete."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * The Craft permissions the given arguments imply. Returns `[]`
     * for `tag` because the admin gate is whole-tool, not per-mode —
     * `execute()` re-asserts admin status directly.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        return [];
    }

    // Private Methods
    // =========================================================================

    /**
     * Defense-in-depth admin re-check. `filterFor()` hides the tool
     * from non-admins, but a direct dispatch (or a stale per-user
     * resolved registry) could still reach `execute()`. Refuses
     * everything that isn't admin or stdio.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertAdmin(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            // stdio path — trusted local user.
            return;
        }

        if ($user->admin === true) {
            return;
        }

        throw new ToolException(
            'permission denied — tag operations require admin status. Craft 5 has no ' .
                'per-tag-group permission, so non-admin tag management is not supported.'
        );
    }

    /**
     * Create-mode dispatch.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _create(array $arguments): array
    {
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $group = $this->_resolveGroup($arguments);
        if ($group === null) {
            throw new ToolException('tag: create requires one of groupId / groupUid / groupHandle.');
        }

        $element = new TagElement();
        $element->groupId = (int) $group->id;
        $element->siteId = $this->_resolveSiteId($arguments);

        $this->_applyAttributes($element, $arguments, isCreate: true);
        $this->_applyFields($element, $arguments);

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'create');
        }

        $envelope = $this->_successEnvelope($element, 'create');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch.
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

        $element = $this->_resolveTag($arguments);

        $this->_applyAttributes($element, $arguments, isCreate: false);
        $this->_applyFields($element, $arguments);

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'update');
        }

        $envelope = $this->_successEnvelope($element, 'update');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Delete-mode dispatch. Soft-deletes by default; `hardDelete: true`
     * removes the row entirely.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _delete(array $arguments): array
    {
        $element = $this->_resolveTag($arguments);

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "tag: delete failed for id={$element->id}. See Craft logs for details."
            );
        }

        return [
            'success' => true,
            'mode' => 'delete',
            'id' => (int) $element->id,
            'uid' => $element->uid,
            'hardDeleted' => $hardDelete,
        ];
    }

    /**
     * Resolve the target tag group by groupId / groupUid / groupHandle.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveGroup(array $arguments): ?TagGroup
    {
        $tags = Craft::$app->getTags();

        $id = $arguments['groupId'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $group = $tags->getTagGroupById((int) $id);
            if ($group === null) {
                throw new ToolException("tag: group id={$id} not found.");
            }
            return $group;
        }

        $uid = $arguments['groupUid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $group = $tags->getTagGroupByUid($uid);
            if ($group === null) {
                throw new ToolException("tag: group uid={$uid} not found.");
            }
            return $group;
        }

        $handle = $arguments['groupHandle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            $group = $tags->getTagGroupByHandle($handle);
            if ($group === null) {
                throw new ToolException("tag: group handle=`{$handle}` not found.");
            }
            return $group;
        }

        return null;
    }

    /**
     * Resolve the tag by `id` or `uid` for `update` / `delete`. Trashed
     * tags are excluded; if the id/uid matches a trashed tag, surface a
     * mode-aware hint message.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveTag(array $arguments): TagElement
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = TagElement::find()->status(null)->site('*');

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException('tag: `id` or `uid` is required.');
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $element = $query->one();
        if ($element instanceof TagElement) {
            return $element;
        }

        // Slow-path probe for trashed tag.
        $trashedProbe = (clone $query)->trashed(true)->one();
        if ($trashedProbe instanceof TagElement) {
            $probeKey = $trashedProbe->id !== null ? "id={$trashedProbe->id}" : "uid={$trashedProbe->uid}";
            throw new ToolException(
                "tag: {$probeKey} is trashed. To remove permanently, use mode=delete with " .
                    'hardDelete=true; to recover, restore the tag via the Craft CP.'
            );
        }

        $key = (is_int($id) || (is_string($id) && ctype_digit($id))) ? "id={$id}" : "uid={$uid}";
        throw new ToolException("tag: no tag found for {$key}.");
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
        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            return $siteId;
        }
        return (int) Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Resolve the target site id from siteId / siteHandle, returning
     * null when neither is supplied.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveOptionalSiteId(array $arguments): ?int
    {
        $siteId = $arguments['siteId'] ?? null;
        if (is_int($siteId) || (is_string($siteId) && ctype_digit($siteId))) {
            $site = Craft::$app->getSites()->getSiteById((int) $siteId);
            if ($site === null) {
                throw new ToolException("tag: site id={$siteId} not found.");
            }
            return (int) $site->id;
        }

        $siteHandle = $arguments['siteHandle'] ?? null;
        if (is_string($siteHandle) && $siteHandle !== '') {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site === null) {
                throw new ToolException("tag: site handle=`{$siteHandle}` not found.");
            }
            return (int) $site->id;
        }

        return null;
    }

    /**
     * Apply scalar attributes to the element.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyAttributes(TagElement $element, array $arguments, bool $isCreate): void
    {
        if (array_key_exists('title', $arguments) && is_string($arguments['title'])) {
            $element->title = $arguments['title'];
        }

        if (array_key_exists('slug', $arguments) && is_string($arguments['slug'])) {
            $element->slug = $arguments['slug'];
        }

        if (array_key_exists('enabled', $arguments)) {
            $element->enabled = (bool) $arguments['enabled'];
        } elseif ($isCreate) {
            $element->enabled = true;
        }
    }

    /**
     * Forward `fields: {handle: value}` to Craft's setFieldValues().
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyFields(TagElement $element, array $arguments): void
    {
        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return;
        }
        $element->setFieldValues($fields);
    }

    /**
     * Success envelope shape returned by create / update.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(TagElement $element, string $mode): array
    {
        $serializer = new ElementSerializer();
        return [
            'success' => true,
            'mode' => $mode,
            'tag' => $serializer->serializeElement($element),
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
    private function _validationEnvelope(TagElement $element, string $mode): array
    {
        return [
            'success' => false,
            'mode' => $mode,
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
