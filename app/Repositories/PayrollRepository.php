<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Payroll;
use App\Libraries\Prototype;

/**
 * The payroll register, and one run a month against it.
 *
 * The register is effective-dated throughout: a pay award, a re-allocation
 * across grants, a joiner and a leaver all carry the run they take effect from,
 * so the roster for March is what March was, not what the register says today.
 * A run is prepared, approved by someone other than its preparer, posted to the
 * ledger, and the 22xx liabilities it raises are cleared by a second journal
 * when they are remitted.
 *
 * This is personal data (Kenya Data Protection Act 2019): every read of the
 * register is logged against the reader, and identifiers (KRA PIN, NSSF, bank
 * account) are never read from their encrypted columns here.
 */
final class PayrollRepository extends Repository
{
    private const COMPONENT_FIELDS = ['basic_salary' => 'basic', 'acting_allowance' => 'acting',
        'sacco' => 'sacco', 'advance_recovery' => 'advance'];

    /** Net pay leaves the operating current account. */

    /** The pay components a run's journal is written against; each needs an account. */
    public const POSTING_COMPONENTS = ['basic_salary', 'nssf_employer', 'paye', 'nssf_employee', 'shif',
        'housing_levy_employee', 'sacco', 'advance_recovery'];

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'posted'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    // ---- What the run is calculated with ----

    /** The benefits on offer, in payslip order. Settings decides which are still live. */
    public function benefits(): array
    {
        return $this->cached('benefits', fn () => array_map(static fn ($c) => [
            'key' => $c['key'], 'name' => $c['name'], 'taxable' => (bool) $c['is_taxable'], 'basis' => $c['basis'],
        ], $this->rows('SELECT * FROM {pay_components} WHERE is_benefit = 1 AND is_active = 1 ORDER BY id')));
    }

    /** Every pay component by key, with the GL code it charges. */
    public function components(): array
    {
        return $this->cached('components', fn () => array_column(array_map(static fn ($c) => [
            'id' => (int) $c['id'], 'key' => $c['key'], 'name' => $c['name'], 'kind' => $c['kind'], 'code' => $c['code'],
        ], $this->rows('SELECT c.*, a.code FROM {pay_components} c LEFT JOIN {accounts} a ON a.id = c.account_id')), null, 'key'));
    }

    /** The grade scale, with what each benefit is worth at each grade. */
    public function grades(): array
    {
        return $this->cached('grades', function () {
            $ben = [];
            foreach ($this->rows('SELECT gb.pay_grade_id, gb.amount, c.key FROM {pay_grade_benefits} gb JOIN {pay_components} c ON c.id = gb.pay_component_id') as $b) {
                $ben[(int) $b['pay_grade_id']][$b['key']] = self::num($b['amount']);
            }

            return array_map(static fn ($g) => [
                'code' => $g['code'], 'title' => $g['title'], 'ben' => $ben[(int) $g['id']] ?? [], 'active' => (bool) $g['is_active'],
            ], $this->rows('SELECT * FROM {pay_grades} ORDER BY sort_order, id'));
        });
    }

    public function engine(): Payroll
    {
        $schemes = ['paye', 'personal_relief', 'nssf', 'shif', 'housing_levy', 'nita'];

        return new Payroll(array_combine($schemes, array_map($this->rates(...), $schemes)), $this->benefits());
    }

    /**
     * Rates in force today for one scheme, lowest band first.
     *
     * @return list<array{lower: float, upper: ?float, pct: float, fixed: ?float}>
     */
    public function rates(string $scheme): array
    {
        return $this->cached("rates:{$scheme}", fn () => array_map(static fn ($r) => [
            'lower' => (float) $r['lower_bound'], 'upper' => $r['upper_bound'] === null ? null : (float) $r['upper_bound'],
            'pct' => (float) $r['rate_pct'], 'fixed' => $r['fixed_amount'] === null ? null : (float) $r['fixed_amount'],
        ], $this->rows(
            'SELECT * FROM {statutory_rates} WHERE scheme = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY band_order',
            [$scheme, Clock::date(), Clock::date()]
        )));
    }

    // ---- The runs the screen moves between ----

    /**
     * The months a run can be looked at: the working year to date. There is no
     * run for a month that has not happened yet.
     *
     * @return list<array>
     */
    public function periods(): array
    {
        return $this->cached('run-periods', fn () => array_values(array_filter(
            $this->lookups->yearPeriods(),
            static fn ($p) => $p['starts_on'] <= Clock::date()
        )));
    }

    public function periodNamed(?string $name): ?array
    {
        $periods = $this->periods();
        foreach ($periods as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }

        return end($periods) ?: null;
    }

    /** The run recorded against a period, or null where none has been prepared. */
    public function runFor(int $periodId): ?array
    {
        return $this->runs()[$periodId] ?? null;
    }

    /** @return array<int, array> runs by period id */
    public function runs(): array
    {
        return $this->cached('runs', fn () => array_column($this->rows(
            'SELECT r.*, j.reference AS journal_ref, rj.reference AS remittance_ref FROM {payroll_runs} r
             LEFT JOIN {journals} j ON j.id = r.journal_id LEFT JOIN {journals} rj ON rj.id = r.remittance_journal_id
             WHERE r.entity_id = ?',
            [$this->lookups->entityId()]
        ), null, 'period_id'));
    }

    // ---- The roster, as it stood in one month ----

    /**
     * Everyone on the run for $period, with the pay and allocation in force that
     * month and what moved since the run before it.
     *
     * @return list<array>
     */
    public function rosterFor(array $period): array
    {
        return $this->cached('roster:' . $period['id'], function () use ($period) {
            [$from, $to] = [$period['starts_on'], $period['ends_on']];
            $inForce = 'effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)';

            $pay = [];
            foreach ($this->rows(
                "SELECT i.staff_id, c.key, i.amount, i.effective_from FROM {staff_pay_items} i JOIN {pay_components} c ON c.id = i.pay_component_id
                 WHERE {$inForce} ORDER BY i.effective_from, i.id",
                [$to, $from]
            ) as $i) {
                $pay[(int) $i['staff_id']][$i['key']] = ['amount' => self::num($i['amount']), 'from' => $i['effective_from']];
            }

            $alloc = [];
            foreach ($this->rows(
                "SELECT a.*, f.ledger_group, g.award_ref FROM {staff_allocations} a JOIN {funds} f ON f.id = a.fund_id LEFT JOIN {grants} g ON g.id = a.grant_id
                 WHERE {$inForce} ORDER BY a.effective_from, a.id",
                [$to, $from]
            ) as $a) {
                $alloc[(int) $a['staff_id']][] = [
                    'fund' => self::FUND_GROUPS[$a['ledger_group']], 'fundId' => (int) $a['fund_id'],
                    'grant' => $a['award_ref'] ?? 'Unassigned', 'grantId' => $a['grant_id'] === null ? null : (int) $a['grant_id'],
                    'program' => $this->lookups->programmeName((int) $a['programme_id']), 'programmeId' => (int) $a['programme_id'],
                    'pct' => self::num($a['allocation_pct']), 'from' => $a['effective_from'],
                ];
            }

            $benefitKeys = array_column($this->benefits(), 'key');
            $monthDays   = (int) date('t', strtotime($from));
            $started     = static fn (?string $date) => $date !== null && $from <= $date && $date <= $to;

            return array_values(array_map(function ($s) use ($pay, $alloc, $benefitKeys, $monthDays, $started, $from, $to) {
                $id    = (int) $s['id'];
                $items = $pay[$id] ?? [];
                $lines = $alloc[$id] ?? [];

                $row = ['id' => $id, 'no' => $s['staff_no'], 'name' => $s['name'], 'role' => $s['job_title'], 'grade' => $s['grade'],
                    'joined' => self::dmy($s['joined_on']), 'joinedOn' => $s['joined_on'], 'bank' => $s['bank_name'],
                    'bankLast4' => $s['bank_account_last4'], 'alloc' => $lines];

                foreach (self::COMPONENT_FIELDS as $key => $field) {
                    $row[$field] = $items[$key]['amount'] ?? 0;
                }
                $row['ben'] = array_combine($benefitKeys, array_map(static fn ($k) => $items[$k]['amount'] ?? 0, $benefitKeys));

                // A leaver is paid for the days worked in the month they go; a
                // joiner's first month is paid in full.
                $row['isNew']  = $started($s['joined_on']);
                $row['leaves'] = $started($s['left_on']) ? self::dmy($s['left_on']) : null;
                $row['days']      = $row['leaves'] === null ? 0 : (int) date('j', strtotime($s['left_on']));
                $row['monthDays'] = $monthDays;

                $changedIn = static fn (array $rows) => array_filter($rows, static fn ($r) => $from <= $r['from'] && $r['from'] <= $to) !== [];
                $row['payChanged'] = !$row['isNew'] && $changedIn(array_values($items));
                $row['allocChanged'] = !$row['isNew'] && $changedIn($lines);
                $row['actingFrom'] = isset($items['acting_allowance']) ? date('M Y', strtotime($items['acting_allowance']['from'])) : null;

                return $row;
            }, $this->rows(
                'SELECT * FROM {staff} WHERE joined_on <= ? AND (left_on IS NULL OR left_on >= ?) ORDER BY staff_no',
                [$to, $from]
            )));
        });
    }

    /** Everyone ever on the register, for the staff editor's list. */
    public function staffList(): array
    {
        return $this->cached('staff-list', fn () => array_map(static fn ($s) => [
            'no' => $s['staff_no'], 'name' => $s['name'], 'role' => $s['job_title'], 'grade' => $s['grade'],
            'left' => $s['left_on'],
        ], $this->rows('SELECT * FROM {staff} ORDER BY staff_no')));
    }

    /**
     * What each person on the register is paid and charged to as things stand.
     *
     * The staff editor opens on these, so a pay award starts from the current
     * figures rather than a blank form — a blank benefit would otherwise fall
     * back to the grade's own award and quietly undo a personal figure.
     *
     * @return array<string, array{grade: string, basic: float, ben: array<string, float>, alloc: list<array>}>
     */
    public function currentPay(): array
    {
        return $this->cached('current-pay', function () {
            $periods = $this->periods();
            $latest  = end($periods) ?: null;
            if ($latest === null) {
                return [];
            }

            $out = [];
            foreach ($this->rosterFor($latest) as $s) {
                $out[$s['no']] = [
                    'grade' => $s['grade'], 'basic' => $s['basic'], 'ben' => $s['ben'],
                    'alloc' => array_map(static fn ($a) => ['grant' => $a['grant'], 'program' => $a['program'], 'pct' => $a['pct']], $s['alloc']),
                ];
            }

            return $out;
        });
    }

    /**
     * The banks staff are already paid through. A suggestion, not a closed list —
     * a new appointment may bank anywhere, and the register is the only place
     * that knows which banks are in use.
     *
     * @return list<string>
     */
    public function banks(): array
    {
        return $this->cached('banks', fn () => array_column(
            $this->rows('SELECT DISTINCT bank_name FROM {staff} WHERE bank_name IS NOT NULL AND bank_name <> :empty: ORDER BY bank_name', ['empty' => '']),
            'bank_name'
        ));
    }

    /**
     * The awards a payroll cost may be charged to: the ones open to posting, and
     * any an existing split already carries so it can be saved again.
     *
     * @return list<string>
     */
    public function chargeableAwards(): array
    {
        $open = array_column(array_filter(
            $this->lookups->grants(),
            static fn ($g) => in_array($g['status'], ['active', 'closing'], true),
        ), 'award_ref');

        $onRoster = [];
        foreach ($this->periods() as $p) {
            foreach ($this->rosterFor($p) as $s) {
                $onRoster = array_merge($onRoster, array_column($s['alloc'], 'grant'));
            }
        }

        $refs = array_values(array_unique(array_filter(array_merge($open, $onRoster), static fn ($r) => $r !== 'Unassigned')));
        sort($refs);

        return array_merge(['Unassigned'], $refs);
    }

    /** Records that a user viewed the payroll register. */
    public function logView(int $userId, string $ip, string $userAgent, string $action = 'view', string $objectType = 'staff'): void
    {
        $this->insert('personal_data_access_log', [
            'user_id' => $userId, 'action' => $action, 'object_type' => $objectType, 'object_id' => null,
            'ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($userAgent, 0, 255), 'accessed_at' => Clock::timestamp(),
        ]);
    }

    // ---- Preparing, approving and posting a run ----

    /**
     * The charge one run puts on the ledger, grouped the way it is posted: gross
     * and the employer's own contributions per fund, grant and programme, and the
     * statutory liabilities it raises against them.
     *
     * @return array{lines: list<array>, totals: array, groups: list<array>}
     */
    public function runView(array $period): array
    {
        $run = $this->runFor((int) $period['id']);

        // A posted run is history: it shows what it paid, from the payslips it
        // wrote and the journal it posted, not what the register would pay now.
        return ($run['status'] ?? '') === 'posted' ? $this->posted($period, $run) : $this->draft($period);
    }

    /**
     * What a posted run actually paid.
     *
     * @return array{lines: list<array>, totals: array, groups: list<array>}
     */
    private function posted(array $period, array $run): array
    {
        $byKey = array_column($this->components(), 'key', 'id');
        $lines = [];
        foreach ($this->rows('SELECT * FROM {payslip_lines} WHERE payslip_id IN (SELECT id FROM {payslips} WHERE payroll_run_id = ?)', [$run['id']]) as $l) {
            $lines[(int) $l['payslip_id']][$byKey[(int) $l['pay_component_id']] ?? ''] = self::num($l['amount']);
        }

        $charged = [];
        foreach ($this->rows(
            'SELECT a.*, f.ledger_group, g.award_ref FROM {payslip_allocations} a JOIN {funds} f ON f.id = a.fund_id
             LEFT JOIN {grants} g ON g.id = a.grant_id WHERE a.payslip_id IN (SELECT id FROM {payslips} WHERE payroll_run_id = ?) ORDER BY a.id',
            [$run['id']]
        ) as $a) {
            $charged[(int) $a['payslip_id']][] = [
                'fund' => self::FUND_GROUPS[$a['ledger_group']], 'fundId' => (int) $a['fund_id'],
                'grant' => $a['award_ref'] ?? 'Unassigned', 'grantId' => $a['grant_id'] === null ? null : (int) $a['grant_id'],
                'program' => $this->lookups->programmeName((int) $a['programme_id']), 'programmeId' => (int) $a['programme_id'],
                'pct' => self::num($a['allocation_pct']), 'gross' => self::num($a['gross']), 'employer' => self::num($a['employer_cost']),
            ];
        }

        $benefits = $this->benefits();
        $roster   = [];
        $payslips = [];
        $groups   = [];

        foreach ($this->rows(
            'SELECT p.*, s.staff_no, s.name, s.job_title, s.grade, s.joined_on, s.left_on, s.bank_name, s.bank_account_last4
             FROM {payslips} p JOIN {staff} s ON s.id = p.staff_id WHERE p.payroll_run_id = ? ORDER BY s.staff_no',
            [$run['id']]
        ) as $p) {
            $id    = (int) $p['id'];
            $of    = static fn (string $key) => $lines[$id][$key] ?? 0;
            $alloc = $charged[$id] ?? [];

            $roster[] = [
                'id' => (int) $p['staff_id'], 'no' => $p['staff_no'], 'name' => $p['name'], 'role' => $p['job_title'],
                'grade' => $p['grade'], 'joined' => self::dmy($p['joined_on']), 'joinedOn' => $p['joined_on'],
                'bank' => $p['bank_name'], 'bankLast4' => $p['bank_account_last4'], 'alloc' => $alloc,
                'basic' => $of('basic_salary'), 'acting' => $of('acting_allowance'),
                'sacco' => $of('sacco'), 'advance' => $of('advance_recovery'),
                'ben' => array_combine(array_column($benefits, 'key'), array_map($of, array_column($benefits, 'key'))),
                'isNew' => $p['joined_on'] >= $period['starts_on'] && $p['joined_on'] <= $period['ends_on'],
                'leaves' => $p['left_on'] !== null && $p['left_on'] <= $period['ends_on'] ? self::dmy($p['left_on']) : null,
                'days' => (int) $p['days_payable'] < (int) $p['days_in_month'] ? (int) $p['days_payable'] : 0,
                'monthDays' => (int) $p['days_in_month'],
                'payChanged' => false, 'allocChanged' => false, 'actingFrom' => null,
            ];

            $employer = ['nssf' => $of('nssf_employer'), 'housingLevy' => $of('housing_levy_employer'), 'nita' => $of('nita')];
            $payslips[] = [
                'basic' => $of('basic_salary'), 'acting' => $of('acting_allowance'),
                'benefits' => array_map(static fn ($b) => $b + ['amount' => $lines[$id][$b['key']] ?? 0], $benefits),
                'gross' => self::num($p['gross']), 'taxable' => self::num($p['taxable_pay']),
                'paye' => self::num($p['paye']), 'nssf' => self::num($p['nssf']), 'shif' => self::num($p['shif']),
                'housingLevy' => self::num($p['housing_levy']), 'sacco' => $of('sacco'), 'advance' => $of('advance_recovery'),
                'deductions' => self::num($p['gross']) - self::num($p['net_pay']), 'net' => self::num($p['net_pay']),
                'employer' => $employer, 'employerCost' => self::num($p['employer_cost']),
                'cost' => self::num($p['gross']) + self::num($p['employer_cost']),
            ];

            foreach ($alloc as $a) {
                $key = $a['fundId'] . '|' . $a['programmeId'] . '|' . ($a['grantId'] ?? 0);
                $groups[$key] ??= $a + ['gross' => 0.0, 'employer' => 0.0, 'heads' => []];
                $groups[$key]['gross']    += $a['gross'];
                $groups[$key]['employer'] += $a['employer'];
                $groups[$key]['heads'][$p['staff_no']] = true;
            }
        }
        usort($groups, static fn ($a, $b) => $b['gross'] <=> $a['gross']);

        return [
            'lines' => $this->journalLines((int) $run['journal_id']),
            'totals' => $this->engine()->totals($payslips) + ['nita' => array_sum(array_map(static fn ($p) => $p['employer']['nita'], $payslips))],
            'groups' => array_values($groups), 'payslips' => $payslips, 'roster' => $roster,
        ];
    }

    /** The entry a posted run put on the ledger, as it stands there. */
    private function journalLines(int $journalId): array
    {
        return array_map(static fn ($l) => [
            'code' => $l['code'], 'fund_id' => (int) $l['fund_id'], 'programme_id' => (int) $l['programme_id'],
            'grant_id' => $l['grant_id'] === null ? null : (int) $l['grant_id'],
            'desc' => $l['description'], 'dr' => self::num($l['debit']), 'cr' => self::num($l['credit']),
        ], $this->rows(
            'SELECT l.*, a.code FROM {journal_lines} l JOIN {accounts} a ON a.id = l.account_id WHERE l.journal_id = ? ORDER BY l.line_no',
            [$journalId]
        ));
    }

    /**
     * What the run would put on the ledger if it were posted now.
     *
     * @return array{lines: list<array>, totals: array, groups: list<array>}
     */
    public function draft(array $period): array
    {
        $engine   = $this->engine();
        $roster   = $this->rosterFor($period);
        $payslips = array_map($engine->payslip(...), $roster);
        $totals   = $engine->totals($payslips);

        // A newly installed instance has a payroll calculation but nowhere to post it:
        // the chart is imported after the reference data, and the accounts each pay
        // component posts to are set in Settings afterwards. The run is still worked
        // out and shown — what is missing is reported rather than thrown, so the
        // screen can say what to set instead of failing to draw.
        $unmapped = $this->unmapped();
        if ($unmapped !== []) {
            return ['lines' => [], 'unmapped' => $unmapped, 'totals' => $totals, 'groups' => [],
                'payslips' => $payslips, 'roster' => $roster];
        }

        $codeOf = fn (string $key) => $this->components()[$key]['code'];

        $groups = [];
        foreach ($roster as $i => $s) {
            $grossShare    = Payroll::apportion($payslips[$i]['gross'], $s['alloc']);
            $employerShare = Payroll::apportion($payslips[$i]['employerCost'], $s['alloc']);
            foreach ($s['alloc'] as $n => $a) {
                $key = $a['fundId'] . '|' . $a['programmeId'] . '|' . ($a['grantId'] ?? 0);
                $groups[$key] ??= $a + ['gross' => 0.0, 'employer' => 0.0, 'heads' => []];
                $groups[$key]['gross']    += $grossShare[$n];
                $groups[$key]['employer'] += $employerShare[$n];
                $groups[$key]['heads'][$s['no']] = true;
            }
        }
        usort($groups, static fn ($a, $b) => $b['gross'] <=> $a['gross']);

        $shared = ['fund_id' => $this->coreFundId(), 'programme_id' => $this->sharedProgrammeId(), 'grant_id' => null];
        $line   = static fn (array $at, string $code, string $desc, float $dr, float $cr) => [
            'code' => $code, 'fund_id' => $at['fund_id'] ?? $at['fundId'] ?? null, 'programme_id' => $at['programme_id'] ?? $at['programmeId'] ?? null,
            'grant_id' => $at['grant_id'] ?? $at['grantId'] ?? null, 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
        $against = static fn (array $g) => $g['grant'] === 'Unassigned' ? 'core funded' : $g['grant'];

        $lines = array_merge(
            array_map(static fn ($g) => $line($g, $codeOf('basic_salary'), 'Gross salaries — ' . $against($g), $g['gross'], 0), $groups),
            array_map(static fn ($g) => $line($g, $codeOf('nssf_employer'), 'Employer statutory contributions — ' . $against($g), $g['employer'], 0), $groups),
            [
                $line($shared, $codeOf('paye'), 'PAYE withheld', 0, $totals['paye']),
                $line($shared, $codeOf('nssf_employee'), 'NSSF — employee and employer', 0, $totals['nssf'] * 2),
                $line($shared, $codeOf('shif'), 'SHIF withheld', 0, $totals['shif']),
                $line($shared, $codeOf('housing_levy_employee'), 'Housing levy and NITA', 0, $totals['housingLevy'] * 2 + $totals['nita']),
                $line($shared, $codeOf('sacco'), 'Staff sacco deductions', 0, $totals['sacco']),
                $line($shared, $codeOf('advance_recovery'), 'Salary advances recovered', 0, $totals['advance']),
                $line($shared, PostingAccounts::of('payrollBank'), 'Net pay to staff bank accounts', 0, $totals['net']),
            ],
        );

        return ['lines' => array_values(array_filter($lines, static fn ($l) => $l['dr'] > 0 || $l['cr'] > 0)),
            'unmapped' => [], 'totals' => $totals, 'groups' => $groups, 'payslips' => $payslips, 'roster' => $roster];
    }

    /**
     * What payroll still needs before a run can be written, each in its own words.
     * Empty once the chart carries everything a run posts to.
     *
     * @return list<string>
     */
    public function unmapped(): array
    {
        return $this->cached('unmapped', function () {
            $components = $this->components();
            $missing = [];

            foreach (self::POSTING_COMPONENTS as $key) {
                if (($components[$key]['code'] ?? null) === null) {
                    $missing[] = ($components[$key]['name'] ?? $key) . ' has no account to post to';
                }
            }
            if (($this->lookups->accounts()[PostingAccounts::of('payrollBank')] ?? null) === null) {
                $missing[] = 'Net pay is paid from account ' . PostingAccounts::of('payrollBank') . ', which is not in the chart of accounts';
            }
            if ($this->coreFundId() === 0) {
                $missing[] = 'There is no active general fund for the statutory liabilities to be held against';
            }
            if ($this->sharedProgrammeId() === null) {
                $missing[] = 'There is no programme for the statutory liabilities to be charged to';
            }

            return $missing;
        });
    }

    /** Refuses a write while payroll has nowhere to post, naming the first thing to set. */
    private function assertPostable(): void
    {
        $missing = $this->unmapped();
        if ($missing !== []) {
            throw new RuleViolation('Payroll cannot post yet: ' . lcfirst($missing[0]) . '. Set the accounts each pay component posts to in Settings → Payroll.');
        }
    }

    /** Sends the run to the approver. Nothing reaches the ledger until it comes back. */
    public function submit(array $period, int $actorId): array
    {
        $run = $this->runFor((int) $period['id']);
        if ($run !== null && $run['status'] !== 'draft') {
            throw new RuleViolation('The ' . $period['name'] . ' run is ' . self::label($run['status']) . ', not a draft.');
        }
        $this->assertPostable();
        $draft = $this->draft($period);
        if ($draft['totals']['staff'] === 0) {
            throw new RuleViolation('There is no one on the register for ' . $period['name'] . ', so there is no run to approve.');
        }

        return $this->write($period, $draft, $run, ['status' => 'pending_approval', 'submitted_at' => Clock::timestamp(), 'prepared_by' => $actorId],
            'Prepared and sent for approval by ' . $this->lookups->shortName($actorId), $actorId);
    }

    /**
     * Approval by someone other than the preparer, and within the role's authority
     * for a payroll run.
     */
    public function approve(array $period, int $actorId, ?string $authorityRef = null): array
    {
        $run = $this->runFor((int) $period['id']);
        if ($run === null || $run['status'] !== 'pending_approval') {
            throw new RuleViolation('There is no ' . $period['name'] . ' run waiting for approval.');
        }
        if ((int) $run['prepared_by'] === $actorId) {
            throw new RuleViolation('The person who prepared a payroll run cannot approve it. It needs a second approver.');
        }
        $cost = (float) $run['gross'] + (float) $run['employer_cost'];
        (new ApprovalPolicy())->check('payroll_run', $cost, $actorId, $run['reference'] ?? $period['name'], $authorityRef);

        $this->transaction(function () use ($run, $actorId, $period) {
            $this->db->table('payroll_runs')->where('id', $run['id'])->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('payroll_run', (int) $run['id'], $run['reference'], 'Approved by ' . $this->lookups->shortName($actorId)
                . ' — the ' . $period['name'] . ' run can now be posted', $actorId, 'history');
        });

        return $this->reread($period);
    }

    /** Posts the approved run: net pay leaves the bank and the 22xx liabilities are raised. */
    public function post(array $period, int $actorId): array
    {
        $run = $this->runFor((int) $period['id']);
        if ($run === null || $run['status'] !== 'approved') {
            throw new RuleViolation('Only an approved run can be posted. The ' . $period['name'] . ' run is '
                . ($run === null ? 'not yet prepared' : self::label($run['status'])) . '.');
        }
        $draft = $this->draft($period);

        $this->transaction(function () use ($run, $draft, $period, $actorId) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => $period['ends_on'], 'sourceType' => 'payroll_run', 'sourceId' => (int) $run['id'],
                'docRef' => $run['reference'], 'series' => 'PR',
                'narration' => $period['name'] . ' payroll — ' . $draft['totals']['staff'] . ' staff',
                'memo' => 'Net pay released and PAYE, NSSF, SHIF, the housing levy and NITA raised as liabilities.',
            ], $draft['lines'], (int) $run['prepared_by'], (int) $run['approved_by'], 'Raised by payroll on posting the ' . $period['name'] . ' run');

            $this->db->table('payroll_runs')->where('id', $run['id'])->update([
                'status' => 'posted', 'posted_at' => Clock::timestamp(), 'journal_id' => $this->journalId($journal),
                'updated_at' => Clock::timestamp(),
            ]);
            $this->writePayslips((int) $run['id'], $draft);
            $this->audit('payroll_run', (int) $run['id'], $run['reference'], 'Posted as ' . $journal . ' by '
                . $this->lookups->shortName($actorId) . ' — ' . Prototype::fmt($draft['totals']['net']) . ' net pay released', $actorId);
        });

        return $this->reread($period);
    }

    /** Payroll raises the 22xx liabilities; remitting them is what clears them. */
    public function remit(array $period, int $actorId): array
    {
        $run = $this->runFor((int) $period['id']);
        if ($run === null || $run['status'] !== 'posted') {
            throw new RuleViolation('Post the ' . $period['name'] . ' payroll first — there is no liability to remit until it is in the ledger.');
        }
        if ($run['remitted_at'] !== null) {
            throw new RuleViolation($period['name'] . ' statutory deductions have already been remitted on ' . $run['remittance_ref'] . '.');
        }

        $due    = $this->remittanceDue($period);
        $totals = $this->statutory($period);
        $codeOf = fn (string $key) => $this->components()[$key]['code'];
        $at     = ['fund_id' => $this->coreFundId(), 'programme_id' => $this->lookups->programmeId('Shared services'), 'grant_id' => null];
        $line   = static fn (string $code, string $desc, float $dr, float $cr) => $at + ['code' => $code, 'desc' => $desc, 'dr' => $dr, 'cr' => $cr];

        $this->transaction(function () use ($run, $period, $totals, $due, $line, $codeOf, $actorId) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => $due, 'sourceType' => 'payroll_run', 'sourceId' => (int) $run['id'],
                'docRef' => $run['reference'], 'series' => 'PV',
                'narration' => 'Statutory remittance — ' . $period['name'],
                'memo' => 'PAYE, NSSF, SHIF, the housing levy and NITA for ' . $period['name'] . ' remitted to KRA, NSSF and SHA.',
            ], [
                $line($codeOf('paye'), 'PAYE remitted to KRA iTax', $totals['paye'], 0),
                $line($codeOf('nssf_employee'), 'NSSF Tier I and II remitted', $totals['nssf'], 0),
                $line($codeOf('shif'), 'SHIF remitted', $totals['shif'], 0),
                $line($codeOf('housing_levy_employee'), 'Housing levy and NITA remitted', $totals['housing'], 0),
                $line(PostingAccounts::of('payrollBank'), 'Paid from the operating account', 0, $totals['total']),
            ], (int) $run['prepared_by'], $actorId, 'Raised by payroll on remitting the ' . $period['name'] . ' statutory deductions');

            $this->db->table('payroll_runs')->where('id', $run['id'])->update([
                'remitted_at' => Clock::timestamp(), 'remittance_journal_id' => $this->journalId($journal), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('payroll_run', (int) $run['id'], $run['reference'], 'Statutory deductions of '
                . Prototype::fmt($totals['total']) . ' remitted as ' . $journal . ' by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return $this->reread($period);
    }

    /**
     * What is owed to KRA, NSSF and SHA for a month: the employee's deductions and
     * the employer's own contributions, which are remitted together.
     */
    public function statutory(array $period): array
    {
        $totals = $this->runView($period)['totals'];
        $out    = [
            'paye' => $totals['paye'], 'nssf' => $totals['nssf'] * 2, 'shif' => $totals['shif'],
            'housing' => $totals['housingLevy'] * 2 + $totals['nita'], 'nita' => $totals['nita'],
            'levy' => $totals['housingLevy'] * 2,
        ];

        return $out + ['total' => $out['paye'] + $out['nssf'] + $out['shif'] + $out['housing']];
    }

    /** Statutory deductions fall due on the 9th of the month after the run. */
    public function remittanceDue(array $period): string
    {
        return date('Y-m-09', strtotime($period['ends_on'] . ' +1 day'));
    }

    // ---- Changing the register ----

    /** A new appointment. The starter first appears in the run for the month they join. */
    public function addStarter(array $in, int $actorId): array
    {
        $name  = trim((string) ($in['name'] ?? ''));
        $basic = (float) ($in['basic'] ?? 0);
        if ($name === '' || $basic <= 0) {
            throw new RuleViolation('A new starter needs a name and a basic salary.');
        }
        $joined = $this->asDate($in['joined'] ?? null, 'joining date');
        $grade  = $this->requireGrade((string) ($in['grade'] ?? ''));
        $alloc  = $this->checkedAllocation($in['alloc'] ?? []);

        // The joining month is the starter's first run, so it cannot be one that
        // has already paid — the posted journal would no longer be the run.
        $period = $this->periodOf($joined);
        if ($period !== null && ($this->runFor((int) $period['id'])['status'] ?? '') === 'posted') {
            throw new RuleViolation('The ' . $period['name'] . ' run is already posted. Date the appointment in a later run, or reverse that one first.');
        }

        $encrypter = service('encrypter');
        $encrypt   = static fn (?string $v) => $v === null || trim($v) === '' ? null : base64_encode($encrypter->encrypt(trim($v)));
        $account   = preg_replace('/\D/', '', (string) ($in['bankAccount'] ?? ''));

        return $this->transaction(function () use ($in, $name, $basic, $joined, $grade, $alloc, $encrypt, $account, $actorId) {
            $no = $this->nextStaffNo();
            $id = $this->insert('staff', [
                'entity_id' => $this->lookups->entityId(), 'staff_no' => $no, 'name' => $name,
                'job_title' => trim((string) ($in['role'] ?? '')) ?: 'Officer', 'grade' => $grade['code'], 'joined_on' => $joined,
                'kra_pin_encrypted' => $encrypt($in['kra'] ?? null), 'nssf_no_encrypted' => $encrypt($in['nssfNo'] ?? null),
                'bank_name' => trim((string) ($in['bankName'] ?? '')) ?: null,
                'bank_account_encrypted' => $encrypt($account), 'bank_account_last4' => $account === '' ? null : substr($account, -4),
                'created_at' => Clock::timestamp(),
            ]);

            $this->writePay($id, $joined, ['basic_salary' => $basic] + $this->benefitAmounts($in, $grade, $basic)
                + ['sacco' => (float) ($in['sacco'] ?? 0)]);
            $this->writeAllocation($id, $joined, $alloc);
            $this->audit('staff', $id, $no, $name . ' appointed as ' . ($in['role'] ?? 'Officer') . ' on grade ' . $grade['code']
                . ', first paid in the ' . date('M Y', strtotime($joined)) . ' run', $actorId, 'created');

            return ['staffNo' => $no, 'name' => $name, 'from' => date('M Y', strtotime($joined))];
        });
    }

    /** A pay award, effective from a run. Earlier runs are untouched. */
    public function changePay(string $staffNo, array $in, int $actorId): array
    {
        $staff  = $this->requireStaff($staffNo);
        $period = $this->openRunPeriod($in['from'] ?? null, 'pay award');
        $basic  = (float) ($in['basic'] ?? 0);
        if ($basic <= 0) {
            throw new RuleViolation('Enter the revised basic salary.');
        }
        $grade  = $this->requireGrade($staff['grade']);
        $items  = ['basic_salary' => $basic] + $this->benefitAmounts($in, $grade, $basic);

        return $this->transaction(function () use ($staff, $period, $items, $actorId) {
            $this->closeAndReplace((int) $staff['id'], $period['starts_on'], $items);
            $gross = array_sum($items);
            $this->audit('staff', (int) $staff['id'], $staff['staff_no'], 'Pay award effective ' . $period['name']
                . ' — gross moves to ' . Prototype::fmt($gross) . ' from that run', $actorId);

            return ['staffNo' => $staff['staff_no'], 'name' => $staff['name'], 'from' => $period['name'], 'gross' => $gross];
        });
    }

    /** A new split across grants, effective from a run, employer contributions with it. */
    public function reallocate(string $staffNo, array $in, int $actorId): array
    {
        $staff  = $this->requireStaff($staffNo);
        $period = $this->openRunPeriod($in['from'] ?? null, 're-allocation');
        $alloc  = $this->checkedAllocation($in['alloc'] ?? []);

        return $this->transaction(function () use ($staff, $period, $alloc, $actorId) {
            $this->db->table('staff_allocations')->where('staff_id', $staff['id'])
                ->where('effective_from <', $period['starts_on'])->where('effective_to IS NULL', null, false)
                ->update(['effective_to' => date('Y-m-d', strtotime($period['starts_on'] . ' -1 day')), 'updated_at' => Clock::timestamp()]);
            $this->db->table('staff_allocations')->where('staff_id', $staff['id'])
                ->where('effective_from >=', $period['starts_on'])->delete();
            $this->writeAllocation((int) $staff['id'], $period['starts_on'], $alloc);

            $this->audit('staff', (int) $staff['id'], $staff['staff_no'], $staff['name'] . ' re-allocated across '
                . count($alloc) . ' charge line(s) from the ' . $period['name'] . ' run', $actorId);

            return ['staffNo' => $staff['staff_no'], 'name' => $staff['name'], 'from' => $period['name'], 'lines' => count($alloc)];
        });
    }

    /** A leaver: paid pro rata for the days worked in the leaving month, then off the roster. */
    public function recordLeaver(string $staffNo, ?string $lastDay, int $actorId): array
    {
        $staff = $this->requireStaff($staffNo);
        $date  = $this->asDate($lastDay, 'last day');
        if ($date < $staff['joined_on']) {
            throw new RuleViolation($staff['name'] . ' joined on ' . self::dmy($staff['joined_on']) . ' and cannot leave before that.');
        }
        $period = $this->periodOf($date);
        if ($period !== null && ($this->runFor((int) $period['id'])['status'] ?? '') === 'posted') {
            throw new RuleViolation('The ' . $period['name'] . ' run is already posted. Reverse it before changing who it paid.');
        }

        return $this->transaction(function () use ($staff, $date, $actorId) {
            $this->db->table('staff')->where('id', $staff['id'])->update(['left_on' => $date, 'updated_at' => Clock::timestamp()]);
            $this->audit('staff', (int) $staff['id'], $staff['staff_no'], $staff['name'] . ' recorded as leaving ' . self::dmy($date)
                . ' — paid for ' . (int) date('j', strtotime($date)) . ' days in that run and off the roster afterwards', $actorId);

            return ['staffNo' => $staff['staff_no'], 'name' => $staff['name'], 'leaves' => self::dmy($date),
                'days' => (int) date('j', strtotime($date))];
        });
    }

    // ---- Writing ----

    /** Creates the run row on first submission, or moves the one already there. */
    private function write(array $period, array $draft, ?array $run, array $set, string $note, int $actorId): array
    {
        $totals = $draft['totals'];
        $row    = [
            'gross' => $totals['gross'], 'total_deductions' => $totals['deductions'], 'net' => $totals['net'],
            'employer_cost' => $totals['employerCost'], 'staff_count' => $totals['staff'], 'updated_at' => Clock::timestamp(),
        ] + $set;

        $this->transaction(function () use ($run, $row, $period, $note, $actorId) {
            if ($run === null) {
                $reference = $this->nextRunReference($period['ends_on']);
                $id = $this->insert('payroll_runs', $row + [
                    'entity_id' => $this->lookups->entityId(), 'period_id' => (int) $period['id'], 'reference' => $reference,
                    'created_at' => Clock::timestamp(),
                ]);
            } else {
                [$id, $reference] = [(int) $run['id'], $run['reference']];
                $this->db->table('payroll_runs')->where('id', $id)->update($row);
            }
            $this->audit('payroll_run', $id, $reference, $note, $actorId, 'history');
        });

        return $this->reread($period);
    }

    /** The payslips a posted run paid, with the charge behind each one. */
    private function writePayslips(int $runId, array $draft): void
    {
        $amounts = static fn (array $p) => array_filter([
            'basic_salary' => $p['basic'], 'acting_allowance' => $p['acting'], 'paye' => $p['paye'],
            'nssf_employee' => $p['nssf'], 'shif' => $p['shif'], 'housing_levy_employee' => $p['housingLevy'],
            'sacco' => $p['sacco'], 'advance_recovery' => $p['advance'], 'nssf_employer' => $p['employer']['nssf'],
            'housing_levy_employer' => $p['employer']['housingLevy'], 'nita' => $p['employer']['nita'],
        ] + array_column($p['benefits'], 'amount', 'key'), static fn ($a) => $a > 0);

        foreach ($draft['roster'] as $i => $s) {
            $p  = $draft['payslips'][$i];
            $id = $this->insert('payslips', [
                'payroll_run_id' => $runId, 'staff_id' => $s['id'],
                'days_payable' => $s['days'] > 0 ? $s['days'] : $s['monthDays'], 'days_in_month' => $s['monthDays'],
                'gross' => $p['gross'], 'taxable_pay' => $p['taxable'], 'paye' => $p['paye'], 'nssf' => $p['nssf'],
                'shif' => $p['shif'], 'housing_levy' => $p['housingLevy'], 'other_deductions' => $p['sacco'] + $p['advance'],
                'net_pay' => $p['net'], 'employer_cost' => $p['employerCost'], 'created_at' => Clock::timestamp(),
            ]);

            foreach ($amounts($p) as $key => $amount) {
                $this->insert('payslip_lines', ['payslip_id' => $id, 'pay_component_id' => $this->componentId($key), 'amount' => $amount]);
            }

            $gross    = Payroll::apportion($p['gross'], $s['alloc']);
            $employer = Payroll::apportion($p['employerCost'], $s['alloc']);
            foreach ($s['alloc'] as $n => $a) {
                $this->insert('payslip_allocations', [
                    'payslip_id' => $id, 'fund_id' => $a['fundId'], 'programme_id' => $a['programmeId'], 'grant_id' => $a['grantId'],
                    'allocation_pct' => $a['pct'], 'gross' => $gross[$n], 'employer_cost' => $employer[$n],
                ]);
            }
        }
    }

    /** @param array<string, float> $items component key => amount */
    private function writePay(int $staffId, string $from, array $items): void
    {
        foreach ($items as $key => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $this->insert('staff_pay_items', [
                'staff_id' => $staffId, 'pay_component_id' => $this->componentId($key), 'amount' => $amount,
                'effective_from' => $from, 'created_at' => Clock::timestamp(),
            ]);
        }
    }

    private function writeAllocation(int $staffId, string $from, array $alloc): void
    {
        foreach ($alloc as $a) {
            $this->insert('staff_allocations', [
                'staff_id' => $staffId, 'fund_id' => $a['fundId'], 'programme_id' => $a['programmeId'], 'grant_id' => $a['grantId'],
                'allocation_pct' => $a['pct'], 'effective_from' => $from, 'created_at' => Clock::timestamp(),
            ]);
        }
    }

    /**
     * Deducts an advance from pay over a run of months.
     *
     * Advances calls this when an unsurrendered balance is converted to a payroll
     * recovery: the deduction is a dated pay item like any other, so each run
     * picks it up and credits 1220 as it posts. An existing recovery still
     * running is added to rather than replaced — one person can owe two advances.
     */
    public function scheduleRecovery(int $staffId, float $each, string $from, string $to): void
    {
        $existing = $this->row(
            'SELECT i.* FROM {staff_pay_items} i JOIN {pay_components} c ON c.id = i.pay_component_id
             WHERE i.staff_id = ? AND c.key = ? AND (i.effective_to IS NULL OR i.effective_to >= ?) ORDER BY i.effective_from DESC LIMIT 1',
            [$staffId, 'advance_recovery', $from]
        );

        if ($existing === null) {
            $this->writePay($staffId, $from, ['advance_recovery' => $each]);
            $this->db->table('staff_pay_items')->where('staff_id', $staffId)
                ->where('pay_component_id', $this->componentId('advance_recovery'))
                ->where('effective_from', $from)->update(['effective_to' => $to, 'updated_at' => Clock::timestamp()]);

            return;
        }

        $this->db->table('staff_pay_items')->where('id', $existing['id'])->update([
            'amount'       => self::num($existing['amount']) + $each,
            'effective_to' => max((string) ($existing['effective_to'] ?? $to), $to),
            'updated_at'   => Clock::timestamp(),
        ]);
    }

    /**
     * Ends the pay items in force the day before the award and writes the new
     * ones — history is kept, so earlier runs still recompute to what they paid.
     */
    private function closeAndReplace(int $staffId, string $from, array $items): void
    {
        $ids = array_map($this->componentId(...), array_keys($items));
        $this->db->table('staff_pay_items')->where('staff_id', $staffId)->whereIn('pay_component_id', $ids)
            ->where('effective_from <', $from)->where('effective_to IS NULL', null, false)
            ->update(['effective_to' => date('Y-m-d', strtotime($from . ' -1 day')), 'updated_at' => Clock::timestamp()]);
        $this->db->table('staff_pay_items')->where('staff_id', $staffId)->whereIn('pay_component_id', $ids)
            ->where('effective_from >=', $from)->delete();

        $this->writePay($staffId, $from, $items);
    }

    // ---- Reading back and checking ----

    private function reread(array $period): array
    {
        self::forget();

        return ['period' => $period['name'], 'run' => (new self())->runFor((int) $period['id'])];
    }

    private function componentId(string $key): int
    {
        // `key` is reserved in MySQL, so the column is always qualified.
        return (int) $this->value('SELECT c.id FROM {pay_components} c WHERE c.key = ?', [$key])
            ?: throw new RuleViolation($key . ' is not a pay component.');
    }

    /**
     * The programme the statutory liabilities are charged to.
     *
     * PAYE, NSSF and the rest are withheld across the whole payroll, so they belong
     * to the programme that carries shared costs rather than to any one of them —
     * "Shared services" where the organisation names one, and otherwise whichever
     * programme carries the largest share of the shared-cost allocation.
     */
    private function sharedProgrammeId(): ?int
    {
        $named = $this->lookups->programmeId('Shared services');
        if ($named !== null) {
            return $named;
        }

        $id = $this->value("SELECT id FROM {programmes} WHERE status <> 'inactive' ORDER BY cost_share_pct DESC, code LIMIT 1");

        return $id === null ? null : (int) $id;
    }

    private function coreFundId(): int
    {
        return (int) $this->value("SELECT id FROM {funds} WHERE ledger_group = 'general' AND status = 'active' ORDER BY code LIMIT 1");
    }

    private function journalId(string $ref): int
    {
        return (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$ref]);
    }

    private function nextRunReference(string $date): string
    {
        $stem = 'PR-' . substr($date, 2, 2) . '-';
        $max  = 0;
        foreach ($this->rows('SELECT reference FROM {payroll_runs} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function nextStaffNo(): string
    {
        $max = 0;
        foreach ($this->rows("SELECT staff_no FROM {staff} WHERE staff_no LIKE 'ELG-%'") as $s) {
            $max = max($max, (int) substr($s['staff_no'], 4));
        }

        return 'ELG-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function requireStaff(string $staffNo): array
    {
        return $this->row('SELECT * FROM {staff} WHERE staff_no = ?', [$staffNo])
            ?? throw new RuleViolation($staffNo . ' is not on the payroll register.');
    }

    private function requireGrade(string $code): array
    {
        foreach ($this->grades() as $g) {
            if ($g['code'] === $code) {
                return $g;
            }
        }

        throw new RuleViolation('"' . $code . '" is not a grade on the scale. Grades are set in Settings → Payroll.');
    }

    /** The run a change takes effect from: a month on the scale that is not already posted. */
    private function openRunPeriod(?string $name, string $what): array
    {
        $period = $this->periodNamed($name);
        if ($period === null || $period['name'] !== $name) {
            throw new RuleViolation('Choose the run the ' . $what . ' takes effect from.');
        }
        if (($this->runFor((int) $period['id'])['status'] ?? '') === 'posted') {
            throw new RuleViolation('The ' . $period['name'] . ' run is already posted. Apply the ' . $what
                . ' from a later run, or reverse that one first.');
        }

        return $period;
    }

    private function periodOf(string $date): ?array
    {
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $date && $date <= $p['ends_on']) {
                return $p;
            }
        }

        return null;
    }

    private function asDate(mixed $value, string $what): string
    {
        $date = is_string($value) ? substr(trim($value), 0, 10) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
            throw new RuleViolation('Give the ' . $what . ' as a date.');
        }

        return $date;
    }

    /** What the grade awards where the form leaves a benefit blank. */
    private function benefitAmounts(array $in, array $grade, float $basic): array
    {
        $out = [];
        foreach ($this->benefits() as $b) {
            $given = $in['ben'][$b['key']] ?? null;
            $award = $grade['ben'][$b['key']] ?? 0;
            $out[$b['key']] = $given === null || $given === ''
                ? ($b['basis'] === 'pct' ? round($basic * $award / 100) : $award)
                : (float) $given;
        }

        return $out;
    }

    /**
     * The split a change is written with: every line has to resolve to a fund,
     * programme and award, and the percentages have to come to 100.
     */
    private function checkedAllocation(mixed $lines): array
    {
        $out   = [];
        $total = 0.0;
        foreach (is_array($lines) ? $lines : [] as $l) {
            $pct = (float) ($l['pct'] ?? 0);
            if ($pct <= 0) {
                continue;
            }
            $ref     = (string) ($l['grant'] ?? 'Unassigned');
            $grantId = $ref === '' || $ref === 'Unassigned' ? null : $this->lookups->grantId($ref)
                ?? throw new RuleViolation('"' . $ref . '" is not an award on the register.');
            $programme = $this->lookups->programmeId((string) ($l['program'] ?? ''))
                ?? throw new RuleViolation('Every charge line needs a programme.');

            $out[] = [
                'fundId' => $this->lookups->resolveFund($grantId === null ? 'General Fund' : 'Grant Fund', $grantId, (string) $l['program']),
                'programmeId' => $programme, 'grantId' => $grantId, 'pct' => $pct,
            ];
            $total += $pct;
        }

        if ($out === []) {
            throw new RuleViolation('Say where the cost is charged — at least one line.');
        }
        if (round($total, 2) !== 100.0) {
            throw new RuleViolation('Cost allocation must total 100% — it currently comes to ' . rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.') . '%.');
        }

        return $out;
    }
}
