<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Repositories\ReceivablesRepository;
use App\Repositories\Repository;
use CodeIgniter\Database\Seeder;

/**
 * Brings claims the prototype records as written off into the ledger: provided for
 * in full, then the allowance used, as a write-off posts today (INV-26-0020).
 *
 * Runs after every seeder that loads journals, since the entries take the next
 * JV numbers, and before PeriodCloseSeeder closes the month they are dated in.
 */
class RecordedWriteOffSeeder extends Seeder
{
    public function run(): void
    {
        Repository::forget();
        (new ReceivablesRepository($this->db))->bookRecordedWriteOffs(SeedContext::get()->systemUserId());
        Repository::forget();
    }
}
