<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * posting_accounts: which account in the chart each automatic posting goes to —
 * trade payables for a bill, the allowance for a doubtful debt, the bank a payroll
 * is paid from. The roles are fixed by the application (PostingAccounts::ROLES);
 * the account each one uses is the organisation's, chosen in Settings → Ledger. A
 * role with no row uses its standard code, so an instance is never without one.
 */
class CreatePostingAccounts extends SchemaMigration
{
    public function up(): void
    {
        $this->table('posting_accounts', [
            'id'         => $this->id(),
            'role'       => $this->string(32),
            'account_id' => $this->fk(),
        ] + $this->timestamps(), [
            'unique' => ['role'],
            'fks'    => ['account_id' => 'accounts'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['posting_accounts']);
    }
}
