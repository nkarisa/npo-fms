<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;
use RuntimeException;

/**
 * FY2025, the year before the ledger starts, as the legacy system kept it: the
 * figures the FY2026 statements compare themselves with (legacy_balances; see
 * AlignReports).
 *
 * The year is built backwards from where it has to end: its closing position is
 * the balance sheet the ledger brought forward on 1 January 2026 (the opening
 * journal, OB-26-0001), account by account, so last year's closing statement and
 * this year's opening agree to the shilling.
 *
 * - Expenditure runs at 82% of this year's monthly rate, account by account and
 *   fund by fund, with a little variation, phased across the months.
 * - Income runs at the same 82% of this year's rate, except that no fund's surplus
 *   is more than a quarter of the balance it closed the year on. FY2026 is an
 *   election year and its donor funds earn far more than they spend; FY2025 was
 *   not, and a fund cannot have opened it below nil. The General Fund, which
 *   carries personnel and administration, draws down its reserve as it does this
 *   year, and a fund with no income of its own (the capital fund, which carries
 *   depreciation) spends down what it held.
 * - Funds open at their closing balance less the year's surplus, which is carried
 *   into them at the year end.
 * - Fixed assets open at most of their closing cost, less the year's depreciation;
 *   every other asset and liability opens near its closing balance, and moves to
 *   it evenly through the year. The KES current account takes up the difference:
 *   what makes the opening balance sheet equal the funds, and each month's cash.
 */
class ComparativesSeeder extends Seeder
{
    private const YEAR = 'FY2025';

    /** Expenditure against this year's monthly rate. */
    private const SPEND_RATE = 0.82;

    /** The share of its closing balance each fund earned in the year. */
    private const SURPLUS_SHARE = 0.25;

    private const FIXED_ASSET_GROUP = '13';

    private const FIXED_ASSETS_HELD = 0.93;

    private const DEPRECIATION = '5350';

    private const BANK = '1110';

    /** How the year's activity falls across the months, January first (the mean is 1). */
    private const SEASON = [0.78, 0.84, 0.96, 1.0, 1.04, 1.08, 1.06, 1.02, 0.98, 1.04, 1.1, 1.1];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $db = $ctx->db();
        $t = static fn (string $table) => $db->prefixTable($table);
        $entity = $ctx->entityId();

        $year = $db->query("SELECT id FROM {$t('fiscal_years')} WHERE entity_id = ? AND code = ?", [$entity, self::YEAR])->getRowArray()
            ?? throw new RuntimeException(self::YEAR . ' is not on the calendar.');
        $periods = array_column($db->query("SELECT id, starts_on FROM {$t('periods')} WHERE fiscal_year_id = ? ORDER BY starts_on", [$year['id']])->getResultArray(), 'id');
        if (count($periods) !== 12) {
            throw new RuntimeException(self::YEAR . ' does not have twelve periods.');
        }

        $accounts = [];
        foreach ($db->query("SELECT id, code, type FROM {$t('accounts')}")->getResultArray() as $a) {
            $accounts[$a['code']] = ['id' => (int) $a['id'], 'type' => $a['type']];
        }

        // Where the year has to end: the balances brought forward into FY2026, by account and fund.
        $closing = [];
        foreach ($db->query(
            "SELECT a.code, l.fund_id, SUM(l.debit - l.credit) AS net FROM {$t('journal_lines')} l
             JOIN {$t('journals')} j ON j.id = l.journal_id JOIN {$t('accounts')} a ON a.id = l.account_id
             WHERE j.entity_id = ? AND j.source_type = 'fiscal_year' AND j.status = 'posted' GROUP BY a.code, l.fund_id",
            [$entity]
        )->getResultArray() as $r) {
            $closing[$r['code'] . '|' . $r['fund_id']] = round((float) $r['net'], 2);
        }
        if ($closing === []) {
            throw new RuntimeException('The ledger has no balances brought forward to close ' . self::YEAR . ' on.');
        }

        // This year's income and expenditure to date, by account and fund, and how many months it covers.
        $current = $db->query(
            "SELECT a.code, a.type, l.fund_id, SUM(l.debit - l.credit) AS net FROM {$t('journal_lines')} l
             JOIN {$t('journals')} j ON j.id = l.journal_id JOIN {$t('accounts')} a ON a.id = l.account_id
             WHERE j.entity_id = ? AND j.status IN ('posted', 'reversed') AND a.type IN ('income', 'expense')
               AND j.journal_date BETWEEN ? AND ? GROUP BY a.code, a.type, l.fund_id",
            [$entity, SeedContext::YEAR . '-01-01', SeedContext::AS_AT]
        )->getResultArray();
        $monthsToDate = (int) substr(SeedContext::AS_AT, 5, 2);

        $spend = $earnings = [];
        foreach ($current as $r) {
            $annual = abs((float) $r['net']) / $monthsToDate * 12;
            if ($r['type'] === 'expense') {
                $spend[$r['code'] . '|' . $r['fund_id']] = round($annual * self::SPEND_RATE * (0.92 + 0.16 * self::draw('spend' . $r['code'] . $r['fund_id'])) / 1000) * 1000;
            } else {
                $earnings[(int) $r['fund_id']][$r['code']] = $annual;
            }
        }

        // Each fund earns at last year's rate, but no more than takes it to a quarter above where it opened.
        $fundClosing = [];
        foreach ($closing as $key => $net) {
            [$code, $fund] = explode('|', $key);
            if ($accounts[$code]['type'] === 'equity') {
                $fundClosing[(int) $fund] = ($fundClosing[(int) $fund] ?? 0) - $net;
            }
        }
        $income = [];
        $surplus = [];
        foreach ($earnings + array_fill_keys(array_map(static fn ($k) => (int) explode('|', $k)[1], array_keys($spend)), []) as $fund => $mix) {
            $spent = array_sum(array_filter($spend, static fn ($k) => (int) explode('|', $k)[1] === $fund, ARRAY_FILTER_USE_KEY));
            // A fund with no income of its own (the capital fund, which carries depreciation) spends down what it holds.
            if ($mix === []) {
                $surplus[$fund] = -$spent;
                continue;
            }
            $atLastYearsRate = array_sum($mix) * self::SPEND_RATE * (0.94 + 0.12 * self::draw('earn' . $fund));
            $surplus[$fund] = round(min($atLastYearsRate - $spent, max(0, $fundClosing[$fund] ?? 0) * self::SURPLUS_SHARE) / 1000) * 1000;
            $earned = $spent + $surplus[$fund];
            $placed = 0.0;
            $last = array_key_last($mix);
            foreach ($mix as $code => $weight) {
                $amount = $code === $last ? $earned - $placed : round($earned * $weight / array_sum($mix) / 1000) * 1000;
                $placed += $amount;
                $income[$code . '|' . $fund] = $amount;
            }
        }

        // Where the year opened: funds before the year's surplus, and the balance sheet near where it closed.
        $opening = [];
        $depreciation = array_sum(array_filter($spend, static fn ($k) => str_starts_with($k, self::DEPRECIATION . '|'), ARRAY_FILTER_USE_KEY));
        foreach ($closing as $key => $net) {
            [$code, $fund] = explode('|', $key);
            $type = $accounts[$code]['type'];
            $opening[$key] = match (true) {
                $type === 'equity'                                            => $net + ($surplus[(int) $fund] ?? 0),
                $code === self::BANK                                          => null,
                str_starts_with($code, self::FIXED_ASSET_GROUP) && $net < 0   => $net + $depreciation,
                str_starts_with($code, self::FIXED_ASSET_GROUP)               => round($net * self::FIXED_ASSETS_HELD / 1000) * 1000,
                $type === 'asset'                                             => round($net * (0.75 + 0.2 * self::draw('open' . $key)) / 1000) * 1000,
                default                                                       => round($net * (0.85 + 0.25 * self::draw('open' . $key)) / 1000) * 1000,
            };
        }
        $bankKey = current(array_filter(array_keys($closing), static fn ($k) => str_starts_with($k, self::BANK . '|')))
            ?: throw new RuntimeException('The balances brought forward carry no KES current account.');
        $opening[$bankKey] = -array_sum(array_filter($opening, static fn ($v) => $v !== null));

        // The months: income and expenditure phased by season, the balance sheet moving evenly, the bank taking the rest.
        $month = static fn (float $total, string $seed, int $m, float $placed) => $m === 11
            ? round($total - $placed, 2)
            : round($total * self::SEASON[$m] * (0.9 + 0.2 * self::draw($seed . $m)) / 12 / 100) * 100;
        $rows = [];
        $bankRun = $opening[$bankKey];
        $lowestBank = $bankRun;
        $placed = [];
        for ($m = 0; $m < 12; $m++) {
            $moves = [];
            foreach ($spend as $key => $amount) {
                $moves[$key] = $month($amount, 'e' . $key, $m, $placed[$key] ?? 0);
                $placed[$key] = ($placed[$key] ?? 0) + $moves[$key];
            }
            foreach ($income as $key => $amount) {
                $earned = $month($amount, 'i' . $key, $m, $placed[$key] ?? 0);
                $placed[$key] = ($placed[$key] ?? 0) + $earned;
                $moves[$key] = -$earned;
            }
            foreach ($closing as $key => $net) {
                if ($key !== $bankKey && $accounts[explode('|', $key)[0]]['type'] !== 'equity') {
                    $moves[$key] = $m === 11 ? round($net - $opening[$key] - ($placed[$key] ?? 0), 2) : round(($net - $opening[$key]) / 12 / 100) * 100;
                    $placed[$key] = ($placed[$key] ?? 0) + $moves[$key];
                }
            }
            $moves[$bankKey] = round(-array_sum($moves), 2);
            $bankRun += $moves[$bankKey];
            $lowestBank = min($lowestBank, $bankRun);
            foreach ($moves as $key => $amount) {
                $rows[] = [$periods[$m], $key, 'movement', $amount];
            }
        }
        if ($lowestBank < 0 || $opening[$bankKey] < 0) {
            throw new RuntimeException('The KES current account would be overdrawn in ' . self::YEAR . '; adjust the opening balances.');
        }
        if (round($bankRun - $closing[$bankKey], 2) != 0) {
            throw new RuntimeException(self::YEAR . ' does not close on the balances brought forward.');
        }
        foreach ($opening as $key => $amount) {
            $rows[] = [$periods[0], $key, 'opening', $amount];
        }

        $now = $ctx->now();
        foreach ($rows as [$period, $key, $kind, $amount]) {
            if (round($amount, 2) == 0) {
                continue;
            }
            [$code, $fund] = explode('|', $key);
            $ctx->insert('legacy_balances', [
                'entity_id' => $entity, 'fiscal_year_id' => (int) $year['id'], 'period_id' => (int) $period,
                'account_id' => $accounts[$code]['id'], 'fund_id' => (int) $fund, 'kind' => $kind,
                'debit' => round(max($amount, 0), 2), 'credit' => round(max(-$amount, 0), 2), 'created_at' => $now,
            ]);
        }
    }

    /** A number in [0, 1) that is the same for the same seed on every load. */
    private static function draw(string $seed): float
    {
        return (crc32('comparatives' . $seed) % 100000) / 100000;
    }
}
