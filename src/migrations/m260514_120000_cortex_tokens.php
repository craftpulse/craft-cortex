<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Gate 7.2 migration — creates the `cortex_tokens` table.
 *
 * Each row is one admin-issued bearer token bound to a Craft user. The
 * plaintext token is never stored; only its SHA-256 hex digest
 * (`tokenHash`, indexed unique for lookup) and the first 8 chars of the
 * plaintext (`tokenPrefix`, surfaced in the CP listing so operators can
 * identify a row without exposing the secret). Soft-delete via
 * `dateDeleted` mirrors `cortex_runtime_overrides`.
 *
 * The `userId` FK cascades on user delete — a user disappearing takes
 * their tokens with them. The `scope` JSON column is reserved for a
 * future fine-grained scope mechanism; 7.2 ignores it and writes null.
 *
 * Idempotent: bails out if the table already exists. `safeDown()`
 * reverses cleanly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260514_120000_cortex_tokens extends Migration
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
        if ($this->db->tableExists(Table::TOKENS)) {
            return true;
        }

        $this->createTable(Table::TOKENS, [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull(),
            'tokenHash' => $this->string(64)->notNull(),
            'tokenPrefix' => $this->string(8)->notNull(),
            'userId' => $this->integer()->notNull(),
            'scope' => $this->text()->null(),
            'expiresAt' => $this->dateTime()->null(),
            'lastUsedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        // Unique index on `tokenHash` so lookup is a single equality
        // probe and accidental hash collisions surface as an insert
        // error rather than a silent overwrite.
        $this->createIndex(null, Table::TOKENS, ['tokenHash'], true);
        $this->createIndex(null, Table::TOKENS, ['userId']);
        $this->createIndex(null, Table::TOKENS, ['expiresAt']);
        $this->createIndex(null, Table::TOKENS, ['dateDeleted']);

        $this->addForeignKey(
            null,
            Table::TOKENS,
            ['userId'],
            CraftTable::USERS,
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
        $this->dropTableIfExists(Table::TOKENS);
        return true;
    }
}
