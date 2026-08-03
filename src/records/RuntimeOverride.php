<?php

namespace craftpulse\herald\records;

use craft\db\ActiveRecord;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Active record for the `herald_runtime_overrides` table.
 *
 * Each row is an admin-issued allowlist pattern that layers on top of
 * the project-config / `config/herald.php` defaults. Patterns auto-
 * expire (default 7 days, configurable via `Settings::$runtimeOverrideTtl`)
 * so transient grants don't accumulate. Cleanup runs inline during
 * Craft's gc sweep via `Allowlist::pruneExpired()`.
 * =========================================================================
 *
 * @property int $id
 * @property string $pattern
 * @property string|null $note
 * @property string|null $expiresAt
 * @property int|null $createdByUserId
 * @property int|null $subjectUserId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string|null $dateDeleted
 * @property string $uid
 *
 * @author CraftPulse
 * @since  5.0.0
 */
class RuntimeOverride extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author CraftPulse
     * @since  5.0.0
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
     * @author CraftPulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['pattern'], 'required'],
            [['pattern', 'note'], 'string', 'max' => 255],
        ];
    }
}
