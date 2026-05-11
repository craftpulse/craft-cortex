<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Cortex install migration — creates the runtime-overrides table.
 *
 * Ships with one table: `{{%cortex_runtime_overrides}}` for
 * admin-editable allowlist patterns that layer on top of the
 * project-config defaults and `config/cortex.php` overrides. Each
 * override has an explicit `expiresAt` (default 7 days, configurable
 * per `Settings::$runtimeOverrideTtl`) so transient grants don't
 * accumulate indefinitely.
 *
 * Idempotent: bails out if the table already exists. `safeDown()`
 * reverses cleanly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  0.1.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::RUNTIME_OVERRIDES)) {
            return true;
        }

        $this->createTable(Table::RUNTIME_OVERRIDES, [
            'id' => $this->primaryKey(),
            'pattern' => $this->string(255)->notNull(),
            'note' => $this->string(255)->null(),
            'expiresAt' => $this->dateTime()->null(),
            'createdByUserId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['pattern']);
        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['expiresAt']);
        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['dateDeleted']);

        $this->addForeignKey(
            null,
            Table::RUNTIME_OVERRIDES,
            ['createdByUserId'],
            CraftTable::USERS,
            ['id'],
            'SET NULL',
            null,
        );

        return true;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::RUNTIME_OVERRIDES);
        return true;
    }
}
