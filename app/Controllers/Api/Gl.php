<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

/** General ledger — deterministically generates postings per account so figures stay stable across requests. */
class Gl extends BaseApiController
{
    private const MONTHS      = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'];
    private const FULL_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August'];

    private function buildLedger(array $acct): array
    {
        mt_srand(crc32($acct['code'] . $acct['name']));
        $debitNormal = in_array($acct['type'], ['Asset', 'Expense'], true);
        $opening     = round($acct['balance'] * 0.38 / 1000) * 1000;
        $movement    = $acct['balance'] - $opening;

        $n = 10 + mt_rand(0, 5);
        $weights = [];
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $neg = (mt_rand(0, 99) / 100 < 0.16) ? -0.35 : 1;
            $v   = (0.4 + mt_rand(0, 99) / 100) * $neg;
            $weights[] = $v;
            $sum += $v;
        }

        $sources = Prototype::load('SOURCES');
        $narrationMap = Prototype::load('NARRATION');
        $narr = $narrationMap[$acct['code']]
            ?? $narrationMap[substr($acct['code'], 0, 2)]
            ?? $narrationMap[substr($acct['code'], 0, 1)]
            ?? $narrationMap['5'];

        $contraFor = function () use ($acct) {
            $r = mt_rand(0, 99) / 100;
            return match ($acct['type']) {
                'Income'    => $r < 0.55 ? '1120' : '1210',
                'Expense'   => $r < 0.6 ? '1110' : '2110',
                'Asset'     => $r < 0.5 ? '1110' : '2110',
                'Liability' => '1110',
                default     => '3900',
            };
        };

        $entries = [];
        $acc = 0;
        for ($i = 0; $i < $n; $i++) {
            $amt = round(($movement * ($weights[$i] / ($sum ?: 1))) / 100) * 100;
            if ($i === $n - 1) {
                $amt = $movement - $acc;
            }
            $acc += $amt;
            $month = min(7, (int) floor(($i / $n) * 8 + mt_rand(0, 99) / 100 * 0.8));
            $day   = 2 + mt_rand(0, 25);
            $src   = $sources[array_rand($sources)];
            $entries[] = [
                'month'    => $month,
                'day'      => $day,
                'source'   => $src['name'],
                'ref'      => $src['ref'] . '-26-' . str_pad((string) (140 + mt_rand(0, 799)), 4, '0', STR_PAD_LEFT),
                'narration'=> $narr[array_rand($narr)],
                'fund'     => $acct['fund'] === 'All funds' ? ['General Fund', 'Grant Fund'][mt_rand(0, 1)] : $acct['fund'],
                'program'  => $acct['program'] === 'Shared' || $acct['program'] === '—'
                    ? ['Election Observation', 'Civic Education', 'Governance Advocacy', 'Shared services'][mt_rand(0, 3)]
                    : $acct['program'],
                'funder'   => $acct['funder'],
                'preparer' => Prototype::load('PREPARERS')[array_rand(Prototype::load('PREPARERS'))],
                'doc'      => 'ELOG/' . $acct['code'] . '/' . (10 + mt_rand(0, 88)),
                'contra'   => $contraFor(),
                'amount'   => $amt,
            ];
        }

        usort($entries, fn ($a, $b) => $a['month'] <=> $b['month'] ?: $a['day'] <=> $b['day']);

        return ['opening' => $opening, 'debitNormal' => $debitNormal, 'entries' => $entries];
    }

    public function index()
    {
        $list   = Prototype::load('SEED');
        $leaves = array_values(array_filter($list, fn ($a) => $a['level'] === 2 && $a['status'] === 'Active'));

        $code = $this->request->getGet('account') ?: ($leaves[0]['code'] ?? '5110');
        $acct = null;
        foreach ($leaves as $a) {
            if ($a['code'] === $code) {
                $acct = $a;
                break;
            }
        }
        $acct ??= $leaves[0];

        $period      = $this->request->getGet('period') ?: 'FY2026 · Jan – Aug';
        $periodRange = Prototype::load('PERIOD_RANGE');
        [$pStart, $pEnd] = $periodRange[$period] ?? [0, 7];
        $fundFilter    = $this->request->getGet('fund') ?: 'All funds';
        $programFilter = $this->request->getGet('program') ?: 'All programmes';
        $q             = strtolower(trim($this->request->getGet('q') ?? ''));

        $led = $this->buildLedger($acct);

        $priorMovement = 0;
        $inPeriod = [];
        foreach ($led['entries'] as $e) {
            if ($e['month'] < $pStart) {
                $priorMovement += $e['amount'];
                continue;
            }
            if ($e['month'] > $pEnd) {
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

        $nameOf = function ($c) use ($list) {
            foreach ($list as $a) {
                if ($a['code'] === $c) {
                    return $a['name'];
                }
            }
            return 'Suspense';
        };

        $openingBal = $led['opening'] + $priorMovement;
        $run = $openingBal;
        $tDr = 0;
        $tCr = 0;
        $openingDate = $pStart === 0 ? '01 Jan' : '01 ' . self::MONTHS[$pStart];
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
            'balance'   => Prototype::fmt($openingBal),
            'isOpening' => true,
        ]];
        foreach ($inPeriod as $e) {
            $run += $e['amount'];
            $isDr = $led['debitNormal'] ? $e['amount'] >= 0 : $e['amount'] < 0;
            $mag  = abs($e['amount']);
            if ($isDr) {
                $tDr += $mag;
            } else {
                $tCr += $mag;
            }
            $rows[] = [
                'date'      => str_pad((string) $e['day'], 2, '0', STR_PAD_LEFT) . ' ' . self::MONTHS[$e['month']],
                'ref'       => $e['ref'],
                'narration' => $e['narration'],
                'source'    => $e['source'],
                'fund'      => $e['fund'],
                'program'   => $e['program'],
                'contra'    => $e['contra'] . ' · ' . $nameOf($e['contra']),
                'debit'     => $isDr ? Prototype::fmt($mag) : '—',
                'credit'    => $isDr ? '—' : Prototype::fmt($mag),
                'balance'   => Prototype::fmt($run),
            ];
        }

        return $this->json([
            'account'        => $acct,
            'accountOptions' => array_map(fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], $leaves),
            'periodOptions'  => Prototype::load('PERIODS'),
            'period'         => $period,
            'fund'           => $fundFilter,
            'program'        => $programFilter,
            'rows'           => $rows,
            'summary' => [
                ['label' => 'Opening balance', 'value' => Prototype::fmt($openingBal), 'note' => $openingDate . ' 2026'],
                ['label' => 'Debits', 'value' => Prototype::fmt($tDr), 'note' => count($inPeriod) . ' postings'],
                ['label' => 'Credits', 'value' => Prototype::fmt($tCr), 'note' => $period],
                ['label' => 'Closing balance', 'value' => Prototype::fmt($run), 'note' => $led['debitNormal'] ? 'debit normal' : 'credit normal'],
            ],
        ]);
    }
}
