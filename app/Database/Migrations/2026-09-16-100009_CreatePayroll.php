<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Staff, pay, payroll runs and payslips.
 *
 * This is personal data under Kenya's Data Protection Act 2019. Identifiers
 * (KRA PIN, NSSF, SHIF, bank account) are stored encrypted by the application
 * (`*_encrypted` columns hold ciphertext; only the last four digits of the bank
 * account are kept in clear for display), and every read of a payroll record is
 * written to personal_data_access_log.
 */
class CreatePayroll extends SchemaMigration
{
    public function up(): void
    {
        // Earnings, deductions and employer contributions, including the statutory ones.
        $this->table('pay_components', [
            'id'          => $this->id(),
            'key'         => $this->string(30),
            'name'        => $this->string(60),
            'kind'        => $this->string(10),
            'is_taxable'  => $this->bool(),
            'is_statutory' => $this->bool(),
            'account_id'  => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['key'],
            'fks'    => ['account_id' => 'accounts'],
            'checks' => ['kind' => $this->in('kind', ['earning', 'deduction', 'employer'])],
        ]);

        // PAYE bands and other statutory rates, dated so a Finance Act change is a new row.
        $this->table('statutory_rates', [
            'id'             => $this->id(),
            'scheme'         => $this->string(16),
            'band_order'     => $this->int(false, 1, 'SMALLINT'),
            'lower_bound'    => $this->money(),
            'upper_bound'    => $this->money(true),
            'rate_pct'       => $this->pct(),
            'fixed_amount'   => $this->money(true),
            'effective_from' => $this->date(),
            'effective_to'   => $this->date(true),
        ] + $this->timestamps(), [
            'unique' => [['scheme', 'effective_from', 'band_order']],
            'checks' => [
                'scheme' => $this->in('scheme', ['paye', 'personal_relief', 'nssf', 'shif', 'housing_levy', 'nita']),
                'bounds' => 'upper_bound IS NULL OR upper_bound > lower_bound',
                'dates'  => 'effective_to IS NULL OR effective_to >= effective_from',
            ],
        ]);

        $this->table('staff', [
            'id'                     => $this->id(),
            'entity_id'              => $this->fk(),
            'staff_no'               => $this->string(12),
            'user_id'                => $this->fk(true),
            'name'                   => $this->string(120),
            'job_title'              => $this->string(120),
            'grade'                  => $this->string(5),
            'joined_on'              => $this->date(),
            'left_on'                => $this->date(true),
            'kra_pin_encrypted'      => $this->text(),
            'nssf_no_encrypted'      => $this->text(),
            'shif_no_encrypted'      => $this->text(),
            'bank_name'              => $this->string(60, true),
            'bank_account_encrypted' => $this->text(),
            'bank_account_last4'     => $this->string(4, true),
        ] + $this->timestamps(), [
            'unique' => ['staff_no', 'user_id'],
            'keys'   => ['entity_id'],
            'fks'    => ['entity_id' => 'entities', 'user_id' => 'users'],
            'checks' => ['dates' => 'left_on IS NULL OR left_on >= joined_on'],
        ]);

        // Recurring pay: basic, house and transport allowances, acting allowance,
        // SACCO and other standing deductions. Dated so history is kept.
        $this->table('staff_pay_items', [
            'id'               => $this->id(),
            'staff_id'         => $this->fk(),
            'pay_component_id' => $this->fk(),
            'amount'           => $this->money(),
            'effective_from'   => $this->date(),
            'effective_to'     => $this->date(true),
            'note'             => $this->string(255, true),
        ] + $this->timestamps(), [
            'keys'   => [['staff_id', 'effective_from'], 'pay_component_id'],
            'fks'    => ['staff_id' => ['staff', 'CASCADE'], 'pay_component_id' => 'pay_components'],
            'checks' => [
                'amount' => 'amount >= 0',
                'dates'  => 'effective_to IS NULL OR effective_to >= effective_from',
            ],
        ]);

        // How a person's cost splits across funds, grants and programmes. The
        // percentages for one person and date must total 100 (service layer).
        $this->table('staff_allocations', [
            'id'             => $this->id(),
            'staff_id'       => $this->fk(),
            'fund_id'        => $this->fk(),
            'programme_id'   => $this->fk(),
            'grant_id'       => $this->fk(true),
            'allocation_pct' => $this->pct(),
            'effective_from' => $this->date(),
            'effective_to'   => $this->date(true),
        ] + $this->timestamps(), [
            'keys'   => [['staff_id', 'effective_from'], 'grant_id'],
            'fks'    => ['staff_id' => ['staff', 'CASCADE'], 'fund_id' => 'funds', 'programme_id' => 'programmes',
                'grant_id' => 'grants'],
            'checks' => [
                'pct'   => 'allocation_pct > 0 AND allocation_pct <= 100',
                'dates' => 'effective_to IS NULL OR effective_to >= effective_from',
            ],
        ]);

        $this->table('payroll_runs', [
            'id'               => $this->id(),
            'entity_id'        => $this->fk(),
            'period_id'        => $this->fk(),
            'status'           => $this->string(16, false, 'draft'),
            'gross'            => $this->money(),
            'total_deductions' => $this->money(),
            'net'              => $this->money(),
            'employer_cost'    => $this->money(),
            'prepared_by'      => $this->fk(),
            'approved_by'      => $this->fk(true),
            'approved_at'      => $this->datetime(),
            'posted_at'        => $this->datetime(),
            'journal_id'       => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'period_id']],
            'fks'    => ['entity_id' => 'entities', 'period_id' => 'periods', 'prepared_by' => 'users',
                'approved_by' => 'users', 'journal_id' => 'journals'],
            'checks' => [
                'status' => $this->in('status', ['draft', 'pending_approval', 'approved', 'posted', 'rejected']),
                'sod'    => 'approved_by IS NULL OR approved_by <> prepared_by',
            ],
        ]);

        // days_payable < days_in_month for joiners and leavers, who are paid pro rata.
        $this->table('payslips', [
            'id'               => $this->id(),
            'payroll_run_id'   => $this->fk(),
            'staff_id'         => $this->fk(),
            'days_payable'     => $this->int(false, null, 'SMALLINT'),
            'days_in_month'    => $this->int(false, null, 'SMALLINT'),
            'gross'            => $this->money(),
            'taxable_pay'      => $this->money(),
            'paye'             => $this->money(),
            'nssf'             => $this->money(),
            'shif'             => $this->money(),
            'housing_levy'     => $this->money(),
            'other_deductions' => $this->money(),
            'net_pay'          => $this->money(),
        ] + $this->timestamps(), [
            'unique' => [['payroll_run_id', 'staff_id']],
            'keys'   => ['staff_id'],
            'fks'    => ['payroll_run_id' => ['payroll_runs', 'CASCADE'], 'staff_id' => 'staff'],
            'checks' => [
                'days'   => 'days_payable >= 0 AND days_payable <= days_in_month',
                'values' => 'gross >= 0 AND net_pay >= 0',
            ],
        ]);

        $this->table('payslip_lines', [
            'id'               => $this->id(),
            'payslip_id'       => $this->fk(),
            'pay_component_id' => $this->fk(),
            'amount'           => $this->money(),
        ], [
            'unique' => [['payslip_id', 'pay_component_id']],
            'fks'    => ['payslip_id' => ['payslips', 'CASCADE'], 'pay_component_id' => 'pay_components'],
        ]);

        // Append-only (see CreateIntegrityTriggers).
        $this->table('personal_data_access_log', [
            'id'          => $this->id(),
            'user_id'     => $this->fk(),
            'action'      => $this->string(8),
            'object_type' => $this->string(20),
            'object_id'   => $this->fk(true),
            'ip_address'  => $this->string(45, true),
            'user_agent'  => $this->string(255, true),
            'accessed_at' => $this->datetime(false),
        ], [
            'keys'   => [['object_type', 'object_id'], ['user_id', 'accessed_at']],
            'fks'    => ['user_id' => 'users'],
            'checks' => [
                'action' => $this->in('action', ['view', 'export', 'update']),
                'object' => $this->in('object_type', ['staff', 'payslip', 'payroll_run']),
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['personal_data_access_log', 'payslip_lines', 'payslips', 'payroll_runs', 'staff_allocations',
            'staff_pay_items', 'staff', 'statutory_rates', 'pay_components']);
    }
}
