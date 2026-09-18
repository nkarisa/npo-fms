<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\TrialBalanceCsv;
use CodeIgniter\Database\BaseConnection;

/**
 * Carrying an entity's permanent balances from a legacy system onto this ledger.
 *
 * The balances become one journal, not a set of stored figures: every balance this
 * application reports is derived from posted journal lines, so an opening balance
 * held anywhere else would be a second answer the trial balance could not see. The
 * entry is dated the first day of the first period kept here and marked
 * `source_type` "fiscal_year", which the chart, the general ledger and the journal
 * register already recognise as brought-forward figures.
 *
 * The load is in two steps, as a bank statement's is:
 *
 * - `run()` without `$commit` reads the file and answers every check, changing
 *   nothing. The same call with `$commit` writes it, and only if every check passed.
 * - What it writes is a DRAFT journal. It is then submitted and approved on the
 *   Journals screen like any other entry, so the preparer cannot approve their own
 *   conversion and the approval limits apply to it. Until it is approved the whole
 *   load can be thrown away; afterwards it is a posted journal, immutable like the
 *   rest, and a mistake is corrected by a further entry.
 *
 * What may be carried:
 *
 * - The period must be open, and nothing may already be posted in it or before it.
 *   Opening balances go onto an empty ledger; on top of live postings they would
 *   double the figures with no way to tell which entry was which.
 * - Where the period opens the fiscal year, only balance-sheet accounts may be
 *   carried: a year's income and expenditure ended with it and belongs in the
 *   accumulated fund. Converting mid-year, income and expenditure carry too, as
 *   the year to date.
 * - Figures are in the entity's own currency. There is no rate table to translate
 *   against, and a wrong rate buried in opening equity cannot be found later.
 * - A restricted or endowment fund may not open below zero; the ledger would refuse
 *   every later entry that drew on it.
 */
final class ConversionRepository extends Repository
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** The journal's document series and the type the ledger already uses for brought-forward figures. */
    public const PREFIX = 'OB';

    private const JOURNAL_TYPE = 'adjustment';

    private const SOURCE_TYPE = 'fiscal_year';

    private Lookups $lookups;

    private ?JournalRepository $journals = null;

    public function __construct(?BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->lookups = new Lookups();
    }

    /** The journal rules, so the load is refused for the same reasons a journal would be. */
    private function journals(): JournalRepository
    {
        return $this->journals ??= new JournalRepository();
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * What the screen needs before a file is chosen: the periods a conversion could
     * be dated in, the conversion already loaded, and whether the ledger is still
     * empty enough to take one.
     */
    public function options(): array
    {
        $batch = $this->batch();
        $periods = [];
        foreach ($this->lookups->periods() as $p) {
            if ($p['status'] !== 'open') {
                continue;
            }
            $posted = $this->postedBefore($p['starts_on']);
            $carried = $this->openingJournalIn($p);
            $periods[] = [
                'name'        => $p['name'],
                'starts'      => self::dmy($p['starts_on']),
                'cutOff'      => self::dmy($this->cutOff($p)),
                'yearStart'   => $this->opensYear($p),
                'postedBefore' => $posted,
                'carried'     => $carried,
                'available'   => $posted === 0 && $carried === null,
            ];
        }

        return [
            'periods'   => $periods,
            'batch'     => $batch === null ? null : $this->summary($batch),
            'columns'   => array_map(static fn ($names) => $names[0], TrialBalanceCsv::COLUMNS),
            'maxBytes'  => self::MAX_BYTES,
            'currency'  => $this->currency(),
        ];
    }

    /** The conversion this entity has loaded, whatever state it is in. */
    public function batch(): ?array
    {
        return $this->cached('batch', fn () => $this->row(
            "SELECT b.*, p.name AS period_name, j.reference AS journal_ref, j.status AS journal_status
             FROM {conversion_batches} b JOIN {periods} p ON p.id = b.period_id LEFT JOIN {journals} j ON j.id = b.journal_id
             WHERE b.entity_id = ? AND b.status <> 'discarded' ORDER BY b.id DESC LIMIT 1",
            [$this->lookups->entityId()]
        ));
    }

    /**
     * The trial balance to fill in, as a CSV the reader will accept back unchanged.
     *
     * The columns are the ones TrialBalanceCsv looks for first, so a file built from
     * this needs no mapping. Under them sits the organisation's own chart — every
     * postable account, already coded with the fund and programme it defaults to —
     * so the work is entering figures rather than matching codes. Where the period
     * opens the fiscal year, income and expenditure are left out: a year that has
     * ended carries its result in the accumulated fund, and a row for them would
     * only be rejected on the way back in.
     *
     * Accounts with nothing against them are deleted or left blank; a nil balance
     * brings nothing forward either way.
     *
     * @return array{filename: string, csv: string}
     */
    public function template(?string $period = null): array
    {
        $p = $period === null || $period === '' ? null : $this->lookups->periodByName($period);
        $yearStart = $p !== null && $this->opensYear($p);
        $funds = $this->lookups->funds();
        $programmes = $this->lookups->programmes();

        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Account code', 'Account name', 'Fund code', 'Programme code', 'Award ref', 'County code', 'Debit', 'Credit']);

        foreach ($this->lookups->accounts() as $a) {
            if ((int) $a['is_leaf'] === 0 || $a['status'] !== 'active') {
                continue;
            }
            if ($yearStart && in_array($a['type'], ['income', 'expense'], true)) {
                continue;
            }
            fputcsv($out, [
                $a['code'], $a['name'],
                $a['default_fund_id'] === null ? '' : ($funds[(int) $a['default_fund_id']]['code'] ?? ''),
                $a['default_programme_id'] === null ? '' : ($programmes[(int) $a['default_programme_id']]['code'] ?? ''),
                '', '', '', '',
            ]);
        }

        rewind($out);
        // UTF-8 BOM so a spreadsheet reads an account name's dashes and accents correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        $at = $p === null ? Clock::date() : $this->cutOff($p);

        return ['filename' => 'opening-balances-' . $at . '.csv', 'csv' => $csv];
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    /**
     * Reads a trial balance for a period and, when `$commit` is set and every check
     * passes, loads it as a draft journal.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     * @param array{source?: string, decimal?: string} $input where the figures came from, and how they were written
     */
    public function run(string $period, array $file, array $input, int $actorId, bool $commit): array
    {
        $p = $this->targetPeriod($period);
        $this->assertFile($file);

        $read = TrialBalanceCsv::read((string) file_get_contents($file['path']), ($input['decimal'] ?? '.') === ',' ? ',' : '.');
        if ($read['error'] !== null) {
            throw new RuleViolation($file['name'] . ': ' . $read['error']);
        }

        $rows = $this->resolve($read['rows'], $p);
        $checks = $this->checks($rows, $p, $read);
        $ok = array_filter($checks, static fn ($c) => !$c['ok']) === [];

        $debit  = round(array_sum(array_column($rows, 'debit')), 2);
        $credit = round(array_sum(array_column($rows, 'credit')), 2);

        $result = [
            'period'    => $p['name'],
            'date'      => self::dmy($p['starts_on']),
            'cutOff'    => self::dmy($this->cutOff($p)),
            'yearStart' => $this->opensYear($p),
            'file'      => $file['name'],
            'headers'   => $read['headers'],
            'mapped'    => $read['mapped'],
            'rows'      => array_map(static fn ($r) => [
                'line' => $r['line'], 'account' => $r['account'], 'name' => $r['name'], 'description' => $r['description'],
                'fund' => $r['fundName'], 'programme' => $r['programmeName'], 'grant' => $r['grant'], 'county' => $r['county'],
                'dr' => self::num($r['debit']), 'cr' => self::num($r['credit']), 'problem' => $r['problem'],
            ], $rows),
            'summary'   => [
                'read' => count($read['rows']), 'loaded' => count(array_filter($rows, static fn ($r) => $r['problem'] === null)),
                'problems' => count(array_filter($rows, static fn ($r) => $r['problem'] !== null)),
                'debit' => self::num($debit), 'credit' => self::num($credit),
            ],
            'checks'    => $checks,
            'ok'        => $ok,
        ];

        if (!$commit) {
            return $result;
        }
        if (!$ok) {
            throw new RuleViolation(current(array_filter($checks, static fn ($c) => !$c['ok']))['label'] . '.');
        }

        return $result + ['committed' => $this->commit($rows, $p, $file, $input, $debit, $credit, count($read['rows']), $actorId)];
    }

    /**
     * Throws the conversion away. A load that has not become a journal, and a journal
     * still in draft, both go; once the entry has been approved it is a posted journal
     * like any other and only a further entry can change it.
     */
    public function discard(int $actorId): array
    {
        $batch = $this->batch() ?? throw new RuleViolation('There is no conversion to discard.');
        if ($batch['journal_status'] !== null && !in_array($batch['journal_status'], ['draft', 'rejected'], true)) {
            throw new RuleViolation($batch['journal_ref'] . ' has been ' . strtolower(self::label($batch['journal_status']))
                . ' and is part of the ledger now. Correct the opening balances with a journal in an open period.');
        }

        $ref = $batch['journal_ref'];

        return $this->transaction(function () use ($batch, $ref, $actorId) {
            $this->db->table('conversion_batches')->where('id', $batch['id'])->update([
                'status' => 'discarded', 'journal_id' => null, 'updated_at' => Clock::timestamp(),
            ]);
            if ($batch['journal_id'] !== null) {
                $this->db->table('journal_lines')->where('journal_id', $batch['journal_id'])->delete();
                $this->db->table('journals')->where('id', $batch['journal_id'])->delete();
            }
            $this->audit('conversion', (int) $batch['id'], $ref, 'Conversion discarded by ' . $this->lookups->shortName($actorId)
                . ($ref === null ? '' : ' — draft ' . $ref . ' deleted'), $actorId, 'history', (int) $batch['entity_id']);

            return ['message' => 'The conversion has been discarded. Nothing of it remains in the ledger.'];
        });
    }

    // ------------------------------------------------------------------
    // Resolving the file against the chart
    // ------------------------------------------------------------------

    /**
     * Each row with the account, fund, programme, award and county it names, and why
     * it cannot become a posting line. Coding the old system did not carry is taken
     * from the account's defaults; a line the chart cannot place says so rather than
     * being quietly dropped.
     *
     * @return list<array>
     */
    private function resolve(array $rows, array $period): array
    {
        $accounts   = $this->lookups->accounts();
        $funds      = $this->lookups->funds();
        $programmes = $this->lookups->programmes();
        $counties   = array_column($this->rows('SELECT id, code, name FROM {counties}'), null, 'code');
        $yearStart  = $this->opensYear($period);

        $out = [];
        foreach ($rows as $r) {
            $account = $accounts[$r['account']] ?? null;
            $problem = match (true) {
                $r['errors'] !== []             => ucfirst(implode('; ', $r['errors'])),
                $account === null               => 'Account ' . $r['account'] . ' is not in the chart of accounts',
                (int) $account['is_leaf'] === 0 => $account['code'] . ' ' . $account['name'] . ' is a heading, not a postable account',
                $account['status'] !== 'active' => $account['code'] . ' ' . $account['name'] . ' is archived',
                $yearStart && in_array($account['type'], ['income', 'expense'], true)
                    => $account['code'] . ' ' . $account['name'] . ' is ' . $account['type'] . '. A year that has ended carries its result in the accumulated fund, not line by line',
                default => null,
            };

            $fundId = $programmeId = $grantId = $countyId = null;
            $fundName = $r['fund'];
            $programmeName = $r['programme'];

            if ($problem === null) {
                $fundId = $this->match($funds, $r['fund']) ?? ($r['fund'] === '' ? self::asInt($account['default_fund_id']) : null);
                if ($fundId === null) {
                    $problem = $r['fund'] === ''
                        ? 'No fund: the file gives none and ' . $account['code'] . ' has no default fund'
                        : 'Fund "' . $r['fund'] . '" is not in the ledger';
                } else {
                    $fundName = $funds[$fundId]['name'];
                }
            }

            if ($problem === null) {
                $programmeId = $this->match($programmes, $r['programme'])
                    ?? ($r['programme'] === '' ? (self::asInt($account['default_programme_id']) ?? $this->lookups->programmeId(null)) : null);
                if ($programmeId === null) {
                    $problem = 'Programme "' . $r['programme'] . '" is not in the ledger';
                } else {
                    $programmeName = $programmes[$programmeId]['name'];
                }
            }

            if ($problem === null && $r['grant'] !== '') {
                $grantId = $this->lookups->grantId($r['grant']);
                if ($grantId === null) {
                    $problem = 'Award "' . $r['grant'] . '" is not on the grant register';
                }
            }

            if ($problem === null) {
                $problem = $this->journals()->awardProblem([[
                    'code' => $r['account'], 'fund' => $this->lookups->fundGroupLabel($fundId),
                    'program' => $programmeName, 'grantRef' => $r['grant'],
                ]], true);
            }

            if ($problem === null && $r['county'] !== '') {
                $countyId = isset($counties[$r['county']]) ? (int) $counties[$r['county']]['id'] : null;
                if ($countyId === null) {
                    $problem = 'County code "' . $r['county'] . '" is not recognised';
                }
            }

            $out[] = $r + [
                'name'          => $account['name'] ?? '',
                'type'          => $account['type'] ?? '',
                'accountId'     => $account === null ? null : (int) $account['id'],
                'fundId'        => $fundId,
                'fundName'      => $fundName,
                'restriction'   => $fundId === null ? '' : $funds[$fundId]['restriction'],
                'programmeId'   => $programmeId,
                'programmeName' => $programmeName,
                'grantId'       => $grantId,
                'countyId'      => $countyId,
                'problem'       => $problem,
            ];
        }

        return $out;
    }

    /** A fund or programme by code or name, case and spacing aside. */
    private function match(array $records, string $text): ?int
    {
        $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($text)));
        if ($key === '') {
            return null;
        }
        foreach ($records as $id => $record) {
            if (mb_strtolower($record['code']) === $key || mb_strtolower($record['name']) === $key) {
                return (int) $id;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // The checks
    // ------------------------------------------------------------------

    /**
     * Every rule the load is held to, each answered in its own words so the screen
     * can show what to fix rather than a refusal.
     *
     * @return list<array{ok: bool, label: string}>
     */
    private function checks(array $rows, array $period, array $read): array
    {
        $problems = array_values(array_filter($rows, static fn ($r) => $r['problem'] !== null));
        $debit  = round(array_sum(array_column($rows, 'debit')), 2);
        $credit = round(array_sum(array_column($rows, 'credit')), 2);
        $money  = static fn (float $n) => BankRepository::money($n);

        $checks = [];

        $checks[] = $problems === []
            ? ['ok' => true, 'label' => count($rows) . (count($rows) === 1 ? ' balance' : ' balances') . ' read, every one placed on the chart']
            : ['ok' => false, 'label' => count($problems) . (count($problems) === 1 ? ' balance cannot be placed' : ' balances cannot be placed')
                . ' — line ' . implode(', ', array_slice(array_column($problems, 'line'), 0, 6))
                . (count($problems) > 6 ? ' and ' . (count($problems) - 6) . ' more' : '')];

        $checks[] = $rows !== []
            ? ['ok' => true, 'label' => 'Debits ' . $money($debit) . ' against credits ' . $money($credit)]
            : ['ok' => false, 'label' => 'The file carries no balances'];

        $checks[] = $debit == $credit && $debit > 0
            ? ['ok' => true, 'label' => 'The trial balance balances']
            : ['ok' => false, 'label' => $debit == 0.0 && $credit == 0.0
                ? 'Every balance in the file is nil'
                : 'The trial balance is out by ' . $money(abs($debit - $credit)) . ' — debits ' . $money($debit) . ', credits ' . $money($credit)];

        $overdrawn = [];
        foreach ($rows as $r) {
            if ($r['problem'] === null && in_array($r['type'], ['equity', 'income', 'expense'], true) && in_array($r['restriction'], ['restricted', 'endowment'], true)) {
                $overdrawn[$r['fundName']] = ($overdrawn[$r['fundName']] ?? 0) + $r['credit'] - $r['debit'];
            }
        }
        $negative = array_keys(array_filter($overdrawn, static fn ($n) => round($n, 2) < 0));
        $checks[] = $negative === []
            ? ['ok' => true, 'label' => 'No restricted or endowment fund opens below zero']
            : ['ok' => false, 'label' => self::list($negative) . (count($negative) === 1 ? ' opens' : ' open')
                . ' below zero. The ledger would refuse every later entry drawing on ' . (count($negative) === 1 ? 'it' : 'them')];

        $posted = $this->postedBefore($period['starts_on']);
        $checks[] = $posted === 0
            ? ['ok' => true, 'label' => 'Nothing is posted on or before ' . self::dmy($this->cutOff($period)) . ', so the balances stand on their own']
            : ['ok' => false, 'label' => $posted . (($posted === 1) ? ' entry is' : ' entries are') . ' already posted up to ' . self::dmy($this->cutOff($period))
                . '. Opening balances go onto an empty ledger, or the figures count twice'];

        $existing = $this->batch();
        $carried = $this->openingJournalIn($period);
        $checks[] = match (true) {
            $existing !== null && $existing['status'] === 'committed' => ['ok' => false, 'label' => 'Opening balances were already loaded as '
                . $existing['journal_ref'] . '. Discard that conversion before loading another'],
            $carried !== null => ['ok' => false, 'label' => $carried . ' already carries brought-forward figures into ' . $period['name']
                . '. Balances are carried once; correct them with a journal in an open period'],
            default => ['ok' => true, 'label' => 'No balances have been carried into ' . $period['name'] . ' yet'],
        };

        return $checks;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** Records the load and writes the balances as a draft journal for approval. */
    private function commit(array $rows, array $period, array $file, array $input, float $debit, float $credit, int $read, int $actorId): array
    {
        $source = mb_substr(trim((string) ($input['source'] ?? '')), 0, 80) ?: 'a legacy system';
        $cutOff = $this->cutOff($period);

        return $this->transaction(function () use ($rows, $period, $file, $source, $cutOff, $debit, $credit, $read, $actorId) {
            $now = Clock::timestamp();
            $entityId = $this->lookups->entityId();
            $ref = $this->nextReference(self::PREFIX, $period['starts_on']);

            // Any earlier load for this entity is superseded, not kept alongside.
            $this->db->table('conversion_batches')->where('entity_id', $entityId)->where('status !=', 'discarded')
                ->update(['status' => 'discarded', 'journal_id' => null, 'updated_at' => $now]);

            $journalId = $this->insert('journals', [
                'entity_id' => $entityId, 'period_id' => $period['id'], 'reference' => $ref,
                'journal_date' => $period['starts_on'], 'type' => self::JOURNAL_TYPE, 'status' => 'draft',
                'document_type_id' => $this->documentTypeId(), 'document_ref' => $ref,
                'source_type' => self::SOURCE_TYPE, 'source_id' => $period['fiscal_year_id'],
                'memo' => mb_substr('Balances at ' . self::dmy($cutOff) . ' from ' . $source, 0, 255),
                'narration' => 'Balances brought forward from ' . $source . ' at the close of ' . self::dmy($cutOff)
                    . ', loaded from ' . $file['name'] . '. Prepared for approval before it joins the ledger.',
                'prepared_by' => $actorId, 'created_at' => $now,
            ]);

            $lineNo = 0;
            foreach ($rows as $r) {
                if ($r['problem'] !== null) {
                    continue;
                }
                $this->insert('journal_lines', [
                    'journal_id' => $journalId, 'line_no' => ++$lineNo,
                    'account_id' => $r['accountId'], 'fund_id' => $r['fundId'], 'programme_id' => $r['programmeId'],
                    'grant_id' => $r['grantId'], 'county_id' => $r['countyId'],
                    'description' => mb_substr($r['description'] !== '' ? $r['description'] : $r['name'] . ' brought forward', 0, 255),
                    'debit' => $r['debit'], 'credit' => $r['credit'],
                ]);
            }

            $batchId = $this->insert('conversion_batches', [
                'entity_id' => $entityId, 'period_id' => $period['id'], 'conversion_date' => $cutOff,
                'source_system' => $source, 'filename' => mb_substr($file['name'], 0, 255), 'status' => 'committed',
                'rows_read' => $read, 'rows_loaded' => $lineNo, 'total_debit' => $debit, 'total_credit' => $credit,
                'journal_id' => $journalId, 'loaded_by' => $actorId, 'committed_by' => $actorId, 'committed_at' => $now,
                'created_at' => $now,
            ]);

            foreach ($rows as $r) {
                $this->insert('conversion_lines', [
                    'batch_id' => $batchId, 'row_no' => $r['line'], 'account_code' => $r['account'],
                    'fund_code' => $r['fund'] !== '' ? $r['fund'] : null, 'programme_code' => $r['programme'] !== '' ? $r['programme'] : null,
                    'grant_ref' => $r['grant'] !== '' ? $r['grant'] : null, 'county_code' => $r['county'] !== '' ? $r['county'] : null,
                    'description' => $r['description'], 'debit' => $r['debit'], 'credit' => $r['credit'],
                    'problem' => $r['problem'] === null ? null : mb_substr($r['problem'], 0, 255),
                ]);
            }

            $note = $lineNo . (($lineNo === 1) ? ' balance' : ' balances') . ' totalling ' . BankRepository::money($debit)
                . ' brought forward from ' . $source . ' at ' . self::dmy($cutOff);
            $this->audit('conversion', $batchId, $ref, $note . ', loaded by ' . $this->lookups->shortName($actorId), $actorId, 'history', $entityId);
            $this->audit('journal', $journalId, $ref, 'Opening balances loaded from ' . $file['name'] . ' by ' . $this->lookups->shortName($actorId), $actorId);

            return [
                'reference' => $ref,
                'lines'     => $lineNo,
                'message'   => $ref . ' holds ' . $note . '. It is a draft: submit it on the Journals screen so a second person approves it before it joins the ledger.',
            ];
        });
    }

    // ------------------------------------------------------------------
    // Rules about the period
    // ------------------------------------------------------------------

    private function targetPeriod(string $name): array
    {
        $p = $this->lookups->periodByName($name) ?? throw new RuleViolation('There is no period called ' . $name . '.');
        if ($p['status'] !== 'open') {
            throw new RuleViolation($name . ' is closed. Opening balances are dated in the first open period the ledger keeps.');
        }

        return $p;
    }

    /** The last day the old system is authoritative for: the day before the period starts. */
    private function cutOff(array $period): string
    {
        return date('Y-m-d', strtotime($period['starts_on'] . ' -1 day'));
    }

    /** Whether the period is the first of its fiscal year, so a full year has ended before it. */
    private function opensYear(array $period): bool
    {
        return $period['starts_on'] === $this->value('SELECT starts_on FROM {fiscal_years} WHERE id = ?', [$period['fiscal_year_id']]);
    }

    /** The reference of the opening journal a period already carries, if any. */
    private function openingJournalIn(array $period): ?string
    {
        return $this->value(
            "SELECT reference FROM {journals} WHERE entity_id = ? AND period_id = ? AND source_type = ? AND status <> 'rejected' ORDER BY id LIMIT 1",
            [$this->lookups->entityId(), $period['id'], self::SOURCE_TYPE]
        );
    }

    /** Posted entries dated before a date, other than a conversion's own. */
    private function postedBefore(string $startsOn): int
    {
        return (int) $this->value(
            "SELECT COUNT(*) FROM {journals} WHERE entity_id = ? AND journal_date < ? AND status IN ('posted', 'reversed') AND (source_type IS NULL OR source_type <> ?)",
            [$this->lookups->entityId(), $startsOn, self::SOURCE_TYPE]
        );
    }

    private function assertFile(array $file): void
    {
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuleViolation($file['name'] . ' is larger than 2 MB. A trial balance is a few hundred rows — check it is the right file.');
        }
        if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw new RuleViolation($file['name'] . ' is not a CSV file. Export the trial balance from the old system as CSV.');
        }
    }

    // ------------------------------------------------------------------
    // Small things
    // ------------------------------------------------------------------

    /** The OB document series, created the first time an entity converts. */
    private function documentTypeId(): int
    {
        $id = $this->value('SELECT id FROM {document_types} WHERE prefix = ?', [self::PREFIX]);

        return $id === null ? $this->insert('document_types', ['prefix' => self::PREFIX, 'name' => 'Opening balance']) : (int) $id;
    }

    /** OB-26-0001: one more than the highest issued for the prefix and year, never reused. */
    private function nextReference(string $prefix, string $date): string
    {
        $stem = $prefix . '-' . substr($date, 2, 2) . '-';
        $max  = 0;
        foreach ($this->rows('SELECT reference FROM {journals} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** The conversion as the screen lists it. */
    private function summary(array $batch): array
    {
        return [
            'period'    => $batch['period_name'],
            'cutOff'    => self::dmy($batch['conversion_date']),
            'source'    => $batch['source_system'],
            'file'      => $batch['filename'],
            'rows'      => (int) $batch['rows_loaded'],
            'total'     => self::num($batch['total_debit']),
            'reference' => $batch['journal_ref'],
            'status'    => self::label($batch['journal_status'] ?? $batch['status']),
            'settled'   => in_array($batch['journal_status'], ['posted', 'reversed'], true),
            'loadedBy'  => $this->lookups->shortName(self::asInt($batch['loaded_by'])),
            'loadedAt'  => self::dmy(substr((string) $batch['committed_at'], 0, 10)),
        ];
    }

    private static function asInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function list(array $items): string
    {
        return count($items) < 2 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }

    private function currency(): string
    {
        return (string) ($this->value('SELECT functional_currency FROM {entities} WHERE id = ?', [$this->lookups->entityId()]) ?? 'KES');
    }
}
