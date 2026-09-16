<?php

namespace App\Database\Seeds\Support;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

/**
 * State and translation rules shared by the seeders that load the prototype data
 * (app/Data/OLD/*.json) into the database. The application reads the database;
 * the JSON is kept only as the seed source.
 *
 * The prototype stores names where the schema stores keys ("Grant Fund", "M. Otieno",
 * "USAID / Uraia 2026"). Every such name is resolved here, once, so all seeders
 * apply the same rules:
 *
 * - People. A name that matches a user's short or full name is that user. A name
 *   that matches no user is not invented as an account: where a user is required
 *   the record is attributed to the data-migration system user, and the original
 *   wording is kept in the record's audit trail.
 * - Dates. Prototype dates without a year ("02 Jul") fall in 2026, the year every
 *   such record belongs to. Relative dates ("Today", "Yesterday") count back from
 *   AS_AT.
 * - Funds. The prototype codes lines to a ledger group ("Grant Fund"); the schema
 *   needs one of the nine funds. See fundId().
 */
final class SeedContext
{
    /** The prototype's "today". */
    public const AS_AT = '2026-08-31';

    public const YEAR = 2026;

    public const SYSTEM_EMAIL = 'data-migration@system.invalid';

    private static ?self $instance = null;

    /** @var array<string, array<string, mixed>> */
    private array $maps = [];

    /** @var array<string, array> seed datasets by name */
    private array $data = [];

    private function __construct(private BaseConnection $db)
    {
    }

    public static function start(BaseConnection $db): self
    {
        return self::$instance = new self($db);
    }

    public static function get(): self
    {
        return self::$instance ?? throw new RuntimeException('Run the seeders through DatabaseSeeder so the shared context is set up.');
    }

    public function db(): BaseConnection
    {
        return $this->db;
    }

    // ------------------------------------------------------------------
    // Data and writes
    // ------------------------------------------------------------------

    public function data(string $name): array
    {
        if (!isset($this->data[$name])) {
            $path = APPPATH . 'Data/OLD/' . $name . '.json';
            if (!is_file($path)) {
                throw new RuntimeException("Seed data {$path} is missing.");
            }
            $this->data[$name] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->data[$name];
    }

    public function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $this->db->insertID();
    }

    /** Remembers an id under a map and key, e.g. remember('accounts', '1110', 7). */
    public function remember(string $map, string $key, mixed $value): void
    {
        $this->maps[$map][$key] = $value;
    }

    public function lookup(string $map, ?string $key): mixed
    {
        return $key === null ? null : ($this->maps[$map][$key] ?? null);
    }

    public function require(string $map, string $key): mixed
    {
        return $this->maps[$map][$key] ?? throw new RuntimeException("Unknown {$map} '{$key}' in the prototype data.");
    }

    public function all(string $map): array
    {
        return $this->maps[$map] ?? [];
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    /** "28 Aug 2026", "02 Jul", "15 Jul 2024" → Y-m-d; anything else ("—", "on hold") → null. */
    public function date(?string $text): ?string
    {
        $text = trim((string) $text);

        if (preg_match('/^(\d{1,2}) ([A-Z][a-z]{2})[a-z]*\.?(?: (\d{4}))?$/', $text, $m) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!j M Y', "{$m[1]} {$m[2]} " . ($m[3] ?? self::YEAR));

        return $parsed === false ? null : $parsed->format('Y-m-d');
    }

    /** "26 Aug 09:41", "31 Aug", "Today, 09:12 · Nairobi", "2 days ago" → Y-m-d H:i:s. */
    public function datetime(?string $text): ?string
    {
        $text = trim((string) $text);
        $time = preg_match('/(\d{1,2}):(\d{2})/', $text, $t) === 1 ? sprintf('%02d:%02d:00', $t[1], $t[2]) : '00:00:00';
        $head = trim(preg_split('/[,·]| \d{1,2}:\d{2}/', $text)[0]);

        $relative = match (true) {
            strcasecmp($head, 'today') === 0     => 0,
            strcasecmp($head, 'yesterday') === 0 => 1,
            preg_match('/^(\d+) days ago$/i', $head, $d) === 1 => (int) $d[1],
            default => null,
        };

        if ($relative !== null) {
            return (new DateTimeImmutable(self::AS_AT))->modify("-{$relative} days")->format('Y-m-d') . ' ' . $time;
        }

        $date = $this->date($head);

        return $date === null ? null : "{$date} {$time}";
    }

    /** "Aug 2026" → the period's first and last day. */
    public function monthBounds(string $month): array
    {
        $start = DateTimeImmutable::createFromFormat('!M Y', $month);

        return [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')];
    }

    /**
     * Report periods as the prototype writes them: "Apr – Jun 26", "Jul 24 – Sep 26",
     * "Oct – Dec 2025", "Jan – Dec 25", "2025", "2025 – 2026". Returns [start, end] or
     * null for wording such as "Full period".
     */
    public function periodRange(string $text): ?array
    {
        $parts = array_map('trim', preg_split('/\s+[–-]\s+/u', trim($text)));
        $year  = static fn (?string $y) => $y === null ? null : (strlen($y) === 2 ? 2000 + (int) $y : (int) $y);
        $parse = static fn (string $p) => preg_match('/^(?:([A-Z][a-z]{2})\s*)?(\d{2}|\d{4})?$/', $p, $m) === 1
            ? ['month' => $m[1] ?? '', 'year' => isset($m[2]) && $m[2] !== '' ? $m[2] : null]
            : null;

        $from = $parse($parts[0]);
        $to   = $parse($parts[count($parts) - 1]);
        if ($from === null || $to === null || ($to['year'] ?? $from['year']) === null) {
            return null;
        }

        $toYear   = $year($to['year'] ?? $from['year']);
        $fromYear = $year($from['year']) ?? $toYear;
        if ($from['year'] === null && $from['month'] !== '' && $to['month'] !== ''
            && DateTimeImmutable::createFromFormat('!M', $from['month']) > DateTimeImmutable::createFromFormat('!M', $to['month'])) {
            $fromYear--;
        }

        $start = new DateTimeImmutable(sprintf('%d-%s-01', $fromYear, $from['month'] !== '' ? DateTimeImmutable::createFromFormat('!M', $from['month'])->format('m') : '01'));
        $end   = new DateTimeImmutable(sprintf('%d-%s-01', $toYear, $to['month'] !== '' ? DateTimeImmutable::createFromFormat('!M', $to['month'])->format('m') : '12'));

        return [$start->format('Y-m-d'), $end->modify('last day of this month')->format('Y-m-d')];
    }

    // ------------------------------------------------------------------
    // People
    // ------------------------------------------------------------------

    /** "M. Otieno", "Michael Otieno", "G. Wambui · Programme Officer" → user id, or null. */
    public function userId(?string $name): ?int
    {
        $name = trim(explode('·', (string) $name)[0]);

        return $name === '' ? null : $this->lookup('users', mb_strtolower($name));
    }

    public function userOrSystem(?string $name): int
    {
        return $this->userId($name) ?? $this->systemUserId();
    }

    public function systemUserId(): int
    {
        return $this->require('users', self::SYSTEM_EMAIL);
    }

    /** The person in "Approved by W. Kamau — reason": "W. Kamau". */
    public function actorIn(string $text): ?string
    {
        return preg_match("/\\bby ([A-Z]\\. [A-Z][A-Za-z'-]+|PKF Kenya)/u", $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * The first trail entry matching a pattern, as [when (Y-m-d H:i:s), person, text].
     *
     * @param list<array{when: string, what: string}> $trail
     */
    public function trailEntry(array $trail, string $pattern): ?array
    {
        foreach ($trail as $entry) {
            if (preg_match($pattern, $entry['what']) === 1) {
                return ['when' => $this->datetime($entry['when']), 'who' => $this->actorIn($entry['what']), 'what' => $entry['what']];
            }
        }

        return null;
    }

    /**
     * Writes a record's history ("trail") to the audit log.
     *
     * @param list<array{when: string, what: string}> $trail
     */
    public function writeTrail(string $objectType, int $objectId, string $ref, array $trail, ?int $entityId): void
    {
        foreach ($trail as $entry) {
            $actor = $this->actorIn($entry['what']);

            $this->insert('audit_events', [
                'entity_id'     => $entityId,
                'occurred_at'   => $this->datetime($entry['when']) ?? self::AS_AT . ' 00:00:00',
                'actor_user_id' => $this->userId($actor),
                'action'        => 'history',
                'object_type'   => $objectType,
                'object_id'     => $objectId,
                'object_ref'    => $ref,
                'summary'       => mb_substr($entry['what'], 0, 255),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Coding dimensions
    // ------------------------------------------------------------------

    public function entityId(string $code = 'ELOG-NS'): int
    {
        return $this->require('entities', $code);
    }

    public function accountId(string $code): int
    {
        return $this->require('accounts', $code);
    }

    /** Programme names as the prototype writes them ("Shared" is Shared services). */
    public function programmeId(?string $name): int
    {
        $name = in_array($name, [null, '', '—', 'Shared'], true) ? 'Shared services' : $name;

        return $this->require('programmes', $name);
    }

    /** A grant from its award reference or its short name; "Unassigned" and the like → null. */
    public function grantId(?string $label): ?int
    {
        return $this->lookup('grants', trim((string) $label));
    }

    public function periodId(string $date): int
    {
        return $this->require('periods', substr($date, 0, 7));
    }

    /**
     * The fund a line belongs to.
     *
     * General, Capital and Endowment Fund each have one fund. "Grant Fund" is one of
     * five restricted funds, chosen by, in order:
     *   1. the grant the line is coded to;
     *   2. when $accountFirst, the funder recorded on the account (income and
     *      expenditure accounts in the chart name their funder);
     *   3. the programme's grant fund (the first listed for it in PROGS, then FUNDS);
     *   4. the funder recorded on the account.
     */
    public function fundId(string $group, ?int $grantId = null, ?string $programme = null, ?string $accountCode = null, bool $accountFirst = false): int
    {
        $single = ['General Fund' => 'FND-100', 'Capital Fund' => 'FND-300', 'Endowment Fund' => 'FND-400'];
        if (isset($single[$group])) {
            return $this->require('funds', $single[$group]);
        }

        if ($group !== 'Grant Fund') {
            throw new RuntimeException("Unknown ledger fund '{$group}'.");
        }

        $byGrant   = $grantId === null ? null : $this->lookup('grant_funds', (string) $grantId);
        $byAccount = $accountCode === null ? null : $this->lookup('account_funds', $accountCode);
        $byProgram = $programme === null ? null : $this->lookup('programme_grant_funds', $programme === 'Shared' ? 'Shared services' : $programme);

        $fund = $byGrant ?? ($accountFirst ? ($byAccount ?? $byProgram) : ($byProgram ?? $byAccount));

        return $fund ?? throw new RuntimeException("Cannot tell which grant fund a {$programme} line on {$accountCode} belongs to.");
    }

    /** The grant a grant fund was set up for, if any. */
    public function grantOfFund(int $fundId): ?int
    {
        return $this->lookup('fund_grants', (string) $fundId);
    }
}
