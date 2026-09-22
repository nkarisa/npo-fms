<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Recurring journal templates: entries raised on a calendar rather than on an
 * event (the monthly depreciation charge, the support cost allocation, rent and
 * audit fee accruals).
 *
 * A template holds the lines; running it raises an ordinary journal
 * (source_type 'recurring_template') that is approved and posted like any other,
 * so a template never reaches the ledger on its own. Each run is recorded against
 * the template.
 */
class CreateRecurringTemplates extends SchemaMigration
{
    private const TYPES = ['standard', 'accrual', 'reversing', 'allocation', 'adjustment', 'recurring'];

    public function up(): void
    {
        $this->table('recurring_templates', [
            'id'            => $this->id(),
            'entity_id'     => $this->fk(),
            'code'          => $this->string(12),
            'name'          => $this->string(120),
            'type'          => $this->string(12, false, 'recurring'),
            'frequency'     => $this->string(10, false, 'monthly'),
            // "Last day of the month", "First day of the quarter", "25th of the month".
            'rule'          => $this->string(40),
            'next_due'      => $this->date(),
            'last_run_on'   => $this->date(true),
            'owner_user_id' => $this->fk(),
            'status'        => $this->string(8, false, 'active'),
            // Generated entries go straight to approval, or wait as drafts for the owner.
            'auto_submit'   => $this->bool(),
            'document_ref'  => $this->string(60),
            'narration'     => $this->text(false),
            'memo'          => $this->string(255, true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'code']],
            'fks'    => ['entity_id' => 'entities', 'owner_user_id' => 'users'],
            'checks' => [
                'type'      => $this->in('type', self::TYPES),
                'frequency' => $this->in('frequency', ['monthly', 'quarterly', 'annually']),
                'status'    => $this->in('status', ['active', 'paused']),
            ],
        ]);

        $this->table('recurring_template_lines', [
            'id'           => $this->id(),
            'template_id'  => $this->fk(),
            'line_no'      => $this->int(false, null, 'SMALLINT'),
            'account_id'   => $this->fk(),
            'fund_id'      => $this->fk(),
            'programme_id' => $this->fk(),
            'grant_id'     => $this->fk(true),
            'description'  => $this->string(255),
            'debit'        => $this->money(),
            'credit'       => $this->money(),
        ], [
            'unique' => [['template_id', 'line_no']],
            'fks'    => ['template_id' => ['recurring_templates', 'CASCADE'], 'account_id' => 'accounts', 'fund_id' => 'funds',
                'programme_id' => 'programmes', 'grant_id' => 'grants'],
            'checks' => [
                'one_side' => '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)',
            ],
        ]);

        // Each entry a template has generated. A run whose journal is in the ledger
        // reads its status from the journal; a run from before the ledger was
        // migrated, or whose draft was discarded, keeps the status it ended with.
        $this->table('recurring_template_runs', [
            'id'          => $this->id(),
            'template_id' => $this->fk(),
            'run_on'      => $this->date(),
            'journal_id'  => $this->fk(true),
            'reference'   => $this->string(20),
            'status'      => $this->string(16, true),
        ], [
            'keys'   => [['template_id', 'run_on'], 'journal_id'],
            'fks'    => ['template_id' => ['recurring_templates', 'CASCADE'], 'journal_id' => 'journals'],
            'checks' => [
                'status'  => 'status IS NULL OR ' . $this->in('status', ['draft', 'pending_approval', 'posted', 'reversed', 'discarded']),
                'journal' => '(journal_id IS NULL) = (status IS NOT NULL)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['recurring_template_runs', 'recurring_template_lines', 'recurring_templates']);
    }
}
