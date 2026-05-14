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
}
