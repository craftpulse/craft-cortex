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
use craftpulse\cortex\tools\support\ConsoleRunner;
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
 * Translates a structured argument shape into a `Craft::$app->runAction`
 * call against `resave/<type>`. Uses `ConsoleRunner` so stdout/stderr
 * are captured rather than corrupting the JSON-RPC channel.
 *
 * The tool only exposes the option surface that's safe for AI use:
 *   - `type` selects which element kind to resave (entries / assets /
 *     categories / tags / users / addresses).
 *   - `section`, `group`, `volume`, `entryType` narrow the query.
 *   - `status`, `limit` further narrow it.
 *   - `set` + `to` pair drives the field-rewrite use case.
 *   - `queue` flips between in-process and background dispatch.
 *
 * Anything not in this surface (custom criteria, `withFields`, etc.) is
 * left to `craft_command` for raw access to the same controller.
 *
 * # Streaming surface (Gate 8.9a)
 *
 * Implements `StreamableToolInterface`. When the HTTP transport
 * delivers the request with `Accept: text/event-stream`, the dispatcher
 * routes to `stream()`; otherwise the existing `execute()` (captured-
 * stdout) path runs unchanged.
 *
 * The streaming path bypasses `ConsoleRunner` entirely. Instead, it
 * subscribes to `Elements::EVENT_AFTER_RESAVE_ELEMENT` via a
 * `FiberProgressBridge` so each event surfaces as a `notifications/
 * progress` SSE frame in real time. Cancellation between elements
 * throws `craft\db\QueryAbortedException` into the Fiber — Craft's
 * resave loop catches that exception type at
 * `vendor/craftcms/cms/src/services/Elements.php:1677` and unwinds
 * cleanly. The in-flight element's save is not interruptible (matches
 * Craft's non-killable per-row model).
 *
 * **Terminal envelope shape divergence.** The streaming
 * `stream()->getReturn()` shape is NOT identical to the non-streaming
 * `execute()` shape — see locked decision 16 in
 * `docs/plans/gate-8.9.md`. The streaming surface has structured per-
 * element data via the events; the non-streaming surface wraps the
 * console controller and only sees the captured stdout/stderr. The
 * two paths are intentionally different observability surfaces; the
 * non-streaming path is left bit-identical to pre-8.9a behaviour.
 *
 * **Streaming restrictions.** `queue: true` is rejected on the
 * streaming path (a queued resave runs out-of-band — nothing to
 * stream). The seven field-rewrite options (`set`, `to`, `ifEmpty`,
 * `ifInvalid`, `propagateTo`, `toDefault`, `setEnabledForSite`) are
 * rejected too — those rewrites are driven by
 * `EVENT_BEFORE_RESAVE_ELEMENT` in `ResaveController._resaveElements()`,
 * which Cortex doesn't re-implement in 8.9a to keep the diff focused
 * on the progress infrastructure. Operators wanting streaming field-
 * rewrites get a future gate; for now the non-streaming path accepts
 * the full surface unchanged.
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
     * Field-rewrite options rejected on the streaming path. The non-
     * streaming `execute()` path accepts them unchanged.
     */
    private const STREAM_REJECTED_REWRITE_OPTIONS = [
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
        return 'Re-save elements through Craft\'s resave commands. Required `type`: ' .
            'entries / assets / categories / tags / users / addresses. Optional filters: ' .
            'section, entryType, group, volume, status, limit. Optional rewrite: pair ' .
            '`set` (field handle) with `to` (PHP value expression — see Craft\'s ' .
            'resave/--to documentation). `queue: true` dispatches as a background job; ' .
            'otherwise runs in-process. `updateSearchIndex` and `touch` mirror the ' .
            'corresponding CLI flags. Returns the dispatched route, exit code, captured ' .
            'output, and any error. Streaming clients (Accept: text/event-stream) get ' .
            'a structured per-element progress stream plus a structured terminal ' .
            'envelope; non-streaming clients get the captured-output envelope. The ' .
            'streaming path does not support `queue: true` or field-rewrite options.';
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
            'set' => Schema::string()->description('Field handle to rewrite. Pair with `to`.'),
            'to' => Schema::string()->description('Replacement expression. See Craft resave docs.'),
            'ifEmpty' => Schema::boolean(),
            'ifInvalid' => Schema::boolean(),
            'touch' => Schema::boolean(),
            'updateSearchIndex' => Schema::boolean(),
            'propagateTo' => Schema::string(),
            'queue' => Schema::boolean()->description('Dispatch as a background queue job instead of running in-process.'),
            'batchSize' => Schema::integer()->minimum(1),
        ])->toArray();
    }

    /**
     * @inheritdoc
     *
     * Non-streaming path. Unchanged from pre-8.9a — wraps
     * `ConsoleRunner::run('resave/<type>')`. The streaming path lives in
     * `stream()` and bypasses `ConsoleRunner` entirely.
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function execute(array $arguments): array
    {
        $type = isset($arguments['type']) && is_string($arguments['type']) ? $arguments['type'] : null;
        if ($type === null) {
            throw new ToolException('`type` is required (one of: ' . implode(', ', array_keys(self::TYPE_ROUTES)) . ').');
        }

        if (!isset(self::TYPE_ROUTES[$type])) {
            throw new ToolException("Unknown type '{$type}'. Allowed: " . implode(', ', array_keys(self::TYPE_ROUTES)) . '.');
        }

        $route = self::TYPE_ROUTES[$type];
        $params = $this->_buildParams($type, $arguments);

        $result = ConsoleRunner::run($route, $params);

        return [
            'type' => $type,
            'route' => $route,
            'options' => $params,
            'exitCode' => $result['exitCode'],
            'output' => $result['output'],
            'error' => $result['error'],
        ];
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
     * Terminal envelope shape: see locked decision 16 in
     * `docs/plans/gate-8.9.md`. Diverges from `execute()` by design.
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
                'resave: streaming is incompatible with `queue: true` — invoke without `queue: true` for streaming, ' .
                'or wait for the queued job out-of-band.',
            );
        }

        $rejectedRewrites = array_intersect_key(
            $arguments,
            array_flip(self::STREAM_REJECTED_REWRITE_OPTIONS),
        );
        if ($rejectedRewrites !== []) {
            throw new ToolException(
                'resave: streaming does not support field-rewrite options (' .
                implode('/', self::STREAM_REJECTED_REWRITE_OPTIONS) .
                ') — use non-streaming dispatch for those.',
            );
        }

        // Reuse _buildParams() for filter validation. The result is the
        // same key set the non-streaming path passes to the controller,
        // but the streaming path consumes individual keys directly to
        // build an element query rather than dispatching to the
        // controller binding layer.
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
     * Translate the structured input into the parameter array Yii's
     * controller-option binding consumes. Yii matches keys against the
     * controller's public properties by name (camelCase), so we keep the
     * shape identical to `ResaveController`'s public properties — no
     * hyphenation or alias mapping.
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

        // Type-specific filters (validate at the tool layer; the controller
        // would silently ignore mismatches otherwise). The default branch
        // is unreachable — `execute()` rejects unknown types — but keeps
        // PHPStan happy without disabling the match-coverage check.
        $allowed = match ($type) {
            'entries' => ['section', 'entryType', 'status', 'limit', 'propagateTo'],
            'assets' => ['volume', 'status', 'limit', 'propagateTo'],
            'categories' => ['group', 'status', 'limit', 'propagateTo'],
            'tags' => ['group', 'status', 'limit', 'propagateTo'],
            'users' => ['group', 'status', 'limit'],
            'addresses' => ['status', 'limit'],
            default => throw new ToolException("Unknown type '{$type}'."),
        };

        $rejected = array_diff(
            array_keys(array_diff_key($arguments, ['type' => true])),
            array_merge($allowed, ['set', 'to', 'ifEmpty', 'ifInvalid', 'touch', 'updateSearchIndex', 'queue', 'batchSize']),
        );
        if ($rejected !== []) {
            throw new ToolException(
                "Option(s) not supported for type '{$type}': " . implode(', ', $rejected) .
                '. Allowed: ' . implode(', ', $allowed) . ' plus set/to/ifEmpty/ifInvalid/touch/updateSearchIndex/queue/batchSize.',
            );
        }

        // Map common filters. ResaveController binds against public
        // properties named exactly like these (entryType maps to `type`
        // on the controller — Craft's CLI uses --type for entry types,
        // which collides with our outer `type` so we rename it here).
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
        if (isset($arguments['propagateTo'])) {
            $params['propagateTo'] = (string) $arguments['propagateTo'];
        }

        // Field-rewrite pair.
        if (isset($arguments['set']) || isset($arguments['to'])) {
            if (!isset($arguments['set'], $arguments['to'])) {
                throw new ToolException('`set` and `to` must be provided together.');
            }
            $params['set'] = (string) $arguments['set'];
            $params['to'] = (string) $arguments['to'];
        }

        foreach (['ifEmpty', 'ifInvalid', 'touch', 'updateSearchIndex', 'queue'] as $flag) {
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
