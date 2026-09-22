<?php

namespace App\Controllers\Api;

use App\Libraries\Clock;
use App\Libraries\Ledger;
use App\Libraries\Prototype;
use App\Repositories\BankRepository;
use App\Repositories\FundRepository;
use App\Repositories\Lookups;
use App\Repositories\SettingsRepository;

class Dashboard extends BaseApiController
{
    public function index()
    {
        return $this->json([
            'date'         => Clock::today()->format('l, j F Y') . ' · ' . date('F', strtotime('1 ' . Ledger::currentPeriod())) . ' open',
            'subtitle'     => 'One reconciling book. What follows is drawn from the ledger as it stands this morning — nothing here is entered twice or kept on a side spreadsheet.',
            'stats'        => $this->stats(),
            'queue'        => $this->queue(),
            'queueHint'    => $this->queueHint(),
            'grants'       => $this->grants(),
            'grantFooter'  => $this->grantFooter(),
            'funds'        => $this->funds(),
            'fundHint'     => $this->fundHint(),
            'checks'       => $this->checks(),
            'activity'     => array_map(
                static fn ($a) => $a + ['meta' => $a['when'] . ' · ' . $a['who'] . ' · ' . $a['area']],
                array_slice((new SettingsRepository())->auditLog(), 0, 4)
            ),
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
        $accounts  = (new BankRepository())->accounts();
        $unreconciled = (new BankRepository())->unreconciled();

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
                'title' => 'Depreciation not yet run for ' . Ledger::currentPeriod(),
                'detail' => 'The asset register has calculated the charge but nothing is in the ledger',
                'value' => Prototype::fmt(Ledger::depreciationRunRate()),
                'href' => '/asset-register',
            ],
            [
                'n' => count($unreconciled), 'tone' => 'warn', 'cta' => 'Reconcile',
                'title' => 'Bank and M-Pesa accounts not yet reconciled',
                'detail' => date('F', strtotime('1 ' . Ledger::currentPeriod())) . ' cannot be closed until every statement agrees to the cash book',
                'value' => count($unreconciled) . ' of ' . count($accounts) . ' accounts',
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

    /** Whose queue this is — it is filtered to the acting user's decisions. */
    private function queueHint(): string
    {
        $n    = count($this->queue());
        $role = $this->actor()['role'];

        return $n ? $n . ($n === 1 ? ' item' : ' items') . ' open · ' . $role : $role . ' · clear';
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
        $all = (new FundRepository())->all();
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
        $all = (new FundRepository())->all();
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
                'note'  => count(Ledger::closedPeriods()) . ' periods locked to further posting · earliest open is ' . Ledger::earliestOpenPeriod(),
                'ok'    => true,
                'href'  => '/period-close',
            ],
        ];
    }

    /**
     * The board pack: a governance summary for trustees, deliberately without
     * account-level detail — that lives in the monthly close pack.
     */
    public function boardPack()
    {
        $cash     = Ledger::sumCodes(['1110', '1120', '1130', '1140']);
        $income   = Ledger::byType('Income');
        $spend    = Ledger::byType('Expense');
        $burn     = (int) round($spend / max(1, Ledger::monthsElapsed()));
        // Whole months only: part of a month of cover is not a month of payroll.
        $runway   = $burn > 0 ? (int) floor($cash / $burn) : 0;
        $funds    = (new FundRepository())->all();
        $total    = array_sum(array_map(static fn ($f) => Ledger::fundClose($f), $funds));
        $restrict = Ledger::fundsByClass('Restricted');
        $payable  = Ledger::billsNet(Ledger::openBills());

        $overdueBills = Ledger::overdueBills();
        $lateReports  = Ledger::overdueReports();
        $overLines    = Ledger::overBudgetLines();
        $expiring     = Ledger::expiringFunds();
        $returnable   = array_sum(array_map(static fn ($f) => Ledger::fundAvailable($f) - $f['spend'], $expiring));

        $plural = static fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);

        $risks = [
            [
                'area' => 'Liquidity',
                'note' => $overdueBills
                    ? count($overdueBills) . ' supplier bills already past due, ' . Prototype::fmt(Ledger::billsNet($overdueBills)) . ' outstanding'
                    : 'No supplier bill is past due',
                'ok'   => !$overdueBills,
            ],
            [
                'area' => 'Donor reporting',
                'note' => $lateReports
                    ? $plural(count($lateReports), 'donor report is', 'donor reports are') . ' past the reporting deadline'
                    : 'Every donor report is within its deadline',
                'ok'   => !$lateReports,
            ],
            [
                'area' => 'Budget control',
                'note' => $overLines
                    ? $plural(count($overLines), 'budget line is', 'budget lines are') . ' over the approved amount'
                    : 'No budget line is over its approved amount',
                'ok'   => !$overLines,
            ],
            [
                'area' => 'Restricted funds',
                'note' => $expiring
                    ? $plural(count($expiring), 'restricted fund closes', 'restricted funds close') . ' within 90 days · ' . Prototype::fmt($returnable) . ' unspent and returnable'
                    : 'No restricted fund closes within 90 days',
                'ok'   => !$expiring,
            ],
            [
                'area' => 'Period close',
                'note' => count(Ledger::closedPeriods()) . ' periods locked · earliest open is ' . Ledger::earliestOpenPeriod(),
                'ok'   => true,
            ],
            [
                'area' => 'Segregation of duties',
                'note' => 'No journal reaches the ledger without a second person approving it',
                'ok'   => true,
            ],
        ];

        $attention = count(array_filter($risks, static fn ($r) => !$r['ok']));

        $sections = [
            ['name' => 'Financial position', 'figure' => Prototype::fmt($cash) . ' cash held'],
            ['name' => 'Income and expenditure against budget', 'figure' => Prototype::fmt($income - $spend) . ' surplus'],
            ['name' => 'Cash and runway', 'figure' => $runway . ' months at current burn'],
            ['name' => 'Funds and restricted balances', 'figure' => Prototype::fmt($restrict) . ' restricted'],
            ['name' => 'Grant burn against elapsed time', 'figure' => count(Ledger::liveGrants()) . ' live awards'],
            ['name' => 'Payables ageing', 'figure' => Prototype::fmt($payable) . ' outstanding'],
            ['name' => 'Risk and compliance', 'figure' => $plural($attention, 'matter', 'matters') . ' for attention'],
        ];

        return $this->json([
            'title'    => 'Board pack',
            'meta'     => (new Lookups())->organisationNames()['registered'] . ' · prepared for the Board of Trustees · KES',
            'intro'    => "A governance summary of the position, the funds held and the matters needing the board's attention. Account-level detail sits in the monthly close pack.",
            'headline' => [
                ['label' => 'Cash held', 'value' => Prototype::fmt($cash), 'note' => 'across bank, M-Pesa and petty cash'],
                ['label' => 'Runway', 'value' => $runway . ' months', 'note' => 'at ' . Prototype::fmt($burn) . ' average monthly spend'],
                ['label' => 'Surplus to date', 'value' => Prototype::fmt($income - $spend), 'note' => Prototype::fmt($income) . ' income against ' . Prototype::fmt($spend) . ' spend'],
                ['label' => 'Restricted funds', 'value' => Prototype::fmt($restrict), 'note' => 'of ' . Prototype::fmt($total) . ' held across ' . count($funds) . ' funds'],
            ],
            'sections' => array_map(
                static fn ($x, $i) => $x + ['no' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)],
                $sections,
                array_keys($sections)
            ),
            'risks'     => $risks,
            'attention' => $attention
                ? $plural($attention, 'matter needs', 'matters need') . " the board's attention"
                : "Nothing requires the board's attention this period",
            'footer'    => 'Eight pages · governance summary, no account detail',
        ]);
    }
}
