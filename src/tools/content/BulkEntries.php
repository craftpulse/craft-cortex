<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\helpers\DateTimeHelper;
use craft\models\EntryType;
use craft\models\Section;
use craftpulse\herald\attributes\IsDestructive;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\Title;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\IdempotencyTrait;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\ProToolTrait;
use craftpulse\herald\tools\StreamableToolInterface;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use DateTime;
use Generator;
use Throwable;

/**
 * =========================================================================
 * `bulk_entries` Pro tool — query-driven bulk mutation across entries.
 *
 * Herald's first streaming Pro tool. Resolves an `EntryQuery` from a
 * compact filter object, walks results in 100-row batches via
 * `EntryQuery::each(100)`, and applies one of four mutation modes per
 * row. Yields `notifications/progress` frames every `progressInterval`
 * rows (default 100) and returns a terminal envelope with per-row
 * outcomes in a `results[]` array.
 *
 * Modes:
 *   - `set_status` — toggles `$entry->enabled` between `enabled` and
 *     `disabled`. The `live` / `pending` / `expired` states are
 *     computed by Craft from `enabled + postDate + expiryDate` and are
 *     not directly settable here — operators that need those drive the
 *     dates explicitly through `update_fields`.
 *   - `update_fields` — pass-through to `Entry::setFieldValues()` for
 *     each visited row. Per-row validation failures land in `failed`
 *     with the field-keyed `validationErrors` map; the operation
 *     continues across the rest of the result set.
 *   - `relate` — resolves the target field (must be a `BaseRelationField`
 *     descendant), reads current relations from the entry, applies the
 *     merge strategy (`replace` / `add` / `remove`), saves. The
 *     relation-field resolver is memoised across the result set.
 *   - `migrate` — moves the entry to a new `(sectionId, typeId)` pair.
 *     Default is **dry-run** (`dryRun: true`) so the operator confirms
 *     the per-row preview before re-running with `dryRun: false`. In
 *     dry-run mode the per-row permission check still fires; saves are
 *     skipped. The idempotency cache scopes `dryRun: true` and
 *     `dryRun: false` separately under the same key.
 *
 * Hard row cap: 10 000 visited rows per call without `force: true`.
 * Beyond the cap without force, the tool throws `ToolException` naming
 * the cap before any mutation happens. The cap is a safety belt
 * specific to bulk operations — a single user accidentally typing
 * `section: "blog"` against a 50k-entry channel shouldn't melt the
 * server. `force: true` is the documented escape hatch.
 *
 * Permission contract:
 *   - `filterFor()` admits any user with at least one
 *     `saveEntries:{sectionUid}` permission. stdio and admin always
 *     pass.
 *   - `_requiredPermissions()` returns the per-section save permission
 *     when the query resolves to exactly one section UID, otherwise the
 *     wildcard sentinel. Per-row enforcement runs INSIDE the each-loop
 *     (`Elements::canSave($entry, $caller)`) — denied rows go to
 *     `skipped` with `reason: permission_denied` (default) or abort
 *     the operation with a `-32002` ToolException listing every denied
 *     row when `onPermissionDenied: "fail"`.
 *
 * Idempotency contract:
 *   - Cache prefix `herald:bulk_entries:idem:`. Whole-operation
 *     dedup keyed by `{userId, idempotencyKey}`.
 *   - 24h TTL.
 *   - Dry-run results are NOT cached — the operator's intent is to
 *     re-run with `dryRun: false` next. Commit results ARE cached.
 *   - Caveat: stale result if the underlying entry set changed
 *     between calls. LLMs should generate fresh keys per intent.
 *
 * Cancellation: streaming-cooperative. The tool consults
 * `$ctx->getCancellationToken()->isCancelled()` between batches and
 * between rows; on a flipped flag it returns the partial `results[]`
 * with `cancelled: true`. Both the synthetic `notifications/cancelled`
 * path and the real TCP-disconnect path (Gate 7.7.5) flip the same
 * token.
 *
 * No outer transaction: per-row failures persist independently. The
 * `results[]` array is the recovery surface for the caller.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Bulk Entries — query-driven set_status / update_fields / relate / migrate')]
class BulkEntries extends AbstractTool implements StreamableToolInterface
{
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Idempotency cache key prefix. Consumed by `IdempotencyTrait` via
     * `static::IDEMPOTENCY_CACHE_PREFIX`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'herald:bulk_entries:idem:';

    /**
     * Hard ceiling on the number of rows visited per call. Above this,
     * `force: true` is required to proceed. The cap is a safety belt
     * against accidentally-broad queries hitting production-sized
     * channels.
     *
     * @since 5.0.0
     */
    public const ROW_CAP = 10_000;

    /**
     * Default progress-frame interval in rows visited (success + failure
     * + skipped all count).
     *
     * @since 5.0.0
     */
    public const DEFAULT_PROGRESS_INTERVAL = 100;

    /**
     * Default batch size for `EntryQuery::each()` iteration. Matches
     * Yii's `Query::each()` default — see
     * `vendor/yiisoft/yii2/db/Query.php:226-235`. The cursor stays open
     * across yields because PHP generators suspend the entire stack
     * frame, including the open DataReader.
     *
     * @since 5.0.0
     */
    public const ITER_BATCH_SIZE = 100;

    /**
     * Allowed `set_status` target values. The tool only toggles the
     * `enabled` flag directly — Craft 5 derives the rest of the status
     * surface (`live` / `pending` / `expired`) from `enabled + postDate
     * + expiryDate`, and the date columns are editorial intent rather
     * than a status flip. Operators that want a row to be pending or
     * expired must drive the dates explicitly through `update_fields`.
     *
     * @since 5.0.0
     */
    public const STATUSES = ['enabled', 'disabled'];

    /**
     * Allowed `relate` merge strategies.
     *
     * @since 5.0.0
     */
    public const MERGE_STRATEGIES = ['replace', 'add', 'remove'];

    /**
     * Allowed values for `onPermissionDenied`.
     *
     * @since 5.0.0
     */
    public const PERMISSION_MODES = ['skip', 'fail'];

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
        return 'bulk_entries';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Bulk-mutate entries selected by an `EntryQuery`-shaped filter. Modes: ' .
            'set_status (toggle the `enabled` flag — set enabled or disabled; `pending` ' .
            'and `expired` are not status flips, drive postDate / expiryDate via ' .
            'update_fields instead) / update_fields (pass-through `fields: {handle: value}` ' .
            'to setFieldValues) / relate (apply ' .
            'replace / add / remove on a relation field) / migrate (move entries to a ' .
            'new section + entry type — defaults to dryRun:true). Permission: per-row ' .
            '`saveEntries:{sectionUid}`. Denied rows go to `skipped[]` by default; pass ' .
            '`onPermissionDenied: "fail"` to abort. Hard cap 10,000 rows per call; ' .
            '`force: true` to bypass. Streams `notifications/progress` frames every ' .
            '`progressInterval` rows (default 100). Returns a terminal envelope with ' .
            'per-row `results[]` ({kind: success / failure / skipped, id, …}). ' .
            'Cancellation-cooperative: the running stream short-circuits on ' .
            '`notifications/cancelled` or a TCP disconnect. `idempotencyKey` caches the ' .
            'whole-operation envelope for 24h (dry-run and commit are cached separately). ' .
            'Pro edition only.';
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
                ->enum(['set_status', 'update_fields', 'relate', 'migrate'])
                ->required()
                ->description('Mutation mode.'),

            'query' => Schema::object([
                'section' => Schema::string()->description('Section handle (single).'),
                'entryType' => Schema::string()->description('Entry-type handle (single).'),
                'status' => Schema::any()->description('Status filter — string or array of strings (live / pending / expired / disabled).'),
                'search' => Schema::string()->description('Pass-through to ElementQuery::search().'),
                'ids' => Schema::array(Schema::integer())->description('Restrict to these element IDs.'),
                'authorId' => Schema::integer()->description('Author user id.'),
                'dateCreated' => Schema::object([
                    'gte' => Schema::string(),
                    'lte' => Schema::string(),
                ])->description('Range filter — ISO 8601 datetimes.'),
                'dateUpdated' => Schema::object([
                    'gte' => Schema::string(),
                    'lte' => Schema::string(),
                ])->description('Range filter — ISO 8601 datetimes.'),
                'postDate' => Schema::object([
                    'gte' => Schema::string(),
                    'lte' => Schema::string(),
                ])->description('Range filter — ISO 8601 datetimes.'),
                'expiryDate' => Schema::object([
                    'gte' => Schema::string(),
                    'lte' => Schema::string(),
                ])->description('Range filter — ISO 8601 datetimes.'),
            ])
                ->additionalProperties(false)
                ->required()
                ->description('Filter shape — at minimum one selector should be present, otherwise the cap-check rejects the unbounded query.'),

            'siteId' => Schema::any()->description('Site id (int), site handle (string), or `"*"` for all sites. Defaults to primary.'),

            // Mode-specific arguments.
            'status' => Schema::string()
                ->enum(self::STATUSES)
                ->description('set_status only — new status for every visited row.'),
            'fields' => Schema::object()
                ->additionalProperties(true)
                ->description('update_fields only — `{handle: value}` map forwarded to setFieldValues.'),
            'targetField' => Schema::string()
                ->description('relate only — relation field handle.'),
            'targetIds' => Schema::array(Schema::integer())
                ->description('relate only — element IDs to relate to.'),
            'mergeStrategy' => Schema::string()
                ->enum(self::MERGE_STRATEGIES)
                ->description('relate only — relation merge strategy. Defaults to `replace`.'),
            'toSectionUid' => Schema::string()
                ->description('migrate only — target section UID.'),
            'toEntryTypeUid' => Schema::string()
                ->description('migrate only — target entry type UID.'),
            'dryRun' => Schema::boolean()
                ->description('migrate only — preview without saving. Defaults to true.'),

            // Cross-mode operational flags.
            'force' => Schema::boolean()->description('Override the 10,000-row cap. Defaults to false.'),
            'onPermissionDenied' => Schema::string()
                ->enum(self::PERMISSION_MODES)
                ->description('Behaviour on per-row permission denial. Defaults to `skip`.'),
            'progressInterval' => Schema::integer()
                ->minimum(1)
                ->description('Yield a progress frame every N rows. Defaults to 100.'),
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('Whole-operation idempotency token. Same key within 24h returns the cached envelope. Dry-run and commit cached separately.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Whole-tool visibility: any user with at least one
     * `saveEntries:{sectionUid}` passes. stdio and admin always pass.
     * Per-row enforcement happens inside the each-loop.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function filterFor(?User $user = null): bool
    {
        if ($user === null) {
            return true;
        }

        if ($user->admin) {
            return true;
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:{$section->uid}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point. Drives `stream()` to completion,
     * discards progress frames, returns the terminal envelope. Used
     * for the JSON-mode HTTP transport and for the synchronous stdio
     * path where no progress channel exists.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $ctx = new InvocationContext();
        $gen = $this->stream($arguments, $ctx);

        // Drain progress frames — they're for the streaming surface.
        while ($gen->valid()) {
            $gen->next();
        }

        $return = $gen->getReturn();
        return is_array($return) ? $return : [];
    }

    /**
     * @inheritdoc
     *
     * Streaming entry point. Validates arguments, runs the
     * idempotency-cache short-circuit, dispatches to the per-mode
     * generator, yields progress frames, returns the terminal envelope.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $mode = $this->_mode($arguments);
        if ($mode === null) {
            throw new ToolException('bulk_entries: `mode` is required.');
        }
        if (!in_array($mode, ['set_status', 'update_fields', 'relate', 'migrate'], true)) {
            throw new ToolException(
                "bulk_entries: unknown mode `{$mode}`. Allowed: set_status / update_fields / relate / migrate.",
            );
        }

        // Idempotency short-circuit. Hit returns the cached envelope
        // without re-running. Dry-run and commit are cached separately
        // — the cache key embeds the dry-run state via a salt.
        $cacheHit = $this->_idempotencyCacheHit($this->_idempotencyArguments($arguments, $mode));
        if ($cacheHit !== null) {
            // Yield one synthetic terminal-shaped progress frame so the
            // stream isn't empty (clients SHOULD tolerate zero progress
            // frames, but emitting one keeps the wire-level contract
            // monotonically progress-aware). Then return the cached
            // envelope as the terminal payload.
            yield ['progress' => 1, 'total' => 1, 'message' => 'Idempotency cache hit — returning cached envelope.'];
            return $cacheHit;
        }

        return yield from $this->_dispatch($mode, $arguments, $ctx);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $query = is_array($arguments['query'] ?? null) ? $arguments['query'] : [];
        $sectionUid = $this->_resolveSectionUidFromQuery($query);
        if ($sectionUid === null) {
            return ['saveEntries:*'];
        }
        return ["saveEntries:{$sectionUid}"];
    }

    /**
     * Coarse permission gate for `bulk_entries`. Differs from the
     * standard `PermissionedToolTrait::_assertPermission()` in one
     * important way: a wildcard sentinel (`saveEntries:*`) is NOT a
     * programmer error here — it's the legitimate outcome of a
     * query-by-ids call where no single section UID resolves from the
     * args alone. The per-row `canSave()` check inside the each-loop is
     * the authoritative gate for those cases; the coarse gate is just
     * an early-bounce for query-by-section calls where the LLM
     * obviously can't even afford the per-section permission.
     *
     * stdio and admin skip per the same rules as the trait.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertCoarsePermission(array $arguments): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return;
        }
        if ($user->admin) {
            return;
        }

        $permissions = $this->_requiredPermissions($arguments);
        foreach ($permissions as $permission) {
            if (str_ends_with($permission, ':*')) {
                // Per-row enforcement is the real gate when we couldn't
                // resolve a single section UID from the query.
                continue;
            }
            if (!$user->can($permission)) {
                throw new ToolException($this->_buildPermissionDeniedMessage($permission, $arguments));
            }
        }
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $mode = $this->_mode($arguments) ?? '?';
        return sprintf(
            'permission denied: mode `%s` requires `%s`.',
            $mode,
            $missingPermission,
        );
    }

    // Private Methods — mode dispatch
    // =========================================================================

    /**
     * Dispatch to the per-mode generator. Wraps the dispatch in the
     * idempotency cache write on terminal success.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _dispatch(string $mode, array $arguments, InvocationContext $ctx): Generator
    {
        $envelope = yield from match ($mode) {
            'set_status' => $this->_setStatus($arguments, $ctx),
            'update_fields' => $this->_updateFields($arguments, $ctx),
            'relate' => $this->_relate($arguments, $ctx),
            'migrate' => $this->_migrate($arguments, $ctx),
            default => throw new ToolException("bulk_entries: unsupported mode `{$mode}`."),
        };

        // Cache rule: don't cache cancelled, top-level-errored, or
        // dry-run envelopes. Commit-mode success envelopes are cached.
        $isCancelled = ($envelope['cancelled'] ?? false) === true;
        $hasTopLevelErrors = isset($envelope['errors']);
        $isDryRun = ($envelope['dryRun'] ?? false) === true;
        if (!$isCancelled && !$hasTopLevelErrors && !$isDryRun) {
            $this->_cacheIdempotencyEnvelope($this->_idempotencyArguments($arguments, $mode), $envelope);
        }

        return $envelope;
    }

    /**
     * set_status mode. Walks the resolved query, toggles `enabled`
     * (or applies the pending/expired date heuristic), saves per row.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _setStatus(array $arguments, InvocationContext $ctx): Generator
    {
        $status = $arguments['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            return $this->_terminalErrorEnvelope(
                'set_status',
                ['status' => 'set_status requires `status` to be one of ' . implode(' / ', self::STATUSES) . '.'],
                $arguments,
            );
        }

        $this->_assertCoarsePermission($arguments);

        $query = $this->_buildQuery($arguments);
        $total = $this->_preflightCount($query);
        $this->_assertCap($total, $arguments);

        return yield from $this->_runLoop(
            mode: 'set_status',
            query: $query,
            total: $total,
            arguments: $arguments,
            ctx: $ctx,
            applyFn: function(EntryElement $entry) use ($status): array {
                $this->_applyStatus($entry, $status);
                if (!Craft::$app->getElements()->saveElement($entry, runValidation: true)) {
                    return $this->_failureOutcome($entry, 'validation failed');
                }
                return ['kind' => 'success', 'id' => (int) $entry->id, 'newStatus' => $status];
            },
        );
    }

    /**
     * update_fields mode. Walks the resolved query, sets field values,
     * saves per row.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _updateFields(array $arguments, InvocationContext $ctx): Generator
    {
        $fields = $arguments['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return $this->_terminalErrorEnvelope(
                'update_fields',
                ['fields' => 'update_fields requires a non-empty `fields: {handle: value}` map.'],
                $arguments,
            );
        }

        $this->_assertCoarsePermission($arguments);

        $query = $this->_buildQuery($arguments);
        $total = $this->_preflightCount($query);
        $this->_assertCap($total, $arguments);

        $handles = array_keys($fields);

        return yield from $this->_runLoop(
            mode: 'update_fields',
            query: $query,
            total: $total,
            arguments: $arguments,
            ctx: $ctx,
            applyFn: function(EntryElement $entry) use ($fields, $handles): array {
                $entry->setFieldValues($fields);
                if (!Craft::$app->getElements()->saveElement($entry, runValidation: true)) {
                    return $this->_failureOutcome($entry, 'validation failed');
                }
                return ['kind' => 'success', 'id' => (int) $entry->id, 'fieldsUpdated' => array_values($handles)];
            },
        );
    }

    /**
     * relate mode. Resolves the target field once, validates it's a
     * relation field, walks the query applying the merge strategy
     * per row.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _relate(array $arguments, InvocationContext $ctx): Generator
    {
        $targetField = $arguments['targetField'] ?? null;
        $targetIds = $arguments['targetIds'] ?? null;
        $mergeStrategy = $arguments['mergeStrategy'] ?? 'replace';

        if (!is_string($targetField) || $targetField === '') {
            return $this->_terminalErrorEnvelope(
                'relate',
                ['targetField' => 'relate requires `targetField` to be a non-empty string (field handle).'],
                $arguments,
            );
        }
        if (!is_array($targetIds)) {
            return $this->_terminalErrorEnvelope(
                'relate',
                ['targetIds' => 'relate requires `targetIds` to be an array of element ids.'],
                $arguments,
            );
        }
        if (!in_array($mergeStrategy, self::MERGE_STRATEGIES, true)) {
            return $this->_terminalErrorEnvelope(
                'relate',
                ['mergeStrategy' => 'relate requires `mergeStrategy` to be one of ' . implode(' / ', self::MERGE_STRATEGIES) . '.'],
                $arguments,
            );
        }

        $field = Craft::$app->getFields()->getFieldByHandle($targetField);
        if ($field === null) {
            return $this->_terminalErrorEnvelope(
                'relate',
                ['targetField' => "relate: field handle `{$targetField}` not found."],
                $arguments,
            );
        }
        if (!$field instanceof BaseRelationField) {
            return $this->_terminalErrorEnvelope(
                'relate',
                ['targetField' => "relate: field `{$targetField}` is not a relation field."],
                $arguments,
            );
        }

        $this->_assertCoarsePermission($arguments);

        $query = $this->_buildQuery($arguments);
        $total = $this->_preflightCount($query);
        $this->_assertCap($total, $arguments);

        $normalisedTargets = array_values(array_unique(array_map(
            static fn(mixed $v): int => (int) $v,
            $targetIds,
        )));
        // `$field->handle` mirrors the validated `$targetField` argument
        // — restate as a string-typed local so the per-row closure
        // captures a non-nullable string for `setFieldValue()`.
        $resolvedHandle = (string) $field->handle;

        return yield from $this->_runLoop(
            mode: 'relate',
            query: $query,
            total: $total,
            arguments: $arguments,
            ctx: $ctx,
            applyFn: function(EntryElement $entry) use ($resolvedHandle, $normalisedTargets, $mergeStrategy): array {
                $current = $this->_currentRelationIds($entry, $resolvedHandle);
                $next = match ($mergeStrategy) {
                    'add' => array_values(array_unique(array_merge($current, $normalisedTargets))),
                    'remove' => array_values(array_diff($current, $normalisedTargets)),
                    default => $normalisedTargets, // 'replace'
                };
                $entry->setFieldValue($resolvedHandle, $next);
                if (!Craft::$app->getElements()->saveElement($entry, runValidation: true)) {
                    return $this->_failureOutcome($entry, 'validation failed');
                }
                $delta = count($next) - count($current);
                return [
                    'kind' => 'success',
                    'id' => (int) $entry->id,
                    'relationsAdded' => max(0, $delta),
                ];
            },
        );
    }

    /**
     * migrate mode. Moves entries to a new (section, entryType) pair.
     * Defaults to dry-run; explicit `dryRun: false` commits.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _migrate(array $arguments, InvocationContext $ctx): Generator
    {
        $toSectionUid = $arguments['toSectionUid'] ?? null;
        $toEntryTypeUid = $arguments['toEntryTypeUid'] ?? null;

        if (!is_string($toSectionUid) || $toSectionUid === '') {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toSectionUid' => 'migrate requires `toSectionUid` (target section UID).'],
                $arguments,
            );
        }
        if (!is_string($toEntryTypeUid) || $toEntryTypeUid === '') {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toEntryTypeUid' => 'migrate requires `toEntryTypeUid` (target entry type UID).'],
                $arguments,
            );
        }

        $entriesService = Craft::$app->getEntries();
        $targetSection = $entriesService->getSectionByUid($toSectionUid);
        if ($targetSection === null) {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toSectionUid' => "migrate: target section uid=`{$toSectionUid}` not found."],
                $arguments,
            );
        }
        $targetEntryType = $entriesService->getEntryTypeByUid($toEntryTypeUid);
        if ($targetEntryType === null) {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toEntryTypeUid' => "migrate: target entry type uid=`{$toEntryTypeUid}` not found."],
                $arguments,
            );
        }
        if (!$this->_sectionAllowsEntryType($targetSection, $targetEntryType)) {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toEntryTypeUid' => sprintf(
                    'migrate: entry type `%s` is not assigned to section `%s`.',
                    $targetEntryType->handle,
                    $targetSection->handle,
                )],
                $arguments,
            );
        }

        // Permission re-check needs save permission on the SOURCE
        // section (the coarse gate covers it when the query resolves a
        // section handle; per-row canSave() covers it otherwise) AND
        // the TARGET section. Surface the target check explicitly so
        // moves into restricted sections fail fast.
        $this->_assertCoarsePermission($arguments);
        $this->_assertTargetPermission($targetSection);

        $dryRun = $this->_isDryRun($arguments);

        $query = $this->_buildQuery($arguments);
        $total = $this->_preflightCount($query);
        $this->_assertCap($total, $arguments);

        if ($targetEntryType->id === null) {
            return $this->_terminalErrorEnvelope(
                'migrate',
                ['toEntryTypeUid' => 'migrate: resolved target entry type has no id (unsaved?).'],
                $arguments,
            );
        }

        $targetSectionId = (int) $targetSection->id;
        $targetTypeId = (int) $targetEntryType->id;
        $targetEntryTypeUid = $targetEntryType->uid;

        return yield from $this->_runLoop(
            mode: 'migrate',
            query: $query,
            total: $total,
            arguments: $arguments,
            ctx: $ctx,
            applyFn: function(EntryElement $entry) use ($targetSectionId, $targetTypeId, $targetEntryTypeUid, $dryRun): array {
                if ($dryRun) {
                    return [
                        'kind' => 'success',
                        'id' => (int) $entry->id,
                        'newEntryTypeUid' => $targetEntryTypeUid,
                        'dryRun' => true,
                    ];
                }
                $entry->sectionId = $targetSectionId;
                $entry->typeId = $targetTypeId;
                if (!Craft::$app->getElements()->saveElement($entry, runValidation: true)) {
                    return $this->_failureOutcome($entry, 'validation failed');
                }
                return [
                    'kind' => 'success',
                    'id' => (int) $entry->id,
                    'newEntryTypeUid' => $targetEntryTypeUid,
                ];
            },
            extras: ['dryRun' => $dryRun],
        );
    }

    // Private Methods — main loop
    // =========================================================================

    /**
     * Walk the resolved query, applying `$applyFn` per row, yielding a
     * progress frame every `progressInterval` rows. Returns the terminal
     * envelope shape.
     *
     * @param callable(EntryElement): array<string,mixed> $applyFn
     * @param array<string,mixed>                          $arguments
     * @param array<string,mixed>                          $extras   Additional top-level fields (e.g. `dryRun`).
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _runLoop(
        string $mode,
        EntryQuery $query,
        int $total,
        array $arguments,
        InvocationContext $ctx,
        callable $applyFn,
        array $extras = [],
    ): Generator {
        $progressInterval = $this->_progressInterval($arguments);
        $onPermissionDenied = $this->_onPermissionDenied($arguments);
        $caller = Craft::$app->getUser()->getIdentity();
        $elements = Craft::$app->getElements();
        $token = $ctx->getCancellationToken();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];
        $deniedRows = [];
        $cancelled = false;

        if ($total === 0) {
            // Empty pre-flight — yield one frame so the wire surface is
            // non-empty, return the zero-row envelope.
            yield ['progress' => 0, 'total' => 0, 'message' => 'No rows matched.'];
            return $this->_terminalEnvelope($mode, $total, $processed, $succeeded, $failed, $skipped, $cancelled, $results, $extras);
        }

        // Iterate via each() — DataReader cursor stays open across
        // yields because the generator suspends the whole frame.
        foreach ($query->each(self::ITER_BATCH_SIZE) as $entry) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            if (!$entry instanceof EntryElement) {
                continue;
            }

            $entryId = (int) $entry->id;

            // Per-row permission check via Craft's native canSave().
            // Caller is the resolved User on HTTP; stdio path's `null`
            // identity is treated as trusted (same skip rule as the
            // PermissionedToolTrait).
            $allowed = $caller === null || $elements->canSave($entry, $caller);
            if (!$allowed) {
                if ($onPermissionDenied === 'fail') {
                    $deniedRows[] = $entryId;
                    throw new ToolException(sprintf(
                        'bulk_entries: permission denied on row id=%d (onPermissionDenied=fail). ' .
                            'Denied rows so far: %s.',
                        $entryId,
                        json_encode($deniedRows),
                    ));
                }
                $results[] = [
                    'kind' => 'skipped',
                    'id' => $entryId,
                    'reason' => 'permission_denied',
                ];
                $skipped++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield $this->_progressFrame($processed, $total, "Skipped entry {$entryId} (no permission)");
                }
                continue;
            }

            // Defensive: if the entry resolved to trashed, surface it.
            // Craft's `each()` typically excludes trashed by default,
            // but `status(null)` widens the lens.
            if ($entry->trashed) {
                $results[] = [
                    'kind' => 'skipped',
                    'id' => $entryId,
                    'reason' => 'trashed',
                ];
                $skipped++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield $this->_progressFrame($processed, $total, "Skipped entry {$entryId} (trashed)");
                }
                continue;
            }

            try {
                $outcome = $applyFn($entry);
            } catch (Throwable $e) {
                $outcome = [
                    'kind' => 'failure',
                    'id' => $entryId,
                    'reason' => $e->getMessage(),
                ];
            }

            $kind = $outcome['kind'] ?? 'failure';
            if ($kind === 'success') {
                $succeeded++;
            } elseif ($kind === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
            $results[] = $outcome;
            $processed++;

            if ($processed % $progressInterval === 0) {
                yield $this->_progressFrame($processed, $total, "Processing entry {$entryId}");
            }
        }

        return $this->_terminalEnvelope($mode, $total, $processed, $succeeded, $failed, $skipped, $cancelled, $results, $extras);
    }

    // Private Methods — query resolution
    // =========================================================================

    /**
     * Build the `EntryQuery` from the validated query object. Applies
     * `section`, `entryType`, `status`, `search`, `ids`, `authorId`,
     * and the four date-range filters. Applies `site()` (or `site('*')`)
     * and `status(null)` so the iteration crosses every status.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildQuery(array $arguments): EntryQuery
    {
        $rawQuery = $arguments['query'] ?? null;
        if (!is_array($rawQuery)) {
            throw new ToolException('bulk_entries: `query` is required and must be an object.');
        }

        $query = EntryElement::find();
        $entriesService = Craft::$app->getEntries();

        $section = $rawQuery['section'] ?? null;
        if (is_string($section) && $section !== '') {
            $resolved = $entriesService->getSectionByHandle($section);
            if ($resolved === null) {
                throw new ToolException("bulk_entries: section handle=`{$section}` not found.");
            }
            $query->sectionId($resolved->id);
        }

        $entryType = $rawQuery['entryType'] ?? null;
        if (is_string($entryType) && $entryType !== '') {
            $resolvedType = $entriesService->getEntryTypeByHandle($entryType);
            if ($resolvedType === null) {
                throw new ToolException("bulk_entries: entry type handle=`{$entryType}` not found.");
            }
            $query->typeId($resolvedType->id);
        }

        if (array_key_exists('status', $rawQuery)) {
            $query->status($rawQuery['status']);
        } else {
            // Default: every status — bulk-write doesn't presume the
            // operator only meant `live` rows.
            $query->status(null);
        }

        if (isset($rawQuery['search']) && is_string($rawQuery['search']) && $rawQuery['search'] !== '') {
            $query->search($rawQuery['search']);
        }

        if (isset($rawQuery['ids']) && is_array($rawQuery['ids']) && $rawQuery['ids'] !== []) {
            $query->id(array_map(static fn(mixed $v): int => (int) $v, $rawQuery['ids']));
        }

        if (isset($rawQuery['authorId']) && (is_int($rawQuery['authorId']) || (is_string($rawQuery['authorId']) && ctype_digit($rawQuery['authorId'])))) {
            $query->authorId((int) $rawQuery['authorId']);
        }

        foreach (['dateCreated', 'dateUpdated', 'postDate', 'expiryDate'] as $dateParam) {
            $value = $rawQuery[$dateParam] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $parsed = $this->_parseDateRange($value);
            if ($parsed !== null) {
                $query->{$dateParam}($parsed);
            }
        }

        // Site context. Accept int, handle, or '*'.
        $siteArg = $arguments['siteId'] ?? null;
        if ($siteArg === '*') {
            $query->site('*');
        } elseif (is_int($siteArg) || (is_string($siteArg) && ctype_digit($siteArg))) {
            $query->siteId((int) $siteArg);
        } elseif (is_string($siteArg) && $siteArg !== '') {
            $query->site($siteArg);
        } else {
            $query->siteId((int) Craft::$app->getSites()->getPrimarySite()->id);
        }

        return $query;
    }

    /**
     * Parse `{gte, lte}` into Craft's `Db::parseDateParam`-shaped array
     * value. Returns null when neither bound is parseable so the caller
     * can short-circuit.
     *
     * @param array<string,mixed> $range
     * @return array<int,string>|null
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _parseDateRange(array $range): ?array
    {
        $bounds = [];
        if (isset($range['gte']) && is_string($range['gte']) && $range['gte'] !== '') {
            $dt = DateTimeHelper::toDateTime($range['gte']);
            if ($dt instanceof DateTime) {
                $bounds[] = '>=' . $dt->format('Y-m-d H:i:s');
            }
        }
        if (isset($range['lte']) && is_string($range['lte']) && $range['lte'] !== '') {
            $dt = DateTimeHelper::toDateTime($range['lte']);
            if ($dt instanceof DateTime) {
                $bounds[] = '<=' . $dt->format('Y-m-d H:i:s');
            }
        }
        if ($bounds === []) {
            return null;
        }
        // `and` joiner so both bounds combine into a range constraint.
        array_unshift($bounds, 'and');
        return $bounds;
    }

    /**
     * Pre-flight `COUNT(*)` on the assembled query. Cost is one
     * aggregate query; no element hydration. Result feeds every
     * progress frame's `total` field.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _preflightCount(EntryQuery $query): int
    {
        return (int) $query->count();
    }

    /**
     * Resolve the section UID from the query argument shape, returning
     * null when no section handle was supplied or it doesn't resolve.
     * Used by `_requiredPermissions()` to drive whole-tool visibility
     * coarse-gating.
     *
     * @param array<string,mixed> $rawQuery
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _resolveSectionUidFromQuery(array $rawQuery): ?string
    {
        $handle = $rawQuery['section'] ?? null;
        if (!is_string($handle) || $handle === '') {
            return null;
        }
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);
        return $section?->uid;
    }

    /**
     * Enforce the 10 000-row cap unless `force: true`. Throws
     * `ToolException` naming the cap so the LLM can re-narrow the
     * filter or re-issue with `force: true`.
     *
     * @param array<string,mixed> $arguments
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertCap(int $total, array $arguments): void
    {
        if ($total <= self::ROW_CAP) {
            return;
        }
        if (($arguments['force'] ?? false) === true) {
            return;
        }
        throw new ToolException(sprintf(
            'bulk_entries: query matches %d rows which exceeds the %d-row cap. ' .
                'Narrow the filter or pass `force: true` to override.',
            $total,
            self::ROW_CAP,
        ));
    }

    // Private Methods — per-mode helpers
    // =========================================================================

    /**
     * Apply a `set_status` target value to an entry. The tool only
     * flips the `enabled` column directly; `live` / `pending` /
     * `expired` are computed states (`enabled + postDate + expiryDate`)
     * and operators that want those must drive the dates explicitly
     * via `update_fields`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyStatus(EntryElement $entry, string $status): void
    {
        match ($status) {
            'enabled' => $entry->enabled = true,
            'disabled' => $entry->enabled = false,
            default => null,
        };
    }

    /**
     * Read the current set of related element ids on the given relation
     * field. Returns an empty list when the field hasn't been populated
     * yet on this entry.
     *
     * @return list<int>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _currentRelationIds(EntryElement $entry, string $fieldHandle): array
    {
        $value = $entry->getFieldValue($fieldHandle);
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $ids = $value->ids();
        } elseif (is_array($value)) {
            $ids = array_map(
                static fn(mixed $v): int => match (true) {
                    $v instanceof ElementInterface => (int) $v->id,
                    is_int($v) || (is_string($v) && ctype_digit($v)) => (int) $v,
                    default => 0,
                },
                $value,
            );
        } else {
            $ids = [];
        }
        return array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
    }

    /**
     * Whether the given section is assigned the given entry type.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _sectionAllowsEntryType(Section $section, EntryType $entryType): bool
    {
        foreach ($section->getEntryTypes() as $candidate) {
            if ($candidate->id === $entryType->id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Re-check `saveEntries:{targetSectionUid}` for `migrate` mode.
     * Skips on stdio and admin per the trait's contract.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _assertTargetPermission(Section $section): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null) {
            return;
        }
        if ($user->admin) {
            return;
        }
        $permission = "saveEntries:{$section->uid}";
        if (!$user->can($permission)) {
            throw new ToolException(sprintf(
                'bulk_entries: migrate requires `%s` on the target section `%s`.',
                $permission,
                $section->handle,
            ));
        }
    }

    /**
     * Whether `migrate` mode should run in dry-run. Defaults to true
     * when the caller omits the argument.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _isDryRun(array $arguments): bool
    {
        if (!array_key_exists('dryRun', $arguments)) {
            return true;
        }
        return (bool) $arguments['dryRun'];
    }

    // Private Methods — envelope helpers
    // =========================================================================

    /**
     * Build the terminal envelope. Used by every mode's exit path
     * (including the empty-result short-circuit and the cancellation
     * exit). Adds `extras` for mode-specific top-level fields like
     * `dryRun`.
     *
     * @param array<int,array<string,mixed>> $results
     * @param array<string,mixed>            $extras
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _terminalEnvelope(
        string $mode,
        int $total,
        int $processed,
        int $succeeded,
        int $failed,
        int $skipped,
        bool $cancelled,
        array $results,
        array $extras = [],
    ): array {
        $envelope = [
            'success' => $failed === 0 && !$cancelled,
            'mode' => $mode,
            'total' => $total,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'cancelled' => $cancelled,
            'results' => $results,
        ];
        if (array_key_exists('dryRun', $extras)) {
            $envelope['dryRun'] = (bool) $extras['dryRun'];
            // `committed` mirrors `succeeded` in commit mode and is
            // pinned to 0 in dry-run to make the operator's intent
            // post-call unambiguous.
            $envelope['committed'] = $envelope['dryRun'] ? 0 : $succeeded;
        }
        return $envelope;
    }

    /**
     * Build a terminal envelope for the top-level validation-error
     * path (missing required arguments, target section mismatch, etc.).
     * Does NOT count towards the success path — `errors` is the
     * recovery surface.
     *
     * @param array<string,string> $errors
     * @param array<string,mixed>  $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _terminalErrorEnvelope(string $mode, array $errors, array $arguments): array
    {
        $envelope = [
            'success' => false,
            'mode' => $mode,
            'total' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'cancelled' => false,
            'results' => [],
            'errors' => $errors,
        ];
        if ($mode === 'migrate') {
            $envelope['dryRun'] = $this->_isDryRun($arguments);
            $envelope['committed'] = 0;
        }
        return $envelope;
    }

    /**
     * Per-row failure outcome envelope. Pulls validation errors off
     * the element so the LLM can iterate on field values.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _failureOutcome(EntryElement $entry, string $reason): array
    {
        return [
            'kind' => 'failure',
            'id' => (int) $entry->id,
            'reason' => $reason,
            'validationErrors' => $entry->getErrors(),
        ];
    }

    /**
     * Build a `notifications/progress` frame's payload shape. The
     * dispatcher wraps it in the JSON-RPC envelope upstream — here we
     * only emit the inner `{progress, total, message}` triple.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _progressFrame(int $progress, int $total, string $message): array
    {
        return [
            'progress' => $progress,
            'total' => $total,
            'message' => $message,
        ];
    }

    // Private Methods — argument readers
    // =========================================================================

    /**
     * Read the `progressInterval` argument, clamped to a sensible lower
     * bound (≥1).
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _progressInterval(array $arguments): int
    {
        $value = $arguments['progressInterval'] ?? self::DEFAULT_PROGRESS_INTERVAL;
        return max(1, (int) $value);
    }

    /**
     * Read the `onPermissionDenied` argument, defaulting to `skip`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _onPermissionDenied(array $arguments): string
    {
        $value = $arguments['onPermissionDenied'] ?? 'skip';
        return in_array($value, self::PERMISSION_MODES, true) ? (string) $value : 'skip';
    }

    /**
     * Build the cache-key salt for the idempotency cache. Dry-run and
     * commit are cached separately under the same logical key so the
     * operator's preview-then-commit workflow doesn't collide.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _idempotencyArguments(array $arguments, string $mode): array
    {
        $key = $arguments['idempotencyKey'] ?? null;
        if (!is_string($key) || $key === '') {
            return $arguments;
        }
        $salt = $mode;
        if ($mode === 'migrate') {
            $salt .= ':' . ($this->_isDryRun($arguments) ? 'dry' : 'commit');
        }
        // Re-suffix the key so the trait builds a per-mode (and per-
        // dry-run state) cache slot.
        return ['idempotencyKey' => $key . ':' . $salt] + $arguments;
    }
}
