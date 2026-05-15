<?php

namespace craftpulse\cortex\tools\content;

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\models\EntryType;
use craft\models\Section;
use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ProToolTrait;
use craftpulse\cortex\tools\support\ElementSerializer;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use Throwable;

/**
 * =========================================================================
 * `entry` Pro tool — create / update / delete / restore / apply_draft.
 *
 * Write-side counterpart to the Free `entries` read tool. Five modes
 * dispatched off the `mode` argument:
 *
 *   - `create` — instantiate a fresh `Entry`, set attributes + custom
 *     fields, save via `Craft::$app->getElements()->saveElement()`.
 *     Requires `saveEntries:{sectionUid}` on the target section.
 *   - `update` — load an existing entry by `id` or `uid`, mutate, save.
 *     Requires `saveEntries:{sectionUid}` on the entry's section.
 *   - `delete` — soft-delete by default; `hardDelete: true` removes the
 *     row entirely. Requires `deleteEntries:{sectionUid}`.
 *   - `restore` — load via `->trashed()`, restore via the elements
 *     service. Requires `saveEntries:{sectionUid}`.
 *   - `apply_draft` — load a draft by `id` / `uid`, apply to canonical.
 *     Requires `saveEntries:{sectionUid}` on the canonical's section.
 *
 * Permission contract (locked decision 4 of `docs/plans/gate-8.md`):
 *   - `_requiredPermissions(array $arguments): array` returns the
 *     Craft permission strings the arguments imply. The same method
 *     drives both `filterFor()` (whole-tool visibility, sentinel
 *     `saveEntries:*`) and the in-`execute()` re-check (per-section).
 *   - `execute()` re-checks against the resolved arguments before any
 *     element mutation. Throws `ToolException` with JSON-RPC code
 *     `-32002` on miss; data envelope `{required, tool, mode}`.
 *
 * Validation envelope: when `saveElement(runValidation: true)` returns
 * false, the tool returns `{success: false, errors, mode, id}` rather
 * than throwing. `ToolException` is reserved for permission denial,
 * missing arguments, mode misuse, entry-not-found — outcomes the LLM
 * can't fix without external help. Validation failures are an iterable
 * outcome the LLM SHOULD reason about.
 *
 * Field-value coercion: `fields: {handle: value}` is forwarded to
 * `Entry::setFieldValues()` verbatim — Craft's per-field-type
 * normalisation runs at save time. The tool does not re-implement
 * coercion. Document the "we pass through; Craft validates" contract
 * to clients so they understand iteration on errors is expected.
 *
 * Idempotency contract: `idempotencyKey` is server-side deduplication
 * for `create` and `update` only. When present:
 *   - Cache key: `cortex:entry:idem:{userId}:{idempotencyKey}` — scoped
 *     per-user so two users with the same key don't collide.
 *   - TTL: 24h (86400s).
 *   - On replay: returns the cached envelope without re-saving and
 *     without re-checking permissions (the original call already
 *     passed; replaying it is idempotent).
 *   - Validation failures are NOT cached — the LLM may want to retry
 *     with corrected fields.
 *
 * Cancellation: this is a single-mutation tool, not streaming. The
 * Gate 7.4 InvocationContext is irrelevant here per locked decision 8
 * of `docs/plans/gate-8.md` — the operation either completes or
 * doesn't. No `getCancellationToken()` polling.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Entry — create / update / delete / restore / apply_draft')]
class Entry extends AbstractTool
{
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * JSON-RPC error code emitted on permission denial. Matches the
     * shape every Gate 8 Pro tool returns per locked decision 3 of
     * `docs/plans/gate-8.md` — `-32002` is the cortex-wide "permission
     * denied" code and stays stable across `filterFor()`-level and
     * per-mode denials.
     *
     * @since 5.0.0
     */
    public const ERROR_PERMISSION_DENIED = -32002;

    /**
     * Idempotency cache key prefix. Gate 8.3+ Pro tools should follow
     * the same `cortex:{toolName}:idem:{userId}:{key}` shape so the
     * cache namespace stays grep-able and the per-user scope is
     * uniform.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'cortex:entry:idem:';

    /**
     * TTL applied to cached idempotency envelopes. 24 hours mirrors
     * common API conventions (Stripe, GitHub) without being so long
     * the cache table fills up. Beyond TTL, the same key re-runs the
     * save — the LLM gets a fresh attempt.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_TTL = 86400;

    /**
     * Max length for `idempotencyKey`. Matches the schema's
     * `maxLength` constraint. Longer keys are rejected at JSON-Schema
     * validation upstream; execute() does not re-check.
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
        return 'entry';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Write tool for Craft entries. Modes: create / update / delete / restore / ' .
            'apply_draft. Per-section permissions required: saveEntries:{sectionUid} for ' .
            'create / update / restore / apply_draft, deleteEntries:{sectionUid} for delete. ' .
            'Returns the serialised entry on success; on field-level validation failure ' .
            'returns {success: false, errors: {handle: [messages]}, mode, id} rather than ' .
            'throwing — the LLM iterates on field values until they validate. Throws only ' .
            'for permission denial, missing arguments, mode misuse, or entry-not-found. ' .
            'Pass `fields: {handle: value}`; Craft normalises per field type at save time. ' .
            '`idempotencyKey` (create / update only) caches the result for 24h so safe ' .
            'retries don\'t double-save. Pro edition only.';
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
                ->enum(['create', 'update', 'delete', 'restore', 'apply_draft'])
                ->required()
                ->description('Operation to perform.'),

            // Element resolution (update / delete / restore / apply_draft).
            'id' => Schema::integer()->description('Element id. Required for update / delete / restore / apply_draft unless `uid` is supplied.'),
            'uid' => Schema::string()->description('Element uid. Alternative to `id` for update / delete / restore / apply_draft.'),

            // Site targeting.
            'siteId' => Schema::integer()->description('Target site id. Defaults to the primary site.'),
            'siteHandle' => Schema::string()->description('Target site handle. Alternative to `siteId`.'),

            // Section / entry-type resolution (create only).
            'sectionId' => Schema::integer()->description('Section id (create only — one of sectionId / sectionUid / sectionHandle required).'),
            'sectionUid' => Schema::string()->description('Section uid (create only).'),
            'sectionHandle' => Schema::string()->description('Section handle (create only).'),
            'entryTypeId' => Schema::integer()->description('Entry-type id (create only — required when the section has multiple entry types).'),
            'entryTypeUid' => Schema::string()->description('Entry-type uid (create only).'),
            'entryTypeHandle' => Schema::string()->description('Entry-type handle (create only).'),

            // Native attributes.
            'title' => Schema::string(),
            'slug' => Schema::string()->description('Auto-generated by Craft if omitted on create.'),
            'authorId' => Schema::integer()->description('Author user id. Defaults to the bearer-token user on create.'),
            'postDate' => Schema::string()->format('date-time')->description('ISO 8601 datetime. `null` allowed to clear.'),
            'expiryDate' => Schema::string()->format('date-time')->description('ISO 8601 datetime. `null` allowed to clear.'),
            'enabled' => Schema::boolean()->description('Defaults to true on create.'),
            'parentId' => Schema::integer()->description('Structure parent id. Only valid in Structure sections.'),
            'propagateTo' => Schema::array(Schema::any())
                ->description('Site ids or handles to propagate this entry to. Multi-site only.'),

            // Custom-field values — pass-through to Craft's setFieldValues().
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('Custom field values keyed by handle. Forwarded verbatim to Entry::setFieldValues(); Craft normalises per field type.'),

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
     * `saveEntries:{sectionUid}` permission. `delete`-only callers
     * (i.e. `deleteEntries:*` but no `saveEntries:*`) are not surfaced
     * — the practical universe of "delete entries you can't otherwise
     * edit" is empty in Craft's permission model.
     *
     * `execute()` performs its own per-section re-check regardless —
     * filterFor is for the LLM's tool-selection UX; execute is the
     * security boundary; both fail closed.
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

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:{$section->uid}")) {
                return true;
            }
            if ($user->can("deleteEntries:{$section->uid}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. Filters the `mode` enum so a
     * user without `deleteEntries:*` on any section doesn't see
     * `delete` in their tools/list, and a user without
     * `saveEntries:*` doesn't see `create / update / restore /
     * apply_draft`. stdio (`null`) always sees the full enum.
     *
     * The LLM benefits from accurate mode visibility — picking a mode
     * the user can't reach is wasted work. `execute()` still
     * re-validates the resolved mode for security.
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
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!$hasSave && $user->can("saveEntries:{$section->uid}")) {
                $hasSave = true;
            }
            if (!$hasDelete && $user->can("deleteEntries:{$section->uid}")) {
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
            $modes[] = 'restore';
            $modes[] = 'apply_draft';
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

        // Preserve schema order (create / update / delete / restore /
        // apply_draft) regardless of which check flipped first.
        $order = ['create', 'update', 'delete', 'restore', 'apply_draft'];
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
            throw new ToolException("entry: `mode` is required.");
        }

        return match ($mode) {
            'create' => $this->_create($arguments),
            'update' => $this->_update($arguments),
            'delete' => $this->_delete($arguments),
            'restore' => $this->_restore($arguments),
            'apply_draft' => $this->_applyDraft($arguments),
            default => throw new ToolException(
                "entry: unknown mode `{$mode}`. Allowed: create / update / delete / restore / apply_draft."
            ),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * The Craft permissions the given arguments imply. Returns the
     * sentinel `saveEntries:*` / `deleteEntries:*` when section UID
     * resolution isn't possible from the arguments alone — used by
     * `filterFor()` to decide whole-tool visibility.
     *
     * This is the **convention reference** for Gate 8.3 / 8.4 / 8.5.
     * Copy the shape; don't extract to a trait yet — the second
     * consumer (`category`) earns the trait extraction.
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
        $sectionUid = $this->_resolveSectionUid($arguments);

        if ($sectionUid === null) {
            // Caller has supplied no section-resolving arguments (e.g.
            // filterFor() probing with empty args). Return the
            // wildcard sentinel — the caller (filterFor()) treats
            // `:*` as "any section" and walks the user's section
            // permissions inline.
            return match ($mode) {
                'delete' => ['deleteEntries:*'],
                default => ['saveEntries:*'],
            };
        }

        return match ($mode) {
            'create', 'update', 'restore', 'apply_draft' => ["saveEntries:{$sectionUid}"],
            'delete' => ["deleteEntries:{$sectionUid}"],
            default => [],
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * Create-mode dispatch. Resolves section + entry type, builds a
     * fresh `Entry`, applies attributes + custom fields, saves through
     * Craft's elements service. Returns the serialised entry or a
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
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            return $cacheHit;
        }

        $section = $this->_resolveSection($arguments);
        if ($section === null) {
            throw new ToolException("entry: create requires one of sectionId / sectionUid / sectionHandle.");
        }

        $entryType = $this->_resolveEntryType($arguments, $section);
        if ($entryType === null) {
            throw new ToolException(
                "entry: section `{$section->handle}` has multiple entry types; one of " .
                    "entryTypeId / entryTypeUid / entryTypeHandle is required."
            );
        }

        $this->_assertPermission('create', $section->uid);

        if ($entryType->id === null) {
            throw new ToolException("entry: resolved entry-type has no id (unsaved?).");
        }

        $element = new EntryElement();
        $element->sectionId = $section->id;
        $element->typeId = (int) $entryType->id;
        $element->siteId = $this->_resolveSiteId($arguments);

        $this->_applyAttributes($element, $arguments, isCreate: true);
        $this->_applyFields($element, $arguments);

        if (!Craft::$app->getElements()->saveElement($element, runValidation: true)) {
            // Field-level validation failure — return iterable shape,
            // don't cache.
            return $this->_validationEnvelope($element, 'create');
        }

        $envelope = $this->_successEnvelope($element, 'create');
        $this->_cacheIdempotencyEnvelope($arguments, $envelope);

        return $envelope;
    }

    /**
     * Update-mode dispatch. Resolves the existing entry by id / uid,
     * re-checks permission against the entry's section, applies
     * incoming attributes + field values, saves.
     *
     * Supplied `sectionId / sectionUid / sectionHandle` are ignored
     * on update — the section is implied by the loaded entry, and
     * moving an entry between sections is a separate workflow
     * (Gate 8.6 `bulk_entries` migrate mode).
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

        $element = $this->_resolveEntry($arguments);
        $section = $element->getSection();
        if ($section === null) {
            throw new ToolException(
                "entry: update on a sectionless (nested) entry is not supported in Gate 8.2."
            );
        }

        $this->_assertPermission('update', $section->uid);

        // Optional entry-type switch — when the caller supplied
        // entryTypeId/Uid/Handle the resolved type is set; otherwise
        // `_resolveEntryType()` may still return the section's only
        // entry type, which is a harmless re-assignment to the
        // existing typeId. Craft handles incompatible field drops at
        // save time.
        $entryType = $this->_resolveEntryType($arguments, $section);
        if ($entryType !== null && $entryType->id !== null) {
            $element->typeId = (int) $entryType->id;
        }

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
        $element = $this->_resolveEntry($arguments);
        $section = $element->getSection();
        if ($section === null) {
            throw new ToolException(
                "entry: delete on a sectionless (nested) entry is not supported in Gate 8.2."
            );
        }

        $this->_assertPermission('delete', $section->uid);

        $hardDelete = (bool) ($arguments['hardDelete'] ?? false);

        if (!Craft::$app->getElements()->deleteElement($element, hardDelete: $hardDelete)) {
            throw new ToolException(
                "entry: delete failed for id={$element->id}. See Craft logs for details."
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
     * Restore-mode dispatch. Loads via `->trashed()`, restores via
     * the elements service.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _restore(array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = EntryElement::find()->status(null)->trashed(true)->site('*');
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException("entry: restore requires `id` or `uid`.");
        }

        $element = $query->one();
        if (!$element instanceof EntryElement) {
            throw new ToolException("entry: no trashed entry found.");
        }

        $section = $element->getSection();
        if ($section === null) {
            throw new ToolException(
                "entry: restore on a sectionless (nested) entry is not supported in Gate 8.2."
            );
        }

        $this->_assertPermission('restore', $section->uid);

        if (!Craft::$app->getElements()->restoreElement($element)) {
            throw new ToolException(
                "entry: restore failed for id={$element->id}. See Craft logs for details."
            );
        }

        return $this->_successEnvelope($element, 'restore');
    }

    /**
     * Apply-draft-mode dispatch. Resolves the draft by `id` or `uid`
     * (the argument refers to the **draft**, not the canonical),
     * checks `saveEntries:{sectionUid}` on the canonical's section,
     * applies the draft via Craft's drafts service.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyDraft(array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = EntryElement::find()->status(null)->drafts(true)->site('*');
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException("entry: apply_draft requires `id` or `uid`.");
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $draft = $query->one();
        if (!$draft instanceof EntryElement) {
            throw new ToolException("entry: no draft found.");
        }

        $canonical = $draft->getCanonical(anySite: true);
        if (!$canonical instanceof EntryElement) {
            throw new ToolException("entry: apply_draft could not resolve canonical for draft id={$draft->id}.");
        }

        $canonicalSection = $canonical->getSection();
        if ($canonicalSection === null) {
            throw new ToolException(
                "entry: apply_draft on a sectionless (nested) entry is not supported in Gate 8.2."
            );
        }

        $this->_assertPermission('apply_draft', $canonicalSection->uid);

        try {
            $applied = Craft::$app->getDrafts()->applyDraft($draft);
        } catch (Throwable $e) {
            throw new ToolException("entry: apply_draft failed — {$e->getMessage()}");
        }

        if (!$applied instanceof EntryElement) {
            throw new ToolException(
                "entry: apply_draft returned non-Entry element for draft id={$draft->id}."
            );
        }

        return $this->_successEnvelope($applied, 'apply_draft');
    }

    /**
     * Permission re-check on the resolved section UID. Throws the
     * Gate 8 standard JSON-RPC `-32002` envelope on miss. Admins
     * bypass — Craft's `User::can()` already returns true for admins,
     * but the explicit branch is defensive against accidental
     * permission-system reconfiguration that flips that default.
     *
     * `$sectionUid` is nominally typed `?string` only because Craft
     * declares Section::$uid as nullable on the base model; in
     * practice every persisted section has a UID, and a null arrival
     * here indicates an unsaved-section programmer error. We throw a
     * `ToolException` in that case rather than `\TypeError` so the
     * MCP envelope stays well-formed.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertPermission(string $mode, ?string $sectionUid): void
    {
        if ($sectionUid === null) {
            throw new ToolException("entry: cannot resolve section UID for permission check.");
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            // stdio path — trusted local user. Skip the check.
            return;
        }

        if ($user->admin) {
            return;
        }

        $permission = match ($mode) {
            'delete' => "deleteEntries:{$sectionUid}",
            default => "saveEntries:{$sectionUid}",
        };

        if (!$user->can($permission)) {
            throw new ToolException(sprintf(
                'permission denied — mode `%s` on section `%s` requires `%s`.',
                $mode,
                $sectionUid,
                $permission,
            ));
        }
    }

    /**
     * Build the {section}, looking up by sectionId / sectionUid /
     * sectionHandle in that precedence order. Returns null when no
     * section-resolving argument is set; throws when an argument is
     * set but resolves to no section (i.e. the caller mistyped).
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveSection(array $arguments): ?Section
    {
        $entries = Craft::$app->getEntries();

        $id = $arguments['sectionId'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $section = $entries->getSectionById((int) $id);
            if ($section === null) {
                throw new ToolException("entry: section id={$id} not found.");
            }
            return $section;
        }

        $uid = $arguments['sectionUid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $section = $entries->getSectionByUid($uid);
            if ($section === null) {
                throw new ToolException("entry: section uid={$uid} not found.");
            }
            return $section;
        }

        $handle = $arguments['sectionHandle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            $section = $entries->getSectionByHandle($handle);
            if ($section === null) {
                throw new ToolException("entry: section handle=`{$handle}` not found.");
            }
            return $section;
        }

        return null;
    }

    /**
     * Lighter-weight variant of `_resolveSection()` that returns the
     * UID directly without throwing — used by `_requiredPermissions()`
     * when probed with empty arguments (filterFor()).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveSectionUid(array $arguments): ?string
    {
        try {
            $section = $this->_resolveSection($arguments);
        } catch (ToolException) {
            return null;
        }
        return $section?->uid;
    }

    /**
     * Resolve the entry type for the section, by id / uid / handle.
     * When no argument is supplied and the section has exactly one
     * entry type, returns that type (Craft's natural default).
     * Returns null when no argument is supplied and the section has
     * multiple types — the caller (`_create`) throws with a helpful
     * message; the caller (`_update`) silently skips the type switch.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveEntryType(array $arguments, Section $section): ?EntryType
    {
        $entries = Craft::$app->getEntries();

        $id = $arguments['entryTypeId'] ?? null;
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $entryType = $entries->getEntryTypeById((int) $id);
            if ($entryType === null) {
                throw new ToolException("entry: entry-type id={$id} not found.");
            }
            return $entryType;
        }

        $uid = $arguments['entryTypeUid'] ?? null;
        if (is_string($uid) && $uid !== '') {
            $entryType = $entries->getEntryTypeByUid($uid);
            if ($entryType === null) {
                throw new ToolException("entry: entry-type uid={$uid} not found.");
            }
            return $entryType;
        }

        $handle = $arguments['entryTypeHandle'] ?? null;
        if (is_string($handle) && $handle !== '') {
            $entryType = $entries->getEntryTypeByHandle($handle);
            if ($entryType === null) {
                throw new ToolException("entry: entry-type handle=`{$handle}` not found.");
            }
            return $entryType;
        }

        $types = $section->getEntryTypes();
        if (count($types) === 1) {
            return $types[0];
        }

        return null;
    }

    /**
     * Resolve the entry by `id` or `uid` for `update` / `delete`.
     *
     * Trashed entries are explicitly excluded. Craft's element-query
     * default (`$trashed = false`) does this already; this method
     * does NOT opt back in. The rationale is the trashed-on-`update`
     * footgun: a re-save of a trashed entry would silently un-trash
     * it, which is `restore` mode's job and an unexpected effect of
     * `update`.
     *
     * When the lookup misses, the method probes whether the id/uid
     * matches a trashed row and surfaces a mode-aware error pointing
     * at `restore` (for mutation) or `delete` + `hardDelete=true`
     * (for permanent removal). The probe runs only on the slow
     * (not-found) path, so happy-path calls pay nothing.
     *
     * `apply_draft` does NOT route through here — it loads via
     * `Drafts::getDraftById()` against a draft id, not a canonical
     * entry.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveEntry(array $arguments): EntryElement
    {
        $id = $arguments['id'] ?? null;
        $uid = $arguments['uid'] ?? null;

        $query = EntryElement::find()->status(null)->site('*');

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $query->id((int) $id);
        } elseif (is_string($uid) && $uid !== '') {
            $query->uid($uid);
        } else {
            throw new ToolException("entry: `id` or `uid` is required.");
        }

        $siteId = $this->_resolveOptionalSiteId($arguments);
        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        $element = $query->one();
        if ($element instanceof EntryElement) {
            return $element;
        }

        // Slow-path probe: does this id/uid match a TRASHED entry?
        // If yes, surface a mode-aware hint instead of the generic
        // "no entry found" so the LLM can recover (restore-then-
        // mutate, or hard-delete to remove permanently).
        $trashedProbe = (clone $query)->trashed(true)->one();
        if ($trashedProbe instanceof EntryElement) {
            $probeKey = $trashedProbe->id !== null ? "id={$trashedProbe->id}" : "uid={$trashedProbe->uid}";
            throw new ToolException(
                "entry: {$probeKey} is trashed. To mutate, restore first with " .
                    "mode=restore; to remove permanently, use mode=delete with hardDelete=true."
            );
        }

        $key = (is_int($id) || (is_string($id) && ctype_digit($id))) ? "id={$id}" : "uid={$uid}";
        throw new ToolException("entry: no entry found for {$key}.");
    }

    /**
     * Resolve the target site id from siteId / siteHandle, falling
     * back to the primary site. Used by `create` where a site is
     * always required.
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
     * null when neither is supplied. Used by `update / delete /
     * restore / apply_draft` where the site is optional (Craft picks
     * the default for the entry when no site is set).
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
                throw new ToolException("entry: site id={$siteId} not found.");
            }
            return (int) $site->id;
        }

        $siteHandle = $arguments['siteHandle'] ?? null;
        if (is_string($siteHandle) && $siteHandle !== '') {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if ($site === null) {
                throw new ToolException("entry: site handle=`{$siteHandle}` not found.");
            }
            return (int) $site->id;
        }

        return null;
    }

    /**
     * Apply scalar / date / author / parent / propagation attributes
     * to the element. `setFieldValues()` handles the custom-field
     * surface separately in `_applyFields()`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyAttributes(EntryElement $element, array $arguments, bool $isCreate): void
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

        if (array_key_exists('postDate', $arguments)) {
            $element->postDate = $arguments['postDate'] !== null
                ? (DateTimeHelper::toDateTime($arguments['postDate']) ?: null)
                : null;
        }

        if (array_key_exists('expiryDate', $arguments)) {
            $element->expiryDate = $arguments['expiryDate'] !== null
                ? (DateTimeHelper::toDateTime($arguments['expiryDate']) ?: null)
                : null;
        }

        if (array_key_exists('authorId', $arguments)) {
            $authorId = $arguments['authorId'];
            if (is_int($authorId) || (is_string($authorId) && ctype_digit($authorId))) {
                $element->setAuthorId((int) $authorId);
            }
        } elseif ($isCreate) {
            $current = Craft::$app->getUser()->getIdentity();
            if ($current instanceof User) {
                $element->setAuthorId((int) $current->id);
            }
        }

        if (array_key_exists('parentId', $arguments)) {
            $parentId = $arguments['parentId'];
            if (is_int($parentId) || (is_string($parentId) && ctype_digit($parentId))) {
                $element->setParentId((int) $parentId);
            }
        }
    }

    /**
     * Forward `fields: {handle: value}` to Craft's setFieldValues().
     * The contract is pass-through — Craft normalises per field type
     * at save time. Document this in the tool's PHPDoc.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyFields(EntryElement $element, array $arguments): void
    {
        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return;
        }
        $element->setFieldValues($fields);
    }

    /**
     * Success envelope shape returned by create / update / restore /
     * apply_draft. The serialised entry sits under `entry` so the
     * envelope is forward-compatible — additional metadata (propagation
     * info, idempotency-cache-hit indicator, etc.) can land alongside
     * without breaking existing consumers.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _successEnvelope(EntryElement $element, string $mode): array
    {
        $serializer = new ElementSerializer();
        return [
            'success' => true,
            'mode' => $mode,
            'entry' => $serializer->serializeElement($element),
        ];
    }

    /**
     * Validation envelope shape returned when `saveElement(runValidation: true)`
     * returns false. The LLM iterates on `errors` (keyed by field
     * handle, listing every messages a field gathered) and retries
     * with corrected values. Not cached against the idempotency key
     * — the LLM should be free to retry with different inputs.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _validationEnvelope(EntryElement $element, string $mode): array
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
     * Look up a cached idempotency envelope for the
     * `{userId, idempotencyKey}` pair. Returns the cached value (so
     * the caller can `return` it directly) or `null` when no cache
     * entry exists or when the request didn't carry an
     * idempotencyKey at all.
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
     * Cache the success envelope under the
     * `{userId, idempotencyKey}` pair with the standard 24h TTL.
     * No-op when no idempotencyKey was supplied.
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
     * Build the cache key for an idempotent request, scoping by the
     * current user id so two users with the same key don't collide.
     * Returns null when no idempotencyKey was supplied or when the
     * user isn't resolved (stdio path falls into this case — stdio
     * is single-process and doesn't need server-side dedup).
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
            // stdio path — no per-request identity. Skip caching;
            // stdio is single-process and the LLM-driven retry path
            // is unlikely to fire there.
            return null;
        }

        return self::IDEMPOTENCY_CACHE_PREFIX . $user->id . ':' . $idempotencyKey;
    }
}
