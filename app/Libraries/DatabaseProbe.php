<?php

namespace App\Libraries;

use App\Repositories\RuleViolation;
use CodeIgniter\Database\BaseConnection;
use Config\Database as DatabaseConfig;
use Throwable;

/**
 * Whether a database the installer has been given is one this application can be
 * installed into, answered before anything is written to it.
 *
 * The installer connects to the server first and lists the databases that user can
 * see (databases()), so one is chosen rather than typed. It creates a new one only
 * when the user holds CREATE on the whole server (canCreate()), empty and with the
 * right character set; otherwise the administrator creates it. It never drops or
 * empties one. Once chosen, four things can be wrong with it, each with a
 * different remedy, so each is reported separately rather than as one failure:
 *
 * - it cannot be reached, or the credentials are refused;
 * - it was created with the wrong character set, which takes the migrations
 *   happily and mangles names months later;
 * - it already holds an organisation, and installing again would leave the
 *   consolidation with two head offices (App\Libraries\Installer::refusal);
 * - the user reaching it cannot do what the migrations need.
 *
 * That last one is the reason this class exists. The schema is not only tables:
 * migration 100014 creates the triggers that keep a posted journal and the audit
 * log append-only, and 100015 creates two reporting views. TRIGGER and CREATE VIEW
 * are the privileges most often left out of a restricted grant, and finding out at
 * migration 14 of 43 leaves a half-built schema that cannot be rolled back, because
 * MySQL does not keep DDL in a transaction. So the privileges are proved first, on
 * a scratch table that is dropped again, where failing costs nothing.
 */
final class DatabaseProbe
{
    /**
     * The drivers the schema is written for, and what to call them on screen.
     *
     * Not a matter of taste: App\Database\SchemaMigration and the triggers in
     * migration 100014 are written for these two and refuse anything else, so
     * offering a third would only fail part-way through migrating.
     */
    public const DRIVERS = ['MySQLi' => 'MySQL 8 or MariaDB', 'SQLite3' => 'SQLite (a file on this server)'];

    /** What the schema should have been created with. Anything else is warned about. */
    public const CHARSET   = 'utf8mb4';
    public const COLLATION = 'utf8mb4_general_ci';

    /** The PHP extension each driver needs. */
    private const EXTENSIONS = ['MySQLi' => 'mysqli', 'SQLite3' => 'sqlite3'];

    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config as config() returns */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * The drivers this server can actually use: the extension has to be loaded.
     *
     * @return array<string, string> driver => label
     */
    public static function available(): array
    {
        return array_filter(self::DRIVERS, static fn ($label, $driver) => extension_loaded(self::EXTENSIONS[$driver]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * A connection group built from what the installer was told, with everything
     * else taken from the application's own defaults so an instance installed in
     * the browser is configured exactly like one installed by hand.
     *
     * With $needsDatabase false it is a connection to the server alone — for MySQL,
     * signed in but with no database chosen — which is what listing the databases
     * and creating one need.
     *
     * @param array<string, string> $in
     * @return array<string, mixed>
     */
    public static function config(array $in, bool $needsDatabase = true): array
    {
        $driver = (string) ($in['driver'] ?? 'MySQLi');
        if (!isset(self::DRIVERS[$driver])) {
            throw new RuleViolation(
                $driver . ' is not a database this application can be installed into: the schema, and the triggers that keep '
                . 'the ledger append-only, are written for ' . implode(' and ', array_keys(self::DRIVERS)) . '.'
            );
        }
        if (!isset(self::available()[$driver])) {
            throw new RuleViolation('This server\'s PHP has no ' . self::EXTENSIONS[$driver] . ' extension, so it cannot reach a ' . self::DRIVERS[$driver] . ' database.');
        }

        $database = trim((string) ($in['database'] ?? ''));
        if ($database === '' && $needsDatabase) {
            throw new RuleViolation($driver === 'SQLite3'
                ? 'Give the path of the database file.'
                : 'Give the name of the database the administrator created for this instance.');
        }

        $config = (new DatabaseConfig())->default;
        $config['DBDriver'] = $driver;
        $config['database'] = $database;
        $config['DBPrefix'] = trim((string) ($in['prefix'] ?? ''));
        // Failures have to throw, so they can be reported rather than passed over.
        $config['DBDebug']  = true;
        $config['pConnect'] = false;

        if ($driver === 'SQLite3') {
            $config['foreignKeys'] = true;

            return $config;
        }

        $config['hostname'] = trim((string) ($in['hostname'] ?? '')) ?: 'localhost';
        $config['port']     = (int) ($in['port'] ?? 3306) ?: 3306;
        $config['username'] = (string) ($in['username'] ?? '');
        $config['password'] = (string) ($in['password'] ?? '');
        $config['charset']  = trim((string) ($in['charset'] ?? '')) ?: self::CHARSET;
        $config['DBCollat'] = trim((string) ($in['collation'] ?? '')) ?: self::COLLATION;

        return $config;
    }

    /** The settings that belong in .env, so the next request connects the same way. */
    public function envValues(): array
    {
        $c = $this->config;
        $values = [
            'database.default.DBDriver' => (string) $c['DBDriver'],
            'database.default.database' => (string) $c['database'],
            'database.default.DBPrefix' => (string) $c['DBPrefix'],
        ];
        if ($c['DBDriver'] === 'SQLite3') {
            return $values;
        }

        return $values + [
            'database.default.hostname' => (string) $c['hostname'],
            'database.default.port'     => (string) $c['port'],
            'database.default.username' => (string) $c['username'],
            'database.default.password' => (string) $c['password'],
            'database.default.charset'  => (string) $c['charset'],
            'database.default.DBCollat' => (string) $c['DBCollat'],
        ];
    }

    /**
     * The connection, or a refusal in the driver's own words — which is what makes a
     * refused password tellable apart from a host that is not listening.
     */
    public function connect(): BaseConnection
    {
        try {
            /** @var BaseConnection $db */
            $db = DatabaseConfig::connect($this->config, false);
            // Connecting is lazy; ask it something so a refusal surfaces here.
            $db->initialize();

            return $db;
        } catch (Throwable $e) {
            throw new RuleViolation($this->unreachable($e));
        }
    }

    // ------------------------------------------------------------------
    // Choosing a database
    // ------------------------------------------------------------------

    /**
     * The databases the installer could be pointed at, each with what it holds, so
     * the one to use can be picked rather than typed.
     *
     * For MySQL, the databases this user can see on the server (the server's own are
     * left out); for SQLite, the database files in writable/. One that already
     * belongs to an organisation is listed but cannot be chosen: installing into it
     * would give the consolidation a second head office.
     *
     * @return list<array{name: string, value: string, state: string, usable: bool, tables: int,
     *     charset: string, charsetOk: bool, organisation: string|null, note: string}>
     */
    public function databases(): array
    {
        return $this->config['DBDriver'] === 'SQLite3' ? $this->sqliteFiles() : $this->mysqlDatabases();
    }

    /**
     * Whether this user may create a database: for MySQL, CREATE (or everything)
     * granted on every database; for SQLite, a writable writable/.
     */
    public function canCreate(): bool
    {
        if ($this->config['DBDriver'] === 'SQLite3') {
            return is_dir(WRITEPATH) && is_writable(WRITEPATH);
        }

        $db = $this->connect();
        try {
            foreach ($db->query('SHOW GRANTS FOR CURRENT_USER()')->getResultArray() as $row) {
                $grant = (string) reset($row);
                if (preg_match('/^GRANT\s+(.+?)\s+ON\s+\*\.\*\s+TO\s/i', $grant, $m) !== 1) {
                    continue;
                }
                $privileges = array_map(static fn ($p) => strtoupper(trim($p)), explode(',', $m[1]));
                if (array_intersect($privileges, ['ALL', 'ALL PRIVILEGES', 'CREATE']) !== []) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            // Grants that cannot be read are grants that cannot be relied on.
        } finally {
            $db->close();
        }

        return false;
    }

    /**
     * Creates the database, empty and with the character set the schema wants, and
     * returns what to connect to: its name, or for SQLite the file's path. Never
     * over something that is already there — choosing an existing database is what
     * the list is for.
     */
    public function create(string $name): string
    {
        $name = trim($name);

        if ($this->config['DBDriver'] === 'SQLite3') {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name) !== 1) {
                throw new RuleViolation('Name the new database file with letters, digits, hyphens and underscores, e.g. books.');
            }
            $path = WRITEPATH . $name . '.sqlite';
            if (is_file($path)) {
                throw new RuleViolation('writable/' . $name . '.sqlite is already there. Choose it from the list, or give the new one another name.');
            }
            if (@touch($path) === false) {
                throw new RuleViolation('writable/' . $name . '.sqlite could not be created: the web server cannot write to writable/.');
            }

            return $path;
        }

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new RuleViolation('Name the new database with letters, digits and underscores, e.g. fms.');
        }

        $db = $this->connect();
        try {
            $exists = $db->query('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name])->getRowArray();
            if ((int) ($exists['n'] ?? 0) > 0) {
                throw new RuleViolation($name . ' already exists. Choose it from the list, or give the new one another name.');
            }
            $db->query('CREATE DATABASE `' . $name . '` CHARACTER SET ' . self::CHARSET . ' COLLATE ' . self::COLLATION);
        } catch (RuleViolation $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuleViolation($this->config['username'] . ' could not create ' . $name . '. The server said: ' . $this->serverSaid($e));
        } finally {
            $db->close();
        }

        return $name;
    }

    /** @return list<array<string, mixed>> */
    private function mysqlDatabases(): array
    {
        $db = $this->connect();
        $prefix = (string) $this->config['DBPrefix'];

        try {
            $rows = $db->query(
                "SELECT s.SCHEMA_NAME AS name, s.DEFAULT_CHARACTER_SET_NAME AS cs, COUNT(t.TABLE_NAME) AS tables,
                        SUM(CASE WHEN t.TABLE_NAME = ? THEN 1 ELSE 0 END) AS has_entities
                   FROM information_schema.SCHEMATA s
                   LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME
                  WHERE s.SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
                  GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME
                  ORDER BY s.SCHEMA_NAME",
                [$prefix . 'entities']
            )->getResultArray();

            $out = [];
            foreach ($rows as $row) {
                $organisation = null;
                if ((int) $row['has_entities'] > 0) {
                    try {
                        $quote = static fn (string $id) => '`' . str_replace('`', '``', $id) . '`';
                        $entity = $db->query('SELECT name FROM ' . $quote($row['name']) . '.' . $quote($prefix . 'entities') . ' ORDER BY id LIMIT 1')->getRowArray();
                        $organisation = $entity === null ? null : (string) $entity['name'];
                    } catch (Throwable $e) {
                        $organisation = null;
                    }
                }
                $out[] = self::listed((string) $row['name'], (string) $row['name'], (int) $row['tables'],
                    (int) $row['has_entities'] > 0, $organisation, (string) $row['cs']);
            }

            return $out;
        } finally {
            $db->close();
        }
    }

    /** @return list<array<string, mixed>> */
    private function sqliteFiles(): array
    {
        $files = array_merge(...array_map(static fn ($pattern) => glob(WRITEPATH . $pattern) ?: [], ['*.sqlite', '*.sqlite3', '*.db']));
        sort($files);

        $out = [];
        foreach ($files as $path) {
            $name = 'writable/' . basename($path);
            try {
                $db = (new self(self::config(['driver' => 'SQLite3', 'database' => $path, 'prefix' => $this->config['DBPrefix']])))->connect();
                try {
                    $tables = array_map('strtolower', $db->listTables());
                    $has = static fn (string $table) => in_array(strtolower($db->prefixTable($table)), $tables, true);
                    $organisation = null;
                    if ($has('entities')) {
                        $entity = $db->table('entities')->select('name')->orderBy('id')->get(1)->getRowArray();
                        $organisation = $entity === null ? null : (string) $entity['name'];
                    }
                    $out[] = self::listed($name, $path, count($tables), $has('entities'), $organisation, 'UTF-8');
                } finally {
                    $db->close();
                }
            } catch (Throwable $e) {
                $out[] = ['name' => $name, 'value' => $path, 'state' => 'unreadable', 'usable' => false, 'tables' => 0,
                    'charset' => '', 'charsetOk' => true, 'organisation' => null, 'note' => 'Not a database this server can open.'];
            }
        }

        return $out;
    }

    /**
     * One database in the list, with what it holds said in a line. It is this
     * application's own when it has the entities table — a migrations table alone
     * says nothing, since other frameworks keep one of the same name.
     */
    private static function listed(string $name, string $value, int $tables, bool $ours, ?string $organisation, string $charset): array
    {
        $count = $tables . ' ' . ($tables === 1 ? 'table' : 'tables');
        [$state, $note] = match (true) {
            $organisation !== null => ['installed', 'Already holds ' . $organisation . '.'],
            $tables === 0          => ['empty', 'Empty.'],
            $ours                  => ['migrated', $count . ' from an earlier attempt, no organisation yet. The schema is brought up to date.'],
            default                => ['other', $count . ($tables === 1 ? ' that is' : ' that are') . ' not this application\'s. They are left alone, but a database of its own is better.'],
        };
        $charsetOk = $charset === '' || in_array(strtolower($charset), [self::CHARSET, 'utf-8'], true);
        if (!$charsetOk) {
            $note .= ' Created as ' . $charset . ', not ' . self::CHARSET . '.';
        }

        return ['name' => $name, 'value' => $value, 'state' => $state, 'usable' => $state !== 'installed', 'tables' => $tables,
            'charset' => $charset, 'charsetOk' => $charsetOk, 'organisation' => $organisation, 'note' => $note];
    }

    // ------------------------------------------------------------------
    // Proving the chosen one
    // ------------------------------------------------------------------

    /**
     * What is true of this database, as four separate answers.
     *
     * @return array{ok: bool, driver: string, label: string, server: string, database: string,
     *     charset: array{ok: bool, name: string, collation: string, note: string},
     *     schema: array{state: string, tables: int, migrations: int, organisation: string|null, note: string},
     *     privileges: array{ok: bool, missing: list<string>, note: string},
     *     refusal: string|null}
     */
    public function report(): array
    {
        $db = $this->connect();

        try {
            $charset    = $this->charset($db);
            $schema     = $this->schema($db);
            $privileges = $schema['state'] === 'installed' ? $this->skipped() : $this->privileges($db);

            $refusal = null;
            if ($schema['state'] === 'installed') {
                $refusal = $schema['note'];
            } elseif (!$privileges['ok']) {
                $refusal = $privileges['note'];
            }

            return [
                'ok'         => $refusal === null,
                'driver'     => (string) $this->config['DBDriver'],
                'label'      => self::DRIVERS[$this->config['DBDriver']],
                'server'     => $this->version($db),
                'database'   => (string) $this->config['database'],
                'charset'    => $charset,
                'schema'     => $schema,
                'privileges' => $privileges,
                'refusal'    => $refusal,
            ];
        } finally {
            $db->close();
        }
    }

    // ------------------------------------------------------------------
    // The four answers
    // ------------------------------------------------------------------

    private function version(BaseConnection $db): string
    {
        try {
            return (string) $db->getVersion();
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * The character set the database was created with. A warning, never a refusal:
     * a latin1 schema takes every migration without complaint and then cannot hold
     * a supplier's name or a narration in anything but Western European letters.
     */
    private function charset(BaseConnection $db): array
    {
        if ($this->config['DBDriver'] === 'SQLite3') {
            return ['ok' => true, 'name' => 'UTF-8', 'collation' => '', 'note' => 'SQLite holds text as UTF-8.'];
        }

        try {
            $row = $db->query(
                'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                [$this->config['database']]
            )->getRowArray();
        } catch (Throwable $e) {
            $row = null;
        }

        $name      = (string) ($row['cs'] ?? '');
        $collation = (string) ($row['co'] ?? '');
        if ($name === '') {
            return ['ok' => true, 'name' => 'unknown', 'collation' => '',
                'note' => 'The character set could not be read. Check it is ' . self::CHARSET . ' before carrying real books.'];
        }
        if ($name === self::CHARSET) {
            return ['ok' => true, 'name' => $name, 'collation' => $collation, 'note' => 'As it should be.'];
        }

        return ['ok' => false, 'name' => $name, 'collation' => $collation,
            'note' => $this->config['database'] . ' was created as ' . $name . ', not ' . self::CHARSET . '. The migrations will run, '
                . 'but names and narrations outside that character set will be stored wrongly. Recreate it with '
                . 'CHARACTER SET ' . self::CHARSET . ' COLLATE ' . self::COLLATION . ' before carrying real books.'];
    }

    /**
     * Whether the database is empty, already migrated, or already belongs to an
     * organisation — the one case the installer cannot go on from.
     */
    private function schema(BaseConnection $db): array
    {
        $tables = array_map('strtolower', $db->listTables());
        $has    = fn (string $table) => in_array(strtolower($db->prefixTable($table)), $tables, true);

        $migrations = 0;
        if ($has('migrations')) {
            try {
                $migrations = (int) $db->table('migrations')->countAllResults();
            } catch (Throwable $e) {
                $migrations = 0;
            }
        }

        if ($has('entities')) {
            try {
                $entity = $db->table('entities')->select('name')->orderBy('id')->get(1)->getRowArray();
            } catch (Throwable $e) {
                $entity = null;
            }
            if ($entity !== null) {
                return ['state' => 'installed', 'tables' => count($tables), 'migrations' => $migrations,
                    'organisation' => (string) $entity['name'],
                    'note' => $this->config['database'] . ' already belongs to ' . $entity['name'] . '. Installing again would add a second '
                        . 'head office, which the consolidation cannot carry. Point the installer at an empty database, or drop and '
                        . 'recreate this one before installing again.'];
            }
        }

        if ($migrations > 0 || $tables !== []) {
            return ['state' => 'migrated', 'tables' => count($tables), 'migrations' => $migrations, 'organisation' => null,
                'note' => $tables === [] ? 'Empty.' : count($tables) . ' tables, no organisation yet. The installer will bring the schema '
                    . 'up to date and carry on; nothing already there is removed.'];
        }

        return ['state' => 'empty', 'tables' => 0, 'migrations' => 0, 'organisation' => null, 'note' => 'Empty, as a new instance wants.'];
    }

    private function skipped(): array
    {
        return ['ok' => true, 'missing' => [], 'note' => 'Not checked: this database already holds an organisation.'];
    }

    /**
     * Proves the user can do everything the migrations will need, on a table made
     * for the purpose and dropped again.
     *
     * Each statement stands for one privilege, so a refusal can be named rather
     * than reported as "the migration failed". The cleanup runs whatever happens,
     * so a database that refuses half way is not left with the scratch table in it.
     */
    private function privileges(BaseConnection $db): array
    {
        $table   = $db->prefixTable('install_probe_' . bin2hex(random_bytes(4)));
        $view    = $table . '_v';
        $trigger = $table . '_t';
        $mysql   = $this->config['DBDriver'] === 'MySQLi';

        $steps = $mysql
            ? [
                'CREATE'      => "CREATE TABLE {$table} (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id INT UNSIGNED NULL, n INT NOT NULL DEFAULT 0)",
                'ALTER'       => "ALTER TABLE {$table} ADD CONSTRAINT {$trigger}_fk FOREIGN KEY (parent_id) REFERENCES {$table} (id)",
                'CREATE VIEW' => "CREATE VIEW {$view} AS SELECT id, n FROM {$table}",
                'TRIGGER'     => "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW SET NEW.n = NEW.n + 1",
                'INSERT'      => "INSERT INTO {$table} (n) VALUES (1)",
                'SELECT'      => "SELECT id FROM {$view}",
                'UPDATE'      => "UPDATE {$table} SET n = 2 WHERE n <> 2",
                'DELETE'      => "DELETE FROM {$table} WHERE n = 2",
            ]
            : [
                'CREATE'      => "CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL REFERENCES {$table} (id), n INT NOT NULL DEFAULT 0)",
                'CREATE VIEW' => "CREATE VIEW {$view} AS SELECT id, n FROM {$table}",
                'TRIGGER'     => "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} BEGIN SELECT 1; END",
                'INSERT'      => "INSERT INTO {$table} (n) VALUES (1)",
                'SELECT'      => "SELECT id FROM {$view}",
                'UPDATE'      => "UPDATE {$table} SET n = 2 WHERE n <> 2",
                'DELETE'      => "DELETE FROM {$table} WHERE n = 2",
            ];

        $missing = [];
        $said    = [];
        try {
            foreach ($steps as $privilege => $sql) {
                try {
                    $db->query($sql);
                } catch (Throwable $e) {
                    $missing[] = $privilege;
                    $said[]    = $this->serverSaid($e);
                    // Nothing after a failed CREATE can succeed; the rest would only
                    // repeat the same refusal.
                    if ($privilege === 'CREATE') {
                        break;
                    }
                }
            }
        } finally {
            foreach (["DROP TRIGGER IF EXISTS {$trigger}", "DROP VIEW IF EXISTS {$view}", "DROP TABLE IF EXISTS {$table}"] as $sql) {
                try {
                    $db->query($sql);
                } catch (Throwable $e) {
                    // Nothing to do: it was never created, or cannot be removed, and
                    // either way the name is random and belongs to nothing.
                }
            }
        }

        if ($missing === []) {
            return ['ok' => true, 'missing' => [], 'note' => 'Everything the migrations need.'];
        }

        $note = (string) $this->config['username'] . ' cannot ' . implode(', ', $missing) . ' in ' . $this->config['database']
            . '. The schema needs all of SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER and CREATE VIEW: '
            . 'the ledger\'s append-only triggers and the two reporting views are part of it. Grant those and check again.';
        if (in_array('TRIGGER', $missing, true)) {
            $note .= ' A server with binary logging on can also refuse a trigger from a user without SUPER unless '
                . 'log_bin_trust_function_creators is on.';
        }
        if ($said !== []) {
            $note .= ' The server said: ' . $said[0];
        }

        return ['ok' => false, 'missing' => $missing, 'note' => $note];
    }

    // ------------------------------------------------------------------

    /** The refusal to connect, in the words most likely to say what to change. */
    private function unreachable(Throwable $e): string
    {
        $said = $this->serverSaid($e);

        if ($this->config['DBDriver'] === 'SQLite3') {
            $directory = dirname((string) $this->config['database']);

            return 'The database file ' . $this->config['database'] . ' could not be opened. '
                . (is_dir($directory)
                    ? (is_writable($directory) ? 'Check the path and that the web server may read and write the file.' : $directory . ' cannot be written by the web server.')
                    : $directory . ' does not exist.')
                . ' The server said: ' . $said;
        }

        $where = $this->config['hostname'] . ':' . $this->config['port'];
        $who = $this->config['username'] === '' ? '(no user given)' : $this->config['username'];
        if ((string) $this->config['database'] === '') {
            return 'The database server at ' . $where . ' could not be reached as ' . $who
                . '. Check the host, port, user and password. The server said: ' . $said;
        }

        return $this->config['database'] . ' on ' . $where . ' could not be reached as ' . $who
            . '. Check the database exists, and that the host, user and password are right. '
            . 'The server said: ' . $said;
    }

    /** What the driver said, kept short enough to read and free of our own wrapping. */
    private function serverSaid(Throwable $e): string
    {
        $message = trim($e->getMessage());
        // CodeIgniter wraps the driver's message; the last line is the driver's own.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $message)), static fn ($l) => $l !== ''));

        return mb_substr($lines[count($lines) - 1] ?? $message, 0, 300);
    }
}
