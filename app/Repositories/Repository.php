<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\EntityScope;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Base for the repositories that read and write the finance database.
 *
 * Repositories return records in the shapes the API has always served (the
 * prototype's field names, labels and date formats), so controllers and the
 * browser do not change when the source of the data does. Figures that the
 * prototype stored — balances, amounts received, days overdue — are derived here
 * from the ledger and the clock instead.
 *
 * Reads are cached for the length of a request; every write clears the cache.
 */
abstract class Repository
{
    /** @var array<string, mixed> */
    private static array $cache = [];

    protected BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /** Clears cached reads, e.g. after a write or between tests. */
    public static function forget(): void
    {
        self::$cache = [];
        EntityScope::forget();
    }

    protected function cached(string $key, callable $load): mixed
    {
        // Keyed by the entities in scope too, so a read made for one is never served for another.
        $key = static::class . ':' . implode(',', EntityScope::ids() ?? ['*']) . ':' . $key;

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $load();
        }

        return self::$cache[$key];
    }

    // ------------------------------------------------------------------
    // Queries
    // ------------------------------------------------------------------

    /**
     * Runs SQL with {table} placeholders resolved to prefixed names, and each
     * entity's table read only for the entities the user is working in.
     */
    protected function rows(string $sql, array $binds = []): array
    {
        return $this->db->query($this->sql($sql), $binds)->getResultArray();
    }

    protected function row(string $sql, array $binds = []): ?array
    {
        return $this->rows($sql, $binds)[0] ?? null;
    }

    protected function value(string $sql, array $binds = []): mixed
    {
        $row = $this->row($sql, $binds);

        return $row === null ? null : reset($row);
    }

    /**
     * Resolves {table} placeholders to prefixed names.
     *
     * An entity's table read after FROM or JOIN becomes a derived table holding only
     * the rows of the entities in scope (App\Libraries\EntityScope), under the alias
     * the query gave it or else its own name — so `FROM {journals} j` reads as
     * `FROM (SELECT * FROM journals WHERE entity_id IN (3)) j`. Both MySQL and SQLite
     * merge such a table back into the query, so the entity_id index is still used.
     * A write's own table (DELETE FROM, UPDATE, INSERT INTO) is left as it is, and
     * {all:table} reads every entity's rows on purpose: for the organisation's own
     * records (the settings log), for a check against a key the whole organisation
     * shares (an award reference, a supplier's invoice number), and for the next
     * document number, which is issued across the organisation so that a reference
     * names one record even in the consolidated view.
     */
    protected function sql(string $sql): string
    {
        $keywords = 'WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|ON|USING|ORDER|GROUP|LIMIT|HAVING|UNION|SET|FOR|WINDOW';

        return preg_replace_callback(
            '/(\bDELETE\s+)?(\b(?:FROM|JOIN)\s+)?\{(all:)?(\w+)\}(?:(\s+(?:AS\s+)?)(?!(?:' . $keywords . ')\b)([A-Za-z_]\w*))?/i',
            function ($m) {
                $table = $this->db->prefixTable($m[4]);
                $alias = $m[6] ?? '';
                $condition = $m[2] !== '' && ($m[1] ?? '') === '' && ($m[3] ?? '') === '' ? EntityScope::condition($m[4]) : null;

                if ($condition === null) {
                    return ($m[1] ?? '') . $m[2] . $table . ($alias !== '' ? $m[5] . $alias : '');
                }

                return $m[2] . '(SELECT * FROM ' . $table . ' WHERE ' . $condition . ') ' . ($alias !== '' ? $alias : $table);
            },
            $sql
        );
    }

    protected function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    /**
     * Runs a write atomically. A refusal from the database — a trigger or CHECK
     * constraint enforcing an accounting rule — becomes a RuleViolation carrying
     * the rule's own wording.
     */
    protected function transaction(callable $write): mixed
    {
        $this->db->transException(true)->transStart();

        try {
            $result = $write();
            $this->db->transComplete();
        } catch (Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            self::forget();

            throw $e instanceof DatabaseException ? new RuleViolation(self::ruleMessage($e->getMessage()), 0, $e) : $e;
        }

        self::forget();

        return $result;
    }

    /** The user-facing part of a database refusal. */
    private static function ruleMessage(string $message): string
    {
        if (preg_match("/Check constraint '([a-z_]+)_check' is violated|CHECK constraint failed: ([a-z_]+)_check/i", $message, $m) === 1) {
            $name = $m[1] !== '' ? $m[1] : $m[2];

            return str_ends_with($name, '_sod')
                ? 'The person who prepared this cannot also approve it. It needs a second approver.'
                : 'The change breaks a rule the ledger enforces (' . str_replace('_', ' ', $name) . ').';
        }

        return trim(preg_replace('/^.*?(?:SQLSTATE\[\w+\]:?|\d{4}:)\s*/', '', $message));
    }

    /** Appends an entry to a record's history (audit_events). */
    protected function audit(string $objectType, ?int $objectId, ?string $ref, string $summary, ?int $actorId, string $action = 'history', ?int $entityId = null): void
    {
        $this->insert('audit_events', [
            'entity_id'     => $entityId,
            'occurred_at'   => Clock::timestamp(),
            'actor_user_id' => $actorId,
            'action'        => $action,
            'object_type'   => $objectType,
            'object_id'     => $objectId,
            'object_ref'    => $ref,
            'summary'       => mb_substr($summary, 0, 255),
        ]);
    }

    /**
     * The history lines for every record of one type, keyed by record id.
     *
     * @return array<int, list<array{when: string, what: string}>>
     */
    protected function trails(string $objectType, string $format = 'd M'): array
    {
        return $this->cached("trails:{$objectType}:{$format}", function () use ($objectType, $format) {
            $out = [];
            foreach ($this->rows('SELECT object_id, occurred_at, summary FROM {audit_events} WHERE object_type = ? ORDER BY occurred_at, id', [$objectType]) as $r) {
                $out[(int) $r['object_id']][] = ['when' => date($format, strtotime($r['occurred_at'])), 'what' => (string) $r['summary']];
            }

            return $out;
        });
    }

    // ------------------------------------------------------------------
    // Presentation, as the API has always served it
    // ------------------------------------------------------------------

    /** Amounts as whole numbers where they are whole, so 18420500.00 serves as 18420500. */
    protected static function num(mixed $value): int|float
    {
        $f = round((float) $value, 2);

        return floor($f) === $f && abs($f) < PHP_INT_MAX ? (int) $f : $f;
    }

    /** "2026-08-28" → "28 Aug 2026"; empty → the placeholder. */
    protected static function dmy(?string $date, string $empty = '—'): string
    {
        return $date ? date('d M Y', strtotime($date)) : $empty;
    }

    /** "2026-08-28" → "28 Aug". */
    protected static function dm(?string $date, string $empty = '—'): string
    {
        return $date ? date('d M', strtotime($date)) : $empty;
    }

    /** "pending_approval" → "Pending approval", with per-record overrides. */
    protected static function label(?string $value, array $overrides = []): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $overrides[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }

    /** The inverse of label(). */
    protected static function unlabel(string $label, array $overrides = []): string
    {
        $flipped = array_flip($overrides);

        return $flipped[$label] ?? str_replace([' ', '-'], '_', strtolower($label));
    }

    /** Ledger groups as the screens name them. */
    protected const FUND_GROUPS = ['general' => 'General Fund', 'grant' => 'Grant Fund', 'capital' => 'Capital Fund', 'endowment' => 'Endowment Fund'];
}
