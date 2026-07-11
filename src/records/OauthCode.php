<?php

namespace craftpulse\herald\records;

use craft\db\ActiveRecord;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Active record for `herald_oauth_codes`.
 *
 * One-shot authorization codes. Primary key is the `code` identifier
 * minted by league's grant (`generateUniqueIdentifier()`), not an
 * auto-increment id — so `isAuthCodeRevoked()` can flip the row by
 * the same id the grant carries around without an extra lookup.
 *
 * `isRevoked` is set once the code is consumed at `/oauth/token`.
 * `expiresAt` mirrors the grant's `$authCodeTTL` for periodic gc
 * pruning.
 * =========================================================================
 *
 * @property string $code
 * @property string $clientId
 * @property int|null $userId
 * @property string|null $redirectUri
 * @property string|null $scope
 * @property string|null $resource
 * @property string|null $codeChallenge
 * @property string|null $codeChallengeMethod
 * @property bool $isRevoked
 * @property string $expiresAt
 * @property string $dateCreated
 * @property string $dateUpdated
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthCode extends ActiveRecord
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
        return Table::OAUTH_CODES;
    }

    /**
     * @inheritdoc
     *
     * `code` is the primary key — Yii's ActiveRecord needs the
     * explicit declaration because the column doesn't follow the
     * default `id` convention.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public static function primaryKey(): array
    {
        return ['code'];
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
            [['code', 'clientId', 'expiresAt'], 'required'],
            [['code'], 'string', 'max' => 80],
            [['clientId'], 'string', 'max' => 64],
            [['userId'], 'integer'],
            [['scope'], 'string', 'max' => 255],
            [['codeChallenge'], 'string', 'max' => 255],
            [['codeChallengeMethod'], 'string', 'max' => 10],
            [['isRevoked'], 'boolean'],
        ];
    }
}
