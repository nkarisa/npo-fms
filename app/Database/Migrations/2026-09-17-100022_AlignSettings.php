<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 Settings screen edits that the first schema had no place for.
 *
 * - entities: the organisation's registered name, short name, KRA PIN and NGO
 *   Board registration, held on the head office entity.
 * - settings.kind: 'toggle' for the posting controls, 'choice' for the reporting
 *   basis (framework, financial year end, account code length), 'language'
 *   for holding numbers, dates and currency in the reporting locale, and
 *   'appearance' for the interface theme.
 * - currencies: the currencies awards and donor claims may be stated in, with an
 *   indicative rate to the functional currency that seeds a new claim. Disabling
 *   one only removes it from future forms.
 * - pay_components: which earnings are benefits on the grade scale, whether each is
 *   a percentage of basic or a flat amount, and whether it is still offered.
 * - pay_grades and pay_grade_benefits: the grade scale and what each benefit is
 *   worth at each grade. A new appointment takes these; staff already on a grade
 *   keep the figures on their record.
 */
class AlignSettings extends SchemaMigration
{
    public const SETTING_KINDS = ['toggle', 'choice', 'language', 'appearance'];

    public function up(): void
    {
        $this->table('currencies', [
            'id'              => $this->id(),
            'code'            => ['type' => 'CHAR', 'constraint' => 3],
            'name'            => $this->string(60),
            'indicative_rate' => $this->fxRate(),
            'is_active'       => $this->bool(true),
        ] + $this->timestamps(), [
            'unique' => ['code'],
            'checks' => ['rate' => 'indicative_rate > 0'],
        ]);

        $this->table('pay_grades', [
            'id'         => $this->id(),
            'code'       => $this->string(5),
            'title'      => $this->string(60),
            'sort_order' => $this->int(false, 0, 'SMALLINT'),
            'is_active'  => $this->bool(true),
        ] + $this->timestamps(), [
            'unique' => ['code'],
        ]);

        // A percentage of basic for a 'pct' benefit, shillings for a 'flat' one.
        $this->table('pay_grade_benefits', [
            'pay_grade_id'     => $this->fk(),
            'pay_component_id' => $this->fk(),
            'amount'           => $this->money(),
        ], [
            'primary' => ['pay_grade_id', 'pay_component_id'],
            'keys'    => ['pay_component_id'],
            'fks'     => ['pay_grade_id' => ['pay_grades', 'CASCADE'], 'pay_component_id' => 'pay_components'],
            'checks'  => ['amount' => 'amount >= 0'],
        ]);

        // Added without constraints: altering these tables on SQLite would rebuild
        // them, which the schema's triggers and views would not survive. The service
        // layer checks the values.
        $this->forge->addColumn('entities', [
            'registered_name' => $this->string(160, true),
            'short_name'      => $this->string(40, true),
            'tax_pin'         => $this->string(20, true),
            'registration_no' => $this->string(60, true),
        ]);
        $this->forge->addColumn('settings', ['kind' => $this->string(10, false, 'toggle')]);
        $this->forge->addColumn('pay_components', [
            'is_benefit' => $this->bool(),
            'basis'      => $this->string(4, true),
            'is_active'  => $this->bool(true),
        ]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        foreach (['is_benefit', 'basis', 'is_active'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {pay_components} DROP COLUMN {$column}"));
        }
        $this->db->query($this->sql('ALTER TABLE {settings} DROP COLUMN kind'));
        foreach (['registered_name', 'short_name', 'tax_pin', 'registration_no'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {entities} DROP COLUMN {$column}"));
        }
        $this->dropTables(['pay_grade_benefits', 'pay_grades', 'currencies']);
    }
}
