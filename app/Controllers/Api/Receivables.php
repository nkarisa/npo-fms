<?php

namespace App\Controllers\Api;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use App\Repositories\ReceivablesRepository as Repo;
use App\Repositories\RuleViolation;

/**
 * Receivables (v5): amounts due to ELOG — overwhelmingly grant tranches and
 * reimbursable claims rather than trade sales. The invoice list with its ageing
 * (on the outstanding balance, since a claim can be part received), status tabs,
 * fund and search, ten a page; the invoice drawer; the claim builder; issuing,
 * reminders and receipts for one invoice or a selection; the allowance for
 * doubtful debts, by hand on a claim or by the ageing rates; write-off; and the
 * aged statement.
 */
class Receivables extends BaseApiController
{
    private const PAGE_SIZE = 10;

    private const TABS = ['All', 'Draft', 'Issued', 'Part received', 'Overdue', 'Received', 'Written off'];

    private const BUCKETS = Repo::BUCKETS;

    public static function outstanding(array $i): float
    {
        return Repo::outstanding($i);
    }

    private static function bucket(array $i): string
    {
        return Repo::bucket($i);
    }

    private static function sumOut(array $set): float
    {
        return array_sum(array_map([Repo::class, 'outstanding'], $set));
    }

    public function index()
    {
        $repo   = new Repo();
        $all    = $repo->all();
        $status = in_array($this->request->getGet('status'), self::TABS, true) ? $this->request->getGet('status') : 'All';
        $age    = in_array($this->request->getGet('age'), self::BUCKETS, true) ? $this->request->getGet('age') : 'All';
        $fund   = $this->request->getGet('fund') ?: 'All funds';
        $q      = mb_strtolower(trim($this->request->getGet('q') ?? ''));
        $page   = max(1, (int) ($this->request->getGet('page') ?: 1));

        $filtered = array_values(array_filter($all, static function ($i) use ($status, $age, $fund, $q) {
            if ($status === 'Overdue' ? !Repo::isLate($i) : ($status !== 'All' && $i['status'] !== $status)) {
                return false;
            }
            if ($fund !== 'All funds' && $i['fund'] !== $fund) {
                return false;
            }
            if ($age !== 'All' && (!Repo::canCarryAllowance($i) || self::bucket($i) !== $age)) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($i['no'] . ' ' . $i['donor'] . ' ' . $i['grantRef'] . ' ' . $i['program']), $q);
        }));

        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($page, $pages);

        $rows = array_map(static fn ($i) => [
            'no' => $i['no'], 'donor' => $i['donor'], 'subtitle' => $i['grantRef'] . ' · ' . $i['type'],
            'issue' => substr($i['issue'], 0, 6), 'due' => substr($i['due'], 0, 6), 'fund' => $i['fund'], 'program' => $i['program'],
            'amount' => $i['amount'], 'received' => $i['received'], 'outstanding' => Repo::outstanding($i), 'allowance' => $i['allowance'],
            // An issued or part-received claim past its due date reads as overdue.
            'status' => Repo::isLate($i) ? 'Overdue' : $i['status'],
            'age' => $i['dueIn'] >= 0 ? 'in ' . $i['dueIn'] . 'd' : -$i['dueIn'] . 'd',
            'overdue' => Repo::isLate($i),
        ], array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE));

        $open  = array_values(array_filter($all, [Repo::class, 'isOpen']));
        // What is owed: issued claims still open. A draft is not a receivable until it is issued, so
        // these figures agree with 1210 grants receivable.
        $owed  = array_values(array_filter($all, [Repo::class, 'canCarryAllowance']));
        $late  = array_values(array_filter($all, [Repo::class, 'isLate']));
        $due30 = array_values(array_filter($open, static fn ($i) => $i['status'] !== 'Draft' && $i['dueIn'] >= 0 && $i['dueIn'] <= 30));
        $month = Clock::today()->format('Y-m');
        $banked = array_sum(array_map(
            static fn ($i) => array_sum(array_map(static fn ($r) => str_starts_with($r['onISO'], $month) ? $r['amount'] : 0, $i['receipts'])),
            $all
        ));

        $held    = array_sum(array_column($owed, 'allowance'));
        $rates   = $repo->rates();
        $carrying = $owed;

        return $this->json([
            'rows'        => $rows,
            'total'       => count($all),
            'filtered'    => count($filtered),
            'page'        => $page,
            'pages'       => $pages,
            'pageSize'    => self::PAGE_SIZE,
            'fundOptions' => ['All funds', 'General Fund', 'Grant Fund', 'Capital Fund', 'Endowment Fund'],
            'aging'       => array_map(static function ($b) use ($owed) {
                $set = $b === 'All' ? $owed : array_values(array_filter($owed, static fn ($i) => self::bucket($i) === $b));

                return ['key' => $b, 'label' => $b === 'All' ? 'All outstanding' : $b, 'value' => Prototype::fmt(self::sumOut($set)),
                    'count' => count($set) . (count($set) === 1 ? ' invoice' : ' invoices')];
            }, array_merge(['All'], self::BUCKETS)),
            'tabs' => array_map(static fn ($s) => [
                'label' => $s,
                'count' => match ($s) {
                    'All'     => count($all),
                    'Overdue' => count($late),
                    default   => count(array_filter($all, static fn ($i) => $i['status'] === $s)),
                },
            ], self::TABS),
            'hint'   => $age === 'All' ? count($late) . ' invoices past due' : 'Aging filter: ' . $age,
            'footer' => count($filtered) . ' of ' . count($all) . ' invoices · outstanding ' . Prototype::fmt(self::sumOut(array_filter($filtered, [Repo::class, 'isOpen']))),
            'stats'  => [
                ['label' => 'Total receivable', 'value' => Prototype::fmt(self::sumOut($owed)), 'note' => count($owed) . ' issued invoices' . ($held > 0 ? ' · ' . Prototype::fmt(self::sumOut($owed) - $held) . ' net of allowance' : '')],
                ['label' => 'Overdue', 'value' => Prototype::fmt(self::sumOut($late)), 'note' => count($late) . ' past due date'],
                ['label' => 'Due within 30 days', 'value' => Prototype::fmt(self::sumOut($due30)), 'note' => 'Expected before ' . Clock::today()->modify('+30 days')->format('d M')],
                ['label' => 'Received this month', 'value' => Prototype::fmt($banked), 'note' => 'Banked in ' . Clock::today()->format('F')],
                ['label' => 'Unbilled entitlement', 'value' => Prototype::fmt($repo->unbilled()), 'note' => 'Incurred but not yet claimed'],
            ],
            // Held is what 1215 carries against open claims; by the rates is what the
            // ageing rates alone would hold, so the gap shows what applying them posts.
            'allowance' => [
                'held'      => Prototype::fmt($held),
                'net'       => Prototype::fmt(self::sumOut($owed) - $held),
                'specific'  => count(array_filter($carrying, static fn ($i) => $i['allowanceBasis'] === 'Set on the claim')),
                'buckets'   => array_map(static function ($b) use ($carrying, $rates, $repo) {
                    $set = array_values(array_filter($carrying, static fn ($i) => self::bucket($i) === $b));
                    $byRate = array_filter($set, static fn ($i) => $i['allowanceBasis'] !== 'Set on the claim');

                    return [
                        'bucket'  => $b, 'pct' => $rates[$b] ?? 0, 'outstanding' => Prototype::fmt(self::sumOut($set)),
                        'held'    => Prototype::fmt(array_sum(array_column($set, 'allowance'))),
                        'byRates' => Prototype::fmt(array_sum(array_map([$repo, 'ageingAllowance'], $byRate)) + array_sum(array_column(array_diff_key($set, $byRate), 'allowance'))),
                    ];
                }, self::BUCKETS),
                'canManage' => $this->actor()['canApprove'],
            ],
        ]);
    }

    /** One invoice for the drawer, with the actions open to the acting user. */
    public function show($no)
    {
        $repo = new Repo();
        $i = $repo->find($no);
        if ($i === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $no . ' was not found in receivables.']);
        }

        $actor   = $this->actor();
        $mine    = $i['preparedBy'] === $this->actorId();
        $draft   = $i['status'] === 'Draft';
        $receive = Repo::isOpen($i) && !$draft;

        return $this->json([
            'invoice' => $i + ['outstanding' => Repo::outstanding($i), 'overdue' => Repo::isLate($i), 'bucket' => self::bucket($i), 'byRates' => $repo->ageingAllowance($i)],
            'can' => [
                'issue'    => $draft && $actor['canApprove'] && !$mine,
                'receive'  => $receive && ($actor['canPrepare'] || $actor['canApprove']),
                'remind'   => $receive && ($actor['canPrepare'] || $actor['canApprove']),
                'writeOff' => Repo::isLate($i) && $actor['canApprove'],
                'recover'  => $i['status'] === 'Written off' && Repo::outstanding($i) > 0 && $actor['canApprove'],
                'allowance' => Repo::canCarryAllowance($i) && $actor['canApprove'],
                'attach'   => $actor['canPrepare'],
                'note'     => $draft && $mine && $actor['canApprove']
                    ? 'You built this claim, so a second person must issue it.'
                    : ($draft && !$actor['canApprove'] ? 'Issuing a claim needs an approver — it raises the receivable and recognises the income.' : ''),
            ],
            'receiptAccounts' => $repo->receiptAccounts(),
        ]);
    }

    /** What the claim builder offers: active awards with their lines, other income accounts and currencies. */
    public function form()
    {
        $repo = new Repo();

        return $this->json([
            'awards'       => $repo->awards(),
            'otherAccounts' => $repo->otherIncomeAccounts(),
            'currencies'   => Repo::currencies(),
            'due'          => Clock::today()->modify('+30 days')->format('d M Y'),
        ]);
    }

    public function create()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare']) {
            return $this->forbidden($actor['role'] . ' cannot build donor claims.');
        }

        try {
            $invoice = (new Repo())->create($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['invoice' => $invoice])->setStatusCode(201);
    }

    /** Body: {nos: [...]}. */
    public function issue()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot issue claims to donors.');
        }

        return $this->batch(fn (array $nos) => (new Repo())->issue($nos, $this->actorId()));
    }

    /** Body: {nos: [...]}. */
    public function remind()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot send donor reminders.');
        }

        return $this->batch(fn (array $nos) => (new Repo())->remind($nos, $this->actorId()));
    }

    /** Body: {nos: [...]}. */
    public function receiveInFull()
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot record receipts.');
        }

        return $this->batch(fn (array $nos) => (new Repo())->receiveInFull($nos, $this->actorId()));
    }

    /** Body: {amount, account, ref, note}. Records a receipt against an issued claim, in full or in part. */
    public function receipt($no)
    {
        $actor = $this->actor();
        if (!$actor['canPrepare'] && !$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot record receipts.');
        }
        $body = $this->request->getJSON(true) ?? [];

        try {
            $invoice = (new Repo())->recordReceipt(
                (string) $no,
                (float) preg_replace('/[^0-9.]/', '', (string) ($body['amount'] ?? '')),
                (string) ($body['account'] ?? '1110'),
                (string) ($body['ref'] ?? ''),
                trim((string) ($body['note'] ?? '')),
                $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['invoice' => $invoice + ['outstanding' => Repo::outstanding($invoice)]]);
    }

    /** Body: {reason}. */
    public function writeOff($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot write off a claim.');
        }

        try {
            $invoice = (new Repo())->writeOff((string) $no, (string) (($this->request->getJSON(true) ?? [])['reason'] ?? ''), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['invoice' => $invoice]);
    }

    /** Body: {amount, account, ref, note}. Banks money recovered on a written-off claim and reverses the write-off for it. */
    public function recovery($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot reverse a write-off.');
        }
        $body = $this->request->getJSON(true) ?? [];

        try {
            $invoice = (new Repo())->recordRecovery(
                (string) $no,
                (float) preg_replace('/[^0-9.]/', '', (string) ($body['amount'] ?? '')),
                (string) ($body['account'] ?? '1110'),
                (string) ($body['ref'] ?? ''),
                trim((string) ($body['note'] ?? '')),
                $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['invoice' => $invoice + ['outstanding' => Repo::outstanding($invoice)]]);
    }

    /** Body: {amount, reason}. Sets the allowance for doubtful debts held against one claim. */
    public function allowance($no)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot provide for doubtful debts.');
        }
        $body = $this->request->getJSON(true) ?? [];
        $amount = trim((string) ($body['amount'] ?? ''));

        try {
            if ($amount === '' || preg_match('/^[0-9,]*\.?[0-9]*$/', $amount) !== 1) {
                throw new RuleViolation('Enter the allowance to hold against the claim, from 0 to its outstanding balance.');
            }
            $invoice = (new Repo())->setAllowance((string) $no, (float) str_replace(',', '', $amount), (string) ($body['reason'] ?? ''), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['invoice' => $invoice + ['outstanding' => Repo::outstanding($invoice)]]);
    }

    /** Brings the allowance on claims not judged by hand into line with the ageing rates. */
    public function applyAllowanceRates()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot provide for doubtful debts.');
        }

        try {
            return $this->json((new Repo())->applyAgeingRates($this->actorId()));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Body: {rates: {bucket: pct}}. */
    public function allowanceRates()
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->forbidden($actor['role'] . ' cannot change the ageing rates for doubtful debts.');
        }
        $rates = ($this->request->getJSON(true) ?? [])['rates'] ?? [];

        try {
            return $this->json(['rates' => (new Repo())->saveRates(is_array($rates) ? $rates : [], $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** The aged receivables statement as at today, as CSV: issued invoices still open, by ageing bucket. */
    public function statement()
    {
        $open = array_values(array_filter((new Repo())->all(), [Repo::class, 'canCarryAllowance']));
        usort($open, static fn ($a, $b) => [$a['donor'], $a['dueISO']] <=> [$b['donor'], $b['dueISO']]);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Aged receivables statement as at ' . Clock::today()->format('d M Y')]);
        fputcsv($out, array_merge(['Invoice', 'Donor or payer', 'Award', 'Type', 'Fund', 'Status', 'Issued', 'Due', 'Currency', 'Invoiced (KES)', 'Received (KES)'], self::BUCKETS, ['Allowance (KES)']));
        $totals = array_fill_keys(self::BUCKETS, 0.0);
        $allowance = 0.0;
        foreach ($open as $i) {
            $owed = Repo::outstanding($i);
            $bucket = self::bucket($i);
            $totals[$bucket] += $owed;
            $allowance += $i['allowance'];
            fputcsv($out, array_merge(
                [$i['no'], $i['donor'], $i['grantRef'], $i['type'], $i['fund'], Repo::isLate($i) ? 'Overdue' : $i['status'], $i['issue'], $i['due'], $i['ccy'], $i['amount'], $i['received']],
                array_map(static fn ($b) => $b === $bucket ? $owed : '', self::BUCKETS),
                [$i['allowance'] ?: '']
            ));
        }
        fputcsv($out, array_merge(['Total', '', '', '', '', '', '', '', '', '', ''], array_values($totals), [$allowance]));
        fputcsv($out, array_merge(['Net of the allowance for doubtful debts', '', '', '', '', '', '', '', '', '', ''], array_fill(0, count(self::BUCKETS), ''), [array_sum($totals) - $allowance]));
        rewind($out);
        $csv = stream_get_contents($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="aged-receivables-' . Clock::date() . '.csv"')
            ->setBody($csv);
    }

    private function batch(callable $action)
    {
        $nos = ($this->request->getJSON(true) ?? [])['nos'] ?? [];

        try {
            return $this->json($action(is_array($nos) ? $nos : [$nos]));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    private function forbidden(string $message)
    {
        return $this->response->setStatusCode(403)->setJSON(['error' => $message]);
    }
}
