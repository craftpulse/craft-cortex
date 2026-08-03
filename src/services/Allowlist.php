<?php

namespace craftpulse\herald\services;

use Carbon\Carbon;
use Craft;
use craftpulse\herald\Herald;
use craftpulse\herald\records\RuntimeOverride;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use yii\base\Component;
use yii\base\Exception;
use yii\console\Controller as ConsoleController;
use yii\helpers\Inflector;

/**
 * =========================================================================
 * Effective command allowlist resolver and runtime-override store.
 *
 * The `craft_command` tool calls `getEffective()` to learn which
 * patterns it may dispatch. The list is the union of:
 *
 *   1. `Settings::$allowedCommands` — defaults baked into the plugin
 *      and overridable from project config or `config/herald.php`.
 *   2. Active runtime overrides — DB rows that haven't been
 *      soft-deleted and whose `expiresAt` is null or in the future.
 *
 * The CP allowlist UI drives the runtime-override surface — admins
 * add a pattern with an optional note and TTL; herald grants the
 * pattern until expiry; `pruneExpired()` sweeps expired rows during
 * Craft's gc.
 *
 * Carbon over `DateTimeHelper` here because services rule says:
 * services use Carbon, elements/queries use DateTimeHelper. Mixing in
 * the same class is forbidden.
 * =========================================================================
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class Allowlist extends Component
{
    // Constants
    // =========================================================================

    /**
     * Cap on the number of expired overrides pruned per gc invocation.
     * Re-armed on the next gc cycle, so eventual consistency is
     * preserved without a single sweep blocking other DB work.
     *
     * @since 5.0.0
     */
    public const PRUNE_BATCH_LIMIT = 10000;

    /**
     * Single source of truth for which enumerated console routes are
     * "admin-level" — routes that mutate project config, schema,
     * scaffolding, section/field DDL, or fixtures. A route is admin-level
     * when it `fnmatch`es one of these patterns; everything else is
     * content-level.
     *
     * These mirror the DEFAULT `Settings::$adminLevelCommands` prefixes —
     * `Settings` references this constant for its property default, and
     * the CP command browser uses `isAdminLevelRoute()` (which fnmatches
     * against these patterns) to render the admin section. Declaring the
     * literal list once here prevents drift PHPStan can't detect.
     *
     * NOTE: this is the *classification* set, not the *effective* admin
     * allowlist. An operator can tighten `Settings::$adminLevelCommands`
     * to a subset; the browser still renders a route under the admin
     * section if it matches here, but the route only dispatches when the
     * configured `adminLevelCommands` admits it AND `allowAdminChanges`
     * is true.
     *
     * @var string[]
     *
     * @since 5.0.0
     */
    public const DEFAULT_ADMIN_LEVEL_PATTERNS = [
        'project-config/*',
        // `pc` is Craft's built-in short alias for `project-config`
        // (`PcController extends ProjectConfigController`), so `pc/apply`,
        // `pc/rebuild`, etc. mutate project config identically. It MUST be
        // classified alongside `project-config/*` — otherwise the alias
        // would slip into the content section and bypass the admin gate.
        'pc/*',
        'migrate/*',
        'up',
        'make/*',
        'entrify/*',
        'sections/*',
        'fields/*',
        'fixture/*',
    ];

    /**
     * Cache key under which the enumerated console-command groups are
     * stored. Bumped if the group payload shape ever changes so a stale
     * cache from a prior plugin version can't deserialise into the new
     * reader.
     *
     * @since 5.0.0
     */
    public const COMMAND_GROUPS_CACHE_KEY = 'herald.commandGroups.v1';

    /**
     * TTL (seconds) for the enumerated command-groups cache. One hour —
     * console routes only change when a plugin is installed / removed or
     * Craft is updated, none of which happen mid-session. A short TTL
     * keeps the browser fresh after a `composer require` without a
     * manual cache flush.
     *
     * @since 5.0.0
     */
    public const COMMAND_GROUPS_CACHE_TTL = 3600;

    // Private Properties
    // =========================================================================

    /**
     * Per-request memoization of `getActiveOverrides()`, keyed by the
     * subject-scope string (`'*'` for the global-only view, or the
     * caller's user id). The same request can resolve `getEffective()`
     * multiple times (once for the registry lookup, once for the
     * audit-log line, plus inside `craft_command` itself) — caching trims
     * the DB round-trips without persisting across requests. Mutating
     * methods (`add()` / `remove()` / `pruneExpired()`) reset every key.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $_activeOverridesCache = [];

    // Public Methods — Read
    // =========================================================================

    /**
     * Effective allowlist — content-level defaults, plus admin-level
     * defaults when `Craft::$app->getConfig()->getGeneral()->allowAdminChanges`
     * is `true`, plus active runtime overrides, deduplicated.
     *
     * The admin-level merge mirrors the `allowAdminChanges` policy
     * from the locked `allowAdminChanges` policy: when the host
     * Craft install forbids admin-level changes, no tool — including
     * `craft_command` — may dispatch a route that mutates project
     * config, schema, or scaffolding. `getEffective()` is the single
     * source of truth for both the `craft_command` dispatch gate and
     * the `get_initial_context` tool's `allowlist` field, so flipping
     * `allowAdminChanges` is visible to both surfaces in lockstep.
     *
     * `$userId` scopes the runtime-grant contribution: a global grant
     * (`subjectUserId` null) always applies; a per-user grant applies
     * only when its subject matches `$userId`. Pass the resolved calling
     * user so the dispatch gate honours a grant only for the user it was
     * issued to; pass null (stdio / unidentified caller) to see the
     * global-only view.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getEffective(?int $userId = null): array
    {
        $settings = Herald::getInstance()->getSettings();
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $defaults = $settings->allowedCommands;

        if ($allowAdminChanges) {
            $defaults = array_merge($defaults, $settings->adminLevelCommands);
        }

        $overridePatterns = array_map(
            static fn(array $row): string => (string) $row['pattern'],
            $this->getActiveOverrides($userId),
        );

        // A runtime grant may not advertise an admin-level route while
        // the host forbids admin changes. Grants are stored in the
        // content-level bucket, so without this filter a grant of
        // `migrate/up` showed up as available in `craft_command`'s
        // `list` mode and in `get_initial_context.allowlist` even
        // though the dispatch gate refuses it.
        //
        // Pattern-level classification is best-effort by construction: a
        // deliberately broad grant (`*`) matches no admin-level pattern
        // and therefore still appears here. That is a cosmetic gap only.
        // `CraftCommand::_assertAdminChangesForRoute()` classifies the
        // resolved route at dispatch and is the authoritative gate.
        if (!$allowAdminChanges) {
            $overridePatterns = array_filter(
                $overridePatterns,
                fn(string $pattern): bool => !$this->isAdminLevelRoute($pattern),
            );
        }

        return array_values(array_unique(array_merge($defaults, $overridePatterns)));
    }

    /**
     * Currently-active grants (unexpired and not soft-deleted), scoped to
     * the given subject. A global grant (`subjectUserId` null) is always
     * included; a per-user grant is included only when its subject equals
     * `$userId`. When `$userId` is null (stdio / unidentified caller) only
     * global grants are returned, so a per-user grant can never be used by
     * a caller it was not issued to.
     *
     * Memoized per-request, keyed by subject scope — mutations invalidate
     * every key so a single request that adds + reads back gets the
     * up-to-date row.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getActiveOverrides(?int $userId = null): array
    {
        // Prefix the per-user key so it stays a non-numeric string — a bare
        // numeric string array key would coerce to int and break the
        // declared `array<string,...>` cache shape.
        $key = $userId === null ? '*' : 'u' . $userId;
        if (isset($this->_activeOverridesCache[$key])) {
            return $this->_activeOverridesCache[$key];
        }

        $now = Carbon::now('UTC')->toDateTimeString();
        $query = RuntimeOverride::find()
            ->where(['dateDeleted' => null])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]]);

        if ($userId === null) {
            $query->andWhere(['subjectUserId' => null]);
        } else {
            $query->andWhere(['or', ['subjectUserId' => null], ['subjectUserId' => $userId]]);
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows = $query->orderBy(['expiresAt' => SORT_ASC])->asArray()->all();
        return $this->_activeOverridesCache[$key] = $rows;
    }

    /**
     * All non-deleted overrides, optionally including expired ones.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getAllOverrides(bool $includeExpired = true): array
    {
        $query = RuntimeOverride::find()->where(['dateDeleted' => null]);
        if (!$includeExpired) {
            $now = Carbon::now('UTC')->toDateTimeString();
            $query->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]]);
        }
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $query->orderBy(['expiresAt' => SORT_ASC])->asArray()->all();
        return $rows;
    }

    // Public Methods — Command Enumeration
    // =========================================================================

    /**
     * Every dispatchable console-command route on this install, grouped
     * by controller, for the CP allowlist browser. The list is the union
     * of core Craft commands and the console controllers shipped by every
     * enabled plugin.
     *
     * Enumeration is reflection-driven, NOT shell-driven: a console
     * `Application` cannot be spun up inside a live web request (Craft's
     * bootstrap is single-application per process), and `ConsoleRunner`
     * attaches stream filters to `STDOUT`/`STDERR` — fine under
     * `herald/serve`, corrupting in a CP page render. Instead we walk the
     * same sources Yii's `HelpController::getModuleCommands()` walks —
     * controller directories resolved from known namespaces — and reflect
     * each controller's public `action*` methods exactly as
     * `HelpController::getActions()` does.
     *
     * Result is memoised in `Craft::$app->getCache()` for
     * `COMMAND_GROUPS_CACHE_TTL` — the route list only shifts on a
     * plugin install / Craft update, so a per-request rebuild would be
     * wasted reflection on every CP page load.
     *
     * Shape (keyed by group handle, sorted):
     *
     * ```
     * [
     *   'resave' => [
     *     'group'   => 'resave',
     *     'source'  => 'Craft',
     *     'actions' => [
     *       ['id' => 'resave/entries', 'action' => 'entries'],
     *       ['id' => 'resave/assets',  'action' => 'assets'],
     *       ...
     *     ],
     *   ],
     *   ...
     * ]
     * ```
     *
     * A controller whose only public action is the default (`index`) is
     * surfaced as a single bare-route action whose `id` is the controller
     * handle itself (e.g. `up`, `gc`) — the dispatcher accepts `up` with
     * no trailing segment.
     *
     * @return array<string,array{group:string,source:string,adminLevel:bool,actions:array<int,array{id:string,action:string,adminLevel:bool}>}>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function getCommandGroups(): array
    {
        $cache = Craft::$app->getCache();
        if ($cache === null) {
            return $this->_enumerateCommandGroups();
        }

        $cached = $cache->get(self::COMMAND_GROUPS_CACHE_KEY);
        if (is_array($cached)) {
            /** @var array<string,array{group:string,source:string,adminLevel:bool,actions:array<int,array{id:string,action:string,adminLevel:bool}>}> $cached */
            return $cached;
        }

        $groups = $this->_enumerateCommandGroups();
        $cache->set(self::COMMAND_GROUPS_CACHE_KEY, $groups, self::COMMAND_GROUPS_CACHE_TTL);

        return $groups;
    }

    /**
     * Map the saved content-level and admin-level pattern arrays onto the
     * enumerated group structure so the CP browser can render two
     * sections (content commands + admin-level commands) without
     * re-deriving toggle state in Twig.
     *
     * Each group is mapped against the bucket matching its own
     * `adminLevel` classification: an admin-level group resolves its
     * toggle state from `$adminPatterns`, a content group from
     * `$contentPatterns`. A pattern in the wrong bucket for a group is
     * ignored for that group (it can't toggle a content group from the
     * admin bucket or vice versa) — the security boundary is that a
     * content toggle can never surface an admin route.
     *
     * Returns a tuple:
     *   - `groups`               — `getCommandGroups()` enriched per group
     *                               with a `fullToggle` flag, an
     *                               `allowedCount`, a `totalCount`, the
     *                               `adminLevel` tag, and per-action
     *                               `allowed` flags.
     *   - `contentCustomPatterns` — every content-bucket pattern that does
     *                               NOT resolve to a known content
     *                               group-glob or exact content action id.
     *   - `adminCustomPatterns`   — the same, per the admin bucket.
     *
     * Custom patterns are preserved verbatim and per-bucket so power-user
     * globs (`resave/ent*`) and patterns for since-removed plugins survive
     * a round-trip in the bucket they were saved in, never dropped or
     * moved across buckets.
     *
     * @param string[] $contentPatterns The saved `Settings::$allowedCommands`.
     * @param string[] $adminPatterns   The saved `Settings::$adminLevelCommands`.
     * @return array{groups:array<string,array{group:string,source:string,adminLevel:bool,fullToggle:bool,allowedCount:int,totalCount:int,actions:array<int,array{id:string,action:string,adminLevel:bool,allowed:bool}>}>,contentCustomPatterns:string[],adminCustomPatterns:string[]}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function mapPatternsToToggleState(array $contentPatterns, array $adminPatterns): array
    {
        $groups = $this->getCommandGroups();

        // Index the recognised group-globs + exact action ids per bucket,
        // so a pattern only ever toggles a group of its own class.
        $contentIndex = $this->_indexKnownPatterns($groups, adminLevel: false);
        $adminIndex = $this->_indexKnownPatterns($groups, adminLevel: true);

        $content = $this->_resolveBucket($contentPatterns, $contentIndex);
        $admin = $this->_resolveBucket($adminPatterns, $adminIndex);

        $mappedGroups = [];
        foreach ($groups as $handle => $group) {
            $resolved = $group['adminLevel'] ? $admin : $content;
            $fullToggle = isset($resolved['fullGroups'][$handle]);

            $actions = [];
            $allowedCount = 0;
            foreach ($group['actions'] as $action) {
                $allowed = $fullToggle || isset($resolved['actionIds'][$action['id']]);
                if ($allowed) {
                    $allowedCount++;
                }
                $actions[] = [
                    'id' => $action['id'],
                    'action' => $action['action'],
                    'adminLevel' => $action['adminLevel'],
                    'allowed' => $allowed,
                ];
            }

            $mappedGroups[$handle] = [
                'group' => $group['group'],
                'source' => $group['source'],
                'adminLevel' => $group['adminLevel'],
                'fullToggle' => $fullToggle,
                'allowedCount' => $allowedCount,
                'totalCount' => count($actions),
                'actions' => $actions,
            ];
        }

        return [
            'groups' => $mappedGroups,
            'contentCustomPatterns' => $content['customPatterns'],
            'adminCustomPatterns' => $admin['customPatterns'],
        ];
    }

    /**
     * Inverse of `mapPatternsToToggleState()` for a single bucket — fold
     * the browser's posted toggle state back into a flat pattern array.
     * The controller calls this twice (once per bucket) so each setting
     * (`allowedCommands` / `adminLevelCommands`) is reconstructed from the
     * toggles of its own class:
     *
     *   - A fully-toggled group collapses to one group glob — `group/*`,
     *     or the bare handle when the group's only route is the bare
     *     default-action route (so `up`, not `up/*`, which would never
     *     `fnmatch` the bare `up` route).
     *   - A partially-toggled group emits one exact id per checked action.
     *   - Custom patterns are appended verbatim.
     *
     * `$adminLevel` selects which groups are eligible: only groups whose
     * classification matches are folded, so a posted admin-group toggle
     * can never leak into the content bucket (defense in depth — the
     * controller already posts per-bucket payloads, but the service
     * refuses to cross the boundary regardless).
     *
     * Unknown group / action ids in the posted payload are ignored.
     *
     * @param array<string,mixed> $fullGroups     Group handles flagged fully-on (`{handle: '1'}`).
     * @param array<string,mixed> $actionIds      Exact action ids flagged on (`{id: '1'}`).
     * @param string[]            $customPatterns Verbatim power-user globs.
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function patternsFromToggleState(array $fullGroups, array $actionIds, array $customPatterns, bool $adminLevel): array
    {
        $groups = $this->getCommandGroups();

        $patterns = [];
        foreach (array_keys($fullGroups) as $handle) {
            $handle = (string) $handle;
            if (isset($groups[$handle]) && $groups[$handle]['adminLevel'] === $adminLevel) {
                $patterns[] = $this->_groupGlobFor($groups[$handle]);
            }
        }

        // A fully-toggled group already covers its actions via the glob;
        // skip exact ids that belong to a fully-toggled group so the saved
        // list stays minimal. Map each action id to its group handle +
        // class so a cross-bucket id is dropped.
        $knownActionHandles = [];
        foreach ($groups as $handle => $group) {
            if ($group['adminLevel'] !== $adminLevel) {
                continue;
            }
            foreach ($group['actions'] as $action) {
                $knownActionHandles[$action['id']] = $handle;
            }
        }

        foreach (array_keys($actionIds) as $id) {
            $id = (string) $id;
            if (!isset($knownActionHandles[$id])) {
                continue;
            }
            if (isset($fullGroups[$knownActionHandles[$id]])) {
                continue;
            }
            $patterns[] = $id;
        }

        foreach ($customPatterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * Index the recognised group-globs and exact action ids of the groups
     * matching the given classification, so `_resolveBucket()` can detect
     * an unrecognised pattern in O(1). The group-glob for a group is
     * whatever `_groupGlobFor()` returns (`group/*`, or the bare handle
     * for a bare-only group), keyed to its handle.
     *
     * @param array<string,array{group:string,source:string,adminLevel:bool,actions:array<int,array{id:string,action:string,adminLevel:bool}>}> $groups
     * @return array{groupGlobs:array<string,string>,actionIds:array<string,string>}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _indexKnownPatterns(array $groups, bool $adminLevel): array
    {
        $groupGlobs = [];
        $actionIds = [];
        foreach ($groups as $handle => $group) {
            if ($group['adminLevel'] !== $adminLevel) {
                continue;
            }
            $groupGlobs[$this->_groupGlobFor($group)] = $handle;
            foreach ($group['actions'] as $action) {
                $actionIds[$action['id']] = $handle;
            }
        }

        return ['groupGlobs' => $groupGlobs, 'actionIds' => $actionIds];
    }

    /**
     * Resolve a bucket's flat pattern array against its known-pattern
     * index, splitting into fully-toggled group handles, exact allowed
     * action ids, and verbatim custom patterns. A pattern that matches no
     * known group-glob or exact id in THIS bucket falls into
     * `customPatterns` — never silently dropped, never moved across
     * buckets.
     *
     * @param string[]                                                        $patterns
     * @param array{groupGlobs:array<string,string>,actionIds:array<string,string>} $index
     * @return array{fullGroups:array<string,bool>,actionIds:array<string,bool>,customPatterns:string[]}
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _resolveBucket(array $patterns, array $index): array
    {
        $fullGroups = [];
        $actionIds = [];
        $customPatterns = [];
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            if (isset($index['groupGlobs'][$pattern])) {
                $fullGroups[$index['groupGlobs'][$pattern]] = true;
                continue;
            }
            if (isset($index['actionIds'][$pattern])) {
                $actionIds[$pattern] = true;
                continue;
            }
            $customPatterns[] = $pattern;
        }

        return [
            'fullGroups' => $fullGroups,
            'actionIds' => $actionIds,
            'customPatterns' => array_values(array_unique($customPatterns)),
        ];
    }

    /**
     * The glob a fully-toggled group collapses to. Normally `{handle}/*`,
     * but a group whose only route is the bare default-action route (route
     * id == handle, no slash, e.g. `up`) collapses to the bare handle
     * itself — `up/*` would never `fnmatch` the bare `up` route, so the
     * toggle would grant nothing.
     *
     * @param array{group:string,actions:array<int,array{id:string,action:string,adminLevel:bool}>} $group
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _groupGlobFor(array $group): string
    {
        $handle = $group['group'];
        $ids = array_column($group['actions'], 'id');

        if ($ids === [$handle]) {
            return $handle;
        }

        return $handle . '/*';
    }

    // Public Methods — Write
    // =========================================================================

    /**
     * Add a runtime grant. Returns the saved record. `ttlSeconds`
     * defaults to `Settings::getRuntimeOverrideTtl()` when null.
     *
     * `$subjectUserId` names the user the grant applies to: null issues a
     * GLOBAL grant (applies to every caller, the legacy semantics); a
     * non-null value scopes the grant to exactly that user, so the
     * dispatch gate honours it only for them. `$userId` is the grantor
     * (recorded on `createdByUserId` for the audit trail), distinct from
     * the subject.
     *
     * @throws Exception when the underlying record fails validation or save.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function add(
        string $pattern,
        ?int $userId = null,
        ?string $note = null,
        ?int $ttlSeconds = null,
        ?int $subjectUserId = null,
    ): RuntimeOverride {
        $ttl = $ttlSeconds ?? Herald::getInstance()->getSettings()->getRuntimeOverrideTtl();

        $override = new RuntimeOverride();
        $override->pattern = $pattern;
        $override->note = $note;
        $override->createdByUserId = $userId;
        $override->subjectUserId = $subjectUserId;
        $override->expiresAt = Carbon::now('UTC')->addSeconds($ttl)->toDateTimeString();
        $this->_saveOrThrow($override, 'save', $pattern);
        $this->_activeOverridesCache = [];

        return $override;
    }

    /**
     * Soft-delete an override by id. Returns whether a row was matched.
     *
     * @throws Exception when the underlying record fails to save.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function remove(int $id): bool
    {
        $override = RuntimeOverride::findOne($id);
        if ($override === null) {
            return false;
        }
        $override->dateDeleted = Carbon::now('UTC')->toDateTimeString();
        $this->_saveOrThrow($override, 'soft-delete', "#{$id}");
        $this->_activeOverridesCache = [];
        return true;
    }

    /**
     * Hard-delete expired overrides in capped batches. Invoked during
     * Craft's gc sweep via the listener registered in `Herald::init()`.
     * Returns the number of rows pruned in this call.
     *
     * The cap (`PRUNE_BATCH_LIMIT`) bounds gc's worst-case runtime when
     * an install has accumulated tens of thousands of expired rows —
     * subsequent gc cycles pick up the rest. Without the cap a single
     * gc pass could lock the table for seconds on large installs.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function pruneExpired(): int
    {
        $now = Carbon::now('UTC')->toDateTimeString();

        // `deleteAll` has no built-in LIMIT, so we select the next batch
        // of expired ids and delete by primary key. Indexed lookup +
        // bounded round-trip cost.
        $ids = RuntimeOverride::find()
            ->select(['id'])
            ->where([
                'and',
                ['dateDeleted' => null],
                ['<', 'expiresAt', $now],
            ])
            ->limit(self::PRUNE_BATCH_LIMIT)
            ->column();

        if ($ids === []) {
            return 0;
        }

        $deleted = RuntimeOverride::deleteAll(['id' => $ids]);
        $this->_activeOverridesCache = [];
        return $deleted;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reflection-driven enumeration of every console-command group.
     * Walks Craft's core console controllers plus each enabled plugin's
     * `console\controllers` namespace, reflecting public `action*`
     * methods exactly as Yii's `HelpController::getActions()` does.
     *
     * @return array<string,array{group:string,source:string,adminLevel:bool,actions:array<int,array{id:string,action:string,adminLevel:bool}>}>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _enumerateCommandGroups(): array
    {
        /** @var array<string,array{source:string,actions:array<string,array{id:string,action:string}>}> $groups */
        $groups = [];

        // Core Craft commands live under `craft\console\controllers`,
        // resolved to a directory via the `@craft` alias.
        $this->_collectControllers(
            namespace: 'craft\\console\\controllers',
            directory: (string) Craft::getAlias('@craft/console/controllers'),
            source: 'Craft',
            commandPrefix: '',
            groups: $groups,
        );

        // A handful of core commands are registered by id via the console
        // Application's `coreCommands()` map rather than discovered from a
        // matching `*Controller.php` filename — `cache` maps to Yii's
        // `CacheController`, which lives outside the scanned Craft
        // directory. Register those explicitly so `cache/*` and friends
        // appear as real groups (the bundled default allowlist ships
        // `cache/*`).
        foreach ($this->_coreCommandAliases() as $id => $class) {
            $this->_collectActionsForRoute($id, $class, 'Craft', $groups);
        }

        // Each enabled plugin contributes its own console controllers,
        // namespaced `{baseNamespace}\console\controllers` per
        // `craft\base\Plugin::init()`. The web request never sets the
        // plugin's console `controllerNamespace`, so we derive it from the
        // plugin class's namespace rather than reading `$plugin->controllerNamespace`.
        foreach (Craft::$app->getPlugins()->getAllPlugins() as $plugin) {
            $baseNamespace = $this->_baseNamespace($plugin::class);
            if ($baseNamespace === null) {
                continue;
            }
            $directory = $this->_pluginConsoleDirectory($plugin::class);
            if ($directory === null || !is_dir($directory)) {
                continue;
            }
            $this->_collectControllers(
                namespace: $baseNamespace . '\\console\\controllers',
                directory: $directory,
                source: (string) $plugin->name,
                commandPrefix: $plugin->id . '/',
                groups: $groups,
            );
        }

        // Finalise: sort groups by handle, sort actions by id, drop the
        // associative action index in favour of a stable list, and tag
        // each action + the group with its admin-level classification so
        // the CP browser can render two sections (content vs admin-level).
        ksort($groups);
        $result = [];
        foreach ($groups as $handle => $group) {
            $actions = array_values($group['actions']);
            usort($actions, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));

            $taggedActions = [];
            $groupAdminLevel = false;
            foreach ($actions as $action) {
                $adminLevel = $this->isAdminLevelRoute($action['id']);
                $groupAdminLevel = $groupAdminLevel || $adminLevel;
                $taggedActions[] = [
                    'id' => $action['id'],
                    'action' => $action['action'],
                    'adminLevel' => $adminLevel,
                ];
            }

            $result[$handle] = [
                'group' => $handle,
                'source' => $group['source'],
                'adminLevel' => $groupAdminLevel,
                'actions' => $taggedActions,
            ];
        }

        return $result;
    }

    /**
     * Whether a console route id is "admin-level" — i.e. it `fnmatch`es
     * one of `DEFAULT_ADMIN_LEVEL_PATTERNS` (project config, migrations,
     * scaffolding, section/field DDL, fixtures). The CP command browser
     * uses this to split the enumerated routes into a "Content commands"
     * section (folds to `Settings::$allowedCommands`) and an
     * "Admin-level commands" section (folds to
     * `Settings::$adminLevelCommands`, gated by `allowAdminChanges`).
     *
     * Classification is fixed in code — it does NOT read the operator's
     * configured `adminLevelCommands`, so tightening that setting can't
     * silently re-class a `migrate/*` route as content-level and slip it
     * into the always-admitted bucket.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    public function isAdminLevelRoute(string $routeId): bool
    {
        foreach (self::DEFAULT_ADMIN_LEVEL_PATTERNS as $pattern) {
            if (fnmatch($pattern, $routeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reflect every `*Controller` under a namespace + directory and fold
     * its public `action*` methods into the `$groups` accumulator. A
     * controller exposing only the default `index` action contributes a
     * single bare-route action whose id is the controller handle itself
     * (core), or `{prefix}{controller}` for a plugin command.
     *
     * `$commandPrefix` is the Yii module command prefix — empty for core
     * Craft commands, `{pluginHandle}/` for plugin commands. It mirrors
     * `HelpController::getModuleCommands()`'s `$module->getUniqueId() . '/'`
     * prefix so the enumerated route ids match what the dispatcher and the
     * `fnmatch` allowlist actually see (`herald/serve`, not `serve`).
     *
     * @param array<string,array{source:string,actions:array<string,array{id:string,action:string}>}> $groups
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _collectControllers(
        string $namespace,
        string $directory,
        string $source,
        string $commandPrefix,
        array &$groups,
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !str_ends_with($file->getFilename(), 'Controller.php')) {
                continue;
            }

            $relative = trim(str_replace($directory, '', $file->getPath()), '/\\');
            $subNamespace = $relative !== '' ? '\\' . str_replace('/', '\\', $relative) : '';
            $class = $namespace . $subNamespace . '\\' . $file->getBasename('.php');

            $controllerId = $this->_controllerIdFromClass($file->getBasename('.php'));
            if ($relative !== '') {
                $controllerId = Inflector::camel2id(str_replace('/', '-', $relative), '-') . '/' . $controllerId;
            }
            $controllerId = $commandPrefix . $controllerId;

            $actions = $this->_reflectActions($class);
            if ($actions === []) {
                continue;
            }

            $handle = $this->_groupHandleFromRouteId($controllerId);
            if (!isset($groups[$handle])) {
                $groups[$handle] = ['source' => $source, 'actions' => []];
            }

            $this->_foldActions($controllerId, $actions, $this->_defaultActionId($class), $groups[$handle]['actions']);
        }
    }

    /**
     * Core console commands registered by id through the console
     * Application's `coreCommands()` map rather than discovered from a
     * `*Controller.php` filename in the scanned directory. `migrate` and
     * `help` are intentionally omitted — `migrate` is found by file scan
     * (Craft ships `MigrateController.php`), and `help` is the internal
     * help command, not an operator-dispatchable route worth allowlisting.
     *
     * @return array<string,class-string>
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _coreCommandAliases(): array
    {
        return [
            'cache' => \yii\console\controllers\CacheController::class,
        ];
    }

    /**
     * Reflect a single controller class under an explicit route id (a
     * `coreCommands()` alias) and fold its actions into `$groups`. Mirrors
     * the per-controller fold in `_collectControllers()` but skips the
     * directory-derived id since the route id is fixed by the alias map.
     *
     * @param class-string $class
     * @param array<string,array{source:string,actions:array<string,array{id:string,action:string}>}> $groups
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _collectActionsForRoute(string $id, string $class, string $source, array &$groups): void
    {
        $actions = $this->_reflectActions($class);
        if ($actions === []) {
            return;
        }

        $handle = $this->_groupHandleFromRouteId($id);
        if (!isset($groups[$handle])) {
            $groups[$handle] = ['source' => $source, 'actions' => []];
        }

        $this->_foldActions($id, $actions, $this->_defaultActionId($class), $groups[$handle]['actions']);
    }

    /**
     * Fold a controller's reflected action ids into the per-group action
     * accumulator, keyed by route id. Centralises the route-id rules so
     * the file-scan path and the `coreCommands()`-alias path agree:
     *
     *   - The default `index` action dispatches as the bare controller id
     *     (`up`, or `herald/serve` under a plugin prefix).
     *   - A non-`index` action that IS the controller's `$defaultAction`
     *     (e.g. `GcController::$defaultAction = 'run'`) is registered
     *     under BOTH its explicit `{controllerId}/{action}` route AND a
     *     bare-handle alias (`gc`), because the dispatcher accepts the
     *     bare handle and the shipped default allowlist names it bare.
     *   - Every other action as `{controllerId}/{action}`.
     *
     * @param string[]                                      $actions
     * @param array<string,array{id:string,action:string}> $accumulator
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _foldActions(string $controllerId, array $actions, string $defaultAction, array &$accumulator): void
    {
        $bare = !str_contains($controllerId, '/');

        foreach ($actions as $actionId) {
            if ($actionId === 'index') {
                $accumulator[$controllerId] = [
                    'id' => $controllerId,
                    'action' => $bare ? $controllerId : $actionId,
                ];
                continue;
            }

            $routeId = $controllerId . '/' . $actionId;
            $accumulator[$routeId] = ['id' => $routeId, 'action' => $actionId];

            // A non-index default action also dispatches bare (`gc`
            // → gc/run). Register the bare alias so the shipped `gc`
            // default pattern maps to its group toggle instead of
            // landing in custom patterns.
            if ($actionId === $defaultAction && $bare) {
                $accumulator[$controllerId] = ['id' => $controllerId, 'action' => $controllerId];
            }
        }
    }

    /**
     * The controller's default action id (dash-cased), read from its
     * `$defaultAction` property. Yii defaults this to `index`; controllers
     * like `GcController` set it to `run` so a bare `gc` dispatches to
     * `gc/run`. Returns `index` when the class can't be reflected — the
     * conservative default that registers no extra bare alias.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _defaultActionId(string $class): string
    {
        try {
            if (!class_exists($class)) {
                return 'index';
            }
            $default = (new ReflectionClass($class))->getDefaultProperties()['defaultAction'] ?? 'index';
        } catch (Throwable) {
            return 'index';
        }

        return is_string($default) && $default !== '' ? Inflector::camel2id($default) : 'index';
    }

    /**
     * Reflect the public action ids of a console-controller class —
     * `action*()` methods (minus the `action` prefix, camel-cased to an
     * id) plus any keys returned by `actions()`. Mirrors
     * `HelpController::getActions()`. Returns an empty array for a class
     * that fails to load, is abstract, or is not a console controller, so
     * a broken plugin can never abort the whole enumeration.
     *
     * @return string[]
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _reflectActions(string $class): array
    {
        try {
            if (!class_exists($class)) {
                return [];
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(ConsoleController::class)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $actions = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($name !== 'actions' && !$method->isStatic() && str_starts_with($name, 'action')) {
                $actions[] = Inflector::camel2id(substr($name, 6));
            }
        }

        return array_values(array_unique(array_filter(
            $actions,
            static fn(string $id): bool => $id !== '',
        )));
    }

    /**
     * Group handle for a route id — everything before the final `/`
     * segment, or the whole id for a bare default-action route. `resave/entries`
     * groups under `resave`; `up` groups under `up`. Centralised so the
     * forward (`mapPatternsToToggleState`) and inverse
     * (`patternsFromToggleState`) mappings agree on the rule.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _groupHandleFromRouteId(string $routeId): string
    {
        $slash = strpos($routeId, '/');
        return $slash === false ? $routeId : substr($routeId, 0, $slash);
    }

    /**
     * Controller id (lower-case, dash-separated) from a `FooBarController`
     * class basename. `ResaveController` → `resave`,
     * `ClearCachesController` → `clear-caches`. Mirrors
     * `HelpController::getModuleCommands()`'s `camel2id(...)` call.
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _controllerIdFromClass(string $basename): string
    {
        return Inflector::camel2id(substr($basename, 0, -strlen('Controller')), '-', true);
    }

    /**
     * Top-level package namespace for a plugin class — the segment before
     * `\` boundaries that holds `console\controllers`. For
     * `craftpulse\herald\Herald` returns `craftpulse\herald`. Returns null
     * when the class is not namespaced (defensive — every Craft plugin is).
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _baseNamespace(string $class): ?string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? null : substr($class, 0, $pos);
    }

    /**
     * On-disk `console/controllers` directory for a plugin, derived from
     * the plugin class's own file location via reflection. Composer's
     * PSR-4 map is the authority here — Yii aliases are NOT auto-derived
     * from PSR-4 prefixes, so a plugin's `@vendor/handle` alias is usually
     * unregistered and `Craft::getAlias()` would fail. The plugin class
     * sits at `{root}/src/Foo.php`, so its directory is the package source
     * root; `console/controllers` hangs directly off it. Returns null when
     * the class file path cannot be resolved (internal classes).
     *
     * @param class-string $pluginClass
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _pluginConsoleDirectory(string $pluginClass): ?string
    {
        $reflection = new ReflectionClass($pluginClass);

        $file = $reflection->getFileName();
        if ($file === false) {
            return null;
        }

        return dirname($file) . '/console/controllers';
    }

    /**
     * Persist the override or throw on validation/save failure. Used by
     * `add()` and `remove()` to surface the underlying error summary
     * with consistent wording.
     *
     * @throws Exception
     *
     * @author CraftPulse
     * @since  5.0.0
     */
    private function _saveOrThrow(RuntimeOverride $override, string $action, string $context): void
    {
        if ($override->save()) {
            return;
        }
        throw new Exception(sprintf(
            'Failed to %s runtime override %s: %s',
            $action,
            $context,
            implode(', ', $override->getFirstErrors()),
        ));
    }
}
