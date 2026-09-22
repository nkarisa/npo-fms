<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The lookups the prototype refers to by name — document types, phasing profiles and
 * pay components — mapped to the rows BaselineSeeder wrote, so the rest of the
 * prototype seeders can resolve them.
 *
 * The lookups themselves, and the counties, budget rules, grade scale and statutory
 * rates beside them, are BaselineSeeder's: they are the same for any instance.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $ctx = SeedContext::get();

        // The lookups themselves are BaselineSeeder's; this takes them as they stand.
        foreach (['document_types' => 'prefix', 'phasing_profiles' => 'key', 'pay_components' => 'key'] as $table => $column) {
            $ctx->adopt($table, $table, $column);
        }

        // Which GL code each pay component posts to. Accounts are seeded later, so the
        // code is remembered here and resolved when payroll is seeded.
        foreach (BaselineSeeder::PAY_COMPONENTS as [$key, , , , , $code]) {
            $ctx->remember('pay_component_accounts', $key, $code);
        }
    }
}
