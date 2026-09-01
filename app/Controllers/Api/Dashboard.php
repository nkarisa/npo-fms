<?php

namespace App\Controllers\Api;

use App\Libraries\Ledger;
use App\Libraries\Prototype;

class Dashboard extends BaseApiController
{
    public function index()
    {
        return $this->json([
            'date'         => 'Thursday, 27 August 2026 · August open',
            'stats'        => $this->stats(),
            'queue'        => $this->queue(),
            'queueHint'    => $this->queueHint(),
            'grants'       => $this->grants(),
            'grantFooter'  => $this->grantFooter(),
            'funds'        => $this->funds(),
            'fundHint'     => $this->fundHint(),
            'checks'       => $this->checks(),
            'activity'     => array_slice(Prototype::load('ST_AUDIT'), 0, 4),
        ]);
    }

    private function stats(): array
    {
        $live    = Ledger::liveGrants();
        $income  = Ledger::byType('Income');
        $expense = Ledger::byType('Expense');
        $overdue = Ledger::overdueBills();

        return [
            [
                'label' => 'Cash and bank',
                'value' => Prototype::fmt(Ledger::sumCodes(['1110', '1120', '1130', '1140'])),
                'note'  => Prototype::fmt(Ledger::acctBal('1120')) . ' of it restricted',
            ],
            [
                'label' => 'Restricted funds held',
                'value' => Prototype::fmt(Ledger::fundsByClass('Restricted')),
                'note'  => Ledger::restrictedUtilisation() . '% utilised',
            ],
            [
                'label' => 'Unspent commitment',
                'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['value'] - $g['spent'], $live))),
                'note'  => count($live) . ' live awards to deliver',
            ],
            [
                'label' => 'Owed to suppliers',
                'value' => Prototype::fmt(Ledger::billsNet(Ledger::openBills())),
                'note'  => count($overdue) . ' bills past due',
            ],
            [
                'label' => 'Surplus year to date',
                'value' => Prototype::fmt($income - $expense),
                'note'  => Prototype::fmt($income) . ' income, ' . Prototype::fmt($expense) . ' spent',
            ],
        ];
    }

    /**
     * Everything that is genuinely waiting on a decision, most pressing first.
     * Items with nothing outstanding drop out rather than showing a zero.
     */
    private function queue(): array
    {
        $pending   = count(Ledger::journalsByStatus('Pending approval'));
        $awaiting  = Ledger::awaitingBills();
        $overdue   = Ledger::overdueBills();
        $untied    = Ledger::untiedReports();
        $drafts    = Ledger::unbalancedDrafts();
        $lateDonor = Ledger::overdueReports();
        $overLines = Ledger::overBudgetLines();
        $expiring  = Ledger::expiringFunds();
        $accounts  = Prototype::load('BR_ACCOUNTS');

        $plural = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);

        $items = [
            [
                'n' => $pending, 'tone' => 'warn', 'cta' => 'Review',
                'title' => 'Journals waiting for your approval',
                'detail' => 'Nothing posts to the ledger until you sign these off',
                'value' => $plural($pending, 'journal', 'journals'),
                'href' => '/journals?status=Pending+approval',
            ],
            [
                'n' => count($awaiting), 'tone' => 'warn', 'cta' => 'Approve',
                'title' => 'Supplier bills waiting for sign-off',
                'detail' => count($awaiting) . ' bills above the delegated limit',
                'value' => Prototype::fmt(Ledger::billsNet($awaiting)),
                'href' => '/payables?status=Awaiting+approval',
            ],
            [
                'n' => count($overdue), 'tone' => 'urgent', 'cta' => 'Schedule',
                'title' => 'Suppliers already past due',
                'detail' => 'Late payment risks the field logistics contracts',
                'value' => Prototype::fmt(Ledger::billsNet($overdue)),
                'href' => '/payables?status=Overdue',
            ],
            [
                'n' => count($untied), 'tone' => 'urgent', 'cta' => 'Reconcile',
                'title' => 'Donor reports that do not tie to the ledger',
                'detail' => 'These cannot be submitted until the difference is resolved',
                'value' => $plural(count($untied), 'report', 'reports'),
                'href' => '/donor-reports',
            ],
            [
                'n' => count($drafts), 'tone' => 'urgent', 'cta' => 'Fix',
                'title' => 'Draft journals out of balance',
                'detail' => 'Debits and credits do not agree',
                'value' => $plural(count($drafts), 'draft', 'drafts'),
                'href' => '/journals?status=Draft',
            ],
            [
                // No depreciation run has been posted for the open period yet.
                'n' => 1, 'tone' => 'warn', 'cta' => 'Run',
                'title' => 'Depreciation not yet run for ' . Ledger::CURRENT_PERIOD,
                'detail' => 'The asset register has calculated the charge but nothing is in the ledger',
                'value' => Prototype::fmt(Ledger::depreciationRunRate()),
                'href' => '/asset-register',
            ],
            [
                'n' => count($accounts), 'tone' => 'warn', 'cta' => 'Reconcile',
                'title' => 'Bank and M-Pesa accounts not yet reconciled',
                'detail' => 'August cannot be closed until every statement agrees to the cash book',
                'value' => count($accounts) . ' of ' . count($accounts) . ' accounts',
                'href' => '/bank-rec',
            ],
            [
                'n' => count($overLines), 'tone' => 'warn', 'cta' => 'Revise',
                'title' => 'Budget lines over their approved amount',
                'detail' => 'A revision or a reallocation is needed before further spend',
                'value' => $plural(count($overLines), 'line', 'lines'),
                'href' => '/budgets',
            ],
            [
                'n' => count($lateDonor), 'tone' => 'urgent', 'cta' => 'Open',
                'title' => 'Donor reports past their deadline',
                'detail' => 'Overdue reporting holds up the next disbursement',
                'value' => count($lateDonor) . ' late',
                'href' => '/donor-reports?status=Overdue',
            ],
            [
                'n' => count($expiring), 'tone' => 'calm', 'cta' => 'Review',
                'title' => 'Restricted funds closing within 90 days',
                'detail' => 'Unspent balances are returnable to the donor at close',
                'value' => Prototype::fmt(array_sum(array_map(fn ($f) => Ledger::fundAvailable($f) - $f['spend'], $expiring))),
                'href' => '/funds?filter=Restricted',
            ],
        ];

        return array_values(array_map(
            fn ($q) => array_diff_key($q, ['n' => null]),
            array_filter($items, fn ($q) => $q['n'] > 0)
        ));
    }

    private function queueHint(): string
    {
        $n = count($this->queue());

        return $n ? $n . ($n === 1 ? ' item' : ' items') . ' open · oldest 11 days' : 'Clear';
    }

    /** The four awards burning fastest — those are the ones worth a look. */
    private function grants(): array
    {
        $live = Ledger::liveGrants();
        usort($live, fn ($a, $b) => Ledger::burnPct($b) <=> Ledger::burnPct($a));

        return array_map(function ($g) {
            $burn = Ledger::burnPct($g);
            $pace = $burn > $g['elapsed'] + 5 ? 'Ahead of schedule by ' . ($burn - $g['elapsed']) . ' points'
                : ($burn < $g['elapsed'] - 5 ? 'Behind schedule by ' . ($g['elapsed'] - $burn) . ' points'
                : 'Tracking to plan');

            return [
                'ref'       => $g['ref'],
                'funder'    => $g['funder'],
                'program'   => $g['program'],
                'money'     => Prototype::fmt($g['spent']) . ' of ' . Prototype::fmt($g['value']),
                'burnPct'   => min(100, $burn),
                'elapsed'   => min(100, $g['elapsed']),
                'burnLabel' => $burn . '% spent · ' . Prototype::fmt($g['value'] - $g['spent']) . ' remaining',
                'paceLabel' => $pace,
                // Amber once spend has outrun the calendar by more than five points.
                'barColour' => $burn > $g['elapsed'] + 5 ? '#B4703A' : '#2C6B58',
            ];
        }, array_slice($live, 0, 4));
    }

    private function grantFooter(): string
    {
        $live = Ledger::liveGrants();
        $burn = (int) round(array_sum(array_map(fn ($g) => $g['spent'], $live)) / max(1, array_sum(array_map(fn ($g) => $g['value'], $live))) * 100);
        $elapsed = (int) round(array_sum(array_map(fn ($g) => $g['elapsed'], $live)) / max(1, count($live)));

        return count($live) . ' live awards · portfolio burn ' . $burn . '% against ' . $elapsed . '% elapsed';
    }

    private function funds(): array
    {
        $all = Prototype::load('FUNDS');
        $total = array_sum(array_map(fn ($f) => Ledger::fundClose($f), $all));
        usort($all, fn ($a, $b) => Ledger::fundClose($b) <=> Ledger::fundClose($a));

        return array_map(fn ($f) => [
            'name'   => $f['name'],
            'value'  => Prototype::fmt(Ledger::fundClose($f)),
            'pct'    => max(2, (int) round(Ledger::fundClose($f) / max(1, $total) * 100)),
            'colour' => $f['cls'] === 'Unrestricted' ? '#2C6B58' : ($f['cls'] === 'Endowment' ? '#4A6B7A' : '#8A9A5B'),
            'href'   => '/funds?fund=' . rawurlencode($f['code']),
        ], array_slice($all, 0, 5));
    }

    private function fundHint(): string
    {
        $all = Prototype::load('FUNDS');
        $total = array_sum(array_map(fn ($f) => Ledger::fundClose($f), $all));

        return Prototype::fmt($total) . ' across ' . count($all) . ' funds';
    }

    /** Does the book hold together — the five assertions the finance manager signs against. */
    private function checks(): array
    {
        $tb     = Ledger::trialBalanceBalanced();
        $pos    = Ledger::positionBalanced();
        $untied = Ledger::untiedReports();
        $open   = Ledger::openSegments();

        return [
            [
                'label' => 'Trial balance',
                'note'  => $tb ? 'Debits equal credits across every active account' : 'The ledger is out of balance',
                'ok'    => $tb,
                'href'  => '/reports?report=Trial+balance',
            ],
            [
                'label' => 'Statement of financial position',
                'note'  => $pos ? 'Assets less liabilities equal the fund balances' : 'The statement does not balance',
                'ok'    => $pos,
                'href'  => '/reports?report=Statement+of+financial+position',
            ],
            [
                'label' => 'Donor reports against the ledger',
                'note'  => $untied
                    ? count($untied) . (count($untied) === 1 ? ' report does not tie' : ' reports do not tie')
                    : 'Every report ties to the postings behind it',
                'ok'    => !$untied,
                'href'  => '/donor-reports',
            ],
            [
                'label' => 'Donor traceability',
                'note'  => $open
                    ? 'The ' . implode(' and ', array_map(fn ($s) => strtolower($s['name']), $open)) . ' segment is still optional on postings'
                    : 'Fund, grant and restriction are mandatory on every posting',
                'ok'    => !$open,
                'href'  => '/settings?section=Segments',
            ],
            [
                'label' => 'Closed periods',
                'note'  => count(Ledger::CLOSED) . ' periods locked to further posting · earliest open is ' . Ledger::earliestOpenPeriod(),
                'ok'    => true,
                'href'  => '/period-close',
            ],
        ];
    }
}
