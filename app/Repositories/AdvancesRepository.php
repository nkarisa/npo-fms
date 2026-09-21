<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use Config\Documents;

/**
 * Staff and observer advances.
 *
 * Money handed to a person before it becomes expenditure. Issuing puts it on
 * 1220 as a receivable from the holder — not expenditure — and only a surrender
 * against receipts moves it onto the programme lines. What cannot be accounted
 * for is recovered from pay, which is why the ageing here, and not the bank
 * balance, is the measure of field discipline.
 *
 * What is accounted for is the sum of the receipts surrendered; what is
 * recovered is the sum of payroll deductions and cash paid back. The control
 * balance is the posted balance on 1220.
 */
final class AdvancesRepository extends Repository
{
    public const CONTROL_ACCOUNT = '1220';

    /** Where the money leaves from, by the method it is paid out with. */
    public const PAYING_ACCOUNTS = ['mpesa' => '1130', 'bank' => '1110', 'cash' => '1140'];

    /** An overspend is owed back to the holder, and sits with the other payables. */
    private const PAYABLE = '2110';

    private const METHOD_LABELS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cash' => 'Cash'];

    /** The grace an advance policy allows before a balance is taken from pay. */
    public const RECOVERY_AFTER_DAYS = 14;

    /** How a chase escalates when an advance stays unsurrendered. */
    private const REMINDERS = [
        1 => ['the holder', 'First surrender reminder sent'],
        2 => ['the programme director', 'Second reminder — escalated to the programme director'],
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
            $surrenders = [];
            foreach ($this->rows('SELECT s.*, a.code, a.name FROM {advance_surrenders} s JOIN {accounts} a ON a.id = s.account_id ORDER BY s.advance_id, s.id') as $s) {
                $surrenders[(int) $s['advance_id']][] = ['code' => $s['code'], 'account' => $s['name'], 'desc' => $s['description'], 'amount' => self::num($s['amount'])];
            }
            $recovered = array_column($this->rows('SELECT advance_id, SUM(amount) AS total FROM {advance_recoveries} GROUP BY advance_id'), 'total', 'advance_id');

            $reminders = [];
            foreach ($this->rows('SELECT * FROM {advance_reminders} ORDER BY advance_id, level') as $r) {
                $reminders[(int) $r['advance_id']][] = ['level' => (int) $r['level'], 'on' => self::dmy($r['sent_on']), 'to' => $r['sent_to']];
            }
            $trails = $this->trails('advance');
            $documents = (new AttachmentRepository())->byObject('advance');

            return array_map(function ($a) use ($surrenders, $recovered, $reminders, $trails, $documents) {
                $id   = (int) $a['id'];
                $fund = $this->lookups->funds()[$a['fund_id']];

                return [
                    'id'        => $id,
                    'ref'       => $a['reference'],
                    'holder'    => $a['holder_name'],
                    'kind'      => ucfirst($a['holder_kind']),
                    'role'      => $a['holder_role'] ?? '',
                    'purpose'   => $a['purpose'],
                    'program'   => $this->lookups->programmeName((int) $a['programme_id']),
                    'fund'      => self::FUND_GROUPS[$fund['ledger_group']],
                    'amount'    => self::num($a['amount']),
                    'reqDate'   => self::dmy($a['requested_on']),
                    'dueDate'   => self::dmy($a['due_on']),
                    'dueOn'     => $a['due_on'],
                    'dueIn'     => Clock::daysUntil($a['due_on']),
                    'status'    => ucfirst($a['status']),
                    'method'    => self::METHOD_LABELS[$a['payment_method']] ?? '',
                    'issueDate' => self::dmy($a['issued_on'], ''),
                    'accounted' => self::num(array_sum(array_column($surrenders[$id] ?? [], 'amount'))),
                    'receipts'  => $surrenders[$id] ?? [],
                    'documents' => $documents[$id] ?? [],
                    'recovered' => self::num($recovered[$id] ?? 0),
                    'reminders' => $reminders[$id] ?? [],
                    'trail'     => $trails[$id] ?? [],
                    'grant'     => $a['grant_short'] ?? ($fund['restriction'] === 'unrestricted' ? 'Unrestricted — own income' : 'Unassigned'),
                    'funder'    => $a['funder'] ?? '—',
                    'journal'   => $a['issue_ref'],
                    'reason'    => $a['rejected_reason'],
                    // A holder who has left cannot be recovered from: their final pay is settled.
                    'left'      => $a['left_on'] !== null,
                    'staffId'   => $a['staff_id'] === null ? null : (int) $a['staff_id'],
                ];
            }, $this->rows(
                'SELECT a.*, g.short_name AS grant_short, fr.name AS funder, j.reference AS issue_ref, s.left_on
                 FROM {advances} a
                 LEFT JOIN {grants} g ON g.id = a.grant_id LEFT JOIN {funders} fr ON fr.id = g.funder_id
                 LEFT JOIN {journals} j ON j.id = a.issue_journal_id LEFT JOIN {staff} s ON s.id = a.staff_id
                 ORDER BY a.requested_on DESC, a.reference DESC'
            ));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $a) {
            if ($a['ref'] === $ref) {
                return $a;
            }
        }

        return null;
    }

    public function controlBalance(): float
    {
        return $this->lookups->balance(self::CONTROL_ACCOUNT);
    }

    /** Only an issued advance is outstanding; requested and cleared ones are not. */
    public static function outstanding(array $a): float
    {
        return $a['status'] === 'Issued' ? $a['amount'] - $a['accounted'] - $a['recovered'] : 0;
    }

    // ---- Raising and deciding ----

    /**
     * Raises a request. This is a claim on the programme budget, not a posting:
     * nothing reaches 1220 until the funds are issued.
     */
    public function create(array $in, int $actorId): array
    {
        $holder  = trim((string) ($in['holder'] ?? ''));
        $purpose = trim((string) ($in['purpose'] ?? ''));
        $amount  = round((float) ($in['amount'] ?? 0), 2);

        if ($holder === '') {
            throw new RuleViolation('Name the holder — an advance is a receivable from a person, not from a department.');
        }
        if ($purpose === '') {
            throw new RuleViolation('Say what the advance is for. The purpose is what the surrender is later checked against.');
        }
        if ($amount <= 0) {
            throw new RuleViolation('Enter the amount requested.');
        }

        $kind = ($in['kind'] ?? 'Staff') === 'Observer' ? 'observer' : 'staff';
        $due  = $this->asDate($in['dueDate'] ?? null, 'surrender due date');
        $today = Clock::date();
        if ($due < $today) {
            throw new RuleViolation('An advance cannot be due for surrender before it is requested.');
        }

        $award     = trim((string) ($in['grant'] ?? ''));
        $grantId   = $award === '' || $award === self::UNRESTRICTED ? null : $this->lookups->grantId($award)
            ?? throw new RuleViolation('"' . $award . '" is not an award on the register.');
        $programme = $this->lookups->programmeId((string) ($in['program'] ?? ''))
            ?? throw new RuleViolation('Choose the programme the advance is charged against.');

        return $this->transaction(function () use ($holder, $purpose, $amount, $kind, $due, $grantId, $programme, $in, $actorId) {
            $reference = $this->nextReference('ADV', Clock::date());
            $id = $this->insert('advances', [
                'entity_id' => $this->lookups->entityId(), 'reference' => $reference, 'holder_kind' => $kind,
                'staff_id' => $kind === 'staff' ? $this->staffIdNamed($holder) : null,
                'holder_name' => $holder, 'holder_role' => trim((string) ($in['role'] ?? '')) ?: ucfirst($kind),
                'purpose' => $purpose, 'programme_id' => $programme,
                'fund_id' => $this->lookups->resolveFund($grantId === null ? 'General Fund' : 'Grant Fund', $grantId, (string) $in['program']),
                'grant_id' => $grantId, 'amount' => $amount, 'requested_on' => Clock::date(), 'due_on' => $due,
                'status' => 'requested', 'requested_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('advance', $id, $reference, 'Requested by ' . $this->lookups->shortName($actorId)
                . ($holder === $this->lookups->shortName($actorId) ? '' : ' on behalf of ' . $holder), $actorId, 'created');

            return ['ref' => $reference, 'amount' => $amount, 'holder' => $holder];
        });
    }

    /**
     * Approval, by someone other than the requester and within their authority.
     * Above the finance manager's limit an advance goes to the Executive Director.
     */
    public function approve(string $ref, int $actorId, ?string $authorityRef = null): array
    {
        $advance = $this->requireAdvance($ref);
        if ($advance['status'] !== 'requested') {
            throw new RuleViolation('Only a requested advance can be approved. ' . $ref . ' is ' . $advance['status'] . '.');
        }
        if ((int) $advance['requested_by'] === $actorId) {
            throw new RuleViolation('The person who requested an advance cannot approve it. It needs a second approver.');
        }
        (new ApprovalPolicy())->check('advance', (float) $advance['amount'], $actorId, $ref, $authorityRef);

        $this->transaction(function () use ($advance, $ref, $actorId) {
            $this->db->table('advances')->where('id', $advance['id'])->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('advance', (int) $advance['id'], $ref, 'Approved by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return ['ref' => $ref, 'amount' => self::num($advance['amount'])];
    }

    /** Returns a request to the requester, with the reason on record. */
    public function reject(string $ref, string $reason, int $actorId): array
    {
        $advance = $this->requireAdvance($ref);
        if ($advance['status'] !== 'requested') {
            throw new RuleViolation('Only a requested advance can be rejected. ' . $ref . ' is ' . $advance['status'] . '.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuleViolation('Say why the request is refused — the requester has to know what to do instead.');
        }

        $this->transaction(function () use ($advance, $ref, $reason, $actorId) {
            $this->db->table('advances')->where('id', $advance['id'])->update([
                'status' => 'rejected', 'rejected_by' => $actorId, 'rejected_at' => Clock::timestamp(),
                'rejected_reason' => $reason, 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('advance', (int) $advance['id'], $ref, 'Rejected by ' . $this->lookups->shortName($actorId) . ' — ' . $reason, $actorId);
        });

        return ['ref' => $ref, 'reason' => $reason];
    }

    /**
     * Pays the advance out. This is the posting that makes it a receivable: 1220
     * is debited and the paying account credited. No expenditure is recognised.
     */
    public function issue(string $ref, string $method, int $actorId): array
    {
        $advance = $this->requireAdvance($ref);
        if ($advance['status'] !== 'approved') {
            throw new RuleViolation('Only an approved advance can be issued. ' . $ref . ' is ' . $advance['status'] . '.');
        }
        if ((int) $advance['approved_by'] === $actorId && (int) $advance['requested_by'] === $actorId) {
            throw new RuleViolation('One person cannot request, approve and pay out the same advance.');
        }
        $key = array_search($method, self::METHOD_LABELS, true)
            ?: throw new RuleViolation('Pay an advance out by ' . implode(', ', self::METHOD_LABELS) . '.');

        // Petty cash is in the chart but keeps no bank record, so the paying
        // account is resolved from the chart and the bank link left where there is one.
        $code    = self::PAYING_ACCOUNTS[$key];
        $account = $this->lookups->accounts()[$code]
            ?? throw new RuleViolation('There is no ' . $method . ' account to pay ' . $ref . ' from.');
        $bankId = isset($this->lookups->bankAccounts()[$code]) ? (int) $this->lookups->bankAccounts()[$code]['id'] : null;

        $amount = self::num($advance['amount']);
        $who    = $this->lookups->shortName($actorId);
        $line   = static fn (string $code, string $desc, float $dr, float $cr) => [
            'code' => $code, 'fund_id' => (int) $advance['fund_id'], 'programme_id' => (int) $advance['programme_id'],
            'grant_id' => $advance['grant_id'] === null ? null : (int) $advance['grant_id'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];

        $preparedBy = (int) ($advance['requested_by'] ?? $advance['approved_by']);

        return $this->transaction(function () use ($advance, $ref, $key, $method, $account, $bankId, $amount, $line, $who, $preparedBy, $actorId) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => Clock::date(), 'sourceType' => 'advance', 'sourceId' => (int) $advance['id'], 'docRef' => $ref, 'series' => 'PV',
                'narration' => 'Advance to ' . $advance['holder_name'] . ' — ' . $advance['purpose'],
                'memo' => 'Advance issued by ' . $method . '. A receivable from the holder, not expenditure.',
            ], [
                $line(self::CONTROL_ACCOUNT, 'Advance to ' . $advance['holder_name'], $amount, 0),
                $line($account['code'], $account['name'] . ' — ' . $ref, 0, $amount),
            ], $preparedBy, $preparedBy === (int) $advance['approved_by'] ? null : (int) $advance['approved_by'],
                'Raised by advances on issuing ' . $ref);

            $this->db->table('advances')->where('id', $advance['id'])->update([
                'status' => 'issued', 'payment_method' => $key, 'issued_on' => Clock::date(),
                'bank_account_id' => $bankId, 'issue_journal_id' => $this->journalId($journal),
                'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('advance', (int) $advance['id'], $ref, 'Issued via ' . $method . ' · ' . $journal . ' by ' . $who, $actorId);

            return ['ref' => $ref, 'journal' => $journal, 'amount' => $amount, 'method' => $method,
                'account' => $account['name'], 'holder' => $advance['holder_name']];
        });
    }

    // ---- Chasing, surrendering, recovering ----

    /** Chases an unsurrendered advance, escalating each time one goes unanswered. */
    public function remind(string $ref, int $actorId): array
    {
        $advance = $this->requireAdvance($ref);
        $current = $this->find($ref);
        if (self::outstanding($current) <= 0) {
            throw new RuleViolation('There is nothing outstanding on ' . $ref . ' to chase.');
        }

        $level = (int) $this->value('SELECT COALESCE(MAX(level), 0) FROM {advance_reminders} WHERE advance_id = ?', [$advance['id']]) + 1;
        [$to, $note] = self::REMINDERS[$level]
            ?? ['the Executive Director', 'Reminder ' . $level . ' — escalated to the Executive Director for payroll recovery'];

        $this->transaction(function () use ($advance, $ref, $level, $to, $note, $actorId) {
            $this->insert('advance_reminders', [
                'advance_id' => (int) $advance['id'], 'level' => $level, 'sent_on' => Clock::date(),
                'sent_to' => $level === 1 ? $advance['holder_name'] : $to, 'note' => $note,
                'sent_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('advance', (int) $advance['id'], $ref, $note, $actorId);
        });

        return ['ref' => $ref, 'level' => $level, 'to' => $level === 1 ? $advance['holder_name'] : $to, 'note' => $note];
    }

    /**
     * Surrenders an issued advance against receipts, and posts what they account
     * for out of 1220 and onto the programme lines.
     *
     * "refund" banks any unspent balance and closes the advance; "outstanding"
     * leaves the balance owed by the holder, still ageing. An overspend is owed
     * back to the holder and joins the payables.
     *
     * @param  list<array{code: string, desc: string, amount: float}> $receipts
     * @return array{balance: float, journal: string, accounted: float, cleared: bool}
     */
    public function surrender(string $ref, array $receipts, string $mode, int $actorId, mixed $documentIds = []): array
    {
        $advance = $this->requireAdvance($ref);
        $current = $this->find($ref);
        if ($advance['status'] !== 'issued') {
            throw new RuleViolation('Only an issued advance can be surrendered. ' . $ref . ' is ' . $advance['status'] . '.');
        }
        if ($receipts === []) {
            throw new RuleViolation('Code at least one receipt line before surrendering.');
        }
        foreach ($receipts as $r) {
            if (!isset($this->lookups->accounts()[$r['code']])) {
                throw new RuleViolation('Account ' . $r['code'] . ' is not in the chart of accounts.');
            }
            if (trim($r['desc']) === '') {
                throw new RuleViolation('Every receipt line needs a description — the audit file has to say what was bought.');
            }
        }
        $attachments = new AttachmentRepository();
        $documents = $attachments->pending($documentIds, $actorId);
        if ($documents === [] && config(Documents::class)->requireAdvanceReceipts) {
            throw new RuleViolation('Attach the receipts for what was spent — a scan or photo of each. The surrender codes them to the programme, and the audit file needs the receipts themselves.');
        }

        // What is still to be accounted for, so a second surrender picks up where
        // the first left off rather than starting from the whole advance again.
        $target    = self::outstanding($current);
        $accounted = round(array_sum(array_column($receipts, 'amount')), 2);
        $balance   = round($target - $accounted, 2);
        $partial   = $balance > 0 && $mode === 'outstanding';

        $line = static fn (string $code, string $desc, float $dr, float $cr) => [
            'code' => $code, 'fund_id' => (int) $advance['fund_id'], 'programme_id' => (int) $advance['programme_id'],
            'grant_id' => $advance['grant_id'] === null ? null : (int) $advance['grant_id'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
        $holder = $advance['holder_name'];

        $lines = array_map(static fn ($r) => $line($r['code'], $r['desc'], $r['amount'], 0), $receipts);
        if (!$partial && $balance > 0) {
            $lines[] = $line(self::PAYING_ACCOUNTS['bank'], 'Unspent advance refunded by ' . $holder, $balance, 0);
        }
        $lines[] = $line(self::CONTROL_ACCOUNT, 'Advance cleared — ' . $holder, 0, $partial ? $accounted : $target);
        if ($balance < 0) {
            $lines[] = $line(self::PAYABLE, 'Overspend owed to ' . $holder, 0, -$balance);
        }

        $note = match (true) {
            $partial       => 'Partly surrendered — ' . Prototype::fmt($balance) . ' still outstanding',
            $balance > 0   => 'Surrendered with receipts; unspent ' . Prototype::fmt($balance) . ' refunded to bank',
            $balance < 0   => 'Surrendered with receipts; overspend of ' . Prototype::fmt(-$balance) . ' reimbursed to the holder',
            default        => 'Surrendered in full with receipts',
        };

        return $this->transaction(function () use ($advance, $ref, $receipts, $lines, $note, $partial, $balance, $accounted, $actorId, $attachments, $documents) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => Clock::date(), 'sourceType' => 'advance', 'sourceId' => (int) $advance['id'], 'docRef' => $ref, 'series' => 'JV',
                'narration' => 'Surrender of ' . $ref . ' — ' . $advance['holder_name'],
                'memo' => $note,
            ], $lines, $actorId, null, 'Raised by advances on the surrender of ' . $ref);

            $now = Clock::timestamp();
            $id  = (int) $advance['id'];
            foreach ($receipts as $r) {
                $this->insert('advance_surrenders', [
                    'advance_id' => $id, 'account_id' => $this->lookups->accounts()[$r['code']]['id'],
                    'description' => $r['desc'], 'amount' => $r['amount'], 'surrendered_on' => Clock::date(),
                    'journal_id' => $this->journalId($journal), 'created_by' => $actorId, 'created_at' => $now,
                ]);
            }

            if (!$partial) {
                $this->db->table('advances')->where('id', $id)->update(['status' => 'surrendered', 'updated_at' => $now]);
                if ($balance > 0) {
                    $this->insert('advance_recoveries', [
                        'advance_id' => $id, 'method' => 'bank', 'amount' => $balance, 'recovered_on' => Clock::date(),
                        'status' => 'recovered', 'reference' => 'Unspent balance refunded at surrender',
                        'journal_id' => $this->journalId($journal), 'created_by' => $actorId, 'created_at' => $now,
                    ]);
                }
            }

            $attachments->claim($documents, 'advance', $id);
            $this->audit('advance', $id, $ref, $note . ' · ' . $journal . ' by ' . $this->lookups->shortName($actorId)
                . ($documents === [] ? '' : ' · ' . count($documents) . ' receipt document' . (count($documents) === 1 ? '' : 's') . ' attached'), $actorId);

            return ['balance' => $balance, 'journal' => $journal, 'accounted' => $accounted, 'cleared' => !$partial];
        });
    }

    /**
     * Converts an unsurrendered balance to a payroll recovery.
     *
     * Nothing posts here: the deduction is scheduled on the holder's pay, and
     * 1220 clears as each run takes it. A holder who has left cannot be recovered
     * from this way — their final pay is already settled.
     */
    public function recover(string $ref, int $actorId): array
    {
        $advance = $this->requireAdvance($ref);
        $current = $this->find($ref);
        $balance = self::outstanding($current);

        if ($balance <= 0) {
            throw new RuleViolation('There is nothing outstanding on ' . $ref . ' to recover.');
        }
        // Recovery from pay is a last resort: the holder gets the grace period the
        // advance policy allows before their salary is touched.
        if ($current['dueIn'] >= -self::RECOVERY_AFTER_DAYS) {
            throw new RuleViolation($ref . ' is not yet ' . self::RECOVERY_AFTER_DAYS . ' days past its surrender date. '
                . 'Chase the holder for receipts first.');
        }
        if ($advance['staff_id'] === null) {
            throw new RuleViolation($current['holder'] . ' is not on the payroll register, so this balance cannot be recovered from pay. '
                . 'It needs recovery from the holder directly, or a write-off decision.');
        }
        if ($current['left']) {
            throw new RuleViolation($current['holder'] . ' has left and final pay is already settled, so payroll recovery is no longer available. '
                . 'This balance needs a written-off decision from the Board finance committee, or recovery from the former employee directly.');
        }

        // Spread over enough runs that the deduction does not take the whole pay —
        // or over however many runs are left, when the year is nearly out.
        $wanted  = $balance > 60000 ? 6 : ($balance > 20000 ? 3 : 1);
        $periods = $this->recoveryPeriods($wanted);
        if ($periods === []) {
            throw new RuleViolation('There is no open payroll period to recover ' . $ref . ' over.');
        }
        $runs     = count($periods);
        $shortened = $runs < $wanted;
        $each    = round($balance / $runs, 2);
        $payroll = new PayrollRepository();

        return $this->transaction(function () use ($advance, $ref, $balance, $runs, $wanted, $shortened, $each, $periods, $payroll, $actorId) {
            $id  = (int) $advance['id'];
            $now = Clock::timestamp();
            $from = $periods[0];
            $last = $periods[count($periods) - 1];

            foreach ($periods as $i => $period) {
                $this->insert('advance_recoveries', [
                    'advance_id' => $id, 'method' => 'payroll',
                    // The last run takes the rounding, so the schedule comes to the balance.
                    'amount' => $i === count($periods) - 1 ? round($balance - $each * ($runs - 1), 2) : $each,
                    'recovered_on' => $period['ends_on'], 'status' => 'scheduled', 'period_id' => (int) $period['id'],
                    'reference' => 'Scheduled against the ' . $period['name'] . ' payroll run',
                    'created_by' => $actorId, 'created_at' => $now,
                ]);
            }

            $payroll->scheduleRecovery((int) $advance['staff_id'], $each, $from['starts_on'], $last['ends_on']);

            $this->db->table('advances')->where('id', $id)->update(['status' => 'recovered', 'updated_at' => $now]);
            $this->audit('advance', $id, $ref, 'Not surrendered — converted to payroll recovery of ' . Prototype::fmt($balance)
                . ' over ' . $runs . ($runs === 1 ? ' run' : ' runs') . ' from ' . $from['name'], $actorId);

            return ['ref' => $ref, 'amount' => $balance, 'runs' => $runs, 'from' => $from['name'], 'to' => $last['name'],
                'each' => $each, 'holder' => $advance['holder_name'], 'wanted' => $wanted, 'shortened' => $shortened];
        });
    }

    // ---- Options the screen offers ----

    /** The awards and programmes a request may be charged to. */
    public function formOptions(): array
    {
        $awards = [];
        foreach ($this->lookups->grants() as $id => $g) {
            if (!in_array($g['status'], ['active', 'closing'], true)) {
                continue;
            }
            $awards[] = [
                'ref' => $g['award_ref'], 'label' => $g['short_name'], 'funder' => $g['funder_name'], 'fund' => 'Grant Fund',
                'restricted' => true,
                'programmes' => array_map(fn ($p) => $this->lookups->programmeName($p), $this->lookups->grantProgrammes((int) $id)),
            ];
        }

        $programmes = array_values(array_map(
            static fn ($p) => $p['name'],
            array_filter($this->lookups->programmes(), static fn ($p) => $p['status'] !== 'inactive'),
        ));

        return [
            // Unrestricted money may be charged to any programme; an award fixes its own.
            'awards' => array_merge([['ref' => self::UNRESTRICTED, 'label' => self::UNRESTRICTED, 'funder' => 'ELOG own income',
                'fund' => 'General Fund', 'restricted' => false, 'programmes' => $programmes]], $awards),
            'programmes' => $programmes,
            'methods'    => array_values(self::METHOD_LABELS),
            // What the receipts on a surrender may be coded to.
            'codes' => array_values(array_map(
                static fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']],
                array_filter($this->lookups->accounts(), static fn ($a) => (int) $a['is_leaf'] === 1 && $a['status'] === 'active'
                    && str_starts_with($a['code'], '5')),
            )),
        ];
    }

    public const UNRESTRICTED = 'Unrestricted — own income';

    // ---- Internals ----

    /** The runs a recovery is spread over: the open periods from the current one. */
    private function recoveryPeriods(int $runs): array
    {
        $open = array_values(array_filter(
            $this->lookups->periods(),
            static fn ($p) => $p['status'] !== 'closed' && $p['ends_on'] >= Clock::date(),
        ));

        return array_slice($open, 0, $runs);
    }

    private function requireAdvance(string $ref): array
    {
        return $this->row('SELECT * FROM {advances} WHERE reference = ?', [$ref])
            ?? throw new RuleViolation($ref . ' was not found in the advances register.');
    }

    /** The payroll record for a holder named on the register, when there is one. */
    private function staffIdNamed(string $name): ?int
    {
        $id = $this->value('SELECT id FROM {staff} WHERE name = ? AND left_on IS NULL', [$name]);

        return $id === null ? null : (int) $id;
    }

    private function journalId(string $ref): int
    {
        return (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$ref]);
    }

    private function nextReference(string $prefix, string $date): string
    {
        $stem = $prefix . '-' . substr($date, 2, 2) . '-';
        $max  = 0;
        foreach ($this->rows('SELECT reference FROM {advances} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function asDate(mixed $value, string $what): string
    {
        $date = is_string($value) ? substr(trim($value), 0, 10) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
            throw new RuleViolation('Give the ' . $what . ' as a date.');
        }

        return $date;
    }
}
