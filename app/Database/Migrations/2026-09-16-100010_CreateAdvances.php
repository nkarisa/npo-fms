<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Staff and observer advances: issue, surrender against receipts, and recovery of
 * anything not accounted for (through payroll or a refund).
 *
 * Observers are not staff, and not every staff holder is on this entity's payroll
 * register, so an advance names its holder directly and links to a staff record
 * only when there is one. requested_by is null when holders request for themselves
 * without a system account.
 */
class CreateAdvances extends SchemaMigration
{
    public function up(): void
    {
        $this->table('advances', [
            'id'               => $this->id(),
            'entity_id'        => $this->fk(),
            'reference'        => $this->string(20),
            'holder_kind'      => $this->string(8),
            'staff_id'         => $this->fk(true),
            'holder_name'      => $this->string(120),
            'holder_role'      => $this->string(80, true),
            'holder_phone'     => $this->string(20, true),
            'purpose'          => $this->text(false),
            'programme_id'     => $this->fk(),
            'fund_id'          => $this->fk(),
            'grant_id'         => $this->fk(true),
            'amount'           => $this->money(),
            'requested_on'     => $this->date(),
            'due_on'           => $this->date(),
            'status'           => $this->string(12, false, 'requested'),
            'payment_method'   => $this->string(6, true),
            'bank_account_id'  => $this->fk(true),
            'issued_on'        => $this->date(true),
            'issue_journal_id' => $this->fk(true),
            'requested_by'     => $this->fk(true),
            'approved_by'      => $this->fk(true),
            'approved_at'      => $this->datetime(),
            'rejected_reason'  => $this->text(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => [['entity_id', 'status', 'due_on'], 'staff_id', 'grant_id'],
            'fks'    => ['entity_id' => 'entities', 'staff_id' => 'staff', 'programme_id' => 'programmes',
                'fund_id' => 'funds', 'grant_id' => 'grants', 'bank_account_id' => 'bank_accounts',
                'issue_journal_id' => 'journals', 'requested_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'kind'   => $this->in('holder_kind', ['staff', 'observer']),
                'status' => $this->in('status', ['requested', 'approved', 'issued', 'surrendered', 'recovered', 'rejected']),
                'method' => 'payment_method IS NULL OR ' . $this->in('payment_method', ['mpesa', 'bank', 'cash']),
                'issued' => "status IN ('requested', 'approved', 'rejected') OR (issued_on IS NOT NULL AND payment_method IS NOT NULL)",
                'amount' => 'amount > 0',
                'dates'  => 'due_on >= requested_on',
                'sod'    => 'approved_by IS NULL OR approved_by <> requested_by',
            ],
        ]);

        // Receipts the holder brings back, each charged to an expense line.
        $this->table('advance_surrenders', [
            'id'             => $this->id(),
            'advance_id'     => $this->fk(),
            'account_id'     => $this->fk(),
            'description'    => $this->string(255),
            'amount'         => $this->money(),
            'receipt_ref'    => $this->string(60, true),
            'surrendered_on' => $this->date(),
            'journal_id'     => $this->fk(true),
            'created_by'     => $this->fk(),
            'created_at'     => $this->datetime(),
        ], [
            'keys'   => ['advance_id', 'account_id'],
            'fks'    => ['advance_id' => 'advances', 'account_id' => 'accounts', 'journal_id' => 'journals',
                'created_by' => 'users'],
            'checks' => ['amount' => 'amount > 0'],
        ]);

        $this->table('advance_recoveries', [
            'id'           => $this->id(),
            'advance_id'   => $this->fk(),
            'method'       => $this->string(8),
            'amount'       => $this->money(),
            'recovered_on' => $this->date(),
            'payslip_id'   => $this->fk(true),
            'reference'    => $this->string(60, true),
            'journal_id'   => $this->fk(true),
            'created_by'   => $this->fk(),
            'created_at'   => $this->datetime(),
        ], [
            'keys'   => ['advance_id', 'payslip_id'],
            'fks'    => ['advance_id' => 'advances', 'payslip_id' => 'payslips', 'journal_id' => 'journals',
                'created_by' => 'users'],
            'checks' => [
                'method'  => $this->in('method', ['payroll', 'mpesa', 'bank', 'cash']),
                'amount'  => 'amount > 0',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['advance_recoveries', 'advance_surrenders', 'advances']);
    }
}
