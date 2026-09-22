<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 donor reports screen needs that the first schema had no place for.
 *
 * A report's figures are a snapshot of the ledger. The screen says when that
 * snapshot was taken, and a draft can take it again, so the report records it:
 * donor_reports.figures_taken_at. Reports seeded or raised before this carry the
 * date of their "Report generated from ledger" history entry where they have one.
 */
class AlignDonorReports extends SchemaMigration
{
    public function up(): void
    {
        // Added without a constraint: altering the table on SQLite would rebuild it.
        $this->forge->addColumn('donor_reports', [
            'figures_taken_at' => $this->datetime(),
        ]);

        $this->db->query($this->sql(
            "UPDATE {donor_reports} SET figures_taken_at = (
                SELECT MIN(e.occurred_at) FROM {audit_events} e
                WHERE e.object_type = 'donor_report' AND e.object_id = {donor_reports}.id AND e.summary LIKE 'Report generated from ledger%'
            ) WHERE EXISTS (SELECT 1 FROM {donor_report_lines} l WHERE l.donor_report_id = {donor_reports}.id)"
        ));
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {donor_reports} DROP COLUMN figures_taken_at'));
    }
}
