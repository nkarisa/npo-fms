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

    /** Streams the full chart of accounts as a CSV download. */
    public function export()
    {
        $list = Prototype::load('SEED');

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

    /** Adds a new leaf account to the chart, in code order, and persists it. */
    public function create()
    {
        $body = $this->request->getJSON(true) ?? [];

        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $type = $body['type'] ?? 'Expense';

        if ($code === '' || $name === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Account code and name are required.']);
        }

        $list = Prototype::load('SEED');
        foreach ($list as $a) {
            if ($a['code'] === $code) {
                return $this->response->setStatusCode(422)->setJSON(['error' => $code . ' already exists in the chart of accounts.']);
            }
        }

        $account = [
            'code'        => $code,
            'name'        => $name,
            'level'       => 2,
            'type'        => $type,
            'restriction' => $body['restriction'] ?? 'Unrestricted',
            'fund'        => $body['fund'] ?? 'General Fund',
            'program'     => $body['program'] ?? 'Shared',
            'funder'      => $body['funder'] ?? '—',
            'balance'     => 0,
            'status'      => 'Active',
            'parent'      => $body['parent'] ?? '— (top level)',
            'normal'      => $body['normal'] ?? self::normal(['type' => $type, 'name' => $name]),
            'currency'    => $body['currency'] ?? 'KES',
            'grant'       => $body['grant'] ?? 'Unassigned',
            'notes'       => trim((string) ($body['notes'] ?? '')),
            'postable'    => (bool) ($body['postable'] ?? true),
            'reconcile'   => (bool) ($body['reconcile'] ?? false),
            'donorReport' => (bool) ($body['donorReport'] ?? true),
        ];

        $at = count($list);
        foreach ($list as $i => $a) {
            if ($a['code'] > $code) {
                $at = $i;
                break;
            }
        }
        array_splice($list, $at, 0, [$account]);

        Prototype::save('SEED', $list);

        $account['statement'] = self::statement($account);

        return $this->response->setStatusCode(201)->setJSON(['account' => $account]);
    }

    /** Updates an existing account's classification/dimensions (code and level are immutable). */
    public function update($code)
    {
        $body = $this->request->getJSON(true) ?? [];
        $list = Prototype::load('SEED');

        foreach ($list as $i => $a) {
            if ($a['code'] !== $code) {
                continue;
            }
            $name = trim((string) ($body['name'] ?? $a['name']));
            if ($name === '') {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Account name is required.']);
            }

            $list[$i] = array_merge($a, [
                'name'        => $name,
                'type'        => $body['type'] ?? $a['type'],
                'restriction' => $body['restriction'] ?? $a['restriction'],
                'fund'        => $body['fund'] ?? $a['fund'],
                'program'     => $body['program'] ?? $a['program'],
                'funder'      => $body['funder'] ?? $a['funder'],
                'parent'      => $body['parent'] ?? ($a['parent'] ?? '— (top level)'),
                'normal'      => $body['normal'] ?? ($a['normal'] ?? self::normal($a)),
                'currency'    => $body['currency'] ?? ($a['currency'] ?? 'KES'),
                'grant'       => $body['grant'] ?? ($a['grant'] ?? 'Unassigned'),
                'notes'       => trim((string) ($body['notes'] ?? ($a['notes'] ?? ''))),
                'postable'    => array_key_exists('postable', $body) ? (bool) $body['postable'] : ($a['postable'] ?? true),
                'reconcile'   => array_key_exists('reconcile', $body) ? (bool) $body['reconcile'] : ($a['reconcile'] ?? false),
                'donorReport' => array_key_exists('donorReport', $body) ? (bool) $body['donorReport'] : ($a['donorReport'] ?? true),
            ]);

            Prototype::save('SEED', $list);

            $updated = $list[$i];
            $updated['statement'] = self::statement($updated);

            return $this->json(['account' => $updated]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $code . ' was not found in the chart of accounts.']);
    }

    /** Marks an account as archived; historical postings are retained. */
    public function archive($code)
    {
        $list = Prototype::load('SEED');

        foreach ($list as $i => $a) {
            if ($a['code'] !== $code) {
                continue;
            }
            $list[$i]['status'] = 'Archived';
            Prototype::save('SEED', $list);

            return $this->json(['account' => $list[$i]]);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $code . ' was not found in the chart of accounts.']);
    }
}
