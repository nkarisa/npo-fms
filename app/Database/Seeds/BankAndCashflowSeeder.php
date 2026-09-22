<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\StatementCsv;
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
 * - The M-Pesa integration: the organisation's own paybill, pointed at the float
 *   account its statement reconciles against. It is seeded as configured but not
 *   connected — the Daraja credentials are entered by the Finance Manager on
 *   Settings → Integrations and are not seed data.
 * - Statement formats: the M-Pesa organisation portal export (built in), and the
 *   KCB and Equity internet banking exports, each assigned to its account. Seeded
 *   lines carry the same fingerprint an upload gives them, so loading a statement
 *   over them skips what is already there.
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

    /**
     * The CSV layouts the banks' downloads use. The M-Pesa export is the same for
     * every organisation; the bank layouts are a starting point, adjusted in
     * Settings when a bank's download differs.
     */
    private const FORMATS = [
        'mpesa' => [
            'name' => 'M-Pesa organisation portal (CSV)', 'builtin' => true, 'accounts' => ['1130'],
            'date_column' => 'Completion Time', 'date_format' => 'yyyy-mm-dd|dd-mm-yyyy|dd/mm/yyyy', 'reference_column' => 'Receipt No.',
            'description_columns' => ['Details'], 'amount_layout' => 'split', 'debit_column' => 'Withdrawn', 'credit_column' => 'Paid In',
            'balance_column' => 'Balance', 'status_column' => 'Transaction Status', 'status_value' => 'Completed',
            'entry_rules' => [['match' => 'Charge', 'entry' => 'charges']],
        ],
        'kcb' => [
            'name' => 'KCB internet banking (CSV)', 'builtin' => false, 'accounts' => ['1110'],
            'date_column' => 'Transaction Date', 'date_format' => 'dd/mm/yyyy', 'reference_column' => 'Reference',
            'description_columns' => ['Transaction Details'], 'amount_layout' => 'split', 'debit_column' => 'Money Out', 'credit_column' => 'Money In',
            'balance_column' => 'Ledger Balance',
            'entry_rules' => [['match' => 'CHG', 'entry' => 'charges'], ['match' => 'ledger fee', 'entry' => 'charges'], ['match' => 'INT CR', 'entry' => 'interest'], ['match' => 'interest', 'entry' => 'interest']],
        ],
        'equity' => [
            'name' => 'Equity Bank online banking (CSV)', 'builtin' => false, 'accounts' => ['1120'],
            'date_column' => 'Transaction Date', 'date_format' => 'dd-mm-yyyy', 'reference_column' => 'Transaction Reference',
            'description_columns' => ['Narrative'], 'amount_layout' => 'split', 'debit_column' => 'Debit', 'credit_column' => 'Credit',
            'balance_column' => 'Running Balance',
            'entry_rules' => [['match' => 'charge', 'entry' => 'charges'], ['match' => 'interest', 'entry' => 'interest'], ['match' => 'exchange gain', 'entry' => 'fx_gain']],
        ],
    ];

    private const PREPARER = 'M. Otieno';

    private const REVIEWER = 'W. Kamau';

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $august = $ctx->periodId('2026-08-01');
        $this->formats($ctx);
        $this->mpesa($ctx);

        foreach ($ctx->data('BR_ACCOUNTS') as $b) {
            $bank = $ctx->require('bank_accounts', $b['code']);
            $received = preg_match('/(?:received|pulled) (\d{1,2} [A-Z][a-z]{2})$/', $b['stmtRef'], $m) === 1 ? $ctx->date($m[1]) : null;
            $closing = $b['opening'] + array_sum(array_column($b['stmt'], 'amt'));

            $statement = $ctx->insert('bank_statements', [
                'bank_account_id' => $bank, 'period_id' => $august, 'reference' => $b['stmtRef'], 'received_on' => $received,
                'opening_balance' => $b['opening'], 'closing_balance' => $closing, 'imported_by' => $ctx->systemUserId(), 'created_at' => $now,
            ]);

            $seen = [];
            foreach ($b['stmt'] as $line) {
                $ctx->insert('bank_statement_lines', [
                    'bank_statement_id' => $statement, 'line_date' => $ctx->date($line['date']), 'reference' => $line['ref'],
                    'description' => $line['desc'], 'amount' => $line['amt'], 'bank_entry' => self::BANK_ENTRIES[$line['jl']] ?? null,
                    'import_hash' => self::fingerprint($seen, $bank, $ctx->date($line['date']), $line['ref'], (float) $line['amt'], $line['desc']),
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

            $seen = [];
            foreach ($postings as $p) {
                $amount = round($p['debit'] - $p['credit'], 2);
                if ($amount == 0) {
                    continue;
                }
                $reference = self::bankReference($b['code'], (int) $p['id'], $amount);
                $description = $p['description'] !== '' ? $p['description'] : $p['narration'];
                $line = $ctx->insert('bank_statement_lines', [
                    'bank_statement_id' => $statement, 'line_date' => $p['journal_date'], 'reference' => $reference, 'description' => $description,
                    'amount' => $amount, 'import_hash' => self::fingerprint($seen, $bank, $p['journal_date'], $reference, $amount, $description),
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

    /** The statement formats, and the account each is assigned to. */
    /**
     * The organisation's M-Pesa short code, against the float account 1130 whose
     * statement the reconciliation already reads. Neither service is switched on:
     * nothing can collect or pay until the Daraja credentials are entered.
     */
    private function mpesa(SeedContext $ctx): void
    {
        $ctx->insert('mpesa_integrations', [
            'entity_id'         => $ctx->entityId(),
            'bank_account_id'   => $ctx->require('bank_accounts', '1130'),
            'environment'       => 'sandbox',
            'shortcode'         => '509118',
            'shortcode_kind'    => 'paybill',
            'account_reference' => 'ELOG',
            'payment_ceiling'   => 150000,
            'auto_match'        => 1,
            'created_at'        => $ctx->now(),
        ]);
    }

    private function formats(SeedContext $ctx): void
    {
        foreach (self::FORMATS as $f) {
            $id = $ctx->insert('bank_statement_formats', [
                'entity_id' => $ctx->entityId(), 'name' => $f['name'], 'is_builtin' => (int) $f['builtin'], 'delimiter' => 'comma',
                'date_column' => $f['date_column'], 'date_format' => $f['date_format'], 'reference_column' => $f['reference_column'],
                'description_columns' => json_encode($f['description_columns']), 'amount_layout' => $f['amount_layout'],
                'debit_column' => $f['debit_column'], 'credit_column' => $f['credit_column'], 'balance_column' => $f['balance_column'],
                'decimal_mark' => '.', 'status_column' => $f['status_column'] ?? null, 'status_value' => $f['status_value'] ?? null,
                'entry_rules' => json_encode($f['entry_rules']), 'created_by' => $ctx->systemUserId(), 'created_at' => $ctx->now(),
            ]);
            foreach ($f['accounts'] as $code) {
                $ctx->db()->table('bank_accounts')->where('id', $ctx->require('bank_accounts', $code))->update(['statement_format_id' => $id]);
            }
        }
    }

    /**
     * The fingerprint an upload would give the line, counting identical lines on the
     * statement so two equal charges on one day both load.
     */
    private static function fingerprint(array &$seen, int $bank, string $date, string $ref, float $amount, string $desc): string
    {
        $key = implode('|', [$date, mb_strtolower($ref), round($amount, 2), mb_strtolower($desc)]);
        $seen[$key] = ($seen[$key] ?? 0) + 1;

        return StatementCsv::fingerprint($bank, $date, $ref, $amount, $desc, $seen[$key]);
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
