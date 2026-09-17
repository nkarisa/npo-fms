<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Lookup tables: source document types, counties, phasing profiles, budget
 * reallocation rules, pay components and statutory rates.
 *
 * Sources: SOURCES, PROFILE, B_RULES, and the rates the Payroll controller applies
 * (app/Controllers/Api/Payroll.php).
 */
class ReferenceDataSeeder extends Seeder
{
    /** Kenya's 47 counties with their official codes (County Governments Act, First Schedule order). */
    private const COUNTIES = ['Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita-Taveta', 'Garissa', 'Wajir',
        'Mandera', 'Marsabit', 'Isiolo', 'Meru', 'Tharaka-Nithi', 'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua',
        'Nyeri', 'Kirinyaga', "Murang'a", 'Kiambu', 'Turkana', 'West Pokot', 'Samburu', 'Trans-Nzoia', 'Uasin Gishu',
        'Elgeyo-Marakwet', 'Nandi', 'Baringo', 'Laikipia', 'Nakuru', 'Narok', 'Kajiado', 'Kericho', 'Bomet', 'Kakamega',
        'Vihiga', 'Bungoma', 'Busia', 'Siaya', 'Kisumu', 'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Nairobi'];

    private const PROFILE_NAMES = ['even' => 'Even', 'peak' => 'Election peak', 'front' => 'Front-loaded', 'back' => 'Back-loaded'];

    private const LEDGER_GROUPS = ['Grant Fund' => 'grant', 'General Fund' => 'general', 'Capital Fund' => 'capital'];

    /** [key, name, kind, taxable, statutory, GL code, benefit basis ('pct' of basic or 'flat'), or null when not a benefit]. */
    private const PAY_COMPONENTS = [
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

    /** The grade scale (the prototype's GRADE_SCALE): [code, band, house allowance % of basic, transport KES]. */
    private const GRADES = [
        ['G1', 'Executive', 30, 60000],
        ['G2', 'Director', 30, 42000],
        ['G3', 'Manager', 30, 27000],
        ['G4', 'Officer', 30, 21000],
        ['G5', 'Assistant officer', 29, 16000],
        ['G6', 'Administrator', 29, 11000],
        ['G7', 'Support', 29, 10000],
    ];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();

        foreach ($ctx->data('SOURCES') as $s) {
            $ctx->remember('document_types', $s['ref'], $ctx->insert('document_types', ['prefix' => $s['ref'], 'name' => $s['name']]));
        }

        foreach (self::COUNTIES as $i => $name) {
            $ctx->insert('counties', ['code' => sprintf('%03d', $i + 1), 'name' => $name]);
        }

        foreach ($ctx->data('PROFILE') as $key => $weights) {
            $ctx->remember('phasing_profiles', $key, $ctx->insert('phasing_profiles', [
                'key' => $key, 'name' => self::PROFILE_NAMES[$key], 'weights' => json_encode($weights), 'created_at' => $now,
            ]));
        }

        foreach ($ctx->data('B_RULES') as $group => $rules) {
            foreach ($rules as $i => $text) {
                $ctx->insert('budget_rules', ['ledger_group' => self::LEDGER_GROUPS[$group], 'sort_order' => $i + 1, 'text' => $text, 'created_at' => $now]);
            }
        }

        // Accounts are seeded later; components are linked to GL codes then.
        foreach (self::PAY_COMPONENTS as $c) {
            [$key, $name, $kind, $taxable, $statutory, $code] = $c;
            $ctx->remember('pay_components', $key, $ctx->insert('pay_components', [
                'key' => $key, 'name' => $name, 'kind' => $kind, 'is_taxable' => (int) $taxable, 'is_statutory' => (int) $statutory,
                'is_benefit' => (int) isset($c[6]), 'basis' => $c[6] ?? null, 'is_active' => 1, 'created_at' => $now,
            ]));
            $ctx->remember('pay_component_accounts', $key, $code);
        }

        foreach (self::GRADES as $i => [$grade, $title, $housePct, $transport]) {
            $id = $ctx->insert('pay_grades', ['code' => $grade, 'title' => $title, 'sort_order' => $i + 1, 'is_active' => 1, 'created_at' => $now]);
            $ctx->db()->table('pay_grade_benefits')->insertBatch([
                ['pay_grade_id' => $id, 'pay_component_id' => $ctx->require('pay_components', 'house_allowance'), 'amount' => $housePct],
                ['pay_grade_id' => $id, 'pay_component_id' => $ctx->require('pay_components', 'transport_allowance'), 'amount' => $transport],
            ]);
        }

        $this->seedStatutoryRates($ctx, $now);
    }

    /**
     * The rates the payroll screen computes with, as dated rows. The prototype does
     * not say when they took effect, so they are dated from the start of FY2026.
     */
    private function seedStatutoryRates(SeedContext $ctx, string $now): void
    {
        $from = '2026-01-01';
        $row  = static fn (string $scheme, int $order, float $lower, ?float $upper, float $pct, ?float $fixed = null) => [
            'scheme' => $scheme, 'band_order' => $order, 'lower_bound' => $lower, 'upper_bound' => $upper,
            'rate_pct' => $pct, 'fixed_amount' => $fixed, 'effective_from' => $from, 'created_at' => $now,
        ];

        // PAYE: PR_BANDS holds band widths; stored here as cumulative bounds.
        $lower = 0.0;
        foreach ($ctx->data('PR_BANDS') as $i => [$width, $rate]) {
            $upper = $width === null ? null : $lower + $width;
            $ctx->insert('statutory_rates', $row('paye', $i + 1, $lower, $upper, $rate * 100));
            $lower = $upper ?? $lower;
        }

        $ctx->insert('statutory_rates', $row('personal_relief', 1, 0, null, 0, 2400));
        $ctx->insert('statutory_rates', $row('nssf', 1, 0, 72000, 6));
        // SHIF is 2.75% of gross with a KES 300 minimum: a fixed band up to the
        // gross at which 2.75% reaches 300, then the percentage.
        $ctx->insert('statutory_rates', $row('shif', 1, 0, round(300 / 0.0275, 2), 0, 300));
        $ctx->insert('statutory_rates', $row('shif', 2, round(300 / 0.0275, 2), null, 2.75));
        $ctx->insert('statutory_rates', $row('housing_levy', 1, 0, null, 1.5));
        $ctx->insert('statutory_rates', $row('nita', 1, 0, null, 0, 50));
    }
}
