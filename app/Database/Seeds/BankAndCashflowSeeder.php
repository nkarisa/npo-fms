<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * August bank statements and their open reconciliations, and the thirteen-week
 * cashflow forecast.
 *
 * Sources: BR_ACCOUNTS, CF_OPENING, CF_WEEKS.
 *
 * The prototype's "book" side of each reconciliation lists vouchers that are not
 * in its journal list, so no statement line is matched to the ledger here; each
 * reconciliation starts in progress with its book balance as the prototype shows.
 */
class BankAndCashflowSeeder extends Seeder
{
    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $august = $ctx->periodId('2026-08-01');

        foreach ($ctx->data('BR_ACCOUNTS') as $b) {
            $bank = $ctx->require('bank_accounts', $b['code']);
            $received = preg_match('/(?:received|pulled) (\d{1,2} [A-Z][a-z]{2})$/', $b['stmtRef'], $m) === 1 ? $ctx->date($m[1]) : null;
            $closing = $b['opening'] + array_sum(array_column($b['stmt'], 'amt'));

            $statement = $ctx->insert('bank_statements', [
                'bank_account_id' => $bank, 'period_id' => $august, 'reference' => $b['stmtRef'], 'received_on' => $received,
                'opening_balance' => $b['opening'], 'closing_balance' => $closing, 'imported_by' => $ctx->systemUserId(), 'created_at' => $now,
            ]);

            foreach ($b['stmt'] as $line) {
                $ctx->insert('bank_statement_lines', [
                    'bank_statement_id' => $statement, 'line_date' => $ctx->date($line['date']), 'reference' => $line['ref'],
                    'description' => $line['desc'], 'amount' => $line['amt'],
                ]);
            }

            $ctx->insert('reconciliations', [
                'bank_account_id' => $bank, 'period_id' => $august, 'bank_statement_id' => $statement,
                'book_balance' => $b['opening'] + array_sum(array_column($b['book'], 'amt')), 'statement_balance' => $closing,
                'status' => 'in_progress', 'prepared_by' => $ctx->systemUserId(), 'created_at' => $now,
            ]);
        }

        $weeks = $ctx->data('CF_WEEKS');
        $opening = $ctx->data('CF_OPENING');
        $forecast = $ctx->insert('cashflow_forecasts', [
            'entity_id' => $ctx->entityId(), 'name' => 'Base case — 13 weeks', 'starts_on' => $ctx->date($weeks[0]['wc']),
            'opening_balance' => $opening['opening'], 'restricted_balance' => $opening['restricted'],
            'created_by' => $ctx->systemUserId(), 'created_at' => $now,
        ]);

        foreach ($weeks as $w) {
            $ctx->insert('cashflow_forecast_weeks', [
                'cashflow_forecast_id' => $forecast, 'week_commencing' => $ctx->date($w['wc']), 'inflow' => $w['inflow'],
                'outflow' => $w['outflow'], 'note' => $w['note'] !== '' ? $w['note'] : null, 'is_grant_receipt' => (int) $w['grant'],
                'created_at' => $now,
            ]);
        }
    }
}
