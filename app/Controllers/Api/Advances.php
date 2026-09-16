<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

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
    /** The balance on 1220 as last posted. Drift against this is the control check. */
    private const CONTROL_BALANCE = 3184000;

    private const BUCKETS = ['Not due', '1–30 days', '31–60 days', '60+ days'];

    /** Only an issued advance is outstanding; requested and cleared ones are not. */
    public static function outstanding(array $a): float
    {
        return $a['status'] === 'Issued' ? $a['amount'] - $a['accounted'] - $a['recovered'] : 0;
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
        $all    = Prototype::load('ADVANCES');
        $filter = $this->request->getGet('filter') ?: 'Outstanding';
        $age    = $this->request->getGet('age') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($a) use ($filter, $age, $q) {
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
            if ($q !== '' && !str_contains(strtolower($a['ref'] . ' ' . $a['holder'] . ' ' . $a['role'] . ' ' . $a['purpose'] . ' ' . $a['program'] . ' ' . ($a['grant'] ?? '')), $q)) {
                return false;
            }
            return true;
        }));

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
                'bucket'      => self::bucket($a),
                'age'         => $out <= 0 ? '—' : ($a['dueIn'] >= 0 ? 'in ' . $a['dueIn'] . 'd' : -$a['dueIn'] . 'd overdue'),
            ];
        }, $filtered);

        $open      = array_values(array_filter($all, static fn ($a) => self::outstanding($a) > 0));
        $overdue   = array_values(array_filter($open, static fn ($a) => $a['dueIn'] < 0));
        $dueSoon   = array_values(array_filter($open, static fn ($a) => $a['dueIn'] >= 0 && $a['dueIn'] <= 7));
        $observers = array_values(array_filter($open, static fn ($a) => $a['kind'] === 'Observer'));
        $waiting   = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Requested'));
        $queued    = array_values(array_filter($all, static fn ($a) => $a['status'] === 'Approved'));

        $sumOut   = static fn (array $set) => array_sum(array_map(static fn ($a) => self::outstanding($a), $set));
        $outTotal = $sumOut($open);

        $aging = array_map(static function ($b) use ($open, $sumOut) {
            $in = array_values(array_filter($open, static fn ($a) => self::bucket($a) === $b));

            return ['label' => $b, 'value' => Prototype::fmt($sumOut($in)), 'count' => count($in)];
        }, self::BUCKETS);

        $drift = $outTotal - self::CONTROL_BALANCE;

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'aging' => $aging,
            'tabs'  => array_map(static fn ($t) => [
                'label' => $t,
                'count' => match ($t) {
                    'Outstanding'       => count($open),
                    'Overdue'           => count($overdue),
                    'Awaiting approval' => count($waiting) + count($queued),
                    'Cleared'           => count(array_filter($all, static fn ($a) => in_array($a['status'], ['Surrendered', 'Recovered'], true))),
                    default             => count($all),
                },
            ], ['Outstanding', 'Overdue', 'Awaiting approval', 'Cleared', 'All']),
            'stats' => [
                ['label' => 'Outstanding', 'value' => Prototype::fmt($outTotal), 'note' => count($open) . ' advances against 1220'],
                ['label' => 'Overdue', 'value' => Prototype::fmt($sumOut($overdue)), 'note' => count($overdue) . ' past the surrender date'],
                ['label' => 'Awaiting approval', 'value' => (string) count($waiting), 'note' => count($queued) . ' approved, not yet issued'],
                ['label' => 'Due within a week', 'value' => (string) count($dueSoon), 'note' => Prototype::fmt($sumOut($dueSoon))],
                ['label' => 'Observer float', 'value' => Prototype::fmt($sumOut($observers)), 'note' => count($observers) . ' deployment batches'],
            ],
            'control' => [
                'balance'    => Prototype::fmt(self::CONTROL_BALANCE),
                'register'   => Prototype::fmt($outTotal),
                'reconciled' => $drift === 0.0,
                'note'       => $drift === 0.0
                    ? 'Outstanding advances of ' . Prototype::fmt($outTotal)
                        . ' agree to the balance on 1220 staff and observer advances. A difference here means expenditure '
                        . 'has been coded straight out of the control account without a surrender.'
                    : 'Outstanding advances now total ' . Prototype::fmt($outTotal) . ', against '
                        . Prototype::fmt(self::CONTROL_BALANCE) . ' last posted to 1220 — a movement of '
                        . Prototype::fmt(abs($drift)) . ' from issues and surrenders not yet posted. '
                        . 'The control account picks the movement up as each voucher and journal posts; until then the register leads the ledger.',
            ],
        ]);
    }

    public function show($ref)
    {
        foreach (Prototype::load('ADVANCES') as $a) {
            if ($a['ref'] === $ref) {
                $a['outstanding'] = self::outstanding($a);
                $a['bucket']      = self::bucket($a);
                $a['receiptTotal'] = array_sum(array_map(static fn ($r) => $r['amount'], $a['receipts']));
                $a['canApprove']  = $a['status'] === 'Requested';
                $a['canIssue']    = $a['status'] === 'Approved';
                $a['canSurrender'] = $a['status'] === 'Issued';

                return $this->json($a);
            }
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $ref . ' was not found in the advances register.']);
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
        $body     = $this->request->getJSON(true) ?? [];
        $receipts = $body['receipts'] ?? [];
        // "refund" banks the unspent balance and closes the advance; "outstanding"
        // leaves it on 1220 against the holder, still ageing.
        $mode     = (string) ($body['mode'] ?? 'refund');
        if (!in_array($mode, ['refund', 'outstanding'], true)) {
            $mode = 'refund';
        }

        $all = Prototype::load('ADVANCES');
        foreach ($all as $idx => $a) {
            if ($a['ref'] !== $ref) {
                continue;
            }

            if ($a['status'] !== 'Issued') {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'Only an issued advance can be surrendered. ' . $ref . ' is ' . strtolower($a['status']) . '.',
                ]);
            }
            if ($receipts === []) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'A surrender needs the receipts it is being accounted for with.']);
            }

            $lines = [];
            foreach ($receipts as $r) {
                $code   = trim((string) ($r['code'] ?? ''));
                $amount = round((float) ($r['amount'] ?? 0));

                if ($code === '' || $amount <= 0) {
                    return $this->response->setStatusCode(422)->setJSON([
                        'error' => 'Every receipt line needs an expenditure account and an amount.',
                    ]);
                }
                $lines[] = ['code' => $code, 'desc' => trim((string) ($r['desc'] ?? '')), 'amount' => $amount];
            }

            $accounted = array_sum(array_map(static fn ($l) => $l['amount'], $lines));
            $balance   = $a['amount'] - $accounted;

            $all[$idx]['receipts']  = $lines;
            $all[$idx]['accounted'] = $accounted;

            if ($balance > 0 && $mode === 'outstanding') {
                // Part surrender: the cash has not come back, so it keeps ageing.
                $all[$idx]['trail'][] = [
                    'when' => date('d M'),
                    'what' => 'Part surrender of ' . Prototype::fmt($accounted) . ' with receipts · '
                        . Prototype::fmt($balance) . ' still outstanding against the holder',
                ];
            } else {
                $all[$idx]['status']  = 'Surrendered';
                $all[$idx]['trail'][] = ['when' => date('d M'), 'what' => 'Surrendered with receipts of ' . Prototype::fmt($accounted)];

                if ($balance > 0) {
                    $all[$idx]['trail'][] = ['when' => date('d M'), 'what' => 'Unspent ' . Prototype::fmt($balance) . ' refunded to bank'];
                } elseif ($balance < 0) {
                    $all[$idx]['trail'][] = ['when' => date('d M'), 'what' => 'Overspend of ' . Prototype::fmt(-$balance) . ' reimbursed to holder on the next payment run'];
                }
            }

            Prototype::save('ADVANCES', $all);

            $result = $all[$idx];
            $result['outstanding'] = self::outstanding($result);
            $result['balance']     = $balance;
            $result['verdict']     = $balance > 0
                ? Prototype::fmt($balance) . ' unspent'
                : ($balance < 0 ? Prototype::fmt(-$balance) . ' overspent' : 'Exactly accounted');

            return $this->json(['advance' => $result]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $ref . ' was not found in the advances register.']);
    }
}
