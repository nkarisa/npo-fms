<?php

namespace App\Repositories;

use App\Libraries\Ledger;

/**
 * The financial statements — financial position, activities, cash flows and the
 * trial balance — for a period, against a comparative, read from the posted ledger.
 *
 * A period is a range of months: the year to date, a completed quarter, a month,
 * or an earlier financial year. A statement of position (and the trial balance)
 * reads the books as at the range's last day; a statement of activities or cash
 * flows reads what moved within it.
 *
 * A financial year's figures come from the ledger when anything is posted in it,
 * and otherwise from legacy_balances — the months kept in the system the ledger
 * replaced (AlignReports) — so the first year on the ledger can carry comparatives.
 * Never from both, so no year is counted twice.
 *
 * Membership of every statement is read from the chart, never listed: an account
 * added under an existing heading appears under it (see Ledger::statementLeaves for
 * what a hardcoded list once cost). Only the headings that decide where a group is
 * presented are fixed here.
 *
 * Figures are returned as numbers, debit or credit as each statement presents
 * them; the page formats them, and the export writes them as they are.
 */
final class ReportRepository extends Repository
{
    public const REPORTS = ['Statement of financial position', 'Statement of activities', 'Statement of cash flows', 'Trial balance'];

    public const COMPARATIVES = ['Prior year', 'Approved budget', 'None'];

    /** Cash and cash equivalents: the group the cash flow statement explains. */
    private const CASH_GROUP = '1100';

    /** Expenditure groups presented together as support costs — running the organisation rather than its programmes. */
    private const SUPPORT_GROUPS = ['5200', '5300'];

    /** Expenditure groups that pass money on to partners, presented on their own. */
    private const SUB_GRANT_GROUPS = ['5400'];

    /** Fund classes whose money is held for a donor's purpose rather than the organisation's. */
    private const RESTRICTED = ['restricted', 'endowment'];

    private const BROUGHT_FORWARD_SOURCE = 'fiscal_year';

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Periods
    // ------------------------------------------------------------------

    /**
     * The periods a statement can be drawn for: the working year to date, each
     * completed quarter of it, each of its months, latest first, and every earlier
     * financial year the books hold figures for.
     *
     * @return list<array{label: string, from: string, to: string, yearStart: string, kind: string, year: string}>
     */
    public function periods(): array
    {
        return $this->cached('report-periods', function () {
            $all = $this->lookups->periods();
            if ($all === []) {
                return [];
            }
            $current = (new PeriodRepository())->current() ?? end($all);
            $year = $this->yearOf($current['starts_on']);
            if ($year === null) {
                return [];
            }
            $inYear = array_values(array_filter($all, static fn ($p) => $p['starts_on'] >= $year['starts_on'] && $p['starts_on'] <= $current['starts_on']));
            $range = static fn (string $label, string $from, string $to, string $kind) => [
                'label' => $label, 'from' => $from, 'to' => $to, 'yearStart' => $year['starts_on'], 'kind' => $kind, 'year' => $year['code'],
            ];

            $out = [$range($current['name'] . ' YTD', $year['starts_on'], $current['ends_on'], 'ytd')];
            $quarters = [];
            foreach (array_chunk($inYear, 3) as $q => $months) {
                if (count($months) === 3 && end($months)['ends_on'] < $current['starts_on']) {
                    $quarters[] = $range('Q' . ($q + 1) . ' ' . substr(end($months)['ends_on'], 0, 4), $months[0]['starts_on'], end($months)['ends_on'], 'quarter');
                }
            }
            array_push($out, ...array_reverse($quarters));
            foreach (array_reverse($inYear) as $p) {
                $out[] = $range($p['name'], $p['starts_on'], $p['ends_on'], 'month');
            }
            foreach (array_reverse($this->years()) as $y) {
                if ($y['starts_on'] < $year['starts_on'] && $this->source($y) !== null) {
                    $out[] = ['label' => $y['code'] . ($y['status'] === 'closed' ? ' (final)' : ''), 'from' => $y['starts_on'], 'to' => $y['ends_on'],
                        'yearStart' => $y['starts_on'], 'kind' => 'year', 'year' => $y['code']];
                }
            }

            return $out;
        });
    }

    /** The same months a year earlier, labelled the way the period list labels them. */
    public function priorYear(array $range): array
    {
        $back = static fn (string $d) => date('Y-m-d', strtotime(substr($d, 0, 7) . '-01 -1 year'));
        $from = $back($range['from']);
        $to = date('Y-m-t', strtotime($back($range['to'])));
        $year = $this->yearOf($from);
        $label = match ($range['kind']) {
            'ytd'     => date('M Y', strtotime($to)) . ' YTD',
            'quarter' => preg_replace('/\d{4}$/', substr($to, 0, 4), $range['label']),
            'month'   => date('M Y', strtotime($to)),
            default   => ($year['code'] ?? 'FY' . substr($from, 0, 4)) . (($year['status'] ?? '') === 'closed' ? ' (final)' : ''),
        };

        return ['label' => $label, 'from' => $from, 'to' => $to, 'yearStart' => $year['starts_on'] ?? $back($range['yearStart']), 'kind' => $range['kind'], 'year' => $year['code'] ?? ''];
    }

    // ------------------------------------------------------------------
    // Statements
    // ------------------------------------------------------------------

    /**
     * One statement for one period, with its comparative.
     *
     * @return array the statement: columns, sections of rows, notes and the checks it passes
     */
    public function statement(string $report, array $range, string $compare, bool $split): array
    {
        $report = in_array($report, self::REPORTS, true) ? $report : self::REPORTS[0];
        $activities = $report === 'Statement of activities';
        if (!in_array($compare, self::COMPARATIVES, true) || ($compare === 'Approved budget' && !$activities)) {
            $compare = 'Prior year';
        }
        $split = $split && $activities;

        $now = $this->figures($range);
        $prior = $compare === 'Prior year' ? $this->priorYear($range) : null;
        $then = $prior === null ? null : $this->figures($prior);
        $budget = $compare === 'Approved budget' ? $this->budget($range) : null;

        $built = match ($report) {
            'Statement of activities' => $this->activities($now, $then, $budget, $split),
            'Statement of cash flows' => $this->cashFlows($now, $then),
            'Trial balance'           => $this->trialBalance($now, $then),
            default                   => $this->financialPosition($now, $then),
        };

        return $built + [
            'report'     => $report,
            'period'     => $range['label'],
            'range'      => $range,
            'compare'    => $compare,
            'comparative' => $prior === null ? null : [
                'label'  => $prior['label'],
                'source' => $then['source'],
                'held'   => $then['source'] !== null,
            ],
            'budget'     => $budget === null ? null : ['label' => $budget['label'], 'held' => $budget['held']],
            'split'      => $split,
            'source'     => $now['source'],
            'closed'     => $now['closed'],
            'openPeriods' => $now['openPeriods'],
        ];
    }

    /** Assets, liabilities and funds as at the period's last day. */
    private function financialPosition(array $now, ?array $then): array
    {
        $columns = [$now, ...($then === null ? [] : [$then])];
        $leaves = $this->leaves($columns, 'position');
        $value = static fn (array $f, string $code) => self::presented($leaves[$code]['type'] ?? 'Asset', $f['position'][$code] ?? null);
        $nonCurrent = static fn (array $l) => in_array($l['group'], Ledger::NON_CURRENT_ASSET_GROUPS, true);

        $groups = [
            'current'  => array_filter($leaves, static fn ($l) => $l['type'] === 'Asset' && !$nonCurrent($l)),
            'fixed'    => array_filter($leaves, static fn ($l) => $l['type'] === 'Asset' && $nonCurrent($l)),
            'liab'     => array_filter($leaves, static fn ($l) => $l['type'] === 'Liability'),
            'funds'    => array_filter($leaves, static fn ($l) => $l['type'] === 'Equity' && $l['code'] !== ChartRepository::DERIVED_SURPLUS),
        ];
        $sum = static fn (array $f, array $group) => array_sum(array_map(static fn ($code) => $value($f, (string) $code), array_keys($group)));
        $surplus = static fn (array $f) => self::surplusOf($f['yearIe']);
        $carried = static fn (array $f) => self::surplusOf($f['earlierIe']);

        $per = static function (callable $of) use ($columns) {
            return array_map(static fn ($f) => $f['source'] === null ? null : $of($f), $columns);
        };
        $tca = $per(static fn ($f) => $sum($f, $groups['current']));
        $tnca = $per(static fn ($f) => $sum($f, $groups['fixed']));
        $tl = $per(static fn ($f) => $sum($f, $groups['liab']));
        $tf = $per(static fn ($f) => $sum($f, $groups['funds']) + $surplus($f) + $carried($f));
        $net = array_map(static fn ($a, $b, $l) => $a === null ? null : $a + $b - $l, $tca, $tnca, $tl);

        $lines = fn (array $group) => array_values(array_map(fn ($l) => $this->statementRow($l['name'], $per(static fn ($f) => $value($f, $l['code'])), 'line', $l['code']), $group));
        $fundRows = $lines($groups['funds']);
        if (array_filter($per($carried), static fn ($v) => round((float) $v) != 0) !== []) {
            $fundRows[] = $this->statementRow('Accumulated surplus brought forward', $per($carried), 'line');
        }
        $derived = $leaves[ChartRepository::DERIVED_SURPLUS]['name'] ?? 'Surplus / (deficit) for the year';
        $fundRows[] = $this->statementRow($derived, $per($surplus), 'line', ChartRepository::DERIVED_SURPLUS, false);

        $balanced = $now['source'] === null || round($net[0]) == round($tf[0]);
        $restricted = $now['source'] === null ? 0 : array_sum(array_map(static fn ($code) => -($now['position'][$code]['r'] ?? 0), array_keys($groups['funds']))) + self::surplusOf($now['yearIe'], 'r');
        $receivable = $value($now, '1210');

        return [
            'columns'  => $this->valueColumns($now, $then, null),
            'labelColumn' => '',
            'sections' => [
                ['heading' => 'Current assets', 'rows' => [...$lines($groups['current']), $this->statementRow('Total current assets', $tca, 'sub')]],
                ['heading' => 'Non-current assets', 'rows' => [...$lines($groups['fixed']), $this->statementRow('Total non-current assets', $tnca, 'sub'), $this->statementRow('Total assets', array_map(static fn ($a, $b) => $a === null ? null : $a + $b, $tca, $tnca), 'total')]],
                ['heading' => 'Current liabilities', 'rows' => [...$lines($groups['liab']), $this->statementRow('Total liabilities', $tl, 'sub'), $this->statementRow('Net assets', $net, 'total')]],
                ['heading' => 'Funds and reserves', 'rows' => [...$fundRows, $this->statementRow('Total funds and reserves', $tf, 'total')]],
            ],
            'balanced' => $balanced,
            'notes'    => array_values(array_filter([
                $now['source'] === null ? null : ($balanced
                    ? 'Net assets of ' . self::money($net[0]) . ' equal total funds and reserves; the statement balances.'
                    : 'Net assets of ' . self::money($net[0]) . ' differ from total funds and reserves of ' . self::money($tf[0]) . ' by ' . self::money(abs($net[0] - $tf[0])) . ' — the statement does not balance.'),
                $now['source'] === null ? null : 'Surplus for the period of ' . self::money($surplus($now)) . ' is carried in funds and reserves and is derived from income less expenditure for the period, never posted.',
                round($restricted) == 0 ? null : 'Restricted and endowment funds, with the restricted part of the surplus, come to ' . self::money($restricted) . ' — held for specified donor purposes and not available for core costs.',
                round($receivable) == 0 ? null : ($leaves['1210']['name'] ?? 'Grants receivable') . ' of ' . self::money($receivable) . ' represent tranches claimed from funders but not yet banked at the reporting date.',
            ])),
            'footer'   => 'balances drawn from the chart of accounts',
        ];
    }

    /** Income and expenditure for the period, by restriction class when split. */
    private function activities(array $now, ?array $then, ?array $budget, bool $split): array
    {
        $columns = [$now, ...($then === null ? [] : [$then])];
        $leaves = $this->leaves($columns, 'activity', $budget['amounts'] ?? []);
        $income = array_filter($leaves, static fn ($l) => $l['type'] === 'Income');
        $expense = array_filter($leaves, static fn ($l) => $l['type'] === 'Expense');

        // A figure as the statement presents it: income as a credit, expenditure as a debit.
        $of = static function (?array $f, string $code, string $class = 'all') use ($leaves) {
            if ($f === null || $f['source'] === null) {
                return null;
            }
            $m = $f['activity'][$code] ?? ['u' => 0.0, 'r' => 0.0];
            $debit = $class === 'u' ? $m['u'] : ($class === 'r' ? $m['r'] : $m['u'] + $m['r']);

            return $leaves[$code]['type'] === 'Income' ? -$debit : $debit;
        };
        $compareOf = static function (string $code) use ($then, $budget, $of) {
            if ($budget !== null) {
                return $budget['held'] ? ($budget['amounts'][$code] ?? 0.0) : null;
            }

            return $then === null ? 0.0 : $of($then, $code);
        };
        $hasCompare = $then !== null || $budget !== null;
        $cells = static function (array $codes) use ($of, $compareOf, $now, $split, $hasCompare) {
            $sumOf = static fn (callable $f) => array_sum(array_map(static fn ($c) => (float) $f((string) $c), $codes));
            $row = $split
                ? [$sumOf(static fn ($c) => $of($now, $c, 'u')), $sumOf(static fn ($c) => $of($now, $c, 'r')), $sumOf(static fn ($c) => $of($now, $c))]
                : [$sumOf(static fn ($c) => $of($now, $c))];
            if ($now['source'] === null) {
                $row = array_fill(0, count($row), null);
            }
            if ($hasCompare) {
                $compare = array_map(static fn ($c) => $compareOf((string) $c), $codes);
                $row[] = in_array(null, $compare, true) ? null : array_sum(array_map('floatval', $compare));
            }

            return $row;
        };
        $diff = static fn (array $a, array $b) => array_map(static fn ($x, $y) => $x === null || $y === null ? null : $x - $y, $a, $b);

        // Expenditure groups: programmes and partners each on their own, support costs together.
        $byGroup = [];
        foreach ($expense as $code => $l) {
            $byGroup[$l['group']][] = (string) $code;
        }
        $sections = [];
        $incomeCodes = array_map('strval', array_keys($income));
        $sections[] = ['heading' => 'Income', 'rows' => [
            ...array_values(array_map(fn ($l) => $this->statementRow($l['name'], $cells([$l['code']]), 'line', $l['code'], true, $split), $income)),
            $this->statementRow('Total income', $cells($incomeCodes), 'total', null, true, $split),
        ]];
        $support = [];
        foreach ($byGroup as $group => $codes) {
            if (in_array((string) $group, self::SUPPORT_GROUPS, true)) {
                array_push($support, ...$codes);
                continue;
            }
            $name = $this->groupName((string) $group);
            $sections[] = ['heading' => in_array((string) $group, self::SUB_GRANT_GROUPS, true) ? 'Grants to implementing partners' : $name, 'rows' => [
                ...array_map(fn ($c) => $this->statementRow($expense[$c]['name'], $cells([$c]), 'line', $c, true, $split), $codes),
                $this->statementRow(in_array((string) $group, self::SUB_GRANT_GROUPS, true) ? 'Total sub-granting' : 'Total ' . mb_strtolower($name), $cells($codes), 'sub', null, true, $split),
            ]];
        }
        $expenseCodes = array_map('strval', array_keys($expense));
        $totalExpense = $cells($expenseCodes);
        $surplus = $diff($cells($incomeCodes), $totalExpense);
        $closing = [
            $this->statementRow('Total expenditure', $totalExpense, 'total', null, true, $split),
            $this->statementRow('Surplus / (deficit) for the period', $surplus, 'total', null, true, $split),
        ];
        if ($support !== []) {
            $sections[] = ['heading' => 'Personnel and administration', 'rows' => [
                ...array_map(fn ($c) => $this->statementRow($expense[$c]['name'], $cells([$c]), 'line', $c, true, $split), $support),
                $this->statementRow('Total support costs', $cells($support), 'sub', null, true, $split),
                ...$closing,
            ]];
        } else {
            array_push($sections[count($sections) - 1]['rows'], ...$closing);
        }

        $notes = [];
        if ($now['source'] !== null) {
            $all = $cells($expenseCodes);
            $restrictedSurplus = $split ? $surplus[1] : self::surplusOf($now['activity'], 'r');
            $notes[] = 'Surplus for the period is ' . self::money($split ? $surplus[2] : $surplus[0]) . ', of which ' . self::money($restrictedSurplus)
                . ' arises on restricted funds and is carried forward for donor purposes.';
            $supportCost = $support === [] ? 0.0 : (float) $cells($support)[$split ? 2 : 0];
            $totalCost = (float) $all[$split ? 2 : 0];
            if ($support !== [] && $totalCost > 0) {
                $caps = $this->indirectCaps();
                $notes[] = 'Support costs of ' . self::money($supportCost) . ' are ' . round($supportCost / $totalCost * 100) . '% of total expenditure'
                    . ($caps === [] ? '.' : '; live awards cap indirect costs at ' . implode(', ', $caps) . ', read against each award\'s own budget.');
            }
            $subGrants = array_merge(...array_map(static fn ($g) => $byGroup[$g] ?? [], self::SUB_GRANT_GROUPS));
            if ($subGrants !== []) {
                $notes[] = 'Sub-granting to partners of ' . self::money((float) $cells($subGrants)[$split ? 2 : 0]) . ' is reported gross, as the organisation acts as principal under its agreements.';
            }
        }
        if ($budget !== null) {
            $notes[] = !$budget['held'] ? 'There is no approved budget for ' . $budget['year'] . ', so the budget column is blank.'
                : ($budget['incomeHeld'] ? 'The budget column is the approved budget (' . $budget['version'] . ') phased to the same months.'
                    : 'The budget column is the approved budget (' . $budget['version'] . ') phased to the same months. It sets expenditure only, so income and the surplus carry no budget.');
            if ($budget['held'] && !$budget['incomeHeld']) {
                foreach ($sections as &$section) {
                    foreach ($section['rows'] as &$row) {
                        if (($row['code'] !== null && isset($income[$row['code']])) || in_array($row['label'], ['Total income', 'Surplus / (deficit) for the period'], true)) {
                            $row['cells'][count($row['cells']) - 1]['v'] = null;
                        }
                    }
                }
                unset($section, $row);
            }
        }

        return [
            'columns'  => $split
                ? ['Unrestricted', 'Restricted', 'Total', ...array_slice($this->valueColumns($now, $then, $budget), 1)]
                : $this->valueColumns($now, $then, $budget),
            'labelColumn' => '',
            'sections' => $sections,
            'notes'    => $notes,
            'footer'   => $split ? 'split by restriction class' : 'combined funds',
        ];
    }

    /**
     * Movement in cash for the period by the indirect method: the surplus, less what
     * did not move cash, then the balance sheet's other movements, grouped.
     */
    private function cashFlows(array $now, ?array $then): array
    {
        $columns = [$now, ...($then === null ? [] : [$then])];
        $chart = $this->chart();
        // Where each account's movement falls: cash itself, the surplus, or one of the lines that explain the change in cash.
        $bucket = static fn (array $a) => match (true) {
            $a['type'] === 'Income' || $a['type'] === 'Expense'        => 'surplus',
            $a['group'] === self::CASH_GROUP                           => 'cash',
            $a['type'] === 'Asset' && $a['contra'] && $a['nonCurrent'] => 'noncash',
            $a['type'] === 'Asset' && $a['nonCurrent']                 => 'investing',
            $a['type'] === 'Asset'                                     => 'receivables',
            $a['type'] === 'Liability'                                 => 'payables',
            default                                                    => 'financing',
        };
        $flows = array_map(static function ($f) use ($chart, $bucket) {
            if ($f['source'] === null) {
                return null;
            }
            $p = ['surplus' => 0.0, 'noncash' => 0.0, 'receivables' => 0.0, 'payables' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'cash' => 0.0, 'opening' => 0.0, 'closing' => 0.0];
            // Each line is the cash effect of a movement: a debit anywhere but cash is cash spent.
            foreach ($f['flow'] as $code => $m) {
                if (isset($chart[$code])) {
                    $p[$bucket($chart[$code])] -= $m['u'] + $m['r'];
                }
            }
            foreach (['start' => 'opening', 'position' => 'closing'] as $set => $to) {
                foreach ($f[$set] as $code => $m) {
                    if (($chart[$code]['group'] ?? '') === self::CASH_GROUP) {
                        $p[$to] += $m['u'] + $m['r'];
                    }
                }
            }
            // Every other movement's opposite is the movement in cash.
            $p['net'] = -$p['cash'];

            return $p;
        }, $columns);

        $col = static fn (callable $of) => array_map(static fn ($p) => $p === null ? null : $of($p), $flows);
        $operating = $col(static fn ($p) => $p['surplus'] + $p['noncash'] + $p['receivables'] + $p['payables']);
        // A line opens its postings only when one account stands behind it.
        $only = static function (callable $where) use ($chart) {
            $codes = array_keys(array_filter($chart, static fn ($a) => $a['leaf'] && $where($a)));

            return count($codes) === 1 ? (string) $codes[0] : null;
        };
        $contra = $only(static fn ($a) => $bucket($a) === 'noncash');
        $capital = $only(static fn ($a) => $bucket($a) === 'investing');
        $funding = $only(static fn ($a) => $bucket($a) === 'financing' && $a['code'] !== ChartRepository::DERIVED_SURPLUS);
        $net = $col(static fn ($p) => $p['net']);
        $articulates = $flows[0] === null || round($flows[0]['opening'] + $flows[0]['net']) == round($flows[0]['closing']);

        $cashNames = array_map(static fn ($c) => $chart[$c]['name'], array_keys(array_filter($chart, static fn ($a) => $a['group'] === self::CASH_GROUP && $a['leaf'])));
        $usd = array_key_first(array_filter($chart, static fn ($a) => $a['group'] === self::CASH_GROUP && $a['leaf'] && str_contains($a['name'], 'USD')));
        $mpesa = array_key_first(array_filter($chart, static fn ($a) => $a['group'] === self::CASH_GROUP && $a['leaf'] && str_contains(mb_strtolower($a['name']), 'm-pesa')));
        $deferred = array_key_first(array_filter($chart, static fn ($a) => $a['type'] === 'Liability' && $a['leaf'] && str_contains(mb_strtolower($a['name']), 'deferred')));
        $bal = static fn (?string $code) => $code === null || $now['source'] === null ? 0.0 : self::presented($chart[$code]['type'], $now['position'][$code] ?? null);

        return [
            'columns'  => $this->valueColumns($now, $then, null),
            'labelColumn' => '',
            'sections' => [
                ['heading' => 'Cash flows from operating activities', 'rows' => [
                    $this->statementRow('Surplus for the period', $col(static fn ($p) => $p['surplus']), 'line'),
                    $this->statementRow('Adjustment — depreciation', $col(static fn ($p) => $p['noncash']), 'line', $contra),
                    $this->statementRow('Movement in receivables and prepayments', $col(static fn ($p) => $p['receivables']), 'line'),
                    $this->statementRow('Movement in payables and statutory liabilities', $col(static fn ($p) => $p['payables']), 'line'),
                    $this->statementRow('Net cash from operating activities', $operating, 'sub'),
                ]],
                ['heading' => 'Cash flows from investing activities', 'rows' => [
                    $this->statementRow('Purchase of property and equipment', $col(static fn ($p) => $p['investing']), 'line', $capital),
                    $this->statementRow('Net cash used in investing activities', $col(static fn ($p) => $p['investing']), 'sub'),
                ]],
                ['heading' => 'Cash flows from financing activities', 'rows' => [
                    $this->statementRow('Endowment and fund contributions received', $col(static fn ($p) => $p['financing']), 'line', $funding),
                    $this->statementRow('Net cash from financing activities', $col(static fn ($p) => $p['financing']), 'sub'),
                    $this->statementRow('Net increase in cash and cash equivalents', $net, 'total'),
                ]],
                ['heading' => 'Cash and cash equivalents', 'rows' => [
                    $this->statementRow('Balance at the beginning of the period', $col(static fn ($p) => $p['opening']), 'line'),
                    $this->statementRow('Net increase for the period', $net, 'line'),
                    $this->statementRow('Balance at the end of the period', $col(static fn ($p) => $p['opening'] + $p['net']), 'total'),
                ]],
            ],
            'balanced' => $articulates,
            'notes'    => array_values(array_filter([
                $flows[0] === null ? null : ($articulates
                    ? 'Closing cash of ' . self::money($flows[0]['closing']) . ' agrees to the ' . count($cashNames) . ' cash and bank accounts on the statement of financial position'
                        . ($usd !== null && round($bal($usd)) != 0 ? ', and includes ' . self::money($bal($usd)) . ' held in the ' . $chart[$usd]['name'] . ', which is restricted to grant purposes.' : '.')
                    : 'Opening cash and the period\'s movement come to ' . self::money($flows[0]['opening'] + $flows[0]['net']) . ', but the cash accounts hold ' . self::money($flows[0]['closing']) . ' — a balance was brought forward part-way through the period.'),
                $deferred === null || round($bal($deferred)) == 0 ? null : $chart[$deferred]['name'] . ' of ' . self::money($bal($deferred)) . ' is cash received in advance of expenditure and will be released to income as activities are delivered.',
                $mpesa === null || round($bal($mpesa)) == 0 ? null : 'The ' . $chart[$mpesa]['name'] . ' of ' . self::money($bal($mpesa)) . ' is reconciled daily against the paybill statement.',
            ])),
            'footer'   => 'indirect method',
        ];
    }

    /** Every account carrying a balance, on the side it falls, as at the period's last day. */
    private function trialBalance(array $now, ?array $then): array
    {
        $columns = [$now, ...($then === null ? [] : [$then])];
        $leaves = $this->leaves($columns, 'trial');
        $side = static function (?array $f, string $code) {
            if ($f === null || $f['source'] === null) {
                return null;
            }
            $m = ($f['trial'][$code] ?? ['u' => 0.0, 'r' => 0.0]);

            return round($m['u'] + $m['r'], 2);
        };

        $dr = $cr = 0.0;
        $rows = [];
        foreach ($leaves as $code => $l) {
            $code = (string) $code;
            $v = $side($now, $code);
            $cells = [
                ['v' => $v !== null && $v > 0 ? $v : null],
                ['v' => $v !== null && $v < 0 ? -$v : null],
            ];
            if ($v !== null) {
                $v > 0 ? $dr += $v : $cr -= $v;
            }
            if ($then !== null) {
                $p = $side($then, $code);
                $cells[] = ['v' => $p === null ? null : abs($p), 'muted' => true];
            }
            $rows[] = ['label' => $l['name'], 'code' => $code, 'kind' => 'line', 'cells' => $cells, 'drill' => $code];
        }
        $carried = $now['source'] === null ? 0.0 : -self::surplusOf($now['earlierIe']);
        if (round($carried) != 0) {
            $rows[] = ['label' => 'Accumulated surplus brought forward', 'code' => null, 'kind' => 'line', 'drill' => null,
                'cells' => [['v' => $carried > 0 ? $carried : null], ['v' => $carried < 0 ? -$carried : null], ...($then === null ? [] : [['v' => null]])]];
            $carried > 0 ? $dr += $carried : $cr -= $carried;
        }
        $balanced = $now['source'] === null || round($dr - $cr) == 0;
        $rows[] = ['label' => 'Totals', 'code' => null, 'kind' => 'total', 'drill' => null,
            'cells' => [['v' => $dr, 'bold' => true, 'accent' => true], ['v' => $cr, 'bold' => true, 'accent' => true], ...($then === null ? [] : [['v' => null]])]];
        // The contra account the note explains is the one carrying most.
        $contras = array_filter($leaves, static fn ($l) => $l['contra'] && round(abs((float) $side($now, $l['code']))) != 0);
        uasort($contras, static fn ($a, $b) => abs((float) $side($now, $b['code'])) <=> abs((float) $side($now, $a['code'])));
        $contra = current($contras);

        return [
            'columns'  => ['Debit', 'Credit', ...($then === null ? [] : ['Prior'])],
            'labelColumn' => 'Account',
            'sections' => [['heading' => null, 'rows' => $rows]],
            'balanced' => $balanced,
            'notes'    => array_values(array_filter([
                $now['source'] === null ? null : ($balanced
                    ? 'Debits and credits both total ' . self::money($dr) . '; the ledger is in balance.'
                    : 'Debits of ' . self::money($dr) . ' and credits of ' . self::money($cr) . ' differ by ' . self::money(abs($dr - $cr)) . ' — the ledger does not balance and must be investigated before reporting.'),
                $contra === false || $now['source'] === null ? null : 'Contra accounts appear on the side their balance falls: ' . mb_strtolower($contra['name']) . ' of ' . self::money(abs((float) $side($now, $contra['code']))) . ' carries a credit balance inside the asset block.',
                'Surplus for the year is derived at close and is excluded here, since the income and expenditure accounts that produce it are still open. Archived accounts appear only while they carry a balance.',
            ])),
            'footer'   => count($leaves) . ' accounts · ' . ($balanced ? 'in balance' : 'out of balance by ' . self::money(abs($dr - $cr))),
        ];
    }

    // ------------------------------------------------------------------
    // Figures
    // ------------------------------------------------------------------

    /**
     * Everything a statement reads for one period, by account and restriction class
     * ('u' unrestricted, 'r' restricted), debit positive:
     *
     * - position:  balances as at the last day, the year's income and expenditure included
     * - trial:     the same, but income and expenditure for this year only, and funds as posted
     * - yearIe:    income and expenditure from the start of the year to the last day
     * - earlierIe: income and expenditure of earlier years on the ledger, not yet closed to funds
     * - activity:  income and expenditure within the period
     * - start:     balances when the period opened, balances brought forward included
     * - flow:      everything that moved within the period, balances brought forward excluded
     */
    private function figures(array $range): array
    {
        return $this->cached('figures:' . $range['from'] . ':' . $range['to'], function () use ($range) {
            $year = $this->yearOf($range['from']);
            $source = $year === null ? null : $this->source($year);
            $empty = ['position' => [], 'trial' => [], 'yearIe' => [], 'earlierIe' => [], 'activity' => [], 'start' => [], 'flow' => []];
            $periods = array_filter($this->lookups->periods(), static fn ($p) => $p['starts_on'] >= $range['from'] && $p['ends_on'] <= $range['to']);
            $open = array_values(array_column(array_filter($periods, static fn ($p) => $p['status'] !== 'closed'), 'name'));
            $meta = ['source' => $source, 'range' => $range, 'closed' => $periods !== [] && $open === [], 'openPeriods' => $open];

            if ($source === null) {
                return $empty + $meta;
            }
            $ie = "a.type IN ('income', 'expense')";
            $notBroughtForward = "(j.source_type IS NULL OR j.source_type <> '" . self::BROUGHT_FORWARD_SOURCE . "')";

            if ($source === 'ledger') {
                $position = $this->ledger('j.journal_date <= ?', [$range['to']]);
                $yearIe = $this->ledger("{$ie} AND j.journal_date BETWEEN ? AND ?", [$year['starts_on'], $range['to']]);
                $earlierIe = $this->ledger("{$ie} AND j.journal_date < ?", [$year['starts_on']]);

                return [
                    'position'  => $position,
                    'trial'     => self::minus($position, $earlierIe),
                    'yearIe'    => $yearIe,
                    'earlierIe' => $earlierIe,
                    'activity'  => $this->ledger("{$ie} AND j.journal_date BETWEEN ? AND ?", [$range['from'], $range['to']]),
                    'start'     => $this->ledger("(j.journal_date < ? OR (j.source_type = '" . self::BROUGHT_FORWARD_SOURCE . "' AND j.journal_date <= ?))", [$range['from'], $range['from']]),
                    'flow'      => $this->ledger("j.journal_date BETWEEN ? AND ? AND {$notBroughtForward}", [$range['from'], $range['to']]),
                ] + $meta;
            }

            // A year kept in the legacy system: its own opening balances and its months.
            $inYear = 'p.starts_on >= ? AND p.ends_on <= ?';
            $position = $this->legacy("{$inYear}", [$year['starts_on'], $range['to']]);
            $yearIe = $this->legacy("{$ie} AND b.kind = 'movement' AND {$inYear}", [$year['starts_on'], $range['to']]);

            return [
                'position'  => $position,
                'trial'     => $position,
                'yearIe'    => $yearIe,
                'earlierIe' => [],
                'activity'  => $this->legacy("{$ie} AND b.kind = 'movement' AND {$inYear}", [$range['from'], $range['to']]),
                'start'     => $this->legacy("p.starts_on >= ? AND (b.kind = 'opening' OR p.ends_on < ?)", [$year['starts_on'], $range['from']]),
                'flow'      => $this->legacy("b.kind = 'movement' AND {$inYear}", [$range['from'], $range['to']]),
            ] + $meta;
        });
    }

    /** @return array<string, array{u: float, r: float}> */
    private function ledger(string $where, array $binds): array
    {
        return $this->grouped($this->rows(
            "SELECT a.code, f.restriction, SUM(l.debit) AS dr, SUM(l.credit) AS cr
             FROM {journal_lines} l
             JOIN {journals} j ON j.id = l.journal_id
             JOIN {accounts} a ON a.id = l.account_id
             JOIN {funds} f ON f.id = l.fund_id
             WHERE j.status IN ('posted', 'reversed') AND {$where}
             GROUP BY a.code, f.restriction",
            $binds
        ));
    }

    /** @return array<string, array{u: float, r: float}> */
    private function legacy(string $where, array $binds): array
    {
        return $this->grouped($this->rows(
            "SELECT a.code, f.restriction, SUM(b.debit) AS dr, SUM(b.credit) AS cr
             FROM {legacy_balances} b
             JOIN {periods} p ON p.id = b.period_id
             JOIN {accounts} a ON a.id = b.account_id
             JOIN {funds} f ON f.id = b.fund_id
             WHERE {$where}
             GROUP BY a.code, f.restriction",
            $binds
        ));
    }

    private function grouped(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $class = in_array($r['restriction'], self::RESTRICTED, true) ? 'r' : 'u';
            $out[$r['code']] ??= ['u' => 0.0, 'r' => 0.0];
            $out[$r['code']][$class] += (float) $r['dr'] - (float) $r['cr'];
        }

        return $out;
    }

    /**
     * The approved budget phased to the period's months, by account, as the statement
     * of activities presents it (income a credit, expenditure a debit).
     */
    private function budget(array $range): array
    {
        $year = $this->yearOf($range['from']);
        $version = $this->row(
            "SELECT bv.name FROM {budget_versions} bv JOIN {fiscal_years} fy ON fy.id = bv.fiscal_year_id
             WHERE bv.status = 'approved' AND fy.starts_on = ? ORDER BY bv.id DESC LIMIT 1",
            [$year['starts_on'] ?? '']
        );
        $amounts = [];
        foreach ($version === null ? [] : $this->rows(
            "SELECT a.code, SUM(ph.amount) AS amount
             FROM {budget_phases} ph
             JOIN {budget_lines} bl ON bl.id = ph.budget_line_id
             JOIN {budget_versions} bv ON bv.id = bl.budget_version_id
             JOIN {periods} p ON p.id = ph.period_id
             JOIN {accounts} a ON a.id = bl.account_id
             WHERE bv.status = 'approved' AND a.type IN ('income', 'expense') AND p.starts_on >= ? AND p.ends_on <= ?
             GROUP BY a.code",
            [$range['from'], $range['to']]
        ) as $r) {
            $amounts[$r['code']] = (float) $r['amount'];
        }
        $chart = $this->chart();

        return [
            'label'      => 'Approved budget',
            'year'       => $year['code'] ?? 'this year',
            'version'    => $version['name'] ?? '',
            'held'       => $version !== null,
            'incomeHeld' => array_filter(array_keys($amounts), static fn ($c) => ($chart[$c]['type'] ?? '') === 'Income') !== [],
            'amounts'    => $amounts,
        ];
    }

    /** Live awards' indirect-cost ceilings, e.g. "8% (DANIDA)". */
    private function indirectCaps(): array
    {
        return array_map(
            static fn ($g) => rtrim(rtrim(number_format((float) $g['indirect_cap_pct'], 1), '0'), '.') . '% (' . $g['funder'] . ')',
            $this->rows(
                "SELECT g.indirect_cap_pct, COALESCE(f.name, g.short_name) AS funder FROM {grants} g LEFT JOIN {funders} f ON f.id = g.funder_id
                 WHERE g.status = 'active' AND g.indirect_cap_pct IS NOT NULL ORDER BY g.indirect_cap_pct"
            )
        );
    }

    // ------------------------------------------------------------------
    // Chart and calendar
    // ------------------------------------------------------------------

    /**
     * Postable accounts in code order, with the level-1 group each sits under.
     *
     * @return array<string, array{code: string, name: string, type: string, status: string, group: string, leaf: bool, contra: bool, nonCurrent: bool}>
     */
    private function chart(): array
    {
        return $this->cached('report-chart', function () {
            $out = [];
            foreach ($this->rows(
                'SELECT a.code, a.name, a.type, a.level, a.status, a.is_leaf, p.code AS parent_code, gp.code AS grand_code
                 FROM {accounts} a LEFT JOIN {accounts} p ON p.id = a.parent_id LEFT JOIN {accounts} gp ON gp.id = p.parent_id
                 ORDER BY a.code'
            ) as $a) {
                $type = ucfirst($a['type']);
                $group = (int) $a['level'] === 1 ? $a['code'] : ((int) $a['level'] === 2 ? (string) $a['parent_code'] : (string) ($a['grand_code'] ?? $a['parent_code']));
                $out[$a['code']] = [
                    'code' => $a['code'], 'name' => $a['name'], 'type' => $type, 'status' => $a['status'], 'group' => $group,
                    'leaf' => (bool) $a['is_leaf'],
                    'contra' => $type === 'Asset' && ChartRepository::normal(['type' => $type, 'name' => $a['name']]) === 'Credit',
                    'nonCurrent' => in_array($group, Ledger::NON_CURRENT_ASSET_GROUPS, true),
                ];
            }

            return $out;
        });
    }

    private function groupName(string $group): string
    {
        return $this->chart()[$group]['name'] ?? $group;
    }

    /**
     * The postable accounts a statement lists: every active one of the types it
     * shows, and an archived one while any column carries a figure on it.
     *
     * @param list<array> $columns figures
     * @param array<string, float> $extra further figures by code (a budget)
     */
    private function leaves(array $columns, string $set, array $extra = []): array
    {
        $types = match ($set) {
            'position' => ['Asset', 'Liability', 'Equity'],
            'activity' => ['Income', 'Expense'],
            default    => ['Asset', 'Liability', 'Equity', 'Income', 'Expense'],
        };
        $carries = static function (string $code) use ($columns, $set, $extra) {
            foreach ($columns as $f) {
                $m = $f[$set][$code] ?? null;
                if ($m !== null && (round($m['u'], 2) != 0 || round($m['r'], 2) != 0)) {
                    return true;
                }
            }

            return round($extra[$code] ?? 0, 2) != 0;
        };

        return array_filter($this->chart(), static fn ($a) => $a['leaf'] && in_array($a['type'], $types, true)
            && ($set !== 'trial' || $a['code'] !== ChartRepository::DERIVED_SURPLUS)
            && ($a['status'] === 'active' || $carries($a['code'])));
    }

    /** @return list<array> the entity's financial years, earliest first */
    private function years(): array
    {
        return $this->cached('report-years', fn () => $this->rows(
            'SELECT id, code, starts_on, ends_on, status FROM {fiscal_years} WHERE entity_id = ? ORDER BY starts_on',
            [$this->lookups->entityId()]
        ));
    }

    private function yearOf(string $date): ?array
    {
        foreach ($this->years() as $y) {
            if ($y['starts_on'] <= $date && $date <= $y['ends_on']) {
                return $y;
            }
        }

        return null;
    }

    /** Where a year's figures are read from: the ledger when anything is posted in it, else the legacy system's, else nowhere. */
    private function source(array $year): ?string
    {
        return $this->cached('source:' . $year['starts_on'], function () use ($year) {
            if ($this->value("SELECT 1 FROM {journals} j WHERE j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN ? AND ? LIMIT 1", [$year['starts_on'], $year['ends_on']]) !== null) {
                return 'ledger';
            }
            if ($this->value('SELECT 1 FROM {legacy_balances} b JOIN {periods} p ON p.id = b.period_id WHERE p.starts_on >= ? AND p.ends_on <= ? LIMIT 1', [$year['starts_on'], $year['ends_on']]) !== null) {
                return 'legacy';
            }

            return null;
        });
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    private function valueColumns(array $now, ?array $then, ?array $budget): array
    {
        return [$now['range']['label'], ...($then === null ? [] : ['Prior year']), ...($budget === null ? [] : ['Approved budget'])];
    }

    /**
     * A statement line. The first cell is this period, the last the comparative
     * (muted); a subtotal or total is set in weight, a total's own column in accent.
     *
     * @param list<float|null> $values
     */
    private function statementRow(string $label, array $values, string $kind, ?string $code = null, bool $drill = true, bool $split = false): array
    {
        // Split, the third column is the total; otherwise the first is. A comparative, when there is one, comes last.
        $totalColumn = $split ? 2 : 0;
        $compareColumn = count($values) > $totalColumn + 1 ? count($values) - 1 : null;
        $cells = [];
        foreach ($values as $i => $v) {
            $cell = ['v' => $v === null ? null : round((float) $v, 2)];
            if ($i === $compareColumn) {
                $cell += ['muted' => true] + ($kind === 'line' ? [] : ['semi' => true]);
            } elseif ($kind === 'total' || ($kind === 'sub' && $split)) {
                $cell += $i === $totalColumn ? ['bold' => true, 'accent' => true] : ['semi' => true];
            } elseif ($kind === 'sub') {
                $cell += ['bold' => true];
            } elseif ($split && $i === $totalColumn) {
                $cell += ['semi' => true];
            }
            $cells[] = $cell;
        }

        return ['label' => $label, 'code' => $code, 'kind' => $kind, 'cells' => $cells, 'drill' => $drill && $kind === 'line' ? $code : null];
    }

    /** A debit-positive figure as a statement presents it: debit-side accounts as debits, the rest as credits. */
    private static function presented(string $type, ?array $m): float
    {
        $v = $m === null ? 0.0 : $m['u'] + $m['r'];

        return in_array($type, ['Asset', 'Expense'], true) ? $v : -$v;
    }

    /** Income less expenditure, from debit-positive income and expenditure figures. */
    private static function surplusOf(array $ie, string $class = 'all'): float
    {
        $t = 0.0;
        foreach ($ie as $m) {
            $t -= $class === 'all' ? $m['u'] + $m['r'] : $m[$class];
        }

        return $t;
    }

    private static function minus(array $a, array $b): array
    {
        foreach ($b as $code => $m) {
            $a[$code] ??= ['u' => 0.0, 'r' => 0.0];
            $a[$code]['u'] -= $m['u'];
            $a[$code]['r'] -= $m['r'];
        }

        return $a;
    }

    private static function money(float $n): string
    {
        $s = number_format(abs(round($n)));

        return $n < 0 ? '(' . $s . ')' : $s;
    }
}
