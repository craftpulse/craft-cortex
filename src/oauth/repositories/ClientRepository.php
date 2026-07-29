<?php

namespace craftpulse\herald\oauth\repositories;

use craftpulse\herald\oauth\entities\ClientEntity;
use craftpulse\herald\records\OauthClient as OauthClientRecord;
use JsonException;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * =========================================================================
 * League adapter — `ClientRepositoryInterface`.
 *
 * Fetches `herald_oauth_clients` rows by `clientId` and validates the
 * client secret for confidential clients. Public clients (PKCE-only,
 * `isPublic = 1`) bypass the secret check — league re-verifies the
 * PKCE proof at the token endpoint.
 *
 * `validateClient()` returns true for public clients regardless of
 * what `$clientSecret` was passed because league's AuthCode grant
 * calls this method on every request — including the PKCE-only paths
 * where no secret is presented. The PKCE proof itself is verified
 * separately in `AuthCodeGrant::respondToAccessTokenRequest()`.
 *
 * DCR approval gate (WS3): an UNAPPROVED client (`approved = 0`) is
 * invisible to both `getClientEntity()` (authorize flow) and
 * `validateClient()` (token flow) — both return null / false as if the
 * client did not exist. League surfaces that as `invalid_client`, and
 * the `OauthController` maps the authorize-time miss to a clear
 * "pending admin approval" page so the operator knows to approve the
 * client on the Clients CP screen. Out-of-band-seeded clients with
 * `approved = 1` are unaffected.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class ClientRepository implements ClientRepositoryInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $record = OauthClientRecord::findOne(['clientId' => $clientIdentifier]);
        if (!$record instanceof OauthClientRecord) {
            return null;
        }

        // Unapproved clients are invisible to the authorize flow — the
        // DCR approval gate (WS3). Treated as if the client did not
        // exist; the controller surfaces a "pending admin approval"
        // page from the resulting miss.
        if (!(bool) $record->approved) {
            return null;
        }

        return $this->_hydrate($record);
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $record = OauthClientRecord::findOne(['clientId' => $clientIdentifier]);
        if (!$record instanceof OauthClientRecord) {
            return false;
        }

        // Unapproved clients fail the token flow too — the DCR approval
        // gate, enforced on both authorize and token boundaries.
        if (!(bool) $record->approved) {
            return false;
        }

        // Public clients (PKCE-only) skip the secret check. League's
        // AuthCode grant still verifies the PKCE proof separately at
        // the token endpoint, so this is safe.
        if ((bool) $record->isPublic) {
            return true;
        }

        // Confidential clients require a secret. A missing or empty
        // `$clientSecret` fails closed.
        if ($clientSecret === null || $clientSecret === '') {
            return false;
        }

        if ($record->clientSecretHash === null || $record->clientSecretHash === '') {
            return false;
        }

        return password_verify($clientSecret, $record->clientSecretHash);
    }

    // Private Methods
    // =========================================================================

    /**
     * Hydrate a league `ClientEntity` from a `herald_oauth_clients`
     * record. `redirectUris` decodes from JSON; a malformed JSON
     * payload (would only happen via direct DB write) falls back to
     * an empty list so league rejects the request with
     * `invalid_redirect_uri` rather than crashing on `json_decode`.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _hydrate(OauthClientRecord $record): ClientEntity
    {
        $clientId = (string) $record->clientId;
        if ($clientId === '') {
            // Hard-impossible because the column is `NOT NULL` and
            // service inserts always populate, but PHPStan can't
            // infer that from the Record's nullable property
            // declarations. The throw narrows the type for league.
            throw new \LogicException('Encountered an OauthClient row with an empty clientId, which violates a schema invariant.');
        }

        $entity = new ClientEntity();
        $entity->setIdentifier($clientId);
        $entity->setName((string) $record->clientName);
        $entity->setIsConfidential(!(bool) $record->isPublic);

        $entity->setRedirectUri($this->_decodeRedirectUris((string) $record->redirectUris));

        return $entity;
    }

    /**
     * Decode the JSON-encoded `redirectUris` column. Returns the
     * single string when the list has one entry, the array otherwise.
     * League accepts either shape per `ClientEntityInterface::getRedirectUri()`.
     *
     * @return string|string[]
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _decodeRedirectUris(string $json): string|array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $strings = array_values(array_filter($decoded, 'is_string'));
        if (count($strings) === 1) {
            return $strings[0];
        }

        return $strings;
    }
}
