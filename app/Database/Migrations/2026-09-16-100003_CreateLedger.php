<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Journals, their lines and the bank and mobile-money accounts that sit on the
 * ledger.
 *
 * Every subledger (payables, receivables, payroll, assets, advances) posts here;
 * `source_type` / `source_id` link a journal back to the document that raised it.
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
        $this->dropTables(['bank_accounts', 'journal_lines', 'journals']);
    }
}
