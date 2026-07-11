<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\elements\Category as CategoryElement;
use craft\elements\User;
use craft\models\CategoryGroup;
use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\IdempotencyTrait;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\support\ElementSerializer;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;

/**
 * =========================================================================
 * `category` Pro tool — create / update / delete categories.
 *
 * Write-side counterpart to the Free `categories` read tool. Three modes
 * dispatched off the `mode` argument:
 *
 *   - `create` — instantiate a fresh `Category`, set attributes + custom
 *     fields, save via `Craft::$app->getElements()->saveElement()`.
 *     Requires `saveCategories:{groupUid}` on the target group.
 *   - `update` — load an existing category by `id` or `uid`, mutate, save.
 *     Requires `saveCategories:{groupUid}` on the category's group.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes the
 *     row entirely. Requires `deleteCategories:{groupUid}`.
 *
 * Permission contract (locked decision 4 of `docs/plans/gate-8.md`):
 *   - `_requiredPermissions(array $arguments): array` returns the
 *     Craft permission strings the arguments imply. The same method
 *     drives both `filterFor()` (whole-tool visibility, sentinel
 *     `saveCategories:*` / `deleteCategories:*`) and the in-`execute()`
 *     re-check (per-group).
 *   - `execute()` re-checks against the resolved arguments before any
 *     element mutation. Throws `ToolException` with JSON-RPC code
 *     `-32002` on miss.
 *
 * Structured nesting: `parentId` is honoured for category groups using
 * Craft's native structure. The parent MUST belong to the same group as
 * the new / updated category — cross-group parents are rejected through
 * the validation envelope (not a thrown error), so the LLM can adjust.
 *
 * Validation envelope: when `saveElement(runValidation: true)` returns
 * false, the tool returns `{success: false, errors, mode, id}` rather
 * than throwing. `ToolException` is reserved for permission denial,
 * missing arguments, mode misuse, category-not-found, and trashed-on-
 * update.
 *
 * Field-value coercion: pass-through to `Category::setFieldValues()` —
 * Craft normalises per field type at save time.
 *
 * Idempotency contract: `idempotencyKey` is server-side dedup for
 * `create` and `update`. Cache key prefix: `herald:category:idem:`.
 * TTL 24h. Skipped on stdio (no per-request identity).
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Category — create / update / delete')]
class Category extends AbstractTool
{
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Idempotency cache key prefix. Mirrors the `herald:{toolName}:idem:`
     * shape from `Entry`. Consumed by `IdempotencyTrait`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'herald:category:idem:';

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
        return 'category';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft categories. Modes: create / update / delete. Per-group ' .
            'permissions required: saveCategories:{groupUid} for create / update, ' .
            'deleteCategories:{groupUid} for delete. Returns the serialised category on ' .
            'success; on field-level validation failure returns {success: false, errors: ' .
            '{handle: [messages]}, mode, id} rather than throwing — the LLM iterates on ' .
            'field values until they validate. Throws only for permission denial, missing ' .
            'arguments, mode misuse, or category-not-found. Pass `fields: {handle: value}`; ' .
            'Craft normalises per field type at save time. `parentId` must belong to the ' .
            'same group (cross-group parents are rejected via validation envelope). ' .
            '`idempotencyKey` (create / update only) caches the result for 24h. Pro edition only.';
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
            'groupId' => Schema::integer()->description('Category group id (create only — one of groupId / groupUid / groupHandle required).'),
            'groupUid' => Schema::string()->description('Category group uid (create only).'),
            'groupHandle' => Schema::string()->description('Category group handle (create only).'),

            // Native attributes.
            'title' => Schema::string(),
            'slug' => Schema::string()->description('Auto-generated by Craft if omitted on create.'),
            'enabled' => Schema::boolean()->description('Defaults to true on create.'),
            'parentId' => Schema::integer()->description('Structure parent id. Must belong to the same category group.'),
            'propagateTo' => Schema::array(Schema::any())
                ->description('Site ids or handles to propagate this category to. Multi-site only.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to Category::setFieldValues(); Craft normalises per field type.'),

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
     * Per-user visibility check. Returns `true` for the stdio caller
     * (trusted local user) and for any HTTP caller with at least one
     * `saveCategories:{groupUid}` or `deleteCategories:{groupUid}`
     * permission. `execute()` performs its own per-group re-check
     * regardless.
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

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if ($user->can("saveCategories:{$group->uid}")) {
                return true;
            }
            if ($user->can("deleteCategories:{$group->uid}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. Filters the `mode` enum so a user
     * without `deleteCategories:*` on any group doesn't see `delete`,
     * and a user without `saveCategories:*` doesn't see `create` /
     * `update`. stdio (`null`) always sees the full enum.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        $schema = static::getInputSchema();

        if ($user === null) {
            return $schema;
        }

        if ($user->admin) {
            return $schema;
        }

        $hasSave = false;
        $hasDelete = false;
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            if (!$hasSave && $user->can("saveCategories:{$group->uid}")) {
                $hasSave = true;
            }
            if (!$hasDelete && $user->can("deleteCategories:{$group->uid}")) {
                $hasDelete = true;
            }
            if ($hasSave && $hasDelete) {
                break;
            }
        }

        $modes = [];
        if ($hasSave) {
            $modes[] = 'create';
            $modes[] = 'update';
        }
        if ($hasDelete) {
            $modes[] = 'delete';
        }

        if ($modes === []) {
            // Defensive: filterFor() should have hidden the tool
            // entirely in this case. Leave the enum untouched rather
            // than emit an invalid empty-enum schema.
            return $schema;
        }

        $order = ['create', 'update', 'delete'];
        $filtered = array_values(array_intersect($order, $modes));

        $schema['properties']['mode']['enum'] = $filtered;

        return $schema;
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
            throw new ToolException('category: `mode` is required.');
        }

        return match ($mode) {
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            default => throw new ToolException(
                "category: unknown mode `{$mode}`. Allowed: create / update / delete."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * The Craft permissions the given arguments imply. Returns the
     * sentinel `saveCategories:*` / `deleteCategories:*` when group UID
     * resolution isn't possible from the arguments alone — used by
     * `filterFor()` to decide whole-tool visibility.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $mode = $this->_mode($arguments);
        $groupUid = $this->_resolveGroupUid($arguments);

        if ($groupUid === null) {
            return match ($mode) {
                'delete' => ['deleteCategories:*'],
                default => ['saveCategories:*'],
            };
        }

        return match ($mode) {
            'create', 'update' => ["saveCategories:{$groupUid}"],
            'delete' => ["deleteCategories:{$groupUid}"],
            default => [],
        };
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the rich category-specific format existing tests assert
     * on (mode + group UID + the missing permission string).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = $this->_mode($arguments) ?? '?';
        $groupUid = $this->_resolveGroupUid($arguments) ?? '?';

        return sprintf(
            'permission denied — mode `%s` on group `%s` requires `%s`.',
            $mode,
            $groupUid,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Create-mode dispatch. Resolves the target group, builds a fresh
     * `Category`, applies attributes + custom fields, saves through
     * Craft's elements service. Returns the serialised category or a
     * validation envelope.
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
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $group = $this->_resolveGroup($arguments);
        if ($group === null) {
            throw new ToolException('category: create requires one of groupId / groupUid / groupHandle.');
        }

        $this->_assertPermission($arguments + ['groupUid' => $group->uid]);

        $element = new CategoryElement();
        $element->groupId = (int) $group->id;
        $element->siteId = $this->_resolveSiteId($arguments);

        $this->_applyAttributes($element, $arguments, isCreate: true);
        $this->_applyFields($element, $arguments);

        // Cross-group parent validation — surface through the validation
        // envelope rather than throwing, so the LLM can adjust.
        if (!$this->_assertParentInGroup($element, $arguments)) {
            return [
                'success' => false,
                'mode' => 'create',
                'id' => null,
                'uid' => null,
                'errors' => [
                    'parentId' => ['Parent must belong to the same category group.'],
                ],
            ];
        }

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            return $this->_validationEnvelope($element, 'create');
        }

        $envelope = $this->_successEnvelope($element, 'create');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch. Resolves the existing category by id / uid,
     * re-checks permission against the category's group, applies
     * incoming attributes + field values, saves.
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
        // Permission was checked when this response was originally
        // computed; userId is in the cache key — see IdempotencyTrait.
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $element = $this->_resolveCategory($arguments);
        $group = $element->getGroup();

        $this->_assertPermission($arguments + ['groupUid' => $group->uid]);

        $this->_applyAttributes($element, $arguments, isCreate: false);
        $this->_applyFields($element, $arguments);

        if (!$this->_assertParentInGroup($element, $arguments)) {
            return [
                'success' => false,
                'mode' => 'update',
                'id' => $element->id !== null ? (int) $element->id : null,
                'uid' => $element->uid,
                'errors' => [
                    'parentId' => ['Parent must belong to the same category group.'],
                ],
            ];
        }

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
        $element = $this->_resolveCategory($arguments);
        $group = $element->getGroup();

        $this->_assertPermission($arguments + ['groupUid' => $group->uid]);

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "category: delete failed for id={$element->id}. See Craft logs for details."
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
     * Resolve the target group by groupId / groupUid / groupHandle in
     * that precedence order. Returns null when no group-resolving
     * argument is set; throws when an argument is set but resolves to
     * no group.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveGroup(array $arguments): ?CategoryGroup
    {
        $categories = Craft::$app->getCategories();

        $id = $arguments['groupId'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $group = $categories->getGroupById((int) $id);
            if ($group === null) {
                throw new ToolException("category: group id={$id} not found.");
            }
            return $group;
        }

        $uid = $arguments['groupUid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $group = $categories->getGroupByUid($uid);
            if ($group === null) {
                throw new ToolException("category: group uid={$uid} not found.");
            }
            return $group;
        }

        $handle = $arguments['groupHandle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            $group = $categories->getGroupByHandle($handle);
            if ($group === null) {
                throw new ToolException("category: group handle=`{$handle}` not found.");
            }
            return $group;
        }

        return null;
    }

    /**
     * Lighter-weight variant of `_resolveGroup()` that returns the UID
     * directly without throwing — used by `_requiredPermissions()` when
     * probed with empty arguments.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveGroupUid(array $arguments): ?string
    {
        try {
            $group = $this->_resolveGroup($arguments);
        } catch (ToolException) {
            return null;
        }
        return $group?->uid;
    }

    /**
     * Resolve the category by `id` or `uid` for `update` / `delete`.
     *
     * Trashed categories are excluded by Craft's element-query default.
     * When the lookup misses, a slow-path probe checks whether the
     * id/uid matches a trashed row; if yes, surface a mode-aware hint
     * naming the missing recovery path (Craft doesn't ship a category
     * restore tool today — the LLM gets told to use `hardDelete` for
     * permanent removal or to recover via Craft's CP).
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveCategory(array $arguments): CategoryElement
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = CategoryElement::find()->status(null)->site('*');

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException('category: `id` or `uid` is required.');
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $element = $query->one();
        if ($element instanceof CategoryElement) {
            return $element;
        }

        // Slow-path probe: does this id/uid match a TRASHED category?
        $trashedProbe = (clone $query)->trashed(true)->one();
        if ($trashedProbe instanceof CategoryElement) {
            $probeKey = $trashedProbe->id !== null ? "id={$trashedProbe->id}" : "uid={$trashedProbe->uid}";
            throw new ToolException(
                "category: {$probeKey} is trashed. To remove permanently, use mode=delete " .
                    'with hardDelete=true; to recover, restore the category via the Craft CP.'
            );
        }

        $key = (is_int($id) || (is_string($id) && ctype_digit($id))) ? "id={$id}" : "uid={$uid}";
        throw new ToolException("category: no category found for {$key}.");
    }

    /**
     * Apply scalar / parent attributes to the element. `setFieldValues()`
     * handles the custom-field surface separately in `_applyFields()`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyAttributes(CategoryElement $element, array $arguments, bool $isCreate): void
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

        if (array_key_exists('parentId', $arguments)) {
            $parentId = $arguments['parentId'];
            if (is_int($parentId) || (is_string($parentId) && ctype_digit($parentId))) {
                $element->setParentId((int) $parentId);
            }
        }
    }

    /**
     * Verify the supplied `parentId` belongs to the same category
     * group as `$element`. Returns true when no parent is supplied or
     * the parent's group matches; false when a cross-group parent is
     * detected.
     *
     * `setParentId()` was already called in `_applyAttributes()` so we
     * read the parent through Craft's parent resolution.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertParentInGroup(CategoryElement $element, array $arguments): bool
    {
        if (!array_key_exists('parentId', $arguments)) {
            return true;
        }

        $parentId = $arguments['parentId'];
        if (!is_int($parentId) && !(is_string($parentId) && ctype_digit($parentId))) {
            return true;
        }

        $parentId = (int) $parentId;
        if ($parentId === 0) {
            return true;
        }

        $parent = CategoryElement::find()
            ->id($parentId)
            ->status(null)
            ->site('*')
            ->one();

        if (!$parent instanceof CategoryElement) {
            // Parent doesn't exist — let Craft's own save-time validator
            // surface the error so the message is consistent with how
            // Craft handles bad structure references.
            return true;
        }

        return $parent->groupId === $element->groupId;
    }

    /**
     * Success envelope shape returned by create / update.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(CategoryElement $element, string $mode): array
    {
        $serializer = new ElementSerializer();
        return [
            'success' => true,
            'mode' => $mode,
            'category' => $serializer->serializeElement($element),
        ];
    }
}
