<?php

namespace craftpulse\herald\records;

use craft\db\ActiveRecord;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Active record for the `herald_tokens` table.
 *
 * Each row is one admin-issued bearer token bound to a Craft user. The
 * plaintext token is never stored; only the SHA-256 hex digest is kept
 * in `tokenHash`, indexed unique for lookup. `tokenPrefix` stores the
 * first 8 chars of the plaintext so operators can identify a row in the
 * CP listing without exposing the secret.
 *
 * Soft-delete via `dateDeleted` mirrors `RuntimeOverride`. `scope` holds
 * the space-delimited capability scopes the token carries and IS
 * enforced: `McpController` refuses a credential whose scope is null or
 * empty, so a null here denies rather than granting everything.
 * =========================================================================
 *
 * @property int $id
 * @property string $name
 * @property string $tokenHash
 * @property string $tokenPrefix
 * @property int $userId
 * @property string|null $scope
 * @property string|null $expiresAt
 * @property string|null $lastUsedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string|null $dateDeleted
 * @property string $uid
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class Token extends ActiveRecord
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
        return Table::TOKENS;
    }

    /**
     * @inheritdoc
     *
     * Mirrors the column constraints declared in
     * `Install::_createTokensTable()` at the model layer so `save()`
     * fails cleanly via validation rather than as a raw DB exception.
     * The 64-char `tokenHash` constraint catches a caller passing the
     * plaintext where the hash was expected (or vice versa) — both
     * happen to be 64 hex chars in herald's scheme, so the length check
     * is necessary but not sufficient; callers remain responsible for
     * passing the *hashed* form.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['name', 'tokenHash', 'tokenPrefix', 'userId'], 'required'],
            [['name'], 'string', 'max' => 64],
            [['tokenHash'], 'string', 'length' => 64],
            [['tokenPrefix'], 'string', 'length' => 8],
            [['userId'], 'integer'],
        ];
    }
}
