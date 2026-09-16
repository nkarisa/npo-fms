<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\BudgetRepository;
use App\Repositories\ChartRepository;
use App\Repositories\PeriodRepository;

class Budgets extends BaseApiController
{
    public function index()
    {
        $budget  = new BudgetRepository();
        $lines   = $budget->lines();
        $seed    = (new ChartRepository())->accounts();
        $periods = new PeriodRepository();
        $elapsed = $periods->monthsElapsed();
        $group  = $this->request->getGet('group') ?: 'Account group';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $nameOf = function ($c) use ($seed) {
            foreach ($seed as $a) {
                if ($a['code'] === $c) {
                    return $a['name'];
                }
            }
            return $c;
        };

        $enriched = array_map(function ($l) use ($nameOf, $elapsed) {
            $annual = $l['annual'];
            $phased = round($annual * $elapsed / 12);
            $variance = $phased - $l['actual'];
            $pct = $annual > 0 ? (int) round($l['actual'] / $annual * 100) : 0;
            $status = $l['actual'] > $annual ? 'Over'
                : ($l['actual'] > $phased * 1.1 ? 'Watch'
                : ($l['actual'] < $phased * 0.7 ? 'Underspent' : 'On track'));

            return array_merge($l, [
                'name' => $nameOf($l['code']), 'phased' => $phased, 'variance' => $variance, 'pct' => $pct, 'status' => $status,
            ]);
        }, $lines);

        $filtered = array_values(array_filter($enriched, fn ($l) => $q === '' || str_contains(strtolower($l['code'] . ' ' . $l['name'] . ' ' . $l['program'] . ' ' . $l['fund']), $q)));

        $groupKey = $group === 'Programme' ? 'program' : ($group === 'Fund' ? 'fund' : 'group');
        $groups = [];
        foreach ($filtered as $l) {
            $k = $l[$groupKey];
            $groups[$k]['label'] ??= $k;
            $groups[$k]['lines'][] = $l;
        }
        $groupRows = array_map(function ($g) {
            $ls = $g['lines'];
            $a  = array_sum(array_map(fn ($l) => $l['annual'], $ls));
            $ac = array_sum(array_map(fn ($l) => $l['actual'], $ls));
            return [
                'label'  => $g['label'],
                'annual' => Prototype::fmt($a),
                'actual' => Prototype::fmt($ac),
                'pct'    => $a > 0 ? (int) round($ac / $a * 100) : 0,
                'lines'  => array_map(fn ($l) => array_merge($l, [
                    'annualFmt' => Prototype::fmt($l['annual']), 'phasedFmt' => Prototype::fmt($l['phased']),
                    'actualFmt' => Prototype::fmt($l['actual']), 'varianceFmt' => Prototype::fmt($l['variance']),
                ]), $ls),
            ];
        }, array_values($groups));

        $overLines  = array_values(array_filter($enriched, fn ($l) => $l['status'] === 'Over'));
        $watchLines = array_values(array_filter($enriched, fn ($l) => $l['status'] === 'Watch'));
        $totalAnnual = array_sum(array_map(fn ($l) => $l['annual'], $enriched));
        $totalActual = array_sum(array_map(fn ($l) => $l['actual'], $enriched));
        $totalPhased = array_sum(array_map(fn ($l) => $l['phased'], $enriched));

        return $this->json([
            'groups'    => $groupRows,
            'groupOptions' => ['Account group', 'Programme', 'Fund'],
            'versionOptions' => ['FY2026 Original', 'FY2026 Revision 1 (approved)', 'FY2026 Revision 2 (working)'],
            'total'     => count($enriched),
            'rules'     => $budget->rules(),
            'stats' => [
                ['label' => 'Annual budget', 'value' => Prototype::fmt($totalAnnual), 'note' => 'FY2026 Revision 1 (approved)'],
                ['label' => 'Phased to ' . substr($periods->currentName(), 0, 3), 'value' => Prototype::fmt($totalPhased), 'note' => $elapsed . ' of 12 months'],
                ['label' => 'Actual to date', 'value' => Prototype::fmt($totalActual), 'note' => $totalAnnual > 0 ? round($totalActual / $totalAnnual * 100) . '% of annual' : '—'],
                ['label' => 'Variance to phasing', 'value' => Prototype::fmt($totalPhased - $totalActual), 'note' => 'favourable if positive'],
                ['label' => 'Lines needing attention', 'value' => (string) (count($overLines) + count($watchLines)), 'note' => count($overLines) . ' over, ' . count($watchLines) . ' on watch'],
            ],
        ]);
    }
}
