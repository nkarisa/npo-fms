<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * Recurring journal templates: entries the organisation raises on a calendar
 * rather than on an event.
 *
 * A template posts nothing on its own. Running it raises an ordinary journal
 * (source_type 'recurring_template') through JournalRepository, so the entry is
 * held to every rule a manual entry is — an open period, balanced lines, awards
 * on restricted lines — and still needs a second person to approve it.
 */
final class RecurringTemplateRepository extends Repository
{
    private const FREQUENCY_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /**
     * Every template with its lines and run history, in code order.
     *
     * @return list<array{id: string, name: string, type: string, frequency: string, rule: string, next: string, last: string,
     *                    owner: string, status: string, autoSubmit: bool, doc: string, narration: string, memo: string,
     *                    lines: list<array>, runs: list<array{when: string, ref: string, status: string}>}>
     */
    public function all(): array
    {
        return $this->cached('all', function () {
            $lines = [];
            foreach ($this->rows(
                'SELECT l.*, a.code FROM {recurring_template_lines} l JOIN {accounts} a ON a.id = l.account_id ORDER BY l.template_id, l.line_no'
            ) as $l) {
                $grant = $l['grant_id'] === null ? null : ($this->lookups->grants()[(int) $l['grant_id']] ?? null);
                $lines[(int) $l['template_id']][] = [
                    'code'     => $l['code'],
                    'desc'     => $l['description'],
                    'fund'     => $this->lookups->fundGroupLabel((int) $l['fund_id']),
                    'program'  => $this->lookups->programmeName((int) $l['programme_id']),
                    'grantRef' => $grant['award_ref'] ?? '',
                    'grant'    => $grant['short_name'] ?? '',
                    'dr'       => self::num($l['debit']),
                    'cr'       => self::num($l['credit']),
                ];
            }

            // A run whose journal is still in the ledger reads its status from the journal.
            $runs = [];
            foreach ($this->rows(
                'SELECT r.*, j.status AS journal_status FROM {recurring_template_runs} r LEFT JOIN {journals} j ON j.id = r.journal_id
                 ORDER BY r.template_id, r.run_on DESC, r.id DESC'
            ) as $r) {
                $runs[(int) $r['template_id']][] = [
                    'when'   => self::dmy($r['run_on']),
                    'ref'    => $r['reference'],
                    'status' => self::label($r['journal_status'] ?? $r['status']),
                ];
            }

            return array_map(fn ($t) => [
                'id'         => $t['code'],
                'name'       => $t['name'],
                'type'       => self::label($t['type']),
                'frequency'  => self::label($t['frequency']),
                'rule'       => $t['rule'],
                'next'       => self::dmy($t['next_due']),
                'nextISO'    => $t['next_due'],
                'last'       => self::dmy($t['last_run_on']),
                'owner'      => $this->lookups->shortName((int) $t['owner_user_id']),
                'status'     => self::label($t['status']),
                'autoSubmit' => (bool) $t['auto_submit'],
                'doc'        => $t['document_ref'],
                'narration'  => $t['narration'],
                'memo'       => $t['memo'] ?? '',
                'lines'      => $lines[(int) $t['id']] ?? [],
                'runs'       => $runs[(int) $t['id']] ?? [],
            ], $this->rows('SELECT * FROM {recurring_templates} WHERE entity_id = ? ORDER BY code', [$this->lookups->entityId()]));
        });
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $t) {
            if ($t['id'] === $code) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Generates the template's next entry, dated on its due date, and moves the
     * schedule on. The entry is submitted for approval or saved as a draft as the
     * template says.
     */
    public function run(string $code, int $actorId): array
    {
        $template = $this->header($code);
        if ($template['status'] !== 'active') {
            throw new RuleViolation($template['name'] . ' is paused. Resume it before generating an entry.');
        }

        $period = null;
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $template['next_due'] && $template['next_due'] <= $p['ends_on']) {
                $period = $p;
                break;
            }
        }
        if ($period === null) {
            throw new RuleViolation('No accounting period covers ' . self::dmy($template['next_due']) . ', so ' . $template['name'] . ' cannot run yet.');
        }
        if ($period['status'] === 'closed') {
            throw new RuleViolation($period['name'] . ' is closed to further posting. Reopen the month in Period close before running ' . $template['name'] . '.');
        }

        $current = $this->find($code);
        $who = $this->lookups->shortName($actorId);

        $journal = $this->transaction(function () use ($template, $current, $period, $actorId, $who) {
            $journal = (new JournalRepository())->create([
                'date'        => $template['next_due'],
                'type'        => self::label($template['type']),
                'period'      => $period['name'],
                'status'      => $template['auto_submit'] ? 'Pending approval' : 'Draft',
                'docLink'     => 'recurring:' . $template['code'],
                'memo'        => 'Generated from recurring template ' . $template['code'] . ' · ' . $template['name'] . '.',
                'narration'   => $template['narration'],
                'lines'       => $current['lines'],
                'createdNote' => 'Generated from recurring template ' . $template['code'] . ' by ' . $who,
            ], $actorId);

            $this->insert('recurring_template_runs', [
                'template_id' => $template['id'], 'run_on' => $template['next_due'],
                'journal_id'  => $this->value('SELECT id FROM {journals} WHERE reference = ?', [$journal['ref']]),
                'reference'   => $journal['ref'], 'status' => null,
            ]);
            $this->db->table('recurring_templates')->where('id', $template['id'])->update([
                'last_run_on' => $template['next_due'],
                'next_due'    => self::advance($template['next_due'], $template['frequency'], $template['rule']),
                'updated_at'  => Clock::timestamp(),
            ]);
            $this->audit('recurring_template', (int) $template['id'], $template['code'], $journal['ref'] . ' generated by ' . $who, $actorId);

            return $journal;
        });

        return $journal;
    }

    /** Pauses an active template or resumes a paused one. */
    public function toggle(string $code, int $actorId): array
    {
        $template = $this->header($code);
        $to = $template['status'] === 'active' ? 'paused' : 'active';

        $this->transaction(function () use ($template, $to, $actorId) {
            $this->db->table('recurring_templates')->where('id', $template['id'])->update(['status' => $to, 'updated_at' => Clock::timestamp()]);
            $this->audit('recurring_template', (int) $template['id'], $template['code'], ($to === 'paused' ? 'Paused by ' : 'Resumed by ') . $this->lookups->shortName($actorId), $actorId);
        });

        return $this->find($code);
    }

    /**
     * Holds an entry as a monthly template: the lines, coding and narration carry
     * forward and only the date changes. The entry becomes the template's first run.
     */
    public function fromJournal(string $ref, int $actorId): array
    {
        $journal = $this->row("SELECT * FROM {journals} WHERE reference = ? AND (source_type IS NULL OR source_type NOT IN ('archive', 'fiscal_year'))", [$ref]);
        if ($journal === null) {
            throw new RuleViolation('Journal ' . $ref . ' cannot be held as a template.');
        }
        $existing = $this->value(
            'SELECT t.code FROM {recurring_template_runs} r JOIN {recurring_templates} t ON t.id = r.template_id WHERE r.journal_id = ? LIMIT 1',
            [$journal['id']]
        );
        if ($existing !== null) {
            throw new RuleViolation($ref . ' is already a run of recurring template ' . $existing . '.');
        }

        $max = 0;
        foreach ($this->rows('SELECT code FROM {all:recurring_templates}') as $r) {
            $max = max($max, (int) substr($r['code'], 3));
        }
        $code = 'RT-' . str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
        $rule = self::ruleFor($journal['journal_date']);
        $who = $this->lookups->shortName($actorId);

        $this->transaction(function () use ($journal, $ref, $code, $rule, $actorId, $who) {
            $now = Clock::timestamp();
            $id = $this->insert('recurring_templates', [
                'entity_id' => $journal['entity_id'], 'code' => $code, 'name' => mb_substr($journal['narration'] !== '' ? $journal['narration'] : $ref, 0, 120),
                // A reversal repeated on a calendar is an accrual being released.
                'type' => $journal['type'] === 'reversing' ? 'accrual' : $journal['type'],
                'frequency' => 'monthly', 'rule' => $rule,
                'next_due' => self::advance($journal['journal_date'], 'monthly', $rule), 'last_run_on' => $journal['journal_date'],
                'owner_user_id' => $actorId, 'status' => 'active', 'auto_submit' => 1,
                'document_ref' => $journal['document_ref'] ?? 'ELOG/AC/' . $code,
                'narration' => $journal['narration'], 'memo' => mb_substr('Template created from ' . $ref . ' by ' . $who . '.', 0, 255),
                'created_at' => $now,
            ]);
            foreach ($this->rows('SELECT * FROM {journal_lines} WHERE journal_id = ? ORDER BY line_no', [$journal['id']]) as $l) {
                $this->insert('recurring_template_lines', [
                    'template_id' => $id, 'line_no' => $l['line_no'], 'account_id' => $l['account_id'], 'fund_id' => $l['fund_id'],
                    'programme_id' => $l['programme_id'], 'grant_id' => $l['grant_id'],
                    'description' => $l['description'], 'debit' => $l['debit'], 'credit' => $l['credit'],
                ]);
            }
            $this->insert('recurring_template_runs', [
                'template_id' => $id, 'run_on' => $journal['journal_date'], 'journal_id' => $journal['id'], 'reference' => $ref, 'status' => null,
            ]);
            $this->audit('recurring_template', $id, $code, 'Created from ' . $ref . ' by ' . $who, $actorId);
        });

        return $this->find($code);
    }

    /**
     * The next due date. The rule, not the previous date, decides the day, so a
     * month-end schedule stays on month end.
     */
    public static function advance(string $date, string $frequency, string $rule): string
    {
        $from = new \DateTimeImmutable(substr($date, 0, 10));
        $first = $from->modify('first day of this month')->modify('+' . (self::FREQUENCY_MONTHS[strtolower($frequency)] ?? 1) . ' months');
        $endOfMonth = (int) $first->format('t');

        $day = match (true) {
            (bool) preg_match('/last day/i', $rule)                     => $endOfMonth,
            (bool) preg_match('/first day/i', $rule)                    => 1,
            (bool) preg_match('/(\d{1,2})(?:st|nd|rd|th)/', $rule, $m) => min((int) $m[1], $endOfMonth),
            default                                                     => min((int) $from->format('j'), $endOfMonth),
        };

        return $first->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    /** The schedule rule a date implies: month end, month start or the nth. */
    public static function ruleFor(string $date): string
    {
        $d = new \DateTimeImmutable(substr($date, 0, 10));
        $day = (int) $d->format('j');

        if ($day === (int) $d->format('t')) {
            return 'Last day of the month';
        }
        if ($day === 1) {
            return 'First day of the month';
        }

        return $d->format('jS') . ' of the month';
    }

    private function header(string $code): array
    {
        $template = $this->row('SELECT * FROM {recurring_templates} WHERE code = ? AND entity_id = ?', [$code, $this->lookups->entityId()]);
        if ($template === null) {
            throw new RuleViolation('Recurring template ' . $code . ' not found.');
        }

        return $template;
    }
}
