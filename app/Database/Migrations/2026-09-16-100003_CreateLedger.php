<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Journals, their lines and the bank and mobile-money accounts that sit on the
 * ledger.
 *
 * Every subledger (payables, receivables, payroll, assets, advances) posts here;
 * `source_type` / `source_id` link a journal back to the document that raised it
 * (a bill, or a payroll period's register), and `document_ref` is the reference
 * the entry carries — the source's own, or the next in the ELOG/JV, AC or AL
 * series for a manual entry. Supporting files are kept in `attachments`.
 * Balances, posting, immutability and closed-period rules are enforced by the
 * triggers in CreateIntegrityTriggers.
 */
class CreateLedger extends SchemaMigration
{
    public function up(): void
    {
        $this->table('journals', [
            'id'                  => $this->id(),
            'entity_id'           => $this->fk(),
            'period_id'           => $this->fk(),
            'reference'           => $this->string(20),
            'journal_date'        => $this->date(),
            'type'                => $this->string(12, false, 'standard'),
            'status'              => $this->string(16, false, 'draft'),
            'document_type_id'    => $this->fk(true),
            'document_ref'        => $this->string(60, true),
            'source_type'         => $this->string(30, true),
            'source_id'           => $this->fk(true),
            'memo'                => $this->string(255, true),
            'narration'           => $this->text(false),
            'prepared_by'         => $this->fk(),
            'submitted_at'        => $this->datetime(),
            'approved_by'         => $this->fk(true),
            'approved_at'         => $this->datetime(),
            'rejected_reason'     => $this->text(),
            'posted_at'           => $this->datetime(),
            'reverses_journal_id' => $this->fk(true),
        ] + $this->timestamps(), [
            // Not unique: several draft reversals of one journal can exist; the
            // service layer allows only one of them to post.
            'unique' => [['entity_id', 'reference']],
            'keys'   => [['entity_id', 'period_id', 'status'], 'journal_date', ['source_type', 'source_id'], 'prepared_by', 'reverses_journal_id'],
            'fks'    => ['entity_id' => 'entities', 'period_id' => 'periods', 'document_type_id' => 'document_types',
                'prepared_by' => 'users', 'approved_by' => 'users', 'reverses_journal_id' => 'journals'],
            'checks' => [
                'type'     => $this->in('type', ['standard', 'accrual', 'reversing', 'allocation', 'adjustment', 'recurring']),
                'status'   => $this->in('status', ['draft', 'pending_approval', 'posted', 'rejected', 'reversed']),
                // Segregation of duties (rule 6).
                'sod'      => 'approved_by IS NULL OR approved_by <> prepared_by',
                'posted'   => "status NOT IN ('posted', 'reversed') OR posted_at IS NOT NULL",
                'reversal' => "reverses_journal_id IS NULL OR type = 'reversing'",
                // A journal raised from a record names both the kind of record and which one.
                'source'   => '(source_type IS NULL) = (source_id IS NULL)',
            ],
        ]);

        // Three-dimensional coding (rule 7): account, fund and programme are all
        // required. Grant and county are the optional fourth and fifth segments.
        $this->table('journal_lines', [
            'id'           => $this->id(),
            'journal_id'   => $this->fk(),
            'line_no'      => $this->int(false, null, 'SMALLINT'),
            'account_id'   => $this->fk(),
            'fund_id'      => $this->fk(),
            'programme_id' => $this->fk(),
            'grant_id'     => $this->fk(true),
            'county_id'    => $this->fk(true),
            'description'  => $this->string(255),
            'debit'        => $this->money(),
            'credit'       => $this->money(),
        ], [
            'unique' => [['journal_id', 'line_no']],
            'keys'   => [['account_id', 'fund_id', 'programme_id'], 'fund_id', 'programme_id', 'grant_id'],
            'fks'    => ['journal_id' => ['journals', 'CASCADE'], 'account_id' => 'accounts', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants', 'county_id' => 'counties'],
            'checks' => [
                'one_side' => '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)',
            ],
        ]);

        // Entries raised on a calendar rather than on an event. A template holds the
        // lines; running it raises an ordinary journal (source_type
        // 'recurring_template') that is approved and posted like any other.
        $this->table('recurring_templates', [
            'id'            => $this->id(),
            'entity_id'     => $this->fk(),
            'code'          => $this->string(12),
            'name'          => $this->string(120),
            'type'          => $this->string(12, false, 'recurring'),
            'frequency'     => $this->string(10, false, 'monthly'),
            // "Last day of the month", "First day of the quarter", "25th of the month".
            'rule'          => $this->string(40),
            'next_due'      => $this->date(),
            'last_run_on'   => $this->date(true),
            'owner_user_id' => $this->fk(),
            'status'        => $this->string(8, false, 'active'),
            // Generated entries go straight to approval, or wait as drafts for the owner.
            'auto_submit'   => $this->bool(),
            'document_ref'  => $this->string(60),
            'narration'     => $this->text(false),
            'memo'          => $this->string(255, true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'code']],
            'fks'    => ['entity_id' => 'entities', 'owner_user_id' => 'users'],
            'checks' => [
                'type'      => $this->in('type', ['standard', 'accrual', 'reversing', 'allocation', 'adjustment', 'recurring']),
                'frequency' => $this->in('frequency', ['monthly', 'quarterly', 'annually']),
                'status'    => $this->in('status', ['active', 'paused']),
            ],
        ]);

        $this->table('recurring_template_lines', [
            'id'           => $this->id(),
            'template_id'  => $this->fk(),
            'line_no'      => $this->int(false, null, 'SMALLINT'),
            'account_id'   => $this->fk(),
            'fund_id'      => $this->fk(),
            'programme_id' => $this->fk(),
            'grant_id'     => $this->fk(true),
            'description'  => $this->string(255),
            'debit'        => $this->money(),
            'credit'       => $this->money(),
        ], [
            'unique' => [['template_id', 'line_no']],
            'fks'    => ['template_id' => ['recurring_templates', 'CASCADE'], 'account_id' => 'accounts', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants'],
            'checks' => [
                'one_side' => '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)',
            ],
        ]);

        // Each entry a template has generated. A run whose journal is in the ledger
        // reads its status from the journal; a run from before the ledger was
        // migrated, or whose draft was discarded, keeps the status it ended with.
        $this->table('recurring_template_runs', [
            'id'          => $this->id(),
            'template_id' => $this->fk(),
            'run_on'      => $this->date(),
            'journal_id'  => $this->fk(true),
            'reference'   => $this->string(20),
            'status'      => $this->string(16, true),
        ], [
            'keys'   => [['template_id', 'run_on'], 'journal_id'],
            'fks'    => ['template_id' => ['recurring_templates', 'CASCADE'], 'journal_id' => 'journals'],
            'checks' => [
                'status'  => 'status IS NULL OR ' . $this->in('status', ['draft', 'pending_approval', 'posted', 'reversed', 'discarded']),
                'journal' => '(journal_id IS NULL) = (status IS NOT NULL)',
            ],
        ]);

        $this->table('bank_accounts', [
            'id'             => $this->id(),
            'entity_id'      => $this->fk(),
            'account_id'     => $this->fk(),
            'name'           => $this->string(120),
            'short_name'     => $this->string(40),
            'kind'           => $this->string(12, false, 'bank'),
            'bank_name'      => $this->string(60, true),
            'account_number' => $this->string(40, true),
            'currency'       => $this->currency(),
            'status'         => $this->string(8, false, 'active'),
        ] + $this->timestamps(), [
            'unique' => ['account_id'],
            'keys'   => ['entity_id'],
            'fks'    => ['entity_id' => 'entities', 'account_id' => 'accounts'],
            'checks' => [
                'kind'   => $this->in('kind', ['bank', 'mobile_money', 'petty_cash']),
                'status' => $this->in('status', ['active', 'closed']),
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['bank_accounts', 'recurring_template_runs', 'recurring_template_lines', 'recurring_templates', 'journal_lines', 'journals']);
    }
}
