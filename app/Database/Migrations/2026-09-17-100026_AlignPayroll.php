<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 Payroll screen does that the first schema had no place for.
 *
 * The screen is a run for one month at a time, moved through prepared →
 * approved → posted → remitted, so a run needs its own reference, the count of
 * staff it covered, and the two facts the first schema left out: when it was
 * submitted for approval, and when the statutory liabilities it raised were
 * remitted and against which journal.
 *
 * - payroll_runs.reference: PR-26-0001, the run's own document number. The
 *   journal it posts keeps its own JV reference; this is the run's.
 * - payroll_runs.remitted_at / remittance_journal_id: payroll raises the 22xx
 *   liabilities, and remitting them is a second journal that clears them. Until
 *   it is posted the run is complete but the money is still owed.
 * - payslips.employer_cost: the employer's own contributions for one post — NSSF
 *   match, housing levy and NITA. Not deducted from pay, so not in net_pay, but
 *   part of what the post costs and what is charged to the grant.
 * - payslip_allocations: how one payslip's total cost was split across funds,
 *   grants and programmes at the moment it was run. The staff allocation in
 *   force can be changed afterwards; what a posted run charged cannot.
 */
class AlignPayroll extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering these tables on SQLite would rebuild
        // them, which the schema's triggers and views would not survive. The service
        // layer checks the values.
        $this->forge->addColumn('payroll_runs', [
            'reference'             => $this->string(20, true),
            'staff_count'           => $this->int(true, null, 'SMALLINT'),
            'submitted_at'          => $this->datetime(),
            'remitted_at'           => $this->datetime(),
            'remittance_journal_id' => $this->fk(true),
        ]);
        $this->forge->addColumn('payslips', ['employer_cost' => $this->money(true)]);

        // The charge behind a payslip: what a posted run actually put against each
        // grant. Percentages for one payslip total 100 (service layer).
        $this->table('payslip_allocations', [
            'id'             => $this->id(),
            'payslip_id'     => $this->fk(),
            'fund_id'        => $this->fk(),
            'programme_id'   => $this->fk(),
            'grant_id'       => $this->fk(true),
            'allocation_pct' => $this->pct(),
            'gross'          => $this->money(),
            'employer_cost'  => $this->money(),
        ], [
            'keys'   => ['payslip_id', 'grant_id'],
            'fks'    => ['payslip_id' => ['payslips', 'CASCADE'], 'fund_id' => 'funds', 'programme_id' => 'programmes',
                'grant_id' => 'grants'],
            'checks' => [
                'pct'    => 'allocation_pct > 0 AND allocation_pct <= 100',
                'values' => 'gross >= 0 AND employer_cost >= 0',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['payslip_allocations']);

        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {payslips} DROP COLUMN employer_cost'));
        foreach (['reference', 'staff_count', 'submitted_at', 'remitted_at', 'remittance_journal_id'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {payroll_runs} DROP COLUMN {$column}"));
        }
    }
}
