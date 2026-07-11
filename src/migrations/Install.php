<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Herald install migration — creates the runtime-overrides + skills
 * tables.
 *
 * Tables:
 *   - `{{%herald_runtime_overrides}}` — admin-editable allowlist
 *     patterns that layer on top of the project-config defaults and
 *     `config/herald.php` overrides. Each override has an explicit
 *     `expiresAt` (default 7 days, configurable per
 *     `Settings::$runtimeOverrideTtl`) so transient grants don't
 *     accumulate indefinitely.
 *   - `{{%herald_skills}}` (Gate 8.6) — author-able overrides for the
 *     bundled skills corpus. Joined to `elements(id)` via FK with
 *     ON DELETE CASCADE. `handle` is a globally-unique natural key
 *     (UNIQUE index); `description` lives as a native column so the
 *     list-view path doesn't pay for a content-table join. The body
 *     lives in the PC-stored field layout.
 *
 * Idempotent: each `createTable()` call is guarded by a `tableExists`
 * check. `safeDown()` reverses cleanly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Install extends Migration
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
        if (!$this->db->tableExists(Table::RUNTIME_OVERRIDES)) {
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
        }

        // Gate 8.6 — herald_skills table for author-able overrides of
        // the bundled skills corpus. FK to elements(id) ON DELETE
        // CASCADE so a hard-deleted element wipes its row; handle is
        // UNIQUE so the natural-key invariant is enforced at the
        // database layer.
        if (!$this->db->tableExists(Table::SKILLS)) {
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
        }

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
        $this->dropTableIfExists(Table::RUNTIME_OVERRIDES);
        return true;
    }
}
