<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Herald install migration — creates the full schema on a fresh install.
 *
 * Pre-release plugin (no tagged releases yet), so every incremental
 * migration that previously shipped alongside this one has been folded
 * in here rather than kept as separate numbered files: `m260514_120000_
 * herald_tokens`, `m260514_120100_herald_oauth`, `m260515_080000_herald_
 * oauth_client_fks`, `m260514_120200_herald_invocations`,
 * `m260514_120300_herald_invocations_rate_limit`, `m260516_080000_herald_
 * skills`, `m260620_120000_herald_oauth_token_family`,
 * `m260620_120100_herald_oauth_client_approval`, and
 * `m260714_120000_herald_grant_subject`. This is the single source of
 * truth for Herald's schema.
 *
 * Tables:
 *   - `{{%herald_runtime_overrides}}` — admin-editable allowlist patterns
 *     that layer on top of the project-config defaults and
 *     `config/herald.php` overrides. Each override has an explicit
 *     `expiresAt` (default 7 days, configurable per
 *     `Settings::$runtimeOverrideTtl`) so transient grants don't
 *     accumulate indefinitely. `subjectUserId` scopes a grant to a single
 *     Craft user; null means a global grant.
 *   - `{{%herald_tokens}}` — admin-issued bearer tokens bound to a Craft
 *     user. Only `tokenHash` (SHA-256) is stored; `tokenPrefix` lets the
 *     CP listing identify a row without exposing the secret.
 *   - `{{%herald_oauth_clients}}` — OAuth 2.1 clients registered via RFC
 *     7591 Dynamic Client Registration or seeded out-of-band. Every DCR-
 *     registered client starts `approved = false` until an admin approves
 *     it (or `Settings::$dcrAutoApprove` is on).
 *   - `{{%herald_oauth_codes}}` — one-shot authorization codes minted by
 *     the `/oauth/authorize` consent flow, exchanged at `/oauth/token`.
 *   - `{{%herald_oauth_tokens}}` — OAuth access (JWT) and refresh (opaque)
 *     tokens. `familyId` is the per-authorization lineage identifier used
 *     for refresh-token-replay theft detection (RFC 6819 §5.2.2.3): every
 *     token descended from one `/oauth/authorize` grant shares a family,
 *     and a replayed (already-`consumedAt`) refresh token revokes the
 *     whole family.
 *   - `{{%herald_invocations}}` — one row per authenticated HTTP-transport
 *     `tools/call`; stdio invocations write to Craft's KV log only.
 *     `rateLimitRemaining` is a per-row snapshot of post-consume bucket
 *     headroom for the calling user, an analytic field with no dedicated
 *     index.
 *   - `{{%herald_skills}}` (Gate 8.6) — author-able overrides for the
 *     bundled skills corpus. Joined to `elements(id)` via FK with
 *     ON DELETE CASCADE. `handle` is a globally-unique natural key
 *     (UNIQUE index); `description` lives as a native column so the
 *     list-view path doesn't pay for a content-table join. The body
 *     lives in the PC-stored field layout.
 *
 * Creation order respects foreign-key dependencies: `herald_oauth_clients`
 * before `herald_oauth_codes` / `herald_oauth_tokens` (both FK to it via
 * `clientId`), and `herald_tokens` before `herald_invocations` (FK to it
 * via `tokenId`). `safeDown()` drops in the reverse order.
 *
 * Idempotent: each `createTable()` call is guarded by a `tableExists`
 * check.
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
        $this->_createRuntimeOverridesTable();
        $this->_createSkillsTable();
        $this->_createTokensTable();
        $this->_createOauthClientsTable();
        $this->_createOauthCodesTable();
        $this->_createOauthTokensTable();
        $this->_createInvocationsTable();

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
        $this->dropTableIfExists(Table::OAUTH_TOKENS);
        $this->dropTableIfExists(Table::OAUTH_CODES);
        $this->dropTableIfExists(Table::OAUTH_CLIENTS);
        $this->dropTableIfExists(Table::TOKENS);
        $this->dropTableIfExists(Table::SKILLS);
        $this->dropTableIfExists(Table::RUNTIME_OVERRIDES);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates `{{%herald_runtime_overrides}}`, the admin-editable allowlist
     * override table. `createdByUserId` records who issued the grant
     * (SET NULL on user delete, preserving the row for forensics);
     * `subjectUserId` scopes a per-user grant (CASCADE on user delete, since
     * a grant is meaningless without its subject).
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createRuntimeOverridesTable(): void
    {
        if ($this->db->tableExists(Table::RUNTIME_OVERRIDES)) {
            return;
        }

        $this->createTable(Table::RUNTIME_OVERRIDES, [
            'id' => $this->primaryKey(),
            'pattern' => $this->string(255)->notNull(),
            'note' => $this->string(255)->null(),
            'expiresAt' => $this->dateTime()->null(),
            'createdByUserId' => $this->integer()->null(),
            'subjectUserId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['pattern']);
        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['expiresAt']);
        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['dateDeleted']);
        $this->createIndex(null, Table::RUNTIME_OVERRIDES, ['subjectUserId']);

        $this->addForeignKey(
            null,
            Table::RUNTIME_OVERRIDES,
            ['createdByUserId'],
            CraftTable::USERS,
            ['id'],
            'SET NULL',
            null,
        );
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

    /**
     * Creates `{{%herald_skills}}` (Gate 8.6), the author-able overrides
     * table for the bundled skills corpus. FK to `elements(id)` ON DELETE
     * CASCADE so a hard-deleted element wipes its row; `handle` is UNIQUE
     * so the natural-key invariant is enforced at the database layer.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createSkillsTable(): void
    {
        if ($this->db->tableExists(Table::SKILLS)) {
            return;
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
    }

    /**
     * Creates `{{%herald_tokens}}`, the admin-issued bearer token table.
     * The plaintext token is never stored; only its SHA-256 hex digest
     * (`tokenHash`, indexed unique for lookup) and the first 8 chars of
     * the plaintext (`tokenPrefix`, surfaced in the CP listing). The
     * `userId` FK cascades on user delete — a user disappearing takes
     * their tokens with them. The `scope` column holds the token's
     * space-delimited capability scopes; null means the token authorises
     * nothing and the HTTP transport refuses it.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createTokensTable(): void
    {
        if ($this->db->tableExists(Table::TOKENS)) {
            return;
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

        // Unique index on `tokenHash` so lookup is a single equality probe
        // and accidental hash collisions surface as an insert error rather
        // than a silent overwrite.
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
    }

    /**
     * Creates `{{%herald_oauth_clients}}`, the OAuth 2.1 registered-client
     * table. `redirectUris` is JSON-encoded. Public clients (`isPublic =
     * 1`, PKCE-only) have no `clientSecretHash`; confidential clients
     * store the hashed secret. `approved` gates the RFC 7591 Dynamic
     * Client Registration flow: every DCR-registered client starts
     * unapproved, and the authorize/token flows reject it until an admin
     * approves it on the Clients CP screen (or `Settings::$dcrAutoApprove`
     * is on).
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createOauthClientsTable(): void
    {
        if ($this->db->tableExists(Table::OAUTH_CLIENTS)) {
            return;
        }

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

    /**
     * Creates `{{%herald_oauth_codes}}`, the one-shot authorization-code
     * table. League's AuthCode grant encrypts the code payload before
     * handing it to the client; we keep a row keyed by the unencrypted
     * code id so `isAuthCodeRevoked()` can flip it after the
     * `/oauth/token` exchange. The `clientId` FK cascades so deleting a
     * client atomically revokes all its in-flight codes.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createOauthCodesTable(): void
    {
        if ($this->db->tableExists(Table::OAUTH_CODES)) {
            return;
        }

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

        // FK on userId — codes mostly outlive a Craft user delete for the
        // brief authorization-code TTL window, but cascade keeps the row
        // index clean if it happens.
        $this->addForeignKey(
            null,
            Table::OAUTH_CODES,
            ['userId'],
            CraftTable::USERS,
            ['id'],
            'CASCADE',
            null,
        );

        // FK on clientId — deleting a client atomically revokes its
        // in-flight codes; no orphaned rows that could still be exchanged
        // at `/oauth/token` after the client is removed.
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

    /**
     * Creates `{{%herald_oauth_tokens}}`, the OAuth access/refresh token
     * table. `tokenType` discriminates access (JWT) from refresh (opaque);
     * `tokenHash` is the SHA-256 of the access token's `jti` claim or the
     * refresh token's opaque identifier, never the plaintext bearer
     * string. `audience` is the RFC 8707 resource indicator bound at issue
     * time. `familyId` is the per-authorization lineage identifier and
     * `consumedAt` the rotation marker used together for refresh-token-
     * replay theft detection (RFC 6819 §5.2.2.3): every token descended
     * from one `/oauth/authorize` grant shares a `familyId`, and a
     * replayed (already-`consumedAt`) refresh token revokes the whole
     * family in a single keyed update. The `clientId` FK cascades so
     * deleting a client atomically revokes its tokens.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createOauthTokensTable(): void
    {
        if ($this->db->tableExists(Table::OAUTH_TOKENS)) {
            return;
        }

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

        // Unique on `tokenHash` so a hash collision (vanishingly unlikely)
        // surfaces as an insert error rather than a silent overwrite.
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

        // FK on clientId — deleting a client atomically revokes its
        // tokens; no orphaned rows outliving the client that registered
        // them.
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

    /**
     * Creates `{{%herald_invocations}}`, the HTTP-transport tool-
     * invocation audit-log table. `argsRedacted` and `responseExcerpt`
     * carry post-`SecretRedactor` payloads; `responseExcerpt` is a
     * forensic excerpt only, never the uncapped wire payload.
     * `rateLimitRemaining` is a per-row snapshot of the post-consume rate-
     * limit bucket headroom for the calling user — an analytic field with
     * no dedicated index, since operators read it per-row rather than
     * filtering by it. `tokenId` correlates to `herald_tokens(id)` only
     * (SET NULL on token delete, preserving audit history); OAuth-
     * authenticated invocations leave it null and correlate implicitly via
     * `(userId, clientName, dateCreated)`.
     *
     * @return void
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _createInvocationsTable(): void
    {
        if ($this->db->tableExists(Table::INVOCATIONS)) {
            return;
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
            'rateLimitRemaining' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // `(transport, dateCreated)` is a composite for the dashboard's
        // "HTTP traffic over time" queries; `toolName` and `kind` are
        // facet filters; `userId` is the per-user audit lookup.
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
        // The `herald_tokens` table soft-deletes via `dateDeleted` so the
        // FK rarely fires; the SET NULL is defensive against future
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
    }
}
