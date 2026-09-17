<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 advances screen does that the first schema had no place for.
 *
 * The screen carries an advance the whole way — requested, approved or rejected,
 * issued, chased, surrendered against receipts, and finally recovered from pay
 * when nothing comes back. Three of those steps had nowhere to be written down.
 *
 * - advances.rejected_by / rejected_at: a rejection is a decision by a named
 *   person, not an approval. The first schema left it to approved_by, which made
 *   the rejecter read as the approver.
 * - advance_reminders: chasing an unsurrendered advance escalates — the holder,
 *   then the programme director, then the Executive Director for payroll
 *   recovery — so the screen has to know how many have gone and to whom. Counting
 *   them out of the audit narrative would not survive a change of wording.
 * - advance_recoveries.status / period_id: a payroll recovery is agreed before it
 *   happens and then taken over several runs, so a row is scheduled against the
 *   run that will take it and becomes recovered when that run posts. Without
 *   this, the register could not tell an intention from a deduction.
 */
class AlignAdvances extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering these tables on SQLite would rebuild
        // them, which the schema's triggers and views would not survive. The service
        // layer checks the values.
        $this->forge->addColumn('advances', [
            'rejected_by' => $this->fk(true),
            'rejected_at' => $this->datetime(),
        ]);
        $this->forge->addColumn('advance_recoveries', [
            'status'    => $this->string(10, false, 'recovered'),
            'period_id' => $this->fk(true),
        ]);

        // Each chase, and who it went to. The level is what makes the next one
        // an escalation rather than a repeat.
        $this->table('advance_reminders', [
            'id'         => $this->id(),
            'advance_id' => $this->fk(),
            'level'      => $this->int(false, 1, 'SMALLINT'),
            'sent_on'    => $this->date(),
            'sent_to'    => $this->string(120),
            'note'       => $this->string(255, true),
            'sent_by'    => $this->fk(),
            'created_at' => $this->datetime(),
        ], [
            'unique' => [['advance_id', 'level']],
            'fks'    => ['advance_id' => ['advances', 'CASCADE'], 'sent_by' => 'users'],
            'checks' => ['level' => 'level > 0'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['advance_reminders']);

        // Dropped in place: Forge rebuilds the table on SQLite.
        foreach (['status', 'period_id'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {advance_recoveries} DROP COLUMN {$column}"));
        }
        foreach (['rejected_by', 'rejected_at'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {advances} DROP COLUMN {$column}"));
        }
    }
}
