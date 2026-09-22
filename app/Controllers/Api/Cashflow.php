<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
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
        $f = $this->forecast();
        $base = ['scenario' => $f['scenario'], 'scenarioOptions' => self::SCENARIOS, 'hold' => $f['hold'], 'holdLabel' => $f['holdLabel']];
        if ($f['rows'] === []) {
            return $this->json($base + ['rows' => [], 'weeks' => 0, 'stats' => [], 'warning' => '', 'blurb' => '', 'hint' => '',
                'empty' => 'No cashflow forecast has been prepared.']);
        }

        $rows  = $f['rows'];
        $low   = $rows[0];
        foreach ($rows as $r) {
            if ($r['unrestricted'] < $low['unrestricted']) {
                $low = $r;
            }
        }
        $burn  = array_sum(array_column($rows, 'outflow')) / count($rows);
        $cover = $burn > 0 ? number_format(($f['opening'] - $f['restricted']) / $burn, 1) : '0';
        $close = end($rows);
        $peak  = max(array_map(static fn ($r) => max($r['inflow'], $r['outflow']), $rows));
        $n     = count($rows);

        return $this->json($base + [
            'rows' => array_map(static fn ($r) => [
                'wc'           => $r['wc'],
                'note'         => $r['note'],
                'inflow'       => Prototype::fmt($r['inflow']),
                'outflow'      => Prototype::fmt($r['outflow']),
                'net'          => Prototype::fmt($r['net']),
                'positive'     => $r['net'] >= 0,
                'closing'      => Prototype::fmt($r['closing']),
                'unrestricted' => Prototype::fmt($r['unrestricted']),
                // The signal: restricted cash cannot cover a core-cost shortfall.
                'tight'        => $r['unrestricted'] < 0,
                'grantWeek'    => $r['grant'],
                'inflowPct'    => $peak > 0 ? (int) round($r['inflow'] / $peak * 100) : 0,
                'outflowPct'   => $peak > 0 ? (int) round($r['outflow'] / $peak * 100) : 0,
            ], $rows),
            'weeks' => $n,
            'blurb' => ($n === 13 ? 'Thirteen' : $n) . ' weeks from ' . date('j F', strtotime($f['startsOn']))
                . ', built from donor claims in receivables, approved bills, payroll and statutory dates. '
                . 'Restricted balances are shown separately — they cannot fund core costs.',
            'stats' => [
                ['label' => 'Cash on hand', 'value' => Prototype::fmt($f['opening']), 'note' => self::listed($f['accounts']) ?: 'All cash accounts'],
                ['label' => 'Of which restricted', 'value' => Prototype::fmt($f['restricted']), 'note' => 'Held for specific grant activities'],
                ['label' => 'Unrestricted cover', 'value' => $cover . ' weeks', 'note' => 'At the current weekly burn'],
                ['label' => 'Lowest unrestricted point', 'value' => Prototype::fmt($low['unrestricted']), 'note' => 'Week commencing ' . $low['wc']],
                ['label' => 'Closing position, week ' . $n, 'value' => Prototype::fmt($close['closing']), 'note' => date('d M Y', strtotime($close['on']))],
            ],
            'warning' => $low['unrestricted'] < 0
                ? 'Unrestricted cash goes negative in the week commencing ' . $low['wc'] . ' at '
                    . Prototype::fmt($low['unrestricted']) . '. Restricted balances cannot be used to cover core costs — '
                    . 'either bring forward a donor claim or hold discretionary spend.'
                : '',
            'hint'   => $n . '-week rolling forecast · opening ' . Prototype::fmt($f['opening']) . ' · scenario: ' . $f['scenario']
                . ($f['hold'] ? ' with discretionary hold' : ''),
            'legend' => 'Green bar receipts · amber bar payments · restricted balances excluded from the unrestricted column',
        ]);
    }

    /** The forecast as the page shows it, scenario and hold applied, for the Board finance committee. */
    public function export()
    {
        $f = $this->forecast();
        if ($f['rows'] === []) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'No cashflow forecast has been prepared.']);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, [count($f['rows']) . '-week cashflow forecast from ' . date('d M Y', strtotime($f['startsOn']))
            . ' · scenario: ' . $f['scenario'] . ($f['hold'] ? ' with discretionary spend held from week 5' : '')]);
        fputcsv($out, ['Opening cash', round($f['opening'], 2), 'Of which restricted', round($f['restricted'], 2)]);
        fputcsv($out, ['Week commencing', 'Receipts', 'Payments', 'Net', 'Driver', 'Grant receipt', 'Closing cash', 'Of which unrestricted']);
        foreach ($f['rows'] as $r) {
            fputcsv($out, [$r['on'], round($r['inflow'], 2), round($r['outflow'], 2), round($r['net'], 2), $r['note'],
                $r['grant'] ? 'Yes' : '', round($r['closing'], 2), round($r['unrestricted'], 2)]);
        }
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' cashflow forecast '
                . $f['startsOn'] . ' ' . strtolower($f['scenario']) . ($f['hold'] ? ' held' : '') . '.csv"')
            ->setBody($csv);
    }

    /**
     * The chosen scenario run through the latest forecast: each week's opening,
     * net, closing and unrestricted cash.
     */
    private function forecast(): array
    {
        $scenario = $this->request->getGet('scenario') ?: self::SCENARIOS[0];
        if (!in_array($scenario, self::SCENARIOS, true)) {
            $scenario = self::SCENARIOS[0];
        }
        $hold = filter_var($this->request->getGet('hold') ?? 'false', FILTER_VALIDATE_BOOLEAN);

        $cash = (new CashflowRepository())->latest();
        $run  = $cash['opening'];
        $rows = [];
        foreach ($this->applyScenario($cash['weeks'], $scenario, $hold) as $w) {
            $net    = $w['inflow'] - $w['outflow'];
            $run   += $net;
            $rows[] = $w + ['net' => $net, 'closing' => $run, 'unrestricted' => $run - $cash['restricted']];
        }

        return [
            'scenario' => $scenario, 'hold' => $hold, 'rows' => $rows, 'opening' => $cash['opening'], 'restricted' => $cash['restricted'],
            'startsOn' => $cash['startsOn'], 'accounts' => $cash['accounts'],
            'holdLabel' => $hold ? 'Discretionary spend held from week 5' : 'Hold discretionary spend from week 5',
        ];
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

    /** ["KCB Current", "Equity USD", "M-Pesa float"] → "KCB Current, Equity USD and M-Pesa float". */
    private static function listed(array $names): string
    {
        $names = array_values(array_unique($names));
        $last  = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
    }
}
