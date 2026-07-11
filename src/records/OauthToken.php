<?php

namespace craftpulse\herald\records;

use craft\db\ActiveRecord;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Active record for `herald_oauth_tokens`.
 *
 * One row per access or refresh token. `tokenType` discriminates:
 *
 *   - `access` — JWT access token. `tokenHash` is the SHA-256 of the
 *     JWT's `jti` claim. Revocation flips `dateRevoked` and the
 *     resource server's `BearerTokenValidator` rejects the token on
 *     next presentation.
 *   - `refresh` — opaque refresh token. `tokenHash` is the SHA-256 of
 *     the opaque identifier league mints. Refreshing produces a new
 *     access (and refresh) token; the old refresh is revoked.
 *
 * `audience` carries the RFC 8707 resource indicator bound at issue
 * time. `McpController::beforeAction()` verifies it matches the MCP
 * endpoint URL before accepting the token.
 * =========================================================================
 *
 * `familyId` ties every access + refresh token minted from one
 * authorization (and every rotation descended from it) into a single
 * lineage. `consumedAt` marks a refresh token that has been rotated
 * away; a present-but-revoked refresh token whose `consumedAt` is set,
 * presented again, is a replay of a consumed token — theft signal that
 * revokes the whole `familyId` (RFC 6819 / OAuth 2.1 rotation BCP).
 *
 * @property int $id
 * @property string $tokenType
 * @property string $tokenHash
 * @property int|null $userId
 * @property string $clientId
 * @property string|null $familyId
 * @property string|null $scope
 * @property string|null $audience
 * @property string $expiresAt
 * @property string|null $dateRevoked
 * @property string|null $consumedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthToken extends ActiveRecord
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
        return Table::OAUTH_TOKENS;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['tokenType', 'tokenHash', 'clientId', 'expiresAt'], 'required'],
            [['tokenType'], 'in', 'range' => ['access', 'refresh']],
            [['tokenHash'], 'string', 'length' => 64],
            [['clientId'], 'string', 'max' => 64],
            [['familyId'], 'string', 'max' => 36],
            [['userId'], 'integer'],
            [['scope'], 'string', 'max' => 255],
        ];
    }
}
