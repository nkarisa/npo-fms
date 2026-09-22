<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Loading bank and M-Pesa statements from the CSV files the banks issue.
 *
 * - bank_statement_formats: how one bank's CSV maps onto a statement line — the
 *   columns holding the date, reference, description, amount and running balance,
 *   the date and number styles, and the rules that recognise the bank's own
 *   entries (charges, interest). A built-in format (M-Pesa) cannot be edited, only
 *   copied. Columns are named by their header, so a bank adding a column does not
 *   break the mapping.
 * - bank_accounts.statement_format_id: the format a cash account's statements use.
 * - bank_statement_imports: one uploaded file — who loaded it, what it held, and
 *   what was skipped as a duplicate or outside the period. The file itself is an
 *   attachment (object_type 'bank_statement_import').
 * - bank_statements.source: 'seed', or 'upload' once a file has been loaded.
 * - bank_statement_lines: the running balance the bank printed, and the upload
 *   the line came from. import_hash already stops a line loading twice.
 */
class CreateBankStatementImports extends SchemaMigration
{
    public const LAYOUTS = ['signed', 'split', 'indicator'];

    public function up(): void
    {
        $this->table('bank_statement_formats', [
            'id'                  => $this->id(),
            'entity_id'           => $this->fk(),
            'name'                => $this->string(80),
            'is_builtin'          => $this->bool(),
            'delimiter'           => $this->string(3, false, ','),
            'date_column'         => $this->string(80),
            'date_format'         => $this->string(60),
            'reference_column'    => $this->string(80, true),
            'description_columns' => $this->json(),
            'amount_layout'       => $this->string(10, false, 'signed'),
            'amount_column'       => $this->string(80, true),
            'debit_column'        => $this->string(80, true),
            'credit_column'       => $this->string(80, true),
            'indicator_column'    => $this->string(80, true),
            'credit_indicator'    => $this->string(10, true),
            'balance_column'      => $this->string(80, true),
            'decimal_mark'        => $this->string(1, false, '.'),
            'status_column'       => $this->string(80, true),
            'status_value'        => $this->string(40, true),
            'entry_rules'         => $this->json(),
            'created_by'          => $this->fk(),
            'updated_by'          => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'name']],
            'fks'    => ['entity_id' => 'entities', 'created_by' => 'users', 'updated_by' => 'users'],
            'checks' => [
                'layout'  => $this->in('amount_layout', self::LAYOUTS),
                'decimal' => $this->in('decimal_mark', ['.', ',']),
            ],
        ]);

        $this->table('bank_statement_imports', [
            'id'                => $this->id(),
            'bank_statement_id' => $this->fk(),
            'filename'          => $this->string(255),
            'rows_read'         => $this->int(false, 0),
            'rows_imported'     => $this->int(false, 0),
            'rows_duplicate'    => $this->int(false, 0),
            'rows_outside'      => $this->int(false, 0),
            'closing_balance'   => $this->money(),
            'imported_by'       => $this->fk(),
            'imported_at'       => $this->datetime(false),
        ], [
            'keys'   => ['bank_statement_id'],
            'fks'    => ['bank_statement_id' => 'bank_statements', 'imported_by' => 'users'],
            'checks' => ['rows' => 'rows_imported >= 0 AND rows_duplicate >= 0 AND rows_outside >= 0'],
        ]);

        // Added without constraints: altering these tables on SQLite would rebuild
        // them, which the schema's triggers and views would not survive.
        $this->forge->addColumn('bank_accounts', ['statement_format_id' => $this->fk(true)]);
        $this->forge->addColumn('bank_statements', ['source' => $this->string(10, false, 'seed')]);
        $this->forge->addColumn('bank_statement_lines', [
            'balance'                  => $this->money(true),
            'bank_statement_import_id' => $this->fk(true),
        ]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        foreach (['balance', 'bank_statement_import_id'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {bank_statement_lines} DROP COLUMN {$column}"));
        }
        $this->db->query($this->sql('ALTER TABLE {bank_statements} DROP COLUMN source'));
        $this->db->query($this->sql('ALTER TABLE {bank_accounts} DROP COLUMN statement_format_id'));
        $this->dropTables(['bank_statement_imports', 'bank_statement_formats']);
    }
}
