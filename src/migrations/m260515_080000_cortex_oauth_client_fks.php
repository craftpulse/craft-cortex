<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Gate 7 review fix — add missing FK constraints on
 * `cortex_oauth_codes.clientId` and `cortex_oauth_tokens.clientId`
 * referencing `cortex_oauth_clients.clientId`.
 *
 * The original Gate 7.3 migration (`m260514_120100_cortex_oauth`)
 * tracked `clientId` as a logical FK only (comment noted "out-of-band
 * seeding"). This migration promotes both to hard DB FKs with
 * `ON DELETE CASCADE` so that deleting a client atomically revokes all
 * its in-flight codes and tokens — no orphaned rows that could still be
 * exchanged at `/oauth/token` after the client is removed.
 *
 * Idempotent: each FK is only added when no existing FK on the same
 * column pair is present. `safeDown()` drops them cleanly so
 * `migrate/down` + `migrate/up` cycles work in the test harness.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260515_080000_cortex_oauth_client_fks extends Migration
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
        // Purge any orphaned rows that reference a `clientId` not
        // present in `cortex_oauth_clients`. The original migration
        // had no FK, so test harnesses and dev installs may have
        // accumulated rows whose clients were later deleted. Adding the
        // FK without cleaning first causes an integrity violation.
        $this->delete(Table::OAUTH_CODES, [
            'not in',
            'clientId',
            (new \yii\db\Query())
                ->select('clientId')
                ->from(Table::OAUTH_CLIENTS),
        ]);

        $this->delete(Table::OAUTH_TOKENS, [
            'not in',
            'clientId',
            (new \yii\db\Query())
                ->select('clientId')
                ->from(Table::OAUTH_CLIENTS),
        ]);

        if (!$this->_hasFk(Table::OAUTH_CODES, 'clientId', Table::OAUTH_CLIENTS, 'clientId')) {
            $this->addForeignKey(
                null,
                Table::OAUTH_CODES,
                ['clientId'],
                Table::OAUTH_CLIENTS,
                ['clientId'],
                'CASCADE',
                null,
            );
        }

        if (!$this->_hasFk(Table::OAUTH_TOKENS, 'clientId', Table::OAUTH_CLIENTS, 'clientId')) {
            $this->addForeignKey(
                null,
                Table::OAUTH_TOKENS,
                ['clientId'],
                Table::OAUTH_CLIENTS,
                ['clientId'],
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
        $this->_dropFkIfExists(Table::OAUTH_TOKENS, 'clientId', Table::OAUTH_CLIENTS, 'clientId');
        $this->_dropFkIfExists(Table::OAUTH_CODES, 'clientId', Table::OAUTH_CLIENTS, 'clientId');
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Check whether a foreign key from `$fromTable.$fromCol` to
     * `$toTable.$toCol` already exists. Inspects the live schema so
     * re-running the migration is safe.
     *
     * @param string $fromTable Bracketed table name, e.g. `{{%cortex_oauth_codes}}`.
     * @param string $fromCol   Local column name without brackets.
     * @param string $toTable   Referenced table name (bracketed).
     * @param string $toCol     Referenced column name without brackets.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _hasFk(string $fromTable, string $fromCol, string $toTable, string $toCol): bool
    {
        $schema = $this->db->getTableSchema($fromTable, true);
        if ($schema === null) {
            return false;
        }

        // Resolve bracketed table names to bare names for comparison.
        $rawTo = $this->db->getSchema()->getRawTableName($toTable);

        foreach ($schema->foreignKeys as $fk) {
            if (!is_array($fk)) {
                continue;
            }
            // Yii2 FK arrays: [0 => referencedTable, col => referencedCol, ...]
            $refTable = $fk[0] ?? null;
            $refCol = $fk[$fromCol] ?? null;
            if ($refTable === $rawTo && $refCol === $toCol) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop a foreign key from `$fromTable.$fromCol` to `$toTable.$toCol`
     * if it exists. No-op when already absent.
     *
     * @param string $fromTable Bracketed table name.
     * @param string $fromCol   Local column name.
     * @param string $toTable   Referenced table name (bracketed).
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
