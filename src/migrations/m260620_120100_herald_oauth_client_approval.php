<?php

namespace craftpulse\herald\migrations;

use craft\db\Migration;
use craftpulse\herald\db\Table;

/**
 * =========================================================================
 * Adds the DCR approval gate to `herald_oauth_clients`.
 *
 * RFC 7591 Dynamic Client Registration lets any caller self-register a
 * client. Herald now starts every DCR-registered client UNAPPROVED:
 * the authorize + token flows reject an unapproved client with a clear
 * "pending admin approval" error until an admin approves it on the
 * Clients CP screen (or `Settings::$dcrAutoApprove` is on for trusted /
 * dev installs).
 *
 * One column lands:
 *   - `approved` — bool, default false. Out-of-band-seeded clients are
 *     back-filled to `true` on upgrade so an existing playground keeps
 *     working (only NEW DCR registrations face the gate); the
 *     application layer is what stamps `false` on fresh DCR rows.
 *
 * Idempotent: the `addColumn` is guarded by a `columnExists` check.
 * `Install.php`'s sibling oauth-creation migration carries the column
 * for fresh installs; this numbered migration upgrades already-installed
 * playgrounds via `craft up`.
 * =========================================================================
 *
 * @author Craftpulse
 * @since  5.0.0
 */
class m260620_120100_herald_oauth_client_approval extends Migration
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
        if (!$this->db->columnExists(Table::OAUTH_CLIENTS, 'approved')) {
            $this->addColumn(
                Table::OAUTH_CLIENTS,
                'approved',
                $this->boolean()->notNull()->defaultValue(false)->after('isPublic'),
            );
            $this->createIndex(null, Table::OAUTH_CLIENTS, ['approved']);

            // Back-fill: existing clients predate the gate, so approve
            // them to avoid breaking a live install. Only NEW DCR
            // registrations face the approval requirement.
            $this->update(Table::OAUTH_CLIENTS, ['approved' => true], '', [], false);
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
        if ($this->db->columnExists(Table::OAUTH_CLIENTS, 'approved')) {
            $this->dropColumn(Table::OAUTH_CLIENTS, 'approved');
        }
        return true;
    }
}
