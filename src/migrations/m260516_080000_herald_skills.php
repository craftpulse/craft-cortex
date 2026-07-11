<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Gate 8.6 retrofit migration — creates the `herald_skills` table on
 * existing installs.
 *
 * Fresh installs pick the table up via `Install.php`; this numbered
 * migration runs the same DDL against installs that bootstrapped prior
 * to Gate 8.6 landing.
 *
 * Schema:
 *   - id          int PK + FK to elements(id) ON DELETE CASCADE
 *   - handle      string(255) NOT NULL UNIQUE — the natural key
 *   - description string(4096) NULL
 *   - dateCreated dateTime NOT NULL
 *   - dateUpdated dateTime NOT NULL
 *   - uid         uid NOT NULL
 *
 * Indexes: UNIQUE(handle); (dateCreated).
 *
 * Body content is NOT in this table — it's a custom-field value bound
 * to the element's PC-stored field layout, stored via Craft 5's content
 * layer.
 *
 * Idempotent: bails out if the table already exists. `safeDown()`
 * reverses cleanly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260516_080000_herald_skills extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::SKILLS)) {
            return true;
        }

        $this->createTable(Table::SKILLS, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(255)->notNull(),
            'description' => $this->string(4096)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, Table::SKILLS, ['handle'], unique: true);
        $this->createIndex(null, Table::SKILLS, ['dateCreated']);

        $this->addForeignKey(
            null,
            Table::SKILLS,
            ['id'],
            CraftTable::ELEMENTS,
            ['id'],
            'CASCADE',
            null,
        );

        return true;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::SKILLS);
        return true;
    }
}
