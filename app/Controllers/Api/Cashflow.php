<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\CashflowRepository;

/**
 * Thirteen-week rolling cash forecast.
 *
 * The line that actually answers "can we pay staff in three months?" is not the
 * closing balance but the unrestricted one. Most of ELOG's cash is committed to
 * specific grant activities and cannot legally be used for core costs, so a
 * healthy total can sit on top of a core-funding hole. Every row therefore
 * carries closing cash and closing cash less restricted balances.
 */
class Cashflow extends BaseApiController
{
    public const SCENARIOS = ['Base case', 'Donor delay', 'By-election surge'];

    public function index()
    {
        $scenario = $this->request->getGet('scenario') ?: self::SCENARIOS[0];
        if (!in_array($scenario, self::SCENARIOS, true)) {
            $scenario = self::SCENARIOS[0];
        }
        $hold = filter_var($this->request->getGet('hold') ?? 'false', FILTER_VALIDATE_BOOLEAN);

        $cash       = (new CashflowRepository())->latest();
        $opening    = $cash['opening'];
        $restricted = $cash['restricted'];

        $weeks = $this->applyScenario($cash['weeks'], $scenario, $hold);
        if ($weeks === []) {
            return $this->json(['rows' => [], 'scenario' => $scenario, 'scenarioOptions' => self::SCENARIOS, 'hold' => $hold, 'weeks' => 0, 'stats' => [], 'warning' => '', 'hint' => 'No cashflow forecast has been prepared.']);
        }

        $peak = max(array_map(static fn ($w) => max($w['inflow'], $w['outflow']), $weeks));
        $run  = $opening;
        $rows = [];
        foreach ($weeks as $w) {
            $net   = $w['inflow'] - $w['outflow'];
            $start = $run;
            $run   = $start + $net;
            $unres = $run - $restricted;

            $rows[] = [
                'wc'        => $w['wc'],
                'note'      => $w['note'],
                'opening'   => Prototype::fmt($start),
                'inflow'    => Prototype::fmt($w['inflow']),
                'outflow'   => Prototype::fmt($w['outflow']),
                'net'       => Prototype::fmt($net),
                'positive'  => $net >= 0,
                'closing'   => Prototype::fmt($run),
                'unrestricted' => Prototype::fmt($unres),
                // The signal: restricted cash cannot cover a core-cost shortfall.
                'tight'     => $unres < 0,
                'grantWeek' => (bool) $w['grant'],
                'inflowPct'  => $peak > 0 ? (int) round($w['inflow'] / $peak * 100) : 0,
                'outflowPct' => $peak > 0 ? (int) round($w['outflow'] / $peak * 100) : 0,
                'closingRaw'      => $run,
                'unrestrictedRaw' => $unres,
            ];
        }

        $low   = $this->lowestPoint($rows);
        $burn  = array_sum(array_map(static fn ($w) => $w['outflow'], $weeks)) / max(1, count($weeks));
        $cover = $burn > 0 ? round(($opening - $restricted) / $burn, 1) : 0;
        $close = end($rows);

        return $this->json([
            'rows'            => array_map(static fn ($r) => array_diff_key($r, ['closingRaw' => null, 'unrestrictedRaw' => null]), $rows),
            'scenario'        => $scenario,
            'scenarioOptions' => self::SCENARIOS,
            'hold'            => $hold,
            'holdLabel'       => $hold ? 'Discretionary spend held from week 5' : 'Hold discretionary spend from week 5',
            'weeks'           => count($rows),
            'stats' => [
                ['label' => 'Cash on hand', 'value' => Prototype::fmt($opening), 'note' => 'KCB, Equity USD and M-Pesa float'],
                ['label' => 'Of which restricted', 'value' => Prototype::fmt($restricted), 'note' => 'held for specific grant activities'],
                ['label' => 'Unrestricted cover', 'value' => $cover . ' weeks', 'note' => 'at the current weekly burn'],
                ['label' => 'Lowest unrestricted point', 'value' => Prototype::fmt($low['unrestrictedRaw']), 'note' => 'week commencing ' . $low['wc']],
                ['label' => 'Closing position, week ' . count($rows), 'value' => Prototype::fmt($close['closingRaw']), 'note' => 'end of the forecast horizon'],
            ],
            'warning' => $low['unrestrictedRaw'] < 0
                ? 'Unrestricted cash goes negative in the week commencing ' . $low['wc'] . ' at '
                    . Prototype::fmt($low['unrestrictedRaw']) . '. Restricted balances cannot be used to cover core costs — '
                    . 'either bring forward a donor claim or hold discretionary spend.'
                : '',
            'hint' => '13-week rolling forecast · opening ' . Prototype::fmt($opening)
                . ' · scenario: ' . $scenario . ($hold ? ' with discretionary hold' : ''),
        ]);
    }

    /**
     * Scenarios re-run the projection rather than annotating it.
     *
     * "Donor delay" pushes 65% of each grant receipt in the first six weeks out by
     * six weeks — the realistic shape of a tranche slipping, not a total loss.
     * "By-election surge" lifts operating outflow by a third across the campaign
     * weeks. The discretionary hold trims 15% off outflow from week five, which is
     * the lever finance actually has.
     */
    private function applyScenario(array $weeks, string $scenario, bool $hold): array
    {
        if ($scenario === 'Donor delay') {
            $held = [];
            foreach ($weeks as $i => $w) {
                if ($i < 6 && $w['grant']) {
                    $held[$i + 6]      = ($held[$i + 6] ?? 0) + $w['inflow'] * 0.65;
                    $weeks[$i]['inflow'] = $w['inflow'] * 0.35;
                }
            }
            foreach ($held as $at => $amount) {
                if (isset($weeks[$at])) {
                    $weeks[$at]['inflow'] += $amount;
                }
            }
        }

        if ($scenario === 'By-election surge') {
            foreach ($weeks as $i => $w) {
                if ($i >= 2 && $i <= 6) {
                    $weeks[$i]['outflow'] = $w['outflow'] * 1.34;
                }
            }
        }

        if ($hold) {
            foreach ($weeks as $i => $w) {
                if ($i >= 4) {
                    $weeks[$i]['outflow'] = $w['outflow'] * 0.85;
                }
            }
        }

        return $weeks;
    }

    private function lowestPoint(array $rows): array
    {
        $low = $rows[0];
        foreach ($rows as $r) {
            if ($r['unrestrictedRaw'] < $low['unrestrictedRaw']) {
                $low = $r;
            }
        }

        return $low;
    }
}
