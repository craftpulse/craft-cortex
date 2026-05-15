<?php

namespace craftpulse\cortex\services;

use Carbon\Carbon;
use Craft;
use craftpulse\cortex\Plugin;
use craftpulse\cortex\records\RuntimeOverride;
use yii\base\Component;
use yii\base\Exception;

/**
 * =========================================================================
 * Effective command allowlist resolver and runtime-override store.
 *
 * The `craft_command` tool calls `getEffective()` to learn which
 * patterns it may dispatch. The list is the union of:
 *
 *   1. `Settings::$allowedCommands` — defaults baked into the plugin
 *      and overridable from project config or `config/cortex.php`.
 *   2. Active runtime overrides — DB rows that haven't been
 *      soft-deleted and whose `expiresAt` is null or in the future.
 *
 * The CP allowlist UI drives the runtime-override surface — admins
 * add a pattern with an optional note and TTL; cortex grants the
 * pattern until expiry; `pruneExpired()` sweeps expired rows during
 * Craft's gc.
 *
 * Carbon over `DateTimeHelper` here because services rule says:
 * services use Carbon, elements/queries use DateTimeHelper. Mixing in
 * the same class is forbidden.
 * =========================================================================
 *
 * @author Craftpulse
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

    // Private Properties
    // =========================================================================

    /**
     * Per-request memoization of `getActiveOverrides()`. The same
     * request can resolve `getEffective()` multiple times (once for
     * the registry lookup, once for the audit-log line, plus inside
     * `craft_command` itself) — caching trims the DB round-trips
     * without persisting across requests. Mutating methods
     * (`add()` / `remove()` / `pruneExpired()`) reset the cache.
     *
     * @var array<int,array<string,mixed>>|null
     */
    private ?array $_activeOverridesCache = null;

    // Public Methods — Read
    // =========================================================================

    /**
     * Effective allowlist — content-level defaults, plus admin-level
     * defaults when `Craft::$app->getConfig()->getGeneral()->allowAdminChanges`
     * is `true`, plus active runtime overrides, deduplicated.
     *
     * The admin-level merge mirrors the `allowAdminChanges` policy
     * from `docs/plans/gate-8.md` locked decision 14: when the host
     * Craft install forbids admin-level changes, no tool — including
     * `craft_command` — may dispatch a route that mutates project
     * config, schema, or scaffolding. `getEffective()` is the single
     * source of truth for both the `craft_command` dispatch gate and
     * the `get_initial_context` tool's `allowlist` field, so flipping
     * `allowAdminChanges` is visible to both surfaces in lockstep.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getEffective(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $defaults = $settings->allowedCommands;

        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $defaults = array_merge($defaults, $settings->adminLevelCommands);
        }

        $overridePatterns = array_map(
            static fn(array $row): string => (string) $row['pattern'],
            $this->getActiveOverrides(),
        );
        return array_values(array_unique(array_merge($defaults, $overridePatterns)));
    }

    /**
     * Currently-active overrides (unexpired and not soft-deleted).
     * Memoized per-request — mutations invalidate the cache so a
     * single request that adds + reads back gets the up-to-date row.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getActiveOverrides(): array
    {
        if ($this->_activeOverridesCache !== null) {
            return $this->_activeOverridesCache;
        }

        $now = Carbon::now()->toDateTimeString();
        /** @var array<int,array<string,mixed>> $rows */
        $rows = RuntimeOverride::find()
            ->where(['dateDeleted' => null])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]])
            ->orderBy(['expiresAt' => SORT_ASC])
            ->asArray()
            ->all();
        return $this->_activeOverridesCache = $rows;
    }

    /**
     * All non-deleted overrides, optionally including expired ones.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getAllOverrides(bool $includeExpired = true): array
    {
        $query = RuntimeOverride::find()->where(['dateDeleted' => null]);
        if (!$includeExpired) {
            $now = Carbon::now()->toDateTimeString();
            $query->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]]);
        }
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $query->orderBy(['expiresAt' => SORT_ASC])->asArray()->all();
        return $rows;
    }

    // Public Methods — Write
    // =========================================================================

    /**
     * Add a runtime override. Returns the saved record. `ttlSeconds`
     * defaults to `Settings::$runtimeOverrideTtl` when null.
     *
     * @throws Exception when the underlying record fails validation or save.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function add(
        string $pattern,
        ?int $userId = null,
        ?string $note = null,
        ?int $ttlSeconds = null,
    ): RuntimeOverride {
        $ttl = $ttlSeconds ?? Plugin::getInstance()->getSettings()->runtimeOverrideTtl;

        $override = new RuntimeOverride();
        $override->pattern = $pattern;
        $override->note = $note;
        $override->createdByUserId = $userId;
        $override->expiresAt = Carbon::now()->addSeconds($ttl)->toDateTimeString();
        $this->_saveOrThrow($override, 'save', $pattern);
        $this->_activeOverridesCache = null;

        return $override;
    }

    /**
     * Soft-delete an override by id. Returns whether a row was matched.
     *
     * @throws Exception when the underlying record fails to save.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function remove(int $id): bool
    {
        $override = RuntimeOverride::findOne($id);
        if ($override === null) {
            return false;
        }
        $override->dateDeleted = Carbon::now()->toDateTimeString();
        $this->_saveOrThrow($override, 'soft-delete', "#{$id}");
        $this->_activeOverridesCache = null;
        return true;
    }

    /**
     * Hard-delete expired overrides in capped batches. Invoked during
     * Craft's gc sweep via the listener registered in `Plugin::init()`.
     * Returns the number of rows pruned in this call.
     *
     * The cap (`PRUNE_BATCH_LIMIT`) bounds gc's worst-case runtime when
     * an install has accumulated tens of thousands of expired rows —
     * subsequent gc cycles pick up the rest. Without the cap a single
     * gc pass could lock the table for seconds on large installs.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function pruneExpired(): int
    {
        $now = Carbon::now()->toDateTimeString();

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
        $this->_activeOverridesCache = null;
        return $deleted;
    }

    // Private Methods
    // =========================================================================

    /**
     * Persist the override or throw on validation/save failure. Used by
     * `add()` and `remove()` to surface the underlying error summary
     * with consistent wording.
     *
     * @throws Exception
     *
     * @author Craftpulse
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
