<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Fixed asset register, depreciation, disposals and physical verification.
 *
 * Accumulated depreciation is opening_accumulated_depreciation (for assets brought
 * in from the old register) plus the sum of depreciation_entries; carrying amount
 * and "fully depreciated" are derived from it.
 */
class CreateAssets extends SchemaMigration
{
    public function up(): void
    {
        $this->table('asset_classes', [
            'id'                          => $this->id(),
            'name'                        => $this->string(60),
            'tag_prefix'                  => $this->string(10),
            'useful_life_years'           => $this->int(false, null, 'SMALLINT'),
            'cost_account_id'             => $this->fk(),
            'accumulated_depreciation_account_id' => $this->fk(),
            'depreciation_expense_account_id'     => $this->fk(),
        ] + $this->timestamps(), [
            'unique' => ['name', 'tag_prefix'],
            'fks'    => ['cost_account_id' => 'accounts', 'accumulated_depreciation_account_id' => 'accounts',
                'depreciation_expense_account_id' => 'accounts'],
            'checks' => ['life' => 'useful_life_years > 0'],
        ]);

        $this->table('locations', [
            'id'        => $this->id(),
            'entity_id' => $this->fk(),
            'name'      => $this->string(120),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'name']],
            'fks'    => ['entity_id' => 'entities'],
        ]);

        $this->table('assets', [
            'id'                               => $this->id(),
            'entity_id'                        => $this->fk(),
            'tag'                              => $this->string(30),
            'description'                      => $this->string(255),
            'asset_class_id'                   => $this->fk(),
            'serial_no'                        => $this->string(60, true),
            'acquired_on'                      => $this->date(),
            'cost'                             => $this->money(),
            'residual_value'                   => $this->money(),
            'useful_life_years'                => $this->int(false, null, 'SMALLINT'),
            'opening_accumulated_depreciation' => $this->money(),
            'fund_id'                          => $this->fk(),
            'programme_id'                     => $this->fk(true),
            'grant_id'                         => $this->fk(true),
            // e.g. "Title reverts to USAID at grant close unless disposal is approved".
            'title_condition'                  => $this->text(),
            'location_id'                      => $this->fk(true),
            'custodian'                        => $this->string(120, true),
            'custodian_user_id'                => $this->fk(true),
            'status'                           => $this->string(10, false, 'in_use'),
            'document_ref'                     => $this->string(60, true),
            'acquisition_journal_id'           => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['tag'],
            'keys'   => ['entity_id', 'asset_class_id', 'location_id', 'grant_id', 'status'],
            'fks'    => ['entity_id' => 'entities', 'asset_class_id' => 'asset_classes', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants', 'location_id' => 'locations',
                'custodian_user_id' => 'users', 'acquisition_journal_id' => 'journals'],
            'checks' => [
                'values' => 'cost >= 0 AND residual_value >= 0 AND residual_value <= cost AND opening_accumulated_depreciation >= 0',
                'life'   => 'useful_life_years > 0',
                'status' => $this->in('status', ['in_use', 'in_store', 'disposed']),
            ],
        ]);

        // One run per entity per period, so a retried run cannot depreciate twice.
        $this->table('depreciation_runs', [
            'id'         => $this->id(),
            'entity_id'  => $this->fk(),
            'period_id'  => $this->fk(),
            'status'     => $this->string(6, false, 'draft'),
            'total'      => $this->money(),
            'run_by'     => $this->fk(),
            'posted_at'  => $this->datetime(),
            'journal_id' => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'period_id']],
            'fks'    => ['entity_id' => 'entities', 'period_id' => 'periods', 'run_by' => 'users', 'journal_id' => 'journals'],
            'checks' => ['status' => $this->in('status', ['draft', 'posted'])],
        ]);

        $this->table('depreciation_entries', [
            'id'                   => $this->id(),
            'depreciation_run_id'  => $this->fk(),
            'asset_id'             => $this->fk(),
            'amount'               => $this->money(),
        ], [
            'unique' => [['depreciation_run_id', 'asset_id']],
            'keys'   => ['asset_id'],
            'fks'    => ['depreciation_run_id' => ['depreciation_runs', 'CASCADE'], 'asset_id' => 'assets'],
            'checks' => ['amount' => 'amount >= 0'],
        ]);

        $this->table('asset_disposals', [
            'id'                => $this->id(),
            'asset_id'          => $this->fk(),
            'disposed_on'       => $this->date(),
            'method'            => $this->string(16),
            'proceeds'          => $this->money(),
            'carrying_amount'   => $this->money(),
            'reason'            => $this->text(false),
            // Donor-funded assets need the funder's consent before disposal.
            'donor_consent_ref' => $this->string(80, true),
            'status'            => $this->string(16, false, 'pending_approval'),
            'requested_by'      => $this->fk(),
            'approved_by'       => $this->fk(true),
            'approved_at'       => $this->datetime(),
            'journal_id'        => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['asset_id'],
            'fks'    => ['asset_id' => 'assets', 'requested_by' => 'users', 'approved_by' => 'users', 'journal_id' => 'journals'],
            'checks' => [
                'method' => $this->in('method', ['sale', 'write_off', 'donation', 'return_to_donor', 'trade_in']),
                'status' => $this->in('status', ['pending_approval', 'approved', 'posted', 'rejected']),
                'values' => 'proceeds >= 0 AND carrying_amount >= 0',
                'sod'    => 'approved_by IS NULL OR approved_by <> requested_by',
            ],
        ]);

        $this->table('verification_rounds', [
            'id'          => $this->id(),
            'entity_id'   => $this->fk(),
            'reference'   => $this->string(20),
            'name'        => $this->string(120),
            'location_id' => $this->fk(true),
            'opened_on'   => $this->date(),
            'closed_on'   => $this->date(true),
            'status'      => $this->string(12, false, 'counting'),
            'opened_by'   => $this->fk(),
            'closed_by'   => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'fks'    => ['entity_id' => 'entities', 'location_id' => 'locations', 'opened_by' => 'users', 'closed_by' => 'users'],
            'checks' => ['status' => $this->in('status', ['counting', 'reconciling', 'closed'])],
        ]);

        // A count is evidence: anything other than "sighted" must say what was found.
        // An exception is resolved (written off, impaired, found) before the period closes.
        $this->table('verification_results', [
            'id'                    => $this->id(),
            'verification_round_id' => $this->fk(),
            'asset_id'              => $this->fk(),
            'result'                => $this->string(16),
            'note'                  => $this->text(),
            'counted_by'            => $this->fk(),
            'counted_at'            => $this->datetime(false),
            'resolution'            => $this->string(16, true),
            'resolved_by'           => $this->fk(true),
            'resolved_at'           => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['verification_round_id', 'asset_id']],
            'keys'   => ['asset_id', 'result'],
            'fks'    => ['verification_round_id' => ['verification_rounds', 'CASCADE'], 'asset_id' => 'assets',
                'counted_by' => 'users', 'resolved_by' => 'users'],
            'checks' => [
                'result'     => $this->in('result', ['sighted', 'not_found', 'condition_issue']),
                'note'       => "result = 'sighted' OR (note IS NOT NULL AND note <> '')",
                'resolution' => 'resolution IS NULL OR ' . $this->in('resolution', ['found', 'impaired', 'written_off', 'disposed', 'no_action']),
                'resolved'   => 'resolution IS NULL OR (resolved_by IS NOT NULL AND resolved_at IS NOT NULL)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['verification_results', 'verification_rounds', 'asset_disposals', 'depreciation_entries',
            'depreciation_runs', 'assets', 'locations', 'asset_classes']);
    }
}
