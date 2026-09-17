<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * Bank reconciliation: each cash account's statement for a period set against its
 * cash book — the posted ledger lines on the account in that period.
 *
 * The cash book opens where the last reconciliation agreed, on the statement's
 * opening balance, so a month is reconciled on its own movements: the statement
 * closing balance must equal the cash book closing balance once the postings the
 * bank has not yet cleared are set aside. Every statement line has to be matched
 * to postings, or journalised first when the bank raised it on its own account.
 *
 * A match joins statement lines to postings whose amounts agree exactly. Signing a
 * reconciliation off completes it and satisfies the period-close check; while it is
 * completed, or its period is closed, nothing on it can change.
 */
final class BankRepository extends Repository
{
    /**
     * What each kind of bank entry is journalised against. Money in credits the
     * account named here; money out debits it.
     */
    public const BANK_ENTRIES = [
        'charges'      => ['account' => '5340', 'label' => 'Post bank charges', 'mobile' => 'Post transaction charges', 'desc' => 'Bank charges and fees per statement'],
        'interest'     => ['account' => '4230', 'label' => 'Post interest received', 'desc' => 'Interest credited per statement'],
        'fx_gain'      => ['account' => '4240', 'label' => 'Post exchange gain', 'desc' => 'Exchange gain credited per statement'],
        'unidentified' => ['account' => '2190', 'label' => 'Post to suspense', 'desc' => 'Unidentified credit held in suspense'],
    ];

    private Lookups $lookups;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    /**
     * The cash accounts that are reconciled to a statement, with the periods each
     * has a statement for, newest first.
     *
     * @return list<array{code: string, name: string, short: string, kind: string, periods: list<string>}>
     */
    public function accounts(): array
    {
        return $this->cached('accounts', function () {
            $periods = [];
            foreach ($this->rows(
                'SELECT s.bank_account_id, p.name FROM {bank_statements} s JOIN {periods} p ON p.id = s.period_id ORDER BY p.starts_on DESC'
            ) as $r) {
                $periods[(int) $r['bank_account_id']][] = $r['name'];
            }

            return array_values(array_map(static fn ($b) => [
                'code' => $b['code'], 'name' => $b['name'], 'short' => $b['short_name'], 'kind' => $b['kind'], 'periods' => $periods[(int) $b['id']],
            ], array_filter($this->lookups->bankAccounts(), static fn ($b) => isset($periods[(int) $b['id']]))));
        });
    }

    /**
     * Cash accounts whose reconciliation for their latest statement is not signed off.
     *
     * @return list<array{code: string, short: string, period: string}>
     */
    public function unreconciled(): array
    {
        return array_values(array_map(static fn ($a) => ['code' => $a['code'], 'short' => $a['short'], 'period' => $a['periods'][0]], array_filter(
            $this->accounts(),
            fn ($a) => $this->header($a['code'], $a['periods'][0])['status'] !== 'completed'
        )));
    }

    /**
     * One account's reconciliation for a period (its latest statement when none is
     * named): the statement, the cash book, the matches and the figures.
     */
    public function reconciliation(string $code, ?string $period = null): array
    {
        $account = current(array_filter($this->accounts(), static fn ($a) => $a['code'] === $code));
        if ($account === false) {
            throw new RuleViolation('Account ' . $code . ' has no bank statement to reconcile.');
        }
        $period = in_array($period, $account['periods'], true) ? $period : $account['periods'][0];

        return $this->cached("reconciliation:{$code}:{$period}", function () use ($account, $period) {
            $h = $this->header($account['code'], $period);

            $matchOfStatement = $matchOfBook = [];
            foreach ($this->rows('SELECT ms.reconciliation_match_id AS m, ms.bank_statement_line_id AS line FROM {reconciliation_match_statement_lines} ms JOIN {reconciliation_matches} rm ON rm.id = ms.reconciliation_match_id WHERE rm.reconciliation_id = ?', [$h['id']]) as $r) {
                $matchOfStatement[(int) $r['line']] = (int) $r['m'];
            }
            foreach ($this->rows('SELECT mj.reconciliation_match_id AS m, mj.journal_line_id AS line FROM {reconciliation_match_journal_lines} mj JOIN {reconciliation_matches} rm ON rm.id = mj.reconciliation_match_id WHERE rm.reconciliation_id = ?', [$h['id']]) as $r) {
                $matchOfBook[(int) $r['line']] = (int) $r['m'];
            }

            // Journals raised from a statement line, newest first, so the live one wins.
            $raised = [];
            foreach ($this->rows(
                "SELECT j.source_id, j.reference, j.status FROM {journals} j JOIN {bank_statement_lines} l ON l.id = j.source_id
                 WHERE j.source_type = 'bank_statement_line' AND l.bank_statement_id = ? ORDER BY j.id",
                [$h['bank_statement_id']]
            ) as $r) {
                $raised[(int) $r['source_id']] = ['ref' => $r['reference'], 'status' => $r['status']];
            }

            $statement = array_map(static fn ($l) => [
                'id' => (int) $l['id'], 'date' => self::dm($l['line_date']), 'ref' => $l['reference'] ?? '', 'desc' => $l['description'],
                'amt' => self::num($l['amount']), 'entry' => $l['bank_entry'], 'match' => $matchOfStatement[(int) $l['id']] ?? null,
                'raised' => $raised[(int) $l['id']] ?? null,
            ], $this->rows('SELECT * FROM {bank_statement_lines} WHERE bank_statement_id = ? ORDER BY line_date, id', [$h['bank_statement_id']]));

            $book = array_map(static fn ($l) => [
                'id' => (int) $l['id'], 'date' => self::dm($l['journal_date']), 'ref' => $l['reference'],
                'desc' => $l['description'] !== '' ? $l['description'] : $l['narration'], 'amt' => self::num($l['debit'] - $l['credit']),
                'match' => $matchOfBook[(int) $l['id']] ?? null, 'raised' => $l['source_type'] === 'bank_statement_line',
            ], $this->bookLines($h));

            return [
                'id' => (int) $h['id'], 'code' => $account['code'], 'name' => $account['name'], 'short' => $account['short'], 'kind' => $account['kind'],
                'period' => $period, 'periods' => $account['periods'], 'periodOpen' => $h['period_status'] === 'open',
                'status' => $h['status'], 'statementRef' => $h['reference'], 'opening' => self::num($h['opening_balance']),
                'reviewedBy' => $h['reviewed_by'] === null ? null : $this->lookups->shortName((int) $h['reviewed_by']),
                'completedAt' => $h['completed_at'] === null ? null : self::dmy($h['completed_at']),
                'periodEnd' => self::dmy($h['ends_on']),
                'statement' => $statement, 'book' => $book,
            ] + self::figures((float) $h['opening_balance'], $statement, $book);
        });
    }

    /**
     * The reconciliation statement: closing balances, what the bank has not yet
     * cleared, and the difference left unexplained.
     */
    private static function figures(float $opening, array $statement, array $book): array
    {
        $sum = static fn (array $lines) => round(array_sum(array_column($lines, 'amt')), 2);
        $uncleared = array_values(array_filter($book, static fn ($l) => $l['match'] === null));
        $statementClose = $opening + $sum($statement);
        $bookClose = $opening + $sum($book);
        $adjusted = $bookClose - $sum($uncleared);

        return [
            'statementClose' => $statementClose,
            'bookClose'      => $bookClose,
            'unclearedPayments' => array_values(array_filter($uncleared, static fn ($l) => $l['amt'] < 0)),
            'unclearedReceipts' => array_values(array_filter($uncleared, static fn ($l) => $l['amt'] > 0)),
            'unmatchedStatement' => array_values(array_filter($statement, static fn ($l) => $l['match'] === null)),
            'adjustedBook'   => $adjusted,
            'difference'     => round($statementClose - $adjusted, 2),
        ];
    }

    // ------------------------------------------------------------------
    // Matching
    // ------------------------------------------------------------------

    /**
     * Matches statement lines to postings. Every line must belong to this
     * reconciliation and be unmatched, and the two sides must agree exactly.
     *
     * @param list<int> $statementIds
     * @param list<int> $bookIds journal line ids
     */
    public function match(string $code, string $period, array $statementIds, array $bookIds, int $actorId): array
    {
        $r = $this->editable($code, $period);
        $statementIds = array_values(array_unique(array_map('intval', $statementIds)));
        $bookIds = array_values(array_unique(array_map('intval', $bookIds)));
        if ($statementIds === [] || $bookIds === []) {
            throw new RuleViolation('Select at least one line on each side.');
        }

        $statement = array_column($r['statement'], null, 'id');
        $book = array_column($r['book'], null, 'id');
        foreach ($statementIds as $id) {
            if (!isset($statement[$id])) {
                throw new RuleViolation('A selected statement line is not on the ' . $r['short'] . ' statement for ' . $period . '.');
            }
            if ($statement[$id]['match'] !== null) {
                throw new RuleViolation('The statement line ' . $statement[$id]['ref'] . ' is already matched.');
            }
        }
        foreach ($bookIds as $id) {
            if (!isset($book[$id])) {
                throw new RuleViolation('A selected posting is not in the ' . $r['code'] . ' cash book for ' . $period . '.');
            }
            if ($book[$id]['match'] !== null) {
                throw new RuleViolation($book[$id]['ref'] . ' is already matched.');
            }
        }

        $s = round(array_sum(array_map(static fn ($id) => $statement[$id]['amt'], $statementIds)), 2);
        $b = round(array_sum(array_map(static fn ($id) => $book[$id]['amt'], $bookIds)), 2);
        if ($s != $b) {
            throw new RuleViolation('The two sides differ by ' . self::money($s - $b) . ' — a match must agree exactly.');
        }

        $this->transaction(function () use ($r, $statementIds, $bookIds, $actorId, $book) {
            $this->insertMatch($r['id'], $statementIds, $bookIds, $actorId);
            $this->audit('reconciliation', $r['id'], $r['code'] . ' ' . $r['period'],
                count($statementIds) . (count($statementIds) === 1 ? ' statement line' : ' statement lines') . ' matched to '
                . implode(', ', array_unique(array_map(static fn ($id) => $book[$id]['ref'], $bookIds))) . ' by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return ['statement' => count($statementIds), 'book' => count($bookIds)];
    }

    /** Undoes the match a statement line is in, freeing every line in it. */
    public function unmatch(string $code, string $period, int $statementLineId, int $actorId): void
    {
        $r = $this->editable($code, $period);
        $line = current(array_filter($r['statement'], static fn ($l) => $l['id'] === $statementLineId));
        if ($line === false || $line['match'] === null) {
            throw new RuleViolation('That statement line is not matched.');
        }

        $this->transaction(function () use ($r, $line, $actorId) {
            $this->db->table('reconciliation_match_statement_lines')->where('reconciliation_match_id', $line['match'])->delete();
            $this->db->table('reconciliation_match_journal_lines')->where('reconciliation_match_id', $line['match'])->delete();
            $this->db->table('reconciliation_matches')->where('id', $line['match'])->delete();
            $this->audit('reconciliation', $r['id'], $r['code'] . ' ' . $r['period'], 'Match on ' . $line['ref'] . ' undone by ' . $this->lookups->shortName($actorId), $actorId);
        });
    }

    /**
     * Pairs each unmatched statement line with the first uncleared posting of exactly
     * the same amount. What is left needs a judgement call.
     *
     * @return int the number of pairs matched
     */
    public function autoMatch(string $code, string $period, int $actorId): int
    {
        $r = $this->editable($code, $period);
        $used = [];
        $pairs = [];
        foreach ($r['unmatchedStatement'] as $s) {
            foreach (array_merge($r['unclearedPayments'], $r['unclearedReceipts']) as $b) {
                if (!isset($used[$b['id']]) && round($b['amt'], 2) == round($s['amt'], 2)) {
                    $used[$b['id']] = true;
                    $pairs[] = [$s['id'], $b['id']];
                    break;
                }
            }
        }

        if ($pairs !== []) {
            $this->transaction(function () use ($r, $pairs, $actorId) {
                foreach ($pairs as [$s, $b]) {
                    $this->insertMatch($r['id'], [$s], [$b], $actorId);
                }
                $this->audit('reconciliation', $r['id'], $r['code'] . ' ' . $r['period'],
                    count($pairs) . (count($pairs) === 1 ? ' line' : ' lines') . ' matched on exact amount by ' . $this->lookups->shortName($actorId), $actorId);
            });
        }

        return count($pairs);
    }

    // ------------------------------------------------------------------
    // Journalising the bank's own entries
    // ------------------------------------------------------------------

    /**
     * Raises the journal for a line the bank put through on its own account and sends
     * it for approval. The line stays unmatched until the journal posts; approval
     * matches the two (see matchRaised).
     *
     * @return array the journal, as the register serves it
     */
    public function journalise(string $code, string $period, int $statementLineId, int $actorId): array
    {
        $r = $this->editable($code, $period);
        $line = current(array_filter($r['statement'], static fn ($l) => $l['id'] === $statementLineId));
        if ($line === false) {
            throw new RuleViolation('That line is not on the ' . $r['short'] . ' statement for ' . $period . '.');
        }
        $entry = self::BANK_ENTRIES[$line['entry'] ?? ''] ?? null;
        if ($entry === null) {
            throw new RuleViolation($line['ref'] . ' is not an entry the bank raised itself. Match it to its posting in the cash book.');
        }
        if ($line['match'] !== null) {
            throw new RuleViolation($line['ref'] . ' is already matched.');
        }
        if ($line['raised'] !== null && in_array($line['raised']['status'], ['draft', 'pending_approval', 'posted'], true)) {
            throw new RuleViolation($line['raised']['ref'] . ' has already been raised from ' . $line['ref'] . '.');
        }

        $accounts = $this->lookups->accounts();
        $bank = $accounts[$r['code']];
        $contra = $accounts[$entry['account']] ?? throw new RuleViolation('Account ' . $entry['account'] . ' is not in the chart of accounts.');
        $bankFund = $bank['default_fund_id'] === null ? null : (int) $bank['default_fund_id'];
        $bankGrant = $bankFund === null ? null : $this->lookups->grantOfFund($bankFund);
        $amount = abs((float) $line['amt']);
        $in = $line['amt'] > 0;

        $bankLine = [
            'code' => $r['code'], 'desc' => $in ? 'Receipt per bank statement' : 'Payment per bank statement',
            'fund' => $this->lookups->fundGroupLabel($bankFund), 'program' => $this->lookups->programmeName($bank['default_programme_id'] === null ? null : (int) $bank['default_programme_id']),
            'grantRef' => $bankGrant === null ? '' : $this->lookups->grants()[$bankGrant]['award_ref'],
            'dr' => $in ? $amount : 0, 'cr' => $in ? 0 : $amount,
        ];
        $contraLine = [
            'code' => $entry['account'], 'desc' => $entry['desc'],
            'fund' => $this->lookups->fundGroupLabel($contra['default_fund_id'] === null ? null : (int) $contra['default_fund_id']),
            'program' => $this->lookups->programmeName($contra['default_programme_id'] === null ? null : (int) $contra['default_programme_id']),
            'grantRef' => '', 'dr' => $in ? 0 : $amount, 'cr' => $in ? $amount : 0,
        ];

        return (new JournalRepository())->create([
            'date' => $this->value('SELECT line_date FROM {bank_statement_lines} WHERE id = ?', [$line['id']]),
            'type' => 'Standard', 'period' => $period, 'status' => 'Pending approval', 'docLink' => 'bankline:' . $line['id'],
            'memo' => 'Raised from the ' . $r['code'] . ' reconciliation to clear an unmatched statement line.',
            'narration' => $line['desc'],
            'createdNote' => 'Raised from statement line ' . $line['ref'] . ' on the ' . $r['short'] . ' reconciliation by ' . $this->lookups->shortName($actorId),
            'lines' => $in ? [$bankLine, $contraLine] : [$contraLine, $bankLine],
        ], $actorId);
    }

    /**
     * Once a journal raised from a statement line posts, matches it to that line —
     * when the line is still open and its reconciliation can still change. Call it
     * inside the approval's transaction.
     */
    public function matchRaised(int $journalId, int $actorId): void
    {
        $j = $this->row(
            "SELECT j.reference, l.id AS line_id, l.amount, l.bank_statement_id, a.code
             FROM {journals} j JOIN {bank_statement_lines} l ON l.id = j.source_id
             JOIN {bank_statements} s ON s.id = l.bank_statement_id JOIN {bank_accounts} b ON b.id = s.bank_account_id JOIN {accounts} a ON a.id = b.account_id
             WHERE j.id = ? AND j.source_type = 'bank_statement_line'",
            [$journalId]
        );
        if ($j === null) {
            return;
        }

        $rec = $this->row(
            "SELECT r.id, r.status, p.name AS period FROM {reconciliations} r JOIN {periods} p ON p.id = r.period_id WHERE r.bank_statement_id = ?",
            [$j['bank_statement_id']]
        );
        $bankLine = $this->value(
            'SELECT l.id FROM {journal_lines} l JOIN {accounts} a ON a.id = l.account_id WHERE l.journal_id = ? AND a.code = ? AND ROUND(l.debit - l.credit, 2) = ? LIMIT 1',
            [$journalId, $j['code'], round((float) $j['amount'], 2)]
        );
        $taken = $this->value('SELECT 1 FROM {reconciliation_match_statement_lines} WHERE bank_statement_line_id = ?', [$j['line_id']]);
        if ($rec === null || $rec['status'] === 'completed' || $bankLine === null || $taken !== null) {
            return;
        }

        $this->insertMatch((int) $rec['id'], [(int) $j['line_id']], [(int) $bankLine], $actorId);
        $this->audit('reconciliation', (int) $rec['id'], $j['code'] . ' ' . $rec['period'], $j['reference'] . ' posted and matched to its statement line', $actorId);
    }

    // ------------------------------------------------------------------
    // Sign-off
    // ------------------------------------------------------------------

    /** Signs the reconciliation off: every statement line explained and no difference left. */
    public function complete(string $code, string $period, int $actorId): array
    {
        $r = $this->editable($code, $period);
        if ($r['difference'] != 0) {
            $open = count($r['unmatchedStatement']);
            throw new RuleViolation('Out by ' . self::money($r['difference']) . ' — ' . $open . ($open === 1 ? ' statement line is' : ' statement lines are') . ' still unexplained.');
        }
        if ($r['unmatchedStatement'] !== []) {
            $open = count($r['unmatchedStatement']);
            throw new RuleViolation($open . ($open === 1 ? ' statement line is' : ' statement lines are') . ' still unmatched. Match or journalise every line before signing off.');
        }
        $h = $this->header($code, $period);
        if ((int) $h['prepared_by'] === $actorId) {
            throw new RuleViolation($this->lookups->shortName($actorId) . ' prepared this reconciliation and cannot also sign it off. It needs a second person.');
        }

        $this->transaction(function () use ($r, $actorId) {
            $now = Clock::timestamp();
            $this->db->table('reconciliations')->where('id', $r['id'])->update([
                'status' => 'completed', 'reviewed_by' => $actorId, 'completed_at' => $now, 'updated_at' => $now,
                'book_balance' => $r['bookClose'], 'statement_balance' => $r['statementClose'],
            ]);
            $this->audit('reconciliation', $r['id'], $r['code'] . ' ' . $r['period'], 'Signed off by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return $this->reconciliation($code, $period);
    }

    /** Reopens a signed-off reconciliation for rework; the period-close check is withdrawn. */
    public function reopen(string $code, string $period, int $actorId): array
    {
        $r = $this->reconciliation($code, $period);
        if ($r['status'] !== 'completed') {
            throw new RuleViolation($r['short'] . ' is not signed off for ' . $period . ', so there is nothing to reopen.');
        }
        if (!$r['periodOpen']) {
            throw new RuleViolation($period . ' is closed. Reopen the month in Period close before reopening its reconciliations.');
        }

        $this->transaction(function () use ($r, $actorId) {
            $this->db->table('reconciliations')->where('id', $r['id'])->update([
                'status' => 'in_progress', 'reviewed_by' => null, 'completed_at' => null, 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('reconciliation', $r['id'], $r['code'] . ' ' . $r['period'], 'Reopened for rework by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return $this->reconciliation($code, $period);
    }

    // ------------------------------------------------------------------

    /** A reconciliation that can still change: not signed off, in an open period. */
    private function editable(string $code, string $period): array
    {
        $r = $this->reconciliation($code, $period);
        if ($r['period'] !== $period) {
            throw new RuleViolation('There is no ' . $r['short'] . ' statement for ' . $period . '.');
        }
        if ($r['status'] === 'completed') {
            throw new RuleViolation($r['short'] . ' was signed off for ' . $period . '. Reopen it to change the matching.');
        }
        if (!$r['periodOpen']) {
            throw new RuleViolation($period . ' is closed. Its reconciliations are fixed.');
        }

        return $r;
    }

    private function header(string $code, string $period): array
    {
        return $this->cached("header:{$code}:{$period}", fn () => $this->row(
            'SELECT r.*, s.reference, s.opening_balance, s.bank_account_id, b.account_id, p.starts_on, p.ends_on, p.status AS period_status
             FROM {reconciliations} r JOIN {bank_statements} s ON s.id = r.bank_statement_id JOIN {bank_accounts} b ON b.id = r.bank_account_id
             JOIN {accounts} a ON a.id = b.account_id JOIN {periods} p ON p.id = r.period_id
             WHERE a.code = ? AND p.name = ?',
            [$code, $period]
        ) ?? throw new RuleViolation('There is no ' . $code . ' reconciliation for ' . $period . '.'));
    }

    /** The cash book: posted lines on the account dated in the period. */
    private function bookLines(array $h): array
    {
        return $this->rows(
            "SELECT l.id, l.debit, l.credit, l.description, j.reference, j.journal_date, j.narration, j.source_type
             FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
             WHERE l.account_id = ? AND j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN ? AND ?
             ORDER BY j.journal_date, j.reference, l.line_no",
            [$h['account_id'], $h['starts_on'], $h['ends_on']]
        );
    }

    private function insertMatch(int $reconciliationId, array $statementIds, array $bookIds, int $actorId): void
    {
        $match = $this->insert('reconciliation_matches', ['reconciliation_id' => $reconciliationId, 'matched_by' => $actorId, 'matched_at' => Clock::timestamp()]);
        foreach ($statementIds as $id) {
            $this->db->table('reconciliation_match_statement_lines')->insert(['reconciliation_match_id' => $match, 'bank_statement_line_id' => $id]);
        }
        foreach ($bookIds as $id) {
            $this->db->table('reconciliation_match_journal_lines')->insert(['reconciliation_match_id' => $match, 'journal_line_id' => $id]);
        }
    }

    /** 14,800 and (14,800), with nil as 0 — the reconciliation's own notation. */
    public static function money(float|int $n): string
    {
        $n = round((float) $n);

        return $n == 0 ? '0' : ($n < 0 ? '(' . number_format(abs($n)) . ')' : number_format($n));
    }
}
