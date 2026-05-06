<?php

namespace craftpulse\cortex\tools\system;

use Craft;
use craft\helpers\FileHelper;
use craft\queue\Queue;
use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\ToolException;
use DateTimeInterface;

/**
 * =========================================================================
 * `diagnostics` tool — every "what's wrong?" surface in one read.
 *
 * Multi-mode tool that consolidates five independent diagnostics
 * surfaces — logs, last error, deprecations, queue jobs, project-config
 * diff — that the AI almost always wants in sequence. Single tool, one
 * `type` param, capped result size to keep responses bounded.
 *
 * Modes:
 *   - `logs` — last N lines of `storage/logs/*.log`. Filterable by
 *     log file (`channel`) and minimum level (`error|warning|info|trace`).
 *   - `last_error` — most recent error from the active log channel.
 *   - `deprecations` — `Deprecator::getLogs()`.
 *   - `queue` — pending / reserved / failed / done counts plus the most
 *     recent N jobs of each.
 *   - `project_config_diff` — `areChangesPending()` summary.
 *
 * Default `limit` is 50 items per mode; hard-cap 500.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Diagnostics extends AbstractTool
{
    // Constants
    // =========================================================================

    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'diagnostics';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getDescription(): string
    {
        return 'Combined diagnostics surface: logs, last_error, deprecations, queue jobs, ' .
            'and project_config_diff. Pick one via `type`. Returns at most `limit` items ' .
            '(default 50, max 500).';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['logs', 'last_error', 'deprecations', 'queue', 'project_config_diff'],
                    'description' => 'Required.',
                ],
                'channel' => [
                    'type' => 'string',
                    'description' => 'Log file basename (e.g. "web", "queue"). Defaults to "web".',
                ],
                'minLevel' => [
                    'type' => 'string',
                    'enum' => ['trace', 'info', 'warning', 'error'],
                    'description' => 'Minimum severity to include. Defaults to "warning".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                ],
            ],
            'required' => ['type'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function execute(array $arguments): array
    {
        $type = $arguments['type'] ?? null;
        if (!is_string($type) || $type === '') {
            throw new ToolException('`type` is required (logs / last_error / deprecations / queue / project_config_diff).');
        }

        $limit = $this->_limit($arguments);

        return match ($type) {
            'logs' => $this->_logs($arguments, $limit),
            'last_error' => $this->_lastError($arguments),
            'deprecations' => $this->_deprecations($limit),
            'queue' => $this->_queue($limit),
            'project_config_diff' => $this->_projectConfigDiff(),
            default => throw new ToolException("Unknown type: '{$type}'."),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * @return array<string,mixed>
     *
     * @throws ToolException
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _deprecations(int $limit): array
    {
        $logs = Craft::$app->getDeprecator()->getLogs();

        $entries = array_slice($logs, 0, $limit);

        return [
            'type' => 'deprecations',
            'entries' => array_map(
                static fn ($d): array => [
                    'key' => $d->key,
                    'message' => $d->message,
                    'fingerprint' => $d->fingerprint,
                    'file' => $d->file,
                    'line' => $d->line,
                    'lastOccurrence' => $d->lastOccurrence?->format(DateTimeInterface::ATOM),
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
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _queue(int $limit): array
    {
        $queue = Craft::$app->getQueue();

        // Counts: Craft's Queue exposes these. (`getTotalDone()` doesn't
        // exist in Craft 5 — done jobs aren't kept; ttr removes them.)
        $totals = [];
        if ($queue instanceof Queue) {
            $totals = [
                'waiting' => $queue->getTotalWaiting(),
                'delayed' => $queue->getTotalDelayed(),
                'reserved' => $queue->getTotalReserved(),
                'failed' => $queue->getTotalFailed(),
                'total' => $queue->getTotalJobs(),
            ];
        }

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
     * @return array<string,mixed>
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
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

            $lines = array_filter($lines, static fn (string $l): bool => trim($l) !== '');
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
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _channel(array $arguments): string
    {
        $channel = $arguments['channel'] ?? null;
        return is_string($channel) && $channel !== '' ? $channel : 'web';
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
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
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _logPath(string $channel): string
    {
        $base = Craft::$app->getPath()->getLogPath();

        // Normalise the channel — let callers pass either "web" or "web.log".
        $name = str_ends_with($channel, '.log') ? $channel : "{$channel}.log";

        return FileHelper::normalizePath("{$base}/{$name}");
    }

    /**
     * @author Craftpulse
     * @since  0.1.0
     */
    private function _limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        return max(1, min(self::MAX_LIMIT, $limit));
    }
}
