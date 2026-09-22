<?php

namespace App\Libraries;

use App\Repositories\ChartRepository;

/**
 * Charts of accounts an organisation can start from rather than build.
 *
 * A template is a list of accounts, nothing more. Where each sits in the tree is
 * read from its code the way the rest of the chart reads it — 1000 is a top-level
 * heading, 1100 a heading under it, 1110 a postable account under that — so a
 * template states no hierarchy of its own that could disagree with the chart's.
 *
 * Nothing here is imposed. An instance is installed with an empty chart; cloning a
 * template is a choice made on the Chart of accounts screen, it is recorded in the
 * audit log like any other change to the chart, and every account it opens can be
 * renamed, archived or added to afterwards. Accounts a template would duplicate are
 * left alone, so cloning onto a chart that already has some of them fills the gaps
 * rather than overwriting.
 *
 * Each template also names the accounts payroll posts to, because a run's journal
 * needs them and they are not something to leave someone guessing at. Cloning sets
 * only the ones that have no account yet; a mapping already made is never changed.
 */
final class ChartTemplate
{
    /**
     * [code, name, type, restriction] — restriction null means unrestricted.
     *
     * The structure is the one this application is built around: four-digit codes,
     * assets 1, liabilities 2, funds 3, income 4, expenditure 5; contra accounts
     * (accumulated depreciation, allowances) sit with what they reduce.
     */
    private const FULL = [
        ['1000', 'Assets', 'Asset', null],
        ['1100', 'Cash and cash equivalents', 'Asset', null],
        ['1110', 'Bank — current account', 'Asset', null],
        ['1120', 'Bank — donor account', 'Asset', 'restricted'],
        ['1130', 'Mobile money float', 'Asset', null],
        ['1140', 'Petty cash', 'Asset', null],
        ['1200', 'Receivables and prepayments', 'Asset', null],
        ['1210', 'Grants receivable', 'Asset', 'restricted'],
        ['1215', 'Allowance for doubtful debts', 'Asset', null],
        ['1220', 'Staff advances', 'Asset', null],
        ['1230', 'Prepaid expenses', 'Asset', null],
        ['1240', 'Withholding tax recoverable', 'Asset', null],
        ['1300', 'Property and equipment', 'Asset', null],
        ['1310', 'Motor vehicles — cost', 'Asset', null],
        ['1320', 'Office and ICT equipment — cost', 'Asset', null],
        ['1330', 'Furniture and fittings — cost', 'Asset', null],
        ['1390', 'Accumulated depreciation', 'Asset', null],

        ['2000', 'Liabilities', 'Liability', null],
        ['2100', 'Trade and other payables', 'Liability', null],
        ['2110', 'Trade payables', 'Liability', null],
        ['2120', 'Accrued expenses', 'Liability', null],
        ['2130', 'Deferred grant income', 'Liability', 'restricted'],
        ['2200', 'Statutory and payroll liabilities', 'Liability', null],
        ['2210', 'PAYE payable', 'Liability', null],
        ['2220', 'Pension and social security payable', 'Liability', null],
        ['2230', 'Health insurance payable', 'Liability', null],
        ['2240', 'Withholding tax payable', 'Liability', null],
        ['2250', 'Other statutory levies payable', 'Liability', null],
        ['2260', 'Staff deductions payable', 'Liability', null],

        ['3000', 'Funds and reserves', 'Equity', null],
        ['3100', 'Unrestricted fund balance', 'Equity', null],
        ['3200', 'Restricted fund balance', 'Equity', 'restricted'],
        ['3300', 'Capital / endowment fund', 'Equity', 'endowment'],

        ['4000', 'Income', 'Income', null],
        ['4100', 'Grant income', 'Income', null],
        ['4110', 'Institutional grant income', 'Income', 'restricted'],
        ['4120', 'Bilateral donor income', 'Income', 'restricted'],
        ['4130', 'Foundation grant income', 'Income', 'restricted'],
        ['4200', 'Other income', 'Income', null],
        ['4210', 'Membership and contributions', 'Income', null],
        ['4220', 'Training and consultancy income', 'Income', null],
        ['4230', 'Bank interest received', 'Income', null],
        ['4240', 'Foreign exchange gain', 'Income', null],
        ['4250', 'Gain on disposal of assets', 'Income', null],

        ['5000', 'Expenditure', 'Expense', null],
        ['5100', 'Programme costs', 'Expense', null],
        ['5110', 'Field activities', 'Expense', null],
        ['5120', 'Training and workshops', 'Expense', null],
        ['5130', 'Materials and publications', 'Expense', null],
        ['5140', 'Travel and accommodation', 'Expense', null],
        ['5200', 'Personnel costs', 'Expense', null],
        ['5210', 'Salaries and wages', 'Expense', null],
        ['5220', 'Statutory contributions', 'Expense', null],
        ['5230', 'Staff welfare and medical', 'Expense', null],
        ['5300', 'Administration and governance', 'Expense', null],
        ['5310', 'Office rent and utilities', 'Expense', null],
        ['5320', 'Communication and internet', 'Expense', null],
        ['5330', 'Audit, legal and compliance', 'Expense', null],
        ['5340', 'Bank charges', 'Expense', null],
        ['5350', 'Depreciation', 'Expense', null],
        ['5360', 'Loss on disposal of assets', 'Expense', null],
        ['5400', 'Grants to partners', 'Expense', null],
        ['5410', 'Sub-grants to implementing partners', 'Expense', 'restricted'],
        ['5420', 'Partner capacity strengthening', 'Expense', 'restricted'],
    ];

    /** The accounts a smaller organisation is likely to need; a strict subset of FULL. */
    private const COMPACT_CODES = ['1000', '1100', '1110', '1140', '1200', '1210', '1220', '1230',
        '1300', '1320', '1390', '2000', '2100', '2110', '2120', '2130', '2200', '2210', '2220', '2230', '2260',
        '3000', '3100', '3200', '4000', '4100', '4110', '4200', '4210', '4230',
        '5000', '5100', '5110', '5120', '5140', '5200', '5210', '5220', '5300', '5310', '5320', '5330', '5340', '5350'];

    /** Which account each pay component posts to, so a run has somewhere to go. */
    private const PAYROLL = [
        'basic_salary' => '5210', 'house_allowance' => '5210', 'transport_allowance' => '5210',
        'acting_allowance' => '5210', 'nssf_employer' => '5220', 'housing_levy_employer' => '5220', 'nita' => '5220',
        'paye' => '2210', 'nssf_employee' => '2220', 'shif' => '2230',
        'housing_levy_employee' => '2250', 'sacco' => '2260', 'advance_recovery' => '1220',
    ];

    /** @return array<string, array{name: string, note: string}> */
    public static function catalogue(): array
    {
        return [
            'nfp' => [
                'name' => 'Not-for-profit (IFRS)',
                'note' => 'Grant income by funder type, programme and administration costs kept apart, '
                    . 'deferred income and sub-grants to partners. For an organisation reporting to several funders.',
            ],
            'nfp-compact' => [
                'name' => 'Not-for-profit — compact',
                'note' => 'The same structure with the accounts a smaller organisation is unlikely to need left out. '
                    . 'Add to it later; nothing is lost by starting smaller.',
            ],
        ];
    }

    /** @return list<array{key: string, name: string, note: string, accounts: int, postable: int}> */
    public static function all(): array
    {
        $out = [];
        foreach (self::catalogue() as $key => $t) {
            $rows = self::rows($key);
            $out[] = $t + [
                'key' => $key,
                'accounts' => count($rows),
                'postable' => count(array_filter($rows, static fn ($r) => self::isPostable($r['code'], $rows))),
            ];
        }

        return $out;
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::catalogue()[$key]);
    }

    public static function name(string $key): string
    {
        return self::catalogue()[$key]['name'] ?? $key;
    }

    /**
     * A template's accounts, deepest last, so each is created after its parent.
     *
     * @return list<array{code: string, name: string, type: string, restriction: string|null, level: int, parent: string|null}>
     */
    public static function rows(string $key): array
    {
        if (!self::isKnown($key)) {
            return [];
        }

        $rows = self::FULL;
        if ($key === 'nfp-compact') {
            $rows = array_values(array_filter($rows, static fn ($r) => in_array($r[0], self::COMPACT_CODES, true)));
        }

        return array_map(static function ($r) {
            [$code, $name, $type, $restriction] = $r;
            $parent = ChartRepository::parentCodeOf($code);

            return [
                'code' => $code, 'name' => $name, 'type' => $type, 'restriction' => $restriction,
                'level' => $parent === $code ? 0 : (str_ends_with($code, '00') ? 1 : 2),
                'parent' => $parent === $code ? null : $parent,
            ];
        }, $rows);
    }

    /** The pay components a template maps, limited to accounts it actually carries. */
    public static function payroll(string $key): array
    {
        $codes = array_column(self::rows($key), 'code');

        return array_filter(self::PAYROLL, static fn ($code) => in_array($code, $codes, true));
    }

    /** True when nothing in the template sits under this code, so postings can. */
    private static function isPostable(string $code, array $rows): bool
    {
        foreach ($rows as $r) {
            if ($r['parent'] === $code) {
                return false;
            }
        }

        return true;
    }
}
