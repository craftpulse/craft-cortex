<?php

namespace craftpulse\cortex\oauth\entities;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use RuntimeException;

/**
 * =========================================================================
 * OAuth access token entity — JWT signed with the install's private
 * key, with RFC 8707 audience binding baked into the `aud` claim.
 *
 * League's stock `AccessTokenTrait` sets `aud = [$client_id]`. That's
 * fine for vanilla OAuth but the MCP spec extends OAuth with RFC 8707
 * resource indicators: clients pass `resource=<mcp-endpoint-url>` on
 * `/authorize` and `/token`, and the issued access token's `aud` must
 * match that resource URI. We override `convertToJWT()` to:
 *
 *   1. Use `permittedFor($audience)` instead of
 *      `permittedFor($client_id)` so the `aud` claim is the resource
 *      URI, not the client id.
 *   2. Still embed the client id in a separate custom claim (`cid`)
 *      so `BearerTokenValidator` still has it; we also override the
 *      validation path in `Oauth::lookupAccessToken()` to read the
 *      cortex-specific claims rather than league's defaults.
 *
 * Rationale: league's `BearerTokenValidator` reads `aud[0]` as the
 * client id at validate time. By moving the client id to `cid` and
 * placing the resource URI in `aud`, the cortex `Oauth` service can
 * enforce audience binding without monkey-patching league's
 * validator. The MCP-spec `aud` semantics win; the client correlation
 * uses our custom claim.
 *
 * `$audience` falls back to the client id when the resource indicator
 * was never set (vanilla OAuth client) so league's own validator
 * still works as a fallback path.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class AccessTokenEntity implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    // Private Properties
    // =========================================================================

    /**
     * @var CryptKeyInterface RSA private key league sets at issuance.
     */
    private CryptKeyInterface $_privateKey;

    /**
     * @var string|null Resource indicator (RFC 8707) bound at issue
     *                  time. Stamped into the `aud` claim. Null when
     *                  the client didn't pass `resource=…` —
     *                  fallback to client id so league's stock
     *                  validator path keeps working.
     */
    private ?string $_audience = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->_privateKey = $privateKey;
    }

    /**
     * Bind the RFC 8707 resource indicator to this token. Set by the
     * `Oauth` service after league issues the token but before the
     * JWT is generated. Embedded as the `aud` claim.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function setAudience(?string $audience): void
    {
        $this->_audience = $audience;
    }

    /**
     * Expose the bound audience so the resource server can verify it
     * matches the MCP endpoint URL at validation time.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function getAudience(): ?string
    {
        return $this->_audience;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function toString(): string
    {
        return $this->_convertToJWT()->toString();
    }

    // Private Methods
    // =========================================================================

    /**
     * Generate the JWT, embedding the resource indicator in the `aud`
     * claim per RFC 8707 §2. The client id moves to a custom `cid`
     * claim so cortex's own validator can correlate the token back to
     * its issuing client.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _convertToJWT(): Token
    {
        $config = $this->_jwtConfiguration();
        $audience = $this->_audience ?? $this->getClient()->getIdentifier();
        $subject = $this->_subjectIdentifier();
        if ($audience === '' || $subject === '') {
            // Defensive — both come from non-empty contracts upstream
            // (client identifier is non-empty by EntityTrait, user
            // identifier defaults to client identifier when null),
            // but the narrow keeps PHPStan happy on non-empty-string.
            throw new RuntimeException('Cannot mint a JWT with an empty audience or subject.');
        }

        return $config->builder()
            ->permittedFor($audience)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($subject)
            ->withClaim('scopes', $this->getScopes())
            ->withClaim('cid', $this->getClient()->getIdentifier())
            ->getToken($config->signer(), $config->signingKey());
    }

    /**
     * Build the Lcobucci JWT configuration. Mirrors league's
     * `AccessTokenTrait::initJwtConfiguration` — RS256 with the
     * install's private key as the signer.
     *
     * @throws RuntimeException When the private key contents are
     *                          empty (misconfigured install).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _jwtConfiguration(): Configuration
    {
        $contents = $this->_privateKey->getKeyContents();
        if ($contents === '') {
            throw new RuntimeException('Private key contents are empty; OAuth access tokens cannot be signed.');
        }

        return Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($contents, $this->_privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );
    }

    /**
     * Resolve the JWT subject. User-bound tokens carry the user id;
     * client-credentials-style tokens (not used in cortex today, but
     * reserved) fall back to the client id.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    private function _subjectIdentifier(): string
    {
        return $this->getUserIdentifier() ?? $this->getClient()->getIdentifier();
    }
}
