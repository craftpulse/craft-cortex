<?php

namespace craftpulse\cortex\tools\dev;

use Craft;
use craft\base\ElementInterface;
use craft\db\QueryAbortedException;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\MultiElementActionEvent;
use craft\services\Elements;
use craftpulse\cortex\attributes\IsDestructive;
use craftpulse\cortex\attributes\IsIdempotent;
use craftpulse\cortex\attributes\IsOpenWorld;
use craftpulse\cortex\attributes\Title;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\StreamableToolInterface;
use craftpulse\cortex\tools\support\FiberProgressBridge;
use craftpulse\cortex\tools\support\InvocationContext;
use craftpulse\cortex\tools\support\Schema;
use craftpulse\cortex\tools\ToolException;
use Generator;
use Throwable;

/**
 * =========================================================================
 * `resave` tool — convenience wrapper for Craft's `resave/*` commands.
 *
 * Translates a structured argument shape into a resave run against
 * `Craft::$app->getElements()->resaveElements()` for the matching
 * element class.
 *
 * The tool only exposes the option surface that's safe for AI use:
 *   - `type` selects which element kind to resave (entries / assets /
 *     categories / tags / users / addresses).
 *   - `section`, `group`, `volume`, `entryType` narrow the query.
 *   - `status`, `limit` further narrow it.
 *   - `touch`, `updateSearchIndex` mirror the corresponding CLI flags.
 *
 * Anything outside this surface (custom criteria, field-rewrite flags,
 * `--queue` dispatch, `withFields`, etc.) is left to `craft_command`
 * for raw access to the same controller.
 *
 * # Streaming + non-streaming surfaces share the same terminal shape
 *
 * Implements `StreamableToolInterface`. When the HTTP transport
 * delivers the request with `Accept: text/event-stream`, the dispatcher
 * routes to `stream()`; otherwise `execute()` drains `stream()` to
 * completion and returns the same terminal envelope. Mirrors the
 * `BulkEntries::execute()` collapse pattern at
 * `src/tools/content/BulkEntries.php:339-351` — one envelope shape
 * across both transports, structured progress data either way.
 *
 * Both paths subscribe to `Elements::EVENT_AFTER_RESAVE_ELEMENT` via a
 * `FiberProgressBridge` so each event surfaces as a `notifications/
 * progress` SSE frame in real time (streaming) or is discarded
 * (non-streaming). Cancellation between elements throws
 * `craft\db\QueryAbortedException` into the Fiber — Craft's resave
 * loop catches that exception type at
 * `vendor/craftcms/cms/src/services/Elements.php:1677` and unwinds
 * cleanly. The in-flight element's save is not interruptible (matches
 * Craft's non-killable per-row model).
 *
 * **Restrictions (apply to both paths).** `queue: true` is rejected
 * (a queued resave runs out-of-band — nothing to drive progress from).
 * The seven field-rewrite options (`set`, `to`, `ifEmpty`,
 * `ifInvalid`, `propagateTo`, `toDefault`, `setEnabledForSite`) are
 * rejected too — those rewrites are driven by
 * `EVENT_BEFORE_RESAVE_ELEMENT` in `ResaveController._resaveElements()`,
 * which Cortex doesn't re-implement here. Operators wanting field-
 * rewrites get a future gate; for now drop them or use `craft_command`
 * for the raw controller surface.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
#[Title('Resave Elements')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class Resave extends AbstractTool implements StreamableToolInterface
{
    // Constants
    // =========================================================================

    /**
     * Minimum interval between streamed progress frames in microseconds.
     * Wire-level throttle so a very fast resave (~1000+ elements/sec)
     * doesn't overwhelm the SSE channel. The terminal envelope still
     * carries the true `processed` count regardless of how many frames
     * emit.
     */
    private const STREAM_THROTTLE_USEC = 16_000;

    /**
     * Soft cap on per-failure rows recorded in the streaming envelope's
     * `results[]`. Successes are counted but not enumerated — only
     * failures get a row, and only the most recent
     * `STREAM_FAILURE_RESULT_CAP` of them.
     */
    private const STREAM_FAILURE_RESULT_CAP = 100;

    /**
     * Allowed element-type values mapped to the `resave/<action>` route.
     */
    private const TYPE_ROUTES = [
        'entries' => 'resave/entries',
        'assets' => 'resave/assets',
        'categories' => 'resave/categories',
        'tags' => 'resave/tags',
        'users' => 'resave/users',
        'addresses' => 'resave/addresses',
    ];

    /**
     * Field-rewrite options unsupported by the tool. Rejected at the
     * top of `stream()` before any dispatch happens — `execute()`
     * drains `stream()` so the rejection applies to JSON callers too.
     * Could be wired into the Fiber bridge in a future gate, but the
     * implementation cost outweighs the LLM ergonomic value at the
     * tier these are aimed at.
     */
    private const REJECTED_REWRITE_OPTIONS = [
        'set',
        'to',
        'ifEmpty',
        'ifInvalid',
        'propagateTo',
        'toDefault',
        'setEnabledForSite',
    ];

    /**
     * Element-type handle → fully-qualified element class name. Mirrors
     * `ResaveController`'s per-action element-class selection. Used by
     * the streaming path's `_streamingResolve()`.
     *
     * @var array<string, class-string<ElementInterface>>
     */
    private const TYPE_ELEMENT_CLASSES = [
        'entries' => Entry::class,
        'assets' => Asset::class,
        'categories' => Category::class,
        'tags' => Tag::class,
        'users' => User::class,
        'addresses' => Address::class,
    ];

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getName(): string
    {
        return 'resave';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Re-save elements through Craft\'s resave pipeline. Required `type`: ' .
            'entries / assets / categories / tags / users / addresses. Optional filters: ' .
            'section, entryType, group, volume, status, limit. `updateSearchIndex` and ' .
            '`touch` mirror the corresponding CLI flags. Returns a structured envelope ' .
            '{success, type, route, total, processed, succeeded, failed, cancelled, ' .
            'results[]} where `results[]` enumerates per-element failures (successes ' .
            'are counted but not listed). Streaming clients (Accept: text/event-stream) ' .
            'additionally get one `notifications/progress` frame per element (subject ' .
            'to a 60Hz wire-level throttle); non-streaming clients get the same terminal ' .
            'envelope without the intermediate frames. `queue: true` and the field-' .
            'rewrite options (`set`, `to`, `ifEmpty`, `ifInvalid`, `propagateTo`, ' .
            '`toDefault`, `setEnabledForSite`) are not supported — use `craft_command` ' .
            'for raw controller access.';
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
            'type' => Schema::string()
                ->enum(array_keys(self::TYPE_ROUTES))
                ->description('Element kind to resave. Required.')
                ->required(),
            'section' => Schema::string()->description('For type=entries. Comma-separated handles or `*` for all.'),
            'entryType' => Schema::string()->description('For type=entries. Comma-separated entry-type handles.'),
            'group' => Schema::string()->description('For type=categories|tags|users.'),
            'volume' => Schema::string()->description('For type=assets. Comma-separated volume handles.'),
            'status' => Schema::string()->description('Element status filter (default `any`).'),
            'limit' => Schema::integer()->minimum(1),
            'touch' => Schema::boolean(),
            'updateSearchIndex' => Schema::boolean(),
            'batchSize' => Schema::integer()->minimum(1),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Non-streaming entry point. Drives `stream()` to completion,
     * discards progress frames, returns the terminal envelope. Mirrors
     * `BulkEntries::execute()` at `src/tools/content/BulkEntries.php:339-351`
     * so the JSON-mode HTTP transport and the synchronous stdio path
     * surface the same structured envelope as the streaming path.
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
     * Streaming entry point. Mirrors `ResaveController.resaveElements()`
     * for query construction but drives `Craft::$app->getElements()->
     * resaveElements()` directly through a `FiberProgressBridge`. Each
     * `EVENT_AFTER_RESAVE_ELEMENT` becomes one progress frame (subject
     * to the 60Hz wire-level throttle). On cancellation, the bridge
     * throws `QueryAbortedException` into the Fiber so Craft's resave
     * loop unwinds cleanly via the catch-block at
     * `vendor/craftcms/cms/src/services/Elements.php:1677`.
     *
     * Terminal envelope shape is shared with `execute()` — both
     * surfaces return `{success, type, route, total, processed,
     * succeeded, failed, cancelled, results[]}`.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function stream(array $arguments, InvocationContext $ctx): Generator
    {
        $type = isset($arguments['type']) && is_string($arguments['type']) ? $arguments['type'] : null;
        if ($type === null) {
            throw new ToolException('`type` is required (one of: ' . implode(', ', array_keys(self::TYPE_ROUTES)) . ').');
        }
        if (!isset(self::TYPE_ROUTES[$type])) {
            throw new ToolException("Unknown type '{$type}'. Allowed: " . implode(', ', array_keys(self::TYPE_ROUTES)) . '.');
        }

        if (!empty($arguments['queue'])) {
            throw new ToolException(
                'resave: `queue: true` is not supported — the tool runs synchronously ' .
                'and streams progress back to the caller. Drop the option, or dispatch ' .
                'the queue job through `craft_command` if a background run is needed.',
            );
        }

        $rejectedRewrites = array_intersect_key(
            $arguments,
            array_flip(self::REJECTED_REWRITE_OPTIONS),
        );
        if ($rejectedRewrites !== []) {
            throw new ToolException(
                'resave: field-rewrite options (' .
                implode('/', self::REJECTED_REWRITE_OPTIONS) .
                ') are not supported on the resave tool.',
            );
        }

        // Reuse _buildParams() for filter validation. The resulting key
        // set is what `_streamingResolve()` consumes to build the
        // element query.
        $params = $this->_buildParams($type, $arguments);
        $route = self::TYPE_ROUTES[$type];

        $query = $this->_streamingResolve($type, $params);

        // Mirror `ResaveController._resaveElements():793-806`: count
        // the unbounded query, then clamp by `offset`/`limit` so the
        // total reflects what will actually be iterated.
        $total = (int) $query->count();
        $queryOffset = $query->offset;
        if (is_numeric($queryOffset)) {
            $total = max($total - (int) $queryOffset, 0);
        }
        $queryLimit = $query->limit;
        if (is_numeric($queryLimit)) {
            $total = min($total, (int) $queryLimit);
        }
        if ($total === 0) {
            // Yield one zero-row frame so the SSE stream isn't empty
            // (clients SHOULD tolerate zero frames per spec, but a
            // single explicit "nothing to do" frame keeps the wire
            // monotonically progress-aware).
            yield ['progress' => 0, 'total' => 0, 'message' => "No {$type} matched the criteria."];

            return [
                'success' => true,
                'type' => $type,
                'route' => $route,
                'total' => 0,
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'cancelled' => false,
                'results' => [],
            ];
        }

        $token = $ctx->getCancellationToken();

        $touch = (bool) ($arguments['touch'] ?? false);
        $updateSearchIndex = isset($arguments['updateSearchIndex']) ? (bool) $arguments['updateSearchIndex'] : null;

        // Per-event aggregation state. The listener mutates these via
        // closure binding so the terminal envelope assembled after the
        // bridge unwinds reflects the full run.
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $failures = [];
        $lastEmittedAt = 0.0;

        $bridge = new FiberProgressBridge(
            Elements::class,
            Elements::EVENT_AFTER_RESAVE_ELEMENT,
            function(object $event) use ($query, $type, $total, &$processed, &$succeeded, &$failed, &$failures): ?array {
                if (!$event instanceof MultiElementActionEvent) {
                    return null;
                }
                // Defensive identity-filter: Cortex's stdio is single-
                // process and HTTP isolates per request, but if a
                // sibling resave runs concurrently we don't want to
                // surface its events.
                if ($event->query !== $query) {
                    return null;
                }

                $processed++;
                $element = $event->element;
                $exception = $event->exception;
                $hasErrors = $element->hasErrors();

                if ($exception !== null || $hasErrors) {
                    $failed++;
                    $failureRow = [
                        'kind' => 'failure',
                        'id' => $element->id !== null ? (int) $element->id : null,
                        'type' => $type,
                        'title' => (string) $element->getUiLabel(),
                    ];
                    if ($exception !== null) {
                        $failureRow['error'] = $exception->getMessage();
                    } elseif ($hasErrors) {
                        $failureRow['error'] = 'validation failed';
                    }
                    $failures[] = $failureRow;
                    if (count($failures) > self::STREAM_FAILURE_RESULT_CAP) {
                        // Sliding window — drop the oldest. The
                        // succeeded/failed counts are aggregates; the
                        // most recent failures are what the LLM
                        // cares about.
                        array_shift($failures);
                    }
                } else {
                    $succeeded++;
                }

                return $this->_emitProgress($event, $total);
            },
            static function() use ($query, $updateSearchIndex, $touch): void {
                Craft::$app->getElements()->resaveElements(
                    $query,
                    continueOnError: true,
                    skipRevisions: true,
                    updateSearchIndex: $updateSearchIndex,
                    touch: $touch,
                );
            },
            new QueryAbortedException(),
        );

        $shouldCancel = static fn(): bool => $token->isCancelled();

        $gen = $bridge->run($shouldCancel);
        try {
            while ($gen->valid()) {
                $frame = $gen->current();
                $now = microtime(true);
                $shouldEmit = ($lastEmittedAt === 0.0)
                    || (($now - $lastEmittedAt) * 1_000_000 >= self::STREAM_THROTTLE_USEC)
                    || (isset($frame['progress'], $frame['total']) && $frame['progress'] === $frame['total']);

                if ($shouldEmit) {
                    yield $frame;
                    $lastEmittedAt = $now;
                }

                $gen->next();
            }
        } catch (Throwable $e) {
            // Propagate any blocking-call failure that escaped Craft's
            // catch (i.e. anything other than QueryAbortedException,
            // which Craft swallows internally).
            throw new ToolException("resave: streaming failed mid-run: {$e->getMessage()}", previous: $e);
        }

        return [
            'success' => $failed === 0 && !$token->isCancelled(),
            'type' => $type,
            'route' => $route,
            'total' => $total,
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'cancelled' => $token->isCancelled(),
            'results' => array_values($failures),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Build the element query for the streaming path. Mirrors the
     * shape `ResaveController._baseCriteria()` + each action method
     * assembles, but consumes the keys cortex's `_buildParams()`
     * already validated. The returned query is what
     * `Elements::resaveElements()` iterates over inside the Fiber.
     *
     * @param array<string,mixed> $params Validated parameter map from `_buildParams()`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _streamingResolve(string $type, array $params): ElementQueryInterface
    {
        $elementClass = self::TYPE_ELEMENT_CLASSES[$type];
        $criteria = [];

        // Per-type filter mapping. Matches `actionEntries()` / etc. in
        // ResaveController's behaviour but reads the already-validated
        // parameter map instead of binding controller properties.
        if ($type === 'entries') {
            if (isset($params['section'])) {
                $criteria['section'] = $params['section'] === '*'
                    ? '*'
                    : explode(',', (string) $params['section']);
            }
            if (isset($params['type'])) {
                $criteria['type'] = explode(',', (string) $params['type']);
            }
        }
        if ($type === 'assets' && isset($params['volume'])) {
            $criteria['volume'] = explode(',', (string) $params['volume']);
        }
        if (($type === 'categories' || $type === 'tags' || $type === 'users') && isset($params['group'])) {
            $criteria['group'] = explode(',', (string) $params['group']);
        }

        // Status / limit — mirrors `_baseCriteria()` semantics from
        // `vendor/craftcms/cms/src/console/controllers/ResaveController.php:744-783`.
        $status = $params['status'] ?? 'any';
        if ($status === 'any') {
            $criteria['status'] = null;
        } else {
            $criteria['status'] = explode(',', (string) $status);
        }
        if (isset($params['limit'])) {
            $criteria['limit'] = (int) $params['limit'];
        }

        // Resave defaults: drafts + provisional drafts excluded, no
        // revisions. Matches the ResaveController defaults at
        // `:744-750`.
        $criteria['drafts'] = false;
        $criteria['provisionalDrafts'] = false;
        $criteria['revisions'] = false;

        $query = $elementClass::find();
        Craft::configure($query, $criteria);

        return $query;
    }

    /**
     * Build a progress frame from a `MultiElementActionEvent`. The
     * total is the pre-flight count captured by the parent generator.
     *
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _emitProgress(MultiElementActionEvent $event, int $total): array
    {
        $element = $event->element;
        $exception = $event->exception;

        $label = (string) $element->getUiLabel();
        $id = $element->id !== null ? (int) $element->id : 0;
        $displayName = $element::displayName();

        $message = $exception !== null
            ? "Failed resaving {$displayName} \"{$label}\" ({$id}): {$exception->getMessage()}"
            : "Resaving {$displayName} \"{$label}\" ({$id})";

        return [
            'progress' => $event->position,
            'total' => $total,
            'message' => $message,
        ];
    }

    /**
     * Translate the structured input into the parameter array used by
     * `_streamingResolve()` to build the element query. The `entryType`
     * key is renamed to `type` because Craft's `ResaveController` binds
     * `--type` to its `$type` property — we accept `entryType` from the
     * AI (since `type` is already taken at the outer tool level) and
     * translate it.
     *
     * `set`/`to`/`ifEmpty`/`ifInvalid`/`propagateTo`/`toDefault`/
     * `setEnabledForSite`/`queue` are rejected by `stream()` before
     * this method runs, so the branches handling them are kept only
     * as defensive no-ops in case a future call site reuses this
     * helper without those upstream rejections.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _buildParams(string $type, array $arguments): array
    {
        $params = [];

        // Type-specific filters (validate at the tool layer; the
        // controller would silently ignore mismatches otherwise). The
        // default branch is unreachable — `stream()` rejects unknown
        // types — but keeps PHPStan happy without disabling the
        // match-coverage check. `set` / `to` / `ifEmpty` / `ifInvalid` /
        // `propagateTo` / `queue` / `toDefault` / `setEnabledForSite`
        // are rejected at the top of `stream()` and never reach here.
        $allowed = match ($type) {
            'entries' => ['section', 'entryType', 'status', 'limit'],
            'assets' => ['volume', 'status', 'limit'],
            'categories' => ['group', 'status', 'limit'],
            'tags' => ['group', 'status', 'limit'],
            'users' => ['group', 'status', 'limit'],
            'addresses' => ['status', 'limit'],
            default => throw new ToolException("Unknown type '{$type}'."),
        };

        $rejected = array_diff(
            array_keys(array_diff_key($arguments, ['type' => true])),
            array_merge($allowed, ['touch', 'updateSearchIndex', 'batchSize']),
        );
        if ($rejected !== []) {
            throw new ToolException(
                "Option(s) not supported for type '{$type}': " . implode(', ', $rejected) .
                '. Allowed: ' . implode(', ', $allowed) . ' plus touch/updateSearchIndex/batchSize.',
            );
        }

        // Map common filters. The streaming bridge binds against the
        // same key set Craft's ResaveController would consume —
        // `entryType` renames to `type` because Craft's CLI uses
        // `--type` for entry types, which collides with our outer
        // `type` field naming the element kind.
        if (isset($arguments['section'])) {
            $params['section'] = (string) $arguments['section'];
        }
        if (isset($arguments['entryType'])) {
            $params['type'] = (string) $arguments['entryType'];
        }
        if (isset($arguments['group'])) {
            $params['group'] = (string) $arguments['group'];
        }
        if (isset($arguments['volume'])) {
            $params['volume'] = (string) $arguments['volume'];
        }
        if (isset($arguments['status'])) {
            $params['status'] = (string) $arguments['status'];
        }
        if (isset($arguments['limit'])) {
            $params['limit'] = (int) $arguments['limit'];
        }

        foreach (['touch', 'updateSearchIndex'] as $flag) {
            if (isset($arguments[$flag])) {
                $params[$flag] = (bool) $arguments[$flag];
            }
        }
        if (isset($arguments['batchSize'])) {
            $params['batchSize'] = (int) $arguments['batchSize'];
        }

        return $params;
    }
}
