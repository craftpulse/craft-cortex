<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Adds the per-user grant subject to `herald_runtime_overrides`.
 *
 * Temporary grants are now per-user access: a grant names the Craft user
 * it applies to (`subjectUserId`) so the `craft_command` dispatch gate can
 * honour a grant only for the user it was issued to. One nullable column
 * lands:
 *
 *   - `subjectUserId` — the granted user. NULL means a GLOBAL grant that
 *     applies to every caller (the pre-per-user semantics, and the shape
 *     of any override row already in the wild). A non-null value scopes
 *     the grant to exactly that user. Indexed so the per-user allowlist
 *     lookup is a single keyed read, FK to `users(id)` ON DELETE CASCADE
 *     so a deleted user's grants vanish with them (a grant is meaningless
 *     without its subject).
 *
 * Idempotent: the `addColumn` is guarded by a `columnExists` check.
 * Pre-release plugin, so `Install.php` carries the column for fresh
 * installs and this numbered migration upgrades already-installed
 * playgrounds via `craft up`. Existing rows keep `subjectUserId = null`
 * and stay global grants, so no data migration is required.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260714_120000_herald_grant_subject extends Migration
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
        if (!$this->db->columnExists(Table::RUNTIME_OVERRIDES, 'subjectUserId')) {
            $this->addColumn(
                Table::RUNTIME_OVERRIDES,
                'subjectUserId',
                $this->integer()->null()->after('createdByUserId'),
            );
            $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['subjectUserId']);
            $this->addForeignKey(
                null,
                Table::RUNTIME_OVERRIDES,
                ['subjectUserId'],
                CraftTable::USERS,
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
        if ($this->db->columnExists(Table::RUNTIME_OVERRIDES, 'subjectUserId')) {
            $this->_dropFkIfExists(Table::RUNTIME_OVERRIDES, 'subjectUserId', CraftTable::USERS, 'id');
            $this->dropIndexIfExists(Table::RUNTIME_OVERRIDES, ['subjectUserId']);
            $this->dropColumn(Table::RUNTIME_OVERRIDES, 'subjectUserId');
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Drop the foreign key on `$fromCol` referencing `$toTable.$toCol` if
     * one exists. The FK name was auto-generated at `addForeignKey` time,
     * so it is resolved from the table schema rather than assumed.
     *
     * @param string $fromTable Bracketed source table name.
     * @param string $fromCol   Source column name.
     * @param string $toTable   Bracketed referenced table name.
     * @param string $toCol     Referenced column name.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _dropFkIfExists(string $fromTable, string $fromCol, string $toTable, string $toCol): void
    {
        $schema = $this->db->getTableSchema($fromTable, true);
        if ($schema === null) {
            return;
        }

        $rawTo = $this->db->getSchema()->getRawTableName($toTable);

        foreach ($schema->foreignKeys as $fkName => $fk) {
            if (!is_array($fk)) {
                continue;
            }
            $refTable = $fk[0] ?? null;
            $refCol = $fk[$fromCol] ?? null;
            if ($refTable === $rawTo && $refCol === $toCol) {
                $this->dropForeignKey((string) $fkName, $fromTable);
                return;
            }
        }
    }
}
