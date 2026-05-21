<?php

namespace craftpulse\cortex\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craftpulse\cortex\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * =========================================================================
 * Active record for the `cortex_skills` table.
 *
 * One row per element-stored custom skill — the Cortex-owned write-side
 * counterpart to the bundled `michtio/craftcms-claude-skills` corpus.
 * `handle` is a globally-unique natural key; `description` lives as a
 * native column so list-view paths skip the content-table join. The
 * body lives in the PC-stored field layout on the element.
 *
 * FK to `elements(id)` ON DELETE CASCADE — hard-deleting the underlying
 * element wipes this row automatically. Soft-deletes leave the row in
 * place (Craft's normal pattern for tag / category / address records).
 * =========================================================================
 *
 * @property int $id
 * @property string $handle
 * @property string|null $description
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Skill extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function tableName(): string
    {
        return Table::SKILLS;
    }

    /**
     * Returns the skill's underlying element row. Mirrors the
     * `craft\records\Tag::getElement()` pattern — the FK to
     * `elements(id)` is one-to-one, so a `hasOne` relation surfaces it
     * to consumers that need to traverse from the cortex-specific
     * record back to Craft's element row.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    /**
     * @inheritdoc
     *
     * Mirrors the column constraints declared in `Install::safeUp()` at
     * the model layer so `save()` fails cleanly via validation rather
     * than as a raw DB exception when callers try to insert an empty
     * or oversized handle. Handle uniqueness is enforced by the DB
     * UNIQUE index; the validator runs first so consumers get a
     * Yii-shaped error envelope before hitting the integrity-violation
     * surface.
     *
     * @return array<int,array<int|string,mixed>>
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['handle'], 'required'],
            [['handle'], 'string', 'max' => 255],
            [['handle'], 'unique'],
            [['description'], 'string', 'max' => 4096],
        ];
    }
}
