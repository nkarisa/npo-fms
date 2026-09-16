<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The fund register with each fund's movement from the posted ledger: fund
 * accounts (opening balances and transfers), income and expenditure. The closing
 * balance is the fund's net assets.
 */
final class FundRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $movements = array_column($this->rows('SELECT * FROM {v_fund_balances}'), null, 'fund_id');
            $programmes = [];
            foreach ($this->rows('SELECT fp.fund_id, p.name FROM {fund_programmes} fp JOIN {programmes} p ON p.id = fp.programme_id ORDER BY p.code') as $r) {
                $programmes[(int) $r['fund_id']][] = $r['name'];
            }

            return array_map(function ($f) use ($movements, $programmes) {
                $id    = (int) $f['id'];
                $m     = $movements[$id] ?? ['income' => 0, 'expenditure' => 0, 'transfers_and_opening' => 0];
                $grant = $this->lookups->grantOfFund($id);

                return [
                    'code'       => $f['code'],
                    'name'       => $f['name'],
                    'cls'        => ucfirst($f['restriction']),
                    'funder'     => $f['funder_name'] ?? 'Own income',
                    'grant'      => $f['deed_ref'] ?? ($grant === null ? '—' : $this->lookups->grants()[$grant]['short_name']),
                    'purpose'    => $f['purpose'] ?? '',
                    'period'     => $f['starts_on'] && $f['spend_by'] ? self::dmy($f['starts_on']) . ' – ' . self::dmy($f['spend_by']) : 'Perpetual',
                    'spendBy'    => self::dmy($f['spend_by']),
                    'daysLeft'   => Clock::daysUntil($f['spend_by']) ?? 9999,
                    'conditions' => $f['conditions'] ?? '',
                    'programs'   => $programmes[$id] ?? [],
                    'ledgerFund' => self::FUND_GROUPS[$f['ledger_group']],
                    'opening'    => self::num($m['transfers_and_opening']),
                    'income'     => self::num($m['income']),
                    'spend'      => self::num($m['expenditure']),
                    'transfers'  => 0,
                ];
            }, $this->rows('SELECT f.*, fu.name AS funder_name FROM {funds} f LEFT JOIN {funders} fu ON fu.id = f.funder_id ORDER BY f.code'));
        });
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $f) {
            if ($f['code'] === $code) {
                return $f;
            }
        }

        return null;
    }
}
