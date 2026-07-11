<?php

namespace craftpulse\herald\db;

/**
 * =========================================================================
 * Database table-name constants for herald.
 *
 * Matches the canonical Craft pattern (`craft\db\Table`) so herald's
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
     * `config/herald.php` overrides remain the canonical source; this
     * table layers temporary additions on top.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const RUNTIME_OVERRIDES = '{{%herald_runtime_overrides}}';

    /**
     * Admin-issued bearer tokens bound to a Craft user. Only the SHA-
     * 256 hash of the plaintext is stored; the plaintext is surfaced
     * once at issuance by `herald/token/issue` and never returned
     * again by any service method. Soft-delete via `dateDeleted`
     * keeps the lookup index small while preserving audit history.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const TOKENS = '{{%herald_tokens}}';

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
    public const OAUTH_CLIENTS = '{{%herald_oauth_clients}}';

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
    public const OAUTH_CODES = '{{%herald_oauth_codes}}';

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
    public const OAUTH_TOKENS = '{{%herald_oauth_tokens}}';

    /**
     * HTTP-transport tool-invocation audit log. One row per
     * authenticated `tools/call` over the HTTP transport. stdio
     * invocations leave the KV log line in Craft's logger only —
     * the DB rows are the HTTP-only audit surface per PLANNING.md
     * §4.9 and Gate 7.5's locked decision 11. Round-trip invariant:
     * every field on the KV log line has a corresponding column
     * here, so `InvocationLogger::formatEntry(row)` reconstitutes
     * the same byte-form a SIEM forwarder would see in the file
     * log.
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const INVOCATIONS = '{{%herald_invocations}}';

    /**
     * Custom-skill element instances — author-able overrides for the
     * bundled `michtio/craftcms-claude-skills` corpus. One row per
     * element, joined to `elements(id)` via FK with ON DELETE CASCADE.
     * Handle is globally unique (the natural key); description is a
     * native column so list-view paths don't pay for a content-table
     * join. The body lives in the field-layout custom-field surface
     * (per Gate 8.6 locked decision 13).
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public const SKILLS = '{{%herald_skills}}';
}
