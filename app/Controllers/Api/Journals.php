<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Journals extends BaseApiController
{
    public function index()
    {
        $all    = Prototype::load('JOURNALS');
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
            'typeOptions' => array_merge(['All types'], Prototype::load('J_TYPES')),
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
        foreach (Prototype::load('JOURNALS') as $j) {
            if ($j['ref'] === $ref) {
                return $this->json($j);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }

    /** Creates a new journal document as a Draft or submits it straight for approval. */
    public function create()
    {
        $body   = $this->request->getJSON(true) ?? [];
        $status = in_array($body['status'] ?? 'Draft', ['Draft', 'Pending approval'], true) ? $body['status'] : 'Draft';
        $type   = (string) ($body['type'] ?? 'Standard');
        $narration = trim((string) ($body['narration'] ?? ''));

        // Validate journal type
        $validTypes = Prototype::load('J_TYPES');
        if (!in_array($type, $validTypes, true)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Invalid journal type. Must be one of: ' . implode(', ', $validTypes) . '.']);
        }

        // Load all journals once for referral checks and final save
        $all = Prototype::load('JOURNALS');

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
        $lines = array_map(fn ($l) => [
            'code'    => trim((string) ($l['code'] ?? '')),
            'desc'    => trim((string) ($l['desc'] ?? '')),
            'fund'    => $l['fund'] ?? 'General Fund',
            'program' => $l['program'] ?? 'Shared services',
            'dr'      => round((float) ($l['dr'] ?? 0)),
            'cr'      => round((float) ($l['cr'] ?? 0)),
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

        if ($status !== 'Draft' && $narration === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'A narration is required before the entry leaves draft.']);
        }

        $seq = 312 + count(array_filter($all, fn ($j) => str_starts_with($j['ref'], 'JV-26')));
        $ref = 'JV-26-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        $date = trim((string) ($body['date'] ?? '')) ?: date('d M Y');

        $trail = [['when' => substr($date, 0, 6), 'what' => 'Draft created by ' . ($body['preparer'] ?? 'J. Achieng')]];
        if ($status === 'Pending approval') {
            $trail[] = ['when' => substr($date, 0, 6), 'what' => 'Submitted for approval to W. Kamau'];
        }

        $journal = [
            'ref'       => $ref,
            'date'      => $date,
            'type'      => $type,
            'period'    => $body['period'] ?? 'Aug 2026',
            'status'    => $status,
            'preparer'  => $body['preparer'] ?? 'J. Achieng',
            'doc'       => trim((string) ($body['doc'] ?? '')),
            'memo'      => trim((string) ($body['memo'] ?? '')),
            'narration' => $narration,
            'lines'     => $lines,
            'trail'     => $trail,
        ];

        // Add reversalOf reference if this is a reversing entry
        if ($type === 'Reversing') {
            $journal['reversalOf'] = trim((string) ($body['reversalOf'] ?? ''));
        }

        array_unshift($all, $journal);
        Prototype::save('JOURNALS', $all);

        return $this->response->setStatusCode(201)->setJSON(['journal' => $journal]);
    }

    /** Approves and posts a journal entry from Pending approval status. */
    public function approve($ref)
    {
        $all = Prototype::load('JOURNALS');
        $journalIndex = null;
        $journal = null;

        foreach ($all as $i => $j) {
            if ($j['ref'] === $ref) {
                $journalIndex = $i;
                $journal = $j;
                break;
            }
        }

        if (!$journal) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }

        if ($journal['status'] !== 'Pending approval') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Only entries awaiting approval can be approved. Current status: ' . $journal['status']]);
        }

        $journal['status'] = 'Posted';
        $date = trim($journal['date']) ?: date('d M Y');
        $journal['trail'][] = ['when' => substr($date, 0, 6), 'what' => 'Approved and posted by W. Kamau'];

        $all[$journalIndex] = $journal;
        Prototype::save('JOURNALS', $all);

        return $this->json(['journal' => $journal]);
    }

    /** Rejects a journal entry, reverting it to Draft status. */
    public function reject($ref)
    {
        $body = $this->request->getJSON(true) ?? [];
        $reason = trim((string) ($body['reason'] ?? ''));

        $all = Prototype::load('JOURNALS');
        $journalIndex = null;
        $journal = null;

        foreach ($all as $i => $j) {
            if ($j['ref'] === $ref) {
                $journalIndex = $i;
                $journal = $j;
                break;
            }
        }

        if (!$journal) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Journal not found']);
        }

        if ($journal['status'] !== 'Pending approval') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Only entries awaiting approval can be rejected. Current status: ' . $journal['status']]);
        }

        $journal['status'] = 'Draft';
        $date = trim($journal['date']) ?: date('d M Y');
        $rejectMsg = 'Rejected by W. Kamau';
        if ($reason !== '') {
            $rejectMsg .= ': ' . $reason;
        }
        $journal['trail'][] = ['when' => substr($date, 0, 6), 'what' => $rejectMsg];

        $all[$journalIndex] = $journal;
        Prototype::save('JOURNALS', $all);

        return $this->json(['journal' => $journal]);
    }
}
