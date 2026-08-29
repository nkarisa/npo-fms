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
}
