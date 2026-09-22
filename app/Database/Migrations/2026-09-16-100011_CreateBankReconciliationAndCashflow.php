<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Bank statements, reconciliations and the thirteen-week cashflow forecast.
 *
 * A match joins any number of statement lines to any number of ledger lines (one
 * bank credit can settle several receipts, and the reverse). Each line can sit in
 * only one match.
 */
class CreateBankReconciliationAndCashflow extends SchemaMigration
{
    public function up(): void
    {
        $this->table('bank_statements', [
            'id'              => $this->id(),
            'bank_account_id' => $this->fk(),
            'period_id'       => $this->fk(),
            'reference'       => $this->string(120),
            'received_on'     => $this->date(true),
            'opening_balance' => $this->money(),
            'closing_balance' => $this->money(),
            'imported_by'     => $this->fk(),
        ] + $this->timestamps(), [
            'unique' => [['bank_account_id', 'period_id']],
            'fks'    => ['bank_account_id' => 'bank_accounts', 'period_id' => 'periods', 'imported_by' => 'users'],
        ]);

        // Amount is signed: receipts positive, payments negative. import_hash stops
        // the same statement file line being loaded twice.
        $this->table('bank_statement_lines', [
            'id'                => $this->id(),
            'bank_statement_id' => $this->fk(),
            'line_date'         => $this->date(),
            'reference'         => $this->string(60, true),
            'description'       => $this->string(255),
            'amount'            => $this->money(),
            'import_hash'       => $this->string(64, true),
        ], [
            'unique' => ['import_hash'],
            'keys'   => [['bank_statement_id', 'line_date']],
            'fks'    => ['bank_statement_id' => ['bank_statements', 'CASCADE']],
            'checks' => ['amount' => 'amount <> 0'],
        ]);

        $this->table('reconciliations', [
            'id'                => $this->id(),
            'bank_account_id'   => $this->fk(),
            'period_id'         => $this->fk(),
            'bank_statement_id' => $this->fk(true),
            'book_balance'      => $this->money(),
            'statement_balance' => $this->money(),
            'status'            => $this->string(14, false, 'in_progress'),
            'prepared_by'       => $this->fk(),
            'reviewed_by'       => $this->fk(true),
            'completed_at'      => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['bank_account_id', 'period_id']],
            'fks'    => ['bank_account_id' => 'bank_accounts', 'period_id' => 'periods',
                'bank_statement_id' => 'bank_statements', 'prepared_by' => 'users', 'reviewed_by' => 'users'],
            'checks' => [
                'status'    => $this->in('status', ['in_progress', 'pending_review', 'completed']),
                'sod'       => 'reviewed_by IS NULL OR reviewed_by <> prepared_by',
                'completed' => "status <> 'completed' OR (reviewed_by IS NOT NULL AND completed_at IS NOT NULL)",
            ],
        ]);

        $this->table('reconciliation_matches', [
            'id'                => $this->id(),
            'reconciliation_id' => $this->fk(),
            'note'              => $this->string(255, true),
            'matched_by'        => $this->fk(),
            'matched_at'        => $this->datetime(false),
        ], [
            'keys' => ['reconciliation_id'],
            'fks'  => ['reconciliation_id' => ['reconciliations', 'CASCADE'], 'matched_by' => 'users'],
        ]);

        $this->table('reconciliation_match_statement_lines', [
            'reconciliation_match_id' => $this->fk(),
            'bank_statement_line_id'  => $this->fk(),
        ], [
            'primary' => ['reconciliation_match_id', 'bank_statement_line_id'],
            'unique'  => ['bank_statement_line_id'],
            'fks'     => ['reconciliation_match_id' => ['reconciliation_matches', 'CASCADE'],
                'bank_statement_line_id' => 'bank_statement_lines'],
        ]);

        $this->table('reconciliation_match_journal_lines', [
            'reconciliation_match_id' => $this->fk(),
            'journal_line_id'         => $this->fk(),
        ], [
            'primary' => ['reconciliation_match_id', 'journal_line_id'],
            'unique'  => ['journal_line_id'],
            'fks'     => ['reconciliation_match_id' => ['reconciliation_matches', 'CASCADE'],
                'journal_line_id' => 'journal_lines'],
        ]);

        $this->table('cashflow_forecasts', [
            'id'                 => $this->id(),
            'entity_id'          => $this->fk(),
            'name'               => $this->string(120),
            'starts_on'          => $this->date(),
            'opening_balance'    => $this->money(),
            'restricted_balance' => $this->money(),
            'created_by'         => $this->fk(),
        ] + $this->timestamps(), [
            'keys' => [['entity_id', 'starts_on']],
            'fks'  => ['entity_id' => 'entities', 'created_by' => 'users'],
        ]);

        $this->table('cashflow_forecast_weeks', [
            'id'                   => $this->id(),
            'cashflow_forecast_id' => $this->fk(),
            'week_commencing'      => $this->date(),
            'inflow'               => $this->money(),
            'outflow'              => $this->money(),
            'note'                 => $this->string(255, true),
            'is_grant_receipt'     => $this->bool(),
        ] + $this->timestamps(), [
            'unique' => [['cashflow_forecast_id', 'week_commencing']],
            'fks'    => ['cashflow_forecast_id' => ['cashflow_forecasts', 'CASCADE']],
            'checks' => ['amounts' => 'inflow >= 0 AND outflow >= 0'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['cashflow_forecast_weeks', 'cashflow_forecasts', 'reconciliation_match_journal_lines',
            'reconciliation_match_statement_lines', 'reconciliation_matches', 'reconciliations',
            'bank_statement_lines', 'bank_statements']);
    }
}
