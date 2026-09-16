<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * Journals and their lines.
 *
 * Posting is the database's decision, not this class's: the integrity triggers
 * refuse an unbalanced journal, a closed or mismatched period, a heading or
 * archived account, and a restricted fund taken below zero, and the CHECK
 * constraints refuse a preparer approving their own entry. This class moves
 * journals through their statuses and reports any refusal in the rule's words.
 */
final class JournalRepository extends Repository
{
    private const TYPES = ['Standard', 'Accrual', 'Reversing', 'Recurring', 'Adjustment', 'Allocation'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** @return list<string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** Every journal, newest first, with its lines and history. */
    public function all(): array
    {
        return $this->cached('all', function () {
            $lines = [];
            foreach ($this->rows(
                'SELECT l.*, a.code, f.ledger_group, g.award_ref
                 FROM {journal_lines} l JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
                 LEFT JOIN {grants} g ON g.id = l.grant_id ORDER BY l.journal_id, l.line_no'
            ) as $l) {
                $lines[(int) $l['journal_id']][] = [
                    'code'     => $l['code'],
                    'desc'     => $l['description'],
                    'fund'     => self::FUND_GROUPS[$l['ledger_group']],
                    'program'  => $this->lookups->programmeName((int) $l['programme_id']),
                    'grantRef' => $l['award_ref'] ?? '',
                    'dr'       => self::num($l['debit']),
                    'cr'       => self::num($l['credit']),
                ];
            }

            $trails = $this->trails('journal');

            return array_map(function ($j) use ($lines, $trails) {
                $journal = [
                    'ref'       => $j['reference'],
                    'date'      => self::dmy($j['journal_date']),
                    'type'      => self::label($j['type']),
                    'period'    => $j['period_name'],
                    'status'    => self::label($j['status']),
                    'preparer'  => $this->lookups->shortName((int) $j['prepared_by']),
                    'doc'       => $j['document_ref'] ?? '',
                    'memo'      => $j['memo'] ?? '',
                    'narration' => $j['narration'],
                    'lines'     => $lines[(int) $j['id']] ?? [],
                    'trail'     => $trails[(int) $j['id']] ?? [],
                ];

                return $j['reverses_ref'] !== null ? $journal + ['reversalOf' => $j['reverses_ref']] : $journal;
            }, $this->rows(
                'SELECT j.*, p.name AS period_name, r.reference AS reverses_ref
                 FROM {journals} j JOIN {periods} p ON p.id = j.period_id LEFT JOIN {journals} r ON r.id = j.reverses_journal_id
                 ORDER BY j.journal_date DESC, j.reference DESC'
            ));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $j) {
            if ($j['ref'] === $ref) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Saves a new journal as a draft or submits it for approval.
     *
     * @param array{date: string, type: string, period: string, status: string, preparer: ?string, doc: string, memo: string,
     *              narration: string, reversalOf?: string, lines: list<array{code: string, desc: string, fund: string, program: string, grantRef: string, dr: float, cr: float}>} $j
     */
    public function create(array $j, int $actorId): array
    {
        $period = $this->lookups->periodByName($j['period']);
        if ($period === null) {
            throw new RuleViolation($j['period'] . ' is not an accounting period.');
        }
        $date = date('Y-m-d', strtotime($j['date']) ?: time());
        $preparer = $this->lookups->userId($j['preparer'] ?? null) ?? $actorId;
        $lines = $this->resolveLines($j['lines']);

        $ref = $this->transaction(function () use ($j, $period, $date, $preparer, $lines) {
            $ref = $this->nextReference('JV', $date);
            $reverses = isset($j['reversalOf']) ? $this->value('SELECT id FROM {journals} WHERE reference = ?', [$j['reversalOf']]) : null;

            $id = $this->insert('journals', [
                'entity_id' => $this->lookups->entityId(), 'period_id' => $period['id'], 'reference' => $ref, 'journal_date' => $date,
                'type' => strtolower($j['type']), 'status' => 'draft',
                'document_type_id' => $this->value('SELECT id FROM {document_types} WHERE prefix = ?', ['JV']),
                'document_ref' => $j['doc'] !== '' ? $j['doc'] : null, 'memo' => $j['memo'] !== '' ? $j['memo'] : null,
                'narration' => $j['narration'], 'prepared_by' => $preparer, 'reverses_journal_id' => $reverses,
                'created_at' => Clock::timestamp(),
            ]);

            foreach ($lines as $i => $l) {
                $this->insert('journal_lines', ['journal_id' => $id, 'line_no' => $i + 1] + $l);
            }

            $this->audit('journal', $id, $ref, 'Draft created by ' . $this->lookups->shortName($preparer), $preparer);

            if ($j['status'] === 'Pending approval') {
                $this->db->table('journals')->where('id', $id)->update(['status' => 'pending_approval', 'submitted_at' => Clock::timestamp()]);
                $approver = $this->lookups->holderOf($this->approverRole());
                $this->audit('journal', $id, $ref, 'Submitted for approval to ' . $this->lookups->shortName($approver, 'the approver'), $preparer);
            }

            return $ref;
        });

        return $this->find($ref);
    }

    /** Approves and posts. The actor must not be the preparer; the database enforces the rest. */
    public function approve(string $ref, int $actorId): array
    {
        $journal = $this->header($ref);
        if ($journal['status'] !== 'pending_approval') {
            throw new RuleViolation('Only entries awaiting approval can be approved. Current status: ' . self::label($journal['status']));
        }
        if ((int) $journal['prepared_by'] === $actorId) {
            throw new RuleViolation($this->lookups->shortName($actorId) . ' prepared ' . $ref . ' and cannot also approve it. It needs a second approver.');
        }

        $this->transaction(function () use ($journal, $ref, $actorId) {
            $now = Clock::timestamp();
            $this->db->table('journals')->where('id', $journal['id'])->update([
                'status' => 'posted', 'approved_by' => $actorId, 'approved_at' => $now, 'posted_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit('journal', (int) $journal['id'], $ref, 'Approved and posted by ' . $this->lookups->shortName($actorId), $actorId);

            if ($journal['reverses_journal_id'] !== null) {
                $original = $this->row('SELECT id, reference FROM {journals} WHERE id = ?', [$journal['reverses_journal_id']]);
                $this->db->table('journals')->where('id', $original['id'])->update(['status' => 'reversed']);
                $this->audit('journal', (int) $original['id'], $original['reference'], 'Reversed by ' . $ref, $actorId);
            }
        });

        return $this->find($ref);
    }

    /** Returns an entry to its preparer as a draft, with the reason on record. */
    public function reject(string $ref, int $actorId, string $reason): array
    {
        $journal = $this->header($ref);
        if ($journal['status'] !== 'pending_approval') {
            throw new RuleViolation('Only entries awaiting approval can be rejected. Current status: ' . self::label($journal['status']));
        }

        $this->transaction(function () use ($journal, $ref, $actorId, $reason) {
            $this->db->table('journals')->where('id', $journal['id'])->update([
                'status' => 'draft', 'rejected_reason' => $reason !== '' ? $reason : null, 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('journal', (int) $journal['id'], $ref, 'Rejected by ' . $this->lookups->shortName($actorId) . ($reason !== '' ? ': ' . $reason : ''), $actorId);
        });

        return $this->find($ref);
    }

    /**
     * Posted movements on one account, oldest first, for the general ledger.
     *
     * @return list<array{date: string, ref: string, narration: string, source: string, fund: string, program: string, contra: string, debit: float, credit: float}>
     */
    public function postings(string $code): array
    {
        return $this->cached("postings:{$code}", function () use ($code) {
            $rows = $this->rows(
                "SELECT l.id, l.journal_id, l.debit, l.credit, l.description, l.programme_id, f.ledger_group,
                        j.reference, j.journal_date, j.narration, d.name AS source
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
                 JOIN {funds} f ON f.id = l.fund_id LEFT JOIN {document_types} d ON d.id = j.document_type_id
                 WHERE a.code = ? AND j.status IN ('posted', 'reversed') ORDER BY j.journal_date, j.reference, l.line_no",
                [$code]
            );

            // The contra account is the largest line on the other side of the same journal.
            $contras = [];
            if ($rows !== []) {
                $ids = array_unique(array_column($rows, 'journal_id'));
                foreach ($this->rows(
                    'SELECT l.journal_id, l.debit, l.credit, a.code FROM {journal_lines} l JOIN {accounts} a ON a.id = l.account_id
                     WHERE l.journal_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY l.debit + l.credit DESC'
                ) as $l) {
                    $side = $l['debit'] > 0 ? 'dr' : 'cr';
                    if ($l['code'] !== $code) {
                        $contras[$l['journal_id']][$side] ??= $l['code'];
                    }
                }
            }

            return array_map(fn ($r) => [
                'date'      => $r['journal_date'],
                'ref'       => $r['reference'],
                'narration' => $r['narration'] !== '' ? $r['narration'] : $r['description'],
                'source'    => $r['source'] ?? 'Journal',
                'fund'      => self::FUND_GROUPS[$r['ledger_group']],
                'program'   => $this->lookups->programmeName((int) $r['programme_id']),
                'contra'    => $contras[$r['journal_id']][$r['debit'] > 0 ? 'cr' : 'dr'] ?? '',
                'debit'     => (float) $r['debit'],
                'credit'    => (float) $r['credit'],
            ], $rows);
        });
    }

    private function header(string $ref): array
    {
        $journal = $this->row('SELECT * FROM {journals} WHERE reference = ?', [$ref]);
        if ($journal === null) {
            throw new RuleViolation('Journal not found');
        }

        return $journal;
    }

    private function approverRole(): string
    {
        return (string) ($this->value(
            "SELECT r.name FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id WHERE ar.document_type = 'journal' LIMIT 1"
        ) ?? 'Finance Manager');
    }

    /** JV-26-0323: one more than the highest issued for the prefix and year, never reused. */
    private function nextReference(string $prefix, string $date): string
    {
        $stem = $prefix . '-' . substr($date, 2, 2) . '-';
        $max  = 0;
        foreach ($this->rows('SELECT reference FROM {journals} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Screen lines → journal_lines rows. Income and expenditure lines are resolved
     * first; a balance-sheet line in a grant fund follows the income or expenditure
     * line of the same programme, so both sides land in the same fund.
     */
    private function resolveLines(array $lines): array
    {
        $accounts = $this->lookups->accounts();
        $resolved = [];

        foreach ([true, false] as $incomeOrExpensePass) {
            foreach ($lines as $i => $l) {
                $account = $accounts[$l['code']] ?? null;
                if ($account === null) {
                    throw new RuleViolation('Account ' . $l['code'] . ' is not in the chart of accounts.');
                }
                if (in_array($account['type'], ['income', 'expense'], true) !== $incomeOrExpensePass) {
                    continue;
                }

                $grantId = $l['grantRef'] !== '' ? $this->lookups->grantId($l['grantRef']) : null;
                $fundId  = null;
                if (!$incomeOrExpensePass && $grantId === null && $l['fund'] === 'Grant Fund') {
                    foreach ($resolved as $k => $r) {
                        if ($lines[$k]['fund'] === 'Grant Fund' && $lines[$k]['program'] === $l['program']) {
                            $fundId = $r['fund_id'];
                            break;
                        }
                    }
                }
                $fundId ??= $this->lookups->resolveFund($l['fund'], $grantId, $l['program'], $l['code'], $incomeOrExpensePass);

                $programme = $this->lookups->programmeId($l['program']);
                if ($programme === null) {
                    throw new RuleViolation($l['program'] . ' is not a programme.');
                }

                $resolved[$i] = [
                    'account_id'   => (int) $account['id'],
                    'fund_id'      => $fundId,
                    'programme_id' => $programme,
                    'grant_id'     => $grantId ?? $this->lookups->grantOfFund($fundId),
                    'description'  => mb_substr($l['desc'] !== '' ? $l['desc'] : $account['name'], 0, 255),
                    'debit'        => $l['dr'],
                    'credit'       => $l['cr'],
                ];
            }
        }

        ksort($resolved);

        return array_values($resolved);
    }
}
