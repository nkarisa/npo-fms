<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ChartRepository;
use App\Repositories\Lookups;
use App\Repositories\RuleViolation;
use App\Libraries\Brand;
use App\Libraries\ChartTemplate;
use App\Repositories\SettingsRepository;

/** The shared chart of accounts: listing, export, import, and adding, editing and archiving accounts. */
class Coa extends BaseApiController
{
    private const TYPES = ['All', 'Asset', 'Liability', 'Equity', 'Income', 'Expense'];

    private const TYPE_LABEL = ['All' => 'All', 'Asset' => 'Assets', 'Liability' => 'Liabilities', 'Equity' => 'Funds', 'Income' => 'Income', 'Expense' => 'Expenditure'];

    /**
     * Adding, changing, importing and archiving an account all need this one
     * permission. It is the Finance Manager's by default, but it is a permission
     * and not a role: any role granted it maintains the chart, and a Finance
     * Manager whose role has it removed no longer can.
     */
    private const PERMISSION = 'chart.manage';

    /** Roll leaf balances up to their headings, following the chart's depth-first order. */
    public static function rollup(array $list): array
    {
        $totals = [];
        $parentOf = static function (int $idx) use ($list) {
            for ($i = $idx - 1; $i >= 0; $i--) {
                if ($list[$i]['level'] < $list[$idx]['level']) {
                    return $i;
                }
            }

            return null;
        };

        foreach ($list as $i => $a) {
            if ($a['level'] !== 2) {
                continue;
            }
            for ($cur = $i; ($p = $parentOf($cur)) !== null; $cur = $p) {
                $totals[$list[$p]['code']] = ($totals[$list[$p]['code']] ?? 0) + $a['balance'];
            }
        }

        return $totals;
    }

    public function index()
    {
        $repo    = new ChartRepository();
        $lookups = new Lookups();
        $list    = $this->rows($repo);
        $leaves  = array_values(array_filter($list, fn ($a) => $a['level'] === 2));
        $income  = array_sum(array_column(array_filter($leaves, fn ($a) => $a['type'] === 'Income'), 'balance'));
        $expense = array_sum(array_column(array_filter($leaves, fn ($a) => $a['type'] === 'Expense'), 'balance'));
        $restrictedGroups = array_values(array_unique(array_map(
            static fn ($f) => $f['ledger_group'],
            array_filter($lookups->funds(), static fn ($f) => $f['status'] === 'active' && $f['ledger_group'] !== 'general')
        )));

        return $this->json([
            'accounts'  => $list,
            'types'     => self::TYPES,
            'typeLabel' => self::TYPE_LABEL,
            'stats' => [
                ['label' => 'Accounts', 'value' => (string) count($list), 'note' => count($leaves) . ' postable'],
                ['label' => 'Entities', 'value' => (string) count((new SettingsRepository())->entities()), 'note' => 'shared master chart'],
                ['label' => 'Restricted funds', 'value' => (string) count($restrictedGroups), 'note' => implode(', ', $restrictedGroups)],
                ['label' => 'YTD income', 'value' => Prototype::fmt($income), 'note' => 'KES, all funds'],
                ['label' => 'YTD expenditure', 'value' => Prototype::fmt($expense), 'note' => $income > 0 ? round($expense / $income * 100) . '% of income' : '—'],
            ],
            'archived'   => count(array_filter($list, fn ($a) => $a['status'] === 'Archived')),
            'lastEdited' => $repo->lastEdited(),
            'canManage'  => $this->canManage(),
            'role'       => $this->actor()['role'],
            'codeLength' => ChartRepository::CODE_LENGTH,
            'options'    => [
                'parents'      => array_merge(['— (top level)'], array_map(fn ($a) => $a['code'] . ' · ' . $a['name'], array_filter($list, fn ($a) => $a['level'] < 2))),
                'types'        => array_slice(self::TYPES, 1),
                'restrictions' => ['Unrestricted', 'Restricted', 'Endowment'],
                'funds'        => array_values(array_intersect_key(['general' => 'General Fund', 'grant' => 'Grant Fund', 'capital' => 'Capital Fund', 'endowment' => 'Endowment Fund'],
                    array_flip(array_column(array_filter($lookups->funds(), static fn ($f) => $f['status'] === 'active'), 'ledger_group')))),
                'programmes'   => array_merge(['Shared'], array_values(array_map(static fn ($p) => $p['name'],
                    array_filter($lookups->programmes(), static fn ($p) => $p['status'] !== 'inactive' && $p['name'] !== 'Shared services')))),
                'grants'       => array_merge(['Unassigned'], array_values(array_map(static fn ($g) => $g['short_name'],
                    array_filter($lookups->grants(), static fn ($g) => in_array($g['status'], ['active', 'closing'], true))))),
                'funders'      => array_merge(['—'], array_values(array_column($lookups->funders(), 'name')), ['Multiple']),
                'currencies'   => [['KES', 'KES — Kenyan Shilling'], ['USD', 'USD — US Dollar'], ['EUR', 'EUR — Euro']],
            ],
        ]);
    }

    /** Streams the chart as CSV, with the screen's type filter and search applied when given. */
    public function export()
    {
        $type = $this->request->getGet('type') ?: 'All';
        $q    = strtolower(trim((string) $this->request->getGet('q')));
        $rows = array_filter($this->rows(new ChartRepository()), static fn ($a) => ($type === 'All' || $a['type'] === $type)
            && ($q === '' || str_contains(strtolower($a['code'] . ' ' . $a['name'] . ' ' . $a['fund'] . ' ' . $a['funder']), $q)));

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Code', 'Account', 'Level', 'Type', 'Normally', 'Statement', 'Restriction', 'Fund', 'Programme', 'Funder', 'Status', 'Balance KES']);
        foreach ($rows as $a) {
            fputcsv($out, [$a['code'], $a['name'], $a['level'], $a['type'], $a['normal'], $a['statement'], $a['restriction'], $a['fund'], $a['program'], $a['funder'], $a['status'], $a['amount']]);
        }
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' chart of accounts.csv"')
            ->setHeader('X-Row-Count', (string) count($rows))
            ->setBody($csv);
    }

    /**
     * Imports accounts from parsed CSV rows. Body: {rows: [...], mode: "update"|"skip",
     * fileName, commit: bool}. Without commit it is a dry run and nothing changes.
     */
    /**
     * The charts an organisation can start from, and what cloning one would do.
     *
     * Passing `template` answers for that one in detail — every account, whether the
     * chart already holds it, and the payroll accounts it would set.
     */
    public function templates()
    {
        $key = trim((string) $this->request->getGet('template'));
        $payload = ['templates' => ChartTemplate::all(), 'canManage' => $this->canManage()];

        if ($key === '') {
            return $this->json($payload);
        }

        try {
            return $this->json($payload + ['plan' => (new ChartRepository())->templatePreview($key)]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** Clones a template into the chart. Body: {template}. Finance Manager only. */
    public function cloneTemplate()
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        try {
            $done = (new ChartRepository())->applyTemplate(
                trim((string) (($this->request->getJSON(true) ?? [])['template'] ?? '')),
                $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $done['name'] . ' cloned — ' . $done['added'] . ($done['added'] === 1 ? ' account' : ' accounts')
                . ' opened at zero'
                . ($done['skipped'] > 0 ? ', ' . $done['skipped'] . ' already held and left as they are' : '')
                . ($done['payroll'] > 0 ? '. ' . $done['payroll'] . ' payroll posting accounts set in Settings' : '')
                . '. Only journals move a balance.',
        ] + $done);
    }

    /**
     * Adopts a template after the organisation has adjusted it. Body:
     * {template, rows: [{code, name?, type?, restriction?, include?}]}. The rows carry
     * the edits — a renamed account, a changed type or restriction, or one left out.
     * Codes are the template's; only what each account says about itself is the
     * organisation's. Finance Manager only.
     */
    public function adopt()
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $body = $this->request->getJSON(true) ?? [];
        $rows = array_values(array_filter((array) ($body['rows'] ?? []), 'is_array'));

        try {
            $done = (new ChartRepository())->adoptTemplate(
                trim((string) ($body['template'] ?? '')),
                $rows,
                $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $done['name'] . ' adopted — ' . $done['added'] . ($done['added'] === 1 ? ' account' : ' accounts')
                . ' opened at zero'
                . ($done['skipped'] > 0 ? ', ' . $done['skipped'] . ' already held and left as they are' : '')
                . ($done['payroll'] > 0 ? '. ' . $done['payroll'] . ' payroll posting accounts set in Settings' : '')
                . '. Only journals move a balance.',
        ] + $done);
    }

    public function import()
    {
        $body = $this->request->getJSON(true) ?? [];
        $rows = array_values(array_filter((array) ($body['rows'] ?? []), 'is_array'));
        $mode = ($body['mode'] ?? 'update') === 'skip' ? 'skip' : 'update';
        $repo = new ChartRepository();

        if (!($body['commit'] ?? false)) {
            return $this->json(['rows' => $repo->importDryRun($rows, $mode)]);
        }
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        try {
            $result = $repo->import($rows, $mode, trim((string) ($body['fileName'] ?? '')), $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($result + ['message' => $result['added'] . ($result['added'] === 1 ? ' account added, ' : ' accounts added, ')
            . $result['updated'] . ($result['updated'] === 1 ? ' account updated.' : ' accounts updated.') . ' Imported accounts open at zero — only journals move a balance.']);
    }

    /** Adds a new account to the chart under its parent. */
    public function create()
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }
        $body = $this->request->getJSON(true) ?? [];
        if (trim((string) ($body['code'] ?? '')) === '' || trim((string) ($body['name'] ?? '')) === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Account code and name are required.']);
        }

        try {
            $account = (new ChartRepository())->create($body + ['type' => 'Expense'], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->response->setStatusCode(201)->setJSON(['account' => $this->withDerived($account)]);
    }

    /** Updates an existing account's classification and dimensions (the code is immutable). */
    public function update($code)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        try {
            $account = (new ChartRepository())->update($code, $this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return str_contains($e->getMessage(), 'was not found')
                ? $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()])
                : $this->refused($e);
        }

        return $this->json(['account' => $this->withDerived($account)]);
    }

    /** Marks an account as archived; historical postings are retained. */
    public function archive($code)
    {
        if (!$this->canManage()) {
            return $this->forbidden();
        }

        try {
            return $this->json(['account' => $this->withDerived((new ChartRepository())->archive($code, $this->actorId()))]);
        } catch (RuleViolation $e) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** The chart with each account's normal side, statement, heading flag and rolled-up amount. */
    private function rows(ChartRepository $repo): array
    {
        $list   = $repo->accounts();
        $totals = self::rollup($list);

        return array_map(function ($a, $i) use ($list, $totals) {
            $isHeader = isset($list[$i + 1]) && $list[$i + 1]['level'] > $a['level'];

            return $this->withDerived($a) + ['isHeader' => $isHeader, 'amount' => $isHeader ? ($totals[$a['code']] ?? 0) : $a['balance']];
        }, $list, array_keys($list));
    }

    private function withDerived(array $a): array
    {
        return $a + ['normal' => ChartRepository::normal($a), 'statement' => ChartRepository::statement($a)];
    }

    private function canManage(): bool
    {
        return in_array(self::PERMISSION, $this->actor()['permissions'] ?? [], true);
    }

    private function forbidden()
    {
        return $this->denied($this->actor()['role'] . ' cannot add, change or archive accounts. That needs a role with ' . self::PERMISSION . '.');
    }
}
