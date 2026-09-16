<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Supplier bills, payment runs and payments.
 *
 * A bill may be paid in more than one payment, so "part paid" and "overdue" are
 * derived from payments and due_date rather than stored.
 */
class CreatePayables extends SchemaMigration
{
    public function up(): void
    {
        $this->table('payment_runs', [
            'id'              => $this->id(),
            'entity_id'       => $this->fk(),
            'reference'       => $this->string(20),
            'run_date'        => $this->date(),
            'bank_account_id' => $this->fk(),
            'total'           => $this->money(),
            'status'          => $this->string(16, false, 'draft'),
            'prepared_by'     => $this->fk(),
            'approved_by'     => $this->fk(true),
            'approved_at'     => $this->datetime(),
            'paid_at'         => $this->datetime(),
            'journal_id'      => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'fks'    => ['entity_id' => 'entities', 'bank_account_id' => 'bank_accounts', 'prepared_by' => 'users',
                'approved_by' => 'users', 'journal_id' => 'journals'],
            'checks' => [
                'status' => $this->in('status', ['draft', 'pending_approval', 'approved', 'paid', 'rejected']),
                'sod'    => 'approved_by IS NULL OR approved_by <> prepared_by',
                'total'  => 'total >= 0',
            ],
        ]);

        $this->table('bills', [
            'id'                       => $this->id(),
            'entity_id'                => $this->fk(),
            'reference'                => $this->string(20),
            'supplier_id'              => $this->fk(),
            'supplier_invoice_no'      => $this->string(60, true),
            'invoice_date'             => $this->date(),
            'due_date'                 => $this->date(),
            'terms_days'               => $this->int(false, 30, 'SMALLINT'),
            'subtotal'                 => $this->money(),
            'vat'                      => $this->money(),
            'wht_rate_pct'             => $this->pct(),
            'wht_amount'               => $this->money(),
            'total'                    => $this->money(),
            'status'                   => $this->string(16, false, 'draft'),
            'pay_from_bank_account_id' => $this->fk(true),
            'payment_run_id'           => $this->fk(true),
            'purchase_order_id'        => $this->fk(true),
            'goods_received_note_id'   => $this->fk(true),
            'journal_id'               => $this->fk(true),
            'prepared_by'              => $this->fk(),
            'approved_by'              => $this->fk(true),
            'approved_at'              => $this->datetime(),
            'rejected_reason'          => $this->text(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference'], ['supplier_id', 'supplier_invoice_no']],
            'keys'   => [['entity_id', 'status', 'due_date'], 'purchase_order_id', 'payment_run_id'],
            'fks'    => ['entity_id' => 'entities', 'supplier_id' => 'suppliers', 'pay_from_bank_account_id' => 'bank_accounts',
                'payment_run_id' => 'payment_runs',
                'purchase_order_id' => 'purchase_orders', 'goods_received_note_id' => 'goods_received_notes',
                'journal_id' => 'journals', 'prepared_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'status'  => $this->in('status', ['draft', 'pending_approval', 'approved', 'scheduled', 'paid', 'rejected']),
                'sod'     => 'approved_by IS NULL OR approved_by <> prepared_by',
                'dates'   => 'due_date >= invoice_date',
                'amounts' => 'subtotal >= 0 AND vat >= 0 AND wht_amount >= 0 AND total >= 0 AND wht_rate_pct >= 0 AND wht_rate_pct <= 100',
            ],
        ]);

        $this->table('bill_lines', [
            'id'                     => $this->id(),
            'bill_id'                => $this->fk(),
            'line_no'                => $this->int(false, null, 'SMALLINT'),
            'account_id'             => $this->fk(),
            'fund_id'                => $this->fk(),
            'programme_id'           => $this->fk(),
            'grant_id'               => $this->fk(true),
            'purchase_order_line_id' => $this->fk(true),
            'description'            => $this->string(255),
            'amount'                 => $this->money(),
        ], [
            'unique' => [['bill_id', 'line_no']],
            'keys'   => [['account_id', 'fund_id', 'programme_id'], 'purchase_order_line_id'],
            'fks'    => ['bill_id' => ['bills', 'CASCADE'], 'account_id' => 'accounts', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants', 'purchase_order_line_id' => 'purchase_order_lines'],
            'checks' => ['amount' => 'amount > 0'],
        ]);

        $this->table('payments', [
            'id'              => $this->id(),
            'entity_id'       => $this->fk(),
            'payment_run_id'  => $this->fk(true),
            'bill_id'         => $this->fk(),
            'bank_account_id' => $this->fk(),
            'paid_on'         => $this->date(),
            'method'          => $this->string(8),
            'amount'          => $this->money(),
            'wht_amount'      => $this->money(),
            'reference'       => $this->string(60, true),
            'journal_id'      => $this->fk(true),
            'created_by'      => $this->fk(),
            'created_at'      => $this->datetime(),
        ], [
            'keys'   => ['payment_run_id', 'bill_id', ['bank_account_id', 'paid_on']],
            'fks'    => ['entity_id' => 'entities', 'payment_run_id' => 'payment_runs', 'bill_id' => 'bills',
                'bank_account_id' => 'bank_accounts', 'journal_id' => 'journals', 'created_by' => 'users'],
            'checks' => [
                'method'  => $this->in('method', ['eft', 'mpesa', 'cheque', 'cash']),
                'amounts' => 'amount > 0 AND wht_amount >= 0',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['payments', 'bill_lines', 'bills', 'payment_runs']);
    }
}
