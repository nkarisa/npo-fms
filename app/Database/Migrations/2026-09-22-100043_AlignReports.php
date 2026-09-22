<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 reports screen needs that the first schema had no place for.
 *
 * Financial statements carry comparatives — the same period a year earlier — and in
 * the first year on this ledger that year was kept in the system it replaced. The
 * opening-balance conversion (CreateOpeningBalances) brings across only where the
 * books stood on the cut-off date; the months before it are not re-posted as
 * journals, which would move every balance the ledger has since built on them.
 *
 * legacy_balances holds those months as figures: for each period of a year kept
 * elsewhere, what each account moved by in each fund (`movement`), plus the
 * balances the year opened with (`opening`, in its first period). The statements
 * read a year from here only when the ledger has nothing posted in it, so a year
 * is never counted twice. Nothing posts here and nothing here posts: it is read by
 * the reports alone, and the year's closing position is meant to equal the balances
 * brought forward into the first year on the ledger.
 */
class AlignReports extends SchemaMigration
{
    public function up(): void
    {
        $this->table('legacy_balances', [
            'id'             => $this->id(),
            'entity_id'      => $this->fk(),
            'fiscal_year_id' => $this->fk(),
            'period_id'      => $this->fk(),
            'account_id'     => $this->fk(),
            'fund_id'        => $this->fk(),
            'kind'           => $this->string(8, false, 'movement'),
            'debit'          => $this->money(),
            'credit'         => $this->money(),
        ] + $this->timestamps(), [
            'unique' => [['period_id', 'account_id', 'fund_id', 'kind']],
            'keys'   => [['entity_id', 'fiscal_year_id'], 'account_id'],
            'fks'    => ['entity_id' => 'entities', 'fiscal_year_id' => 'fiscal_years', 'period_id' => 'periods',
                'account_id' => 'accounts', 'fund_id' => 'funds'],
            'checks' => [
                'kind'     => $this->in('kind', ['opening', 'movement']),
                'one_side' => '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['legacy_balances']);
    }
}
