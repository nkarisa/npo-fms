<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 payables screen records that the first schema had no place for.
 *
 * - spend_categories: the withholding tax policy. A bill's rate defaults to its
 *   spend category's rate; tax policy is data, not code.
 * - bills: how the supplier is to be paid (EFT, M-Pesa or cheque, from the bill's
 *   bank account), why a bill's withholding rate differs from policy, why it was
 *   coded over the remaining budget, and the remittance that paid its withholding
 *   tax over to KRA.
 * - wht_remittances: withholding tax paid over to KRA, one remittance a month.
 * - payment_runs and wht_remittances: the reference for an authority outside the
 *   system (a board minute) when the amount escalates beyond the in-system approvers.
 *
 * The new id column on bills is indexed but carries no foreign key: adding a
 * constraint to an existing table means SQLite rebuilding it, which the schema's
 * triggers and views would not survive. The service layer checks the new bill
 * columns' values for the same reason.
 */
class AlignPayables extends SchemaMigration
{
    public function up(): void
    {
        $this->table('spend_categories', [
            'id'           => $this->id(),
            'name'         => $this->string(60),
            'wht_rate_pct' => $this->pct(),
        ] + $this->timestamps(), [
            'unique' => ['name'],
            'checks' => ['wht' => 'wht_rate_pct >= 0 AND wht_rate_pct <= 100'],
        ]);

        $this->table('wht_remittances', [
            'id'              => $this->id(),
            'entity_id'       => $this->fk(),
            'reference'       => $this->string(20),
            // The month the remittance was made in. It clears everything withheld up
            // to then, and KRA expects each month's tax by the 20th of the next.
            'period_id'       => $this->fk(),
            'remitted_on'     => $this->date(),
            'bank_account_id' => $this->fk(),
            'amount'          => $this->money(),
            'journal_id'      => $this->fk(true),
            'authority_ref'   => $this->string(60, true),
            'remitted_by'     => $this->fk(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference'], ['entity_id', 'period_id']],
            'fks'    => ['entity_id' => 'entities', 'period_id' => 'periods', 'bank_account_id' => 'bank_accounts',
                'journal_id' => 'journals', 'remitted_by' => 'users'],
            'checks' => ['amount' => 'amount > 0'],
        ]);

        $this->forge->addColumn('bills', [
            // eft, mpesa or cheque; the bank account says which account and currency.
            'payment_method'      => $this->string(8, true),
            'wht_override_reason' => $this->text(),
            'over_budget_reason'  => $this->text(),
            'wht_remittance_id'   => $this->fk(true),
        ]);
        $this->db->query($this->sql('CREATE INDEX {bills}_wht_remittance_id ON {bills} (wht_remittance_id)'));

        $this->forge->addColumn('payment_runs', ['authority_ref' => $this->string(60, true)]);
    }

    public function down(): void
    {
        $this->db->query($this->sql('DROP INDEX {bills}_wht_remittance_id' . ($this->isMySQL() ? ' ON {bills}' : '')));
        // Dropped in place: Forge rebuilds the table on SQLite, and the reporting
        // views that read bills do not survive the rebuild.
        $this->db->query($this->sql('ALTER TABLE {payment_runs} DROP COLUMN authority_ref'));
        foreach (['payment_method', 'wht_override_reason', 'over_budget_reason', 'wht_remittance_id'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {bills} DROP COLUMN {$column}"));
        }
        $this->dropTables(['wht_remittances', 'spend_categories']);
    }
}
