<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use Config\Documents;

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

    /** A manual entry's document series by journal type; every other type is JV. */
    private const DOC_SERIES = ['Accrual' => 'AC', 'Recurring' => 'AC', 'Allocation' => 'AL'];

    /** Manual document numbers continue from here where a series has issued fewer. */
    private const DOC_SERIES_FLOOR = 230;

    /** Where supporting documents are kept, under writable/uploads. */
    private const ATTACHMENT_DIR = 'journals';

    /**
     * Journals in the register: everything but the archived detail of months
     * locked before the ledger was migrated, which the general ledger lists and
     * the register summarises by period, and the cash book vouchers whose source
     * documents live outside the ledger, which the general ledger and the bank
     * reconciliation list.
     */
    private const REGISTER = "(j.source_type IS NULL OR j.source_type NOT IN ('archive', 'cash_book'))";

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
                "SELECT l.*, a.code, f.ledger_group, g.award_ref, g.short_name AS grant_short
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
                 LEFT JOIN {grants} g ON g.id = l.grant_id WHERE " . self::REGISTER . ' ORDER BY l.journal_id, l.line_no'
            ) as $l) {
                $lines[(int) $l['journal_id']][] = [
                    'code'     => $l['code'],
                    'desc'     => $l['description'],
                    'fund'     => self::FUND_GROUPS[$l['ledger_group']],
                    'program'  => $this->lookups->programmeName((int) $l['programme_id']),
                    'grantRef' => $l['award_ref'] ?? '',
                    'grant'    => $l['grant_short'] ?? '',
                    // A fund that awards are held in: the line reports by award.
                    'restricted' => in_array($l['ledger_group'], ['grant', 'capital'], true),
                    'dr'       => self::num($l['debit']),
                    'cr'       => self::num($l['credit']),
                ];
            }

            $trails = $this->trails('journal');
            $attachments = $this->attachments();

            return array_map(function ($j) use ($lines, $trails, $attachments) {
                $journal = [
                    'ref'       => $j['reference'],
                    'date'      => self::dmy($j['journal_date']),
                    'dateISO'   => $j['journal_date'],
                    'type'      => self::label($j['type']),
                    'period'    => $j['period_name'],
                    'status'    => self::label($j['status']),
                    'preparer'  => $this->lookups->shortName((int) $j['prepared_by']),
                    'doc'       => $j['document_ref'] ?? '',
                    'docLink'   => $j['source_type'] === null ? ($j['document_ref'] === null ? 'auto' : 'existing:' . $j['document_ref']) : $this->sourceLink($j['source_type'], (int) $j['source_id']),
                    'memo'      => $j['memo'] ?? '',
                    'narration' => $j['narration'],
                    'opening'   => $j['source_type'] === 'fiscal_year',
                    'submittedAt' => $j['submitted_at'],
                    'lines'     => $lines[(int) $j['id']] ?? [],
                    'attachments' => $attachments[(int) $j['id']] ?? [],
                    'trail'     => $trails[(int) $j['id']] ?? [],
                ];

                return $j['reverses_ref'] !== null ? $journal + ['reversalOf' => $j['reverses_ref']] : $journal;
            }, $this->rows(
                'SELECT j.*, p.name AS period_name, r.reference AS reverses_ref
                 FROM {journals} j JOIN {periods} p ON p.id = j.period_id LEFT JOIN {journals} r ON r.id = j.reverses_journal_id
                 WHERE ' . self::REGISTER . ' ORDER BY j.journal_date DESC, j.reference DESC'
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
     * The preparer is the signed-in user. The period must be open unless the
     * closed-period control allows otherwise, and the date must fall inside it. A
     * line naming an award is held to the funds and programmes the award covers;
     * a line in a fund that awards are held in needs its award before the entry
     * leaves draft. `docLink` is "auto" (the next number in the type's ELOG series),
     * "bill:<reference>", "payroll:<period code>" or "bankline:<statement line id>".
     *
     * @param array{date: string, type: string, period: string, status: string, docLink: string, memo: string,
     *              narration: string, reversalOf?: string, lines: list<array{code: string, desc: string, fund: string, program: string, grantRef: string, dr: float, cr: float}>} $j
     * @param list<array{path: string, name: string, size: int, mime: string}> $files uploaded supporting documents
     */
    public function create(array $j, int $actorId, array $files = []): array
    {
        [$period, $date, $lines, $source] = $this->prepare($j);
        $this->assertSupported($j, isset($j['reversalOf']) ? 'reversal' : (string) ($j['docLink'] ?? 'auto'), count($files));

        $stored = [];
        try {
            $ref = $this->transaction(function () use ($j, $period, $date, $actorId, $lines, $source, $files, &$stored) {
                $ref = $this->nextReference('JV', $date);
                $reverses = isset($j['reversalOf']) ? $this->value('SELECT id FROM {journals} WHERE reference = ?', [$j['reversalOf']]) : null;
                $series = $source === null ? (self::DOC_SERIES[$j['type']] ?? 'JV') : $source['prefix'];

                $id = $this->insert('journals', [
                    'entity_id' => $this->lookups->entityId(), 'period_id' => $period['id'], 'reference' => $ref, 'journal_date' => $date,
                    'type' => strtolower($j['type']), 'status' => 'draft',
                    'document_type_id' => $this->value('SELECT id FROM {document_types} WHERE prefix = ?', [$series])
                        ?? $this->value('SELECT id FROM {document_types} WHERE prefix = ?', ['JV']),
                    'document_ref' => $source === null ? $this->autoDocRef($j['type']) : $source['ref'],
                    'source_type' => $source['type'] ?? null, 'source_id' => $source['id'] ?? null,
                    'memo' => $j['memo'] !== '' ? $j['memo'] : null,
                    'narration' => $j['narration'], 'prepared_by' => $actorId, 'reverses_journal_id' => $reverses,
                    'created_at' => Clock::timestamp(),
                ]);

                foreach ($lines as $i => $l) {
                    $this->insert('journal_lines', ['journal_id' => $id, 'line_no' => $i + 1] + $l);
                }

                foreach ($files as $file) {
                    $stored[] = $this->storeAttachment($id, $file, $actorId);
                }

                $this->audit('journal', $id, $ref, $j['createdNote'] ?? 'Draft created by ' . $this->lookups->shortName($actorId), $actorId);

                if ($j['status'] === 'Pending approval') {
                    $this->db->table('journals')->where('id', $id)->update(['status' => 'pending_approval', 'submitted_at' => Clock::timestamp()]);
                    $approver = $this->lookups->holderOf($this->approverRole());
                    $this->audit('journal', $id, $ref, 'Submitted for approval to ' . $this->lookups->shortName($approver, 'the approver'), $actorId);
                }

                return $ref;
            });
        } catch (\Throwable $e) {
            (new AttachmentRepository($this->db))->removeFiles($stored);

            throw $e;
        }

        return $this->find($ref);
    }

    /**
     * The checks every save makes, and the entry as it will be stored.
     *
     * @return array{0: array, 1: string, 2: list<array>, 3: array|null} period, date, resolved lines, source record
     */
    private function prepare(array $j): array
    {
        $period = $this->lookups->periodByName($j['period']);
        if ($period === null) {
            throw new RuleViolation($j['period'] . ' is not an accounting period.');
        }
        if ($period['status'] === 'closed' && !$this->allowsClosedPeriods()) {
            throw new RuleViolation($j['period'] . ' is closed to further posting. Date the entry in an open period, or have the Finance Manager reopen the month in Period close.');
        }
        $date = date('Y-m-d', strtotime($j['date']) ?: strtotime(Clock::date()));
        if ($date < $period['starts_on'] || $date > $period['ends_on']) {
            throw new RuleViolation('The posting date must fall within ' . $j['period'] . '. Change the period first if the entry belongs in ' . date('M Y', strtotime($date)) . '.');
        }

        $this->checkAwards($j['lines'], $j['status'] !== 'Draft');

        return [$period, $date, $this->resolveLines($j['lines']), $this->source($j['docLink'] ?? 'auto')];
    }

    /**
     * Saves changes to a draft, or to an entry awaiting approval, as a draft or
     * submitted for approval. An entry awaiting approval returns to draft while its
     * lines are replaced. The preparer is not reassigned. A `docLink` the entry
     * already carries keeps its document reference.
     *
     * @param list<int> $removeAttachments ids of supporting documents to remove
     */
    public function update(string $ref, array $j, int $actorId, array $files = [], array $removeAttachments = []): array
    {
        $journal = $this->header($ref);
        if (!in_array($journal['status'], ['draft', 'rejected', 'pending_approval'], true)) {
            throw new RuleViolation($ref . ' is ' . strtolower(self::label($journal['status'])) . ' and can no longer be changed. Correct it with a reversal.');
        }
        if ($journal['source_type'] === 'fund_transfer') {
            throw new RuleViolation($ref . ' records an inter-fund transfer, and its lines are the transfer\'s. Discard it once returned, and raise the transfer again from Funds.');
        }

        $current = $this->find($ref);
        // A document that went for approval with the entry is part of what the approver
        // is looking at, so it stays until the entry is taken back to draft — by its
        // preparer saving it as a draft, or by the approver returning it.
        $removeAttachments = array_values(array_intersect(array_map('intval', $removeAttachments), array_column($current['attachments'], 'id')));
        if ($removeAttachments !== [] && $journal['status'] === 'pending_approval') {
            throw new RuleViolation($ref . ' is awaiting approval, and the documents it was submitted with stay with it. Save it as a draft to take it back from approval, then remove the document.');
        }
        $keepDocument = ($j['docLink'] ?? 'auto') === $current['docLink'];
        [$period, $date, $lines] = $this->prepare(['docLink' => 'auto'] + $j);
        $source = $keepDocument ? false : $this->source($j['docLink'] ?? 'auto');
        $kept = array_filter($current['attachments'], static fn ($a) => !in_array($a['id'], $removeAttachments, true));
        // A reversal is supported by the entry it reverses, and an entry raised from a
        // record (the opening balances' fiscal year, a bill) by that record.
        $link = match (true) {
            ($current['reversalOf'] ?? '') !== '' => 'reversal',
            $journal['source_type'] !== null      => 'source:' . $journal['source_type'],
            $keepDocument                         => (string) $current['docLink'],
            default                               => (string) ($j['docLink'] ?? 'auto'),
        };
        $this->assertSupported($j, $link, count($kept) + count($files));

        $stored = [];
        $removed = [];
        try {
            $this->transaction(function () use ($j, $journal, $ref, $period, $date, $actorId, $lines, $source, $files, $removeAttachments, &$stored, &$removed) {
                $id = (int) $journal['id'];
                $now = Clock::timestamp();
                $header = [
                    'period_id' => $period['id'], 'journal_date' => $date, 'type' => strtolower($j['type']), 'status' => 'draft',
                    'memo' => $j['memo'] !== '' ? $j['memo'] : null, 'narration' => $j['narration'], 'updated_at' => $now,
                ];
                if ($source !== false) {
                    $series = $source === null ? (self::DOC_SERIES[$j['type']] ?? 'JV') : $source['prefix'];
                    $header += [
                        'document_type_id' => $this->value('SELECT id FROM {document_types} WHERE prefix = ?', [$series]) ?? $journal['document_type_id'],
                        'document_ref' => $source === null ? $this->autoDocRef($j['type']) : $source['ref'],
                        'source_type' => $source['type'] ?? null, 'source_id' => $source['id'] ?? null,
                    ];
                }
                $this->db->table('journals')->where('id', $id)->update($header);

                $this->db->table('journal_lines')->where('journal_id', $id)->delete();
                foreach ($lines as $i => $l) {
                    $this->insert('journal_lines', ['journal_id' => $id, 'line_no' => $i + 1] + $l);
                }

                foreach ($removeAttachments as $attachmentId) {
                    $row = $this->row("SELECT id, storage_key FROM {attachments} WHERE object_type = 'journal' AND object_id = ? AND id = ?", [$id, (int) $attachmentId]);
                    if ($row !== null) {
                        $this->db->table('attachments')->where('id', $row['id'])->delete();
                        $removed[] = $row['storage_key'];
                    }
                }
                foreach ($files as $file) {
                    $stored[] = $this->storeAttachment($id, $file, $actorId);
                }

                $who = $this->lookups->shortName($actorId);
                if ($j['status'] === 'Pending approval') {
                    $this->db->table('journals')->where('id', $id)->update(['status' => 'pending_approval', 'submitted_at' => $now]);
                    $this->audit('journal', $id, $ref, 'Submitted for approval by ' . $who, $actorId);
                } else {
                    $this->audit('journal', $id, $ref, 'Draft saved by ' . $who, $actorId);
                }
                $this->syncRuns($id);
            });
        } catch (\Throwable $e) {
            (new AttachmentRepository($this->db))->removeFiles($stored);

            throw $e;
        }

        (new AttachmentRepository($this->db))->removeFiles($removed);

        return $this->find($ref);
    }

    /** Discards a draft: its lines and supporting documents go with it; the audit log keeps the record. */
    public function discard(string $ref, int $actorId): void
    {
        $journal = $this->header($ref);
        if (!in_array($journal['status'], ['draft', 'rejected'], true)) {
            throw new RuleViolation('Only a draft can be discarded. ' . $ref . ' is ' . strtolower(self::label($journal['status'])) . '.');
        }

        $files = [];
        $this->transaction(function () use ($journal, $ref, $actorId, &$files) {
            $id = (int) $journal['id'];
            foreach ($this->rows("SELECT id, storage_key FROM {attachments} WHERE object_type = 'journal' AND object_id = ?", [$id]) as $a) {
                $files[] = $a['storage_key'];
            }
            $this->db->table('attachments')->where('object_type', 'journal')->where('object_id', $id)->delete();
            // A template run keeps its place in the run history.
            $this->db->table('recurring_template_runs')->where('journal_id', $id)->update(['journal_id' => null, 'status' => 'discarded']);
            $this->db->table('journal_lines')->where('journal_id', $id)->delete();
            $this->db->table('journals')->where('id', $id)->delete();
            $this->audit('journal', $id, $ref, 'Discarded by ' . $this->lookups->shortName($actorId), $actorId);
        });

        (new AttachmentRepository($this->db))->removeFiles($files);
    }

    /**
     * Raises the reversal of a posted entry and sends it for approval: the same
     * lines with debits and credits swapped, keeping every segment, dated in the
     * first open period. The original is marked reversed when the reversal posts.
     */
    public function reverse(string $ref, int $actorId): array
    {
        $journal = $this->header($ref);
        if ($journal['status'] !== 'posted') {
            throw new RuleViolation('Only a posted entry can be reversed. ' . $ref . ' is ' . strtolower(self::label($journal['status'])) . '.');
        }
        if ($journal['source_type'] === 'archive' || $journal['source_type'] === 'fiscal_year') {
            throw new RuleViolation($ref . ' is held in a closed period\'s archive. Correct it with a new journal in an open period.');
        }
        // Reversing a purchase already on the asset register would take the cost out
        // of the ledger and leave the assets behind.
        $tags = array_column($this->rows(
            'SELECT a.tag FROM {assets} a JOIN {journal_lines} l ON l.id = a.acquisition_journal_line_id WHERE l.journal_id = ? ORDER BY a.tag',
            [$journal['id']]
        ), 'tag');
        if ($tags !== []) {
            throw new RuleViolation($ref . ' was capitalised on the asset register as ' . implode(', ', array_slice($tags, 0, 3)) . (count($tags) > 3 ? ' and ' . (count($tags) - 3) . ' more' : '')
                . '. Take the assets off through a disposal, or correct the purchase with a new journal.');
        }
        $pending = $this->value(
            "SELECT reference FROM {journals} WHERE reverses_journal_id = ? AND status IN ('pending_approval', 'posted') LIMIT 1",
            [$journal['id']]
        );
        if ($pending !== null) {
            throw new RuleViolation($pending . ' already reverses ' . $ref . '.');
        }

        $period = current(array_filter($this->lookups->yearPeriods(), static fn ($p) => $p['status'] === 'open'));
        if ($period === false) {
            throw new RuleViolation('No period is open for posting, so ' . $ref . ' cannot be reversed yet.');
        }
        $date = min(max(Clock::date(), $period['starts_on']), $period['ends_on']);

        $reversal = $this->transaction(function () use ($journal, $ref, $actorId, $period, $date) {
            $reversal = $this->nextReference('JV', $date);
            $now = Clock::timestamp();
            $who = $this->lookups->shortName($actorId);
            $id = $this->insert('journals', [
                'entity_id' => $journal['entity_id'], 'period_id' => $period['id'], 'reference' => $reversal, 'journal_date' => $date,
                'type' => 'reversing', 'status' => 'draft', 'document_type_id' => $journal['document_type_id'], 'document_ref' => $journal['document_ref'],
                'memo' => 'Reversal of ' . $ref . '.', 'narration' => 'Reversal of ' . $ref . ' — ' . $journal['narration'],
                'prepared_by' => $actorId, 'reverses_journal_id' => $journal['id'], 'created_at' => $now,
            ]);
            foreach ($this->rows('SELECT * FROM {journal_lines} WHERE journal_id = ? ORDER BY line_no', [$journal['id']]) as $l) {
                $this->insert('journal_lines', [
                    'journal_id' => $id, 'line_no' => $l['line_no'], 'account_id' => $l['account_id'], 'fund_id' => $l['fund_id'],
                    'programme_id' => $l['programme_id'], 'grant_id' => $l['grant_id'], 'county_id' => $l['county_id'],
                    'description' => $l['description'], 'debit' => $l['credit'], 'credit' => $l['debit'],
                ]);
            }
            $this->audit('journal', $id, $reversal, 'Reversing entry raised against ' . $ref . ' by ' . $who, $actorId);
            $this->db->table('journals')->where('id', $id)->update(['status' => 'pending_approval', 'submitted_at' => $now]);
            $this->audit('journal', $id, $reversal, 'Submitted for approval to ' . $this->lookups->shortName($this->lookups->holderOf($this->approverRole()), 'the approver'), $actorId);

            return $reversal;
        });

        return $this->find($reversal);
    }

    /** A template run whose journal moved on records nothing itself: its status is read from the journal. */
    private function syncRuns(int $journalId): void
    {
        $this->db->table('recurring_template_runs')->where('journal_id', $journalId)->update(['status' => null]);
    }

    /**
     * What the new-journal form offers, for the acting user: the next reference, the
     * periods and their dates, the document series and linkable records, and the
     * funds and programmes each award may be charged to.
     *
     * @param array{short: string, role: string, canPrepare: bool, canApprove: bool} $actor
     */
    public function formOptions(array $actor): array
    {
        $periods = $this->lookups->yearPeriods();
        $open = array_values(array_filter($periods, static fn ($p) => $p['status'] === 'open'));
        $period = $open[0] ?? end($periods);
        $today = Clock::date();
        $date = min(max($today, $period['starts_on']), $period['ends_on']);

        $grants = [];
        foreach ($this->lookups->grants() as $id => $g) {
            $funds = $this->lookups->grantLedgerFunds((int) $id);
            if (!in_array($g['status'], ['active', 'closing'], true) || $funds === []) {
                continue;
            }
            $grants[] = [
                'ref'        => $g['award_ref'],
                'label'      => $g['short_name'],
                'funds'      => $funds,
                'programmes' => array_map(fn ($p) => $this->lookups->programmeName($p), $this->lookups->grantProgrammes((int) $id)),
            ];
        }

        $accounts = array_filter($this->lookups->accounts(), static fn ($a) => (int) $a['is_leaf'] === 1 && $a['status'] === 'active');
        $activeGroups = array_column(array_filter($this->lookups->funds(), static fn ($f) => $f['status'] === 'active'), 'ledger_group');

        return [
            'ref'         => $this->nextReference('JV', $date),
            'date'        => $date,
            'period'      => $period['name'],
            'allowClosed' => $this->allowsClosedPeriods(),
            'periods'     => array_map(static fn ($p) => ['name' => $p['name'], 'min' => $p['starts_on'], 'max' => $p['ends_on'], 'closed' => $p['status'] === 'closed'], $periods),
            'types'       => self::TYPES,
            // When the editor asks for a supporting document before submitting (documentRule()).
            'documentRule' => ['threshold' => config(Documents::class)->journalThreshold, 'types' => config(Documents::class)->journalTypes],
            'docRefs'     => array_combine(self::TYPES, array_map(fn ($t) => $this->autoDocRef($t), self::TYPES)),
            'documents'   => $this->documentRegister(),
            'accounts'    => array_values(array_map(static fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], $accounts)),
            'funds'       => array_values(array_intersect_key(self::FUND_GROUPS, array_flip($activeGroups))),
            'programmes'  => array_values(array_map(static fn ($p) => $p['name'], array_filter($this->lookups->programmes(), static fn ($p) => $p['status'] !== 'inactive'))),
            'grants'      => $grants,
            'awardFunds'  => $this->awardFunds(),
            'preparer'    => $actor['short'],
            'role'        => $actor['role'],
            'canPrepare'  => $actor['canPrepare'],
            'canApprove'  => $actor['canApprove'],
        ];
    }

    /** One of a journal's attachments, for download, or null. */
    public function attachment(string $ref, int $attachmentId): ?array
    {
        $row = $this->row(
            "SELECT a.* FROM {attachments} a JOIN {journals} j ON j.id = a.object_id AND a.object_type = 'journal' WHERE j.reference = ? AND a.id = ?",
            [$ref, $attachmentId]
        );

        return $row;
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
        (new ApprovalPolicy())->check(self::approvalType($journal['source_type']), $this->approvalAmount($journal), $actorId, $ref);
        if ($journal['reverses_journal_id'] !== null) {
            $original = $this->row('SELECT reference, status FROM {journals} WHERE id = ?', [$journal['reverses_journal_id']]);
            if ($original['status'] !== 'posted') {
                throw new RuleViolation($original['reference'] . ' has already been reversed, so ' . $ref . ' cannot post.');
            }
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

            // An entry raised from a bank statement line clears that line once it posts.
            if ($journal['source_type'] === 'bank_statement_line') {
                (new BankRepository($this->db))->matchRaised((int) $journal['id'], $actorId);
            }
        });

        return $this->find($ref);
    }

    /**
     * Posts an entry a sub-ledger raises from one of its records: a supplier bill
     * approved, a payment run released, withholding tax remitted. The record carries
     * the authorisation, so the entry does not wait in the approval queue. `approvedBy`
     * is the person who approved the record, or null where releasing the record was
     * itself the authorised act. Call it inside the caller's transaction; the database
     * still refuses a closed period, an unbalanced entry or an overdrawn restricted fund.
     *
     * @param array{date: string, narration: string, memo: string, sourceType: string, sourceId: int, docRef: string, series: string} $h
     * @param list<array{code: string, fund_id: int, programme_id: int, grant_id: int|null, desc: string, dr: float, cr: float}> $lines
     */
    public function postFromSource(array $h, array $lines, int $preparedBy, ?int $approvedBy, string $raisedNote): string
    {
        return $this->transaction(function () use ($h, $lines, $preparedBy, $approvedBy, $raisedNote) {
            [$id, $ref] = $this->raiseFromSource($h, $lines, $preparedBy, $raisedNote);
            $now = Clock::timestamp();
            $this->db->table('journals')->where('id', $id)->update([
                'status' => 'posted', 'approved_by' => $approvedBy, 'approved_at' => $approvedBy === null ? null : $now, 'posted_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit('journal', $id, $ref, 'Posted to the ledger' . ($approvedBy === null ? '' : ' on approval by ' . $this->lookups->shortName($approvedBy)), $approvedBy ?? $preparedBy);

            return $ref;
        });
    }

    /**
     * Raises an entry from a record that the journal's own approval authorises: an
     * inter-fund transfer, say. It waits in the register for approval like any
     * entry, under the approval rule for its document type (see approve()), and
     * cannot be edited there — its lines are the record's. Returns [id, reference].
     *
     * @param array{date: string, narration: string, memo: string, sourceType: string, sourceId: int, docRef: string, series: string, type?: string} $h
     * @param list<array{code: string, fund_id: int, programme_id: int, grant_id: int|null, desc: string, dr: float, cr: float}> $lines
     * @return array{0: int, 1: string}
     */
    public function submitFromSource(array $h, array $lines, int $preparedBy, string $raisedNote): array
    {
        return $this->transaction(function () use ($h, $lines, $preparedBy, $raisedNote) {
            [$id, $ref] = $this->raiseFromSource($h, $lines, $preparedBy, $raisedNote);
            $this->db->table('journals')->where('id', $id)->update(['status' => 'pending_approval', 'submitted_at' => Clock::timestamp()]);
            $approver = (new ApprovalPolicy())->rule(self::approvalType($h['sourceType']))['approver'] ?? $this->approverRole();
            $this->audit('journal', $id, $ref, 'Submitted for approval to ' . $this->lookups->shortName($this->lookups->holderOf($approver), 'the approver'), $preparedBy);

            return [$id, $ref];
        });
    }

    /** The approval rule an entry is held to: its source record's, where that has one of its own. */
    private static function approvalType(?string $sourceType): string
    {
        return $sourceType === 'fund_transfer' ? 'transfer' : 'journal';
    }

    /**
     * What an approver signs off. A transfer is the amount moved — its journal debits
     * that twice, once in each fund's balance and once in the cash it is held in.
     */
    private function approvalAmount(array $journal): float
    {
        if ($journal['source_type'] === 'fund_transfer') {
            return (float) $this->value('SELECT amount FROM {fund_transfers} WHERE id = ?', [$journal['source_id']]);
        }

        return (float) $this->value('SELECT COALESCE(SUM(debit), 0) FROM {journal_lines} WHERE journal_id = ?', [$journal['id']]);
    }

    /**
     * Inserts an entry raised from a sub-ledger record as a draft with its lines;
     * the caller moves it on. Call it inside a transaction.
     *
     * @return array{0: int, 1: string} id, reference
     */
    private function raiseFromSource(array $h, array $lines, int $preparedBy, string $raisedNote): array
    {
        $period = null;
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $h['date'] && $h['date'] <= $p['ends_on']) {
                $period = $p;
                break;
            }
        }
        if ($period === null) {
            throw new RuleViolation('No accounting period covers ' . self::dmy($h['date']) . ', so ' . $h['docRef'] . ' cannot post.');
        }
        if ($period['status'] === 'closed') {
            throw new RuleViolation($period['name'] . ' is closed to further posting, so ' . $h['docRef'] . ' cannot post. Reopen the month in Period close first.');
        }

        $accounts = $this->lookups->accounts();

        return $this->transaction(function () use ($h, $lines, $period, $accounts, $preparedBy, $raisedNote) {
            $now = Clock::timestamp();
            $ref = $this->nextReference('JV', $h['date']);
            $id  = $this->insert('journals', [
                'entity_id' => $this->lookups->entityId(), 'period_id' => $period['id'], 'reference' => $ref, 'journal_date' => $h['date'],
                'type' => $h['type'] ?? 'standard', 'status' => 'draft',
                'document_type_id' => $this->value('SELECT id FROM {document_types} WHERE prefix = ?', [$h['series']])
                    ?? $this->value('SELECT id FROM {document_types} WHERE prefix = ?', ['JV']),
                'document_ref' => $h['docRef'], 'source_type' => $h['sourceType'], 'source_id' => $h['sourceId'],
                'memo' => $h['memo'] !== '' ? mb_substr($h['memo'], 0, 255) : null, 'narration' => $h['narration'],
                'prepared_by' => $preparedBy, 'created_at' => $now,
            ]);

            $n = 0;
            foreach ($lines as $l) {
                if (round((float) $l['dr'], 2) == 0 && round((float) $l['cr'], 2) == 0) {
                    continue;
                }
                $account = $accounts[$l['code']] ?? throw new RuleViolation('Account ' . $l['code'] . ' is not in the chart of accounts.');
                $this->insert('journal_lines', [
                    'journal_id' => $id, 'line_no' => ++$n, 'account_id' => $account['id'], 'fund_id' => $l['fund_id'],
                    'programme_id' => $l['programme_id'], 'grant_id' => $l['grant_id'],
                    'description' => mb_substr($l['desc'], 0, 255), 'debit' => round((float) $l['dr'], 2), 'credit' => round((float) $l['cr'], 2),
                ]);
            }

            $this->audit('journal', $id, $ref, $raisedNote, $preparedBy);

            return [$id, $ref];
        });
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
     * Posted movements on one account, oldest first, for the general ledger: every
     * line with its segments, source document, preparer and approver. `opening` marks
     * the year's balances brought forward.
     *
     * @return list<array{date: string, ref: string, narration: string, source: string, fund: string, program: string, grant: string, grantRef: string, grantUnassigned: bool, funder: string, preparer: string, approver: string, doc: string, archived: bool, opening: bool, contra: string, debit: float, credit: float}>
     */
    public function postings(string $code): array
    {
        return $this->cached("postings:{$code}", function () use ($code) {
            $rows = $this->rows(
                "SELECT l.id, l.journal_id, l.debit, l.credit, l.description, l.programme_id, f.ledger_group, f.deed_ref, f.funder_id,
                        g.short_name AS grant_short, g.award_ref, gf.name AS grant_funder, j.reference, j.journal_date, j.narration,
                        j.source_type, j.document_ref, j.prepared_by, j.approved_by, d.name AS source
                 FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
                 JOIN {funds} f ON f.id = l.fund_id LEFT JOIN {grants} g ON g.id = l.grant_id LEFT JOIN {funders} gf ON gf.id = g.funder_id
                 LEFT JOIN {document_types} d ON d.id = j.document_type_id
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
            $funders = $this->lookups->funders();

            return array_map(function ($r) use ($contras, $funders) {
                // A line with no award in a fund that awards are held in cannot be claimed on a donor report.
                $awardFund = in_array($r['ledger_group'], ['grant', 'capital'], true);
                $grant = $r['grant_short'] ?? $r['deed_ref'] ?? ($awardFund ? 'Unassigned' : 'Unrestricted');

                return [
                    'date'      => $r['journal_date'],
                    'ref'       => $r['reference'],
                    'narration' => $r['narration'] !== '' ? $r['narration'] : $r['description'],
                    'source'    => $r['source'] ?? 'Journal',
                    'fund'      => self::FUND_GROUPS[$r['ledger_group']],
                    'program'   => $this->lookups->programmeName((int) $r['programme_id']),
                    'grant'     => $grant,
                    'grantRef'  => $r['award_ref'] ?? '',
                    'grantUnassigned' => $grant === 'Unassigned',
                    'funder'    => $r['grant_funder'] ?? ($r['funder_id'] !== null ? ($funders[(int) $r['funder_id']]['name'] ?? '') : '') ?: 'Not attributed',
                    'preparer'  => $this->lookups->shortName((int) $r['prepared_by']),
                    'approver'  => $r['approved_by'] === null ? '' : $this->lookups->shortName((int) $r['approved_by']),
                    'doc'       => $r['document_ref'] ?? '',
                    'archived'  => $r['source_type'] === 'archive',
                    'opening'   => $r['source_type'] === 'fiscal_year',
                    'contra'    => $contras[$r['journal_id']][$r['debit'] > 0 ? 'cr' : 'dr'] ?? '',
                    'debit'     => (float) $r['debit'],
                    'credit'    => (float) $r['credit'],
                ];
            }, $rows);
        });
    }

    /** The lines of one posted entry, including archived detail, for the general ledger's entry drawer. */
    public function entryLines(string $ref): array
    {
        return array_map(static fn ($l) => ['code' => $l['code'], 'name' => $l['name'], 'debit' => (float) $l['debit'], 'credit' => (float) $l['credit']], $this->rows(
            "SELECT a.code, a.name, l.debit, l.credit FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {accounts} a ON a.id = l.account_id
             WHERE j.reference = ? AND j.status IN ('posted', 'reversed') ORDER BY l.line_no",
            [$ref]
        ));
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
            "SELECT r.name FROM {approval_rules} ar JOIN {roles} r ON r.id = ar.approver_role_id
             WHERE ar.document_type = 'journal' AND ar.entity_id IN (?, ?) ORDER BY ar.entity_id = ? DESC LIMIT 1",
            [$this->lookups->entityId(), $this->lookups->headOfficeId(), $this->lookups->entityId()]
        ) ?? 'Finance Manager');
    }

    /** JV-26-0323: one more than the highest issued for the prefix and year, never reused. */
    private function nextReference(string $prefix, string $date): string
    {
        $stem = $prefix . '-' . substr($date, 2, 2) . '-';
        $max  = 0;
        foreach ($this->rows('SELECT reference FROM {all:journals} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Whether the closed-period control lets an entry be dated in a closed month. */
    private function allowsClosedPeriods(): bool
    {
        return $this->value(
            "SELECT s.value FROM {settings} s JOIN {entities} e ON e.id = s.entity_id WHERE e.code = ? AND s.key = 'closedPeriods'",
            [$this->lookups->headOfficeCode()]
        ) === '1';
    }

    /** @return list<string> ledger funds that awards are held in, other than the General Fund */
    private function awardFunds(): array
    {
        $funds = [];
        foreach ($this->lookups->grants() as $id => $g) {
            if (in_array($g['status'], ['active', 'closing'], true)) {
                $funds = array_merge($funds, $this->lookups->grantLedgerFunds((int) $id));
            }
        }

        return array_values(array_intersect(self::FUND_GROUPS, array_diff($funds, ['General Fund'])));
    }

    /**
     * Why these lines break the award rules, or null when they do not.
     *
     * Public so that lines built outside the journal screen — a legacy system's
     * opening balances — are held to exactly the same rules, in the same words,
     * before anything is written.
     *
     * @param list<array{code: string, fund: string, program: string, grantRef: string}> $lines
     */
    public function awardProblem(array $lines, bool $submitting): ?string
    {
        try {
            $this->checkAwards($lines, $submitting);
        } catch (RuleViolation $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * A named award must cover the line's fund and programme. On submission, a line
     * in a fund that awards are held in must name its award: restricted funds report
     * by award, and an uncoded cost cannot be claimed on a donor report.
     */
    private function checkAwards(array $lines, bool $submitting): void
    {
        $gaps = 0;
        foreach ($lines as $l) {
            if ($l['grantRef'] === '') {
                $gaps += in_array($l['fund'], $this->awardFunds(), true) ? 1 : 0;
                continue;
            }

            $grantId = $this->lookups->grantId($l['grantRef']);
            if ($grantId === null) {
                throw new RuleViolation('Selected grant ' . $l['grantRef'] . ' does not exist.');
            }
            $label = $this->lookups->grants()[$grantId]['short_name'];
            $funds = $this->lookups->grantLedgerFunds($grantId);
            if (!in_array($l['fund'], $funds, true)) {
                throw new RuleViolation($label . ' can only be charged to ' . implode(' or ', $funds) . ' — the ' . $l['code'] . ' line is coded to ' . $l['fund'] . '.');
            }
            if (!in_array($this->lookups->programmeId($l['program']), $this->lookups->grantProgrammes($grantId), true)) {
                throw new RuleViolation($label . ' does not fund ' . $l['program'] . ' — change the programme or the grant on the ' . $l['code'] . ' line.');
            }
        }

        if ($submitting && $gaps > 0) {
            throw new RuleViolation(($gaps === 1 ? 'One line charges a restricted fund with no grant against it.' : $gaps . ' lines charge a restricted fund with no grant against them.')
                . ' Name the grant before submitting.');
        }
    }

    /** ELOG/JV/0282: the next number in the journal type's manual series. */
    private function autoDocRef(string $type): string
    {
        $prefix = self::DOC_SERIES[$type] ?? 'JV';
        $max = self::DOC_SERIES_FLOOR;
        foreach ($this->rows('SELECT document_ref FROM {all:journals} WHERE document_ref LIKE ?', ['%/' . $prefix . '/%']) as $r) {
            if (preg_match('#/' . $prefix . '/(\d+)$#', (string) $r['document_ref'], $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'ELOG/' . $prefix . '/' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Records a journal can be raised from: open supplier bills, and the payroll of
     * the last three months.
     *
     * @return list<array{value: string, label: string, ref: string, note: string}>
     */
    private function documentRegister(): array
    {
        $bills = array_map(static fn ($b) => [
            'value' => 'bill:' . $b['reference'], 'label' => $b['reference'] . ' · ' . $b['supplier'], 'ref' => $b['reference'],
            'note'  => 'Supplier bill · ' . $b['category'] . ' · due ' . self::dmy($b['due_date']),
        ], $this->rows(
            "SELECT b.reference, b.due_date, s.name AS supplier, s.category FROM {bills} b JOIN {suppliers} s ON s.id = b.supplier_id
             WHERE b.status NOT IN ('paid', 'rejected') ORDER BY b.invoice_date, b.reference LIMIT 10"
        ));

        $today = Clock::date();
        $past = array_filter($this->lookups->periods(), static fn ($p) => $p['starts_on'] <= $today);
        $payroll = array_map(fn ($p) => [
            'value' => 'payroll:' . $p['code'], 'label' => 'Payroll run · ' . $p['name'], 'ref' => $this->payrollRef($p),
            'note'  => 'Payroll register and statutory schedule for ' . $p['name'],
        ], array_reverse(array_slice(array_values($past), -3)));

        return array_merge($bills, $payroll);
    }

    private function payrollRef(array $period): string
    {
        return 'ELOG/PR/' . str_replace(' ', '', $period['name']);
    }

    /**
     * A form's document link as the record it names.
     *
     * @return array{type: string, id: int, ref: string, prefix: string}|null null for a manual, auto-numbered entry
     */
    private function source(string $link): ?array
    {
        if ($link === 'auto' || $link === '') {
            return null;
        }

        [$kind, $key] = explode(':', $link, 2) + [1 => ''];
        if ($kind === 'bill') {
            $id = $this->value('SELECT id FROM {bills} WHERE reference = ?', [$key]);
            if ($id !== null) {
                return ['type' => 'bill', 'id' => (int) $id, 'ref' => $key, 'prefix' => 'JV'];
            }
        } elseif ($kind === 'recurring') {
            $template = $this->row('SELECT id, type, document_ref FROM {recurring_templates} WHERE code = ?', [$key]);
            if ($template !== null) {
                return ['type' => 'recurring_template', 'id' => (int) $template['id'], 'ref' => $template['document_ref'], 'prefix' => self::DOC_SERIES[self::label($template['type'])] ?? 'JV'];
            }
        } elseif ($kind === 'payroll') {
            foreach ($this->lookups->periods() as $p) {
                if ($p['code'] === $key) {
                    return ['type' => 'payroll', 'id' => (int) $p['id'], 'ref' => $this->payrollRef($p), 'prefix' => 'PR'];
                }
            }
        } elseif ($kind === 'bankline') {
            $line = $this->row(
                'SELECT l.id, l.reference FROM {bank_statement_lines} l JOIN {bank_statements} s ON s.id = l.bank_statement_id
                 JOIN {bank_accounts} b ON b.id = s.bank_account_id WHERE l.id = ?',
                [(int) $key]
            );
            if ($line !== null) {
                return ['type' => 'bank_statement_line', 'id' => (int) $line['id'], 'ref' => $line['reference'] ?? ('Statement line ' . $line['id']), 'prefix' => 'BK'];
            }
        }

        throw new RuleViolation('The source document ' . $link . ' is not a record a journal can be raised from.');
    }

    /** The form's value for a journal's source record. */
    private function sourceLink(string $type, int $id): string
    {
        if ($type === 'bill') {
            return 'bill:' . $this->value('SELECT reference FROM {bills} WHERE id = ?', [$id]);
        }
        if ($type === 'recurring_template') {
            return 'recurring:' . $this->value('SELECT code FROM {recurring_templates} WHERE id = ?', [$id]);
        }
        if ($type === 'bank_statement_line') {
            return 'bankline:' . $id;
        }
        foreach ($this->lookups->periods() as $p) {
            if ($type === 'payroll' && (int) $p['id'] === $id) {
                return 'payroll:' . $p['code'];
            }
        }

        return 'module:' . $type;
    }

    /** @return array<int, list<array{id: int, name: string, size: string}>> attachments by journal id */
    private function attachments(): array
    {
        $out = [];
        foreach ($this->rows("SELECT id, object_id, filename, size_bytes FROM {attachments} WHERE object_type = 'journal' ORDER BY id") as $a) {
            $size = (int) $a['size_bytes'];
            $out[(int) $a['object_id']][] = [
                'id'   => (int) $a['id'],
                'name' => $a['filename'],
                'size' => $size < 1024000 ? max(1, (int) round($size / 1024)) . ' KB' : number_format($size / 1048576, 1) . ' MB',
            ];
        }

        return $out;
    }

    /**
     * Moves an uploaded file into storage and records it against the journal.
     *
     * @param array{path: string, name: string, size: int, mime: string} $file
     * @return string the stored path, so a failed save can remove it
     */
    private function storeAttachment(int $journalId, array $file, int $actorId): string
    {
        return (new AttachmentRepository($this->db))->store('journal', $journalId, $file, $actorId, self::ATTACHMENT_DIR)[1];
    }

    /**
     * Why a manual entry needs a supporting document before it goes for approval,
     * or null: its debits pass Config\Documents::$journalThreshold, or its type is
     * one of $journalTypes. An entry raised from a bill, a payroll run, a bank line
     * or a recurring template carries that record as its evidence, and a reversal
     * the entry it reverses.
     */
    public static function documentRule(string $type, float $debits, string $docLink = 'auto'): ?string
    {
        if ($docLink !== '' && $docLink !== 'auto' && !str_starts_with($docLink, 'existing:')) {
            return null;
        }
        $config = config(Documents::class);

        return match (true) {
            in_array($type, $config->journalTypes, true) => 'An ' . strtolower($type) . ' journal goes for approval only with the document that supports it — a board minute, a reconciliation or the auditor\'s note.',
            $config->journalThreshold > 0 && $debits > $config->journalThreshold => 'This entry is ' . Prototype::fmt($debits) . ', above the ' . Prototype::fmt($config->journalThreshold)
                . ' at which a journal needs its supporting document. Attach the invoice, board minute or funder letter before submitting — a draft can be saved without it.',
            default => null,
        };
    }

    /** Refuses to submit a manual entry that needs a document and has none. */
    private function assertSupported(array $j, string $docLink, int $documents): void
    {
        if (($j['status'] ?? 'Draft') !== 'Pending approval' || $documents > 0) {
            return;
        }
        $debits = array_sum(array_map(static fn ($l) => (float) ($l['dr'] ?? 0), $j['lines'] ?? []));
        $why = self::documentRule((string) ($j['type'] ?? 'Standard'), round($debits, 2), $docLink);
        if ($why !== null) {
            throw new RuleViolation($why);
        }
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
