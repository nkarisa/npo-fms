<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Everything an instance of this application needs before it belongs to anybody:
 * the access model, the coding segments, the document series, and the Kenyan
 * reference data the ledger and payroll are written against.
 *
 *     php spark migrate
 *     php spark db:seed BaselineSeeder
 *     php spark install
 *
 * Nothing here names an organisation. There is no entity, no user, no chart of
 * accounts, no fiscal year and no opening balance — those belong to whoever the
 * instance is being stood up for, and `spark install` asks for them.
 *
 * This is also the first thing DatabaseSeeder runs, so the demonstration database
 * and a real installation share one definition of the reference data rather than
 * two that drift apart. Every row is written through `ensure()`, which leaves an
 * existing row alone, so running it again changes nothing.
 *
 * What is deliberately *not* here:
 *
 * - The chart of accounts. Every organisation's chart is its own; Chart of
 *   accounts → Import loads it, and imported accounts open at zero.
 * - Funds, programmes, funders and grants, which name the money an organisation
 *   actually holds.
 * - Approval rules and the posting-control toggles, which are per-entity and are
 *   written by `spark install` against the head office it creates.
 */
class BaselineSeeder extends Seeder
{
    /** The source language. Others are added in Settings → Language and translation. */
    public const LOCALE = ['code' => 'en-GB', 'label' => 'English (UK)', 'native_name' => 'English',
        'direction' => 'ltr', 'is_source' => 1, 'status' => 'source', 'reviewer' => 'Source strings'];

    /** The roles an instance starts with. Finance Manager is the one that can change settings. */
    public const ROLES = ['Finance Manager', 'Senior Accountant', 'Accountant', 'Programme Officer',
        'Executive Director', 'Auditor (read only)', 'Finance Director', 'Grants Lead'];

    public const PERMISSIONS = [
        'ledger.view'       => 'View the ledger, reports and supporting records',
        'journal.prepare'   => 'Prepare and submit journals and documents',
        'journal.approve'   => 'Approve documents within the role\'s ceiling',
        'journal.post'      => 'Post approved journals to the ledger',
        'requisition.raise' => 'Raise purchase requisitions',
        'payroll.view'      => 'View payroll records (personal data; every read is logged)',
        'settings.manage'   => 'Change organisation, ledger and approval settings',
        'period.close'      => 'Confirm the management review and close a period',
        'period.authorise'  => 'Authorise a period close and reopen a closed period',
        'chart.manage'      => 'Add, change, import and archive accounts in the chart',
        'users.manage'      => 'Invite users, assign their roles and define roles',
    ];

    public const ROLE_PERMISSIONS = [
        'Finance Manager'     => ['ledger.view', 'journal.prepare', 'journal.approve', 'journal.post', 'requisition.raise', 'payroll.view', 'settings.manage', 'period.close', 'chart.manage', 'users.manage'],
        'Executive Director'  => ['ledger.view', 'journal.approve', 'journal.post', 'payroll.view', 'period.authorise'],
        'Senior Accountant'   => ['ledger.view', 'journal.prepare', 'requisition.raise'],
        'Accountant'          => ['ledger.view', 'journal.prepare', 'requisition.raise'],
        'Programme Officer'   => ['requisition.raise'],
        'Auditor (read only)' => ['ledger.view'],
    ];

    /** What a role may approve in one transaction. Null is no ceiling. */
    public const CEILINGS = ['Finance Manager' => 5000000, 'Executive Director' => null];

    /** [code, name, indicative rate to the base currency, active]. Rates are indicative and edited in Settings. */
    public const CURRENCIES = [
        ['KES', 'Kenya Shilling', 1, true],
        ['USD', 'US Dollar', 129.40, true],
        ['EUR', 'Euro', 139.80, true],
        ['DKK', 'Danish Krone', 18.75, true],
        ['GBP', 'Pound Sterling', 163.20, false],
    ];

    /** The coding every posting carries. Examples are worded for an empty instance. */
    public const SEGMENTS = [
        ['fund', 'Fund', 'General, Grant, Capital, Endowment', true, true, 'All postings'],
        ['program', 'Programme / project', 'The programmes the organisation runs', true, true, 'Income and expenditure'],
        ['restriction', 'Restriction class', 'Unrestricted, Restricted, Endowment', true, true, 'All postings'],
        ['grant', 'Grant / award', 'The awards restricted funds are held under', true, true, 'Restricted funds only'],
        ['funder', 'Funder', 'The donors behind the awards', false, true, 'Restricted funds only'],
        ['county', 'County', 'Where field expenditure was incurred', false, false, 'Field expenditure'],
    ];

    /**
     * Source document series. AL is the allocation series JournalRepository issues,
     * and OB the series an opening-balance conversion takes its reference from.
     */
    public const DOCUMENT_TYPES = [
        'PV' => 'Payment voucher', 'RC' => 'Receipt', 'JV' => 'Journal', 'PR' => 'Payroll run',
        'BK' => 'Bank feed', 'MP' => 'M-Pesa batch', 'AC' => 'Accrual', 'AL' => 'Allocation',
        'OB' => 'Opening balance',
    ];

    /** Kenya's 47 counties with their official codes (County Governments Act, First Schedule order). */
    public const COUNTIES = ['Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita-Taveta', 'Garissa', 'Wajir',
        'Mandera', 'Marsabit', 'Isiolo', 'Meru', 'Tharaka-Nithi', 'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua',
        'Nyeri', 'Kirinyaga', "Murang'a", 'Kiambu', 'Turkana', 'West Pokot', 'Samburu', 'Trans-Nzoia', 'Uasin Gishu',
        'Elgeyo-Marakwet', 'Nandi', 'Baringo', 'Laikipia', 'Nakuru', 'Narok', 'Kajiado', 'Kericho', 'Bomet', 'Kakamega',
        'Vihiga', 'Bungoma', 'Busia', 'Siaya', 'Kisumu', 'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Nairobi'];

    /** How a budget line is spread over the year: key => [name, twelve monthly weights]. */
    public const PROFILES = [
        'even'  => ['Even', [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]],
        'peak'  => ['Election peak', [0.5, 0.6, 0.8, 1, 1.4, 1.8, 2.2, 2, 1.4, 0.9, 0.6, 0.5]],
        'front' => ['Front-loaded', [1.8, 1.7, 1.5, 1.3, 1.1, 0.9, 0.8, 0.7, 0.6, 0.6, 0.5, 0.5]],
        'back'  => ['Back-loaded', [0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.1, 1.3, 1.5, 1.6, 1.7, 1.9]],
    ];

    /** What a budget revision must respect, by the fund it moves money within. */
    public const BUDGET_RULES = [
        'grant' => [
            'Reallocation must stay within the same grant agreement.',
            'Movements above 10% of a budget line need written funder consent.',
            'Indirect cost recovery cannot be increased by revision.',
        ],
        'general' => [
            "Reallocation within core costs needs the Finance Manager's authority only.",
            'Movements above KES 2,000,000 require a board minute.',
            'Personnel lines cannot be increased without an approved establishment change.',
        ],
        'capital' => [
            'Capital lines may not be reallocated to recurrent costs.',
            'Asset purchases above KES 1,000,000 require three quotations.',
        ],
    ];

    /** [key, name, kind, taxable, statutory, GL code, benefit basis ('pct' of basic or 'flat') or absent]. */
    public const PAY_COMPONENTS = [
        ['basic_salary', 'Basic salary', 'earning', true, false, '5210'],
        ['house_allowance', 'House allowance', 'earning', true, false, '5210', 'pct'],
        ['transport_allowance', 'Transport allowance', 'earning', true, false, '5210', 'flat'],
        ['acting_allowance', 'Acting allowance', 'earning', true, false, '5210'],
        ['paye', 'PAYE', 'deduction', false, true, '2210'],
        ['nssf_employee', 'NSSF (employee)', 'deduction', false, true, '2220'],
        ['shif', 'SHIF', 'deduction', false, true, '2230'],
        ['housing_levy_employee', 'Affordable housing levy (employee)', 'deduction', false, true, '2250'],
        ['sacco', 'SACCO deduction', 'deduction', false, false, '2260'],
        ['advance_recovery', 'Advance recovery', 'deduction', false, false, '1220'],
        ['nssf_employer', 'NSSF (employer)', 'employer', false, true, '5220'],
        ['housing_levy_employer', 'Affordable housing levy (employer)', 'employer', false, true, '5220'],
        ['nita', 'NITA levy', 'employer', false, true, '5220'],
    ];

    /** [code, band, house allowance % of basic, transport KES]. */
    public const GRADES = [
        ['G1', 'Executive', 30, 60000],
        ['G2', 'Director', 30, 42000],
        ['G3', 'Manager', 30, 27000],
        ['G4', 'Officer', 30, 21000],
        ['G5', 'Assistant officer', 29, 16000],
        ['G6', 'Administrator', 29, 11000],
        ['G7', 'Support', 29, 10000],
    ];

    /** PAYE band widths and rates, lowest band first; a null width is the top band. */
    public const PAYE_BANDS = [[24000, 10], [8333, 25], [467667, 30], [300000, 32.5], [null, 35]];

    /** When the statutory rates below took effect. */
    public const RATES_FROM = '2026-01-01';

    /**
     * The posting controls a new instance starts with: key => [label, note, on].
     * Written against the head office by `spark install`, and changed in
     * Settings → Ledger afterwards.
     */
    public const TOGGLES = [
        'balanced'      => ['Reject unbalanced journals', 'Debits must equal credits before an entry can leave draft. Recommended and expected by auditors.', true],
        'twoPerson'     => ['Enforce the two-person rule', 'The person who prepares an entry can never be the person who approves it, at any value.', true],
        'closedPeriods' => ['Allow posting to closed periods', 'When off, a journal dated in a closed period is rejected. Reopening a period is logged and requires the Finance Manager.', false],
        'backdate'      => ['Allow backdated postings within the open period', 'Permits corrections dated earlier in the same open month.', true],
        'autoNumber'    => ['Auto-number journals and vouchers', 'References are issued in sequence per source type and cannot be reused.', true],
        'budgetCheck'   => ['Block postings that exceed the budget line', 'A hard stop rather than a warning. Blocked postings need a budget revision first.', false],
    ];

    /**
     * The approval policy a new instance starts with:
     * [document type, label, threshold, approver role, escalation role or authority].
     * An escalation that names no role is an authority outside the system, satisfied
     * by recording its reference. Changed in Settings → Approvals.
     */
    public const APPROVAL_RULES = [
        ['journal', 'Journal entries', 500000, 'Finance Manager', 'Executive Director'],
        ['bill', 'Supplier bills', 1000000, 'Finance Manager', 'Executive Director'],
        ['payment_run', 'Payment runs', 2000000, 'Executive Director', 'Board Treasurer'],
        ['subgrant', 'Sub-grants to partners', 0, 'Executive Director', 'Board Treasurer'],
        ['transfer', 'Inter-fund transfers', 0, 'Executive Director', 'Board minute required'],
        ['budget_revision', 'Budget revisions', 2000000, 'Finance Manager', 'Funder consent above 10%'],
        ['payroll_run', 'Payroll runs', 0, 'Executive Director', null],
        ['advance', 'Staff and observer advances', 200000, 'Finance Manager', 'Executive Director'],
    ];

    /**
     * The month-end checklist: key => [label, kind, owner title, permission, note once settled].
     * A `ledger` check is answered from the books; a `confirmation` is ticked by
     * someone holding the permission. Who owns each one is set in the application.
     */
    public const CLOSE_CHECKS = [
        'journals'  => ['Every journal in the period is approved and posted', 'ledger', 'Accountant', null, 'All journals in the period approved, posted and locked'],
        'bills'     => ['Supplier bills for the period are captured and approved', 'ledger', 'Payables', null, 'Every supplier bill captured, approved and settled or accrued'],
        'bank'      => ['Bank accounts reconciled to statement', 'ledger', 'Assistant Accountant', null, 'Every cash account agreed to its statement'],
        'mpesa'     => ['Mobile money float reconciled', 'ledger', 'Assistant Accountant', null, 'The mobile money settlement report agreed to the float account'],
        'payroll'   => ['Payroll posted and statutory deductions accrued', 'ledger', 'Payroll', null, 'Payroll posted with PAYE, NSSF, SHIF, the housing levy and NITA raised as liabilities'],
        'accruals'  => ['Accruals and prepayments raised', 'confirmation', 'Accountant', 'journal.prepare', 'Goods and services received but not invoiced accrued; prepayments carried forward'],
        'disposals' => ['Asset disposals approved and posted', 'ledger', 'Executive Director', null, 'No disposal left unapproved at close'],
        'depn'      => ['Depreciation charged for the month', 'ledger', 'Accountant', null, 'The monthly charge was posted from the asset register'],
        'segments'  => ['Every posting carries its fund, grant and restriction', 'ledger', 'Finance Manager', null, 'Fund, grant and restriction carried on every posting in the period'],
        'donor'     => ['Donor reports reconcile to the ledger', 'ledger', 'Finance Manager', null, 'Every donor report tied to the postings behind it'],
        'budget'    => ['Budget variances explained', 'ledger', 'Finance Manager', null, 'Variances explained; no line left over its approved amount'],
        'tb'        => ['Trial balance in balance', 'ledger', 'System check', null, 'Debits equalled credits across every active account'],
        'review'    => ['Reviewed by the Finance Manager', 'confirmation', 'Finance Manager', 'period.close', 'Management accounts read against budget and the prior month'],
        'signoff'   => ['Authorised by the Executive Director', 'confirmation', 'Executive Director', 'period.authorise', 'Authorised before the period was locked'],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->ensure('locales', ['code' => self::LOCALE['code']], self::LOCALE + ['created_at' => $now]);

        foreach (self::ROLES as $role) {
            $this->ensure('roles', ['name' => $role], [
                'name' => $role, 'is_read_only' => (int) str_contains($role, 'read only'), 'created_at' => $now,
            ]);
        }
        foreach (self::PERMISSIONS as $key => $description) {
            $this->ensure('permissions', ['key' => $key], ['key' => $key, 'description' => $description]);
        }
        foreach (self::ROLE_PERMISSIONS as $role => $keys) {
            foreach ($keys as $key) {
                $this->link('role_permissions', [
                    'role_id' => $this->idOf('roles', ['name' => $role]),
                    'permission_id' => $this->idOf('permissions', ['key' => $key]),
                ]);
            }
        }
        foreach (self::CEILINGS as $role => $ceiling) {
            $roleId = $this->idOf('roles', ['name' => $role]);
            $this->ensure('approval_limits', ['role_id' => $roleId, 'document_type' => null], [
                'role_id' => $roleId, 'document_type' => null, 'ceiling' => $ceiling, 'created_at' => $now,
            ]);
        }

        foreach (self::CURRENCIES as [$code, $name, $rate, $active]) {
            $this->ensure('currencies', ['code' => $code], [
                'code' => $code, 'name' => $name, 'indicative_rate' => $rate, 'is_active' => (int) $active, 'created_at' => $now,
            ]);
        }
        foreach (self::SEGMENTS as [$key, $name, $example, $required, $reported, $applies]) {
            $this->ensure('segments', ['key' => $key], [
                'key' => $key, 'name' => $name, 'example' => $example, 'is_required' => (int) $required,
                'is_reported' => (int) $reported, 'applies_to' => $applies, 'created_at' => $now,
            ]);
        }
        foreach (self::DOCUMENT_TYPES as $prefix => $name) {
            $this->ensure('document_types', ['prefix' => $prefix], ['prefix' => $prefix, 'name' => $name]);
        }
        foreach (self::COUNTIES as $i => $name) {
            $this->ensure('counties', ['code' => sprintf('%03d', $i + 1)], ['code' => sprintf('%03d', $i + 1), 'name' => $name]);
        }
        foreach (self::PROFILES as $key => [$name, $weights]) {
            $this->ensure('phasing_profiles', ['key' => $key], [
                'key' => $key, 'name' => $name, 'weights' => json_encode($weights), 'created_at' => $now,
            ]);
        }
        foreach (self::BUDGET_RULES as $group => $rules) {
            foreach ($rules as $i => $text) {
                $this->ensure('budget_rules', ['ledger_group' => $group, 'sort_order' => $i + 1], [
                    'ledger_group' => $group, 'sort_order' => $i + 1, 'text' => $text, 'created_at' => $now,
                ]);
            }
        }

        $this->payroll($now);
        $this->closeChecks($now);
    }

    /** Pay components, the grade scale and the statutory rates payroll computes with. */
    private function payroll(string $now): void
    {
        foreach (self::PAY_COMPONENTS as $c) {
            [$key, $name, $kind, $taxable, $statutory] = $c;
            $this->ensure('pay_components', ['key' => $key], [
                'key' => $key, 'name' => $name, 'kind' => $kind, 'is_taxable' => (int) $taxable, 'is_statutory' => (int) $statutory,
                'is_benefit' => (int) isset($c[6]), 'basis' => $c[6] ?? null, 'is_active' => 1, 'created_at' => $now,
            ]);
        }

        foreach (self::GRADES as $i => [$grade, $title, $housePct, $transport]) {
            $gradeId = $this->ensure('pay_grades', ['code' => $grade], [
                'code' => $grade, 'title' => $title, 'sort_order' => $i + 1, 'is_active' => 1, 'created_at' => $now,
            ]);
            foreach (['house_allowance' => $housePct, 'transport_allowance' => $transport] as $key => $amount) {
                $this->link('pay_grade_benefits', [
                    'pay_grade_id' => $gradeId, 'pay_component_id' => $this->idOf('pay_components', ['key' => $key]),
                ], ['amount' => $amount]);
            }
        }

        $band = fn (string $scheme, int $order, float $lower, ?float $upper, float $pct, ?float $fixed = null) => $this->ensure(
            'statutory_rates',
            ['scheme' => $scheme, 'band_order' => $order, 'effective_from' => self::RATES_FROM],
            ['scheme' => $scheme, 'band_order' => $order, 'lower_bound' => $lower, 'upper_bound' => $upper,
                'rate_pct' => $pct, 'fixed_amount' => $fixed, 'effective_from' => self::RATES_FROM, 'created_at' => $now]
        );

        // PAYE is held as cumulative bounds; the constant states band widths.
        $lower = 0.0;
        foreach (self::PAYE_BANDS as $i => [$width, $rate]) {
            $upper = $width === null ? null : $lower + $width;
            $band('paye', $i + 1, $lower, $upper, $rate);
            $lower = $upper ?? $lower;
        }

        $band('personal_relief', 1, 0, null, 0, 2400);
        $band('nssf', 1, 0, 72000, 6);
        // SHIF is 2.75% of gross with a KES 300 minimum: a fixed band up to the gross
        // at which 2.75% reaches 300, then the percentage.
        $band('shif', 1, 0, round(300 / 0.0275, 2), 0, 300);
        $band('shif', 2, round(300 / 0.0275, 2), null, 2.75);
        $band('housing_levy', 1, 0, null, 1.5);
        $band('nita', 1, 0, null, 0, 50);
    }

    private function closeChecks(string $now): void
    {
        $order = 0;
        foreach (self::CLOSE_CHECKS as $key => [$label, $kind, $title, $permission, $settled]) {
            $this->ensure('period_close_checks', ['key' => $key], [
                'key' => $key, 'sort_order' => ++$order, 'label' => $label, 'kind' => $kind, 'owner_user_id' => null,
                'owner_title' => $title, 'permission' => $permission, 'settled_note' => $settled, 'created_at' => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** Inserts the row unless one already matches `$key`, and returns its id either way. */
    private function ensure(string $table, array $key, array $row): int
    {
        $existing = $this->idOf($table, $key);
        if ($existing !== null) {
            return $existing;
        }
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID() ?: (int) $this->idOf($table, $key);
    }

    /** The same, for a link table keyed by its columns rather than an id. */
    private function link(string $table, array $key, array $extra = []): void
    {
        if (!$this->matches($table, $key)->countAllResults()) {
            $this->db->table($table)->insert($key + $extra);
        }
    }

    private function idOf(string $table, array $key): ?int
    {
        $row = $this->matches($table, $key)->select('id')->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function matches(string $table, array $key)
    {
        $builder = $this->db->table($table);
        foreach ($key as $column => $value) {
            $value === null ? $builder->where($column . ' IS NULL', null, false) : $builder->where($column, $value);
        }

        return $builder;
    }
}
