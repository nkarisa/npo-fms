<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Assets extends BaseApiController
{
    public static function monthlyCharge(array $a): float
    {
        return round($a['cost'] / ($a['life'] * 12));
    }

    public static function nbv(array $a): float
    {
        return $a['cost'] - $a['accum'];
    }

    public function index()
    {
        $all    = Prototype::load('ASSETS');
        $filter = $this->request->getGet('filter') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($a) use ($filter, $q) {
            if ($q !== '' && !str_contains(strtolower($a['tag'] . ' ' . $a['name'] . ' ' . $a['funder'] . ' ' . $a['custodian'] . ' ' . $a['cls']), $q)) {
                return false;
            }
            if ($filter === 'In use') return $a['status'] === 'In use';
            if ($filter === 'Donor-funded') return $a['funder'] !== '—' && $a['status'] !== 'Disposed';
            if ($filter === 'Fully depreciated') return $a['status'] === 'Fully depreciated';
            if ($filter === 'Disposed') return $a['status'] === 'Disposed';
            return true;
        }));

        $rows = array_map(fn ($a) => array_merge($a, [
            'monthly' => Prototype::fmt(self::monthlyCharge($a)),
            'nbv'     => Prototype::fmt(self::nbv($a)),
            'costFmt' => Prototype::fmt($a['cost']),
            'accumFmt'=> Prototype::fmt($a['accum']),
        ]), $filtered);

        $held      = array_values(array_filter($all, fn ($a) => $a['status'] !== 'Disposed'));
        $costTotal = array_sum(array_map(fn ($a) => $a['cost'], $held));
        $accumTotal= array_sum(array_map(fn ($a) => $a['accum'], $held));
        $chargeNow = array_sum(array_map(fn ($a) => self::monthlyCharge($a), array_filter($held, fn ($a) => $a['status'] === 'In use')));
        $donor     = array_values(array_filter($held, fn ($a) => $a['funder'] !== '—'));

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($k) => ['label' => $k, 'count' => $k === 'All' ? count($all) : ($k === 'Donor-funded' ? count($donor) : count(array_filter($all, fn ($a) => $a['status'] === $k)))], ['All', 'In use', 'Donor-funded', 'Fully depreciated', 'Disposed']),
            'stats' => [
                ['label' => 'Cost of assets held', 'value' => Prototype::fmt($costTotal), 'note' => count($held) . ' items on the register'],
                ['label' => 'Depreciation to date', 'value' => Prototype::fmt($accumTotal), 'note' => $costTotal > 0 ? round($accumTotal / $costTotal * 100) . '% written down' : '—'],
                ['label' => 'Net book value', 'value' => Prototype::fmt($costTotal - $accumTotal), 'note' => 'carried in the statement'],
                ['label' => 'Monthly charge', 'value' => Prototype::fmt($chargeNow), 'note' => 'current run rate'],
                ['label' => 'Donor-funded book value', 'value' => Prototype::fmt(array_sum(array_map(fn ($a) => self::nbv($a), $donor))), 'note' => count($donor) . ' items, title may revert'],
            ],
        ]);
    }

    public function show($tag)
    {
        foreach (Prototype::load('ASSETS') as $a) {
            if ($a['tag'] === $tag) {
                $a['nbv'] = self::nbv($a);
                $a['monthly'] = self::monthlyCharge($a);
                return $this->json($a);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }
}
