<?php

namespace App\Database;

use CodeIgniter\Database\Migration;

/**
 * Column types and table-building helpers shared by the finance schema.
 *
 * - Money is DECIMAL(18,2) in the functional currency (KES) unless the column
 *   name says otherwise (`*_fc` is the document currency), so a journal balances
 *   to the minor unit without float drift.
 * - Enumerations are VARCHAR plus a CHECK constraint rather than ENUM, which keeps
 *   one schema for MySQL 8 (production) and SQLite (the test suite).
 * - Foreign keys restrict deletes by default. Financial records are closed or
 *   archived, not deleted; only a document's own lines cascade with it.
 */
abstract class SchemaMigration extends Migration
{
    protected function id(): array
    {
        return ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true];
    }

    protected function fk(bool $null = false): array
    {
        return ['type' => 'BIGINT', 'unsigned' => true, 'null' => $null];
    }

    protected function money(bool $null = false): array
    {
        return $null
            ? ['type' => 'DECIMAL', 'constraint' => '18,2', 'null' => true]
            : ['type' => 'DECIMAL', 'constraint' => '18,2', 'default' => 0];
    }

    /** KES per one unit of the document currency (e.g. 129.4 for USD). */
    protected function fxRate(): array
    {
        return ['type' => 'DECIMAL', 'constraint' => '18,6', 'default' => 1];
    }

    /** A percentage held as 0–100 (32.5 means 32.5%). */
    protected function pct(bool $null = false): array
    {
        return ['type' => 'DECIMAL', 'constraint' => '6,3', 'null' => $null] + ($null ? [] : ['default' => 0]);
    }

    protected function quantity(): array
    {
        return ['type' => 'DECIMAL', 'constraint' => '14,3', 'default' => 1];
    }

    protected function string(int $length, bool $null = false, ?string $default = null): array
    {
        $field = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $null];

        return $default === null ? $field : $field + ['default' => $default];
    }

    protected function currency(): array
    {
        return ['type' => 'CHAR', 'constraint' => 3, 'default' => 'KES'];
    }

    protected function text(bool $null = true): array
    {
        return ['type' => 'TEXT', 'null' => $null];
    }

    protected function json(): array
    {
        return ['type' => $this->isMySQL() ? 'JSON' : 'TEXT', 'null' => true];
    }

    protected function date(bool $null = false): array
    {
        return ['type' => 'DATE', 'null' => $null];
    }

    protected function datetime(bool $null = true): array
    {
        return ['type' => 'DATETIME', 'null' => $null];
    }

    protected function bool(bool $default = false): array
    {
        return ['type' => 'TINYINT', 'constraint' => 1, 'default' => (int) $default];
    }

    protected function int(bool $null = false, ?int $default = null, string $type = 'INT'): array
    {
        $field = ['type' => $type, 'null' => $null];

        return $default === null ? $field : $field + ['default' => $default];
    }

    protected function timestamps(): array
    {
        return ['created_at' => $this->datetime(), 'updated_at' => $this->datetime()];
    }

    /** SQL for "column is one of these values". */
    protected function in(string $column, array $values): string
    {
        return $column . ' IN (' . implode(', ', array_map(static fn ($v) => "'{$v}'", $values)) . ')';
    }

    /**
     * Creates a table.
     *
     * @param array{
     *     primary?: string|list<string>,
     *     keys?: list<string|list<string>>,
     *     unique?: list<string|list<string>>,
     *     fks?: array<string, string|array{0: string, 1: string}>,
     *     checks?: array<string, string>,
     * } $opts `fks` maps a column to its table, or to [table, ON DELETE action].
     *         `checks` maps a short name to a boolean SQL expression.
     */
    protected function table(string $name, array $fields, array $opts = []): void
    {
        $this->forge->addField($fields);
        $this->forge->addPrimaryKey($opts['primary'] ?? 'id');

        foreach ($opts['keys'] ?? [] as $key) {
            $this->forge->addKey($key, false, false, $this->identifier($name, (array) $key));
        }

        foreach ($opts['unique'] ?? [] as $key) {
            $this->forge->addUniqueKey($key, $this->identifier($name, (array) $key));
        }

        foreach ($opts['fks'] ?? [] as $column => $ref) {
            [$table, $onDelete] = (array) $ref + [1 => ''];
            // ON UPDATE is left as NO ACTION: ids never change, and MySQL refuses a
            // CHECK on a column that a cascading referential action could rewrite.
            // SQLite cannot name foreign keys; MySQL needs the name kept within 64 characters.
            $fkName = $this->isMySQL() ? $this->identifier($name, [$column, 'foreign']) : '';
            $this->forge->addForeignKey($column, $table, 'id', '', $onDelete, $fkName);
        }

        foreach ($opts['checks'] ?? [] as $check => $expression) {
            $this->addCheck($this->identifier($name, [$check, 'check']), $expression);
        }

        $this->forge->createTable($name, false, $this->isMySQL() ? ['ENGINE' => 'InnoDB'] : []);
    }

    /** Drops tables in the order given (children first). */
    protected function dropTables(array $tables): void
    {
        foreach ($tables as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /** Replaces `{table}` placeholders with the prefixed table name. */
    protected function sql(string $sql): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $this->db->prefixTable($m[1]), $sql);
    }

    /**
     * Index and constraint names follow Forge's own pattern (prefix_table_columns),
     * shortened with a hash when they would pass MySQL's 64-character limit.
     */
    private function identifier(string $table, array $parts): string
    {
        $name = $this->db->DBPrefix . $table . '_' . implode('_', $parts);

        return strlen($name) <= 64 ? $name : substr($name, 0, 55) . '_' . hash('crc32b', $name);
    }

    protected function isMySQL(): bool
    {
        return $this->db->DBDriver === 'MySQLi';
    }

    /**
     * Forge has no API for table-level CHECK constraints. A string field is emitted
     * verbatim inside CREATE TABLE, but addField() keys string fields by their first
     * word, so a second "CONSTRAINT ..." would overwrite the first. Registering the
     * literal under the constraint's own name lets several coexist.
     */
    private function addCheck(string $name, string $expression): void
    {
        $literal = "CONSTRAINT {$name} CHECK ({$expression})";

        (function () use ($name, $literal) {
            $this->fields[$name] = $literal;
        })->call($this->forge);
    }
}
