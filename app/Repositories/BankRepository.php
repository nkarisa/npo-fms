<?php

namespace App\Repositories;

/**
 * Bank reconciliation: the latest statement for each account, set against the
 * cash book — the posted ledger lines on that account in the statement period.
 */
final class BankRepository extends Repository
{
    public function accounts(): array
    {
        return $this->cached('accounts', function () {
            return array_map(function ($s) {
                $stmt = array_map(static fn ($l) => [
                    'id' => 's' . $l['id'], 'date' => self::dm($l['line_date']), 'ref' => $l['reference'] ?? '', 'desc' => $l['description'],
                    'amt' => self::num($l['amount']), 'jl' => '',
                ], $this->rows('SELECT * FROM {bank_statement_lines} WHERE bank_statement_id = ? ORDER BY line_date, id', [$s['id']]));

                $book = array_map(static fn ($l) => [
                    'id' => 'b' . $l['id'], 'date' => self::dm($l['journal_date']), 'ref' => $l['reference'],
                    'desc' => $l['description'] !== '' ? $l['description'] : $l['narration'], 'amt' => self::num($l['debit'] - $l['credit']), 'jl' => '',
                ], $this->rows(
                    "SELECT l.*, j.reference, j.journal_date, j.narration FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                     WHERE l.account_id = ? AND j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN ? AND ?
                     ORDER BY j.journal_date, j.reference, l.line_no",
                    [$s['account_id'], $s['starts_on'], $s['ends_on']]
                ));

                $matches = [];
                foreach ($this->rows('SELECT m.id FROM {reconciliation_matches} m JOIN {reconciliations} r ON r.id = m.reconciliation_id WHERE r.bank_statement_id = ?', [$s['id']]) as $m) {
                    $matches[] = [
                        's' => array_map(static fn ($r) => 's' . $r['bank_statement_line_id'], $this->rows('SELECT bank_statement_line_id FROM {reconciliation_match_statement_lines} WHERE reconciliation_match_id = ?', [$m['id']])),
                        'b' => array_map(static fn ($r) => 'b' . $r['journal_line_id'], $this->rows('SELECT journal_line_id FROM {reconciliation_match_journal_lines} WHERE reconciliation_match_id = ?', [$m['id']])),
                    ];
                }

                return [
                    'code'    => $s['code'],
                    'name'    => $s['name'],
                    'short'   => $s['short_name'],
                    'stmtRef' => $s['reference'],
                    'opening' => self::num($s['opening_balance']),
                    'stmt'    => $stmt,
                    'book'    => $book,
                    'matches' => $matches,
                ];
            }, $this->rows(
                'SELECT s.*, b.account_id, b.name, b.short_name, a.code, p.starts_on, p.ends_on
                 FROM {bank_statements} s JOIN {bank_accounts} b ON b.id = s.bank_account_id JOIN {accounts} a ON a.id = b.account_id
                 JOIN {periods} p ON p.id = s.period_id
                 WHERE s.id IN (SELECT MAX(s2.id) FROM {bank_statements} s2 GROUP BY s2.bank_account_id)
                 ORDER BY a.code'
            ));
        });
    }
}
