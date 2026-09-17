<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use App\Libraries\Clock;

/**
 * The allowance for doubtful debts: receivables the organisation does not expect
 * to collect in full, provided for before they are written off.
 *
 * - receivable_allowances: every movement in the allowance held against one
 *   invoice, signed (+ raised, − released or used), with the journal that posted
 *   it. What an invoice carries is the sum of its movements. The basis says why it
 *   moved: set on the claim by hand ('specific'), by the ageing rates ('ageing'),
 *   released because money came in ('receipt'), or used by a write-off ('write_off').
 * - allowance_rates: the share of the outstanding balance provided for in each
 *   ageing bucket, applied to claims no one has set an allowance on by hand.
 * - 1215 Allowance for doubtful debts, a contra account under 1200 that carries a
 *   credit balance, so the statement of financial position shows receivables net.
 *   5370 is renamed Bad and doubtful debts: it now also takes the allowance charge.
 *
 * On a database that has not been seeded the chart is left to the seeder.
 */
class CreateDoubtfulDebtAllowance extends SchemaMigration
{
    public const BASES = ['specific', 'ageing', 'receipt', 'write_off'];

    /** bucket → % of the outstanding balance: nothing until a claim is over a month late. */
    public const DEFAULT_RATES = ['Current' => 0, '1–30 days' => 0, '31–60 days' => 10, '61–90 days' => 25, 'Over 90 days' => 50];

    public function up(): void
    {
        $this->table('allowance_rates', [
            'id'         => $this->id(),
            'bucket'     => $this->string(20),
            'sort_order' => $this->int(false, 0, 'SMALLINT'),
            'pct'        => $this->pct(),
        ] + $this->timestamps(), [
            'unique' => ['bucket'],
            'checks' => ['pct' => 'pct >= 0 AND pct <= 100'],
        ]);

        $now = Clock::timestamp();
        $order = 0;
        foreach (self::DEFAULT_RATES as $bucket => $pct) {
            $this->db->table('allowance_rates')->insert(['bucket' => $bucket, 'sort_order' => ++$order, 'pct' => $pct, 'created_at' => $now]);
        }

        $this->table('receivable_allowances', [
            'id'         => $this->id(),
            'entity_id'  => $this->fk(),
            'invoice_id' => $this->fk(),
            'basis'      => $this->string(10),
            'amount'     => $this->money(),
            'reason'     => $this->text(),
            'journal_id' => $this->fk(),
            'created_by' => $this->fk(),
            'created_at' => $this->datetime(),
        ], [
            'keys'   => ['invoice_id', 'journal_id'],
            'fks'    => ['entity_id' => 'entities', 'invoice_id' => 'invoices', 'journal_id' => 'journals', 'created_by' => 'users'],
            'checks' => [
                'basis'  => $this->in('basis', self::BASES),
                'amount' => 'amount <> 0',
            ],
        ]);

        $accounts = $this->db->table('accounts');
        $heading = $this->db->table('accounts')->where('code', '1200')->get()->getRowArray();
        $badDebts = $this->db->table('accounts')->where('code', '5370')->get()->getRowArray();
        if ($heading === null || $badDebts === null) {
            return;
        }
        if ($this->db->table('accounts')->where('code', '1215')->countAllResults() === 0) {
            $accounts->insert([
                'code' => '1215', 'name' => 'Allowance for doubtful debts', 'type' => 'asset', 'parent_id' => $heading['id'], 'level' => 2,
                'is_leaf' => 1, 'restriction' => 'unrestricted', 'default_fund_id' => $badDebts['default_fund_id'],
                'default_programme_id' => $badDebts['default_programme_id'], 'status' => 'active', 'currency' => 'KES', 'created_at' => $now,
            ]);
        }
        $this->db->table('accounts')->where('code', '5370')->update(['name' => 'Bad and doubtful debts', 'updated_at' => $now]);
    }

    public function down(): void
    {
        $this->dropTables(['receivable_allowances', 'allowance_rates']);

        // 1215 stays if anything has posted to it: posted journals cannot change.
        $id = $this->db->table('accounts')->select('id')->where('code', '1215')->get()->getRowArray()['id'] ?? null;
        if ($id !== null && $this->db->table('journal_lines')->where('account_id', $id)->countAllResults() === 0) {
            $this->db->table('accounts')->where('id', $id)->delete();
        }
        $this->db->table('accounts')->where('code', '5370')->update(['name' => 'Bad debts written off']);
    }
}
