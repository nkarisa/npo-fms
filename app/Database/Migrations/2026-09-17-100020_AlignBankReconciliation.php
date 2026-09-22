<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 bank reconciliation screen records that the first schema had no
 * place for.
 *
 * - bank_statement_lines.bank_entry: a line the bank raised on its own account
 *   (charges, interest, an exchange gain, an unidentified credit). The book has
 *   nothing to match it to until it is journalised, and the kind decides the
 *   account the journal is raised against.
 *
 * A journal raised from a statement line names the line as its source
 * (source_type 'bank_statement_line'), and is matched to it once it posts.
 * Cash book postings loaded with the ledger whose vouchers live outside the
 * journal register carry source_type 'cash_book' and the bank account's id.
 */
class AlignBankReconciliation extends SchemaMigration
{
    public const BANK_ENTRIES = ['charges', 'interest', 'fx_gain', 'unidentified'];

    public function up(): void
    {
        // Checked by the service layer: adding a CHECK to an existing table means
        // SQLite rebuilding it, which the schema's triggers and views would not survive.
        $this->forge->addColumn('bank_statement_lines', ['bank_entry' => $this->string(16, true)]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        $this->db->query($this->sql('ALTER TABLE {bank_statement_lines} DROP COLUMN bank_entry'));
    }
}
