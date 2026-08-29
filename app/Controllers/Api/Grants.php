<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Grants extends BaseApiController
{
    public static function burn(array $g): int
    {
        return $g['value'] > 0 ? (int) min(100, round($g['spent'] / $g['value'] * 100)) : 0;
    }

    public function index()
    {
        $all    = Prototype::load('GRANTS');
        $status = $this->request->getGet('status') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($g) use ($status, $q) {
            if ($status !== 'All' && $g['status'] !== $status) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($g['ref'] . ' ' . $g['title'] . ' ' . $g['funder'] . ' ' . $g['program']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(fn ($g) => [
            'ref' => $g['ref'], 'title' => $g['title'], 'funder' => $g['funder'], 'program' => $g['program'],
            'period' => $g['period'], 'status' => $g['status'],
            'value' => Prototype::fmt($g['value']), 'received' => Prototype::fmt($g['received']), 'spent' => Prototype::fmt($g['spent']),
            'burnPct' => self::burn($g), 'elapsed' => $g['elapsed'],
            'reportDue' => $g['reportDays'] <= 45,
            'nextReport' => $g['nextReport'],
        ], $filtered);

        $live = array_values(array_filter($all, fn ($g) => in_array($g['status'], ['Active', 'Closing'], true)));
        $reportsSoon = array_values(array_filter($all, fn ($g) => $g['reportDays'] <= 45 && !in_array($g['status'], ['Closed', 'Pipeline'], true)));

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : count(array_filter($all, fn ($g) => $g['status'] === $s))], ['All', 'Active', 'Closing', 'Pipeline', 'Suspended', 'Closed']),
            'stats' => [
                ['label' => 'Live portfolio', 'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['value'], $live))), 'note' => count($live) . ' active and closing awards'],
                ['label' => 'Received to date', 'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['received'], $live))), 'note' => 'to date'],
                ['label' => 'Spent to date', 'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['spent'], $live))), 'note' => 'against elapsed time'],
                ['label' => 'Unspent commitment', 'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['value'] - $g['spent'], $live))), 'note' => 'to deliver before close'],
                ['label' => 'Reports due', 'value' => (string) count($reportsSoon), 'note' => 'within 45 days'],
            ],
        ]);
    }

    public function show($ref)
    {
        foreach (Prototype::load('GRANTS') as $g) {
            if ($g['ref'] === $ref) {
                $g['burnPct'] = self::burn($g);
                return $this->json($g);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }
}
