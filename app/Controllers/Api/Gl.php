<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ChartRepository;
use App\Repositories\JournalRepository;
use App\Repositories\PeriodRepository;

/**
 * General ledger — the posted lines on one account for a period, filtered by fund,
 * programme and award, with the balance brought forward and a running balance.
 */
class Gl extends BaseApiController
{
    public function index()
    {
        return $this->json($this->ledger());
    }

    /** The ledger as on screen, filters applied, as CSV. */
    public function export()
    {
        $ledger = $this->ledger();
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Account', $ledger['account']['code'] . ' · ' . $ledger['account']['name']]);
        fputcsv($out, ['Period', $ledger['filters']['period']]);
        fputcsv($out, []);
        fputcsv($out, ['Date', 'Reference', 'Narration', 'Source', 'Fund', 'Programme', 'Grant', 'Debit', 'Credit', 'Balance']);
        fputcsv($out, [$ledger['openingDate'], '', 'Opening balance brought forward', '', '', '', '', '', '', $ledger['opening']]);
        foreach ($ledger['rows'] as $r) {
            fputcsv($out, [$r['fullDate'], $r['ref'], $r['narration'], $r['source'], $r['fund'], $r['program'], $r['grant'], $r['debit'], $r['credit'], $r['balance']]);
        }
        fputcsv($out, ['', '', 'Closing balance — ' . $ledger['filters']['period'], '', '', '', '', $ledger['totalDebit'], $ledger['totalCredit'], $ledger['closing']]);
        rewind($out);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="ELOG general ledger ' . $ledger['account']['code'] . '.csv"')
            ->setBody($csv);
    }

    /** The lines of one posted entry, for the entry drawer. */
    public function entry($ref)
    {
        $lines = (new JournalRepository())->entryLines((string) $ref);

        return $lines === []
            ? $this->response->setStatusCode(404)->setJSON(['error' => 'not found'])
            : $this->json(['ref' => $ref, 'lines' => array_map(static fn ($l) => $l + [
                'debitLabel' => Prototype::fmt($l['debit']), 'creditLabel' => Prototype::fmt($l['credit']),
            ], $lines), 'total' => Prototype::fmt(array_sum(array_column($lines, 'debit')))]);
    }

    private function ledger(): array
    {
        $chart  = (new ChartRepository())->accounts();
        $leaves = array_values(array_filter($chart, static fn ($a) => $a['postable'] && $a['status'] === 'Active' && $a['code'] !== ChartRepository::DERIVED_SURPLUS));
        $code   = $this->request->getGet('account') ?: '5110';
        $acct   = current(array_filter($leaves, static fn ($a) => $a['code'] === $code)) ?: $leaves[0];

        $ranges = (new PeriodRepository())->ledgerRanges();
        $period = $this->request->getGet('period');
        $period = isset($ranges[$period]) ? $period : array_key_first($ranges);
        [$from, $to] = $ranges[$period];

        $fund    = $this->request->getGet('fund') ?: 'All funds';
        $program = $this->request->getGet('program') ?: 'All programmes';
        $grant   = $this->request->getGet('grant') ?: 'All awards';
        $q       = strtolower(trim((string) $this->request->getGet('q')));

        $debitNormal = in_array($acct['type'], ['Asset', 'Expense'], true);
        $signed = static fn (array $e) => $debitNormal ? $e['debit'] - $e['credit'] : $e['credit'] - $e['debit'];

        $postings = (new JournalRepository())->postings($acct['code']);
        $opening  = 0.0;
        $prior    = 0.0;
        $inPeriod = [];
        foreach ($postings as $e) {
            // Balances brought forward open the year; postings before the period start open the period.
            if ($e['opening']) {
                $opening += $signed($e);
                continue;
            }
            if ($e['date'] < $from) {
                $prior += $signed($e);
                continue;
            }
            if ($e['date'] > $to
                || ($fund !== 'All funds' && $e['fund'] !== $fund)
                || ($program !== 'All programmes' && $e['program'] !== $program)
                || ($grant !== 'All awards' && $e['grant'] !== $grant)
                || ($q !== '' && !str_contains(strtolower($e['ref'] . ' ' . $e['narration'] . ' ' . $e['source'] . ' ' . $e['grant']), $q))) {
                continue;
            }
            $inPeriod[] = $e;
        }

        $nameOf = static fn (string $c) => current(array_filter($chart, static fn ($a) => $a['code'] === $c))['name'] ?? 'Suspense';
        $openingBalance = $opening + $prior;
        $run = $openingBalance;
        $tDr = 0.0;
        $tCr = 0.0;
        $rows = array_map(function ($e) use (&$run, &$tDr, &$tCr, $signed, $nameOf) {
            $run += $signed($e);
            $tDr += $e['debit'];
            $tCr += $e['credit'];

            return [
                'date' => date('d M', strtotime($e['date'])), 'fullDate' => date('j F Y', strtotime($e['date'])),
                'ref' => $e['ref'], 'narration' => $e['narration'], 'source' => $e['source'],
                'fund' => $e['fund'], 'program' => $e['program'], 'grant' => $e['grant'], 'grantUnassigned' => $e['grantUnassigned'],
                'funder' => $e['funder'], 'preparer' => $e['preparer'], 'approver' => $e['approver'], 'doc' => $e['doc'],
                'archived' => $e['archived'], 'contra' => $e['contra'] === '' ? '' : $e['contra'] . ' · ' . $nameOf($e['contra']),
                'debit' => $e['debit'] > 0 ? Prototype::fmt($e['debit']) : '—',
                'credit' => $e['credit'] > 0 ? Prototype::fmt($e['credit']) : '—',
                'balance' => Prototype::fmt($run),
            ];
        }, $inPeriod);

        $seen = static fn (string $key) => array_values(array_unique(array_column(array_filter($postings, static fn ($e) => !$e['opening']), $key)));
        $awards = [];
        foreach ($inPeriod as $e) {
            $awards[$e['grant']] ??= ['grant' => $e['grant'], 'count' => 0, 'amount' => 0.0];
            $awards[$e['grant']]['count']++;
            $awards[$e['grant']]['amount'] += $e['debit'] + $e['credit'];
        }
        usort($awards, static fn ($a, $b) => $b['count'] <=> $a['count']);
        $withDebits = count(array_filter($inPeriod, static fn ($e) => $e['debit'] > 0));

        return [
            'account' => $acct + ['normal' => $debitNormal ? 'Debit' : 'Credit'],
            'filters' => ['period' => $period, 'fund' => $fund, 'program' => $program, 'grant' => $grant, 'q' => $q],
            'options' => [
                'accounts' => array_map(static fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], $leaves),
                'periods'  => array_keys($ranges),
                'funds'    => array_merge(['All funds'], $seen('fund')),
                'programs' => array_merge(['All programmes'], $seen('program')),
                'grants'   => array_merge(['All awards'], $seen('grant')),
            ],
            'openingDate' => date('d M', strtotime($from)),
            'opening'     => Prototype::fmt($openingBalance),
            'rows'        => $rows,
            'totalDebit'  => Prototype::fmt($tDr),
            'totalCredit' => Prototype::fmt($tCr),
            'closing'     => Prototype::fmt($run),
            'summary' => [
                ['label' => 'Opening balance', 'value' => Prototype::fmt($openingBalance), 'note' => 'as at ' . date('d M Y', strtotime($from))],
                ['label' => 'Debits', 'value' => Prototype::fmt($tDr), 'note' => $withDebits . ' postings'],
                ['label' => 'Credits', 'value' => Prototype::fmt($tCr), 'note' => (count($inPeriod) - $withDebits) . ' postings'],
                ['label' => 'Net movement', 'value' => Prototype::fmt($run - $openingBalance), 'note' => $period],
                ['label' => 'Closing balance', 'value' => Prototype::fmt($run), 'note' => $debitNormal ? 'debit normal' : 'credit normal'],
            ],
            'awards' => count($awards) > 1 ? array_map(static fn ($a) => ['grant' => $a['grant'], 'value' => Prototype::fmt($a['amount'])], $awards) : [],
            'footer' => count($rows) . ' postings · ' . strtolower($acct['type']) . ' account · ' . strtolower($acct['restriction']) . ' · ' . $acct['fund'],
        ];
    }
}
