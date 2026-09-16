<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ReceivablesRepository;
use App\Repositories\RuleViolation;

/**
 * Amounts due to ELOG — overwhelmingly grant tranches and reimbursable claims
 * rather than trade sales, which is why a row carries its award reference and
 * the fund the receipt will be allocated to.
 *
 * Mirrors Payables so the two ageing screens read the same way, with one
 * difference that matters: a claim can be part received, so the ageing is on
 * the outstanding balance rather than on the invoice value.
 */
class Receivables extends BaseApiController
{
    private const BUCKETS = ['Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'];

    /** What is still to come in — never negative, even if a donor overpays. */
    public static function outstanding(array $i): float
    {
        return max(0, $i['amount'] - $i['received']);
    }

    /** Written-off and fully received claims are closed and drop out of the ageing. */
    private static function isOpen(array $i): bool
    {
        return !in_array($i['status'], ['Received', 'Written off'], true);
    }

    /** A draft has not been issued to the donor yet, so it cannot be late. */
    private static function isLate(array $i): bool
    {
        return self::isOpen($i) && $i['status'] !== 'Draft' && $i['dueIn'] < 0;
    }

    private static function bucket(array $i): string
    {
        if ($i['dueIn'] >= 0) {
            return 'Current';
        }
        $d = -$i['dueIn'];

        return $d <= 30 ? '1–30 days' : ($d <= 60 ? '31–60 days' : ($d <= 90 ? '61–90 days' : 'Over 90 days'));
    }

    public function index()
    {
        $all    = (new ReceivablesRepository())->all();
        $status = $this->request->getGet('status') ?: 'All';
        $age    = $this->request->getGet('age') ?: 'All';
        $fund   = $this->request->getGet('fund') ?: 'All funds';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($i) use ($status, $age, $fund, $q) {
            if ($status === 'Overdue') {
                if (!self::isLate($i)) {
                    return false;
                }
            } elseif ($status !== 'All' && $i['status'] !== $status) {
                return false;
            }
            if ($fund !== 'All funds' && $i['fund'] !== $fund) {
                return false;
            }
            if ($age !== 'All' && self::bucket($i) !== $age) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($i['no'] . ' ' . $i['donor'] . ' ' . $i['grantRef'] . ' ' . $i['program']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(function ($i) {
            $out = self::outstanding($i);

            return [
                'no'          => $i['no'],
                'donor'       => $i['donor'],
                'subtitle'    => $i['grantRef'] . ' · ' . $i['type'],
                'type'        => $i['type'],
                'program'     => $i['program'],
                'fund'        => $i['fund'],
                'issue'       => substr($i['issue'], 0, 6),
                'due'         => substr($i['due'], 0, 6),
                'ccy'         => $i['ccy'],
                'amount'      => Prototype::fmt($i['amount']),
                'received'    => Prototype::fmt($i['received']),
                'outstanding' => Prototype::fmt($out),
                'status'      => $i['status'],
                'overdue'     => self::isLate($i),
                'bucket'      => self::bucket($i),
                'age'         => $i['dueIn'] >= 0 ? 'in ' . $i['dueIn'] . 'd' : -$i['dueIn'] . 'd',
                // A claim billed in USD or EUR still posts in KES at the agreed rate.
                'foreign'     => isset($i['fx']) ? $i['ccy'] . ' ' . number_format($i['amountFc']) . ' at ' . $i['fx'] : '',
            ];
        }, $filtered);

        $open  = array_values(array_filter($all, fn ($i) => self::isOpen($i)));
        $late  = array_values(array_filter($all, fn ($i) => self::isLate($i)));
        $due30 = array_values(array_filter($open, fn ($i) => $i['dueIn'] >= 0 && $i['dueIn'] <= 30));

        $bankedThisMonth = array_sum(array_map(
            static fn ($i) => array_sum(array_map(
                static fn ($r) => str_contains($r['when'], \App\Libraries\Clock::today()->format('M')) ? $r['amount'] : 0,
                $i['receipts']
            )),
            $all
        ));

        $sumOut = static fn (array $set) => array_sum(array_map(static fn ($i) => self::outstanding($i), $set));

        $aging = array_map(function ($b) use ($open, $sumOut) {
            $in = array_values(array_filter($open, fn ($i) => self::bucket($i) === $b));

            return ['label' => $b, 'value' => Prototype::fmt($sumOut($in)), 'count' => count($in)];
        }, self::BUCKETS);

        return $this->json([
            'rows'          => $rows,
            'total'         => count($all),
            'aging'         => $aging,
            'fundOptions'   => ['All funds', 'General Fund', 'Grant Fund', 'Capital Fund', 'Endowment Fund'],
            'tabs'          => array_map(fn ($s) => [
                'label' => $s,
                'count' => $s === 'All'
                    ? count($all)
                    : ($s === 'Overdue' ? count($late) : count(array_filter($all, fn ($i) => $i['status'] === $s))),
            ], ['All', 'Draft', 'Issued', 'Part received', 'Overdue', 'Received', 'Written off']),
            'stats' => [
                ['label' => 'Total receivable', 'value' => Prototype::fmt($sumOut($open)), 'note' => count($open) . ' open invoices'],
                ['label' => 'Overdue', 'value' => Prototype::fmt($sumOut($late)), 'note' => count($late) . ' past due date'],
                ['label' => 'Due within 30 days', 'value' => Prototype::fmt($sumOut($due30)), 'note' => count($due30) . ' claims expected'],
                ['label' => 'Received this month', 'value' => Prototype::fmt($bankedThisMonth), 'note' => 'banked in ' . \App\Libraries\Clock::today()->format('F')],
                ['label' => 'Draft, not yet issued', 'value' => Prototype::fmt($sumOut(array_filter($all, fn ($i) => $i['status'] === 'Draft'))), 'note' => 'nothing claimed until issued'],
            ],
        ]);
    }

    public function show($no)
    {
        $i = (new ReceivablesRepository())->find($no);
        if ($i === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in receivables.']);
        }

        $i['outstanding'] = self::outstanding($i);
        $i['bucket']      = self::bucket($i);
        $i['overdue']     = self::isLate($i);
        $i['lineTotal']   = array_sum(array_map(static fn ($l) => $l['amount'], $i['lines']));

        return $this->json($i);
    }

    /**
     * Records a receipt against a claim, in full or in part.
     *
     * Guards the two things that would corrupt the control account: receipting a
     * claim that was never issued, and banking more than the donor actually owes.
     */
    public function receipt($no)
    {
        $body   = $this->request->getJSON(true) ?? [];
        $amount = round((float) ($body['amount'] ?? 0));
        $ref    = trim((string) ($body['ref'] ?? ''));

        $repo = new ReceivablesRepository();
        foreach ($repo->all() as $i) {
            if ($i['no'] !== $no) {
                continue;
            }

            if (in_array($i['status'], ['Draft', 'Written off'], true)) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => $no . ' is ' . strtolower($i['status']) . ' — it cannot take a receipt until it is issued to the donor.',
                ]);
            }
            if ($amount <= 0) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'A receipt needs an amount.']);
            }
            if ($ref === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'A receipt needs the bank or M-Pesa reference it was banked against.']);
            }

            $owed = self::outstanding($i);
            if ($amount > $owed) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => 'Receipt of ' . Prototype::fmt($amount) . ' exceeds the ' . Prototype::fmt($owed) . ' outstanding on ' . $no . '.',
                ]);
            }

            try {
                $i = $repo->recordReceipt($no, $amount, $ref, trim((string) ($body['note'] ?? '')), (string) ($body['account'] ?? '1110'), $this->actorId());
            } catch (RuleViolation $e) {
                return $this->refused($e);
            }

            $i['outstanding'] = self::outstanding($i);

            return $this->json(['invoice' => $i]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in receivables.']);
    }
}
