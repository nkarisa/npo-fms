<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Settings the screens edit or show that the first schema had no column for.
 *
 * - accounts: the chart form's currency, description, monthly reconciliation and
 *   donor-report flags, and the grant an account defaults to.
 * - programmes: the programme's share of the shared-cost allocation base.
 * - invoices: the donor's own award reference, kept when the award is not in the
 *   grant register (a new donor, or a reference the donor numbers differently).
 * - locale_area_reviews: the reviewer's assessed coverage of an area. Areas are a
 *   review judgement, not a mapping of strings, so this is recorded, not computed.
 *
 * The new id column is indexed but carries no foreign key: adding a constraint to
 * an existing table means SQLite rebuilding it, which the schema's triggers and
 * views would not survive.
 */
class AddScreenSettings extends SchemaMigration
{
    public function up(): void
    {
        $this->forge->addColumn('accounts', [
            'currency'         => $this->currency(),
            'notes'            => $this->text(),
            'requires_reconciliation' => $this->bool(),
            'in_donor_reports' => $this->bool(true),
            'default_grant_id' => $this->fk(true),
        ]);
        $this->db->query($this->sql('CREATE INDEX {accounts}_default_grant_id ON {accounts} (default_grant_id)'));

        $this->forge->addColumn('programmes', ['cost_share_pct' => $this->pct(true)]);
        $this->forge->addColumn('invoices', ['donor_reference' => $this->string(40, true)]);
        $this->forge->addColumn('locale_area_reviews', ['coverage_pct' => $this->pct(true)]);
    }

    public function down(): void
    {
        $this->db->query($this->sql('DROP INDEX {accounts}_default_grant_id' . ($this->isMySQL() ? ' ON {accounts}' : '')));
        $this->forge->dropColumn('locale_area_reviews', 'coverage_pct');
        $this->forge->dropColumn('invoices', 'donor_reference');
        $this->forge->dropColumn('programmes', 'cost_share_pct');
        $this->forge->dropColumn('accounts', ['currency', 'notes', 'requires_reconciliation', 'in_donor_reports', 'default_grant_id']);
    }
}
