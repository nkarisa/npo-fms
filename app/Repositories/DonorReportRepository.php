<?php

namespace App\Repositories;

use App\Libraries\Clock;
use IntlDateFormatter;

/**
 * Donor reports, the path each takes to the funder, and the delivery language
 * each funder's agreement requires.
 *
 * A report's figures are a snapshot of the ledger, taken for the award and the
 * reporting period: expenditure in the period and cumulative to its end, by
 * account, against the award's budget. A submitted report is a document the
 * funder holds, so the snapshot is never retaken once it has left draft. What
 * the funder is told is the snapshot plus any manual adjustment; a report whose
 * adjustment is not nil does not tie to the ledger, and cannot go for review or
 * be submitted until it does.
 *
 * The path: draft (overdue once past its due date) → in review → submitted,
 * then queried and answered as often as the funder asks, and finally accepted.
 * Whoever submits a report is someone other than whoever prepared it.
 */
final class DonorReportRepository extends Repository
{
    private const TYPE_LABELS = ['close_out' => 'Close-out'];

    /** Report types the screen offers, as stored. */
    public const TYPES = ['financial' => 'Financial', 'narrative' => 'Narrative', 'close_out' => 'Close-out'];

    /** Statuses whose report is still with the organisation. */
    private const OPEN = ['draft', 'in_review'];

    /** The word between the two dates of a reporting period, by language. */
    private const PERIOD_CONNECTORS = ['en-GB' => 'to', 'fr' => 'au', 'es' => 'al', 'ar' => 'إلى', 'sw' => 'hadi'];

    /** Cover page wording in the source language; translations come from the catalogue. */
    private const COVER = [
        'prefix'    => 'Expenditure report',
        'awardWord' => 'Award',
        'labels'    => ['Award ceiling', 'Expenditure this period', 'Cumulative expenditure', 'Balance on the award'],
        'foot'      => 'Prepared from posted ledger actuals. Figures in KES.',
    ];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $children = fn (string $sql) => array_reduce($this->rows($sql), static function ($out, $r) {
                $out[(int) $r['donor_report_id']][] = $r;

                return $out;
            }, []);
            $lines   = $children('SELECT l.*, a.code, a.name AS account_name FROM {donor_report_lines} l JOIN {accounts} a ON a.id = l.account_id ORDER BY a.code');
            $checks  = $children('SELECT * FROM {donor_report_checks} ORDER BY sort_order');
            $queries = $children('SELECT * FROM {donor_report_queries} ORDER BY raised_on, id');
            $trails  = $this->trails('donor_report');

            $documents = (new AttachmentRepository())->byObject('donor_report');

            return array_map(function ($r) use ($lines, $checks, $queries, $trails, $documents) {
                $id = (int) $r['id'];
                $reportLines = array_map(static fn ($l) => [
                    'code' => $l['code'], 'name' => $l['account_name'], 'budget' => self::num($l['budget']),
                    'period' => self::num($l['period_amount']), 'cumulative' => self::num($l['cumulative_amount']),
                ], $lines[$id] ?? []);
                $cumulative = round(array_sum(array_column($reportLines, 'cumulative')), 2);
                $adjustment = round((float) $r['reported_adjustment'], 2);

                return [
                    'id'          => $id,
                    'entityId'    => (int) $r['entity_id'],
                    'ref'         => $r['reference'],
                    'title'       => $r['title'],
                    'funder'      => $r['funder_name'],
                    'grantId'     => (int) $r['grant_id'],
                    'grant'       => $r['short_name'],
                    'grantRef'    => $r['award_ref'],
                    'period'      => self::periodLabel($r['period_starts_on'], $r['period_ends_on']),
                    'from'        => $r['period_starts_on'],
                    'to'          => $r['period_ends_on'],
                    'typeKey'     => $r['type'],
                    'type'        => self::label($r['type'], self::TYPE_LABELS),
                    'dueOn'       => $r['due_on'],
                    'due'         => self::dmy($r['due_on']),
                    'dueDays'     => Clock::daysUntil($r['due_on']),
                    'statusKey'   => $r['status'],
                    'status'      => self::statusLabel($r),
                    'preparerId'  => (int) $r['prepared_by'],
                    'preparer'    => $this->lookups->shortName((int) $r['prepared_by']),
                    'reviewer'    => $r['reviewed_by'] === null ? null : $this->lookups->shortName((int) $r['reviewed_by']),
                    'note'        => $r['note'] ?? '',
                    'received'    => self::num($r['funds_received']),
                    'reportedAdj' => self::num($adjustment),
                    'cumulative'  => self::num($cumulative),
                    'reported'    => self::num($cumulative + $adjustment),
                    'tied'        => abs($adjustment) < 0.005,
                    'figuresTaken' => $r['figures_taken_at'] === null ? null : self::dmy($r['figures_taken_at']),
                    'submittedOn' => $r['submitted_at'] === null ? null : substr($r['submitted_at'], 0, 10),
                    'lines'       => $reportLines,
                    'compliance'  => array_column($checks[$id] ?? [], 'text'),
                    // Recommended: the report as submitted, and the donor's acknowledgement.
                    'attachments' => $documents[$id] ?? [],
                    'queries'     => array_map(static fn ($q) => [
                        'ref' => $q['reference'], 'when' => self::dmy($q['raised_on']), 'text' => $q['text'], 'response' => $q['response'] ?? '',
                        'answered' => $q['responded_on'] !== null,
                    ], $queries[$id] ?? []),
                    'trail'       => $trails[$id] ?? [],
                ];
            }, $this->rows(
                'SELECT r.*, g.award_ref, g.short_name, f.name AS funder_name FROM {donor_reports} r
                 JOIN {grants} g ON g.id = r.grant_id JOIN {funders} f ON f.id = g.funder_id ORDER BY r.due_on DESC, r.reference'
            ));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['ref'] === $ref) {
                return $r;
            }
        }

        return null;
    }

    public static function statusLabel(array $r): string
    {
        if ($r['status'] === 'draft' && Clock::daysUntil($r['due_on']) < 0) {
            return 'Overdue';
        }

        return self::label($r['status']);
    }

    /** "Apr – Jun 26", "Jul 24 – Sep 26", "Jan – Dec 25". */
    public static function periodLabel(?string $start, ?string $end): string
    {
        if (!$start || !$end) {
            return 'Full period';
        }

        $sameYear = substr($start, 0, 4) === substr($end, 0, 4);

        return date($sameYear ? 'M' : 'M y', strtotime($start)) . ' – ' . date('M y', strtotime($end));
    }

    /** DR-26-019: the donor report series for the year the report falls due. */
    public function nextReference(string $due): string
    {
        $prefix = 'DR-' . substr($due, 2, 2) . '-';
        $last = 0;
        foreach ($this->rows('SELECT reference FROM {all:donor_reports} WHERE reference LIKE ?', [$prefix . '%']) as $r) {
            $last = max($last, (int) substr($r['reference'], strlen($prefix)));
        }

        return $prefix . str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    // ---- Figures from the ledger ----

    /**
     * What the ledger holds for an award over a reporting period: for each account
     * in donor reports that the award budgets for or has been charged to, the
     * award's budget, what was posted in the period, and what was posted from the
     * start to the period's end; and the disbursements received by then.
     *
     * @return array{lines: list<array{accountId: int, code: string, budget: float, period: float, cumulative: float}>, received: float}
     */
    public function ledgerFigures(int $grantId, string $from, string $to): array
    {
        $budget = [];
        foreach ($this->rows(
            'SELECT b.account_id, SUM(b.amount) AS amount FROM {grant_budget_lines} b WHERE b.grant_id = ? GROUP BY b.account_id', [$grantId]
        ) as $b) {
            $budget[(int) $b['account_id']] = (float) $b['amount'];
        }

        $posted = [];
        foreach ($this->rows(
            "SELECT l.account_id, a.type,
                    SUM(CASE WHEN j.journal_date >= ? THEN l.debit - l.credit ELSE 0 END) AS in_period,
                    SUM(l.debit - l.credit) AS to_date
             FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
             WHERE l.grant_id = ? AND j.status IN ('posted', 'reversed') AND j.journal_date <= ?
             GROUP BY l.account_id, a.type",
            [$from, $grantId, $to]
        ) as $p) {
            // Income, receivables and bank movements are not expenditure; an account
            // the award budgets for (equipment, say) is, whatever its type.
            if ($p['type'] === 'expense' || isset($budget[(int) $p['account_id']])) {
                $posted[(int) $p['account_id']] = $p;
            }
        }

        $accounts = [];
        foreach ($this->rows('SELECT id, code, in_donor_reports FROM {accounts}') as $a) {
            $accounts[(int) $a['id']] = $a;
        }

        $lines = [];
        foreach (array_unique(array_merge(array_keys($budget), array_keys($posted))) as $accountId) {
            $a = $accounts[$accountId] ?? null;
            if ($a === null || !(bool) $a['in_donor_reports']) {
                continue;
            }
            $lines[] = [
                'accountId'  => $accountId,
                'code'       => $a['code'],
                'budget'     => round($budget[$accountId] ?? 0, 2),
                'period'     => round((float) ($posted[$accountId]['in_period'] ?? 0), 2),
                'cumulative' => round((float) ($posted[$accountId]['to_date'] ?? 0), 2),
            ];
        }
        usort($lines, static fn ($x, $y) => strcmp($x['code'], $y['code']));

        $received = (float) $this->value(
            "SELECT COALESCE(SUM(amount), 0) FROM {grant_tranches} WHERE grant_id = ? AND status = 'received' AND (received_on IS NULL OR received_on <= ?)",
            [$grantId, $to]
        );

        return ['lines' => $lines, 'received' => round($received, 2)];
    }

    /**
     * Takes a draft's figures from the ledger again. Any manual adjustment goes:
     * the report is what the ledger says, and so ties.
     */
    public function refresh(string $ref, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'draft') {
            throw new RuleViolation($ref . ' is ' . strtolower($r['status']) . '. Its figures are the ones sent for review and are no longer retaken from the ledger.');
        }

        return $this->transaction(function () use ($r, $actorId) {
            $total = $this->takeFigures($r['id'], $r['grantId'], $r['from'], $r['to']);
            $this->audit('donor_report', $r['id'], $r['ref'], 'Figures taken from the ledger by ' . $this->lookups->shortName($actorId)
                . ': ' . number_format($total) . ' spent to ' . self::dmy($r['to']), $actorId, 'donor_report.refreshed', $r['entityId']);

            return ['ref' => $r['ref'], 'message' => $r['ref'] . ' now carries the ledger as posted: ' . number_format($total) . ' spent to ' . self::dmy($r['to']) . '.'];
        });
    }

    private function takeFigures(int $reportId, int $grantId, string $from, string $to): float
    {
        $figures = $this->ledgerFigures($grantId, $from, $to);

        $this->db->table('donor_report_lines')->where('donor_report_id', $reportId)->delete();
        foreach ($figures['lines'] as $l) {
            $this->insert('donor_report_lines', [
                'donor_report_id' => $reportId, 'account_id' => $l['accountId'], 'budget' => $l['budget'],
                'period_amount' => $l['period'], 'cumulative_amount' => $l['cumulative'],
            ]);
        }
        $this->db->table('donor_reports')->where('id', $reportId)->update([
            'funds_received' => $figures['received'], 'reported_adjustment' => 0,
            'figures_taken_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
        ]);

        return array_sum(array_column($figures['lines'], 'cumulative'));
    }

    /** Takes a manual adjustment off a draft, so what is reported is what is posted. */
    public function removeAdjustment(string $ref, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'draft') {
            throw new RuleViolation($ref . ' is ' . strtolower($r['status']) . ' and can no longer be changed.');
        }
        if ($r['tied']) {
            throw new RuleViolation($ref . ' carries no manual adjustment; it already ties to the ledger.');
        }

        return $this->transaction(function () use ($r, $actorId) {
            $this->db->table('donor_reports')->where('id', $r['id'])->update(['reported_adjustment' => 0, 'updated_at' => Clock::timestamp()]);
            $this->audit('donor_report', $r['id'], $r['ref'], 'Manual adjustment of ' . number_format($r['reportedAdj']) . ' removed by '
                . $this->lookups->shortName($actorId), $actorId, 'donor_report.adjusted', $r['entityId']);

            return ['ref' => $r['ref'], 'message' => 'The adjustment is removed. ' . $r['ref'] . ' reports ' . number_format($r['cumulative']) . ', as posted.'];
        });
    }

    // ---- A new report ----

    /**
     * Raises a draft for an award and period, with its figures taken from the
     * ledger. The period sits inside the award's, and the report falls due after it.
     *
     * @param array{grant?: string, title?: string, type?: string, from?: string, to?: string, due?: string} $in
     */
    public function create(array $in, int $actorId): array
    {
        $grant = $this->row('SELECT g.*, f.name AS funder_name FROM {grants} g JOIN {funders} f ON f.id = g.funder_id WHERE g.award_ref = ?', [trim((string) ($in['grant'] ?? ''))]);
        if ($grant === null) {
            throw new RuleViolation('Choose the award the report is for.');
        }
        if ($grant['starts_on'] === null || in_array($grant['status'], ['pipeline'], true)) {
            throw new RuleViolation($grant['award_ref'] . ' is not yet signed, so there is nothing to report against it.');
        }

        $title = trim((string) ($in['title'] ?? ''));
        $type  = (string) ($in['type'] ?? '');
        $date  = static fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) === 1 ? (string) $in[$k] : null;
        [$from, $to, $due] = [$date('from'), $date('to'), $date('due')];

        $problem = match (true) {
            $title === ''                         => 'Give the report the title the funder knows it by, e.g. "Financial report Q4 2026".',
            !isset(self::TYPES[$type])            => 'Choose whether the report is financial, narrative or a close-out.',
            $from === null || $to === null        => 'Give the reporting period: its first and last day.',
            $to < $from                           => 'The period ends before it starts.',
            $from < $grant['starts_on'] || $to > $grant['ends_on'] => 'The period falls outside the award, which runs ' . self::dmy($grant['starts_on']) . ' – ' . self::dmy($grant['ends_on']) . '.',
            $due === null                         => 'Give the date the funder expects the report.',
            $due < $to                            => 'The report cannot fall due before its period ends.',
            default                               => null,
        };
        if ($problem !== null) {
            throw new RuleViolation($problem);
        }
        if ($this->value('SELECT id FROM {donor_reports} WHERE grant_id = ? AND period_starts_on = ? AND period_ends_on = ? AND type = ?', [$grant['id'], $from, $to, $type]) !== null) {
            throw new RuleViolation('There is already a ' . strtolower(self::TYPES[$type]) . ' report for ' . $grant['award_ref'] . ' over ' . self::periodLabel($from, $to) . '.');
        }

        return $this->transaction(function () use ($grant, $title, $type, $from, $to, $due, $actorId) {
            $ref = $this->nextReference($due);
            $id = $this->insert('donor_reports', [
                'entity_id' => (int) $grant['entity_id'], 'reference' => $ref, 'grant_id' => $grant['id'], 'title' => mb_substr($title, 0, 255),
                'type' => $type, 'period_starts_on' => $from, 'period_ends_on' => $to, 'due_on' => $due, 'status' => 'draft',
                'funds_received' => 0, 'reported_adjustment' => 0, 'prepared_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
            $total = $this->takeFigures($id, (int) $grant['id'], $from, $to);
            $this->audit('donor_report', $id, $ref, 'Report generated from ledger by ' . $this->lookups->shortName($actorId), $actorId, 'donor_report.created', (int) $grant['entity_id']);

            return ['ref' => $ref, 'message' => $ref . ' raised for ' . $grant['funder_name'] . ' with ' . number_format($total) . ' spent to ' . self::dmy($to) . '.'];
        });
    }

    /** Awards a report can be raised for: signed, with their periods. */
    public function reportableAwards(): array
    {
        return array_map(static fn ($g) => [
            'ref' => $g['award_ref'], 'label' => $g['short_name'] . ' · ' . $g['funder_name'], 'from' => $g['starts_on'], 'to' => $g['ends_on'],
        ], $this->rows(
            "SELECT g.award_ref, g.short_name, g.starts_on, g.ends_on, f.name AS funder_name FROM {grants} g JOIN {funders} f ON f.id = g.funder_id
             WHERE g.starts_on IS NOT NULL AND g.status <> 'pipeline' ORDER BY g.short_name"
        ));
    }

    // ---- The path to the funder ----

    /** Draft → in review. Only a report that ties, and has figures if it reports money, goes. */
    public function sendForReview(string $ref, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'draft') {
            throw new RuleViolation($ref . ' is ' . strtolower($r['status']) . ', not a draft.');
        }
        $this->mustTie($r, 'sent for review');
        if ($r['lines'] === [] && $r['typeKey'] !== 'narrative') {
            throw new RuleViolation($ref . ' has no figures yet. Take them from the ledger first.');
        }

        return $this->move($r, 'in_review', [], 'Sent for review by ' . $this->lookups->shortName($actorId), $actorId,
            $ref . ' sent for review. Someone other than ' . $r['preparer'] . ' submits it to ' . $r['funder'] . '.');
    }

    /** In review → back to draft, with the reviewer's reason on its history. */
    public function returnToPreparer(string $ref, string $note, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'in_review') {
            throw new RuleViolation($ref . ' is not in review.');
        }
        $note = trim($note);
        if ($note === '') {
            throw new RuleViolation('Say what ' . $r['preparer'] . ' should change before the report comes back.');
        }

        return $this->move($r, 'draft', [], 'Returned to ' . $r['preparer'] . ' by ' . $this->lookups->shortName($actorId) . ': ' . $note, $actorId,
            $ref . ' returned to ' . $r['preparer'] . '.');
    }

    /**
     * In review → submitted. The reviewer is whoever submits, never the preparer,
     * and submitting confirms the compliance statements on the report.
     */
    public function submit(string $ref, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'in_review') {
            throw new RuleViolation($ref . ' has to be reviewed before it is submitted.');
        }
        $this->mustTie($r, 'submitted');
        if ($actorId === $r['preparerId']) {
            throw new RuleViolation($r['preparer'] . ' prepared ' . $ref . '. Someone else reviews it and submits it to ' . $r['funder'] . '.');
        }

        $now = Clock::timestamp();
        $this->db->table('donor_report_checks')->where('donor_report_id', $r['id'])->where('is_confirmed', 0)
            ->update(['is_confirmed' => 1, 'confirmed_by' => $actorId, 'confirmed_at' => $now]);

        return $this->move($r, 'submitted', ['reviewed_by' => $actorId, 'submitted_at' => $now],
            'Approved by ' . $this->lookups->shortName($actorId) . ' and submitted to ' . $r['funder'], $actorId,
            $ref . ' submitted to ' . $r['funder'] . '.');
    }

    /** Submitted → queried: the funder's question, in its words, with its reference. */
    public function recordQuery(string $ref, string $text, ?string $queryRef, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'submitted') {
            throw new RuleViolation('A query is recorded against a report the funder holds; ' . $ref . ' is ' . strtolower($r['status']) . '.');
        }
        $text = trim($text);
        if ($text === '') {
            throw new RuleViolation('Record the funder\'s query as they put it.');
        }
        $queryRef = trim((string) $queryRef);
        if ($queryRef === '') {
            $queryRef = $this->nextQueryReference();
        } elseif (in_array($queryRef, array_column($r['queries'], 'ref'), true)) {
            throw new RuleViolation($queryRef . ' is already recorded against ' . $ref . '.');
        }

        $this->insert('donor_report_queries', [
            'donor_report_id' => $r['id'], 'reference' => mb_substr($queryRef, 0, 20), 'raised_on' => Clock::date(), 'text' => $text, 'created_at' => Clock::timestamp(),
        ]);

        return $this->move($r, 'queried', [], 'Query ' . $queryRef . ' raised by the funder, recorded by ' . $this->lookups->shortName($actorId), $actorId,
            $queryRef . ' recorded against ' . $ref . '.');
    }

    /**
     * Queried → submitted. Every open query goes back with an answer: the one
     * given here, or the one already drafted on it.
     *
     * @param array<string, string> $responses by query reference
     */
    public function respond(string $ref, array $responses, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'queried') {
            throw new RuleViolation($ref . ' has no query outstanding.');
        }

        $open = array_filter($r['queries'], static fn ($q) => !$q['answered']);
        $answers = [];
        foreach ($open as $q) {
            $answer = trim((string) ($responses[$q['ref']] ?? $q['response']));
            if ($answer === '') {
                throw new RuleViolation('Answer ' . $q['ref'] . ' before the response goes to ' . $r['funder'] . '.');
            }
            $answers[$q['ref']] = $answer;
        }

        $today = Clock::date();
        foreach ($answers as $queryRef => $answer) {
            $this->db->table('donor_report_queries')->where('donor_report_id', $r['id'])->where('reference', $queryRef)
                ->update(['response' => $answer, 'responded_by' => $actorId, 'responded_on' => $today, 'updated_at' => Clock::timestamp()]);
        }

        return $this->move($r, 'submitted', [], 'Response to ' . implode(', ', array_keys($answers)) . ' sent to ' . $r['funder'] . ' by ' . $this->lookups->shortName($actorId), $actorId,
            'Response to ' . implode(', ', array_keys($answers)) . ' sent to ' . $r['funder'] . '.');
    }

    /** Submitted → accepted, on the funder's acceptance letter. */
    public function accept(string $ref, int $actorId): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] !== 'submitted') {
            throw new RuleViolation($r['statusKey'] === 'queried'
                ? $ref . ' has a query outstanding. Answer it before filing acceptance.'
                : $ref . ' has not been submitted to ' . $r['funder'] . '.');
        }

        return $this->move($r, 'accepted', ['accepted_at' => Clock::timestamp()], 'Acceptance letter filed by ' . $this->lookups->shortName($actorId), $actorId,
            $ref . ' marked accepted and filed.');
    }

    /** The reviewer's note: for the approver, or the covering letter. */
    public function saveNote(string $ref, string $note): array
    {
        $r = $this->mustFind($ref);
        if ($r['statusKey'] === 'accepted') {
            throw new RuleViolation($ref . ' is accepted and filed; its record is closed.');
        }

        $note = trim($note);
        $this->transaction(fn () => $this->db->table('donor_reports')->where('id', $r['id'])
            ->update(['note' => $note === '' ? null : $note, 'updated_at' => Clock::timestamp()]));

        return ['ref' => $ref, 'message' => 'Note saved.'];
    }

    private function mustFind(string $ref): array
    {
        return $this->find($ref) ?? throw new RuleViolation($ref . ' is not a donor report.');
    }

    private function mustTie(array $r, string $what): void
    {
        if (!$r['tied']) {
            throw new RuleViolation($r['ref'] . ' does not tie to the ledger: ' . number_format($r['reported']) . ' reported against '
                . number_format($r['cumulative']) . ' posted. It cannot be ' . $what . ' until the difference is posted or removed.');
        }
    }

    private function move(array $r, string $status, array $extra, string $history, int $actorId, string $message): array
    {
        return $this->transaction(function () use ($r, $status, $extra, $history, $actorId, $message) {
            $this->db->table('donor_reports')->where('id', $r['id'])->update(['status' => $status, 'updated_at' => Clock::timestamp()] + $extra);
            $this->audit('donor_report', $r['id'], $r['ref'], $history, $actorId, 'donor_report.' . $status, $r['entityId']);

            return ['ref' => $r['ref'], 'message' => $message];
        });
    }

    /** Q-2026-08: funder queries are numbered by the year they are raised in. */
    private function nextQueryReference(): string
    {
        $prefix = 'Q-' . substr(Clock::date(), 0, 4) . '-';
        $last = 0;
        foreach ($this->rows('SELECT q.reference FROM {donor_report_queries} q WHERE q.reference LIKE ?', [$prefix . '%']) as $q) {
            $last = max($last, (int) substr($q['reference'], strlen($prefix)));
        }

        return $prefix . str_pad((string) ($last + 1), 2, '0', STR_PAD_LEFT);
    }

    // ---- Delivery language ----

    /** Funders whose agreements name a delivery language, with their project titles and award figures. */
    public function funderLanguages(): array
    {
        return $this->cached('languages', function () {
            $titles = [];
            foreach ($this->rows(
                "SELECT c.object_id, c.text, l.code FROM {content_translations} c JOIN {locales} l ON l.id = c.locale_id
                 WHERE c.object_type = 'funder' AND c.field = 'project_title'"
            ) as $t) {
                $titles[(int) $t['object_id']][$t['code']] = $t['text'];
            }

            $grants = (new GrantRepository())->all();

            return array_map(function ($f) use ($titles, $grants) {
                $awards  = array_filter($grants, static fn ($g) => $g['funder'] === $f['name']);
                $ceiling = array_sum(array_column($awards, 'value'));
                $spent   = array_sum(array_column($awards, 'spent'));
                $money   = static fn (float $v) => number_format($v, 2);

                return [
                    'funder'  => $f['name'],
                    'ref'     => preg_match('/Award (\S+)/', (string) $f['report_note'], $m) === 1 ? $m[1] : (array_values($awards)[0]['ref'] ?? '—'),
                    'locale'  => $f['locale'],
                    'note'    => $f['report_note'] ?? '',
                    'project' => $titles[(int) $f['id']] ?? [],
                    'figures' => [$money($ceiling), $money($spent), $money($spent), $money($ceiling - $spent)],
                ];
            }, $this->rows(
                'SELECT f.*, l.code AS locale FROM {funders} f JOIN {locales} l ON l.id = f.report_locale_id ORDER BY f.id'
            ));
        });
    }

    public function setLanguage(string $funder, string $locale): bool
    {
        $id = $this->lookups->funderId($funder);
        $localeId = $this->value('SELECT id FROM {locales} WHERE code = ?', [$locale]);
        if ($id === null || $this->value('SELECT report_locale_id FROM {funders} WHERE id = ?', [$id]) === null || $localeId === null) {
            return false;
        }

        $this->transaction(fn () => $this->db->table('funders')->where('id', $id)->update(['report_locale_id' => $localeId, 'updated_at' => Clock::timestamp()]));

        return true;
    }

    /**
     * The cover page wording in one language. The period is the financial year to
     * the end of the last closed month, with its dates in that language.
     */
    public function cover(string $locale): array
    {
        $translations = new TranslationRepository();
        $t = static fn (string $s) => $translations->translation($locale, $s) ?? $s;

        $closed = (new PeriodRepository())->closed();
        $periods = (new Lookups())->yearPeriods();
        $start = $periods[0]['starts_on'] ?? Clock::date();
        $end = $start;
        foreach ($periods as $p) {
            if (in_array($p['name'], $closed, true)) {
                $end = $p['ends_on'];
            }
        }

        $format = static function (string $date) use ($locale) {
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Africa/Nairobi', null, 'd MMM y');

            return $formatter->format(strtotime($date));
        };

        return [
            'prefix'    => $t(self::COVER['prefix']),
            'awardWord' => $t(self::COVER['awardWord']),
            'period'    => $format($start) . ' ' . (self::PERIOD_CONNECTORS[$locale] ?? 'to') . ' ' . $format($end),
            'labels'    => array_map($t, self::COVER['labels']),
            'foot'      => $t(self::COVER['foot']),
            'org'       => $this->organisation(),
        ];
    }

    /**
     * The name on a report's cover, as Settings → Organisation holds it: the short
     * name and the registered one, or just one when they are the same. A proper
     * name, so it is never run through the catalogue.
     */
    private function organisation(): string
    {
        ['registered' => $registered, 'short' => $short] = $this->lookups->organisationNames();

        return $short === $registered ? $registered : $short . ' · ' . $registered;
    }
}
