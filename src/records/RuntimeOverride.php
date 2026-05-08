<?php

namespace craftpulse\cortex\records;

use craft\db\ActiveRecord;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Active record for the `cortex_runtime_overrides` table.
 *
 * Each row is an admin-issued allowlist pattern that layers on top of
 * the project-config / `config/cortex.php` defaults. Patterns auto-
 * expire (default 7 days, configurable via `Settings::$runtimeOverrideTtl`)
 * so transient grants don't accumulate. Cleanup runs through the
 * `PruneExpiredOverrides` queue job, scheduled via Craft's gc.
 * =========================================================================
 *
 * @property int $id
 * @property string $pattern
 * @property string|null $note
 * @property string|null $expiresAt
 * @property int|null $createdByUserId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string|null $dateDeleted
 * @property string $uid
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class RuntimeOverride extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public static function tableName(): string
    {
        return Table::RUNTIME_OVERRIDES;
    }

    /**
     * @inheritdoc
     *
     * Mirrors the column constraints declared in `Install::safeUp()`
     * at the model layer so save() fails cleanly via validation rather
     * than as a raw DB exception when callers try to insert an empty
     * or oversized pattern.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function rules(): array
    {
        return [
            [['pattern'], 'required'],
            [['pattern', 'note'], 'string', 'max' => 255],
        ];
    }
}
