<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 programmes screen does that the first schema had no place for.
 *
 * Both gaps follow from the same thing: a programme is a coding dimension carried
 * by every posting line, not a record that can be edited freely.
 *
 * - programmes.allocates_out: support costs that belong to no single programme are
 *   collected on one programme and apportioned across the others by the
 *   percentages in cost_share_pct. That programme has no share of its own, so it
 *   held NULL — but so did a closed programme that never had one. The register
 *   could not tell "allocates its costs out" from "takes no share", which is the
 *   difference between a shared-cost base that is complete and one that is short.
 *
 * - programme_renames: a rename changes the label from now on, but a journal
 *   already posted has to keep reading under the name it was recorded under, or a
 *   donor report re-run next year stops agreeing with the one that was filed.
 *   Recording each rename as a dated event makes the name as at any date
 *   derivable without denormalising it onto every posting line. The audit
 *   narrative would not do: it is prose, and reworded prose stops being readable
 *   by anything but a person.
 */
class AlignProgrammes extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering this table on SQLite would rebuild it,
        // which the schema's triggers and views would not survive. The service layer
        // keeps to one programme allocating out.
        $this->forge->addColumn('programmes', ['allocates_out' => $this->bool()]);

        $this->table('programme_renames', [
            'id'           => $this->id(),
            'programme_id' => $this->fk(),
            'from_name'    => $this->string(120),
            'to_name'      => $this->string(120),
            'renamed_on'   => $this->date(),
            'renamed_by'   => $this->fk(true),
            'created_at'   => $this->datetime(),
        ], [
            'keys' => ['programme_id'],
            'fks'  => ['programme_id' => ['programmes', 'CASCADE'], 'renamed_by' => 'users'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['programme_renames']);

        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {programmes} DROP COLUMN allocates_out'));
    }
}
