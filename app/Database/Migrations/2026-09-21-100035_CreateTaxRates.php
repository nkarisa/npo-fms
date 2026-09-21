<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;
use App\Libraries\Clock;

/**
 * tax_rates: VAT and the withholding rates a bill may carry, dated so a Finance Act
 * change is a new row from the day it takes effect rather than an edit. A bill
 * takes the rates in force on its invoice date and keeps the amounts it was
 * captured with; nothing already on file is recalculated. Changed in Settings → Taxes.
 *
 * VAT has one rate in force on any day. Withholding has a set — every row in
 * force is a rate a bill may be coded at; nil is always allowed and not held.
 */
class CreateTaxRates extends SchemaMigration
{
    /** In force since the 16% standard rate was restored on 1 January 2021: [tax, %, what it applies to]. */
    private const DEFAULTS = [
        ['vat', 16, 'Standard rate'],
        ['wht', 3, 'Goods, resident'],
        ['wht', 5, 'Professional and management fees'],
        ['wht', 10, 'Rent and royalties'],
    ];

    private const SINCE = '2021-01-01';

    public function up(): void
    {
        $this->table('tax_rates', [
            'id'             => $this->id(),
            'tax'            => $this->string(8),
            'rate_pct'       => $this->pct(),
            'label'          => $this->string(80),
            'effective_from' => $this->date(),
            'effective_to'   => $this->date(true),
        ] + $this->timestamps(), [
            'unique' => [['tax', 'effective_from', 'rate_pct']],
            'checks' => [
                'tax'   => $this->in('tax', ['vat', 'wht']),
                'rate'  => 'rate_pct > 0 AND rate_pct < 100',
                'dates' => 'effective_to IS NULL OR effective_to >= effective_from',
            ],
        ]);

        $now = Clock::timestamp();
        foreach (self::DEFAULTS as [$tax, $pct, $label]) {
            $this->db->table('tax_rates')->insert(['tax' => $tax, 'rate_pct' => $pct, 'label' => $label, 'effective_from' => self::SINCE, 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        $this->dropTables(['tax_rates']);
    }
}
