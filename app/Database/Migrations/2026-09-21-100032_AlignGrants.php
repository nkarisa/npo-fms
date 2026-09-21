<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 grants screen records that the first schema had no place for.
 *
 * - grants.indirect_cap_pct: the share of an award the agreement lets be recovered
 *   as indirect and support costs. Recording an award checks its budget against it
 *   — the administration and governance lines may not exceed it — and the drawer
 *   states it. Until now it lived only in the prose of a condition ("Maximum 8%
 *   indirect cost recovery"), which nothing but a person can check a budget
 *   against. Null where the agreement sets no cap.
 */
class AlignGrants extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering this table on SQLite would rebuild it,
        // which the schema's triggers and views would not survive. The service layer
        // keeps it between 0 and 100.
        $this->forge->addColumn('grants', ['indirect_cap_pct' => $this->pct(true)]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {grants} DROP COLUMN indirect_cap_pct'));
    }
}
