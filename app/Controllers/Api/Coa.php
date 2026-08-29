<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Coa extends BaseApiController
{
    private const CONTRA_RX = '/accumulated depreciation|provision for|allowance for|impairment/i';

    public static function normal(array $a): string
    {
        $debitClass = in_array($a['type'], ['Asset', 'Expense'], true);
        $contra     = (bool) preg_match(self::CONTRA_RX, $a['name'] ?? '');

        return ($debitClass !== $contra) ? 'Debit' : 'Credit';
    }

    public static function statement(array $a): string
    {
        return in_array($a['type'], ['Income', 'Expense'], true) ? 'Income statement' : 'Balance sheet';
    }

    /** Roll leaf balances up to their level 0/1 parents, following list order like the prototype. */
    public static function rollup(array $list): array
    {
        $totals = [];
        $parentOf = static function (int $idx) use ($list) {
            $level = $list[$idx]['level'];
            for ($i = $idx - 1; $i >= 0; $i--) {
                if ($list[$i]['level'] < $level) {
                    return $i;
                }
            }
            return null;
        };

        foreach ($list as $i => $a) {
            if ($a['level'] !== 2) {
                continue;
            }
            $cur = $i;
            $bal = $a['balance'];
            while (($p = $parentOf($cur)) !== null) {
                $code = $list[$p]['code'];
                $totals[$code] = ($totals[$code] ?? 0) + $bal;
                $cur = $p;
            }
        }

        return $totals;
    }

    public function index()
    {
        $list = Prototype::load('SEED');
        $totals = self::rollup($list);

        $rows = array_map(function ($a) {
            $a['normal']    = self::normal($a);
            $a['statement'] = self::statement($a);

            return $a;
        }, $list);

        $leaves  = array_values(array_filter($list, fn ($a) => $a['level'] === 2));
        $income  = array_sum(array_map(fn ($a) => $a['balance'], array_filter($leaves, fn ($a) => $a['type'] === 'Income')));
        $expense = array_sum(array_map(fn ($a) => $a['balance'], array_filter($leaves, fn ($a) => $a['type'] === 'Expense')));

        return $this->json([
            'accounts'  => $rows,
            'rollups'   => $totals,
            'types'     => Prototype::load('TYPES'),
            'typeLabel' => Prototype::load('TYPE_LABEL'),
            'stats' => [
                ['label' => 'Accounts', 'value' => (string) count($list), 'note' => count($leaves) . ' postable'],
                ['label' => 'Entities', 'value' => '5', 'note' => 'shared master chart'],
                ['label' => 'Restricted funds', 'value' => '3', 'note' => 'grant, capital, endowment'],
                ['label' => 'YTD income', 'value' => Prototype::fmt($income), 'note' => 'KES, all funds'],
                ['label' => 'YTD expenditure', 'value' => Prototype::fmt($expense), 'note' => $income > 0 ? round($expense / $income * 100) . '% of income' : '—'],
            ],
        ]);
    }
}
