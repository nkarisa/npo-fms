<?php

namespace App\Libraries;

use App\Controllers\Api\Assets;
use App\Controllers\Api\Payables;

/**
 * Cross-cutting facts about the book that more than one screen needs to agree on:
 * the dashboard's action queue and integrity checks, and the period-close checklist,
 * are two readings of the same underlying state. Deriving them once here keeps the
 * two screens from drifting apart.
 */
class Ledger
{
    public const MONTHS = ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026'];

    /** Periods locked against further posting. */
    public const CLOSED = ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026'];

    public const CURRENT_PERIOD = 'Aug 2026';

    /** Months of the financial year elapsed at the current period. */
    public const MONTHS_ELAPSED = 8;

    // ---- Chart of accounts ----

    public static function leaves(): array
    {
        return array_values(array_filter(Prototype::load('SEED'), fn ($a) => $a['level'] === 2));
    }

    public static function acctBal(string $code): float
    {
        foreach (Prototype::load('SEED') as $a) {
            if ($a['code'] === $code) {
                return (float) $a['balance'];
            }
        }

        return 0.0;
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
            array_filter(Prototype::load('FUNDS'), fn ($f) => $f['cls'] === $cls)
        ));
    }

    /** Restricted funds closing within 90 days — unspent balances are returnable at close. */
    public static function expiringFunds(): array
    {
        return array_values(array_filter(
            Prototype::load('FUNDS'),
            fn ($f) => $f['daysLeft'] <= 90 && $f['cls'] === 'Restricted'
        ));
    }

    public static function restrictedUtilisation(): int
    {
        $restricted = array_filter(Prototype::load('FUNDS'), fn ($f) => $f['cls'] === 'Restricted');
        $spend = array_sum(array_map(fn ($f) => $f['spend'], $restricted));
        $avail = array_sum(array_map(fn ($f) => self::fundAvailable($f), $restricted));

        return (int) round($spend / max(1, $avail) * 100);
    }

    // ---- Payables ----

    public static function openBills(): array
    {
        return array_values(array_filter(
            Prototype::load('BILLS'),
            fn ($b) => !in_array($b['status'], ['Paid', 'Rejected'], true)
        ));
    }

    public static function overdueBills(): array
    {
        return array_values(array_filter(self::openBills(), fn ($b) => $b['dueIn'] < 0));
    }

    public static function awaitingBills(): array
    {
        return array_values(array_filter(Prototype::load('BILLS'), fn ($b) => $b['status'] === 'Awaiting approval'));
    }

    public static function billsNet(array $bills): float
    {
        return array_sum(array_map(fn ($b) => Payables::totals($b)['net'], $bills));
    }

    // ---- Journals ----

    public static function journalsByStatus(string $status): array
    {
        return array_values(array_filter(Prototype::load('JOURNALS'), fn ($j) => $j['status'] === $status));
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
            Prototype::load('DREPORTS'),
            fn ($r) => self::reportReported($r) !== self::reportCumulative($r)
        ));
    }

    public static function overdueReports(): array
    {
        return array_values(array_filter(Prototype::load('DREPORTS'), fn ($r) => $r['status'] === 'Overdue'));
    }

    // ---- Budget ----

    /** Phased budget to date, then the status a line earns against it. */
    public static function budgetLines(): array
    {
        return array_map(static function ($l) {
            $phased = (int) round($l['annual'] * self::MONTHS_ELAPSED / 12);
            $status = $l['actual'] > $l['annual'] ? 'Over'
                : ($l['actual'] > $phased * 1.1 ? 'Watch'
                : ($l['actual'] < $phased * 0.7 ? 'Underspent' : 'On track'));

            return array_merge($l, [
                'phased'   => $phased,
                'variance' => $phased - $l['actual'],
                'pct'      => $l['annual'] > 0 ? (int) round($l['actual'] / $l['annual'] * 100) : 0,
                'status'   => $status,
            ]);
        }, Prototype::load('BUDGET'));
    }

    public static function overBudgetLines(): array
    {
        return array_values(array_filter(self::budgetLines(), fn ($l) => $l['status'] === 'Over'));
    }

    // ---- Assets ----

    public static function depreciationRunRate(): float
    {
        return array_sum(array_map(
            fn ($a) => Assets::monthlyCharge($a),
            array_filter(Prototype::load('ASSETS'), fn ($a) => $a['status'] === 'In use')
        ));
    }

    // ---- Grants ----

    public static function liveGrants(): array
    {
        return array_values(array_filter(
            Prototype::load('GRANTS'),
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
        return array_values(array_filter(Prototype::load('SEED'), static fn ($a) => ($a['status'] ?? 'Active') === 'Active'
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

    /** Assets less liabilities equal the fund balances. */
    public static function positionBalanced(): bool
    {
        $assets = self::sumCodes(['1110', '1120', '1130', '1140', '1210', '1220', '1230', '1240', '1310', '1320', '1390']);
        $liab   = self::sumCodes(['2110', '2120', '2130', '2210', '2220', '2230', '2240']);
        $funds  = self::sumCodes(self::EQUITY_CODES);

        return round($assets - $liab) == round($funds);
    }

    /** Segments that carry donor meaning but are not yet mandatory on postings. */
    public static function openSegments(): array
    {
        return array_values(array_filter(
            Prototype::load('ST_SEGMENTS'),
            fn ($s) => !$s['required'] && in_array($s['key'], ['fund', 'grant', 'restriction'], true)
        ));
    }

    public static function earliestOpenPeriod(): string
    {
        foreach (self::MONTHS as $m) {
            if (!in_array($m, self::CLOSED, true)) {
                return $m;
            }
        }

        return 'none';
    }
}
