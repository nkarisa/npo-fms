<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\StatementCsv;

/**
 * Loading a statement CSV onto a cash account's statement for a period.
 *
 * The same call previews and imports: the file is read with the account's format,
 * every line is sorted into new, duplicate (already on a statement), outside the
 * period, or passed over (a failed M-Pesa transaction), and the checks run. Only
 * an import whose checks all pass is written.
 *
 * - Opening balance plus every line on the statement must equal the closing
 *   balance printed on it.
 * - Where the bank prints a running balance, each line's balance must follow from
 *   the one before, and the first line of a new statement from its opening.
 * - Every row has to be readable; a row that is not is named with its line number.
 *
 * Several downloads within the month can be loaded in turn: lines already on a
 * statement are recognised and skipped. A statement for a month with no statement
 * yet opens where the previous month's closed, and its reconciliation starts with
 * the person who loaded it as preparer.
 */
final class StatementImportRepository extends Repository
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const STORAGE_DIR = 'statements';

    private Lookups $lookups;

    private StatementFormatRepository $formats;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
        $this->formats = new StatementFormatRepository($this->db);
    }

    /**
     * What the upload panel offers: each account with its format, the open periods,
     * and for each account and period the statement already loaded, if any.
     */
    public function options(): array
    {
        $periods = array_values(array_filter($this->lookups->periods(), static fn ($p) => $p['status'] === 'open'));
        $statements = [];
        foreach ($this->rows(
            "SELECT s.id, s.opening_balance, s.closing_balance, s.reference, a.code, p.name AS period, r.status,
                    (SELECT COUNT(*) FROM {bank_statement_lines} l WHERE l.bank_statement_id = s.id) AS line_count
             FROM {bank_statements} s JOIN {bank_accounts} b ON b.id = s.bank_account_id JOIN {accounts} a ON a.id = b.account_id
             JOIN {periods} p ON p.id = s.period_id LEFT JOIN {reconciliations} r ON r.bank_statement_id = s.id"
        ) as $s) {
            $statements[$s['code']][$s['period']] = [
                'opening' => self::num($s['opening_balance']), 'closing' => self::num($s['closing_balance']), 'lines' => (int) $s['line_count'],
                'reference' => $s['reference'], 'signedOff' => $s['status'] === 'completed',
            ];
        }

        return [
            'accounts' => array_map(fn ($a) => $a + [
                'statements' => array_map(fn ($p) => $statements[$a['code']][$p['name']] ?? ['opening' => $this->previousClosing($a['code'], $p['starts_on']), 'closing' => null, 'lines' => 0, 'reference' => '', 'signedOff' => false, 'new' => true],
                    array_column($periods, null, 'name')),
            ], $this->formats->accounts()),
            'periods' => array_column($periods, 'name'),
            'entries' => StatementFormatRepository::options()['entries'],
        ];
    }

    /**
     * Reads a statement file for an account and period and, when $commit is set and
     * every check passes, loads it.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     * @param array{reference?: string, opening?: mixed, closing?: mixed, entries?: array<int|string, string>} $input
     *        entries: the kind of bank entry per file line, where the person changed it
     */
    public function run(string $code, string $period, array $file, array $input, int $actorId, bool $commit): array
    {
        $account = current(array_filter($this->formats->accounts(), static fn ($a) => $a['code'] === $code))
            ?: throw new RuleViolation('Account ' . $code . ' does not take bank statements.');
        $format = $this->formats->forAccount($code)
            ?? throw new RuleViolation($account['short'] . ' has no statement format. Set one in Settings → Bank statements before uploading.');
        $p = $this->lookups->periodByName($period) ?? throw new RuleViolation('There is no period called ' . $period . '.');
        if ($p['status'] !== 'open') {
            throw new RuleViolation($period . ' is closed. Statements cannot be loaded into a closed month.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuleViolation($file['name'] . ' is larger than 5 MB. A month\'s statement should be far smaller — check it is the right file.');
        }
        if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw new RuleViolation($file['name'] . ' is not a CSV file. Download the statement from the bank as CSV.');
        }

        $bankId = (int) $this->lookups->bankAccounts()[$code]['id'];
        $statement = $this->row(
            'SELECT s.*, r.status AS rec_status FROM {bank_statements} s LEFT JOIN {reconciliations} r ON r.bank_statement_id = s.id WHERE s.bank_account_id = ? AND s.period_id = ?',
            [$bankId, $p['id']]
        );
        if ($statement !== null && $statement['rec_status'] === 'completed') {
            throw new RuleViolation($account['short'] . ' was signed off for ' . $period . '. Reopen the reconciliation before loading more of the statement.');
        }

        $csv = (string) file_get_contents($file['path']);
        $read = StatementCsv::read($csv, $format);
        if ($read['error'] !== null) {
            throw new RuleViolation($file['name'] . ': ' . $read['error']);
        }

        // Existing lines on this account, by fingerprint, so a line loaded before is skipped.
        $overrides = array_map('strval', (array) ($input['entries'] ?? []));
        $seen = [];
        $rows = [];
        foreach ($read['rows'] as $r) {
            $status = match (true) {
                $r['errors'] !== []                                  => 'error',
                $r['skip'] !== null                                  => 'skipped',
                $r['date'] < $p['starts_on'] || $r['date'] > $p['ends_on'] => 'outside',
                default                                              => 'new',
            };
            $hash = null;
            if ($status !== 'error' && $status !== 'skipped') {
                $key = implode('|', [$r['date'], mb_strtolower($r['ref']), $r['amount'], mb_strtolower($r['desc'])]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $hash = StatementCsv::fingerprint($bankId, $r['date'], $r['ref'], $r['amount'], $r['desc'], $seen[$key]);
            }
            if (array_key_exists((string) $r['line'], $overrides)) {
                $r['entry'] = isset(BankRepository::BANK_ENTRIES[$overrides[(string) $r['line']]]) ? $overrides[(string) $r['line']] : null;
            }
            $rows[] = $r + ['status' => $status, 'hash' => $hash];
        }

        $hashes = array_values(array_filter(array_column($rows, 'hash')));
        $known = $hashes === [] ? [] : array_flip(array_column($this->db->table('bank_statement_lines')->select('import_hash')->whereIn('import_hash', $hashes)->get()->getResultArray(), 'import_hash'));
        foreach ($rows as &$r) {
            if ($r['status'] === 'new' && isset($known[$r['hash']])) {
                $r['status'] = 'duplicate';
            }
        }
        unset($r);

        $new = array_values(array_filter($rows, static fn ($r) => $r['status'] === 'new'));
        $count = static fn (string $s) => count(array_filter($rows, static fn ($r) => $r['status'] === $s));

        // Balances.
        $existingSum = $statement === null ? 0.0 : (float) $this->value('SELECT COALESCE(SUM(amount), 0) FROM {bank_statement_lines} WHERE bank_statement_id = ?', [$statement['id']]);
        $existingLines = $statement === null ? 0 : (int) $this->value('SELECT COUNT(*) FROM {bank_statement_lines} WHERE bank_statement_id = ?', [$statement['id']]);
        $opening = $statement !== null ? (float) $statement['opening_balance'] : self::amountInput($input['opening'] ?? null) ?? $this->previousClosing($code, $p['starts_on']);
        $closing = self::amountInput($input['closing'] ?? null);
        $newSum = round(array_sum(array_column($new, 'amount')), 2);
        $expected = $opening === null ? null : round($opening + $existingSum + $newSum, 2);

        $inPeriod = array_values(array_filter($rows, static fn ($r) => in_array($r['status'], ['new', 'duplicate'], true)));
        $balance = StatementCsv::balanceBreaks($inPeriod);
        $hasBalances = ($format['balanceColumn'] ?? '') !== '' && array_filter($inPeriod, static fn ($r) => $r['balance'] !== null) !== [];

        $checks = [];
        $checks[] = $count('error') === 0
            ? ['ok' => true, 'label' => 'Every row of the file could be read']
            : ['ok' => false, 'label' => $count('error') . ($count('error') === 1 ? ' row' : ' rows') . ' could not be read — line ' . implode(', ', array_column(array_filter($rows, static fn ($r) => $r['status'] === 'error'), 'line'))];
        $checks[] = $new !== []
            ? ['ok' => true, 'label' => count($new) . (count($new) === 1 ? ' new line' : ' new lines') . ' dated in ' . $period]
            : ['ok' => false, 'label' => $count('duplicate') > 0 ? 'Nothing new — every line in ' . $period . ' is already on the statement' : 'No lines in the file are dated in ' . $period];
        if ($hasBalances) {
            $checks[] = $balance['breaks'] === []
                ? ['ok' => true, 'label' => 'Running balances follow line by line']
                : ['ok' => false, 'label' => 'The running balance does not follow from the amount on line ' . implode(', ', $balance['breaks'])];
            if ($existingLines === 0 && $opening !== null && $new !== []) {
                $first = $balance['newestFirst'] ? end($inPeriod) : $inPeriod[0];
                $checks[] = round($opening + $first['amount'], 2) == round((float) $first['balance'], 2)
                    ? ['ok' => true, 'label' => 'The first line follows from the opening balance of ' . BankRepository::money($opening)]
                    : ['ok' => false, 'label' => 'The first line\'s balance of ' . BankRepository::money((float) $first['balance']) . ' does not follow from the opening balance of ' . BankRepository::money($opening)];
            }
        }
        $checks[] = match (true) {
            $opening === null => ['ok' => false, 'label' => 'Enter the opening balance printed on the statement — there is no earlier statement to carry it from'],
            $closing === null => ['ok' => false, 'label' => 'Enter the closing balance printed on the statement'],
            $expected == round($closing, 2) => ['ok' => true, 'label' => 'Opening ' . BankRepository::money($opening) . ' plus the lines agrees to the closing balance of ' . BankRepository::money($closing)],
            default => ['ok' => false, 'label' => 'Opening ' . BankRepository::money($opening) . ' plus the lines comes to ' . BankRepository::money($expected)
                . ', not the closing balance of ' . BankRepository::money($closing) . ' — out by ' . BankRepository::money($closing - $expected)],
        };
        $ok = array_filter($checks, static fn ($c) => !$c['ok']) === [];

        $result = [
            'account' => $code, 'short' => $account['short'], 'period' => $period, 'format' => $format['name'], 'file' => $file['name'],
            'newStatement' => $statement === null, 'opening' => $opening, 'closing' => $closing, 'existingLines' => $existingLines,
            'rows' => array_map(static fn ($r) => StatementFormatRepository::previewRow($r) + ['status' => $r['status']], $rows),
            'summary' => ['read' => count($rows), 'new' => count($new), 'duplicate' => $count('duplicate'), 'outside' => $count('outside'), 'skipped' => $count('skipped'), 'error' => $count('error')],
            'checks' => $checks, 'ok' => $ok,
        ];

        if (!$commit) {
            return $result;
        }
        if (!$ok) {
            throw new RuleViolation(current(array_filter($checks, static fn ($c) => !$c['ok']))['label'] . '.');
        }

        // Oldest first, so the statement lists the lines in the order they happened.
        usort($new, static fn ($a, $b) => [$a['date'], $balance['newestFirst'] ? -$a['line'] : $a['line']] <=> [$b['date'], $balance['newestFirst'] ? -$b['line'] : $b['line']]);

        $stored = null;
        try {
            $this->transaction(function () use ($account, $p, $period, $statement, $bankId, $input, $opening, $closing, $file, $rows, $new, $count, $actorId, &$stored) {
                $now = Clock::timestamp();
                $reference = mb_substr(trim((string) ($input['reference'] ?? '')), 0, 120);
                if ($statement === null) {
                    $statementId = $this->insert('bank_statements', [
                        'bank_account_id' => $bankId, 'period_id' => $p['id'],
                        'reference' => $reference !== '' ? $reference : $account['short'] . ' · statement ' . date('m/Y', strtotime($p['starts_on'])) . ' uploaded ' . date('d M', strtotime(Clock::date())),
                        'received_on' => Clock::date(), 'opening_balance' => $opening, 'closing_balance' => $closing,
                        'imported_by' => $actorId, 'source' => 'upload', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $reconciliationId = $this->insert('reconciliations', [
                        'bank_account_id' => $bankId, 'period_id' => $p['id'], 'bank_statement_id' => $statementId,
                        'statement_balance' => $closing, 'status' => 'in_progress', 'prepared_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $statementId = (int) $statement['id'];
                    $this->db->table('bank_statements')->where('id', $statementId)->update(
                        ['closing_balance' => $closing, 'source' => 'upload', 'updated_at' => $now] + ($reference !== '' ? ['reference' => $reference] : [])
                    );
                    $reconciliationId = (int) $this->value('SELECT id FROM {reconciliations} WHERE bank_statement_id = ?', [$statementId]);
                    $this->db->table('reconciliations')->where('id', $reconciliationId)->update(['statement_balance' => $closing, 'updated_at' => $now]);
                }

                $importId = $this->insert('bank_statement_imports', [
                    'bank_statement_id' => $statementId, 'filename' => mb_substr($file['name'], 0, 255), 'rows_read' => count($rows),
                    'rows_imported' => count($new), 'rows_duplicate' => $count('duplicate'), 'rows_outside' => $count('outside'),
                    'closing_balance' => $closing, 'imported_by' => $actorId, 'imported_at' => $now,
                ]);
                foreach ($new as $r) {
                    $this->db->table('bank_statement_lines')->insert([
                        'bank_statement_id' => $statementId, 'line_date' => $r['date'], 'reference' => $r['ref'] !== '' ? $r['ref'] : null,
                        'description' => $r['desc'], 'amount' => $r['amount'], 'balance' => $r['balance'], 'bank_entry' => $r['entry'],
                        'import_hash' => $r['hash'], 'bank_statement_import_id' => $importId,
                    ]);
                }
                $stored = $this->store($importId, $file, $actorId);

                $this->audit('reconciliation', $reconciliationId, $account['code'] . ' ' . $period,
                    $file['name'] . ' loaded by ' . $this->lookups->shortName($actorId) . ' — ' . count($new) . (count($new) === 1 ? ' line' : ' lines') . ' added'
                    . ($count('duplicate') > 0 ? ', ' . $count('duplicate') . ' already on the statement' : ''), $actorId);
            });
        } catch (\Throwable $e) {
            if ($stored !== null && is_file($stored)) {
                @unlink($stored);
            }
            throw $e;
        }

        return $result;
    }

    // ------------------------------------------------------------------

    /** The closing balance of the account's latest statement before a date, or null. */
    private function previousClosing(string $code, string $before): ?float
    {
        $v = $this->value(
            'SELECT s.closing_balance FROM {bank_statements} s JOIN {bank_accounts} b ON b.id = s.bank_account_id JOIN {accounts} a ON a.id = b.account_id
             JOIN {periods} p ON p.id = s.period_id WHERE a.code = ? AND p.starts_on < ? ORDER BY p.starts_on DESC LIMIT 1',
            [$code, $before]
        );

        return $v === null ? null : (float) $v;
    }

    /** "18,047,500", "(1,200.50)", 18047500 — or null when blank or not a number. */
    private static function amountInput(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        return StatementCsv::number((string) $v);
    }

    /**
     * Keeps the uploaded file with the statement, for the audit file.
     *
     * @return string the stored path, so a failed import can remove it
     */
    private function store(int $importId, array $file, int $actorId): string
    {
        $key = self::STORAGE_DIR . '/' . bin2hex(random_bytes(16)) . '.csv';
        $path = WRITEPATH . 'uploads/' . $key;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuleViolation('Statement files cannot be stored right now.');
        }
        $sha = hash_file('sha256', $file['path']);
        if (!(is_uploaded_file($file['path']) ? move_uploaded_file($file['path'], $path) : copy($file['path'], $path))) {
            throw new RuleViolation($file['name'] . ' could not be stored.');
        }

        $this->insert('attachments', [
            'entity_id' => $this->lookups->entityId(), 'object_type' => 'bank_statement_import', 'object_id' => $importId,
            'filename' => mb_substr($file['name'], 0, 255), 'mime_type' => mb_substr($file['mime'] ?: 'text/csv', 0, 100), 'size_bytes' => $file['size'],
            'storage_key' => $key, 'sha256' => $sha, 'uploaded_by' => $actorId, 'uploaded_at' => Clock::timestamp(),
        ]);

        return $path;
    }
}
