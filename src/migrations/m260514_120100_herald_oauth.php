<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Gate 7.3 migration — creates the three `herald_oauth_*` tables.
 *
 * `herald_oauth_clients` holds registered OAuth clients (DCR per RFC
 * 7591 inserts here; out-of-band seeding is fine too). Public clients
 * (`isPublic = 1`, PKCE-only per the AuthCodeGrant) have no
 * `clientSecretHash`; confidential clients store the hashed secret.
 * `redirectUris` is JSON-encoded.
 *
 * `herald_oauth_codes` holds one-shot authorization codes. The grant
 * encrypts the code payload before handing it to the client; we keep
 * a row keyed by the unencrypted code id so `isAuthCodeRevoked()` can
 * flip it after the `/oauth/token` exchange.
 *
 * `herald_oauth_tokens` holds access (JWT) and refresh (opaque)
 * tokens. `tokenType` discriminates. `tokenHash` is the SHA-256 of
 * the access token's `jti` claim or the refresh token's opaque
 * identifier — never the plaintext bearer string. `audience` is the
 * RFC 8707 resource indicator bound at issue time.
 *
 * All three tables get `userId` FK CASCADE on Craft's users table; a
 * user disappearing takes their consents with them. `clientId` on the
 * tokens / codes tables is tracked here as a logical FK only (a string
 * id, no hard DB constraint, to allow out-of-band client seeding). The
 * hard `ON DELETE CASCADE` FKs from `clientId` to
 * `herald_oauth_clients` were added later in the Gate 7 review fix
 * `m260515_080000_herald_oauth_client_fks`, so deleting a client now
 * atomically revokes its in-flight codes and tokens.
 *
 * Idempotent: each `createTable` is guarded by `tableExists`.
 * `safeDown()` drops in reverse FK order.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260514_120100_herald_oauth extends Migration
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
        if (!$this->db->tableExists(Table::OAUTH_CLIENTS)) {
            $this->createTable(Table::OAUTH_CLIENTS, [
                'id' => $this->primaryKey(),
                'clientId' => $this->string(64)->notNull(),
                'clientName' => $this->string(255)->notNull(),
                'redirectUris' => $this->text()->notNull(),
                'scope' => $this->string(255)->null(),
                'isPublic' => $this->boolean()->notNull()->defaultValue(false),
                'approved' => $this->boolean()->notNull()->defaultValue(false),
                'clientSecretHash' => $this->string(255)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::OAUTH_CLIENTS, ['clientId'], true);
            $this->createIndex(null, Table::OAUTH_CLIENTS, ['approved']);
        }

        if (!$this->db->tableExists(Table::OAUTH_CODES)) {
            $this->createTable(Table::OAUTH_CODES, [
                'code' => $this->string(80)->notNull(),
                'clientId' => $this->string(64)->notNull(),
                'userId' => $this->integer()->null(),
                'redirectUri' => $this->string(2000)->null(),
                'scope' => $this->string(255)->null(),
                'resource' => $this->string(2000)->null(),
                'codeChallenge' => $this->string(255)->null(),
                'codeChallengeMethod' => $this->string(10)->null(),
                'isRevoked' => $this->boolean()->notNull()->defaultValue(false),
                'expiresAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'PRIMARY KEY([[code]])',
            ]);

            $this->createIndex(null, Table::OAUTH_CODES, ['expiresAt']);
            $this->createIndex(null, Table::OAUTH_CODES, ['clientId']);
            $this->createIndex(null, Table::OAUTH_CODES, ['userId']);

            // FK on userId — codes mostly outlive a Craft user delete
            // for the brief 5-minute TTL window, but cascade keeps the
            // row index clean if it happens.
            $this->addForeignKey(
                null,
                Table::OAUTH_CODES,
                ['userId'],
                CraftTable::USERS,
                ['id'],
                'CASCADE',
                null,
            );
        }

        if (!$this->db->tableExists(Table::OAUTH_TOKENS)) {
            $this->createTable(Table::OAUTH_TOKENS, [
                'id' => $this->primaryKey(),
                'tokenType' => $this->string(10)->notNull(),
                'tokenHash' => $this->string(64)->notNull(),
                'userId' => $this->integer()->null(),
                'clientId' => $this->string(64)->notNull(),
                'familyId' => $this->string(36)->null(),
                'scope' => $this->string(255)->null(),
                'audience' => $this->string(2000)->null(),
                'expiresAt' => $this->dateTime()->notNull(),
                'dateRevoked' => $this->dateTime()->null(),
                'consumedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Unique on `tokenHash` so a hash collision (vanishingly
            // unlikely) surfaces as an insert error rather than a
            // silent overwrite.
            $this->createIndex(null, Table::OAUTH_TOKENS, ['tokenHash'], true);
            $this->createIndex(null, Table::OAUTH_TOKENS, ['userId']);
            $this->createIndex(null, Table::OAUTH_TOKENS, ['clientId']);
            $this->createIndex(null, Table::OAUTH_TOKENS, ['familyId']);
            $this->createIndex(null, Table::OAUTH_TOKENS, ['expiresAt']);
            $this->createIndex(null, Table::OAUTH_TOKENS, ['dateRevoked']);

            $this->addForeignKey(
                null,
                Table::OAUTH_TOKENS,
                ['userId'],
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
        // Drop in reverse FK order — tokens / codes both reference
        // clients via `clientId` (logical FK on the string id rather
        // than a hard DB FK, since clients can be registered out-of-
        // band without a row in the users table), so the order is
        // tokens → codes → clients.
        $this->dropTableIfExists(Table::OAUTH_TOKENS);
        $this->dropTableIfExists(Table::OAUTH_CODES);
        $this->dropTableIfExists(Table::OAUTH_CLIENTS);
        return true;
    }
}
