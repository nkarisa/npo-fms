<?php

namespace App\Libraries;

use DateTimeImmutable;

/**
 * Reads a bank or M-Pesa statement CSV through a statement format — the mapping of
 * the bank's columns onto a statement line.
 *
 * Nothing here touches the database: the result is the file as statement lines,
 * each with what was wrong with it, so the same read serves the format editor's
 * sample preview, the upload preview and the import itself.
 *
 * - The header row is found, not counted: the first row holding every mapped
 *   column. Account details and "opening balance" preambles above it are ignored.
 * - A row with no amount is not a transaction (an opening or closing balance row,
 *   a totals footer) and is passed over silently.
 * - Amounts are signed as the statement is read: money in positive, money out
 *   negative, whichever of the three layouts the bank uses.
 */
final class StatementCsv
{
    /** How a format names its date layouts → PHP's. A format may accept several, separated by "|". */
    public const DATE_FORMATS = [
        'dd/mm/yyyy'  => 'd/m/Y',
        'dd-mm-yyyy'  => 'd-m-Y',
        'dd.mm.yyyy'  => 'd.m.Y',
        'yyyy-mm-dd'  => 'Y-m-d',
        'yyyy/mm/dd'  => 'Y/m/d',
        'mm/dd/yyyy'  => 'm/d/Y',
        'dd/mm/yy'    => 'd/m/y',
        'dd MMM yyyy' => 'd M Y',
        'dd-MMM-yyyy' => 'd-M-Y',
        'dd-MMM-yy'   => 'd-M-y',
        'dd MMM yy'   => 'd M y',
    ];

    public const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t", 'pipe' => '|'];

    public const LAYOUTS = [
        'signed'    => 'One amount column, money out negative',
        'split'     => 'Separate money out and money in columns',
        'indicator' => 'Amount column with a debit/credit marker',
    ];

    /** How far down the file the header row is looked for. */
    private const HEADER_SEARCH_ROWS = 40;

    /**
     * @param array $format the screen shape: delimiter, dateColumn, dateFormat,
     *        referenceColumn, descriptionColumns, amountLayout, amountColumn,
     *        debitColumn, creditColumn, indicatorColumn, creditIndicator,
     *        balanceColumn, decimalMark, statusColumn, statusValue, rules
     * @return array{
     *     headers: list<string>, headerLine: int|null, error: string|null,
     *     rows: list<array{line: int, date: string|null, ref: string, desc: string, amount: float|null,
     *         balance: float|null, entry: string|null, skip: string|null, errors: list<string>}>
     * }
     */
    public static function read(string $csv, array $format): array
    {
        $records = self::records($csv, self::DELIMITERS[$format['delimiter'] ?? 'comma'] ?? ',');
        $wanted = self::mappedColumns($format);
        $out = ['headers' => [], 'headerLine' => null, 'error' => null, 'rows' => []];

        if ($records === []) {
            $out['error'] = 'The file is empty.';

            return $out;
        }

        $header = null;
        foreach (array_slice($records, 0, self::HEADER_SEARCH_ROWS) as $i => [$line, $cells]) {
            $names = array_map([self::class, 'key'], $cells);
            if ($wanted !== [] && array_diff(array_map([self::class, 'key'], $wanted), $names) === []) {
                $header = $i;
                break;
            }
        }

        if ($header === null) {
            // Show the widest early row: most likely the header the mapping missed.
            $widest = array_reduce(array_slice($records, 0, self::HEADER_SEARCH_ROWS), static fn ($w, $r) => $w === null || count($r[1]) > count($w[1]) ? $r : $w);
            $out['headers'] = array_values(array_filter(array_map('trim', $widest[1]), static fn ($h) => $h !== ''));
            $missing = array_values(array_diff(array_map([self::class, 'key'], $wanted), array_map([self::class, 'key'], $widest[1])));
            $labels = array_values(array_filter($wanted, static fn ($w) => in_array(self::key($w), $missing, true)));
            $out['error'] = $wanted === []
                ? 'Map the columns before reading the file.'
                : 'No header row in the file holds ' . self::list($labels) . '. Check the column names against the file.';

            return $out;
        }

        [$out['headerLine'], $headerCells] = $records[$header];
        $out['headers'] = array_values(array_filter(array_map('trim', $headerCells), static fn ($h) => $h !== ''));
        $index = [];
        foreach ($headerCells as $i => $name) {
            $index[self::key($name)] ??= $i;
        }
        $cell = static fn (array $cells, ?string $column) => $column === null || $column === '' ? '' : trim((string) ($cells[$index[self::key($column)]] ?? ''));

        foreach (array_slice($records, $header + 1) as [$line, $cells]) {
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            $errors = [];
            $amount = self::amount($format, $cells, $cell, $errors);
            $dateText = $cell($cells, $format['dateColumn']);
            if (($amount === null && $errors === []) || preg_match('/\d/', $dateText) !== 1) {
                continue; // a balance or totals row ("Total", "Closing balance"), not a transaction
            }

            $date = self::date($dateText, (string) $format['dateFormat']);
            if ($date === null) {
                $errors[] = 'the date "' . $dateText . '" is not ' . str_replace('|', ' or ', $format['dateFormat']);
            }

            $balanceText = $cell($cells, $format['balanceColumn'] ?? null);
            $balance = $balanceText === '' ? null : self::number($balanceText, $format['decimalMark'] ?? '.');
            if ($balanceText !== '' && $balance === null) {
                $errors[] = 'the balance "' . $balanceText . '" is not a number';
            }

            $desc = implode(' · ', array_values(array_filter(array_map(static fn ($c) => $cell($cells, $c), (array) ($format['descriptionColumns'] ?? [])), static fn ($v) => $v !== '')));
            $ref = $cell($cells, $format['referenceColumn'] ?? null);

            $skip = null;
            if (($format['statusColumn'] ?? '') !== '' && strcasecmp($cell($cells, $format['statusColumn']), (string) $format['statusValue']) !== 0) {
                $skip = 'Status is ' . ($cell($cells, $format['statusColumn']) ?: 'blank') . ', not ' . $format['statusValue'];
            } elseif ($amount !== null && round($amount, 2) == 0) {
                $skip = 'Nil amount';
            }

            $out['rows'][] = [
                'line' => $line, 'date' => $date, 'ref' => mb_substr($ref, 0, 60), 'desc' => mb_substr($desc !== '' ? $desc : $ref, 0, 255),
                'amount' => $amount === null ? null : round($amount, 2), 'balance' => $balance === null ? null : round($balance, 2),
                'entry' => self::entry((array) ($format['rules'] ?? []), $desc . ' ' . $ref), 'skip' => $skip, 'errors' => $errors,
            ];
        }

        return $out;
    }

    /**
     * Whether the running balances follow from the amounts, read oldest first or
     * newest first (M-Pesa lists the latest transaction at the top).
     *
     * @param list<array> $rows transaction rows from read(), in file order
     * @return array{newestFirst: bool, breaks: list<int>} file lines where the balance does not follow
     */
    public static function balanceBreaks(array $rows): array
    {
        $rows = array_values(array_filter($rows, static fn ($r) => $r['balance'] !== null && $r['amount'] !== null && $r['skip'] === null && $r['errors'] === []));
        $breaks = static function (array $rows): array {
            $out = [];
            for ($i = 1; $i < count($rows); $i++) {
                if (round($rows[$i - 1]['balance'] + $rows[$i]['amount'], 2) != round($rows[$i]['balance'], 2)) {
                    $out[] = $rows[$i]['line'];
                }
            }

            return $out;
        };

        $oldestFirst = $breaks($rows);
        $newestFirst = $breaks(array_reverse($rows));
        $datesDescend = count($rows) > 1 && (string) $rows[0]['date'] > (string) end($rows)['date'];
        $useNewest = count($newestFirst) < count($oldestFirst) || (count($newestFirst) === count($oldestFirst) && $datesDescend);

        return ['newestFirst' => $useNewest, 'breaks' => $useNewest ? array_reverse($newestFirst) : $oldestFirst];
    }

    /** The key that stops one statement line being loaded twice. */
    public static function fingerprint(int $bankAccountId, string $date, string $ref, float $amount, string $desc, int $occurrence): string
    {
        $norm = static fn (string $s) => mb_strtolower(preg_replace('/\s+/', ' ', trim($s)));

        return hash('sha256', implode('|', [$bankAccountId, $date, $norm($ref), number_format($amount, 2, '.', ''), $norm($desc), $occurrence]));
    }

    /** The columns a format names, which the header row must hold. */
    public static function mappedColumns(array $format): array
    {
        $columns = array_merge([$format['dateColumn'] ?? ''], (array) ($format['descriptionColumns'] ?? []), [
            $format['referenceColumn'] ?? '', $format['balanceColumn'] ?? '', $format['statusColumn'] ?? '',
        ], match ($format['amountLayout'] ?? 'signed') {
            'split'     => [$format['debitColumn'] ?? '', $format['creditColumn'] ?? ''],
            'indicator' => [$format['amountColumn'] ?? '', $format['indicatorColumn'] ?? ''],
            default     => [$format['amountColumn'] ?? ''],
        });

        return array_values(array_unique(array_filter(array_map('trim', $columns), static fn ($c) => $c !== '')));
    }

    /** Parses a date in any of a format's layouts, ignoring a time after it. */
    public static function date(string $text, string $formats): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return null;
        }
        // "14/08/2026 10:22:05", "2026-08-14T10:22" — the time is not needed.
        $datePart = trim(preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?(\s?[AaPp][Mm])?$/', '', $text));

        foreach (explode('|', $formats) as $name) {
            $php = self::DATE_FORMATS[trim($name)] ?? null;
            if ($php === null) {
                continue;
            }
            $d = DateTimeImmutable::createFromFormat('!' . $php, $datePart);
            $errors = DateTimeImmutable::getLastErrors();
            if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * "1,234.50", "(1,234.50)", "-1,234.50", "1,234.50-", "1.234,50", "KES 1,234.50 DR".
     */
    public static function number(string $text, string $decimalMark = '.'): ?float
    {
        $s = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $text));
        if ($s === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/\s*(DR|CR)\.?$/i', $s, $m) === 1) {
            $negative = strtoupper($m[1]) === 'DR';
            $s = trim(substr($s, 0, -strlen($m[0])));
        }
        $s = trim(preg_replace('/^[A-Z]{3}\s*|\s*[A-Z]{3}$/i', '', $s));
        if (preg_match('/^\((.*)\)$/', $s, $m) === 1) {
            $negative = !$negative;
            $s = $m[1];
        }
        if (str_ends_with($s, '-')) {
            $negative = !$negative;
            $s = substr($s, 0, -1);
        }
        if (str_starts_with($s, '-')) {
            $negative = !$negative;
            $s = substr($s, 1);
        }
        $s = ltrim($s, '+');
        $s = str_replace([' ', "'"], '', $s);
        $s = $decimalMark === ',' ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);

        if (preg_match('/^\d+(\.\d+)?$|^\.\d+$/', $s) !== 1) {
            return null;
        }

        return $negative ? -(float) $s : (float) $s;
    }

    /** The kind of bank entry the first matching rule names, or null. */
    public static function entry(array $rules, string $text): ?string
    {
        foreach ($rules as $rule) {
            $match = trim((string) ($rule['match'] ?? ''));
            if ($match !== '' && mb_stripos($text, $match) !== false) {
                return (string) $rule['entry'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------

    /**
     * The row's signed amount, or null when the row carries none.
     *
     * @param callable(array, ?string): string $cell
     */
    private static function amount(array $format, array $cells, callable $cell, array &$errors): ?float
    {
        $mark = $format['decimalMark'] ?? '.';
        $parse = static function (string $column) use ($cells, $cell, $mark, &$errors): ?float {
            $text = $cell($cells, $column);
            if ($text === '') {
                return null;
            }
            $n = self::number($text, $mark);
            if ($n === null) {
                $errors[] = 'the amount "' . $text . '" is not a number';
            }

            return $n;
        };

        switch ($format['amountLayout'] ?? 'signed') {
            case 'split':
                $out = $parse((string) $format['debitColumn']);
                $in = $parse((string) $format['creditColumn']);

                return $out === null && $in === null ? null : abs((float) $in) - abs((float) $out);

            case 'indicator':
                $n = $parse((string) $format['amountColumn']);
                if ($n === null) {
                    return null;
                }
                $marker = $cell($cells, $format['indicatorColumn']);
                $credit = (string) ($format['creditIndicator'] ?? 'CR');
                if ($marker === '') {
                    $errors[] = 'no debit/credit marker';
                }

                return $credit !== '' && stripos($marker, $credit) === 0 ? abs($n) : -abs($n);

            default:
                return $parse((string) $format['amountColumn']);
        }
    }

    /** @return list<array{0: int, 1: list<string>}> [file line, cells] */
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
            // A quoted cell can run over several lines of the file.
            $line += 1 + array_sum(array_map(static fn ($c) => substr_count($c, "\n"), $cells));
        }
        fclose($handle);

        return $records;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    private static function list(array $items): string
    {
        $items = array_map(static fn ($i) => '"' . $i . '"', $items);

        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }
}
