<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * FY2021–FY2026 and their periods, funders, programmes, funds, the chart of accounts,
 * grants and bank accounts.
 *
 * Sources: SEED (chart), FUNDS (including the funds each award may be charged
 * to), PROGS, GRANTS, BR_ACCOUNTS, DREPORTS and DR_FUNDERS
 * (for funder names and grant short names), AR.
 *
 * Every period is seeded open so the ledger can be posted; PeriodCloseSeeder closes
 * the earlier years and January to July 2026 once the journals are in.
 */
class ChartAndDimensionsSeeder extends Seeder
{
    private const LEDGER_GROUPS = ['General Fund' => 'general', 'Grant Fund' => 'grant', 'Capital Fund' => 'capital', 'Endowment Fund' => 'endowment'];

    /** When the chart was last edited, and by whom ("Last edited 12 Aug 2026 by W. Kamau"): the archiving of 1395. */
    private const LAST_CHART_EDIT = ['2026-08-12 10:00:00', 'W. Kamau'];

    /** GL code → bank account detail the prototype gives only in prose. */
    private const BANKS = [
        '1110' => ['kind' => 'bank', 'bank' => 'KCB', 'number' => '1104578921', 'currency' => 'KES'],
        '1120' => ['kind' => 'bank', 'bank' => 'Equity Bank', 'number' => '0410294778', 'currency' => 'USD'],
        '1130' => ['kind' => 'mobile_money', 'bank' => 'Safaricom M-Pesa', 'number' => 'Paybill 509118', 'currency' => 'KES'],
        '1140' => ['kind' => 'petty_cash', 'bank' => null, 'number' => null, 'currency' => 'KES'],
    ];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        $this->seedPeriods($ctx, $entity, $now);
        $this->seedFunders($ctx, $now);

        foreach ($ctx->data('PROGS') as $p) {
            // A live programme with no share of the base is the shared-cost pool:
            // it collects what belongs to no single programme and charges it out
            // each month. A closed programme also holds no share, which is why the
            // two are told apart by a flag rather than by the share being null.
            $allocatesOut = $p['share'] === null && $p['status'] !== 'Inactive';

            $ctx->remember('programmes', $p['name'], $ctx->insert('programmes', [
                'code' => $p['code'], 'name' => $p['name'], 'manager_user_id' => $ctx->userId($p['manager']),
                'status' => strtolower($p['status']), 'started_on' => $ctx->date($p['since']), 'purpose' => $p['purpose'],
                'cost_share_pct' => $p['share'], 'allocates_out' => (int) $allocatesOut, 'created_at' => $now,
            ]));
        }

        $this->seedFunds($ctx, $now);
        $this->seedAccounts($ctx, $now);
        $this->seedGrants($ctx, $entity, $now);

        foreach (self::BANKS as $code => $bank) {
            // PHP turns numeric string keys into integers; account codes are strings.
            $code = (string) $code;
            $account = $this->chartRow($ctx, $code);
            $register = current(array_filter($ctx->data('BR_ACCOUNTS'), static fn ($b) => $b['code'] === $code)) ?: null;
            $ctx->remember('bank_accounts', $code, $ctx->insert('bank_accounts', [
                'entity_id' => $entity, 'account_id' => $ctx->accountId($code), 'name' => $account['name'],
                'short_name' => $register['short'] ?? 'Petty cash', 'kind' => $bank['kind'], 'bank_name' => $bank['bank'],
                'account_number' => $bank['number'], 'currency' => $bank['currency'], 'created_at' => $now,
            ]));
        }

        foreach ($ctx->all('pay_component_accounts') as $key => $code) {
            $ctx->db()->table('pay_components')->where('id', $ctx->require('pay_components', $key))->update(['account_id' => $ctx->accountId($code)]);
        }
    }

    /**
     * FY2026, the year the ledger holds, and the five years before it. The earlier
     * years exist only as locked months with a summary of what was posted in them;
     * PeriodCloseSeeder closes them.
     */
    private function seedPeriods(SeedContext $ctx, int $entity, string $now): void
    {
        for ($year = SeedContext::YEAR - 5; $year <= SeedContext::YEAR; $year++) {
            $fy = $ctx->insert('fiscal_years', ['entity_id' => $entity, 'code' => 'FY' . $year, 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31", 'created_at' => $now]);
            $ctx->remember('fiscal_years', 'FY' . $year, $fy);

            for ($m = 1; $m <= 12; $m++) {
                $start = sprintf('%d-%02d-01', $year, $m);
                $name  = date('M Y', strtotime($start));
                $id    = $ctx->insert('periods', [
                    'entity_id' => $entity, 'fiscal_year_id' => $fy, 'code' => substr($start, 0, 7), 'name' => $name,
                    'starts_on' => $start, 'ends_on' => date('Y-m-t', strtotime($start)), 'created_at' => $now,
                ]);
                $ctx->remember('periods', substr($start, 0, 7), $id);
                $ctx->remember('period_names', $name, $id);
            }
        }
    }

    /** Everyone the data names as a donor or funder. */
    private function seedFunders(SeedContext $ctx, string $now): void
    {
        $names = array_merge(
            array_column($ctx->data('GRANTS'), 'funder'),
            array_column($ctx->data('SEED'), 'funder'),
            array_column($ctx->data('FUNDS'), 'funder'),
            array_column(array_filter($ctx->data('AR'), static fn ($i) => $i['type'] !== 'Other income'), 'donor'),
            array_column($ctx->data('DR_FUNDERS'), 'funder'),
        );
        $languages = array_column($ctx->data('DR_FUNDERS'), null, 'funder');

        foreach (array_unique($names) as $name) {
            if (in_array($name, ['—', 'Multiple', 'Own income'], true)) {
                continue;
            }
            $language = $languages[$name] ?? null;
            $ctx->remember('funders', $name, $ctx->insert('funders', [
                'name' => $name, 'short_name' => $name,
                'report_locale_id' => $language === null ? null : $ctx->require('locales', $language['locale']),
                'report_note' => $language['note'] ?? null, 'created_at' => $now,
            ]));
        }
    }

    private function seedFunds(SeedContext $ctx, string $now): void
    {
        foreach ($ctx->data('FUNDS') as $f) {
            [$starts, $spendBy] = str_contains($f['period'], '–')
                ? array_map([$ctx, 'date'], array_map('trim', explode('–', $f['period'])))
                : [null, null];

            $id = $ctx->insert('funds', [
                'code' => $f['code'], 'name' => $f['name'], 'restriction' => strtolower($f['cls']),
                'ledger_group' => self::LEDGER_GROUPS[$f['ledgerFund']], 'funder_id' => $ctx->lookup('funders', $f['funder']),
                'purpose' => $f['purpose'], 'deed_ref' => $f['cls'] === 'Endowment' ? $f['grant'] : null,
                'starts_on' => $starts, 'spend_by' => $spendBy, 'conditions' => $f['conditions'], 'created_at' => $now,
            ]);
            $ctx->remember('funds', $f['code'], $id);
            $ctx->remember('fund_names', $f['name'], $id);

            if ($f['ledgerFund'] === 'Grant Fund') {
                $ctx->remember('funder_grant_funds', $f['funder'], $id);
            }
        }

        foreach ($ctx->data('FUNDS') as $f) {
            foreach ($f['programs'] as $programme) {
                $ctx->db()->table('fund_programmes')->insert([
                    'fund_id' => $ctx->require('funds', $f['code']), 'programme_id' => $ctx->programmeId($programme),
                ]);
            }
        }

        // A programme's grant fund: PROGS lists funds in order of importance; FUNDS
        // fills in programmes PROGS gives no grant fund for.
        $grantFunds = array_filter($ctx->data('FUNDS'), static fn ($f) => $f['ledgerFund'] === 'Grant Fund');
        $grantFundNames = array_column($grantFunds, 'name');
        foreach ($ctx->data('PROGS') as $p) {
            foreach ($p['funds'] as $fundName) {
                if (in_array($fundName, $grantFundNames, true) && $ctx->lookup('programme_grant_funds', $p['name']) === null) {
                    $ctx->remember('programme_grant_funds', $p['name'], $ctx->require('fund_names', $fundName));
                }
            }
        }
        foreach ($grantFunds as $f) {
            foreach ($f['programs'] as $programme) {
                if ($ctx->lookup('programme_grant_funds', $programme) === null) {
                    $ctx->remember('programme_grant_funds', $programme, $ctx->require('funds', $f['code']));
                }
            }
        }
    }

    private function seedAccounts(SeedContext $ctx, string $now): void
    {
        $chart   = $ctx->data('SEED');
        $parents = [];

        foreach ($chart as $i => $a) {
            $isLeaf = !isset($chart[$i + 1]) || $chart[$i + 1]['level'] <= $a['level'];
            $funderFund = $ctx->lookup('funder_grant_funds', $a['funder']);

            $defaultFund = match (true) {
                $a['fund'] === 'All funds'                     => null,
                $a['restriction'] === 'Endowment'              => $ctx->require('funds', 'FND-400'),
                $a['fund'] === 'Grant Fund'                    => $funderFund,
                default                                        => $ctx->fundId($a['fund']),
            };

            $id = $ctx->insert('accounts', [
                'code' => $a['code'], 'name' => $a['name'], 'type' => strtolower($a['type']),
                'parent_id' => $a['level'] > 0 ? $parents[$a['level'] - 1] : null, 'level' => $a['level'],
                'is_leaf' => (int) $isLeaf, 'restriction' => $a['restriction'] === '—' ? null : strtolower($a['restriction']),
                'default_fund_id' => $defaultFund, 'default_programme_id' => $a['program'] === '—' ? null : $ctx->programmeId($a['program']),
                'funder_id' => $ctx->lookup('funders', $a['funder']), 'status' => strtolower($a['status']),
                // The chart names the currency only in the account name ("… (USD)").
                'currency' => preg_match('/\((USD|EUR)\)$/', $a['name'], $m) === 1 ? $m[1] : 'KES', 'created_at' => $now,
            ]);

            $parents[$a['level']] = $id;
            $ctx->remember('accounts', $a['code'], $id);
            if ($a['status'] === 'Archived') {
                // The chart's last edit, as the prototype's chart footer records it.
                $ctx->insert('audit_events', [
                    'entity_id' => $ctx->entityId(), 'occurred_at' => self::LAST_CHART_EDIT[0], 'actor_user_id' => $ctx->userId(self::LAST_CHART_EDIT[1]),
                    'action' => 'account.archived', 'object_type' => 'account', 'object_id' => $id, 'object_ref' => $a['code'],
                    'summary' => $a['code'] . ' ' . $a['name'] . ' archived',
                ]);
            }
            if ($funderFund !== null) {
                $ctx->remember('account_funds', $a['code'], $funderFund);
            }
        }

        // Accounts the screens post to that the prototype's chart lacks: its
        // receivables write-off names 5340, which the chart holds as bank charges,
        // so bad and doubtful debts get their own line with the allowance against
        // receivables (a contra account, credit balance); and the bank
        // reconciliation posts an unidentified credit to suspense; and an asset
        // donated in kind is income when it comes onto the register.
        foreach (self::EXTRA_ACCOUNTS as [$code, $name, $parent, $fund, $type]) {
            $ctx->remember('accounts', $code, $ctx->insert('accounts', [
                'code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $ctx->require('accounts', $parent), 'level' => 2,
                'is_leaf' => 1, 'restriction' => 'unrestricted', 'default_fund_id' => $ctx->fundId($fund),
                'default_programme_id' => $ctx->programmeId('Shared services'), 'status' => 'active', 'currency' => 'KES', 'created_at' => $now,
            ]));
        }
    }

    /** code, name, parent heading, default fund, type */
    private const EXTRA_ACCOUNTS = [
        ['1215', 'Allowance for doubtful debts', '1200', 'General Fund', 'asset'],
        ['5370', 'Bad and doubtful debts', '5300', 'General Fund', 'expense'],
        ['2190', 'Suspense — unidentified receipts', '2100', 'General Fund', 'liability'],
        ['4260', 'Donated assets (in kind)', '4200', 'Capital Fund', 'income'],
    ];

    private function seedGrants(SeedContext $ctx, int $entity, string $now): void
    {
        // Short names ("USAID / Uraia 2026") as other records refer to grants.
        $shortNames = [];
        // Only grant funds name a grant; the General Fund holds several awards (KAS,
        // NED) and records its grant as "—".
        foreach ($ctx->data('FUNDS') as $f) {
            foreach ($ctx->data('GRANTS') as $g) {
                if ($g['fund'] === $f['name'] && $f['ledgerFund'] === 'Grant Fund') {
                    $shortNames[$g['ref']] = $f['grant'];
                }
            }
        }
        foreach ($ctx->data('DREPORTS') as $r) {
            $shortNames[$r['grantRef']] ??= $r['grant'];
        }

        foreach ($ctx->data('GRANTS') as $g) {
            $fundId = $ctx->lookup('fund_names', $g['fund']);
            $id = $ctx->insert('grants', [
                'entity_id' => $entity, 'funder_id' => $ctx->require('funders', $g['funder']), 'award_ref' => $g['ref'],
                'short_name' => $shortNames[$g['ref']] ?? $g['ref'], 'title' => $g['title'], 'programme_id' => $ctx->programmeId($g['program']),
                'fund_id' => $fundId, 'manager_user_id' => $ctx->userId($g['manager']), 'currency' => 'KES',
                'value_fc' => $g['value'], 'value' => $g['value'], 'starts_on' => $ctx->date($g['start']), 'ends_on' => $ctx->date($g['end']),
                'status' => strtolower($g['status']), 'indirect_cap_pct' => self::indirectCap($g['conditions']), 'created_at' => $now,
            ]);

            $ctx->remember('grants', $g['ref'], $id);
            $ctx->remember('grants', $shortNames[$g['ref']] ?? $g['ref'], $id);
            if ($fundId !== null && $ctx->lookup('grant_funds', (string) $id) === null) {
                $ctx->remember('grant_funds', (string) $id, $fundId);
            }

            foreach ($g['budget'] as $line) {
                $ctx->insert('grant_budget_lines', [
                    'grant_id' => $id, 'account_id' => $ctx->accountId($line['code']), 'name' => $line['name'], 'amount' => $line['budget'], 'created_at' => $now,
                ]);
            }

            foreach ($g['tranches'] as $t) {
                $date = $ctx->date($t['date']);
                $ctx->insert('grant_tranches', [
                    'grant_id' => $id, 'label' => $t['no'], 'expected_on' => $date, 'amount' => $t['amount'],
                    'status' => $t['status'] === 'Received' ? 'received' : 'scheduled',
                    'received_on' => $t['status'] === 'Received' ? $date : null, 'created_at' => $now,
                ]);
            }

            foreach ($g['conditions'] as $i => $text) {
                $ctx->insert('grant_conditions', ['grant_id' => $id, 'sort_order' => $i + 1, 'text' => $text, 'created_at' => $now]);
            }
        }

        // The grant each fund was set up for (FUNDS.grant), used to code ledger lines.
        $allowed = [];
        foreach ($ctx->data('FUNDS') as $f) {
            $grant = $ctx->grantId($f['grant']);
            if ($grant !== null) {
                $fund = $ctx->require('funds', $f['code']);
                $ctx->remember('fund_grants', (string) $fund, $grant);
                $allowed["{$grant}:{$fund}"] = ['grant_id' => $grant, 'fund_id' => $fund];
            }
        }

        // The funds each award may be charged to: those set up for it, and the fund
        // the grant register holds it in (KAS and NED sit in the General Fund).
        foreach ($ctx->data('GRANTS') as $g) {
            $fund = $ctx->lookup('fund_names', $g['fund']);
            if ($fund !== null) {
                $grant = $ctx->require('grants', $g['ref']);
                $allowed["{$grant}:{$fund}"] = ['grant_id' => $grant, 'fund_id' => $fund];
            }
        }

        foreach ($allowed as $row) {
            $ctx->db()->table('grant_funds')->insert($row);
        }
    }

    private function chartRow(SeedContext $ctx, string $code): array
    {
        return current(array_filter($ctx->data('SEED'), static fn ($a) => $a['code'] === $code));
    }

    /**
     * The indirect cost cap an agreement's conditions state ("Maximum 8% indirect
     * cost recovery"), or null where they set none.
     */
    private static function indirectCap(array $conditions): ?float
    {
        foreach ($conditions as $c) {
            if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*indirect/i', $c, $m) === 1) {
                return (float) $m[1];
            }
        }

        return null;
    }
}
