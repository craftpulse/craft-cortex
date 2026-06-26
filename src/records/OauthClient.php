<?php

namespace craftpulse\cortex\records;

use craft\db\ActiveRecord;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Active record for `cortex_oauth_clients`.
 *
 * One row per OAuth 2.1 client registered with the cortex install.
 * Created by `Oauth::registerClient()` (RFC 7591 DCR) or seeded out-
 * of-band. Public clients (`isPublic = 1`) authenticate via PKCE only;
 * confidential clients store a `password_hash`-derived secret in
 * `clientSecretHash`.
 *
 * `redirectUris` is JSON-encoded. The Record stores the raw JSON
 * string; the service layer (`Oauth`) is responsible for encoding /
 * decoding so consumers receive a typed `string[]`.
 * =========================================================================
 *
 * DCR-registered clients start UNAPPROVED (`approved = 0`): the
 * authorize + token flows reject them until an admin approves the row
 * on the Clients CP screen, or `Settings::$dcrAutoApprove` is on.
 *
 * @property int $id
 * @property string $clientId
 * @property string $clientName
 * @property string $redirectUris
 * @property string|null $scope
 * @property bool $isPublic
 * @property bool $approved
 * @property string|null $clientSecretHash
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class OauthClient extends ActiveRecord
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
        return Table::OAUTH_CLIENTS;
    }

    /**
     * @inheritdoc
     *
     * Mirrors the column constraints declared in the migration so
     * `save()` surfaces validation failures cleanly instead of
     * surfacing as raw DB exceptions. `clientId` length matches
     * the column; the value is a 32-char hex string from
     * `bin2hex(random_bytes(16))`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function rules(): array
    {
        return [
            [['clientId', 'clientName', 'redirectUris'], 'required'],
            [['clientId'], 'string', 'max' => 64],
            [['clientName'], 'string', 'max' => 255],
            [['scope'], 'string', 'max' => 255],
            [['clientSecretHash'], 'string', 'max' => 255],
            [['isPublic', 'approved'], 'boolean'],
        ];
    }
}
