<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ApprovalPolicy;
use App\Repositories\Lookups;
use App\Repositories\PayrollRepository;
use App\Repositories\RuleViolation;

/**
 * One payroll run a month for the whole secretariat.
 *
 * The screen moves a month at a time: the roster for that month is what it was
 * then, and the run against it is prepared, approved by a second person, posted,
 * and its statutory liabilities remitted. Nothing here holds a rate — the
 * calculation reads statutory_rates and the benefits Settings defines.
 */
class Payroll extends BaseApiController
{
    private const TABS = ['All', 'Grant funded', 'Core funded', 'Changes this month'];

    private const PAGE_SIZE = 10;

    private PayrollRepository $repo;

    private function repo(): PayrollRepository
    {
        return $this->repo ??= new PayrollRepository();
    }

    public function index()
    {
        $repo   = $this->repo();
        // Payroll is personal data: every read of the register is logged against the reader.
        $repo->logView($this->actorId(), (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());

        $periods = $repo->periods();
        $period  = $repo->periodNamed($this->request->getGet('period'));
        $at      = array_search($period['name'], array_column($periods, 'name'), true);
        $view    = $repo->runView($period);
        $run     = $repo->runFor((int) $period['id']);
        $status  = $run['status'] ?? 'draft';
        $posted  = $status === 'posted';

        [$roster, $payslips, $totals] = [$view['roster'], $view['payslips'], $view['totals']];
        $changes = array_values(array_filter($roster, static fn ($s) => $s['isNew'] || $s['leaves'] !== null
            || $s['acting'] > 0 || $s['payChanged'] || $s['allocChanged']));

        $filter = in_array($this->request->getGet('filter'), self::TABS, true) ? $this->request->getGet('filter') : 'All';
        $q      = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page   = max(1, (int) ($this->request->getGet('page') ?: 1));

        $keys     = array_keys(array_filter($roster, fn ($s) => $this->matches($s, $filter, $q, $changes)));
        $pages    = max(1, (int) ceil(count($keys) / self::PAGE_SIZE));
        $page     = min($page, $pages);
        $previous = $at > 0 ? $repo->periods()[$at - 1] : null;

        $rows = array_map(static function ($i) use ($roster, $payslips) {
            [$s, $p] = [$roster[$i], $payslips[$i]];

            return [
                'no' => $s['no'], 'name' => $s['name'],
                'sub' => $s['role'] . ' · ' . $s['grade'] . match (true) {
                    $s['leaves'] !== null => ' · leaver, ' . $s['days'] . ' of ' . $s['monthDays'] . ' days',
                    $s['isNew']           => ' · joined this month',
                    $s['payChanged']      => ' · pay award this month',
                    $s['allocChanged']    => ' · re-allocated this month',
                    default               => '',
                },
                'alloc' => implode(' · ', array_map(static fn ($a) => ($a['grant'] === 'Unassigned' ? 'Core' : explode('/', $a['grant'])[0])
                    . ' ' . $a['pct'] . '%', $s['alloc'])),
                'gross' => Prototype::fmt($p['gross']), 'paye' => Prototype::fmt($p['paye']),
                'stat' => Prototype::fmt($p['nssf'] + $p['shif'] + $p['housingLevy']),
                'other' => Prototype::fmt($p['sacco'] + $p['advance']),
                'net' => Prototype::fmt($p['net']),
            ];
        }, array_slice($keys, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE));

        $grantCost = 0.0;
        foreach ($roster as $i => $s) {
            foreach ($s['alloc'] as $a) {
                $grantCost += $a['grant'] === 'Unassigned' ? 0 : $payslips[$i]['cost'] * $a['pct'] / 100;
            }
        }
        $previousCost = $previous === null ? 0.0 : $repo->engine()->totals(array_map(
            $repo->engine()->payslip(...),
            $repo->rosterFor($previous)
        ))['cost'];

        return $this->json([
            'period'        => $period['name'],
            'periodOptions' => array_column($periods, 'name'),
            'periodNote'    => match ($status) {
                'posted'           => 'Posted · locked to further change',
                'approved'         => 'Approved, awaiting posting',
                'pending_approval' => 'Awaiting the approver',
                default            => 'Open — nothing in the ledger yet',
            },
            'kicker' => $period['name'] . match ($status) {
                'posted'           => ' payroll posted',
                'approved'         => ' payroll approved, not yet posted',
                'pending_approval' => ' payroll awaiting approval',
                default            => ' payroll open for review',
            },
            'prevDisabled' => $at <= 0,
            'nextDisabled' => $at >= count($periods) - 1,
            'closed'       => $period['status'] === 'closed',

            'stats' => [
                ['label' => 'Gross pay', 'value' => Prototype::fmt($totals['gross']), 'note' => $totals['staff'] . ' staff on the ' . explode(' ', $period['name'])[0] . ' run'],
                ['label' => 'Statutory deductions', 'value' => Prototype::fmt($totals['paye'] + $totals['nssf'] + $totals['shif'] + $totals['housingLevy']),
                    'note' => 'PAYE, NSSF, SHIF and housing levy withheld'],
                ['label' => 'Employer contributions', 'value' => Prototype::fmt($totals['employerCost']), 'note' => 'NSSF match, housing levy and NITA'],
                ['label' => 'Net pay to staff', 'value' => Prototype::fmt($totals['net']), 'note' => $posted ? 'released from ' . PayrollRepository::BANK : 'to be released from KCB Current'],
                ['label' => 'Total cost to ELOG', 'value' => Prototype::fmt($totals['cost']), 'note' => $this->costNote($totals['cost'], $previousCost, $grantCost, $previous)],
            ],

            'tabs' => array_map(fn ($t) => [
                'label' => $t . ' (' . count(array_filter($roster, fn ($s) => $this->matches($s, $t, '', $changes))) . ')',
                'key'   => $t,
            ], self::TABS),
            'filter' => $filter,

            'rows'     => $rows,
            'empty'    => $keys === [],
            'page'     => $page,
            'pages'    => $pages,
            'pageSize' => self::PAGE_SIZE,
            'filtered' => count($keys),
            'total'    => $totals['staff'],
            'footer'   => count($keys) . ' of ' . $totals['staff'] . ' staff · '
                . count(array_filter($roster, static fn ($s) => array_filter($s['alloc'], static fn ($a) => $a['grant'] !== 'Unassigned') !== []))
                . ' charged in part to grants · '
                . (count($changes) > 0
                    ? count($changes) . (count($changes) === 1 ? ' joiner, leaver or acting change this month' : ' joiners, leavers and acting changes this month')
                    : 'the same roster as ' . ($previous['name'] ?? 'the previous run')),
            'hint' => $posted
                ? 'Posted to the ledger — the run agrees to 5210, 5220 and the 22xx liabilities'
                : 'Nothing is in the ledger until the run is approved and posted',

            'actions'   => $this->actions($period, $run, $totals),
            'remit'     => $this->remittance($period, $run),
            'allocRows' => $this->allocation($view),
            'journal'   => $this->journal($view, $posted),
            'editor'    => $this->editorOptions(),
        ]);
    }

    /** One payslip, in the detail the person is entitled to see on it. */
    public function payslip(string $staffNo)
    {
        $repo   = $this->repo();
        $period = $repo->periodNamed($this->request->getGet('period'));
        $view   = $repo->runView($period);
        $at     = array_search($staffNo, array_column($view['roster'], 'no'), true);
        if ($at === false) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $staffNo . ' was not on the ' . $period['name'] . ' run.']);
        }

        $repo->logView($this->actorId(), (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent(), 'view', 'payslip');
        [$s, $p] = [$view['roster'][$at], $view['payslips'][$at]];

        $relief = Prototype::fmt($repo->rates('personal_relief')[0]['fixed'] ?? 0);
        $shares = \App\Libraries\Payroll::apportion($p['cost'], $s['alloc']);

        return $this->json([
            'no' => $s['no'], 'name' => $s['name'], 'role' => $s['role'], 'period' => 'payslip for ' . $period['name'],
            'meta' => $s['grade'] . ' · joined ' . $s['joined'],
            'bank' => $s['bank'] === null ? '—' : $s['bank'] . ' · ****' . ($s['bankLast4'] ?? '****'),
            'note' => match (true) {
                $s['leaves'] !== null => 'Final dues — left ' . $s['leaves'] . ', paid for ' . $s['days'] . ' of ' . $s['monthDays'] . ' days',
                $s['isNew']           => 'First payroll — joined ' . $s['joined'],
                $s['acting'] > 0      => 'Acting allowance of ' . Prototype::fmt($s['acting']) . ' a month'
                    . ($s['actingFrom'] === null ? '' : ', effective ' . $s['actingFrom']),
                default               => '',
            },
            'earnings' => array_merge(
                [['label' => 'Basic pay', 'value' => Prototype::fmt($p['basic'])]],
                array_map(
                    static fn ($b) => ['label' => $b['name'] . ($b['taxable'] ? '' : ' (non-taxable)'), 'value' => Prototype::fmt($b['amount'])],
                    array_values(array_filter($p['benefits'], static fn ($b) => $b['amount'] > 0)),
                ),
                $p['acting'] > 0 ? [['label' => 'Acting allowance', 'value' => Prototype::fmt($p['acting'])]] : [],
            ),
            'gross'      => Prototype::fmt($p['gross']),
            'deductions' => array_merge([
                ['label' => 'PAYE', 'value' => Prototype::fmt($p['paye']), 'note' => 'on taxable pay of ' . Prototype::fmt($p['taxable']) . ', after relief of ' . $relief],
                ['label' => 'NSSF', 'value' => Prototype::fmt($p['nssf']), 'note' => 'on pensionable pay to the ceiling'],
                ['label' => 'SHIF', 'value' => Prototype::fmt($p['shif']), 'note' => 'a percentage of gross, with a minimum'],
                ['label' => 'Housing levy', 'value' => Prototype::fmt($p['housingLevy']), 'note' => 'a percentage of gross'],
            ], $p['sacco'] > 0 ? [['label' => 'Staff sacco', 'value' => Prototype::fmt($p['sacco']), 'note' => 'voluntary, remitted to ELOG Staff Sacco']] : [],
                $p['advance'] > 0 ? [['label' => 'Salary advance recovered', 'value' => Prototype::fmt($p['advance']), 'note' => 'against 1220 staff advances']] : []),
            'deducted' => Prototype::fmt($p['deductions']),
            'net'      => Prototype::fmt($p['net']),
            'employer' => [
                ['label' => 'NSSF employer match', 'value' => Prototype::fmt($p['employer']['nssf'])],
                ['label' => 'Housing levy employer', 'value' => Prototype::fmt($p['employer']['housingLevy'])],
                ['label' => 'NITA training levy', 'value' => Prototype::fmt($p['employer']['nita'])],
            ],
            'cost'  => Prototype::fmt($p['cost']),
            'alloc' => array_map(static fn ($a, $amount) => [
                'label' => $a['grant'] === 'Unassigned' ? 'Core funded' : $a['grant'],
                'meta' => $a['fund'] . ' · ' . $a['program'], 'pct' => $a['pct'] . '%', 'amount' => Prototype::fmt($amount),
            ], $s['alloc'], $shares),
        ]);
    }

    // ---- The run through approval to the ledger ----

    public function submit()
    {
        return $this->act(fn ($period) => $this->repo()->submit($period, $this->actorId()),
            fn ($r, $period) => $period['name'] . ' payroll sent for approval as ' . ($r['run']['reference'] ?? '') . '.');
    }

    public function approve()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->act(fn ($period) => $this->repo()->approve($period, $this->actorId(), $body['authorityRef'] ?? null),
            fn ($r, $period) => $period['name'] . ' payroll approved — it can now be posted to the ledger.');
    }

    public function post()
    {
        return $this->act(fn ($period) => $this->repo()->post($period, $this->actorId()),
            fn ($r, $period) => explode(' ', $period['name'])[0] . ' payroll posted as ' . ($r['run']['journal_ref'] ?? '')
                . ' — net pay released and PAYE, NSSF, SHIF, the housing levy and NITA raised as liabilities.');
    }

    public function remit()
    {
        return $this->act(fn ($period) => $this->repo()->remit($period, $this->actorId()),
            fn ($r, $period) => 'Statutory remittance for ' . $period['name'] . ' posted as ' . ($r['run']['remittance_ref'] ?? '')
                . ' — the 22xx liabilities are cleared.');
    }

    // ---- Changing the register ----

    public function starter()
    {
        return $this->change(fn ($body) => $this->repo()->addStarter($body, $this->actorId()),
            static fn ($r) => $r['name'] . ' added as ' . $r['staffNo'] . ', first paid in the ' . $r['from'] . ' run.');
    }

    public function payChange()
    {
        return $this->change(fn ($body) => $this->repo()->changePay((string) ($body['staffNo'] ?? ''), $body, $this->actorId()),
            static fn ($r) => 'Pay award for ' . $r['name'] . ' effective ' . $r['from'] . ' — gross moves to '
                . Prototype::fmt($r['gross']) . ' from that run.');
    }

    public function reallocate()
    {
        return $this->change(fn ($body) => $this->repo()->reallocate((string) ($body['staffNo'] ?? ''), $body, $this->actorId()),
            static fn ($r) => $r['name'] . ' re-allocated across ' . $r['lines'] . ' charge line(s) from the ' . $r['from'] . ' run.');
    }

    public function leaver()
    {
        return $this->change(fn ($body) => $this->repo()->recordLeaver((string) ($body['staffNo'] ?? ''), $body['lastDay'] ?? null, $this->actorId()),
            static fn ($r) => $r['name'] . ' recorded as leaving ' . $r['leaves'] . ' — paid for ' . $r['days']
                . ' days in that run and off the roster afterwards.');
    }

    // ---- Shaping the response ----

    private function matches(array $s, string $filter, string $q, array $changes): bool
    {
        if ($q !== '' && !str_contains(mb_strtolower($s['no'] . ' ' . $s['name'] . ' ' . $s['role'] . ' ' . $s['grade']), $q)) {
            return false;
        }
        $onGrant = static fn ($a) => $a['grant'] !== 'Unassigned';

        return match ($filter) {
            'Grant funded'        => array_filter($s['alloc'], $onGrant) !== [],
            'Core funded'         => array_filter($s['alloc'], $onGrant) === [],
            'Changes this month'  => in_array($s['no'], array_column($changes, 'no'), true),
            default               => true,
        };
    }

    private function costNote(float $cost, float $previous, float $grantCost, ?array $prior): string
    {
        if ($prior === null || $previous <= 0) {
            return $cost > 0 ? round($grantCost / $cost * 100) . '% charged to grants' : 'nothing on the run';
        }
        $month = explode(' ', $prior['name'])[0];

        return match (true) {
            round($cost) === round($previous) => 'unchanged on ' . $month,
            $cost > $previous                 => '+' . Prototype::fmt($cost - $previous) . ' on ' . $month,
            default                           => '−' . Prototype::fmt($previous - $cost) . ' on ' . $month,
        };
    }

    /** Which button the run offers next, and why it may not be offered at all. */
    private function actions(array $period, ?array $run, array $totals): array
    {
        $status = $run['status'] ?? 'draft';
        $closed = $period['status'] === 'closed';
        $month  = explode(' ', $period['name'])[0];

        return [
            'status'      => $status,
            'statusLabel' => ucfirst(str_replace('_', ' ', $status)),
            'reference'   => $run['reference'] ?? null,
            'journal'     => $run['journal_ref'] ?? null,
            'canSubmit'   => $status === 'draft' && $totals['staff'] > 0,
            'canApprove'  => $status === 'pending_approval',
            'canPost'     => $status === 'approved' && !$closed,
            'posted'      => $status === 'posted',
            'postLabel'   => 'Post ' . $month . ' payroll',
            'needsAuthority' => (new ApprovalPolicy())->needsAuthority('payroll_run', $totals['cost']),
            // A run may be prepared and approved for a closed month, but nothing
            // posts into one: the month has to be reopened in Period close first.
            'blocked' => $closed && in_array($status, ['draft', 'pending_approval', 'approved'], true)
                ? $period['name'] . ' is closed to posting. Reopen the month in Period close before posting this run.'
                : null,
            'note' => match ($status) {
                'posted'   => '✓ Posted to the ledger. Net pay has left ' . PayrollRepository::BANK . ' and the statutory liabilities sit in 22xx until they are remitted.',
                'approved' => '◐ Approved. Posting will release net pay and raise the statutory liabilities.',
                default    => '◐ Nothing reaches the ledger until the run is approved by the Executive Director.',
            },
        ];
    }

    private function remittance(array $period, ?array $run): array
    {
        $repo = $this->repo();
        $s    = $repo->statutory($period);
        $due  = 'Due ' . date('d M Y', strtotime($repo->remittanceDue($period)));
        $done = ($run['remitted_at'] ?? null) !== null;

        return [
            'total' => Prototype::fmt($s['total']),
            'due'   => date('d M Y', strtotime($repo->remittanceDue($period))),
            'can'   => ($run['status'] ?? '') === 'posted' && !$done,
            'done'  => $done,
            'label' => $done ? 'Remitted ✓' : 'Remit to KRA, NSSF and SHA',
            'journal' => $run['remittance_ref'] ?? null,
            'rows'  => [
                ['name' => 'PAYE — KRA iTax', 'amount' => Prototype::fmt($s['paye']), 'due' => $due, 'basis' => 'Graduated bands, after personal relief'],
                ['name' => 'NSSF Tier I and II', 'amount' => Prototype::fmt($s['nssf']), 'due' => $due, 'basis' => 'A percentage of pensionable pay to the ceiling, matched by ELOG'],
                ['name' => 'SHIF', 'amount' => Prototype::fmt($s['shif']), 'due' => $due, 'basis' => 'A percentage of gross, with a minimum'],
                ['name' => 'Affordable housing levy', 'amount' => Prototype::fmt($s['levy']), 'due' => $due, 'basis' => 'Withheld from staff and matched by ELOG'],
                ['name' => 'NITA training levy', 'amount' => Prototype::fmt($s['nita']), 'due' => $due, 'basis' => 'A flat amount per employee a month'],
            ],
        ];
    }

    /** Where the cost is charged: the run grouped by award, largest first. */
    private function allocation(array $view): array
    {
        $total  = $view['totals']['cost'];
        $groups = [];
        foreach ($view['groups'] as $g) {
            $key = $g['grant'] === 'Unassigned' ? 'Core funded · General Fund' : $g['grant'];
            $groups[$key] ??= ['label' => $key, 'fund' => $g['fund'], 'program' => $g['program'], 'amount' => 0.0, 'heads' => []];
            $groups[$key]['amount'] += $g['gross'] + $g['employer'];
            $groups[$key]['heads'] += $g['heads'];
        }
        usort($groups, static fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return array_map(static fn ($g) => [
            'label' => $g['label'],
            'meta'  => $g['fund'] . ' · ' . $g['program'] . ' · ' . count($g['heads']) . ' staff',
            'amount' => Prototype::fmt($g['amount']),
            'pct'    => $total > 0 ? (int) round($g['amount'] / $total * 100) : 0,
        ], $groups);
    }

    private function journal(array $view, bool $posted): array
    {
        $accounts = (new Lookups())->accounts();
        $debits   = array_sum(array_column($view['lines'], 'dr'));
        $credits  = array_sum(array_column($view['lines'], 'cr'));

        return [
            'title' => $posted ? 'Posted journal' : 'Journal on posting',
            'check' => round($debits, 2) === round($credits, 2)
                ? 'Balanced · ' . Prototype::fmt($debits) . ' each side'
                : 'Out of balance by ' . Prototype::fmt(abs($debits - $credits)) . ' · debits ' . Prototype::fmt($debits) . ', credits ' . Prototype::fmt($credits),
            'lines' => array_map(static fn ($l) => [
                'side' => $l['dr'] > 0 ? 'Dr' : 'Cr',
                'account' => $l['code'] . ' · ' . ($accounts[$l['code']]['name'] ?? ''),
                'memo' => $l['desc'],
                'amount' => Prototype::fmt($l['dr'] > 0 ? $l['dr'] : $l['cr']),
            ], $view['lines']),
        ];
    }

    /** What the staff editor offers: the grades, benefits, awards and runs it can pick from. */
    private function editorOptions(): array
    {
        $repo    = $this->repo();
        $lookups = new Lookups();

        // The editor opens on what each person is paid now, so a pay award or a
        // re-allocation starts from their own figures, not an empty form.
        $pay = $repo->currentPay();

        return [
            'staff' => array_map(static fn ($s) => [
                'no' => $s['no'], 'label' => $s['no'] . ' · ' . $s['name'] . ' · ' . $s['role'],
            ] + ($pay[$s['no']] ?? ['grade' => $s['grade'], 'basic' => 0, 'ben' => (object) [], 'alloc' => []]),
                array_values(array_filter($repo->staffList(), static fn ($s) => $s['left'] === null))),
            'grades' => array_map(static fn ($g) => [
                'code' => $g['code'], 'label' => $g['code'] . ' · ' . $g['title'], 'ben' => $g['ben'],
            ], array_values(array_filter($repo->grades(), static fn ($g) => $g['active']))),
            'benefits' => $repo->benefits(),
            'grants'   => $repo->chargeableAwards(),
            'programmes' => array_values(array_map(static fn ($p) => $p['name'], $lookups->programmes())),
            'banks'    => $repo->banks(),
            'periods'  => array_column($repo->periods(), 'name'),
        ];
    }

    // ---- Running a write ----

    private function act(callable $write, callable $message)
    {
        $period = $this->repo()->periodNamed(($this->request->getJSON(true) ?? [])['period'] ?? $this->request->getGet('period'));

        try {
            $result = $write($period);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($result + ['message' => $message($result, $period)]);
    }

    private function change(callable $write, callable $message)
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $result = $write($body);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($result + ['message' => $message($result)]);
    }
}
