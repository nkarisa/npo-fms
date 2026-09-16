<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ChartRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

class Coa extends BaseApiController
{
    private const CONTRA_RX = '/accumulated depreciation|provision for|allowance for|impairment/i';

    private const TYPES = ['All', 'Asset', 'Liability', 'Equity', 'Income', 'Expense'];

    private const TYPE_LABEL = ['All' => 'All', 'Asset' => 'Assets', 'Liability' => 'Liabilities', 'Equity' => 'Funds', 'Income' => 'Income', 'Expense' => 'Expenditure'];

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
        $list = (new ChartRepository())->accounts();
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
            'types'     => self::TYPES,
            'typeLabel' => self::TYPE_LABEL,
            'stats' => [
                ['label' => 'Accounts', 'value' => (string) count($list), 'note' => count($leaves) . ' postable'],
                ['label' => 'Entities', 'value' => (string) count((new SettingsRepository())->entities()), 'note' => 'shared master chart'],
                ['label' => 'Restricted funds', 'value' => '3', 'note' => 'grant, capital, endowment'],
                ['label' => 'YTD income', 'value' => Prototype::fmt($income), 'note' => 'KES, all funds'],
                ['label' => 'YTD expenditure', 'value' => Prototype::fmt($expense), 'note' => $income > 0 ? round($expense / $income * 100) . '% of income' : '—'],
            ],
        ]);
    }

    /** Streams the full chart of accounts as a CSV download. */
    public function export()
    {
        $list = (new ChartRepository())->accounts();

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Code', 'Name', 'Level', 'Type', 'Normal', 'Statement', 'Restriction', 'Fund', 'Programme', 'Funder', 'Balance', 'Status']);
        foreach ($list as $a) {
            fputcsv($out, [
                $a['code'],
                $a['name'],
                $a['level'],
                $a['type'],
                self::normal($a),
                self::statement($a),
                $a['restriction'],
                $a['fund'],
                $a['program'],
                $a['funder'],
                $a['balance'],
                $a['status'],
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        // UTF-8 BOM so Excel and other spreadsheet apps don't mangle "—" as ANSI/Windows-1252.
        $csv = "\xEF\xBB\xBF" . $csv;

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="chart-of-accounts-' . date('Y-m-d') . '.csv"')
            ->setBody($csv);
    }

    /** Adds a new account to the chart under its parent. */
    public function create()
    {
        $body = $this->request->getJSON(true) ?? [];

        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));

        if ($code === '' || $name === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Account code and name are required.']);
        }

        try {
            $account = (new ChartRepository())->create($body + ['type' => 'Expense']);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $account['normal']    = self::normal($account);
        $account['statement'] = self::statement($account);

        return $this->response->setStatusCode(201)->setJSON(['account' => $account]);
    }

    /** Updates an existing account's classification and dimensions (the code is immutable). */
    public function update($code)
    {
        $body = $this->request->getJSON(true) ?? [];

        try {
            $account = (new ChartRepository())->update($code, $body);
        } catch (RuleViolation $e) {
            return str_contains($e->getMessage(), 'was not found')
                ? $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()])
                : $this->refused($e);
        }

        $account['normal']    = self::normal($account);
        $account['statement'] = self::statement($account);

        return $this->json(['account' => $account]);
    }

    /** Marks an account as archived; historical postings are retained. */
    public function archive($code)
    {
        try {
            return $this->json(['account' => (new ChartRepository())->archive($code)]);
        } catch (RuleViolation $e) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()]);
        }
    }
}
