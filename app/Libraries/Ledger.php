<?php

namespace App\Libraries;

use App\Controllers\Api\Payables;
use App\Repositories\AssetRepository;
use App\Repositories\BudgetRepository;
use App\Repositories\ChartRepository;
use App\Repositories\DonorReportRepository;
use App\Repositories\FundRepository;
use App\Repositories\GrantRepository;
use App\Repositories\JournalRepository;
use App\Repositories\PayablesRepository;
use App\Repositories\PeriodRepository;
use App\Repositories\SettingsRepository;

/**
 * Cross-cutting facts about the book that more than one screen needs to agree on:
 * the dashboard's action queue and integrity checks, and the period-close checklist,
 * are two readings of the same underlying state. Deriving them once here keeps the
 * two screens from drifting apart.
 */
class Ledger
{
    // ---- Periods ----

    /** Periods locked against further posting. */
    public static function closedPeriods(): array
    {
        return (new PeriodRepository())->closed();
    }

    /** The earliest open period. */
    public static function currentPeriod(): string
    {
        return (new PeriodRepository())->currentName();
    }

    /** Months of the financial year up to and including the current period. */
    public static function monthsElapsed(): int
    {
        return (new PeriodRepository())->monthsElapsed();
    }

    // ---- Chart of accounts ----

    public static function chart(): array
    {
        return (new ChartRepository())->accounts();
    }

    public static function leaves(): array
    {
        return array_values(array_filter(self::chart(), fn ($a) => $a['level'] === 2));
    }

    public static function acctBal(string $code): float
    {
        return (float) ((new ChartRepository())->find($code)['balance'] ?? 0);
    }

    public static function sumCodes(array $codes): float
    {
        return array_sum(array_map(static fn ($c) => self::acctBal($c), $codes));
    }

    public static function byType(string $type): float
    {
        return array_sum(array_map(fn ($a) => $a['balance'], array_filter(self::leaves(), fn ($a) => $a['type'] === $type)));
    }

    // ---- Funds ----

    public static function fundClose(array $f): float
    {
        return $f['opening'] + $f['income'] - $f['spend'] + $f['transfers'];
    }

    /** What was available to spend: opening plus income plus transfers in (transfers out do not free up budget). */
    public static function fundAvailable(array $f): float
    {
        return $f['opening'] + $f['income'] + max(0, $f['transfers']);
    }

    public static function fundPct(array $f): int
    {
        $a = self::fundAvailable($f);

        return $a > 0 ? min(100, (int) round($f['spend'] / $a * 100)) : 0;
    }

    /** Utilisation bar colour: amber as a line approaches its ceiling, rust once it is effectively spent. */
    public static function barColour(int $pct): string
    {
        return $pct >= 90 ? '#A45B3E' : ($pct >= 70 ? '#B98A3C' : '#1FA37E');
    }

    public static function fundsByClass(string $cls): float
    {
        return array_sum(array_map(
            fn ($f) => self::fundClose($f),
            array_filter((new FundRepository())->all(), fn ($f) => $f['cls'] === $cls)
        ));
    }

    /** Restricted funds closing within 90 days — unspent balances are returnable at close. */
    public static function expiringFunds(): array
    {
        return array_values(array_filter(
            (new FundRepository())->all(),
            fn ($f) => $f['daysLeft'] <= 90 && $f['cls'] === 'Restricted'
        ));
    }

    public static function restrictedUtilisation(): int
    {
        $restricted = array_filter((new FundRepository())->all(), fn ($f) => $f['cls'] === 'Restricted');
        $spend = array_sum(array_map(fn ($f) => $f['spend'], $restricted));
        $avail = array_sum(array_map(fn ($f) => self::fundAvailable($f), $restricted));

        return (int) round($spend / max(1, $avail) * 100);
    }

    // ---- Payables ----

    public static function openBills(): array
    {
        return array_values(array_filter(
            (new PayablesRepository())->all(),
            fn ($b) => !in_array($b['status'], ['Paid', 'Rejected'], true)
        ));
    }

    public static function overdueBills(): array
    {
        return array_values(array_filter(self::openBills(), fn ($b) => $b['dueIn'] < 0));
    }

    public static function awaitingBills(): array
    {
        return array_values(array_filter((new PayablesRepository())->all(), fn ($b) => $b['status'] === 'Awaiting approval'));
    }

    public static function billsNet(array $bills): float
    {
        return array_sum(array_map(fn ($b) => Payables::totals($b)['net'], $bills));
    }

    // ---- Journals ----

    public static function journalsByStatus(string $status): array
    {
        return array_values(array_filter((new JournalRepository())->all(), fn ($j) => $j['status'] === $status));
    }

    public static function isBalanced(array $j): bool
    {
        $dr = array_sum(array_map(fn ($l) => $l['dr'] ?? 0, $j['lines']));
        $cr = array_sum(array_map(fn ($l) => $l['cr'] ?? 0, $j['lines']));

        return $dr === $cr;
    }

    public static function unbalancedDrafts(): array
    {
        return array_values(array_filter(self::journalsByStatus('Draft'), fn ($j) => !self::isBalanced($j)));
    }

    // ---- Donor reports ----

    public static function reportCumulative(array $r): float
    {
        return array_sum(array_map(fn ($l) => $l['cumulative'], $r['lines']));
    }

    public static function reportReported(array $r): float
    {
        return self::reportCumulative($r) + $r['reportedAdj'];
    }

    /** Reports whose declared figure does not agree with the postings behind it. */
    public static function untiedReports(): array
    {
        return array_values(array_filter(
            (new DonorReportRepository())->all(),
            fn ($r) => self::reportReported($r) !== self::reportCumulative($r)
        ));
    }

    public static function overdueReports(): array
    {
        return array_values(array_filter((new DonorReportRepository())->all(), fn ($r) => $r['status'] === 'Overdue'));
    }

    // ---- Budget ----

    /**
     * Phased budget to date, by each line's phasing profile, and the status a line
     * earns against it — the same measure the Budgets screen shows.
     */
    public static function budgetLines(): array
    {
        return array_map(static fn ($l) => array_merge($l, [
            'variance' => $l['phased'] - $l['actual'],
            'pct'      => $l['annual'] > 0 ? (int) round($l['actual'] / $l['annual'] * 100) : 0,
        ]), (new BudgetRepository())->lines());
    }

    public static function overBudgetLines(): array
    {
        return array_values(array_filter(self::budgetLines(), fn ($l) => $l['status'] === 'Over'));
    }

    // ---- Assets ----

    public static function depreciationRunRate(): float
    {
        $assets = new AssetRepository();

        return $assets->chargeFor($assets->period());
    }

    // ---- Grants ----

    public static function liveGrants(): array
    {
        return array_values(array_filter(
            (new GrantRepository())->all(),
            fn ($g) => in_array($g['status'], ['Active', 'Closing'], true)
        ));
    }

    public static function burnPct(array $g): int
    {
        return (int) round($g['spent'] / max(1, $g['value']) * 100);
    }

    // ---- Integrity checks ----

    /** Fund balances carried on the statement of financial position. */
    public const EQUITY_CODES = ['3100', '3200', '3300', '3900'];

    /**
     * Fund balances that are computed from other accounts rather than posted to.
     * Including them in the trial balance would double-count the year's result.
     */
    public const DERIVED_CODES = ['3900'];

    /**
     * The accounts that carry a balance in their own right: postable leaves plus the
     * fund balances, less the roll-up header and the derived surplus line.
     */
    public static function trialBalanceAccounts(): array
    {
        return array_values(array_filter(self::chart(), static fn ($a) => ($a['status'] ?? 'Active') === 'Active'
                && ($a['level'] === 2 || $a['type'] === 'Equity')
                && $a['code'] !== '3000'
                && !in_array($a['code'], self::DERIVED_CODES, true)));
    }

    /** Debits equal credits across every active account. */
    public static function trialBalanceBalanced(): bool
    {
        $dr = 0.0;
        $cr = 0.0;
        foreach (self::trialBalanceAccounts() as $a) {
            $debitSide = in_array($a['type'], ['Asset', 'Expense'], true);
            // A negative balance on a debit-side account (contra accounts such as
            // accumulated depreciation) belongs in the credit column, and vice versa.
            $onDebit = $debitSide ? $a['balance'] >= 0 : $a['balance'] < 0;
            $onDebit ? $dr += abs($a['balance']) : $cr += abs($a['balance']);
        }

        return round($dr - $cr) == 0;
    }

    /** Level-1 asset groups presented as non-current; every other asset group is current. */
    public const NON_CURRENT_ASSET_GROUPS = ['1300'];

    /**
     * Postable accounts of one balance-sheet type, read from the chart itself.
     *
     * Account membership must never be a hardcoded list of leaf codes: an account
     * added under an existing heading would silently fall out of the statement,
     * understating that side while the other side still carried the postings. This
     * happened with 2250 and 2260 — 426,700 of payroll liabilities left the
     * statement and it reported that the books did not balance when they did.
     *
     * Only the grouping headings are fixed; the leaves under them are discovered.
     * An archived account still carrying a balance is included, because archiving
     * an account does not make its balance disappear.
     *
     * @return list<array{code:string,name:string,balance:float,group:string}>
     */
    public static function statementLeaves(string $type): array
    {
        $chart = self::chart();
        $out   = [];
        $group = '';

        foreach ($chart as $i => $a) {
            if ($a['type'] !== $type) {
                continue;
            }
            if ($a['level'] === 1) {
                $group = $a['code'];
            }

            // The chart is ordered depth-first, so an account is a leaf when the
            // next account is not nested beneath it.
            $next   = $chart[$i + 1] ?? null;
            $isLeaf = $next === null || $next['level'] <= $a['level'];
            if (!$isLeaf || $a['level'] === 0) {
                continue;
            }

            $archived = ($a['status'] ?? 'Active') !== 'Active';
            if ($archived && (float) $a['balance'] === 0.0) {
                continue;
            }

            $out[] = [
                'code'    => $a['code'],
                'name'    => $a['name'],
                'balance' => (float) $a['balance'],
                // A level-1 leaf (the fund balances) is its own group.
                'group'   => $a['level'] === 1 ? $a['code'] : $group,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function statementCodes(string $type, ?callable $where = null): array
    {
        $leaves = self::statementLeaves($type);
        if ($where !== null) {
            $leaves = array_filter($leaves, $where);
        }

        return array_values(array_map(static fn ($l) => $l['code'], $leaves));
    }

    /** Assets less liabilities equal the fund balances. */
    public static function positionBalanced(): bool
    {
        $assets = self::sumCodes(self::statementCodes('Asset'));
        $liab   = self::sumCodes(self::statementCodes('Liability'));
        $funds  = self::sumCodes(self::statementCodes('Equity'));

        return round($assets - $liab) == round($funds);
    }

    /** Segments that carry donor meaning but are not yet mandatory on postings. */
    public static function openSegments(): array
    {
        return array_values(array_filter(
            (new SettingsRepository())->segments(),
            fn ($s) => !$s['required'] && in_array($s['key'], ['fund', 'grant', 'restriction'], true)
        ));
    }

    public static function earliestOpenPeriod(): string
    {
        return self::currentPeriod();
    }
}
