<?php

namespace craftpulse\herald\tools\content;

use Craft;
use craft\elements\Entry as EntryElement;
use craft\elements\User;
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
use Generator;
use Throwable;

/**
 * =========================================================================
 * `scaffold_entries` Pro tool — template-driven bulk create-many.
 *
 * Companion to `bulk_entries`. Where `bulk_entries` mutates rows the
 * query resolver selected, `scaffold_entries` creates N new entries
 * from a deterministic template, with `{n}` / `{n:0Nd}` substitution
 * across the title and slug.
 *
 * Substitution surface (intentionally narrow — no Twig):
 *   - `{n}` — 1-indexed row counter.
 *   - `{n:0Nd}` — zero-padded, N digits. Examples: `{n:04d}` -> `0001`,
 *     `0002`, …, `{n:02d}` -> `01`, `02`, ….
 *
 * Hard row cap 10,000; `force: true` to override. Default
 * `progressInterval = 100`.
 *
 * Permission contract:
 *   - `filterFor()` admits any user with at least one
 *     `saveEntries:{sectionUid}` (admin + stdio always pass).
 *   - `_requiredPermissions()` returns `saveEntries:{sectionUid}` when
 *     the call's `sectionUid` argument resolves to a section.
 *   - Per-row `Elements::canSave($entry, $caller)` runs INSIDE the
 *     creation loop so a caller with `saveEntries:posts` only can
 *     scaffold into `posts` but not into `news` even when the
 *     argument lies; the per-row check is the security boundary.
 *
 * Idempotency contract: cache prefix `herald:scaffold_entries:idem:`.
 * Whole-operation cache keyed by `{userId, idempotencyKey}`. Caveat: if
 * the operator changes `count` or `template.title` between calls
 * sharing the same key, the OLD cached envelope is returned. LLMs
 * should generate fresh keys per intent.
 *
 * Streaming-cooperative — same cancellation surface as `bulk_entries`.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsDestructive]
#[IsIdempotent(false)]
#[Title('Scaffold Entries — template-driven bulk create')]
class ScaffoldEntries extends AbstractTool implements StreamableToolInterface
{
    use IdempotencyTrait;
    use PermissionedToolTrait;
    use ProToolTrait;

    // Constants
    // =========================================================================

    /**
     * Idempotency cache key prefix. Consumed by `IdempotencyTrait`.
     *
     * @since 5.0.0
     */
    public const IDEMPOTENCY_CACHE_PREFIX = 'herald:scaffold_entries:idem:';

    /**
     * Hard ceiling on the number of entries created per call. Above
     * this, `force: true` is required.
     *
     * @since 5.0.0
     */
    public const ROW_CAP = 10_000;

    /**
     * Default progress-frame interval in rows created.
     *
     * @since 5.0.0
     */
    public const DEFAULT_PROGRESS_INTERVAL = 100;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'scaffold_entries';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Bulk-create N entries from a deterministic template. `template.title` and ' .
            '`template.slug` accept `{n}` and `{n:0Nd}` substitution (1-indexed counter; ' .
            'zero-padded N digits). No Twig — the surface stays narrow and predictable. ' .
            'Permission: per-row `saveEntries:{sectionUid}`. Hard cap 10,000 per call; ' .
            '`force: true` to bypass. Streams `notifications/progress` frames every ' .
            '`progressInterval` rows (default 100). Returns a terminal envelope with ' .
            'per-row `results[]` ({kind: success / failure, id, …}). ' .
            'Cancellation-cooperative. `idempotencyKey` caches the envelope for 24h. ' .
            'Pro edition only.';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        return Schema::object([
            'sectionUid' => Schema::string()
                ->required()
                ->description('Target section UID.'),
            'entryTypeUid' => Schema::string()
                ->required()
                ->description('Target entry-type UID.'),
            'count' => Schema::integer()
                ->minimum(1)
                ->maximum(self::ROW_CAP)
                ->required()
                ->description('Number of entries to create (1..10,000).'),
            'template' => Schema::object([
                'title' => Schema::string()
                    ->required()
                    ->description('Title template. Accepts `{n}` / `{n:0Nd}` substitution.'),
                'slug' => Schema::string()
                    ->description('Optional slug template. Accepts the same substitution surface.'),
                'status' => Schema::string()
                    ->enum(['enabled', 'disabled'])
                    ->description('Initial status. Defaults to `enabled`.'),
                'fields' => Schema::object()
                    ->additionalProperties(true)
                    ->description('Custom-field values applied to every created entry.'),
                'authorId' => Schema::integer()
                    ->description('Author user id. Defaults to the dispatch user.'),
            ])
                ->additionalProperties(false)
                ->required(),

            'siteId' => Schema::any()->description('Site id, handle, or `"*"`. Defaults to primary.'),
            'force' => Schema::boolean()->description('Override the row cap. Defaults to false.'),
            'progressInterval' => Schema::integer()
                ->minimum(1)
                ->description('Yield a progress frame every N rows. Defaults to 100.'),
            'idempotencyKey' => Schema::string()
                ->maxLength(self::IDEMPOTENCY_KEY_MAX_LENGTH)
                ->description('Whole-operation idempotency token.'),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
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
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $ctx = new InvocationContext();
        $gen = $this->stream($arguments, $ctx);
        while ($gen->valid()) {
            $gen->next();
        }
        $return = $gen->getReturn();
        return is_array($return) ? $return : [];
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $cacheHit = $this->_idempotencyCacheHit($arguments);
        if ($cacheHit !== null) {
            yield ['progress' => 1, 'total' => 1, 'message' => 'Idempotency cache hit: returning cached envelope.'];
            return $cacheHit;
        }

        $envelope = yield from $this->_runCreate($arguments, $ctx);

        $isCancelled = ($envelope['cancelled'] ?? false) === true;
        $hasTopLevelErrors = isset($envelope['errors']);
        if (!$isCancelled && !$hasTopLevelErrors) {
            $this->_cacheIdempotencyEnvelope($arguments, $envelope);
        }

        return $envelope;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $sectionUid = $arguments['sectionUid'] ?? null;
        if (is_string($sectionUid) && $sectionUid !== '') {
            return ["saveEntries:{$sectionUid}"];
        }
        return ['saveEntries:*'];
    }

    /**
     * @inheritdoc
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        return sprintf(
            'permission denied: scaffold_entries requires `%s`.',
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the loop that materialises N entries from the template.
     * Yields progress frames every `progressInterval` rows, returns the
     * terminal envelope.
     *
     * @param array<string,mixed> $arguments
     * @return Generator<int,array<string,mixed>,mixed,array<string,mixed>>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _runCreate(array $arguments, InvocationContext $ctx): Generator
    {
        $sectionUid = $arguments['sectionUid'] ?? null;
        $entryTypeUid = $arguments['entryTypeUid'] ?? null;
        $count = $arguments['count'] ?? null;
        $template = $arguments['template'] ?? null;

        if (!is_string($sectionUid) || $sectionUid === '') {
            return $this->_terminalErrorEnvelope(['sectionUid' => 'scaffold_entries requires `sectionUid`.']);
        }
        if (!is_string($entryTypeUid) || $entryTypeUid === '') {
            return $this->_terminalErrorEnvelope(['entryTypeUid' => 'scaffold_entries requires `entryTypeUid`.']);
        }
        if (!is_int($count) || $count < 1) {
            return $this->_terminalErrorEnvelope(['count' => 'scaffold_entries requires `count` to be a positive integer.']);
        }
        if (!is_array($template) || !isset($template['title']) || !is_string($template['title']) || $template['title'] === '') {
            return $this->_terminalErrorEnvelope(['template' => 'scaffold_entries requires `template.title` (non-empty string).']);
        }
        if ($count > self::ROW_CAP && ($arguments['force'] ?? false) !== true) {
            throw new ToolException(sprintf(
                'scaffold_entries: count=%d exceeds the %d-row cap. Pass `force: true` to override.',
                $count,
                self::ROW_CAP,
            ));
        }

        $entriesService = Craft::$app->getEntries();
        $section = $entriesService->getSectionByUid($sectionUid);
        if ($section === null) {
            return $this->_terminalErrorEnvelope(['sectionUid' => "scaffold_entries: section uid=`{$sectionUid}` not found."]);
        }
        $entryType = $entriesService->getEntryTypeByUid($entryTypeUid);
        if ($entryType === null) {
            return $this->_terminalErrorEnvelope(['entryTypeUid' => "scaffold_entries: entry type uid=`{$entryTypeUid}` not found."]);
        }
        if (!$this->_sectionAllowsEntryType($section, $entryType)) {
            return $this->_terminalErrorEnvelope([
                'entryTypeUid' => sprintf(
                    'scaffold_entries: entry type `%s` is not assigned to section `%s`.',
                    $entryType->handle,
                    $section->handle,
                ),
            ]);
        }

        $this->_assertPermission($arguments);

        $siteId = $this->_resolveSiteId($arguments);

        $progressInterval = $this->_progressInterval($arguments);
        $token = $ctx->getCancellationToken();
        $elements = Craft::$app->getElements();
        $caller = Craft::$app->getUser()->getIdentity();
        $titleTemplate = (string) $template['title'];
        $slugTemplate = isset($template['slug']) && is_string($template['slug']) ? $template['slug'] : null;
        $defaultEnabled = !isset($template['status']) || $template['status'] !== 'disabled';
        $fields = (isset($template['fields']) && is_array($template['fields'])) ? $template['fields'] : [];
        $authorId = $template['authorId'] ?? null;
        if (!is_int($authorId) && !(is_string($authorId) && ctype_digit($authorId))) {
            $authorId = $caller instanceof User ? (int) $caller->id : null;
        }
        if ($entryType->id === null) {
            return $this->_terminalErrorEnvelope(['entryTypeUid' => 'scaffold_entries: resolved entry type has no id (unsaved?).']);
        }
        $sectionId = (int) $section->id;
        $typeId = (int) $entryType->id;

        $total = $count;
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $results = [];
        $cancelled = false;

        for ($i = 1; $i <= $count; $i++) {
            if ($token->isCancelled()) {
                $cancelled = true;
                break;
            }

            $entry = new EntryElement();
            $entry->sectionId = $sectionId;
            $entry->typeId = $typeId;
            $entry->siteId = $siteId;
            $entry->enabled = $defaultEnabled;
            $entry->title = $this->_substitute($titleTemplate, $i);
            if ($slugTemplate !== null) {
                $entry->slug = $this->_substitute($slugTemplate, $i);
            }
            if ($authorId !== null) {
                $entry->setAuthorId((int) $authorId);
            }
            if ($fields !== []) {
                $entry->setFieldValues($fields);
            }

            // Per-row canSave() check. stdio (`null` caller) is trusted.
            if ($caller !== null && !$elements->canSave($entry, $caller)) {
                $results[] = [
                    'kind' => 'failure',
                    'id' => null,
                    'reason' => 'permission_denied',
                ];
                $failed++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield $this->_progressFrame($processed, $total, "Denied creation at row {$i}");
                }
                continue;
            }

            try {
                $saved = $elements->saveElement($entry, runValidation: true);
            } catch (Throwable $e) {
                $results[] = [
                    'kind' => 'failure',
                    'id' => null,
                    'reason' => $e->getMessage(),
                ];
                $failed++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield $this->_progressFrame($processed, $total, "Error at row {$i}");
                }
                continue;
            }
            if (!$saved) {
                $results[] = [
                    'kind' => 'failure',
                    'id' => $entry->id !== null ? (int) $entry->id : null,
                    'reason' => 'validation failed',
                    'validationErrors' => $entry->getErrors(),
                ];
                $failed++;
                $processed++;
                if ($processed % $progressInterval === 0) {
                    yield $this->_progressFrame($processed, $total, "Validation failure at row {$i}");
                }
                continue;
            }

            $results[] = [
                'kind' => 'success',
                'id' => (int) $entry->id,
            ];
            $succeeded++;
            $processed++;

            if ($processed % $progressInterval === 0) {
                yield $this->_progressFrame($processed, $total, "Created entry {$entry->id}");
            }
        }

        return $this->_terminalEnvelope($total, $processed, $succeeded, $failed, $cancelled, $results);
    }

    /**
     * Apply `{n}` and `{n:0Nd}` substitution to a template string.
     * The `{n:0Nd}` form is matched by a tight regex so adjacent
     * literal braces in the template aren't accidentally consumed.
     *
     * Examples:
     *   - `_substitute('Imported Hero {n}', 7)` -> `'Imported Hero 7'`
     *   - `_substitute('Imported Hero {n:04d}', 7)` -> `'Imported Hero 0007'`
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _substitute(string $template, int $index): string
    {
        // Zero-padded form first so `{n:04d}` matches before plain `{n}`.
        $result = preg_replace_callback(
            '/\{n:0(\d+)d\}/',
            static function(array $match) use ($index): string {
                $width = (int) $match[1];
                return str_pad((string) $index, $width, '0', STR_PAD_LEFT);
            },
            $template,
        );
        if (!is_string($result)) {
            $result = $template;
        }
        return str_replace('{n}', (string) $index, $result);
    }

    /**
     * Whether the given section is assigned the given entry type.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _sectionAllowsEntryType(\craft\models\Section $section, \craft\models\EntryType $entryType): bool
    {
        foreach ($section->getEntryTypes() as $candidate) {
            if ($candidate->id === $entryType->id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read `progressInterval`, clamped to a sensible minimum (>=1).
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _progressInterval(array $arguments): int
    {
        $value = $arguments['progressInterval'] ?? self::DEFAULT_PROGRESS_INTERVAL;
        return max(1, (int) $value);
    }

    /**
     * Build the terminal envelope.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _terminalEnvelope(
        int $total,
        int $processed,
        int $succeeded,
        int $failed,
        bool $cancelled,
        array $results,
    ): array {
        return [
            'success' => $failed === 0 && !$cancelled,
            'mode' => 'create',
            'total' => $total,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => 0,
            'cancelled' => $cancelled,
            'results' => $results,
        ];
    }

    /**
     * Build the terminal envelope for the top-level error path.
     *
     * @param array<string,string> $errors
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _terminalErrorEnvelope(array $errors): array
    {
        return [
            'success' => false,
            'mode' => 'create',
            'total' => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'cancelled' => false,
            'results' => [],
            'errors' => $errors,
        ];
    }

    /**
     * Build a progress-frame payload.
     *
     * @return array<string,mixed>
     *
     * @author CraftPulse
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
}
