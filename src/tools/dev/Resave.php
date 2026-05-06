<?php

namespace craftpulse\cortex\tools\dev;

use craftpulse\cortex\tools\AbstractTool;
use craftpulse\cortex\tools\support\ConsoleRunner;
use craftpulse\cortex\tools\ToolException;

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
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Resave extends AbstractTool
{
    // Constants
    // =========================================================================

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
     * @inheritdoc
     *
     * Mutating action — clients that respect `destructiveHint` will
     * prompt the user before invoking. Even though resaves are
     * recoverable, they touch every matched element and trigger
     * downstream events (search re-index, propagation, plugin hooks).
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getAnnotations(): array
    {
        return [
            'title' => 'Resave Elements',
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function getName(): string
    {
        return 'resave';
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
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
            'output, and any error.';
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
                    'enum' => array_keys(self::TYPE_ROUTES),
                    'description' => 'Element kind to resave. Required.',
                ],
                'section' => ['type' => 'string', 'description' => 'For type=entries. Comma-separated handles or `*` for all.'],
                'entryType' => ['type' => 'string', 'description' => 'For type=entries. Comma-separated entry-type handles.'],
                'group' => ['type' => 'string', 'description' => 'For type=categories|tags|users.'],
                'volume' => ['type' => 'string', 'description' => 'For type=assets. Comma-separated volume handles.'],
                'status' => ['type' => 'string', 'description' => 'Element status filter (default `any`).'],
                'limit' => ['type' => 'integer', 'minimum' => 1],
                'set' => ['type' => 'string', 'description' => 'Field handle to rewrite. Pair with `to`.'],
                'to' => ['type' => 'string', 'description' => 'Replacement expression. See Craft resave docs.'],
                'ifEmpty' => ['type' => 'boolean'],
                'ifInvalid' => ['type' => 'boolean'],
                'touch' => ['type' => 'boolean'],
                'updateSearchIndex' => ['type' => 'boolean'],
                'propagateTo' => ['type' => 'string'],
                'queue' => ['type' => 'boolean', 'description' => 'Dispatch as a background queue job instead of running in-process.'],
                'batchSize' => ['type' => 'integer', 'minimum' => 1],
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

    // Private Methods
    // =========================================================================

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
     * @since  0.1.0
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
            array_keys(array_filter($arguments, fn ($k) => !in_array($k, ['type'], true), ARRAY_FILTER_USE_KEY)),
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
