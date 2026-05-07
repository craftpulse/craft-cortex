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
 * @since  0.1.0
 */
final class Table
{
    /**
     * Runtime allowlist override entries — admin-editable allowlist
     * patterns that auto-expire (Gate 6.5). Project-config defaults +
     * `config/cortex.php` overrides remain the canonical source; this
     * table layers temporary additions on top.
     *
     * @author Craftpulse
     * @since  0.1.0
     */
    public const RUNTIME_OVERRIDES = '{{%cortex_runtime_overrides}}';
}
