<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * One ledger account may carry a cash account of each entity.
 *
 * The chart is shared and every journal belongs to an entity, so 1110 at the Coast
 * office is the Coast's lines on 1110 only: its own bank can sit on the same code
 * as the head office's and still reconcile to its own statement. An entity still
 * holds one cash account per ledger account, or a reconciliation could not say
 * which balance it agreed.
 *
 * The new key leads with account_id, which MySQL needs to keep an index behind the
 * foreign key to accounts, so it is created before the old one is dropped.
 */
class CashAccountsShareLedger extends SchemaMigration
{
    public function up(): void
    {
        $this->db->query($this->sql('CREATE UNIQUE INDEX {bank_accounts_account_id_entity_id} ON {bank_accounts} (account_id, entity_id)'));
        $this->dropIndex('bank_accounts_account_id');
    }

    public function down(): void
    {
        // Unique again only when no two entities share a ledger account: the cash
        // accounts of those that do are kept, under a plain index.
        $shared = $this->db->query($this->sql('SELECT account_id FROM {bank_accounts} GROUP BY account_id HAVING COUNT(*) > 1'))->getResultArray();
        $this->db->query($this->sql('CREATE ' . ($shared === [] ? 'UNIQUE ' : '') . 'INDEX {bank_accounts_account_id} ON {bank_accounts} (account_id)'));
        $this->dropIndex('bank_accounts_account_id_entity_id');
    }

    private function dropIndex(string $name): void
    {
        $this->db->query($this->isMySQL()
            ? $this->sql('ALTER TABLE {bank_accounts} DROP INDEX {' . $name . '}')
            : $this->sql('DROP INDEX {' . $name . '}'));
    }
}
