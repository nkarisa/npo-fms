<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * posting_accounts.entity_id: whose choice a row is.
 *
 * The rows held so far are the organisation's, and move onto the head office, which
 * holds what belongs to the whole organisation. An entity other than the head office
 * holds a row only for a role that pays out of its own bank or cash — the bank a
 * payroll is paid from, an advance issued by M-Pesa — because bank accounts are
 * each entity's own (PostingAccounts::ENTITY_ROLES). A role is then unique per entity.
 */
class PostingAccountsByEntity extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints, as users.last_entity_id is: a constraint would
        // rebuild the table on SQLite.
        $this->forge->addColumn('posting_accounts', ['entity_id' => $this->fk(true)]);

        $head = $this->db->query($this->sql('SELECT id FROM {entities} WHERE parent_id IS NULL ORDER BY id LIMIT 1'))->getRowArray();
        if ($head !== null) {
            $this->db->query($this->sql('UPDATE {posting_accounts} SET entity_id = ?'), [(int) $head['id']]);
        }

        $this->dropIndex('posting_accounts_role');
        $this->db->query($this->sql('CREATE UNIQUE INDEX {posting_accounts_entity_id_role} ON {posting_accounts} (entity_id, role)'));
    }

    public function down(): void
    {
        $this->db->query($this->sql('DELETE FROM {posting_accounts} WHERE entity_id <> (SELECT id FROM {entities} WHERE parent_id IS NULL ORDER BY id LIMIT 1)'));
        $this->dropIndex('posting_accounts_entity_id_role');
        $this->db->query($this->sql('CREATE UNIQUE INDEX {posting_accounts_role} ON {posting_accounts} (role)'));
        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {posting_accounts} DROP COLUMN entity_id'));
    }

    private function dropIndex(string $name): void
    {
        $this->db->query($this->isMySQL()
            ? $this->sql('ALTER TABLE {posting_accounts} DROP INDEX {' . $name . '}')
            : $this->sql('DROP INDEX {' . $name . '}'));
    }
}
