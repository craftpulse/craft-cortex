<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Gate 7.5 migration — creates the `cortex_invocations` audit-log table.
 *
 * One row per authenticated HTTP-transport `tools/call`. stdio
 * invocations write to Craft's KV log only and never persist a row here
 * per the locked decision in `docs/plans/gate-7.md` item 11: stdio is
 * single-process trusted-local; the DB audit log exists to satisfy
 * forensics, compliance, and the Pro audit dashboard which all care
 * about HTTP-transport invocations. The `transport` column still lives
 * here because if decision 11 ever loosens (e.g. a server-mode stdio
 * sidecar lands), the schema absorbs the new value without a migration.
 *
 * Round-trip invariant (locked, decision 5): every field
 * `InvocationLogger::formatEntry()` emits as a KV token has a column on
 * this table. The `argsRedacted` and `responseExcerpt` columns carry
 * post-`SecretRedactor` payloads. `responseExcerpt` is the first
 * `Settings::$auditResponseExcerptBytes` bytes of the JSON-encoded tool
 * response — a forensic excerpt, NOT the wire payload (the wire payload
 * goes back to the MCP client uncapped). The excerpt is a Gate-7.5
 * addition that extends the KV log line; the wire-format is the source
 * of truth, so adding a field on both sides preserves the round-trip
 * invariant in the additive direction.
 *
 * FK strategy:
 *   - `userId` → `users(id)` SET NULL. A deleted user shouldn't take
 *     their audit history with them — the row stays for forensics,
 *     the `userId` slot just goes null.
 *   - `tokenId` → `cortex_tokens(id)` SET NULL. Same rationale —
 *     revoking a token shouldn't lose the audit trail of what it did
 *     while it was live.
 *
 * `tokenId` references `cortex_tokens(id)` only. OAuth tokens
 * (`cortex_oauth_tokens`) are NOT correlated via FK because we have
 * two source tables — `tokenId` carries the bearer-row id when
 * authentication was via long-lived bearer; OAuth-authenticated
 * invocations leave `tokenId` null and the OAuth correlation lives
 * implicitly via `(userId, clientName, dateCreated)`. Future:
 * a `bearerType` discriminator + nullable `oauthTokenId` if the
 * audit dashboard needs first-class OAuth correlation.
 *
 * Idempotent: bails out if the table already exists. `safeDown()`
 * reverses cleanly.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260514_120200_cortex_invocations extends Migration
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
        if ($this->db->tableExists(Table::INVOCATIONS)) {
            return true;
        }

        $this->createTable(Table::INVOCATIONS, [
            'id' => $this->primaryKey(),
            'toolName' => $this->string(64)->notNull(),
            'kind' => $this->string(20)->notNull(),
            'durationMs' => $this->integer()->notNull(),
            'transport' => $this->string(10)->notNull(),
            'requestId' => $this->string(255)->null(),
            'userId' => $this->integer()->null(),
            'clientName' => $this->string(255)->null(),
            'argsRedacted' => $this->text()->null(),
            'responseExcerpt' => $this->text()->null(),
            'errorClass' => $this->string(255)->null(),
            'errorMessage' => $this->string(1000)->null(),
            'tokenId' => $this->integer()->null(),
            'sessionId' => $this->string(64)->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes per plan §7.5. `(transport, dateCreated)` is a
        // composite for the dashboard's "HTTP traffic over time"
        // queries; `toolName` and `kind` are facet filters; `userId`
        // is the per-user audit lookup.
        $this->createIndex(null, Table::INVOCATIONS, ['toolName']);
        $this->createIndex(null, Table::INVOCATIONS, ['userId']);
        $this->createIndex(null, Table::INVOCATIONS, ['transport', 'dateCreated']);
        $this->createIndex(null, Table::INVOCATIONS, ['kind']);

        // userId FK — SET NULL on user delete (preserve audit history).
        $this->addForeignKey(
            null,
            Table::INVOCATIONS,
            ['userId'],
            CraftTable::USERS,
            ['id'],
            'SET NULL',
            null,
        );

        // tokenId FK — SET NULL on token delete (preserve audit history).
        // The `cortex_tokens` table soft-deletes via `dateDeleted` so
        // the FK rarely fires; the SET NULL is defensive against future
        // hard-delete sweeps or operator-driven cleanups.
        $this->addForeignKey(
            null,
            Table::INVOCATIONS,
            ['tokenId'],
            Table::TOKENS,
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
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::INVOCATIONS);
        return true;
    }
}
