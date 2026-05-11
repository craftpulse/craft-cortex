<?php

namespace craftpulse\cortex\services;

use Carbon\Carbon;
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
 * pattern until expiry; the queue cleanup job (`PruneExpiredOverrides`)
 * sweeps expired rows nightly via Craft gc.
 *
 * Carbon over `DateTimeHelper` here because services rule says:
 * services use Carbon, elements/queries use DateTimeHelper. Mixing in
 * the same class is forbidden.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Allowlist extends Component
{
    // Public Methods — Read
    // =========================================================================

    /**
     * Effective allowlist — defaults union active overrides, deduplicated.
     *
     * @return string[]
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getEffective(): array
    {
        $defaults = Plugin::getInstance()->getSettings()->allowedCommands;
        $overridePatterns = array_map(
            static fn(array $row): string => (string) $row['pattern'],
            $this->getActiveOverrides(),
        );
        return array_values(array_unique(array_merge($defaults, $overridePatterns)));
    }

    /**
     * Currently-active overrides (unexpired and not soft-deleted).
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function getActiveOverrides(): array
    {
        $now = Carbon::now()->toDateTimeString();
        /** @var array<int,array<string,mixed>> $rows */
        $rows = RuntimeOverride::find()
            ->where(['dateDeleted' => null])
            ->andWhere(['or', ['expiresAt' => null], ['>', 'expiresAt', $now]])
            ->orderBy(['expiresAt' => SORT_ASC])
            ->asArray()
            ->all();
        return $rows;
    }

    /**
     * All non-deleted overrides, optionally including expired ones.
     *
     * @return array<int,array<string,mixed>>
     *
     * @author Craftpulse
     * @since  0.1.0
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
     * @since  0.1.0
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
        if (!$override->save()) {
            throw new Exception(sprintf(
                'Failed to save runtime override "%s": %s',
                $pattern,
                implode(', ', $override->getErrorSummary(true)),
            ));
        }

        return $override;
    }

    /**
     * Soft-delete an override by id. Returns whether a row was matched.
     *
     * @throws Exception when the underlying record fails to save.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function remove(int $id): bool
    {
        $override = RuntimeOverride::findOne($id);
        if ($override === null) {
            return false;
        }
        $override->dateDeleted = Carbon::now()->toDateTimeString();
        if (!$override->save()) {
            throw new Exception(sprintf(
                'Failed to soft-delete runtime override #%d: %s',
                $id,
                implode(', ', $override->getErrorSummary(true)),
            ));
        }
        return true;
    }

    /**
     * Hard-delete every expired override. Used by `PruneExpiredOverrides`.
     * Returns the number of rows pruned.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function pruneExpired(): int
    {
        $now = Carbon::now()->toDateTimeString();
        return RuntimeOverride::deleteAll([
            'and',
            ['dateDeleted' => null],
            ['<', 'expiresAt', $now],
        ]);
    }
}
