<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ChartRepository;
use App\Repositories\JournalRepository;
use App\Repositories\PeriodRepository;

/** General ledger — the posted lines on one account, with a running balance. */
class Gl extends BaseApiController
{
    public function index()
    {
        $chart  = (new ChartRepository())->accounts();
        $leaves = array_values(array_filter($chart, fn ($a) => $a['postable'] && $a['status'] === 'Active' && $a['code'] !== ChartRepository::DERIVED_SURPLUS));

        $code = $this->request->getGet('account') ?: ($leaves[0]['code'] ?? '5110');
        $acct = null;
        foreach ($leaves as $a) {
            if ($a['code'] === $code) {
                $acct = $a;
                break;
            }
        }
        $acct ??= $leaves[0];

        $ranges = (new PeriodRepository())->ledgerRanges();
        $period = $this->request->getGet('period');
        $period = isset($ranges[$period]) ? $period : array_key_first($ranges);
        [$from, $to] = $ranges[$period];

        $fundFilter    = $this->request->getGet('fund') ?: 'All funds';
        $programFilter = $this->request->getGet('program') ?: 'All programmes';
        $q             = strtolower(trim($this->request->getGet('q') ?? ''));

        $debitNormal = in_array($acct['type'], ['Asset', 'Expense'], true);
        $signed      = static fn (array $e) => $debitNormal ? $e['debit'] - $e['credit'] : $e['credit'] - $e['debit'];

        $opening  = 0.0;
        $inPeriod = [];
        foreach ((new JournalRepository())->postings($acct['code']) as $e) {
            if ($e['date'] < $from) {
                $opening += $signed($e);
                continue;
            }
            if ($e['date'] > $to) {
                continue;
            }
            if ($fundFilter !== 'All funds' && $e['fund'] !== $fundFilter) {
                continue;
            }
            if ($programFilter !== 'All programmes' && $e['program'] !== $programFilter) {
                continue;
            }
            if ($q !== '' && !str_contains(strtolower($e['ref'] . ' ' . $e['narration'] . ' ' . $e['source']), $q)) {
                continue;
            }
            $inPeriod[] = $e;
        }

        $nameOf = function ($c) use ($chart) {
            foreach ($chart as $a) {
                if ($a['code'] === $c) {
                    return $a['name'];
                }
            }
            return 'Suspense';
        };

        $run = $opening;
        $tDr = 0.0;
        $tCr = 0.0;
        $openingDate = date('d M', strtotime($from));
        $rows = [[
            'date'      => $openingDate,
            'ref'       => '',
            'narration' => 'Opening balance brought forward',
            'source'    => '',
            'fund'      => '',
            'program'   => '',
            'contra'    => '',
            'debit'     => '—',
            'credit'    => '—',
            'balance'   => Prototype::fmt($opening),
            'isOpening' => true,
        ]];
        foreach ($inPeriod as $e) {
            $run += $signed($e);
            $tDr += $e['debit'];
            $tCr += $e['credit'];
            $rows[] = [
                'date'      => date('d M', strtotime($e['date'])),
                'ref'       => $e['ref'],
                'narration' => $e['narration'],
                'source'    => $e['source'],
                'fund'      => $e['fund'],
                'program'   => $e['program'],
                'contra'    => $e['contra'] === '' ? '' : $e['contra'] . ' · ' . $nameOf($e['contra']),
                'debit'     => $e['debit'] > 0 ? Prototype::fmt($e['debit']) : '—',
                'credit'    => $e['credit'] > 0 ? Prototype::fmt($e['credit']) : '—',
                'balance'   => Prototype::fmt($run),
            ];
        }

        return $this->json([
            'account'        => $acct,
            'accountOptions' => array_map(fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], $leaves),
            'periodOptions'  => array_keys($ranges),
            'period'         => $period,
            'fund'           => $fundFilter,
            'program'        => $programFilter,
            'rows'           => $rows,
            'summary' => [
                ['label' => 'Opening balance', 'value' => Prototype::fmt($opening), 'note' => date('d M Y', strtotime($from))],
                ['label' => 'Debits', 'value' => Prototype::fmt($tDr), 'note' => count($inPeriod) . ' postings'],
                ['label' => 'Credits', 'value' => Prototype::fmt($tCr), 'note' => $period],
                ['label' => 'Closing balance', 'value' => Prototype::fmt($run), 'note' => $debitNormal ? 'debit normal' : 'credit normal'],
            ],
        ]);
    }
}
