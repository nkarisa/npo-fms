<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * bills.unqualified_supplier_reason: why a bill captured straight into payables is
 * paying a supplier whose pre-qualification is not current (not pre-qualified, or
 * lapsed). Such a bill is allowed only up to the three-quote threshold, and only
 * with this reason; above it, the purchase goes through a purchase order, which
 * needs a pre-qualified supplier. Null for every other bill.
 */
class AddBillSupplierReason extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering this table on SQLite would rebuild it,
        // which the schema's triggers and views would not survive.
        $this->forge->addColumn('bills', ['unqualified_supplier_reason' => $this->text()]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {bills} DROP COLUMN unqualified_supplier_reason'));
    }
}
