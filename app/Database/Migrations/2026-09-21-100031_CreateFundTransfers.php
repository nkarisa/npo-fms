<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Inter-fund transfers, which the v5 funds screen records and the first schema had
 * no place for.
 *
 * A transfer moves a balance from one fund to another on a board minute: the
 * General Fund topping up the Capital Fund, say, or a designated reserve being
 * released. It posts as an ordinary journal — the fund balance account of each
 * fund, and the cash the balance is held in — so the ledger stays the only book.
 * What the journal alone cannot say is that it *was* a transfer, and the
 * statement of changes in funds has to show transfers apart from the opening
 * balance, income and expenditure. This table is that record: which fund gave,
 * which received, how much, and the board minute that authorised it.
 *
 * Its status is the journal's — awaiting approval, posted, returned — so it is not
 * held twice. Discarding the returned journal discards the transfer with it.
 */
class CreateFundTransfers extends SchemaMigration
{
    public function up(): void
    {
        $this->table('fund_transfers', [
            'id'           => $this->id(),
            'entity_id'    => $this->fk(),
            // Set as soon as its journal is raised, in the same transaction: the
            // journal names the transfer as its source, so the transfer comes first.
            'journal_id'   => $this->fk(true),
            'from_fund_id' => $this->fk(),
            'to_fund_id'   => $this->fk(),
            'amount'       => $this->money(),
            'board_minute' => $this->string(40),
            'reason'       => $this->text(),
            'requested_by' => $this->fk(),
            'created_at'   => $this->datetime(),
        ], [
            'unique' => ['journal_id'],
            'keys'   => ['from_fund_id', 'to_fund_id'],
            'fks'    => [
                'entity_id' => 'entities', 'journal_id' => ['journals', 'CASCADE'],
                'from_fund_id' => 'funds', 'to_fund_id' => 'funds', 'requested_by' => 'users',
            ],
            'checks' => [
                'funds'  => 'from_fund_id <> to_fund_id',
                'amount' => 'amount > 0',
            ],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['fund_transfers']);
    }
}
