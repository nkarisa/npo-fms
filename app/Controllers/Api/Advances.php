<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\AdvancesRepository;
use App\Repositories\ApprovalPolicy;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Staff and observer advances — the float that sits on account 1220.
 *
 * An advance is a receivable from the holder until it is surrendered with
 * receipts or recovered through payroll. The register must therefore agree to the
 * control account: if outstanding advances and 1220 disagree, expenditure has
 * been coded straight out of the control account without a surrender, and that is
 * the difference this screen exists to surface.
 *
 * Observer batches matter separately — a single deployment batch can be a
 * hundred M-Pesa transfers against one reference, and they age as one item.
 */
class Advances extends BaseApiController
{
    private const BUCKETS = ['Not due', '1–30 days', '31–60 days', '60+ days'];

    private const TABS = ['Outstanding', 'Overdue', 'Awaiting approval', 'Cleared', 'All'];

    private const PAGE_SIZE = 12;

    private AdvancesRepository $repo;

    private function repo(): AdvancesRepository
    {
        return $this->repo ??= new AdvancesRepository();
    }

    /** Only an issued advance is outstanding; requested and cleared ones are not. */
    public static function outstanding(array $a): float
    {
        return AdvancesRepository::outstanding($a);
    }

    private static function bucket(array $a): string
    {
        if (self::outstanding($a) <= 0) {
            return 'Cleared';
        }
        $d = -$a['dueIn'];

        return $d <= 0 ? 'Not due' : ($d <= 30 ? '1–30 days' : ($d <= 60 ? '31–60 days' : '60+ days'));
    }

    public function index()
    {
        $repo = $this->repo();
        $all  = $repo->all();
        // The balance on 1220 as posted. Drift against this is the control check.
        $control = $repo->controlBalance();

        $filter = in_array($this->request->getGet('filter'), self::TABS, true) ? $this->request->getGet('filter') : 'Outstanding';
        $age    = in_array($this->request->getGet('age'), self::BUCKETS, true) ? $this->request->getGet('age') : 'All';
        $q      = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page   = max(1, (int) ($this->request->getGet('page') ?: 1));

        $filtered = array_values(array_filter($all, static function ($a) use ($filter, $age, $q) {
            $out = self::outstanding($a);

            if ($filter === 'Outstanding' && $out <= 0) {
                return false;
            }
            if ($filter === 'Overdue' && !($out > 0 && $a['dueIn'] < 0)) {
                return false;
            }
            if ($filter === 'Awaiting approval' && !in_array($a['status'], ['Requested', 'Approved'], true)) {
                return false;
            }
            if ($filter === 'Cleared' && !in_array($a['status'], ['Surrendered', 'Recovered'], true)) {
                return false;
            }
            if ($age !== 'All' && self::bucket($a) !== $age) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($a['ref'] . ' ' . $a['holder'] . ' ' . $a['role'] . ' '
                . $a['purpose'] . ' ' . $a['program'] . ' ' . ($a['grant'] ?? '')), $q);
        }));

        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($page, $pages);

        $rows = array_map(static function ($a) {
            $out = self::outstanding($a);

            return [
                'ref'         => $a['ref'],
                'holder'      => $a['holder'],
                'role'        => $a['role'],
                'purpose'     => $a['purpose'],
                'kind'        => $a['kind'],
                'isObserver'  => $a['kind'] === 'Observer',
                'program'     => $a['program'],
                'fund'        => $a['fund'],
                'grant'       => $a['grant'] ?? 'Unassigned',
                'amount'      => Prototype::fmt($a['amount']),
                'accounted'   => $a['accounted'] > 0 ? Prototype::fmt($a['accounted']) : '—',
                'recovered'   => $a['recovered'] > 0 ? Prototype::fmt($a['recovered']) : '—',
                'outstanding' => $out > 0 ? Prototype::fmt($out) : '—',
                'dueDate'     => $a['dueDate'],
                'status'      => $a['status'],
                'method'      => $a['method'] ?: '—',
                'overdue'     => $out > 0 && $a['dueIn'] < 0,
                'open'        => $out > 0,
                'bucket'      => self::bucket($a),
                'age'         => $out <= 0 ? '—' : ($a['dueIn'] >= 0 ? 'in ' . $a['dueIn'] . 'd' : -$a['dueIn'] . 'd overdue'),
            ];
        }, array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE));

        $open      = array_values(array_filter($all, static fn ($a) => self::outstanding($a) > 0));
        $overdue   = array_values(array_filter($open, static fn ($a) => $a['dueIn'] < 0));
        $dueSoon   = array_values(array_filter($open, static fn ($a) => $a['dueIn'] >= 0 && $a['dueIn'] <= 7));
        $observers = array_values(array_filter($open, static fn ($a) => $a['kind'] === 'Observer'));
        $waiting   = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Requested'));
        $queued    = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Approved'));
        $done      = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Surrendered'));
        $taken     = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Recovered'));

        $sumOut   = static fn (array $set) => array_sum(array_map(static fn ($a) => self::outstanding($a), $set));
        $outTotal = $sumOut($open);
        $drift    = round($outTotal - $control, 2);

        return $this->json([
            'rows'     => $rows,
            'total'    => count($all),
            'filtered' => count($filtered),
            'page'     => $page,
            'pages'    => $pages,
            'pageSize' => self::PAGE_SIZE,
            'empty'    => $filtered === [],
            'filter'   => $filter,
            'age'      => $age,

            'aging' => array_map(static function ($b) use ($open, $sumOut) {
                $in = array_values(array_filter($open, static fn ($a) => self::bucket($a) === $b));

                return ['label' => $b, 'value' => Prototype::fmt($sumOut($in)), 'count' => count($in),
                    'note' => count($in) . (count($in) === 1 ? ' advance' : ' advances'), 'heavy' => $b === '60+ days' && $in !== []];
            }, self::BUCKETS),
            'buckets' => self::BUCKETS,

            'tabs' => array_map(static fn ($t) => [
                'label' => $t,
                'count' => match ($t) {
                    'Outstanding'       => count($open),
                    'Overdue'           => count($overdue),
                    'Awaiting approval' => count($waiting) + count($queued),
                    'Cleared'           => count($done) + count($taken),
                    default             => count($all),
                },
            ], self::TABS),

            'stats' => [
                ['label' => 'Outstanding', 'value' => Prototype::fmt($outTotal), 'note' => count($open) . ' advances against 1220'],
                ['label' => 'Overdue', 'value' => Prototype::fmt($sumOut($overdue)), 'note' => count($overdue) . ' past the surrender date'],
                ['label' => 'Awaiting approval', 'value' => (string) count($waiting), 'note' => count($queued) . ' approved, not yet issued'],
                ['label' => 'Due within a week', 'value' => (string) count($dueSoon), 'note' => Prototype::fmt($sumOut($dueSoon))],
                ['label' => 'Observer float', 'value' => Prototype::fmt($sumOut($observers)), 'note' => count($observers) . ' deployment batches'],
            ],

            'footer' => count($filtered) . ' of ' . count($all) . ' advances · ' . count($done) . ' surrendered · '
                . count($taken) . ' recovered through payroll · ' . Prototype::fmt($outTotal) . ' outstanding',

            'control' => [
                'balance'    => Prototype::fmt($control),
                'register'   => Prototype::fmt($outTotal),
                'reconciled' => $drift === 0.0,
                'note'       => $drift === 0.0
                    ? 'Outstanding advances of ' . Prototype::fmt($outTotal)
                        . ' agree to the balance on 1220 staff and observer advances. A difference here means expenditure '
                        . 'has been coded straight out of the control account without a surrender.'
                    : 'Outstanding advances now total ' . Prototype::fmt($outTotal) . ', against '
                        . Prototype::fmt($control) . ' last posted to 1220 — a movement of '
                        . Prototype::fmt(abs($drift)) . ' from issues and surrenders not yet posted. '
                        . 'The control account picks the movement up as each voucher and journal posts; until then the register leads the ledger.',
            ],

            'form' => $repo->formOptions() + ['requireReceipts' => config(\Config\Documents::class)->requireAdvanceReceipts],
        ]);
    }

    public function show($ref)
    {
        $a = $this->repo()->find($ref);
        if ($a === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $ref . ' was not found in the advances register.']);
        }

        $out    = self::outstanding($a);
        $policy = new ApprovalPolicy();
        $rule   = $policy->rule('advance');

        return $this->json($a + [
            'outstanding'   => $out > 0 ? Prototype::fmt($out) : '—',
            'outText'       => match (true) {
                $out > 0                    => Prototype::fmt($out) . ' outstanding',
                $a['status'] === 'Recovered' => Prototype::fmt($a['recovered']) . ' recovered through payroll',
                $a['status'] === 'Surrendered' => 'Cleared',
                default                     => 'Nothing issued yet',
            },
            'amountText'   => Prototype::fmt($a['amount']),
            'bucket'       => self::bucket($a),
            'age'          => $out <= 0 ? '—' : ($a['dueIn'] >= 0 ? 'in ' . $a['dueIn'] . 'd' : -$a['dueIn'] . 'd overdue'),
            'receiptTotal' => Prototype::fmt(array_sum(array_column($a['receipts'], 'amount'))),
            'facts'        => [
                ['label' => 'Holder', 'value' => $a['holder'] . ' · ' . mb_strtolower($a['kind'])],
                ['label' => 'Role', 'value' => $a['role'] ?: '—'],
                ['label' => 'Programme', 'value' => $a['program']],
                ['label' => 'Grant / award', 'value' => $a['grant'] . ' · ' . $a['fund']],
                ['label' => 'Amount advanced', 'value' => Prototype::fmt($a['amount'])],
                ['label' => 'Accounted for', 'value' => $a['accounted'] > 0 ? Prototype::fmt($a['accounted']) : '—'],
                ['label' => 'Recovered', 'value' => $a['recovered'] > 0 ? Prototype::fmt($a['recovered']) : '—'],
                ['label' => 'Outstanding', 'value' => $out > 0 ? Prototype::fmt($out) : '—'],
                ['label' => 'Requested', 'value' => $a['reqDate']],
                ['label' => 'Issued', 'value' => $a['issueDate'] ?: '—'],
                ['label' => 'Paid by', 'value' => $a['method'] ?: '—'],
                ['label' => 'Issue journal', 'value' => $a['journal'] ?: '—'],
                ['label' => 'Surrender due', 'value' => $a['dueDate']],
            ],
            // The three things that change what should be done next.
            'overdueNote' => $out > 0 && $a['dueIn'] < 0
                ? Prototype::fmt($out) . ' has been outstanding ' . (-$a['dueIn']) . ' days past the surrender date. Under the advance policy '
                    . 'an unsurrendered balance is recovered from the next payroll run once it passes ' . SettingsRepository::day('advanceRecoveryDays') . ' days.'
                : '',
            'leaverNote' => $a['left']
                ? 'The holder has left and final pay is already settled, so payroll recovery is no longer available. This balance needs '
                    . 'a written-off decision from the Board finance committee, or recovery from the former employee directly.'
                : '',
            'limitNote' => $a['status'] === 'Requested' && $rule !== null && $rule['threshold'] > 0 && $a['amount'] > $rule['threshold']
                ? 'Above the ' . $rule['approver'] . "'s " . Prototype::fmt($rule['threshold']) . ' advance limit, so it needs the '
                    . ($rule['escalation'] ?? $rule['approver']) . "'s approval."
                : '',
            'rejectedNote' => $a['status'] === 'Rejected' && $a['reason'] ? 'Rejected — ' . $a['reason'] : '',

            'canApprove'   => $a['status'] === 'Requested',
            'canReject'    => $a['status'] === 'Requested',
            'canIssue'     => $a['status'] === 'Approved',
            'canSurrender' => $out > 0,
            'canAttach'    => $this->actor()['canPrepare'],
            'canRemind'    => $out > 0 && $a['dueIn'] < 0,
            'canRecover'   => $out > 0 && $a['dueIn'] < -SettingsRepository::day('advanceRecoveryDays') && !$a['left'] && $a['staffId'] !== null,
            'surrenderTarget' => $out,
            'surrenderText'   => Prototype::fmt($out),
        ]);
    }

    // ---- Moving an advance on ----

    public function create()
    {
        return $this->act(fn ($body) => $this->repo()->create($body, $this->actorId()),
            static fn ($r) => $r['ref'] . ' raised for ' . Prototype::fmt($r['amount']) . '. Nothing reaches 1220 until the advance is issued.');
    }

    public function approve($ref)
    {
        return $this->act(fn ($body) => $this->repo()->approve($ref, $this->actorId(), $body['authorityRef'] ?? null),
            static fn ($r) => $r['ref'] . ' approved. Issuing the funds is what puts ' . Prototype::fmt($r['amount']) . ' on 1220.');
    }

    public function reject($ref)
    {
        return $this->act(fn ($body) => $this->repo()->reject($ref, (string) ($body['reason'] ?? ''), $this->actorId()),
            static fn ($r) => $r['ref'] . ' rejected and returned to the requester.');
    }

    public function issue($ref)
    {
        return $this->act(fn ($body) => $this->repo()->issue($ref, (string) ($body['method'] ?? 'M-Pesa'), $this->actorId()),
            static fn ($r) => $r['journal'] . ' posted — ' . Prototype::fmt($r['amount']) . ' to ' . $r['holder']
                . ', debited to 1220 and credited to ' . $r['account'] . '. A receivable, not expenditure, until it is surrendered.');
    }

    public function remind($ref)
    {
        return $this->act(fn () => $this->repo()->remind($ref, $this->actorId()),
            // The escalation levels already name who they went to; the first does not.
            static fn ($r) => $r['level'] === 1
                ? $r['note'] . ' to ' . $r['to'] . ' for ' . $r['ref'] . '.'
                : $r['note'] . ' for ' . $r['ref'] . '.');
    }

    public function recover($ref)
    {
        return $this->act(fn () => $this->repo()->recover($ref, $this->actorId()),
            static fn ($r) => Prototype::fmt($r['amount']) . ' moved to payroll recovery for ' . $r['holder'] . ' over ' . $r['runs']
                . ($r['runs'] === 1 ? ' run' : ' runs') . ' from ' . $r['from']
                . ($r['shortened'] ? ' — ' . $r['to'] . ' is the last run open, so it is taken over ' . $r['runs'] . ' rather than ' . $r['wanted'] : '')
                . '. 1220 clears as each deduction is made.');
    }

    /**
     * Surrenders an advance against receipts.
     *
     * Three outcomes, and the difference is the point of the screen: receipts can
     * fall short of the advance (the balance is refunded or stays outstanding),
     * match it exactly, or exceed it (the overspend is owed back to the holder).
     */
    public function surrender($ref)
    {
        $body = $this->request->getJSON(true) ?? [];
        // "refund" banks the unspent balance and closes the advance; "outstanding"
        // leaves it on 1220 against the holder, still ageing.
        $mode = in_array($body['mode'] ?? '', ['refund', 'outstanding'], true) ? $body['mode'] : 'refund';

        $lines = [];
        foreach ($body['receipts'] ?? [] as $r) {
            $amount = round((float) ($r['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = ['code' => trim((string) ($r['code'] ?? '')), 'desc' => trim((string) ($r['desc'] ?? '')), 'amount' => $amount];
        }

        try {
            $result = $this->repo()->surrender($ref, $lines, $mode, $this->actorId(), $body['documents'] ?? []);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        [$balance, $accounted] = [$result['balance'], $result['accounted']];

        return $this->json($result + [
            'verdict' => match (true) {
                $balance > 0 => Prototype::fmt($balance) . ' unspent',
                $balance < 0 => Prototype::fmt(-$balance) . ' overspent',
                default      => 'Exactly accounted',
            },
            'message' => $result['journal'] . ' posted — ' . Prototype::fmt($accounted)
                . ' of expenditure coded out of 1220 to the programme lines.' . match (true) {
                    !$result['cleared'] => ' ' . Prototype::fmt($balance) . ' remains outstanding against the holder.',
                    $balance > 0        => ' Unspent ' . Prototype::fmt($balance) . ' banked and the advance is cleared.',
                    $balance < 0        => ' Overspend of ' . Prototype::fmt(-$balance) . ' added to the next payment run.',
                    default             => ' The advance is cleared.',
                },
        ]);
    }

    private function act(callable $write, callable $message)
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
