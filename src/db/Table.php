<?php

namespace craftpulse\cortex\db;

/**
 * =========================================================================
 * Database table-name constants for cortex.
 *
 * Matches the canonical Craft pattern (`craft\db\Table`) so cortex's
 * own queries reference table names through a single source of truth
 * rather than scattering string literals.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
final class Table
{
    /**
     * Runtime allowlist override entries — admin-editable allowlist
     * patterns that auto-expire. Project-config defaults +
     * `config/cortex.php` overrides remain the canonical source; this
     * table layers temporary additions on top.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const RUNTIME_OVERRIDES = '{{%cortex_runtime_overrides}}';

    /**
     * Admin-issued bearer tokens bound to a Craft user. Only the SHA-
     * 256 hash of the plaintext is stored; the plaintext is surfaced
     * once at issuance by `cortex/token/issue` and never returned
     * again by any service method. Soft-delete via `dateDeleted`
     * keeps the lookup index small while preserving audit history.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const TOKENS = '{{%cortex_tokens}}';

    /**
     * OAuth 2.1 clients registered via RFC 7591 Dynamic Client
     * Registration or seeded out-of-band. One row per client. Public
     * clients (PKCE-only, no secret) have `isPublic = 1` and
     * `clientSecretHash` null; confidential clients store a hashed
     * secret. `redirectUris` is a JSON-encoded list per RFC 6749 §3.1.2.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const OAUTH_CLIENTS = '{{%cortex_oauth_clients}}';

    /**
     * One-shot authorization codes minted by the `/oauth/authorize`
     * consent flow, exchanged for tokens at `/oauth/token`. League's
     * AuthCode grant encrypts the code payload before handing it to
     * the client; we still persist a row keyed by the unencrypted
     * `code` identifier so `isAuthCodeRevoked()` can flip it after
     * consumption. ~5 minute TTL enforced by the grant's
     * `$authCodeTTL` DateInterval, this row's `expiresAt` is the
     * mirror for lookup / pruning.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const OAUTH_CODES = '{{%cortex_oauth_codes}}';

    /**
     * OAuth access and refresh tokens. `tokenType` discriminates the
     * row (`access` vs `refresh`). Access tokens are JWTs signed with
     * the install's private key; we persist the SHA-256 of the JWT's
     * `jti` claim so revocation can flip the row without touching the
     * plaintext. Refresh tokens are opaque random identifiers — we
     * hash and persist them the same way. `audience` carries the
     * RFC 8707 resource indicator bound at issue time.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const OAUTH_TOKENS = '{{%cortex_oauth_tokens}}';
}
