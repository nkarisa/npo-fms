<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Carrying a legacy system's balances onto this ledger.
 *
 * Balances are not stored as a column on an account: every figure this application
 * reports is derived from posted journal lines, so an opening balance is a journal
 * like any other — one entry dated the first day of the first period kept here,
 * marked `source_type` "fiscal_year" the way the chart already recognises
 * (JournalRepository reads its brought-forward figures from it).
 *
 * What these two tables add is the staging in front of that journal, so the trial
 * balance coming out of the old system can be read, checked and corrected without
 * touching the ledger:
 *
 * - conversion_batches: one load of one entity's trial balance — where it came
 *   from, the cut-off date, what it totalled, and the draft journal it became.
 *   A batch is `loaded` until it is committed; committing writes the journal as a
 *   DRAFT, which is then submitted and approved like any other entry, so the
 *   segregation-of-duties rule and the approval limits apply to the conversion too.
 * - conversion_lines: the file as rows, with whatever is wrong with each one.
 *   Deliberately no foreign key to accounts, funds or programmes: a code that does
 *   not exist yet is the thing the checks are there to report.
 */
class CreateOpeningBalances extends SchemaMigration
{
    public const STATUSES = ['loaded', 'committed', 'discarded'];

    public function up(): void
    {
        $this->table('conversion_batches', [
            'id'              => $this->id(),
            'entity_id'       => $this->fk(),
            'period_id'       => $this->fk(),
            // The last day the legacy system is authoritative for: the day before the period starts.
            'conversion_date' => $this->date(),
            'source_system'   => $this->string(80),
            'filename'        => $this->string(255),
            'status'          => $this->string(10, false, 'loaded'),
            'rows_read'       => $this->int(false, 0),
            'rows_loaded'     => $this->int(false, 0),
            'total_debit'     => $this->money(),
            'total_credit'    => $this->money(),
            'journal_id'      => $this->fk(true),
            'loaded_by'       => $this->fk(),
            'committed_by'    => $this->fk(true),
            'committed_at'    => $this->datetime(),
        ] + $this->timestamps(), [
            'keys'   => [['entity_id', 'status'], 'period_id', 'journal_id'],
            'fks'    => ['entity_id' => 'entities', 'period_id' => 'periods', 'journal_id' => 'journals',
                'loaded_by' => 'users', 'committed_by' => 'users'],
            'checks' => [
                'status'    => $this->in('status', self::STATUSES),
                'rows'      => 'rows_read >= 0 AND rows_loaded >= 0',
                'totals'    => 'total_debit >= 0 AND total_credit >= 0',
                'committed' => "status <> 'committed' OR (journal_id IS NOT NULL AND committed_by IS NOT NULL AND committed_at IS NOT NULL)",
            ],
        ]);

        // Codes, not ids: the file names accounts, funds and programmes the way the
        // old system did, and `problem` says why a row cannot become a posting line.
        $this->table('conversion_lines', [
            'id'             => $this->id(),
            'batch_id'       => $this->fk(),
            'row_no'         => $this->int(false, null),
            'account_code'   => $this->string(10),
            'fund_code'      => $this->string(20, true),
            'programme_code' => $this->string(20, true),
            'grant_ref'      => $this->string(40, true),
            'county_code'    => $this->string(3, true),
            'description'    => $this->string(255),
            'debit'          => $this->money(),
            'credit'         => $this->money(),
            'problem'        => $this->string(255, true),
        ], [
            'unique' => [['batch_id', 'row_no']],
            'keys'   => ['account_code'],
            'fks'    => ['batch_id' => ['conversion_batches', 'CASCADE']],
            'checks' => ['amounts' => 'debit >= 0 AND credit >= 0'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['conversion_lines', 'conversion_batches']);
    }
}
