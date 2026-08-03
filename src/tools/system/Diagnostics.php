<?php

namespace craftpulse\herald\tools\system;

use Craft;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\FileHelper;
use craft\queue\Queue;
use craftpulse\herald\attributes\IsIdempotent;
use craftpulse\herald\attributes\IsReadOnly;
use craftpulse\herald\Herald;
use craftpulse\herald\tools\AbstractTool;
use craftpulse\herald\tools\PermissionedToolTrait;
use craftpulse\herald\tools\support\Schema;
use craftpulse\herald\tools\ToolException;
use Throwable;

/**
 * =========================================================================
 * `diagnostics` tool — every "what's wrong?" surface in one read, plus
 * Pro-edition queue-manager actions.
 *
 * Multi-mode tool that consolidates the independent diagnostics surfaces
 * the AI almost always wants in sequence. Single tool, one `type` param,
 * capped result size.
 *
 * Free types (always available):
 *   - `logs` — last N lines of `storage/logs/*.log`. Filterable by
 *     log file (`channel`) and minimum level (`error|warning|info|trace`).
 *   - `last_error` — most recent error from the active log channel.
 *   - `deprecations` — `Deprecator::getLogs()`.
 *   - `queue` — pending / reserved / failed / done counts plus the most
 *     recent N jobs of each.
 *   - `project_config_diff` — `areChangesPending()` summary.
 *
 * Pro type (Gate 8.8a — mode-unlock composition contract, locked
 * decision):
 *   - `manage_queue` — retry / release single jobs, or bulk
 *     retry_all / release_all the whole channel. Permission gate is
 *     Craft's native `utility:queue-manager` (`QueueManager::id()`).
 *     `release` IS the cancel/delete action — Craft's `Queue` has no
 *     `cancel()` method; the row is deleted by `release()`.
 *
 * The tool stays Free-registered (`shouldRegister()` always true). Pro
 * unlock happens at `inputSchemaFor()` and at `execute()` per the
 * mode-unlock composition contract.
 *
 * Default `limit` is 50 items per mode; hard-cap 500.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
#[IsReadOnly]
#[IsIdempotent]
class Diagnostics extends AbstractTool
{
    use PermissionedToolTrait;

    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    /**
     * @var list<string>
     */
    private const FREE_TYPES = ['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff'];

    /**
     * @var list<string>
     */
    private const PRO_TYPES = ['manage_queue'];

    /**
     * @var list<string>
     */
    private const MANAGE_QUEUE_ACTIONS = ['retry', 'retry_all', 'release', 'release_all'];

    /**
     * Permission required for `type=manage_queue`. Mirrors Craft's own
     * `utility:queue-manager` permission emitted by
     * `vendor/craftcms/cms/src/utilities/QueueManager.php` (id =
     * `queue-manager`) through the `utility:%s` template at
     * `vendor/craftcms/cms/src/services/UserPermissions.php`.
     */
    private const PERMISSION_QUEUE_MANAGER = 'utility:queue-manager';

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
        return 'system_diagnostics';
    }

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getDescription(): string
    {
        return 'Combined diagnostics surface: logs, last_error, deprecations, queue jobs, ' .
            'and project_config_diff. Pick one via `type`. Returns at most `limit` items ' .
            '(default 50, max 500). Pro adds `type=manage_queue` for retry/release actions ' .
            'gated on `utility:queue-manager`.';
    }

    /**
     * @inheritdoc
     *
     * Base schema — exposes the Free + Pro types on Pro installs, the
     * Free-only types on Free installs. The edition gate runs here so
     * the static surface (`tools/list`, `inputSchemaFor(null)`, and
     * the architecture invariant at
     * `tests/Mcp/ToolInterfaceInvariantTest.php`) stay aligned with the
     * runtime gate in `execute()`. HTTP callers on Pro are further
     * filtered down per Craft permissions in `inputSchemaFor($user)`.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function getInputSchema(): array
    {
        $types = Herald::getInstance()->is(Herald::EDITION_PRO, '>=')
            ? array_merge(self::FREE_TYPES, self::PRO_TYPES)
            : self::FREE_TYPES;

        return self::_schemaWithTypes($types);
    }

    /**
     * @inheritdoc
     *
     * Documentation surface: the Free + Pro `type` enum unconditionally,
     * so `docs/TOOLS.md` is identical whichever edition the generator ran
     * on. Shares `_schemaWithTypes()` with `getInputSchema()`, so the two
     * cannot drift apart when a property or a type is added.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public static function docsInputSchema(): array
    {
        return self::_schemaWithTypes(array_merge(self::FREE_TYPES, self::PRO_TYPES));
    }

    /**
     * @inheritdoc
     *
     * Per-user input-schema rewrite. stdio (`null` user) gets the full
     * static enum verbatim — the trusted local path. Filters happen
     * only for HTTP callers (non-null user). An HTTP caller on a
     * Free-edition install never sees `manage_queue` regardless of
     * permission. An HTTP caller on Pro who lacks
     * `utility:queue-manager` is downgraded to the Free type set.
     * `execute()` still re-validates the resolved type for security
     * AND blocks Pro types on Free installs.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function inputSchemaFor(?User $user = null): array
    {
        // `getInputSchema()` already reflects the edition (Free vs
        // Pro). For stdio (null user) and Free installs, this is the
        // final answer — no per-permission filtering applies.
        $schema = static::getInputSchema();

        if ($user === null || !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            return $schema;
        }

        if ($user->admin) {
            return $schema;
        }

        if ($user->can(self::PERMISSION_QUEUE_MANAGER)) {
            return $schema;
        }

        $schema['properties']['type']['enum'] = self::FREE_TYPES;
        return $schema;
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
        $type = $arguments['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new ToolException(
                '`type` is required (logs / last_error / deprecations / queue / project_config_diff / manage_queue).'
            );
        }

        if (in_array($type, self::PRO_TYPES, true) && !Herald::getInstance()->is(Herald::EDITION_PRO, '>=')) {
            throw new ToolException("system_diagnostics: type `{$type}` is unavailable on this edition.");
        }

        $limit = $this->_limit($arguments, self::DEFAULT_LIMIT, self::MAX_LIMIT);

        return match ($type) {
            'logs' => $this->_logs($arguments, $limit),
            'last_error' => $this->_lastError($arguments),
            'deprecations' => $this->_deprecations($limit),
            'queue' => $this->_queue($limit),
            'project_config_diff' => $this->_projectConfigDiff(),
            'manage_queue' => $this->_manageQueue($arguments),
            default => throw new ToolException("Unknown type: '{$type}'."),
        };
    }

    // Protected Methods
    // =========================================================================

    /**
     * Per-`PermissionedToolTrait` contract. Only `manage_queue`
     * consults this method — the read types never call
     * `_assertPermission()`.
     *
     * @param array<string,mixed> $arguments
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _requiredPermissions(array $arguments): array
    {
        $type = is_string($arguments['type'] ?? null) ? $arguments['type'] : null;
        if (!in_array($type, self::PRO_TYPES, true)) {
            return [];
        }

        return [self::PERMISSION_QUEUE_MANAGER];
    }

    /**
     * Override `PermissionedToolTrait::_buildPermissionDeniedMessage()`
     * to emit the rich tool-specific format keyed on by 8.10's
     * `ModeErrorShapeTest`. No per-resource UID for the queue manager
     * — it's a single global permission.
     *
     * @param array<string,mixed> $arguments
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    protected function _buildPermissionDeniedMessage(string $missingPermission, array $arguments): string
    {
        $type = is_string($arguments['type'] ?? null) ? $arguments['type'] : '?';

        return sprintf(
            'permission denied: type `%s` requires `%s`.',
            $type,
            $missingPermission,
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * The tool's input schema with the `type` enum set to `$types`. Sole
     * definition of the schema body: `getInputSchema()` passes the
     * edition-appropriate enum, `docsInputSchema()` passes the union.
     *
     * @param list<string> $types
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private static function _schemaWithTypes(array $types): array
    {
        return Schema::object([
            'type' => Schema::string()
                ->enum($types)
                ->description('Required.')
                ->required(),
            'channel' => Schema::string()
                ->description('Log file basename (e.g. "web", "queue"). Defaults to "web".'),
            'minLevel' => Schema::string()
                ->enum(['trace', 'info', 'warning', 'error'])
                ->description('Minimum severity to include. Defaults to "warning".'),
            'action' => Schema::string()
                ->enum(self::MANAGE_QUEUE_ACTIONS)
                ->description('Required for `type=manage_queue`. `release` deletes the job; ' .
                    'Craft has no `cancel()` — `release` IS the cancel action.'),
            'jobId' => Schema::string()
                ->description('Queue row id (string). Required for `manage_queue` action=retry / release.'),
            'limit' => Schema::integer()->minimum(1)->maximum(self::MAX_LIMIT),
        ])->toArray();
    }

    /**
     * @return array<string,mixed>
     *
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _logs(array $arguments, int $limit): array
    {
        $channel = $this->_channel($arguments);
        $minLevel = $this->_minLevel($arguments);
        $path = $this->_logPath($channel);

        if (!file_exists($path)) {
            return [
                'type' => 'logs',
                'channel' => $channel,
                'path' => $path,
                'exists' => false,
                'entries' => [],
            ];
        }

        $tail = $this->_tail($path, $limit);
        $entries = $this->_parseLogLines($tail, $minLevel);

        return [
            'type' => 'logs',
            'channel' => $channel,
            'path' => $path,
            'exists' => true,
            'minLevel' => $minLevel,
            'entries' => $entries,
            'count' => count($entries),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _lastError(array $arguments): array
    {
        $channel = $this->_channel($arguments);
        $path = $this->_logPath($channel);

        if (!file_exists($path)) {
            return [
                'type' => 'last_error',
                'channel' => $channel,
                'exists' => false,
                'error' => null,
            ];
        }

        // Walk back from the end of the file looking for the last error line.
        // Bound to ~2000 lines so we don't scan log files indefinitely.
        $tail = $this->_tail($path, 2000);
        $errors = $this->_parseLogLines($tail, 'error');

        return [
            'type' => 'last_error',
            'channel' => $channel,
            'exists' => true,
            'error' => $errors[count($errors) - 1] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _deprecations(int $limit): array
    {
        $logs = Craft::$app->getDeprecator()->getLogs();

        $entries = array_slice($logs, 0, $limit);

        return [
            'type' => 'deprecations',
            'entries' => array_map(
                static fn($d): array => [
                    'key' => $d->key,
                    'message' => $d->message,
                    'fingerprint' => $d->fingerprint,
                    'file' => $d->file,
                    'line' => $d->line,
                    'lastOccurrence' => $d->lastOccurrence !== null ? DateTimeHelper::toIso8601($d->lastOccurrence) : null,
                ],
                $entries,
            ),
            'count' => count($entries),
            'totalCount' => count($logs),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _queue(int $limit): array
    {
        $queue = Craft::$app->getQueue();

        $totals = $queue instanceof Queue ? $this->_queueTotals($queue) : [];

        // Use Craft's built-in API rather than touching the queue table
        // directly — abstracts over future schema changes and respects
        // any queue-driver overrides.
        $jobs = $queue instanceof Queue ? $queue->getJobInfo($limit) : [];

        return [
            'type' => 'queue',
            'totals' => $totals,
            'jobs' => $jobs,
            'count' => count($jobs),
        ];
    }

    /**
     * Manage-queue dispatch (Pro). Routes on the `action` sub-mode and
     * delegates to Craft's `Queue` API. `release` IS the cancel/delete
     * action — Craft has no `cancel()` method; the row is deleted by
     * `release()` (see `vendor/craftcms/cms/src/queue/Queue.php`).
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ToolException
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _manageQueue(array $arguments): array
    {
        $action = $arguments['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::MANAGE_QUEUE_ACTIONS, true)) {
            throw new ToolException(
                'system_diagnostics: `action` is required for type=manage_queue ' .
                    '(retry / retry_all / release / release_all).',
            );
        }

        $this->_assertPermission($arguments);

        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            throw new ToolException(
                'system_diagnostics: the active queue driver does not support manage_queue. ' .
                    'Craft\'s `craft\\queue\\Queue` is required.',
            );
        }

        $jobId = null;
        if (in_array($action, ['retry', 'release'], true)) {
            $rawId = $arguments['jobId'] ?? null;
            if (!is_string($rawId) || $rawId === '') {
                throw new ToolException("system_diagnostics: `jobId` is required for action=`{$action}`.");
            }
            $jobId = $rawId;
        }

        $before = $this->_queueTotals($queue);

        try {
            match ($action) {
                'retry' => $queue->retry((string) $jobId),
                'retry_all' => $queue->retryAll(),
                'release' => $queue->release((string) $jobId),
                'release_all' => $queue->releaseAll(),
            };
        } catch (Throwable $e) {
            throw new ToolException("system_diagnostics: manage_queue {$action} failed: {$e->getMessage()}");
        }

        $after = $this->_queueTotals($queue);

        $affected = match ($action) {
            'retry' => 1,
            'release' => max(0, $before['total'] - $after['total']),
            'retry_all' => max(0, $before['failed'] - $after['failed']),
            'release_all' => max(0, $before['total'] - $after['total']),
        };

        $envelope = [
            'success' => true,
            'type' => 'manage_queue',
            'action' => $action,
            'affected' => $affected,
            'queueAfter' => $after,
        ];
        if ($jobId !== null) {
            $envelope['jobId'] = $jobId;
        }

        return $envelope;
    }

    /**
     * Build the queue totals shape reused by `_queue()` for the read
     * mode and `_manageQueue()` for the post-action confirmation
     * envelope.
     *
     * @return array{waiting:int,delayed:int,reserved:int,failed:int,total:int}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _queueTotals(Queue $queue): array
    {
        // `getTotalDone()` doesn't exist in Craft 5 — done jobs aren't
        // kept; ttr removes them.
        return [
            'waiting' => $queue->getTotalWaiting(),
            'delayed' => $queue->getTotalDelayed(),
            'reserved' => $queue->getTotalReserved(),
            'failed' => $queue->getTotalFailed(),
            'total' => $queue->getTotalJobs(),
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _projectConfigDiff(): array
    {
        $pc = Craft::$app->getProjectConfig();

        return [
            'type' => 'project_config_diff',
            'areChangesPending' => $pc->areChangesPending(),
            'pendingChangesSummary' => $pc->getPendingChangeSummary(),
        ];
    }

    /**
     * Read the last $n lines of a file without loading the whole file
     * into memory. Walks backwards in 8 KiB blocks.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _tail(string $path, int $n): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $buffer = '';
            $chunkSize = 8192;
            $pos = filesize($path);
            $lines = [];

            while ($pos > 0 && count($lines) <= $n) {
                $read = min($chunkSize, $pos);
                $pos -= $read;
                fseek($handle, $pos);
                $buffer = fread($handle, $read) . $buffer;
                $lines = explode("\n", $buffer);
                if ($pos > 0 && count($lines) > $n) {
                    array_shift($lines);
                }
            }

            $lines = array_filter($lines, static fn(string $l): bool => trim($l) !== '');
            return array_slice(array_values($lines), -$n);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse Yii / Craft log lines into structured entries with level
     * filtering. Falls back to `level=info` for lines that don't match
     * the standard prefix so nothing in the tail is silently dropped.
     *
     * @param string[] $lines
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _parseLogLines(array $lines, string $minLevel): array
    {
        $rank = ['trace' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        $threshold = $rank[$minLevel] ?? 2;

        $entries = [];
        foreach ($lines as $line) {
            // Yii log format: "2026-05-06 10:00:00 [info][category] message"
            if (preg_match('/^(\S+ \S+) \[([^\]]+)\]\[([^\]]+)\] (.*)$/s', $line, $m) === 1) {
                $level = strtolower((string) $m[2]);
                if (!isset($rank[$level]) || $rank[$level] < $threshold) {
                    continue;
                }

                $entries[] = [
                    'timestamp' => $m[1],
                    'level' => $level,
                    'category' => $m[3],
                    'message' => trim($m[4]),
                ];
                continue;
            }

            // Unparsed line — only include if threshold is permissive.
            if ($threshold <= ($rank['info'])) {
                $entries[] = [
                    'timestamp' => null,
                    'level' => 'info',
                    'category' => null,
                    'message' => trim($line),
                ];
            }
        }

        return $entries;
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _channel(array $arguments): string
    {
        $channel = $arguments['channel'] ?? null;
        return is_string($channel) && $channel !== '' ? $channel : 'web';
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _minLevel(array $arguments): string
    {
        $level = $arguments['minLevel'] ?? null;
        if (!is_string($level)) {
            return 'warning';
        }

        return in_array($level, ['trace', 'info', 'warning', 'error'], true) ? $level : 'warning';
    }

    /**
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _logPath(string $channel): string
    {
        $base = Craft::$app->getPath()->getLogPath();

        // Normalise the channel — let callers pass either "web" or "web.log".
        $name = str_ends_with($channel, '.log') ? $channel : "{$channel}.log";

        // Strip any path components from the channel before joining with
        // the log directory. `FileHelper::normalizePath` resolves `../`
        // segments, so without `basename()` a caller passing
        // `channel: "../../config/db"` would escape the log directory.
        $name = basename($name);

        return FileHelper::normalizePath("{$base}/{$name}");
    }
}
