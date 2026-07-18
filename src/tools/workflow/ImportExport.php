<?php

namespace craftpulse\herald\tools\workflow;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\DualModeToolInterface;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\StreamableToolInterface;
use craftpulse\herald\tools\support\InvocationContext;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use DateTimeImmutable;
use Generator;
use Throwable;

/**
 * =========================================================================
 * `import_export` tool — structured-JSON export (Free) and round-trip
 * import (Pro).
 *
 * The Free tier ships export only. The Pro tier adds an `import` mode
 * for cross-environment content sync, gated behind
 * `saveEntries:{section}` permissions and dry-run-by-default. The Pro
 * unlock follows the Gate 8.8 mode-unlock composition contract (locked
 * decision 6 of `docs/plans/gate-8.md`): the tool stays
 * Free-registered, the mode enum is edition-aware, HTTP callers see
 * `import` only when they hold `saveEntries:{section}` on at least
 * one section, and `execute()` re-checks edition + permission for
 * defense in depth.
 *
 * Output shape (format 2 — see `FORMAT_VERSION` const for version
 * lifecycle):
 *
 * ```
 * {
 *   "format": 2,
 *   "mode": "export",
 *   "exportedAt": "2026-05-07T15:00:00+00:00",
 *   "craftVersion": "5.x.x",
 *   "craftEdition": "Pro",
 *   "schemaVersion": "...",
 *   "count": 12,
 *   "totalCount": 87,
 *   "limit": 100,
 *   "offset": 0,
 *   "entries": [
 *     {
 *       "uid": "...",
 *       "id": 42,
 *       "title": "...",
 *       "slug": "...",
 *       "section": "blog",
 *       "type": "post",
 *       "site": "default",
 *       "enabled": true,
 *       "enabledForSite": true,
 *       "authorId": 5,
 *       "authorIds": ["uid-of-author"],
 *       "parentUid": null,
 *       "level": 1,
 *       "postDate": "2026-...",
 *       "expiryDate": null,
 *       "dateCreated": "2026-...",
 *       "dateUpdated": "2026-...",
 *       "fields": { ... }
 *     },
 *     ...
 *   ]
 * }
 * ```
 *
 * Field values use Craft's `getSerializedFieldValues()` so the output
 * round-trips into Craft's own serialisation contract — relational
 * fields appear as id arrays, Matrix as block-structure arrays, etc.
 * The Pro importer will consume this exact shape, so what's exported
 * here is what gets imported there.
 *
 * Filters:
 *   - `section` — restrict to one section (handle)
 *   - `id` — single entry id or array of ids (overrides `section`)
 *   - `site` — site handle (defaults to primary)
 *
 * The envelope includes Craft fingerprints (`craftVersion`,
 * `craftEdition`, `schemaVersion`) so the importer can refuse a
 * cross-major or cross-edition payload before walking the entries.
 * Future schema bumps stay backwards-compatible via the `format`
 * version field; format 1 existed only briefly pre-release and is
 * not expected in the wild.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class ImportExport extends AbstractTool implements StreamableToolInterface, DualModeToolInterface
{
    use PermissionedToolTrait;

    // Constants
    // =========================================================================

    /**
     * Envelope format version. Format 2 carries the full round-trip
     * surface (authorIds for multi-author entries, parentUid + level
     * for Structure hierarchies, enabledForSite for the per-site
     * enabled bit, plus craftVersion / schemaVersion / edition
     * fingerprints on the envelope). Format 1 existed only briefly
     * pre-release — no published consumers, no back-compat layer.
     */
    public const FORMAT_VERSION = 2;
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 1000;

    /**
     * Default per-frame row interval for the streaming `import` mode.
     * Mirrors `BulkEntries::DEFAULT_PROGRESS_INTERVAL` — payloads are
     * typically dozens-to-hundreds of items, and 100-row granularity
     * keeps wire overhead bounded without forfeiting per-batch
     * visibility. The `progressInterval` argument overrides per-call.
     *
     * @since 5.0.0
     */
    public const DEFAULT_PROGRESS_INTERVAL = 100;

    /**
     * @var list<string>
     */
    private const FREE_MODES = ['export'];

    /**
     * @var list<string>
     */
    private const PRO_MODES = ['import'];

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
        return 'import_export';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Export entries to a structured JSON envelope suitable for cross-environment ' .
            'sync. Free mode: `export` returns the envelope (filter by `section`, `id`, `site`). ' .
            'Pro mode: `import` consumes the same envelope, validates each entry against the target ' .
            'section\'s field layout, and saves create/update by uid. `import` defaults to ' .
            '`dryRun: true` — pass `dryRun: false` to commit. Per-item permission ' .
            '`saveEntries:{section}`.';
    }

    /**
     * @inheritdoc
     *
     * Derived from `FREE_MODES` / `PRO_MODES` so there is exactly one
     * place per tool where a mode is read vs write. Consulted by
     * `services\Audit::handleToolInvocation()` for per-invocation
     * `herald.tool.write_invoked` classification — the class-level
     * `#[IsReadOnly]` MCP hint above is accurate for the Free tier but
     * cannot express the Pro `import` mode, so the audit emitter asks
     * this method instead of the attribute for tools that implement
     * `DualModeToolInterface`.
     *
     * @return array<string,bool>
     *
     * @author Craftpulse
     * @since  5.1.0
     */
    public static function getModeWriteMap(): array
    {
        return array_merge(
            array_fill_keys(self::FREE_MODES, false),
            array_fill_keys(self::PRO_MODES, true),
        );
    }

    /**
     * @inheritdoc
     *
     * Base schema — exposes the Free + Pro enum on Pro installs, the
     * Free-only enum on Free installs. The edition gate runs here so
     * the static surface (`tools/list`, `inputSchemaFor(null)`, and
     * the architecture invariant at
     * `tests/Mcp/ToolInterfaceInvariantTest.php`) stay aligned with the
     * runtime gate in `execute()`. HTTP callers on Pro are further
     * filtered down per Craft permissions in `inputSchemaFor($user)`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        $modes = Herald::getInstance()->is(Herald::EDITION_PRO, '>=')
            ? array_merge(self::FREE_MODES, self::PRO_MODES)
            : self::FREE_MODES;

        return Schema::object([
            'mode' => Schema::string()
                ->enum($modes)
                ->required()
                ->description('Required.'),
            'section' => Schema::string()->description('Section handle filter (for `export`).'),
            'id' => Schema::any()->description('Single entry id or array of ids. Overrides section filter (for `export`).'),
            'site' => Schema::string()->description('Site handle. Defaults to primary site (for `export`).'),
            'siteHandle' => Schema::string()->description('Site handle override for `import` — picks the per-entry site context if the payload\'s `site` is unknown to this install.'),
            'payload' => Schema::object()
                ->additionalProperties(true)
                ->description('Required for `import`. The envelope shape returned by `export`: `{format, entries: [...]}`.'),
            'dryRun' => Schema::boolean()
                ->description('Defaults to `true` for `import`. Pass `false` to actually write. Validates against the target section\'s field layout either way.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
            'offset' => Schema::integer()->minimum(0),
            'progressInterval' => Schema::integer()
                ->minimum(1)
                ->description('Streaming-only. Emit one `notifications/progress` frame every N items processed (default 100). Ignored on non-streaming dispatch.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. stdio (`null` user) gets the
     * edition-appropriate static schema verbatim. For HTTP callers on
     * Pro, the `import` mode is exposed only when the caller holds
     * `saveEntries:{section}` on at least one section. An HTTP caller
     * on a Free-edition install never sees `import`. `execute()` still
     * re-validates the resolved mode for security AND blocks Pro modes
     * on Free installs.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        $schema = static::getInputSchema();

        if ($user === null || !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            return $schema;
        }

        if ($user->admin) {
            return $schema;
        }

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($user->can("saveEntries:{$section->uid}")) {
                return $schema;
            }
        }

        $schema['properties']['mode']['enum'] = self::FREE_MODES;
        return $schema;
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point. `export` is a single-shot read — it
     * dispatches directly to the paged-array helper. `import` collapses
     * `stream()` to its terminal `getReturn()` value, mirroring the
     * `BulkEntries::execute()` precedent at
     * `src/tools/content/BulkEntries.php:339-351`. Both transports
     * surface the same terminal envelope.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $mode = $arguments['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            throw new ToolException('`mode` is required (export / import).');
        }

        if (in_array($mode, self::PRO_MODES, true) && !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ToolException("import_export: mode `{$mode}` is unavailable on this edition.");
        }

        if ($mode === 'import') {
            $ctx = new InvocationContext();
            $gen = $this->stream($arguments, $ctx);

            // Drain progress frames — they're for the streaming surface.
            while ($gen->valid()) {
                $gen->next();
            }

            $return = $gen->getReturn();
            return is_array($return) ? $return : [];
        }

        return match ($mode) {
            'export' => $this->_export($arguments),
            default => throw new ToolException("Unknown mode: '{$mode}'."),
        };
    }

    /**
     * @inheritdoc
     *
     * Streaming entry point. Only `import` is streamable — `export`
     * returns a single materialised envelope in one shot via `execute()`.
     *
     * Per `docs/plans/gate-8.9.md` locked decision 1: yields one
     * `{progress, total, message}` frame every `progressInterval` items
     * processed; `message` is `"Importing item index={index} uid={uid}"`.
     * Cancellation polls between items; in-flight items always complete.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $mode = $arguments['mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            throw new ToolException('`mode` is required (export / import).');
        }

        if ($mode !== 'import') {
            throw new ToolException("import_export: mode `{$mode}` is not streamable.");
        }

        if (!Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ToolException("import_export: mode `{$mode}` is unavailable on this edition.");
        }

        return yield from $this->_import($arguments, $ctx);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Per-`PermissionedToolTrait` contract. Only `import` consults
     * this method — `export` is Free and never calls
     * `_assertPermission()`.
     *
     * Per-item permission resolution happens INSIDE the import loop
     * (each item targets a different section UID). The top-level call
     * returns the wildcard sentinel so `filterFor()` probing with
     * empty args admits any user with at least one
     * `saveEntries:{section}` permission.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $mode = is_string($arguments['mode'] ?? null) ? $arguments['mode'] : null;
        if (!in_array($mode, self::PRO_MODES, true)) {
            return [];
        }

        $sectionUid = is_string($arguments['sectionUid'] ?? null) ? $arguments['sectionUid'] : null;
        if ($sectionUid !== null && $sectionUid !== '') {
            return ["saveEntries:{$sectionUid}"];
        }
        return ['saveEntries:*'];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the rich tool-specific format keyed on by Gate 8.10's
     * `ModeErrorShapeTest`.
     *
     * @param array<string,mixed> $arguments
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $sectionUid = is_string($arguments['sectionUid'] ?? null) ? $arguments['sectionUid'] : '?';

        return sprintf(
            'permission denied — mode `import` on section `%s` requires `%s`.',
            $sectionUid,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _export(array $arguments): array
    {
        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $this->_offset($arguments);

        $query = Entry::find()->status(null);

        $site = $arguments['site'] ?? null;
        if (is_string($site) && $site !== '') {
            $query->site($site);
        }

        if (isset($arguments['id'])) {
            $query->id($arguments['id']);
        } elseif (isset($arguments['section']) && is_string($arguments['section']) && $arguments['section'] !== '') {
            $query->section($arguments['section']);
        }

        $totalCount = (int) (clone $query)->count();
        $query->limit($limit)->offset($offset);

        /** @var Entry[] $entries */
        $entries = $query->all();

        $info = Craft::$app->getInfo();

        return [
            'format' => self::FORMAT_VERSION,
            'mode' => 'export',
            'exportedAt' => DateTimeHelper::toIso8601(new DateTimeImmutable()),
            'craftVersion' => $info->version,
            'craftEdition' => Craft::$app->getEditionName(),
            'schemaVersion' => $info->schemaVersion,
            'count' => count($entries),
            'totalCount' => $totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'entries' => array_map(
                fn(Entry $e): array => $this->_serializeEntry($e),
                $entries,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _serializeEntry(Entry $entry): array
    {
        $section = $entry->getSection();
        $type = $entry->getType();
        $parentUid = $this->_parentUid($entry);

        return [
            'uid' => $entry->uid,
            'id' => $entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'section' => $section?->handle,
            'type' => $type->handle,
            'site' => $entry->getSite()->handle,
            'enabled' => $entry->enabled,
            'enabledForSite' => $entry->getEnabledForSite(),
            'authorId' => $entry->authorId,
            'authorIds' => $this->_authorIds($entry),
            'parentUid' => $parentUid,
            'level' => $entry->level,
            'postDate' => $entry->postDate !== null ? DateTimeHelper::toIso8601($entry->postDate) : null,
            'expiryDate' => $entry->expiryDate !== null ? DateTimeHelper::toIso8601($entry->expiryDate) : null,
            'dateCreated' => $entry->dateCreated !== null ? DateTimeHelper::toIso8601($entry->dateCreated) : null,
            'dateUpdated' => $entry->dateUpdated !== null ? DateTimeHelper::toIso8601($entry->dateUpdated) : null,
            'fields' => $entry->getSerializedFieldValues(),
        ];
    }

    /**
     * Pull the entry's full author list as a uid array. Craft 5.0+
     * supports multi-author entries via `getAuthorIds()` on the entry.
     * Returns `[]` for entries with no authors. We export uids (not ids)
     * so cross-environment import can resolve users by uid first.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _authorIds(Entry $entry): array
    {
        try {
            $authors = $entry->getAuthors();
        } catch (Throwable) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($author): ?string => is_object($author) && property_exists($author, 'uid') ? (string) $author->uid : null,
            $authors,
        )));
    }

    /**
     * Look up the canonical parent entry's uid for Structure-aware
     * sections. Returns null for top-level entries or non-Structure
     * sections. We expose uid (not id) so a cross-environment import
     * can re-link the hierarchy without depending on auto-incrementing
     * primary keys.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _parentUid(Entry $entry): ?string
    {
        try {
            $parent = $entry->getParent();
        } catch (Throwable) {
            return null;
        }
        return $parent?->uid;
    }

    // Private Methods — Pro import mode
    // =========================================================================

    /**
     * Import-mode dispatch (Pro). Consumes an export-shaped payload,
     * validates each entry against the target section's field layout,
     * and saves create/update by uid. Defaults to `dryRun: true` —
     * explicit `dryRun: false` required to mutate.
     *
     * Generator surface: yields one `{progress, total, message}` frame
     * every `progressInterval` items; returns the terminal envelope. The
     * per-item helper `_importOneEntry()` is unchanged from 8.8b — only
     * the outer loop gained yields + cancellation polling.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _import(array $arguments, InvocationContext $ctx): Generator
    {
        $payload = $arguments['payload'] ?? null;
        if (!is_array($payload)) {
            throw new ToolException('import_export: `payload` is required for mode=import.');
        }

        $format = $payload['format'] ?? null;
        if ($format !== self::FORMAT_VERSION) {
            throw new ToolException(sprintf(
                'import_export: payload `format` mismatch — expected %d, got %s.',
                self::FORMAT_VERSION,
                var_export($format, true),
            ));
        }

        $items = $payload['entries'] ?? null;
        if (!is_array($items)) {
            throw new ToolException('import_export: payload `entries` must be an array.');
        }

        $dryRun = !array_key_exists('dryRun', $arguments) || $arguments['dryRun'] === true;
        $progressInterval = $this->_progressInterval($arguments);
        $token = $ctx->getCancellationToken();
        $total = count($items);

        $results = [];
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $cancelled = false;

        foreach ($items as $index => $item) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            if (!is_array($item)) {
                $results[] = [
                    'kind' => 'failure',
                    'index' => (int) $index,
                    'reason' => 'item_not_object',
                ];
                $failed++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield [
                        'progress' => $processed,
                        'total' => $total,
                        'message' => "Importing item index={$index} uid=?",
                    ];
                }
                continue;
            }

            $outcome = $this->_importOneEntry($item, (int) $index, $dryRun);
            $results[] = $outcome;
            $processed++;
            $kind = $outcome['kind'] ?? 'failure';
            if ($kind === 'success') {
                $succeeded++;
            } elseif ($kind === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }

            if ($processed % $progressInterval === 0) {
                $uid = is_string($item['uid'] ?? null) ? $item['uid'] : '?';
                yield [
                    'progress' => $processed,
                    'total' => $total,
                    'message' => "Importing item index={$index} uid={$uid}",
                ];
            }
        }

        return [
            'success' => $failed === 0 && !$cancelled,
            'mode' => 'import',
            'dryRun' => $dryRun,
            'committed' => $dryRun ? 0 : $succeeded,
            'total' => $total,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'cancelled' => $cancelled,
            'results' => $results,
        ];
    }

    /**
     * Read the `progressInterval` argument with `DEFAULT_PROGRESS_INTERVAL`
     * fallback and a `>= 1` lower clamp. Same contract as
     * `BulkEntries::_progressInterval()` and `Audit::_progressInterval()`.
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
     * Per-item helper for `import`. Resolves the target section, entry
     * type, and site by handle; runs the permission gate; loads any
     * existing entry by uid (update path) or builds a new one (create
     * path); applies attributes + fields; validates; saves (when
     * `$dryRun` is false).
     *
     * Independent method so Gate 8.9 can wrap `_import()` in a
     * `Generator` without touching the per-item resolution and save
     * machinery.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _importOneEntry(array $item, int $index, bool $dryRun): array
    {
        $entriesService = Craft::$app->getEntries();
        $sitesService = Craft::$app->getSites();

        $sectionHandle = is_string($item['section'] ?? null) ? $item['section'] : null;
        if ($sectionHandle === null || $sectionHandle === '') {
            return [
                'kind' => 'skipped',
                'index' => $index,
                'reason' => 'missing_section',
            ];
        }
        $section = $entriesService->getSectionByHandle($sectionHandle);
        if ($section === null) {
            return [
                'kind' => 'skipped',
                'index' => $index,
                'section' => $sectionHandle,
                'reason' => 'missing_section',
            ];
        }

        $typeHandle = is_string($item['type'] ?? null) ? $item['type'] : null;
        if ($typeHandle === null || $typeHandle === '') {
            return [
                'kind' => 'failure',
                'index' => $index,
                'reason' => 'missing_entry_type',
            ];
        }
        $entryType = $entriesService->getEntryTypeByHandle($typeHandle);
        if ($entryType === null) {
            return [
                'kind' => 'skipped',
                'index' => $index,
                'reason' => 'missing_entry_type',
                'entryType' => $typeHandle,
            ];
        }

        // Resolve site context — prefer the per-item `site`, fall back
        // to the call-level `siteHandle`, then to the primary site.
        $siteHandle = is_string($item['site'] ?? null) ? $item['site'] : null;
        $site = $siteHandle !== null && $siteHandle !== ''
            ? $sitesService->getSiteByHandle($siteHandle)
            : null;
        if ($site === null) {
            $site = $sitesService->getPrimarySite();
        }

        try {
            $this->_assertPermission([
                'mode' => 'import',
                'sectionUid' => (string) $section->uid,
            ]);
        } catch (ToolException $e) {
            return [
                'kind' => 'skipped',
                'index' => $index,
                'section' => $section->handle,
                'reason' => 'permission_denied',
                'requiredPermission' => "saveEntries:{$section->uid}",
                'message' => $e->getMessage(),
            ];
        }

        $uid = is_string($item['uid'] ?? null) ? $item['uid'] : null;
        $existing = null;
        if ($uid !== null && $uid !== '') {
            $existing = Entry::find()
                ->uid($uid)
                ->status(null)
                ->site('*')
                ->one();
        }

        if ($existing instanceof Entry) {
            $entry = $existing;
            $resolvedMode = 'update';
        } else {
            $entry = new Entry();
            $entry->sectionId = (int) $section->id;
            $entry->siteId = (int) $site->id;
            if ($uid !== null && $uid !== '') {
                $entry->uid = $uid;
            }
            $resolvedMode = 'create';
        }
        $entry->typeId = (int) $entryType->id;

        $this->_applyImportAttributes($entry, $item);
        $this->_applyImportFields($entry, $item);

        if ($dryRun) {
            $valid = $entry->validate();
            if (!$valid) {
                return [
                    'kind' => 'failure',
                    'index' => $index,
                    'mode' => $resolvedMode,
                    'dryRun' => true,
                    'errors' => $entry->getErrors(),
                ];
            }
            return [
                'kind' => 'success',
                'index' => $index,
                'mode' => $resolvedMode,
                'dryRun' => true,
                'id' => $entry->id !== null ? (int) $entry->id : null,
                'uid' => $entry->uid,
            ];
        }

        try {
            $saved = Craft::$app->getElements()->saveElement($entry, runValidation: true);
        } catch (Throwable $e) {
            return [
                'kind' => 'failure',
                'index' => $index,
                'mode' => $resolvedMode,
                'reason' => $e->getMessage(),
            ];
        }

        if (!$saved) {
            return [
                'kind' => 'failure',
                'index' => $index,
                'mode' => $resolvedMode,
                'errors' => $entry->getErrors(),
            ];
        }

        return [
            'kind' => 'success',
            'index' => $index,
            'mode' => $resolvedMode,
            'id' => (int) $entry->id,
            'uid' => $entry->uid,
        ];
    }

    /**
     * Apply the import payload's scalar attributes onto the resolved
     * entry. Date strings are parsed via `DateTimeHelper::toDateTime()`;
     * `null` clears the field.
     *
     * @param array<string,mixed> $item
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyImportAttributes(Entry $entry, array $item): void
    {
        if (array_key_exists('title', $item) && is_string($item['title'])) {
            $entry->title = $item['title'];
        }
        if (array_key_exists('slug', $item) && is_string($item['slug'])) {
            $entry->slug = $item['slug'];
        }
        if (array_key_exists('enabled', $item)) {
            $entry->enabled = (bool) $item['enabled'];
        }
        if (array_key_exists('enabledForSite', $item)) {
            $entry->setEnabledForSite((bool) $item['enabledForSite']);
        }
        if (array_key_exists('authorId', $item) && (is_int($item['authorId']) || (is_string($item['authorId']) && ctype_digit($item['authorId'])))) {
            $entry->setAuthorIds([(int) $item['authorId']]);
        }
        if (array_key_exists('postDate', $item) && (is_string($item['postDate']) || $item['postDate'] === null)) {
            $parsed = $item['postDate'] !== null ? DateTimeHelper::toDateTime($item['postDate']) : null;
            $entry->postDate = $parsed instanceof \DateTime ? $parsed : null;
        }
        if (array_key_exists('expiryDate', $item) && (is_string($item['expiryDate']) || $item['expiryDate'] === null)) {
            $parsed = $item['expiryDate'] !== null ? DateTimeHelper::toDateTime($item['expiryDate']) : null;
            $entry->expiryDate = $parsed instanceof \DateTime ? $parsed : null;
        }
    }

    /**
     * Forward the import payload's `fields: {handle: value}` map onto
     * the entry's field values via `setFieldValues()`. Same
     * pass-through contract as `_applyFields()` on `AbstractTool` —
     * Craft normalises per field type at save time.
     *
     * @param array<string,mixed> $item
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _applyImportFields(Entry $entry, array $item): void
    {
        $fields = $item['fields'] ?? null;
        if (!is_array($fields) || $fields === []) {
            return;
        }
        $entry->setFieldValues($fields);
    }
}
