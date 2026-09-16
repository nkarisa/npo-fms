<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Budget versions (original, revised), their lines and monthly phasing, and
 * reallocations between lines.
 */
class CreateBudgets extends SchemaMigration
{
    public function up(): void
    {
        // Seasonal spend shapes: `weights` is a JSON array of twelve monthly factors.
        $this->table('phasing_profiles', [
            'id'      => $this->id(),
            'key'     => $this->string(20),
            'name'    => $this->string(60),
            'weights' => $this->json(),
        ] + $this->timestamps(), [
            'unique' => ['key'],
        ]);

        $this->table('budget_versions', [
            'id'                  => $this->id(),
            'entity_id'           => $this->fk(),
            'fiscal_year_id'      => $this->fk(),
            'name'                => $this->string(60),
            'based_on_version_id' => $this->fk(true),
            'status'              => $this->string(16, false, 'draft'),
            'prepared_by'         => $this->fk(),
            'approved_by'         => $this->fk(true),
            'approved_at'         => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'fiscal_year_id', 'name']],
            'fks'    => ['entity_id' => 'entities', 'fiscal_year_id' => 'fiscal_years', 'based_on_version_id' => 'budget_versions',
                'prepared_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'status' => $this->in('status', ['draft', 'pending_approval', 'approved', 'superseded']),
                'sod'    => 'approved_by IS NULL OR approved_by <> prepared_by',
            ],
        ]);

        $this->table('budget_lines', [
            'id'                 => $this->id(),
            'budget_version_id'  => $this->fk(),
            'account_id'         => $this->fk(),
            'fund_id'            => $this->fk(),
            'programme_id'       => $this->fk(),
            'grant_id'           => $this->fk(true),
            'cost_group'         => $this->string(40),
            'annual_amount'      => $this->money(),
            'phasing_profile_id' => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['budget_version_id', 'account_id', 'fund_id', 'programme_id', 'grant_id']],
            'keys'   => [['account_id', 'fund_id', 'programme_id']],
            'fks'    => ['budget_version_id' => ['budget_versions', 'CASCADE'], 'account_id' => 'accounts', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants', 'phasing_profile_id' => 'phasing_profiles'],
            'checks' => ['amount' => 'annual_amount >= 0'],
        ]);

        $this->table('budget_phases', [
            'id'             => $this->id(),
            'budget_line_id' => $this->fk(),
            'period_id'      => $this->fk(),
            'amount'         => $this->money(),
        ], [
            'unique' => [['budget_line_id', 'period_id']],
            'keys'   => ['period_id'],
            'fks'    => ['budget_line_id' => ['budget_lines', 'CASCADE'], 'period_id' => 'periods'],
        ]);

        // Rules shown when reallocating within a fund group.
        $this->table('budget_rules', [
            'id'           => $this->id(),
            'ledger_group' => $this->string(10),
            'sort_order'   => $this->int(false, 0, 'SMALLINT'),
            'text'         => $this->text(false),
        ] + $this->timestamps(), [
            'keys'   => ['ledger_group'],
            'checks' => ['group' => $this->in('ledger_group', ['general', 'grant', 'capital', 'endowment'])],
        ]);

        $this->table('budget_reallocations', [
            'id'                => $this->id(),
            'budget_version_id' => $this->fk(),
            'from_line_id'      => $this->fk(),
            'to_line_id'        => $this->fk(),
            'amount'            => $this->money(),
            'reason'            => $this->text(false),
            'status'            => $this->string(16, false, 'pending_approval'),
            'requested_by'      => $this->fk(),
            'approved_by'       => $this->fk(true),
            'approved_at'       => $this->datetime(),
        ] + $this->timestamps(), [
            'keys'   => ['budget_version_id', 'from_line_id', 'to_line_id'],
            'fks'    => ['budget_version_id' => 'budget_versions', 'from_line_id' => 'budget_lines',
                'to_line_id' => 'budget_lines', 'requested_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'lines'  => 'from_line_id <> to_line_id',
                'amount' => 'amount > 0',
                'status' => $this->in('status', ['pending_approval', 'approved', 'rejected']),
                'sod'    => 'approved_by IS NULL OR approved_by <> requested_by',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['budget_reallocations', 'budget_rules', 'budget_phases', 'budget_lines', 'budget_versions',
            'phasing_profiles']);
    }
}
