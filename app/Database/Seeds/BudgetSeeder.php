<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The FY2026 budget: the original and the revised version, each line phased
 * across the twelve periods by its profile.
 *
 * Source: BUDGET ("orig" and "annual") and PROFILE. Actuals are not stored; they
 * come from the ledger (see v_budget_availability).
 */
class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();
        $fy = $ctx->require('fiscal_years', 'FY2026');

        $versions = [
            'orig'   => $ctx->insert('budget_versions', ['entity_id' => $entity, 'fiscal_year_id' => $fy, 'name' => 'Original',
                'status' => 'superseded', 'prepared_by' => $ctx->systemUserId(), 'created_at' => $now]),
        ];
        $versions['annual'] = $ctx->insert('budget_versions', ['entity_id' => $entity, 'fiscal_year_id' => $fy, 'name' => 'Revised',
            'based_on_version_id' => $versions['orig'], 'status' => 'approved', 'prepared_by' => $ctx->systemUserId(), 'created_at' => $now]);

        $profiles = $ctx->data('PROFILE');

        // Procurement's budget lines (PQ_BUDGET_LINES) that the budget does not carry:
        // added to both versions at their stated amount, on the coding of the
        // requisitions that draw on them, phased evenly.
        $budget = $ctx->data('BUDGET');
        $codes = array_column($budget, 'code');
        foreach ($ctx->data('PQ_BUDGET_LINES') as $l) {
            if (in_array($l['code'], $codes, true)) {
                continue;
            }
            $req = current(array_filter($ctx->data('PQ'), static fn ($r) => $r['code'] === $l['code']));
            if ($req === false) {
                continue;
            }
            $budget[] = ['code' => $l['code'], 'group' => 'Capital expenditure', 'fund' => $req['fund'], 'program' => $req['program'], 'grant' => null,
                'orig' => $l['budget'], 'annual' => $l['budget'], 'profile' => 'even', 'requisition' => true];
        }

        foreach ($versions as $field => $version) {
            foreach ($budget as $b) {
                if (isset($b['requisition'])) {
                    // The same coding the requisition is seeded with.
                    $fund  = $ctx->fundId($b['fund'], null, $b['program'], $b['code']);
                    $grant = $ctx->grantOfFund($fund);
                } else {
                    $grant = $ctx->grantId($b['grant']);
                    $fund  = $ctx->fundId($b['fund'], $grant, $b['program'], $b['code']);
                }
                $line  = $ctx->insert('budget_lines', [
                    'budget_version_id' => $version, 'account_id' => $ctx->accountId($b['code']),
                    'fund_id' => $fund, 'programme_id' => $ctx->programmeId($b['program']),
                    'grant_id' => $grant, 'cost_group' => $b['group'], 'annual_amount' => $b[$field],
                    'phasing_profile_id' => $ctx->require('phasing_profiles', $b['profile']), 'created_at' => $now,
                ]);

                $weights = $profiles[$b['profile']];
                $phased  = 0.0;
                foreach ($weights as $m => $weight) {
                    $amount = $m === 11 ? round($b[$field] - $phased, 2) : round($b[$field] * $weight / array_sum($weights), 2);
                    $phased += $amount;
                    $ctx->insert('budget_phases', [
                        'budget_line_id' => $line, 'period_id' => $ctx->periodId(sprintf('2026-%02d-01', $m + 1)), 'amount' => $amount,
                    ]);
                }
            }
        }
    }
}
