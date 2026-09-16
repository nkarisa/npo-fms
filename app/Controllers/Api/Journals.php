<?php

namespace App\Controllers\Api;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use App\Repositories\GrantRepository;
use App\Repositories\JournalRepository;
use App\Repositories\PeriodRepository;
use App\Repositories\RuleViolation;

class Journals extends BaseApiController
{
    public static function validateAllocationFundTransfer(array $lines): string
    {
        if ($lines === []) {
            return 'Allocation journals must include at least two fund movements.';
        }

        $fundTotals = [];
        foreach ($lines as $line) {
            $fund = trim((string) ($line['fund'] ?? 'General Fund'));
            if ($fund === '') {
                $fund = 'General Fund';
            }

            $fundTotals[$fund] = (float) ($fundTotals[$fund] ?? 0) + ((float) ($line['dr'] ?? 0) - (float) ($line['cr'] ?? 0));
        }

        if (count($fundTotals) < 2) {
            return 'Allocation journals must involve at least two funds and their transfers must sum to zero.';
        }

        $netMovement = array_sum(array_values($fundTotals));
        if (abs($netMovement) > 0.0001) {
            return 'Allocation entries must sum to zero across fund transfers. Current fund movement totals are ' . Prototype::fmt(abs($netMovement)) . ' out of balance.';
        }

        $hasPositive = false;
        $hasNegative = false;
        foreach ($fundTotals as $fund => $total) {
            if ($total > 0) {
                $hasPositive = true;
            }
            if ($total < 0) {
                $hasNegative = true;
            }
        }

        if (!$hasPositive || !$hasNegative) {
            return 'Allocation entries must include both receiving and releasing fund movements so the transfer sums to zero.';
        }

        return '';
    }

    public function index()
    {
        $all    = (new JournalRepository())->all();
        $status = $this->request->getGet('status') ?: 'All';
        $type   = $this->request->getGet('type') ?: 'All types';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $sumDr = fn ($lines) => array_sum(array_map(fn ($l) => $l['dr'] ?? 0, $lines));
        $sumCr = fn ($lines) => array_sum(array_map(fn ($l) => $l['cr'] ?? 0, $lines));

        $filtered = array_values(array_filter($all, function ($j) use ($status, $type, $q, $sumDr) {
            if ($status !== 'All' && $j['status'] !== $status) {
                return false;
            }
            if ($type !== 'All types' && $j['type'] !== $type) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($j['ref'] . ' ' . $j['narration'] . ' ' . $j['preparer'] . ' ' . $j['type']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(function ($j) use ($sumDr) {
            $funds = array_unique(array_map(fn ($l) => $l['fund'], $j['lines']));
            $progs = array_unique(array_map(fn ($l) => $l['program'], $j['lines']));

            return [
                'ref'       => $j['ref'],
                'date'      => substr($j['date'], 0, 6),
                'type'      => $j['type'],
                'narration' => $j['narration'],
                'status'    => $j['status'],
                'fund'      => count($funds) === 1 ? $funds[0] : count($funds) . ' segments',
                'program'   => count($progs) === 1 ? $progs[0] : count($progs) . ' segments',
                'lines'     => count($j['lines']),
                'amount'    => Prototype::fmt($sumDr($j['lines'])),
                'preparer'  => $j['preparer'],
            ];
        }, $filtered);

        $countBy = fn ($s) => count(array_filter($all, fn ($j) => $j['status'] === $s));
        $unbalancedDrafts = count(array_filter($all, fn ($j) => $j['status'] === 'Draft' && $sumDr($j['lines']) !== $sumCr($j['lines'])));

        return $this->json([
            'rows'        => $rows,
            'total'       => count($all),
            'typeOptions' => array_merge(['All types'], JournalRepository::types()),
            'tabs'        => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : $countBy($s)], ['All', 'Draft', 'Pending approval', 'Posted', 'Reversed']),
            'stats' => [
                ['label' => 'Awaiting approval', 'value' => (string) $countBy('Pending approval'), 'note' => 'oldest 11 days'],
                ['label' => 'Drafts', 'value' => (string) $countBy('Draft'), 'note' => $unbalancedDrafts . ' out of balance'],
                ['label' => 'Posted', 'value' => (string) $countBy('Posted'), 'note' => 'all periods'],
                ['label' => 'Reversed', 'value' => (string) $countBy('Reversed'), 'note' => 'requires memo'],
            ],
        ]);
    }

    public function show($ref)
    {
        $journal = (new JournalRepository())->find($ref);

        return $journal === null
            ? $this->response->setStatusCode(404)->setJSON(['error' => 'not found'])
            : $this->json($journal);
    }

    /** Creates a new journal document as a Draft or submits it straight for approval. */
    public function create()
    {
        $body   = $this->request->getJSON(true) ?? [];
        $status = (string) ($body['status'] ?? 'Draft');
        if (!in_array($status, ['Draft', 'Pending approval'], true)) {
            $status = 'Draft';
        }
        $type   = (string) ($body['type'] ?? 'Standard');
        $narration = trim((string) ($body['narration'] ?? ''));

        // Validate journal type
        $validTypes = JournalRepository::types();
        if (!in_array($type, $validTypes, true)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Invalid journal type. Must be one of: ' . implode(', ', $validTypes) . '.']);
        }

        // Load all journals once for the reversal checks
        $all = (new JournalRepository())->all();

        // For reversing entries, require a reference to the original entry
        if ($type === 'Reversing') {
            $reversalOf = trim((string) ($body['reversalOf'] ?? ''));
            if ($reversalOf === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Reversing entries must reference an original entry. Provide the original journal reference in "reversalOf".']);
            }

            // Verify the referenced entry exists
            $originalEntry = null;
            foreach ($all as $j) {
                if ($j['ref'] === $reversalOf) {
                    $originalEntry = $j;
                    break;
                }
            }

            if (!$originalEntry) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Referenced entry ' . $reversalOf . ' does not exist.']);
            }

            if ($originalEntry['status'] !== 'Posted') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Cannot reverse ' . $reversalOf . ' — only posted entries can be reversed. Current status: ' . $originalEntry['status'] . '.']);
            }

            // Check if this entry has already been reversed
            $alreadyReversed = array_filter($all, fn ($j) => ($j['reversalOf'] ?? '') === $reversalOf && $j['status'] === 'Posted');
            if ($alreadyReversed) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Entry ' . $reversalOf . ' has already been reversed.']);
            }
        }

        $lines = array_values(array_filter($body['lines'] ?? [], function ($l) {
            return trim((string) ($l['code'] ?? '')) !== '' || (float) ($l['dr'] ?? 0) !== 0.0 || (float) ($l['cr'] ?? 0) !== 0.0;
        }));
        $grants = [];
        foreach ((new GrantRepository())->all() as $grant) {
            $grants[$grant['ref']] = $grant;
        }

        foreach ($lines as &$line) {
            $grantRef = trim((string) ($line['grantRef'] ?? ''));
            if ($grantRef !== '') {
                if (!isset($grants[$grantRef])) {
                    return $this->response->setStatusCode(422)->setJSON(['error' => 'Selected grant ' . $grantRef . ' does not exist.']);
                }
                if (!in_array($grants[$grantRef]['status'], ['Active', 'Closing'], true)) {
                    return $this->response->setStatusCode(422)->setJSON(['error' => 'Selected grant ' . $grantRef . ' is not available for journal postings.']);
                }

                $line['fund'] = $grants[$grantRef]['fund'];
                $line['program'] = $grants[$grantRef]['program'];
            }
        }
        unset($line);

        $lines = array_map(fn ($l) => [
            'code'     => trim((string) ($l['code'] ?? '')),
            'desc'     => trim((string) ($l['desc'] ?? '')),
            'fund'     => $l['fund'] ?? 'General Fund',
            'program'  => $l['program'] ?? 'Shared services',
            'grantRef' => trim((string) ($l['grantRef'] ?? '')),
            'dr'       => round((float) ($l['dr'] ?? 0)),
            'cr'       => round((float) ($l['cr'] ?? 0)),
        ], $lines);

        if (count($lines) < 2) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'A journal needs at least two lines.']);
        }

        $sumDr = array_sum(array_map(fn ($l) => $l['dr'], $lines));
        $sumCr = array_sum(array_map(fn ($l) => $l['cr'], $lines));

        // Every journal — draft or not — must balance: total debits always equal total credits.
        if ($sumDr !== $sumCr || $sumDr === 0.0) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Entry is out of balance by ' . Prototype::fmt(abs($sumDr - $sumCr)) . ' — debits must equal credits before it can be saved.']);
        }

        if ($type === 'Allocation') {
            $allocationError = self::validateAllocationFundTransfer($lines);
            if ($allocationError !== '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => $allocationError]);
            }
        }

        if ($status !== 'Draft' && $narration === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'A narration is required before the entry leaves draft.']);
        }

        foreach ($lines as $l) {
            if (($l['dr'] > 0) === ($l['cr'] > 0)) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Each line needs either a debit or a credit — ' . ($l['code'] ?: 'a line') . ' has ' . ($l['dr'] > 0 ? 'both' : 'neither') . '.']);
            }
        }

        $journal = [
            'date'      => trim((string) ($body['date'] ?? '')) ?: Clock::today()->format('d M Y'),
            'type'      => $type,
            'period'    => $body['period'] ?? (new PeriodRepository())->currentName(),
            'status'    => $status,
            'preparer'  => $body['preparer'] ?? null,
            'doc'       => trim((string) ($body['doc'] ?? '')),
            'memo'      => trim((string) ($body['memo'] ?? '')),
            'narration' => $narration,
            'lines'     => $lines,
        ];

        if ($type === 'Reversing') {
            $journal['reversalOf'] = trim((string) ($body['reversalOf'] ?? ''));
        }

        try {
            $journal = (new JournalRepository())->create($journal, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->response->setStatusCode(201)->setJSON(['journal' => $journal]);
    }

    /** Approves and posts a journal entry from Pending approval status, as the acting user. */
    public function approve($ref)
    {
        if ((new JournalRepository())->find($ref) === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }

        try {
            return $this->json(['journal' => (new JournalRepository())->approve($ref, $this->actorId())]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Rejects a journal entry, returning it to Draft with the reason on record. */
    public function reject($ref)
    {
        $body   = $this->request->getJSON(true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));

        if ((new JournalRepository())->find($ref) === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }

        try {
            return $this->json(['journal' => (new JournalRepository())->reject($ref, $this->actorId(), $reason)]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }
}
