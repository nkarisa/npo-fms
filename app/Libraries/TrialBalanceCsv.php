<?php

namespace App\Libraries;

/**
 * Reads a legacy system's trial balance export.
 *
 * Unlike a bank statement, a trial balance is exported once, by whoever is leaving
 * the old system, so there is no format to configure: the columns are recognised
 * by their headers and the common synonyms for them. Only the account code and the
 * figures are required; fund, programme, award and county are the coding this
 * ledger needs and the old system usually lacks, and are filled in from the chart's
 * defaults where the file leaves them out.
 *
 * Nothing here touches the database. The result is the file as rows, each with what
 * could not be read, so the same read serves the preview and the load.
 *
 * - The header row is found, not counted: the first row carrying an account column
 *   and something to take an amount from. Report titles above it are ignored.
 * - Two layouts are accepted: separate debit and credit columns, or one signed
 *   balance column where a positive figure is a debit.
 * - A row with no figure is not a balance (a section heading, a totals footer) and
 *   is passed over. A row whose debit and credit are both filled is a mistake in
 *   the export and is reported as one.
 */
final class TrialBalanceCsv
{
    /** Column headers this reader knows, most specific first. */
    public const COLUMNS = [
        'account'     => ['account code', 'account', 'a/c code', 'a/c', 'gl code', 'gl account', 'code', 'ledger code', 'nominal code', 'nominal'],
        'description' => ['account name', 'description', 'narrative', 'particulars', 'details', 'name'],
        'fund'        => ['fund code', 'fund', 'restriction'],
        'programme'   => ['programme code', 'program code', 'programme', 'program', 'project code', 'project', 'cost centre', 'cost center', 'department'],
        'grant'       => ['award ref', 'award', 'grant ref', 'grant', 'donor ref'],
        'county'      => ['county code', 'county', 'region', 'location'],
        'debit'       => ['debit', 'dr', 'debits', 'debit amount', 'dr amount'],
        'credit'      => ['credit', 'cr', 'credits', 'credit amount', 'cr amount'],
        'balance'     => ['balance', 'amount', 'closing balance', 'net', 'net balance', 'ytd balance'],
    ];

    /** Columns a file must carry before it can be read at all. */
    public const REQUIRED = ['account'];

    public const DELIMITERS = [',', ';', "\t", '|'];

    /** How far down the file the header row is looked for. */
    private const HEADER_SEARCH_ROWS = 40;

    /** Rows whose account code reads like one of these are a footer, not a balance. */
    private const FOOTER_WORDS = ['total', 'totals', 'grand total', 'sub total', 'subtotal', 'balance', 'net'];

    /**
     * @param string $csv the file's contents
     * @param string $decimalMark "." or "," — how the old system wrote its figures
     * @return array{
     *     headers: list<string>, headerLine: int|null, error: string|null, mapped: array<string, string>,
     *     rows: list<array{line: int, account: string, description: string, fund: string, programme: string,
     *         grant: string, county: string, debit: float, credit: float, errors: list<string>}>
     * }
     */
    public static function read(string $csv, string $decimalMark = '.'): array
    {
        $out = ['headers' => [], 'headerLine' => null, 'error' => null, 'mapped' => [], 'rows' => []];

        $delimiter = self::delimiter($csv);
        $records = self::records($csv, $delimiter);
        if ($records === []) {
            $out['error'] = 'The file is empty.';

            return $out;
        }

        [$header, $map] = self::header($records);
        if ($header === null) {
            $widest = array_reduce(array_slice($records, 0, self::HEADER_SEARCH_ROWS), static fn ($w, $r) => $w === null || count($r[1]) > count($w[1]) ? $r : $w);
            $out['headers'] = self::visible($widest[1]);
            $out['error'] = 'No header row names an account column and a figure. Export the trial balance with a column headed "Account code" and either "Debit" and "Credit" or "Balance".';

            return $out;
        }

        [$out['headerLine'], $headerCells] = $records[$header];
        $out['headers'] = self::visible($headerCells);
        $out['mapped'] = array_map(static fn ($i) => trim((string) $headerCells[$i]), $map);
        $cell = static fn (array $cells, string $field) => isset($map[$field]) ? trim((string) ($cells[$map[$field]] ?? '')) : '';

        foreach (array_slice($records, $header + 1) as [$line, $cells]) {
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            $account = $cell($cells, 'account');
            if ($account === '' || in_array(mb_strtolower($account), self::FOOTER_WORDS, true)) {
                continue;
            }

            $errors = [];
            [$debit, $credit] = self::amounts($cell, $cells, $map, $decimalMark, $errors);
            if ($debit === null && $credit === null && $errors === []) {
                continue; // a section heading with no figures
            }

            $debit ??= 0.0;
            $credit ??= 0.0;
            if ($debit != 0.0 && $credit != 0.0) {
                $errors[] = 'the row carries both a debit and a credit';
            }
            if (round($debit, 2) == 0.0 && round($credit, 2) == 0.0 && $errors === []) {
                continue; // a nil balance brings nothing forward
            }

            $out['rows'][] = [
                'line'        => $line,
                'account'     => mb_substr($account, 0, 10),
                'description' => mb_substr($cell($cells, 'description'), 0, 255),
                'fund'        => mb_substr($cell($cells, 'fund'), 0, 20),
                'programme'   => mb_substr($cell($cells, 'programme'), 0, 20),
                'grant'       => mb_substr($cell($cells, 'grant'), 0, 40),
                'county'      => mb_substr($cell($cells, 'county'), 0, 3),
                'debit'       => round($debit, 2),
                'credit'      => round($credit, 2),
                'errors'      => $errors,
            ];
        }

        if ($out['rows'] === []) {
            $out['error'] = 'The file has a header row but no balances under it.';
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * A row's debit and credit, from either layout. A signed balance column is a
     * debit when positive, a credit when negative, which is how every trial balance
     * that uses one column writes it.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private static function amounts(callable $cell, array $cells, array $map, string $decimalMark, array &$errors): array
    {
        $number = static function (string $text, string $label) use ($decimalMark, &$errors): ?float {
            if ($text === '') {
                return null;
            }
            $value = StatementCsv::number($text, $decimalMark);
            if ($value === null) {
                $errors[] = 'the ' . $label . ' "' . $text . '" is not a number';
            }

            return $value;
        };

        if (isset($map['debit']) || isset($map['credit'])) {
            $debit  = $number($cell($cells, 'debit'), 'debit');
            $credit = $number($cell($cells, 'credit'), 'credit');

            // A negative in one column is a positive in the other.
            if ($debit !== null && $debit < 0) {
                [$debit, $credit] = [null, ($credit ?? 0.0) - $debit];
            }
            if ($credit !== null && $credit < 0) {
                [$debit, $credit] = [($debit ?? 0.0) - $credit, null];
            }

            return [$debit, $credit];
        }

        $balance = $number($cell($cells, 'balance'), 'balance');

        return $balance === null ? [null, null] : ($balance >= 0 ? [$balance, 0.0] : [0.0, -$balance]);
    }

    /**
     * The header row and where each known column sits on it.
     *
     * @return array{0: int|null, 1: array<string, int>}
     */
    private static function header(array $records): array
    {
        foreach (array_slice($records, 0, self::HEADER_SEARCH_ROWS) as $i => [, $cells]) {
            $map = [];
            foreach (self::COLUMNS as $field => $names) {
                foreach ($cells as $at => $name) {
                    if (in_array(self::key($name), $names, true)) {
                        $map[$field] ??= $at;
                    }
                }
            }
            $hasFigures = isset($map['debit']) || isset($map['credit']) || isset($map['balance']);
            if (array_diff(self::REQUIRED, array_keys($map)) === [] && $hasFigures) {
                return [$i, $map];
            }
        }

        return [null, []];
    }

    /** The delimiter that splits the file's widest row into the most columns. */
    private static function delimiter(string $csv): string
    {
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $csv) ?: [], 0, self::HEADER_SEARCH_ROWS));
        $best = ',';
        $most = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = max(array_map(static fn ($row) => substr_count($row, $delimiter), explode("\n", $sample)) ?: [0]);
            if ($count > $most) {
                [$best, $most] = [$delimiter, $count];
            }
        }

        return $best;
    }

    private static function records(string $csv, string $delimiter): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, str_replace(["\r\n", "\r"], "\n", $csv));
        rewind($handle);

        $records = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(static fn ($c) => (string) $c, $cells);
            $records[] = [$line, $cells];
            $line += 1 + array_sum(array_map(static fn ($c) => substr_count($c, "\n"), $cells));
        }
        fclose($handle);

        return $records;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    /** @return list<string> */
    private static function visible(array $cells): array
    {
        return array_values(array_filter(array_map('trim', $cells), static fn ($h) => $h !== ''));
    }
}
