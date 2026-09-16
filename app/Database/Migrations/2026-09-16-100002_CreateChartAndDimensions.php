<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Periods, the chart of accounts and the coding dimensions (fund, programme,
 * grant, county) that every posting line carries.
 *
 * The chart, funds, programmes and funders are shared across entities so the
 * group consolidates on one chart; periods and grants belong to an entity.
 * An account's normal balance is derived from its type and name, never stored
 * (README, rule 2).
 */
class CreateChartAndDimensions extends SchemaMigration
{
    public function up(): void
    {
        $this->table('fiscal_years', [
            'id'        => $this->id(),
            'entity_id' => $this->fk(),
            'code'      => $this->string(10),
            'starts_on' => $this->date(),
            'ends_on'   => $this->date(),
            'status'    => $this->string(6, false, 'open'),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'code']],
            'fks'    => ['entity_id' => 'entities'],
            'checks' => [
                'dates'  => 'ends_on > starts_on',
                'status' => $this->in('status', ['open', 'closed']),
            ],
        ]);

        // Closing locks a period against posting; closing and reopening (with its
        // reason and approver) are recorded in audit_events.
        $this->table('periods', [
            'id'             => $this->id(),
            'entity_id'      => $this->fk(),
            'fiscal_year_id' => $this->fk(),
            'code'           => $this->string(7),
            'name'           => $this->string(20),
            'starts_on'      => $this->date(),
            'ends_on'        => $this->date(),
            'status'         => $this->string(6, false, 'open'),
            'closed_by'      => $this->fk(true),
            'closed_at'      => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'code']],
            'keys'   => ['fiscal_year_id', ['entity_id', 'starts_on', 'ends_on']],
            'fks'    => ['entity_id' => 'entities', 'fiscal_year_id' => 'fiscal_years', 'closed_by' => 'users'],
            'checks' => [
                'dates'  => 'ends_on >= starts_on',
                'status' => $this->in('status', ['open', 'closed']),
                'closed' => "status = 'open' OR (closed_by IS NOT NULL AND closed_at IS NOT NULL)",
            ],
        ]);

        $this->table('period_close_steps', [
            'id'           => $this->id(),
            'period_id'    => $this->fk(),
            'step'         => $this->string(20),
            'completed_by' => $this->fk(),
            'completed_at' => $this->datetime(false),
            'note'         => $this->text(),
        ], [
            'unique' => [['period_id', 'step']],
            'fks'    => ['period_id' => ['periods', 'CASCADE'], 'completed_by' => 'users'],
            'checks' => ['step' => $this->in('step', ['bank_reconciled', 'mpesa_reconciled', 'accruals_posted',
                'depreciation_run', 'payroll_posted', 'management_review', 'sign_off'])],
        ]);

        $this->table('funders', [
            'id'               => $this->id(),
            'name'             => $this->string(120),
            'short_name'       => $this->string(60),
            'country'          => $this->string(60, true),
            // Some agreements require reports in a given language (EU in French).
            'report_locale_id' => $this->fk(true),
            'report_note'      => $this->string(255, true),
        ] + $this->timestamps(), [
            'unique' => ['name'],
            'fks'    => ['report_locale_id' => 'locales'],
        ]);

        $this->table('programmes', [
            'id'              => $this->id(),
            'code'            => $this->string(20),
            'name'            => $this->string(120),
            'manager_user_id' => $this->fk(true),
            'status'          => $this->string(10, false, 'active'),
            'started_on'      => $this->date(true),
            'purpose'         => $this->text(),
        ] + $this->timestamps(), [
            'unique' => ['code', 'name'],
            'fks'    => ['manager_user_id' => 'users'],
            'checks' => ['status' => $this->in('status', ['pipeline', 'active', 'inactive'])],
        ]);

        // `restriction` is the IFRS class the statements present; `ledger_group` is
        // the fund column the ledger rolls up to (General, Grant, Capital, Endowment).
        $this->table('funds', [
            'id'           => $this->id(),
            'code'         => $this->string(20),
            'name'         => $this->string(120),
            'restriction'  => $this->string(12),
            'ledger_group' => $this->string(10),
            'funder_id'    => $this->fk(true),
            'purpose'      => $this->text(),
            'deed_ref'     => $this->string(80, true),
            'starts_on'    => $this->date(true),
            'spend_by'     => $this->date(true),
            'conditions'   => $this->text(),
            'status'       => $this->string(8, false, 'active'),
        ] + $this->timestamps(), [
            'unique' => ['code', 'name'],
            'fks'    => ['funder_id' => 'funders'],
            'checks' => [
                'restriction'  => $this->in('restriction', ['unrestricted', 'restricted', 'designated', 'endowment']),
                'ledger_group' => $this->in('ledger_group', ['general', 'grant', 'capital', 'endowment']),
                'status'       => $this->in('status', ['active', 'closed']),
            ],
        ]);

        $this->table('fund_programmes', [
            'fund_id'      => $this->fk(),
            'programme_id' => $this->fk(),
        ], [
            'primary' => ['fund_id', 'programme_id'],
            'keys'    => ['programme_id'],
            'fks'     => ['fund_id' => ['funds', 'CASCADE'], 'programme_id' => ['programmes', 'CASCADE']],
        ]);

        $this->table('counties', [
            'id'   => $this->id(),
            'code' => $this->string(3),
            'name' => $this->string(60),
        ], [
            'unique' => ['code', 'name'],
        ]);

        // Only leaf, active accounts are postable (enforced when a journal posts).
        $this->table('accounts', [
            'id'                   => $this->id(),
            'code'                 => $this->string(10),
            'name'                 => $this->string(120),
            'type'                 => $this->string(10),
            'parent_id'            => $this->fk(true),
            'level'                => $this->int(false, 0, 'SMALLINT'),
            'is_leaf'              => $this->bool(true),
            'restriction'          => $this->string(12, true),
            'default_fund_id'      => $this->fk(true),
            'default_programme_id' => $this->fk(true),
            'funder_id'            => $this->fk(true),
            'status'               => $this->string(8, false, 'active'),
        ] + $this->timestamps(), [
            'unique' => ['code'],
            'keys'   => ['parent_id', 'type'],
            'fks'    => ['parent_id' => 'accounts', 'default_fund_id' => 'funds',
                'default_programme_id' => 'programmes', 'funder_id' => 'funders'],
            'checks' => [
                'type'        => $this->in('type', ['asset', 'liability', 'equity', 'income', 'expense']),
                'restriction' => 'restriction IS NULL OR ' . $this->in('restriction', ['unrestricted', 'restricted', 'endowment']),
                'status'      => $this->in('status', ['active', 'archived']),
                'level'       => 'level >= 0',
            ],
        ]);

        $this->table('grants', [
            'id'              => $this->id(),
            'entity_id'       => $this->fk(),
            'funder_id'       => $this->fk(),
            'award_ref'       => $this->string(40),
            'short_name'      => $this->string(60),
            'title'           => $this->string(255),
            'programme_id'    => $this->fk(true),
            'fund_id'         => $this->fk(true),
            'manager_user_id' => $this->fk(true),
            'currency'        => $this->currency(),
            'value_fc'        => $this->money(),
            'value'           => $this->money(),
            // Null while an award is still a proposal.
            'starts_on'       => $this->date(true),
            'ends_on'         => $this->date(true),
            'status'          => $this->string(10, false, 'pipeline'),
            'retention_years' => $this->int(false, 7, 'SMALLINT'),
        ] + $this->timestamps(), [
            'unique' => ['award_ref'],
            'keys'   => ['entity_id', 'funder_id', 'programme_id', 'fund_id'],
            'fks'    => ['entity_id' => 'entities', 'funder_id' => 'funders', 'programme_id' => 'programmes',
                'fund_id' => 'funds', 'manager_user_id' => 'users'],
            'checks' => [
                'dates'  => 'ends_on >= starts_on',
                'value'  => 'value >= 0 AND value_fc >= 0',
                'status' => $this->in('status', ['pipeline', 'active', 'closing', 'suspended', 'closed']),
            ],
        ]);

        // The award's agreed budget, as the donor sees it.
        $this->table('grant_budget_lines', [
            'id'         => $this->id(),
            'grant_id'   => $this->fk(),
            'account_id' => $this->fk(),
            'name'       => $this->string(120),
            'amount'     => $this->money(),
        ] + $this->timestamps(), [
            'unique' => [['grant_id', 'account_id']],
            'fks'    => ['grant_id' => ['grants', 'CASCADE'], 'account_id' => 'accounts'],
            'checks' => ['amount' => 'amount >= 0'],
        ]);

        // "Due" is derived from expected_on; only the stored states are listed. A
        // tranche on hold or payable "on award" has no date yet.
        $this->table('grant_tranches', [
            'id'          => $this->id(),
            'grant_id'    => $this->fk(),
            'label'       => $this->string(40),
            'expected_on' => $this->date(true),
            'amount'      => $this->money(),
            'status'      => $this->string(10, false, 'scheduled'),
            'received_on' => $this->date(true),
        ] + $this->timestamps(), [
            'keys'   => ['grant_id'],
            'fks'    => ['grant_id' => ['grants', 'CASCADE']],
            'checks' => [
                'amount'   => 'amount > 0',
                'status'   => $this->in('status', ['scheduled', 'received', 'cancelled']),
                'received' => "status <> 'received' OR received_on IS NOT NULL",
            ],
        ]);

        $this->table('grant_conditions', [
            'id'         => $this->id(),
            'grant_id'   => $this->fk(),
            'sort_order' => $this->int(false, 0, 'SMALLINT'),
            'text'       => $this->text(false),
        ] + $this->timestamps(), [
            'keys' => ['grant_id'],
            'fks'  => ['grant_id' => ['grants', 'CASCADE']],
        ]);

        // Source document types and their numbering prefixes (PV, RV, INV, …).
        $this->table('document_types', [
            'id'     => $this->id(),
            'prefix' => $this->string(6),
            'name'   => $this->string(60),
        ], [
            'unique' => ['prefix'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['document_types', 'grant_conditions', 'grant_tranches', 'grant_budget_lines', 'grants',
            'accounts', 'counties', 'fund_programmes', 'funds', 'programmes', 'funders', 'period_close_steps',
            'periods', 'fiscal_years']);
    }
}
