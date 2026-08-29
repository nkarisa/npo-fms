<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class DonorReports extends BaseApiController
{
    private static function cumOf(array $r): float
    {
        return array_sum(array_map(fn ($l) => $l['cumulative'], $r['lines']));
    }

    private static function reportedOf(array $r): float
    {
        return self::cumOf($r) + $r['reportedAdj'];
    }

    public function index()
    {
        $all    = Prototype::load('DREPORTS');
        $status = $this->request->getGet('status') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($r) use ($status, $q) {
            if ($status !== 'All' && $r['status'] !== $status) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($r['ref'] . ' ' . $r['title'] . ' ' . $r['funder'] . ' ' . $r['grant'] . ' ' . $r['period']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(function ($r) {
            $rep = self::reportedOf($r);
            $act = self::cumOf($r);
            return [
                'ref' => $r['ref'], 'title' => $r['title'], 'funder' => $r['funder'], 'grant' => $r['grant'],
                'period' => $r['period'], 'type' => $r['type'], 'status' => $r['status'],
                'reported' => Prototype::fmt($rep), 'actual' => Prototype::fmt($act), 'tied' => $rep === $act,
                'due' => substr($r['due'], 0, 6), 'dueSoon' => $r['dueDays'] <= 30,
            ];
        }, $filtered);

        $untied = array_values(array_filter($all, fn ($r) => self::reportedOf($r) !== self::cumOf($r)));
        $open   = array_values(array_filter($all, fn ($r) => in_array($r['status'], ['Draft', 'In review', 'Overdue'], true)));
        $due30  = array_values(array_filter($all, fn ($r) => $r['dueDays'] <= 30 && in_array($r['status'], ['Draft', 'In review', 'Overdue'], true)));

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : count(array_filter($all, fn ($r) => $r['status'] === $s))], ['All', 'Draft', 'In review', 'Submitted', 'Queried', 'Overdue', 'Accepted']),
            'stats' => [
                ['label' => 'Open reports', 'value' => (string) count($open), 'note' => 'draft, in review or overdue'],
                ['label' => 'Due within 30 days', 'value' => (string) count($due30), 'note' => count($due30) ? 'action needed' : 'nothing imminent'],
                ['label' => 'Overdue', 'value' => (string) count(array_filter($all, fn ($r) => $r['status'] === 'Overdue')), 'note' => 'blocking disbursement'],
                ['label' => 'Not reconciled', 'value' => (string) count($untied), 'note' => 'cannot be submitted'],
            ],
        ]);
    }

    public function show($ref)
    {
        foreach (Prototype::load('DREPORTS') as $r) {
            if ($r['ref'] === $ref) {
                $r['cumulative'] = self::cumOf($r);
                $r['reported']   = self::reportedOf($r);
                return $this->json($r);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }
}
