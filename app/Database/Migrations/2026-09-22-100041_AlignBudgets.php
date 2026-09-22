<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 budgets screen does that the first schema had no place for.
 *
 * The screen works on versions: the original, each approved revision, a working
 * revision that reallocations are applied to until it is approved, and a draft for
 * the next financial year derived from what the awards still allow.
 *
 * - budget_versions.submitted_by / submitted_at: a working revision is sent for
 *   approval by a named person, and whoever approves it must be someone else.
 * - budget_versions.returned_note: why an approver sent a version back rather
 *   than approving it. The version goes back to draft; the note is kept.
 * - budget_versions.assumptions: what a derived budget was built on (the uplift on
 *   core lines, whether lines with nothing left were kept), as JSON.
 * - budget_lines.basis / derivation: whether a line was derived from an award's
 *   remaining ceiling or rolled forward from the prior year as core, and how.
 * - budget_reallocations.authority_ref: the reference a revision is made under,
 *   which the screen requires before a movement is applied.
 *
 * The data follows the prototype: the seeded versions are "Original" (approved
 * 12 Dec 2025, now superseded) and "Revision 1", and the back-loaded phasing
 * profile takes the prototype's weights.
 */
class AlignBudgets extends SchemaMigration
{
    private const BACK_LOADED = [0.4, 0.5, 0.6, 0.7, 0.8, 1, 1.2, 1.4, 1.6, 1.8, 2, 2];

    public function up(): void
    {
        // Added without constraints: altering these tables on SQLite would rebuild
        // them, which the schema's triggers and views would not survive. The
        // repository checks the values.
        $this->forge->addColumn('budget_versions', [
            'submitted_by'  => $this->fk(true),
            'submitted_at'  => $this->datetime(),
            'returned_note' => $this->text(),
            'assumptions'   => $this->text(),
        ]);
        $this->forge->addColumn('budget_lines', [
            'basis'      => $this->string(8, true),
            'derivation' => $this->string(255, true),
        ]);
        $this->forge->addColumn('budget_reallocations', [
            'authority_ref' => $this->string(60, true),
        ]);

        $this->db->table('budget_versions')->where('name', 'Revised')->update(['name' => 'Revision 1']);
        $this->db->table('budget_versions')->where('name', 'Original')->where('approved_at', null)
            ->update(['approved_at' => '2025-12-12 10:00:00']);
        $this->db->table('phasing_profiles')->where('key', 'back')->update(['weights' => json_encode(self::BACK_LOADED)]);
    }

    public function down(): void
    {
        $this->db->table('budget_versions')->where('name', 'Revision 1')->update(['name' => 'Revised']);

        // Dropped in place: Forge rebuilds the table on SQLite.
        foreach (['budget_reallocations' => ['authority_ref'], 'budget_lines' => ['basis', 'derivation'],
            'budget_versions' => ['submitted_by', 'submitted_at', 'returned_note', 'assumptions']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->db->query($this->sql("ALTER TABLE {{$table}} DROP COLUMN {$column}"));
            }
        }
    }
}
