<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Bank statements and their reconciliations, and the thirteen-week cashflow
 * forecast.
 *
 * Sources: BR_ACCOUNTS, CF_OPENING, CF_WEEKS.
 *
 * - August is the open reconciliation: each account's statement as the prototype
 *   holds it, against the cash book the ledger seeder posted, with nothing matched
 *   yet. A statement line the bank raised on its own account (the prototype's
 *   "Post bank charges", "Post to suspense" and so on) is marked with its kind.
 * - June and July are the reconciliations signed off before them: every posting on
 *   the account in the month came through on the statement and was matched to it.
 *   Their statements chain back from August's opening balance, so each month's
 *   closing balance is the next month's opening.
 */
class BankAndCashflowSeeder extends Seeder
{
    /** The prototype's suggested posting → the kind of bank entry. */
    private const BANK_ENTRIES = [
        'Post bank charges' => 'charges', 'Post transaction charges' => 'charges', 'Post interest received' => 'interest',
        'Post exchange gain' => 'fx_gain', 'Post to suspense' => 'unidentified',
    ];

    /** Signed off before August, oldest last. */
    private const EARLIER_MONTHS = ['2026-07-01', '2026-06-01'];

    private const PREPARER = 'M. Otieno';

    private const REVIEWER = 'W. Kamau';

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
                    'description' => $line['desc'], 'amount' => $line['amt'], 'bank_entry' => self::BANK_ENTRIES[$line['jl']] ?? null,
                ]);
            }

            $ctx->insert('reconciliations', [
                'bank_account_id' => $bank, 'period_id' => $august, 'bank_statement_id' => $statement,
                'book_balance' => $b['opening'] + $this->movement($b['code'], '2026-08-01'), 'statement_balance' => $closing,
                'status' => 'in_progress', 'prepared_by' => $ctx->userOrSystem(self::PREPARER), 'created_at' => $now,
            ]);

            $this->earlierMonths($ctx, $b, $bank);
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

    /** June and July: statements that mirror the ledger, fully matched and signed off. */
    private function earlierMonths(SeedContext $ctx, array $b, int $bank): void
    {
        $db = $ctx->db();
        $preparer = $ctx->userOrSystem(self::PREPARER);
        $reviewer = $ctx->userOrSystem(self::REVIEWER);
        $closing = (float) $b['opening'];

        foreach (self::EARLIER_MONTHS as $month) {
            $start = new \DateTimeImmutable($month);
            $end = $start->modify('last day of this month');
            $received = $end->modify('+3 days');
            $postings = $this->postings($b['code'], $month);
            $opening = $closing - array_sum(array_map(static fn ($p) => $p['debit'] - $p['credit'], $postings));

            $statement = $ctx->insert('bank_statements', [
                'bank_account_id' => $bank, 'period_id' => $ctx->periodId($month),
                'reference' => preg_replace(['~\d{2}/2026~', '~(received|pulled) \d{1,2} [A-Z][a-z]{2}$~'], [$start->format('m/Y'), '$1 ' . $received->format('d M')], $b['stmtRef']),
                'received_on' => $received->format('Y-m-d'), 'opening_balance' => $opening, 'closing_balance' => $closing,
                'imported_by' => $ctx->systemUserId(), 'created_at' => $received->format('Y-m-d 08:00:00'),
            ]);
            $completedAt = $received->format('Y-m-d 16:00:00');
            $reconciliation = $ctx->insert('reconciliations', [
                'bank_account_id' => $bank, 'period_id' => $ctx->periodId($month), 'bank_statement_id' => $statement,
                'book_balance' => $closing, 'statement_balance' => $closing, 'status' => 'completed',
                'prepared_by' => $preparer, 'reviewed_by' => $reviewer, 'completed_at' => $completedAt, 'created_at' => $received->format('Y-m-d 08:00:00'),
            ]);

            foreach ($postings as $p) {
                $amount = round($p['debit'] - $p['credit'], 2);
                if ($amount == 0) {
                    continue;
                }
                $line = $ctx->insert('bank_statement_lines', [
                    'bank_statement_id' => $statement, 'line_date' => $p['journal_date'],
                    'reference' => self::bankReference($b['code'], (int) $p['id'], $amount), 'description' => $p['description'] !== '' ? $p['description'] : $p['narration'],
                    'amount' => $amount,
                ]);
                $match = $ctx->insert('reconciliation_matches', ['reconciliation_id' => $reconciliation, 'matched_by' => $preparer, 'matched_at' => $completedAt]);
                $db->table('reconciliation_match_statement_lines')->insert(['reconciliation_match_id' => $match, 'bank_statement_line_id' => $line]);
                $db->table('reconciliation_match_journal_lines')->insert(['reconciliation_match_id' => $match, 'journal_line_id' => $p['id']]);
            }
            $ctx->writeTrail('reconciliation', $reconciliation, $b['code'] . ' ' . $start->format('M Y'), [
                ['when' => $received->format('d M Y'), 'what' => 'Reconciled by ' . self::PREPARER . ' — ' . count($postings) . ' statement lines matched'],
                ['when' => $received->format('d M Y'), 'what' => 'Signed off by ' . self::REVIEWER],
            ], $ctx->entityId());

            $closing = $opening;
        }
    }

    /** Posted ledger lines on a cash account in a month. */
    private function postings(string $code, string $month): array
    {
        return SeedContext::get()->db()->query(
            "SELECT l.id, l.debit, l.credit, l.description, j.narration, j.journal_date
             FROM {$this->t('journal_lines')} l JOIN {$this->t('journals')} j ON j.id = l.journal_id JOIN {$this->t('accounts')} a ON a.id = l.account_id
             WHERE a.code = ? AND j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN ? AND ?
             ORDER BY j.journal_date, j.reference, l.line_no",
            [$code, $month, (new \DateTimeImmutable($month))->format('Y-m-t')]
        )->getResultArray();
    }

    private function movement(string $code, string $month): float
    {
        return array_sum(array_map(static fn ($p) => $p['debit'] - $p['credit'], $this->postings($code, $month)));
    }

    private function t(string $table): string
    {
        return SeedContext::get()->db()->prefixTable($table);
    }

    /** The bank's own reference for a line, as its statement prints it. */
    private static function bankReference(string $code, int $id, float $amount): string
    {
        $n = str_pad((string) (($id * 7919) % 1000000), 6, '0', STR_PAD_LEFT);

        return match (true) {
            $code === '1130'  => 'MP ' . substr($n, -4),
            $code === '1120'  => 'SWF ' . $n,
            $amount > 0       => 'CR ' . $n,
            default           => 'EFT ' . $n,
        };
    }
}
