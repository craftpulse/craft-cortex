<?php

namespace craftpulse\cortex\migrations;

use craft\db\Migration;
use craftpulse\cortex\db\Table;

/**
 * =========================================================================
 * Adds refresh-token rotation lineage to `cortex_oauth_tokens`.
 *
 * Refresh-token rotation (league rotates + revokes the old refresh
 * token on every exchange) was already happening, but Cortex had no way
 * to detect a *replay* of an already-consumed refresh token — the
 * hallmark of a stolen token. RFC 6819 §5.2.2.3 / the OAuth 2.1
 * refresh-rotation BCP prescribe family-wide revocation on such a
 * replay.
 *
 * Two columns land:
 *   - `familyId` — a per-authorization lineage identifier. Every
 *     access + refresh token minted from a single `/oauth/authorize`
 *     grant (and every rotation descended from it) shares one
 *     `familyId`. Indexed so the theft-detection revoke is a single
 *     keyed `UPDATE`.
 *   - `consumedAt` — set when a refresh token is rotated away. A
 *     present-but-revoked refresh token whose `consumedAt` is set,
 *     presented again, is a replay → the whole family is revoked.
 *
 * Idempotent: each `addColumn` is guarded by a `columnExists` check.
 * Pre-release plugin, so `Install.php` carries the columns for fresh
 * installs and this numbered migration upgrades already-installed
 * playgrounds via `craft up`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260620_120000_cortex_oauth_token_family extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::OAUTH_TOKENS, 'familyId')) {
            $this->addColumn(Table::OAUTH_TOKENS, 'familyId', $this->string(36)->null()->after('clientId'));
            $this->createIndex(null, Table::OAUTH_TOKENS, ['familyId']);
        }

        if (!$this->db->columnExists(Table::OAUTH_TOKENS, 'consumedAt')) {
            $this->addColumn(Table::OAUTH_TOKENS, 'consumedAt', $this->dateTime()->null()->after('dateRevoked'));
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @author Craftpulse
     * @since  5.0.0
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::OAUTH_TOKENS, 'consumedAt')) {
            $this->dropColumn(Table::OAUTH_TOKENS, 'consumedAt');
        }
        if ($this->db->columnExists(Table::OAUTH_TOKENS, 'familyId')) {
            // Drop the index before the column for symmetry with safeUp's
            // createIndex (and so the column drop doesn't trip on a
            // lingering index on engines that don't auto-drop it).
            $this->dropIndexIfExists(Table::OAUTH_TOKENS, ['familyId']);
            $this->dropColumn(Table::OAUTH_TOKENS, 'familyId');
        }
        return true;
    }
}
