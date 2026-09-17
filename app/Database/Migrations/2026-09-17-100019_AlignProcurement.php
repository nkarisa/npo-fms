<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 procurement screens record that the first schema had no place for.
 *
 * - requisitions: why the purchase is needed, the supplier the requester prefers,
 *   and the single-source justification that stands in for missing quotations
 *   above the three-quote threshold.
 * - goods_received_notes: who signed for delivery. Store staff sign without a
 *   system account, so the name is kept as written; received_by stays the user who
 *   recorded the note.
 *
 * Quotation documents are attachments (object_type 'quotation') and need no column.
 */
class AlignProcurement extends SchemaMigration
{
    public function up(): void
    {
        $this->forge->addColumn('requisitions', [
            'justification'        => $this->text(),
            'preferred_supplier'   => $this->string(120, true),
            'single_source_reason' => $this->text(),
        ]);
        $this->forge->addColumn('goods_received_notes', [
            'received_by_name' => $this->string(120, true),
        ]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite, and the reporting
        // views and triggers that read these tables do not survive the rebuild.
        $this->db->query($this->sql('ALTER TABLE {goods_received_notes} DROP COLUMN received_by_name'));
        foreach (['justification', 'preferred_supplier', 'single_source_reason'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {requisitions} DROP COLUMN {$column}"));
        }
    }
}
