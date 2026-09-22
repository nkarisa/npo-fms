<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Donor claims and other invoices, and the receipts that settle them.
 *
 * Grants arrive in USD and EUR: each invoice and receipt keeps the document
 * currency amount (`amount_fc`), the rate used and the KES amount the ledger
 * carries. Revaluation of open balances is not modelled yet (README, known gap 4).
 */
class CreateReceivables extends SchemaMigration
{
    public function up(): void
    {
        $this->table('invoices', [
            'id'           => $this->id(),
            'entity_id'    => $this->fk(),
            'reference'    => $this->string(20),
            'type'         => $this->string(20),
            'funder_id'    => $this->fk(true),
            'bill_to'      => $this->string(120, true),
            'grant_id'     => $this->fk(true),
            'programme_id' => $this->fk(),
            'fund_id'      => $this->fk(),
            'issue_date'   => $this->date(),
            'due_date'     => $this->date(),
            'currency'     => $this->currency(),
            'fx_rate'      => $this->fxRate(),
            'amount_fc'    => $this->money(),
            'amount'       => $this->money(),
            'status'       => $this->string(14, false, 'draft'),
            'basis'        => $this->text(),
            'journal_id'   => $this->fk(true),
            'prepared_by'  => $this->fk(),
            'approved_by'  => $this->fk(true),
            'written_off_reason' => $this->text(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => [['entity_id', 'status', 'due_date'], 'funder_id', 'grant_id'],
            'fks'    => ['entity_id' => 'entities', 'funder_id' => 'funders', 'grant_id' => 'grants',
                'programme_id' => 'programmes', 'fund_id' => 'funds', 'journal_id' => 'journals',
                'prepared_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'type'      => $this->in('type', ['grant_claim', 'cost_reimbursement', 'other_income']),
                'status'    => $this->in('status', ['draft', 'issued', 'part_received', 'received', 'written_off']),
                'customer'  => 'funder_id IS NOT NULL OR bill_to IS NOT NULL',
                'fx'        => "fx_rate > 0 AND (currency <> 'KES' OR fx_rate = 1)",
                'amounts'   => 'amount >= 0 AND amount_fc >= 0',
                'dates'     => 'due_date >= issue_date',
                'sod'       => 'approved_by IS NULL OR approved_by <> prepared_by',
                'write_off' => "status <> 'written_off' OR written_off_reason IS NOT NULL",
            ],
        ]);

        $this->table('invoice_lines', [
            'id'          => $this->id(),
            'invoice_id'  => $this->fk(),
            'line_no'     => $this->int(false, null, 'SMALLINT'),
            'account_id'  => $this->fk(),
            'description' => $this->string(255),
            'amount_fc'   => $this->money(),
            'amount'      => $this->money(),
        ], [
            'unique' => [['invoice_id', 'line_no']],
            'keys'   => ['account_id'],
            'fks'    => ['invoice_id' => ['invoices', 'CASCADE'], 'account_id' => 'accounts'],
            'checks' => ['amounts' => 'amount > 0 AND amount_fc > 0'],
        ]);

        // A receipt settles an invoice, a scheduled grant tranche, or neither
        // (unallocated money on account).
        $this->table('receipts', [
            'id'               => $this->id(),
            'entity_id'        => $this->fk(),
            'invoice_id'       => $this->fk(true),
            'grant_tranche_id' => $this->fk(true),
            'bank_account_id'  => $this->fk(),
            'received_on'      => $this->date(),
            'reference'        => $this->string(60),
            'currency'         => $this->currency(),
            'fx_rate'          => $this->fxRate(),
            'amount_fc'        => $this->money(),
            'amount'           => $this->money(),
            'note'             => $this->string(255, true),
            'journal_id'       => $this->fk(true),
            'created_by'       => $this->fk(),
            'created_at'       => $this->datetime(),
        ], [
            'unique' => [['bank_account_id', 'reference']],
            'keys'   => ['invoice_id', 'grant_tranche_id', 'received_on'],
            'fks'    => ['entity_id' => 'entities', 'invoice_id' => 'invoices', 'grant_tranche_id' => 'grant_tranches',
                'bank_account_id' => 'bank_accounts', 'journal_id' => 'journals', 'created_by' => 'users'],
            'checks' => [
                'amounts' => 'amount > 0 AND amount_fc > 0',
                'fx'      => "fx_rate > 0 AND (currency <> 'KES' OR fx_rate = 1)",
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['receipts', 'invoice_lines', 'invoices']);
    }
}
