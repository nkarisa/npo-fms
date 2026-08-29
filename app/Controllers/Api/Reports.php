<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

/** Statement of financial position, activities, cash flows and trial balance — all derived from the chart of accounts. */
class Reports extends BaseApiController
{
    private function acctBal(array $seed, string $code): float
    {
        foreach ($seed as $a) {
            if ($a['code'] === $code) {
                return $a['balance'];
            }
        }
        return 0;
    }

    private function sumCodes(array $seed, array $codes): float
    {
        $t = 0;
        foreach ($codes as $c) {
            $t += $this->acctBal($seed, $c);
        }
        return $t;
    }

    private function nameOf(array $seed, string $code): string
    {
        foreach ($seed as $a) {
            if ($a['code'] === $code) {
                return $a['name'];
            }
        }
        return $code;
    }

    private function line(array $seed, string $code): array
    {
        return ['code' => $code, 'name' => $this->nameOf($seed, $code), 'amount' => Prototype::fmt($this->acctBal($seed, $code))];
    }

    public function index()
    {
        $report = $this->request->getGet('report') ?: 'Statement of financial position';
        $seed   = Prototype::load('SEED');

        if ($report === 'Trial balance') {
            return $this->json($this->trialBalance($seed));
        }
        if ($report === 'Statement of activities') {
            return $this->json($this->activities($seed));
        }
        if ($report === 'Statement of cash flows') {
            return $this->json($this->cashFlows($seed));
        }

        return $this->json($this->financialPosition($seed));
    }

    private function financialPosition(array $seed): array
    {
        $currAssets = ['1110', '1120', '1130', '1140', '1210', '1220', '1230', '1240'];
        $nonCurr    = ['1310', '1320', '1390'];
        $currLiab   = ['2110', '2120', '2130', '2210', '2220', '2230', '2240'];
        $funds      = ['3100', '3200', '3300', '3900'];

        $tca = $this->sumCodes($seed, $currAssets);
        $tnca = $this->sumCodes($seed, $nonCurr);
        $tcl = $this->sumCodes($seed, $currLiab);
        $tf  = $this->sumCodes($seed, $funds);
        $balanced = ($tca + $tnca - $tcl) === $tf;

        return [
            'title' => 'Statement of financial position',
            'sections' => [
                ['heading' => 'Current assets', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $currAssets), 'total' => ['label' => 'Total current assets', 'amount' => Prototype::fmt($tca)]],
                ['heading' => 'Non-current assets', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $nonCurr), 'total' => ['label' => 'Total assets', 'amount' => Prototype::fmt($tca + $tnca)]],
                ['heading' => 'Current liabilities', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $currLiab), 'total' => ['label' => 'Net assets', 'amount' => Prototype::fmt($tca + $tnca - $tcl)]],
                ['heading' => 'Funds and reserves', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $funds), 'total' => ['label' => 'Total funds and reserves', 'amount' => Prototype::fmt($tf)]],
            ],
            'balanced' => $balanced,
            'notes' => [$balanced ? 'Net assets equal total funds and reserves; the statement balances.' : 'Net assets differ from total funds and reserves — the statement does not balance.'],
        ];
    }

    private function activities(array $seed): array
    {
        $incUnres = ['4210', '4220', '4230', '4240'];
        $incRes   = ['4110', '4120', '4130', '4140'];
        $expProg  = ['5110', '5120', '5130', '5140', '5150'];
        $expPers  = ['5210', '5220', '5230'];
        $expAdmin = ['5310', '5320', '5330', '5340', '5350'];
        $expGrants= ['5410', '5420'];

        $tiU = $this->sumCodes($seed, $incUnres);
        $tiR = $this->sumCodes($seed, $incRes);
        $teAll = $this->sumCodes($seed, array_merge($expProg, $expPers, $expAdmin, $expGrants));

        return [
            'title' => 'Statement of activities',
            'sections' => [
                ['heading' => 'Income', 'rows' => array_map(fn ($c) => $this->line($seed, $c), array_merge($incRes, $incUnres)), 'total' => ['label' => 'Total income', 'amount' => Prototype::fmt($tiU + $tiR)]],
                ['heading' => 'Programme expenditure', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $expProg), 'total' => ['label' => 'Total programme costs', 'amount' => Prototype::fmt($this->sumCodes($seed, $expProg))]],
                ['heading' => 'Grants to implementing partners', 'rows' => array_map(fn ($c) => $this->line($seed, $c), $expGrants), 'total' => ['label' => 'Total sub-granting', 'amount' => Prototype::fmt($this->sumCodes($seed, $expGrants))]],
                ['heading' => 'Personnel and administration', 'rows' => array_map(fn ($c) => $this->line($seed, $c), array_merge($expPers, $expAdmin)), 'total' => ['label' => 'Surplus / (deficit) for the period', 'amount' => Prototype::fmt($tiU + $tiR - $teAll)]],
            ],
            'notes' => ['Surplus for the period is ' . Prototype::fmt($tiU + $tiR - $teAll) . '.'],
        ];
    }

    private function cashFlows(array $seed): array
    {
        $surplus = $this->sumCodes($seed, ['4110', '4120', '4130', '4140', '4210', '4220', '4230', '4240'])
            - $this->sumCodes($seed, ['5110', '5120', '5130', '5140', '5150', '5210', '5220', '5230', '5310', '5320', '5330', '5340', '5350', '5410', '5420']);
        $dep = $this->acctBal($seed, '5350');
        $wcRecv = -$this->acctBal($seed, '1210') - $this->acctBal($seed, '1220') - $this->acctBal($seed, '1230');
        $wcPay  = $this->sumCodes($seed, ['2110', '2120', '2130', '2210', '2220', '2230', '2240']);
        $opCash = $surplus + $dep + $wcRecv + $wcPay;
        $invest = -($this->acctBal($seed, '1310') + $this->acctBal($seed, '1320')) * 0.18;
        $finance = $this->acctBal($seed, '3300') * 0.1;
        $netCash = $opCash + $invest + $finance;
        $closingCash = $this->sumCodes($seed, ['1110', '1120', '1130', '1140']);

        return [
            'title' => 'Statement of cash flows',
            'sections' => [
                ['heading' => 'Operating activities', 'rows' => [
                    ['code' => '', 'name' => 'Surplus for the period', 'amount' => Prototype::fmt($surplus)],
                    ['code' => '5350', 'name' => 'Adjustment — depreciation', 'amount' => Prototype::fmt($dep)],
                    ['code' => '', 'name' => 'Movement in receivables and prepayments', 'amount' => Prototype::fmt($wcRecv)],
                    ['code' => '', 'name' => 'Movement in payables and statutory liabilities', 'amount' => Prototype::fmt($wcPay)],
                ], 'total' => ['label' => 'Net cash from operating activities', 'amount' => Prototype::fmt($opCash)]],
                ['heading' => 'Investing activities', 'rows' => [
                    ['code' => '1320', 'name' => 'Purchase of property and equipment', 'amount' => Prototype::fmt($invest)],
                ], 'total' => ['label' => 'Net cash used in investing activities', 'amount' => Prototype::fmt($invest)]],
                ['heading' => 'Financing activities', 'rows' => [
                    ['code' => '3300', 'name' => 'Endowment contributions received', 'amount' => Prototype::fmt($finance)],
                ], 'total' => ['label' => 'Net increase in cash and cash equivalents', 'amount' => Prototype::fmt($netCash)]],
                ['heading' => 'Cash and cash equivalents', 'rows' => [
                    ['code' => '', 'name' => 'Balance at the beginning of the period', 'amount' => Prototype::fmt($closingCash - $netCash)],
                    ['code' => '', 'name' => 'Net increase for the period', 'amount' => Prototype::fmt($netCash)],
                ], 'total' => ['label' => 'Balance at the end of the period', 'amount' => Prototype::fmt($closingCash)]],
            ],
            'notes' => ['Closing cash of ' . Prototype::fmt($closingCash) . ' includes restricted USD grant holdings.'],
        ];
    }

    private function trialBalance(array $seed): array
    {
        $accts = array_values(array_filter($seed, fn ($a) => $a['status'] === 'Active' && ($a['level'] === 2 || $a['type'] === 'Equity') && $a['code'] !== '3000' && $a['code'] !== '3900'));
        $colDr = 0;
        $colCr = 0;
        $rows = [];
        foreach ($accts as $a) {
            $dr = in_array($a['type'], ['Asset', 'Expense'], true);
            $onDebit = $dr ? $a['balance'] >= 0 : $a['balance'] < 0;
            $v = abs($a['balance']);
            if ($onDebit) $colDr += $v; else $colCr += $v;
            $rows[] = ['code' => $a['code'], 'name' => $a['name'], 'debit' => $onDebit ? Prototype::fmt($v) : '', 'credit' => $onDebit ? '' : Prototype::fmt($v)];
        }
        $balanced = $colDr === $colCr;

        return [
            'title' => 'Trial balance',
            'rows' => $rows,
            'totals' => ['debit' => Prototype::fmt($colDr), 'credit' => Prototype::fmt($colCr)],
            'balanced' => $balanced,
            'notes' => [$balanced ? 'Debits and credits both total ' . Prototype::fmt($colDr) . '; the ledger is in balance.' : 'The ledger does not balance and must be investigated.'],
        ];
    }
}
