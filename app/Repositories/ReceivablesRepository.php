<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Donor claims and other invoices, from draft through receipt.
 *
 * A claim is built as a draft from expenditure already in the ledger; nothing
 * posts until a second person issues it to the donor. Issuing raises grants
 * receivable (1210) and recognises the income on each claim line. A receipt, in
 * full or in part, clears 1210 against the bank it lands in. Every step is on the
 * claim's audit trail.
 *
 * What may not come in is provided for before it is lost: an allowance for
 * doubtful debts (1215, against 5370 bad and doubtful debts) set on a claim by
 * hand, or by the ageing rates on claims no one has judged individually. Money
 * that does come in releases what the allowance no longer needs. A claim the
 * donor will not pay is written off against its allowance, and only what was not
 * provided for is charged to 5370 then. Money that still comes in after a
 * write-off reverses it and credits 5370 back.
 */
final class ReceivablesRepository extends Repository
{
    private const TYPE_LABELS = ['grant_claim' => 'Grant claim', 'cost_reimbursement' => 'Cost reimbursement', 'other_income' => 'Other income'];

    /** The currencies a new claim can be stated in, with their indicative rates (Settings → Currencies). */
    public static function currencies(): array
    {
        return (new SettingsRepository())->activeRates();
    }


    /** Ageing on the outstanding balance, by days past the due date. */
    public const BUCKETS = ['Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days'];

    private const BASIS_LABELS = ['specific' => 'Set on the claim', 'ageing' => 'By age'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function all(): array
    {
        return $this->cached('all', function () {
            $lines = [];
            foreach ($this->rows('SELECT l.*, a.code, a.name AS account_name FROM {invoice_lines} l JOIN {accounts} a ON a.id = l.account_id ORDER BY l.invoice_id, l.line_no') as $l) {
                $lines[(int) $l['invoice_id']][] = ['code' => $l['code'], 'name' => $l['account_name'], 'desc' => $l['description'], 'amount' => self::num($l['amount'])];
            }

            $receipts = [];
            foreach ($this->rows(
                'SELECT r.*, a.code AS account_code, j.reference AS journal_ref FROM {receipts} r JOIN {bank_accounts} b ON b.id = r.bank_account_id
                 JOIN {accounts} a ON a.id = b.account_id LEFT JOIN {journals} j ON j.id = r.journal_id
                 WHERE r.invoice_id IS NOT NULL ORDER BY r.received_on, r.id'
            ) as $r) {
                $receipts[(int) $r['invoice_id']][] = [
                    'when' => self::dm($r['received_on']), 'ref' => $r['reference'], 'amount' => self::num($r['amount']), 'note' => $r['note'] ?? '',
                    'account' => $r['account_code'], 'journal' => $r['journal_ref'], 'onISO' => $r['received_on'],
                ];
            }

            $trails = $this->trails('invoice');

            // An allowance set by hand stays until someone changes it by hand; the
            // ageing rates only move allowances they set.
            $allowances = [];
            foreach ($this->rows('SELECT invoice_id, basis, amount FROM {receivable_allowances} ORDER BY id') as $a) {
                $k = (int) $a['invoice_id'];
                $allowances[$k]['amount'] = ($allowances[$k]['amount'] ?? 0) + (float) $a['amount'];
                if (isset(self::BASIS_LABELS[$a['basis']])) {
                    $allowances[$k]['basis'] = self::BASIS_LABELS[$a['basis']];
                }
            }

            $documents = (new AttachmentRepository())->byObject('invoice');

            return array_map(function ($i) use ($lines, $receipts, $trails, $allowances, $documents) {
                $id = (int) $i['id'];
                $invoice = [
                    'no'         => $i['reference'],
                    'donor'      => $i['funder_name'] ?? $i['bill_to'],
                    'grantRef'   => $i['award_ref'] ?? $i['donor_reference'] ?? '—',
                    'type'       => self::TYPE_LABELS[$i['type']],
                    'program'    => $this->lookups->programmeName((int) $i['programme_id']),
                    'fund'       => self::FUND_GROUPS[$i['ledger_group']],
                    'issue'      => self::dmy($i['issue_date']),
                    'due'        => self::dmy($i['due_date']),
                    'dueISO'     => $i['due_date'],
                    'dueIn'      => Clock::daysUntil($i['due_date']),
                    'ccy'        => $i['currency'],
                    'amount'     => self::num($i['amount']),
                    'received'   => self::num(array_sum(array_column($receipts[$id] ?? [], 'amount'))),
                    'status'     => self::label($i['status']),
                    'basis'      => $i['basis'] ?? '',
                    'preparedBy' => (int) $i['prepared_by'],
                    'preparer'   => $this->lookups->shortName((int) $i['prepared_by']),
                    'journal'    => $i['journal_ref'],
                    'writeOffReason' => $i['written_off_reason'] ?? '',
                    'allowance'  => self::num($allowances[$id]['amount'] ?? 0),
                    'allowanceBasis' => $allowances[$id]['basis'] ?? '',
                    'lines'      => $lines[$id] ?? [],
                    'receipts'   => $receipts[$id] ?? [],
                    'trail'      => $trails[$id] ?? [],
                    'documents'  => $documents[$id] ?? [],
                ];

                return $i['currency'] === 'KES' ? $invoice : $invoice + ['fx' => (float) $i['fx_rate'], 'amountFc' => self::num($i['amount_fc'])];
            }, $this->rows(
                'SELECT i.*, fu.name AS funder_name, g.award_ref, f.ledger_group, j.reference AS journal_ref
                 FROM {invoices} i LEFT JOIN {funders} fu ON fu.id = i.funder_id LEFT JOIN {grants} g ON g.id = i.grant_id
                 JOIN {funds} f ON f.id = i.fund_id LEFT JOIN {journals} j ON j.id = i.journal_id ORDER BY i.issue_date DESC, i.reference DESC'
            ));
        });
    }

    public function find(string $no): ?array
    {
        foreach ($this->all() as $i) {
            if ($i['no'] === $no) {
                return $i;
            }
        }

        return null;
    }

    /** What is still to come in — never negative, even if a donor overpays. */
    public static function outstanding(array $i): float
    {
        return max(0, $i['amount'] - $i['received']);
    }

    /** Written-off and fully received claims are closed. */
    public static function isOpen(array $i): bool
    {
        return !in_array($i['status'], ['Received', 'Written off'], true);
    }

    /** An issued claim with a balance still to come in can carry an allowance. */
    public static function canCarryAllowance(array $i): bool
    {
        return self::isOpen($i) && $i['status'] !== 'Draft';
    }

    /** The ageing bucket of an invoice's outstanding balance. */
    public static function bucket(array $i): string
    {
        if ($i['dueIn'] >= 0) {
            return 'Current';
        }
        $d = -$i['dueIn'];

        return $d <= 30 ? '1–30 days' : ($d <= 60 ? '31–60 days' : ($d <= 90 ? '61–90 days' : 'Over 90 days'));
    }

    /** @return array<string, float> ageing bucket → % of the outstanding balance provided for */
    public function rates(): array
    {
        return $this->cached('rates', fn () => array_map('floatval', array_column($this->rows('SELECT bucket, pct FROM {allowance_rates} ORDER BY sort_order'), 'pct', 'bucket')));
    }

    /** What the ageing rates would hold against a claim, before anyone's judgement on it. */
    public function ageingAllowance(array $i): float
    {
        return round(self::outstanding($i) * ($this->rates()[self::bucket($i)] ?? 0) / 100, 2);
    }

    /** A draft has not been issued to the donor yet, so it cannot be late. */
    public static function isLate(array $i): bool
    {
        return self::isOpen($i) && $i['status'] !== 'Draft' && $i['dueIn'] < 0;
    }

    /**
     * Awards a claim can be built against: active grants with their budget lines,
     * the expenditure on each, and what has already been claimed. The indirect cost
     * line is kept apart — recovery is a rate on the direct costs claimed.
     *
     * @return list<array>
     */
    public function awards(): array
    {
        return $this->cached('awards', function () {
            $claimed = [];
            foreach ($this->rows("SELECT grant_id, SUM(amount) AS amount FROM {invoices} WHERE grant_id IS NOT NULL AND status <> 'written_off' GROUP BY grant_id") as $r) {
                $claimed[(int) $r['grant_id']] = (float) $r['amount'];
            }

            $out = [];
            foreach ((new GrantRepository())->all() as $g) {
                if ($g['status'] !== 'Active') {
                    continue;
                }
                $id = (int) $this->lookups->grantId($g['ref']);
                $direct = $indirect = [];
                foreach ($g['budget'] as $b) {
                    if (preg_match('/indirect|support cost/i', $b['name']) === 1) {
                        $indirect[] = $b;
                    } else {
                        $direct[] = $b;
                    }
                }
                $recovery = $indirect[0] ?? null;
                $pct = $recovery !== null && preg_match('/(\d+(?:\.\d+)?)\s*%/', $recovery['name'], $m) === 1 ? (float) $m[1] : 0.0;
                $claimedToDate = $claimed[$id] ?? 0.0;

                $out[] = [
                    'ref' => $g['ref'], 'funder' => $g['funder'], 'title' => $g['title'], 'program' => $g['program'],
                    'value' => $g['value'], 'spent' => $g['spent'], 'claimed' => self::num($claimedToDate),
                    'left' => self::num($g['value'] - $claimedToDate), 'unclaimed' => self::num(max(0, $g['spent'] - $claimedToDate)),
                    'lines' => $direct,
                    'indirect' => $recovery === null || $pct <= 0 ? null : ['name' => $recovery['name'], 'pct' => $pct, 'cap' => $recovery['budget']],
                ];
            }

            return $out;
        });
    }

    /** Expenditure on active awards that has not yet been claimed from the donor. */
    public function unbilled(): float
    {
        return array_sum(array_column($this->awards(), 'unclaimed'));
    }

    /** @return list<array{code: string, name: string}> income accounts an invoice with no award behind it can be coded to */
    public function otherIncomeAccounts(): array
    {
        return array_values(array_map(
            static fn ($a) => ['code' => $a['code'], 'name' => $a['name']],
            array_filter($this->lookups->accounts(), static fn ($a) => $a['type'] === 'income' && (int) $a['is_leaf'] === 1
                && $a['status'] === 'active' && str_starts_with($a['code'], '42') && !in_array($a['code'], [PostingAccounts::of('fxGain'), PostingAccounts::of('disposalGain')], true))
        ));
    }

    /** @return list<array{code: string, label: string, currency: string}> accounts a receipt can land in */
    public function receiptAccounts(): array
    {
        return array_values(array_map(
            static fn ($b) => ['code' => $b['code'], 'label' => $b['name'], 'currency' => $b['currency']],
            array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['status'] === 'active' && $b['kind'] !== 'petty_cash')
        ));
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Builds a draft claim. Against an award, each budget line can claim no more
     * than has been spent on it, and the claim as a whole no more than the award's
     * unclaimed expenditure or its unclaimed value; indirect recovery is the
     * agreement's rate on the direct costs claimed, capped at its budget line. With
     * no award, the invoice states its payer, basis, income account and amount.
     *
     * @param array{award: string, type: string, period: string, ccy: string, fx: float|string, indirect: bool, basis: string,
     *              claims: array<string, float|string>, payer?: string, amount?: float|string, account?: string} $f
     */
    public function create(array $f, int $actorId): array
    {
        $num = static fn ($v) => round((float) preg_replace('/[^0-9.]/', '', (string) $v), 2);
        $award = trim((string) ($f['award'] ?? ''));
        $other = $award === '' || $award === '—';
        $period = trim((string) ($f['period'] ?? ''));
        $basis = trim((string) ($f['basis'] ?? ''));
        $ccy = (string) ($f['ccy'] ?? 'KES');
        $fx = $ccy === 'KES' ? 1.0 : (float) ($f['fx'] ?? 0);

        if (!array_key_exists($ccy, self::currencies())) {
            throw new RuleViolation($ccy . ' is not a currency claims can be stated in.');
        }
        if ($ccy !== 'KES' && !($fx > 0)) {
            throw new RuleViolation('Enter the exchange rate used for the claim.');
        }
        if ($period === '') {
            throw new RuleViolation('Name the period the claim covers, e.g. Jul – Sep 2026.');
        }

        $lines = [];
        $indirect = 0.0;
        if ($other) {
            $payer = trim((string) ($f['payer'] ?? ''));
            $amount = $num($f['amount'] ?? 0);
            $account = array_values(array_filter($this->otherIncomeAccounts(), static fn ($a) => $a['code'] === ($f['account'] ?? '')))[0] ?? null;
            $error = match (true) {
                $payer === ''     => 'Name who the invoice is addressed to.',
                $basis === ''     => 'Describe what is being invoiced — an invoice with no award behind it needs a basis on its face.',
                $account === null => 'Choose the income account the invoice is coded to.',
                $amount <= 0      => 'Enter the amount being invoiced.',
                default           => null,
            };
            if ($error !== null) {
                throw new RuleViolation($error);
            }
            $lines[] = ['code' => $account['code'], 'desc' => mb_substr($basis, 0, 255), 'amount' => $amount];
            $type = 'other_income';
            $grant = null;
        } else {
            $grant = array_values(array_filter($this->awards(), static fn ($a) => $a['ref'] === $award))[0] ?? null;
            if ($grant === null) {
                throw new RuleViolation($award . ' is not an active award a claim can be built against.');
            }
            $type = array_search($f['type'] ?? '', self::TYPE_LABELS, true);
            if (!in_array($type, ['grant_claim', 'cost_reimbursement'], true)) {
                throw new RuleViolation('A claim against an award is a grant claim or a cost reimbursement.');
            }
            $claims = (array) ($f['claims'] ?? []);
            foreach ($grant['lines'] as $b) {
                $claim = $num($claims[$b['code']] ?? 0);
                if ($claim <= 0) {
                    continue;
                }
                if ($claim > $b['actual']) {
                    throw new RuleViolation('The ' . $b['name'] . ' line claims ' . Prototype::fmt($claim) . ' against ' . Prototype::fmt($b['actual'])
                        . ' spent. A claim above actual expenditure is what triggers a donor disallowance.');
                }
                $lines[] = ['code' => PostingAccounts::of('grantIncome'), 'desc' => mb_substr($b['name'] . ' — ' . $period, 0, 255), 'amount' => $claim];
            }
            if ($lines === []) {
                throw new RuleViolation('Claim at least one line. The builder only lets you claim expenditure already in the ledger.');
            }
            $direct = array_sum(array_column($lines, 'amount'));
            if (!empty($f['indirect']) && $grant['indirect'] !== null) {
                $indirect = min(round($direct * $grant['indirect']['pct'] / 100), (float) $grant['indirect']['cap']);
                if ($indirect > 0) {
                    $lines[] = ['code' => PostingAccounts::of('grantIncome'), 'desc' => 'Indirect cost recovery at ' . self::num($grant['indirect']['pct']) . '%', 'amount' => $indirect];
                }
            }
            $total = $direct + $indirect;
            if ($total > $grant['unclaimed']) {
                throw new RuleViolation('Only ' . Prototype::fmt($grant['unclaimed']) . ' of expenditure is unclaimed on this award — ' . Prototype::fmt($grant['spent'])
                    . ' spent against ' . Prototype::fmt($grant['claimed']) . ' already claimed. Claiming ' . Prototype::fmt($total) . ' would invoice the same costs twice.');
            }
            if ($total > $grant['left']) {
                throw new RuleViolation('This claim of ' . Prototype::fmt($total) . ' takes total claims past the award value — only ' . Prototype::fmt($grant['left']) . ' is left unclaimed on ' . $award . '.');
            }
        }

        $total = array_sum(array_column($lines, 'amount'));
        $who = $this->lookups->shortName($actorId);
        // Recommended, not required: the claim is assembled from the ledger, but the
        // donor's request or the signed contract for other income belongs with it.
        $attachments = new AttachmentRepository();
        $documents = $attachments->pending($f['documents'] ?? [], $actorId);

        $no = $this->transaction(function () use ($other, $f, $grant, $type, $period, $basis, $ccy, $fx, $lines, $total, $actorId, $who, $attachments, $documents) {
            $now = Clock::timestamp();
            $today = Clock::date();
            $grantId = $grant === null ? null : $this->lookups->grantId($grant['ref']);
            $no = $this->nextReference($today);
            $id = $this->insert('invoices', [
                'entity_id' => $this->lookups->entityId(), 'reference' => $no, 'type' => $type,
                'funder_id' => $grantId === null ? null : (int) $this->lookups->grants()[$grantId]['funder_id'],
                'bill_to' => $other ? trim((string) $f['payer']) : null, 'grant_id' => $grantId,
                'programme_id' => $grantId === null ? $this->lookups->programmeId('Shared services') : $this->grantProgramme($grantId),
                'fund_id' => $grantId === null ? $this->generalFund() : $this->grantFund($grantId),
                'issue_date' => $today, 'due_date' => date('Y-m-d', strtotime($today . ' +' . SettingsRepository::day('claimTermsDays') . ' days')),
                'currency' => $ccy, 'fx_rate' => $fx, 'amount_fc' => round($total / $fx, 2), 'amount' => $total, 'status' => 'draft',
                'basis' => $basis !== '' ? $basis : self::TYPE_LABELS[$type] . ' for ' . $period . ', assembled from expenditure in the ledger.',
                'prepared_by' => $actorId, 'created_at' => $now,
            ]);

            $fcSoFar = 0.0;
            foreach ($lines as $i => $l) {
                $fc = $i === array_key_last($lines) ? round($total / $fx - $fcSoFar, 2) : round($l['amount'] / $fx, 2);
                $fcSoFar += $fc;
                $this->insert('invoice_lines', [
                    'invoice_id' => $id, 'line_no' => $i + 1, 'account_id' => $this->lookups->accounts()[$l['code']]['id'],
                    'description' => $l['desc'], 'amount_fc' => $fc, 'amount' => $l['amount'],
                ]);
            }
            $attachments->claim($documents, 'invoice', $id);
            $this->audit('invoice', $id, $no, 'Draft ' . ($other ? 'invoice raised' : 'claim built from the ' . $grant['ref'] . ' budget') . ' by ' . $who
                . ($documents === [] ? '' : ' · ' . count($documents) . ' document' . (count($documents) === 1 ? '' : 's') . ' attached'), $actorId, 'history', $this->lookups->entityId());

            return $no;
        });

        return $this->find($no);
    }

    /**
     * Issues draft claims to the donor: raises the receivable and recognises the
     * income. The claim is dated today and falls due on its terms. Whoever built a
     * claim cannot issue it.
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>}
     */
    public function issue(array $nos, int $actorId): array
    {
        $who = $this->lookups->shortName($actorId);
        [$invoices, $skipped] = $this->eligible($nos, 'Nothing in the selection is a draft you can issue.', static function ($i) use ($actorId, $who) {
            if ($i['status'] !== 'draft') {
                return $i['reference'] . ' is ' . strtolower(self::label($i['status'])) . ' — only drafts are issued.';
            }

            return (int) $i['prepared_by'] === $actorId ? $who . ' built ' . $i['reference'] . ' and cannot also issue it. It needs a second person.' : null;
        });

        $this->transaction(function () use ($invoices, $actorId, $who) {
            $journals = new JournalRepository();
            foreach ($invoices as $i) {
                $today = Clock::date();
                $term = max(0, (int) ((strtotime($i['due_date']) - strtotime($i['issue_date'])) / 86400)) ?: SettingsRepository::day('claimTermsDays');
                $lines = $this->rows('SELECT l.*, a.code, a.name FROM {invoice_lines} l JOIN {accounts} a ON a.id = l.account_id WHERE l.invoice_id = ? ORDER BY l.line_no', [$i['id']]);
                $posting = [$this->posting(PostingAccounts::of('receivables'), $i, 'Receivable from ' . $i['customer'] . ' · ' . $i['reference'], (float) $i['amount'], 0)];
                foreach ($lines as $l) {
                    $posting[] = $this->posting($l['code'], $i, $l['description'], 0, (float) $l['amount']);
                }

                $ref = $journals->postFromSource([
                    'date' => $today, 'sourceType' => 'invoice', 'sourceId' => (int) $i['id'], 'docRef' => $i['reference'], 'series' => 'JV',
                    'narration' => $i['customer'] . ' — ' . strtolower(self::label($i['type'], self::TYPE_LABELS)) . ', ' . $i['reference'],
                    'memo' => 'Claim issued to ' . $i['customer'] . ($i['award_ref'] ? ' under ' . $i['award_ref'] : '') . '.',
                ], $posting, (int) $i['prepared_by'], $actorId, 'Raised by Receivables from ' . $i['reference']);

                $this->db->table('invoices')->where('id', $i['id'])->update([
                    'status' => 'issued', 'approved_by' => $actorId, 'issue_date' => $today,
                    'due_date' => date('Y-m-d', strtotime($today . ' +' . $term . ' days')),
                    'journal_id' => $this->value('SELECT id FROM {journals} WHERE reference = ?', [$ref]), 'updated_at' => Clock::timestamp(),
                ]);
                $this->audit('invoice', (int) $i['id'], $i['reference'], 'Issued to donor by ' . $who . ' and posted as ' . $ref, $actorId, 'history', (int) $i['entity_id']);
            }
        });

        return ['done' => array_map(fn ($i) => $this->find($i['reference']), $invoices), 'skipped' => $skipped];
    }

    /**
     * Banks a receipt against an issued claim and moves it to part received or
     * received. The entry clears grants receivable against the account the money
     * landed in. Without a bank reference the receipt takes the next receipt number.
     */
    public function recordReceipt(string $no, float $amount, string $accountCode, string $bankRef, string $note, int $actorId): array
    {
        $invoice = $this->header($no);
        $current = $this->find($no);
        $bank = $this->lookups->bankAccounts()[$accountCode] ?? null;
        $amount = round($amount, 2);

        $error = match (true) {
            in_array($invoice['status'], ['draft', 'written_off'], true) => $no . ' is ' . strtolower(self::label($invoice['status'])) . ' — it cannot take a receipt' . ($invoice['status'] === 'draft' ? ' until it is issued to the donor.' : '; record the money as a recovery.'),
            $invoice['status'] === 'received' => $no . ' has already been received in full.',
            $amount <= 0 => 'Enter a receipt amount first.',
            $amount > self::outstanding($current) + 0.005 => 'Receipt of ' . Prototype::fmt($amount) . ' exceeds the ' . Prototype::fmt(self::outstanding($current)) . ' outstanding on ' . $no . '.',
            default => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }
        $bankRef = trim($bankRef);
        $this->checkReceiptBank($bank, $accountCode, $bankRef);

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($invoice, $current, $amount, $bank, $bankRef, $note, $actorId, $who) {
            $now = Clock::timestamp();
            $today = Clock::date();
            $full = $amount >= self::outstanding($current) - 0.005;
            [$journal, $ref] = $this->postReceipt($invoice, $current, $amount, $bank, $bankRef, $note !== '' ? $note : ($full ? 'Settled in full' : 'Part receipt recorded'), $actorId, $today);
            $this->db->table('invoices')->where('id', $invoice['id'])->update(['status' => $full ? 'received' : 'part_received', 'updated_at' => $now]);
            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                ($full ? 'Receipt in full' : 'Part receipt') . ' of ' . Prototype::fmt($amount) . ' posted to ' . $bank['code'] . ' as ' . $journal . ' by ' . $who, $actorId, 'history', (int) $invoice['entity_id']);

            // The allowance can never exceed what is still to come in.
            $excess = round($current['allowance'] - max(0, self::outstanding($current) - $amount), 2);
            if ($excess > 0) {
                $this->moveAllowance($invoice, $current, -$excess, 'receipt', 'Released on receipt ' . $ref, $actorId, $today);
            }
        });

        return $this->find($no);
    }

    /**
     * Records the outstanding balance of each open, issued claim as received in full,
     * into the shilling account (the foreign-currency account for a USD or EUR claim).
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>}
     */
    public function receiveInFull(array $nos, int $actorId): array
    {
        [$invoices, $skipped] = $this->eligible($nos, 'Nothing in the selection is an issued claim with a balance to receive.', static fn ($i) => in_array($i['status'], ['issued', 'part_received'], true)
            ? null
            : $i['reference'] . ' is ' . strtolower(self::label($i['status'])) . ' — only issued claims take a receipt.');

        $accounts = $this->receiptAccounts();
        $done = $this->transaction(function () use ($invoices, $accounts, $actorId) {
            $done = [];
            foreach ($invoices as $i) {
                $current = $this->find($i['reference']);
                $account = array_values(array_filter($accounts, static fn ($a) => $a['currency'] === $i['currency'] && str_starts_with($a['label'], 'Bank')))[0]
                    ?? array_values(array_filter($accounts, static fn ($a) => $a['currency'] === 'KES' && str_starts_with($a['label'], 'Bank')))[0];
                $done[] = $this->recordReceipt($i['reference'], self::outstanding($current), $account['code'], '', 'Received in full', $actorId);
            }

            return $done;
        });

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Notes a payment reminder on each open, issued claim. Delivery by email is not
     * built yet; the reminder is on the claim's audit trail.
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>}
     */
    public function remind(array $nos, int $actorId): array
    {
        [$invoices, $skipped] = $this->eligible($nos, 'Nothing in the selection is an issued claim awaiting payment.', static fn ($i) => in_array($i['status'], ['issued', 'part_received'], true)
            ? null
            : $i['reference'] . ' is ' . strtolower(self::label($i['status'])) . ' — reminders go only on issued claims.');

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($invoices, $actorId, $who) {
            foreach ($invoices as $i) {
                $sent = (int) $this->value("SELECT COUNT(*) FROM {audit_events} WHERE object_type = 'invoice' AND object_id = ? AND summary LIKE '%reminder sent%'", [$i['id']]);
                $ordinal = ['First', 'Second', 'Third'][$sent] ?? ('Reminder ' . ($sent + 1) . ' —');
                $this->audit('invoice', (int) $i['id'], $i['reference'], $ordinal . ' reminder sent to ' . $i['customer'] . ' by ' . $who . ' with the invoice and ledger extract', $actorId, 'history', (int) $i['entity_id']);
            }
        });

        return ['done' => array_map(fn ($i) => $this->find($i['reference']), $invoices), 'skipped' => $skipped];
    }

    /**
     * Writes off what is left on an overdue claim the donor will not pay: cleared
     * from grants receivable, met first from the allowance held against it, with
     * only the balance not provided for charged to bad and doubtful debts.
     */
    public function writeOff(string $no, string $reason, int $actorId): array
    {
        $invoice = $this->header($no);
        $current = $this->find($no);
        $reason = trim($reason);
        if (!self::isLate($current)) {
            throw new RuleViolation('Only an overdue claim can be written off. ' . $no . ' is ' . strtolower($current['status']) . ($current['status'] !== 'Draft' && self::isOpen($current) ? ' and not yet due.' : '.'));
        }
        if ($reason === '') {
            throw new RuleViolation('Say why the claim will not be paid — a write-off needs its reason on record.');
        }

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($invoice, $current, $reason, $actorId, $who) {
            [$journal, $charged, $used] = $this->postWriteOff($invoice, $current, $reason, $actorId, Clock::date(), '');

            $this->db->table('invoices')->where('id', $invoice['id'])->update([
                'status' => 'written_off', 'written_off_reason' => $reason, 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                'Written off by ' . $who . ' as ' . $journal . ' — ' . self::writeOffSplit($charged, $used) . ': ' . $reason, $actorId, 'history', (int) $invoice['entity_id']);
        });

        return $this->find($no);
    }

    /**
     * Banks money that comes in on a claim already written off. The write-off is
     * reversed for what came in — grants receivable reinstated against the
     * allowance (Dr 1210 / Cr 1215) — the receipt clears 1210 against the bank as
     * any receipt does, and the allowance, no longer needed, is released to bad and
     * doubtful debts (Dr 1215 / Cr 5370). The recovery reduces this period's charge.
     * Recovered in full, the claim is received; in part, the rest stays written off.
     */
    public function recordRecovery(string $no, float $amount, string $accountCode, string $bankRef, string $note, int $actorId): array
    {
        $invoice = $this->header($no);
        $current = $this->find($no);
        $bank = $this->lookups->bankAccounts()[$accountCode] ?? null;
        $amount = round($amount, 2);
        $writtenOff = self::outstanding($current);

        $error = match (true) {
            $invoice['status'] !== 'written_off' => $no . ' is ' . strtolower(self::label($invoice['status'])) . ' — only a written-off claim takes a recovery.',
            $amount <= 0 => 'Enter the amount recovered first.',
            $amount > $writtenOff + 0.005 => 'Recovery of ' . Prototype::fmt($amount) . ' exceeds the ' . Prototype::fmt($writtenOff) . ' written off on ' . $no . '.',
            default => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }
        $bankRef = trim($bankRef);
        $this->checkReceiptBank($bank, $accountCode, $bankRef);

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($invoice, $current, $amount, $writtenOff, $bank, $bankRef, $note, $actorId, $who) {
            $today = Clock::date();
            $full = $amount >= $writtenOff - 0.005;

            $reinstated = (new JournalRepository())->postFromSource([
                'date' => $today, 'sourceType' => 'invoice', 'sourceId' => (int) $invoice['id'], 'docRef' => $invoice['reference'], 'series' => 'JV',
                'narration' => 'Write-off of ' . $invoice['reference'] . ' reversed on recovery — ' . $current['donor'],
                'memo' => 'Money came in on a claim written off; the receivable is reinstated against the allowance.',
            ], [
                $this->posting(PostingAccounts::of('receivables'), $invoice, 'Receivable reinstated on recovery — ' . $invoice['reference'], $amount, 0),
                $this->posting(PostingAccounts::of('allowance'), $invoice, 'Allowance reinstated on recovery — ' . $invoice['reference'], 0, $amount),
            ], $actorId, null, 'Raised by Receivables on recovery of ' . $invoice['reference'] . ' by ' . $who);
            $this->insert('receivable_allowances', [
                'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'basis' => 'write_off', 'amount' => $amount,
                'reason' => 'Write-off reversed on recovery', 'journal_id' => $this->journalId($reinstated), 'created_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);

            [$journal, $ref] = $this->postReceipt($invoice, $current, $amount, $bank, $bankRef, $note !== '' ? $note : ($full ? 'Recovered in full after write-off' : 'Part recovery after write-off'), $actorId, $today);
            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                ($full ? 'Recovery in full' : 'Part recovery') . ' of ' . Prototype::fmt($amount) . ' after write-off posted to ' . $bank['code'] . ' as ' . $journal
                . ' by ' . $who . ' — write-off reversed as ' . $reinstated, $actorId, 'history', (int) $invoice['entity_id']);
            $this->moveAllowance($invoice, ['allowance' => $amount] + $current, -$amount, 'receipt', 'Released on recovery ' . $ref, $actorId, $today);

            if ($full) {
                $this->db->table('invoices')->where('id', $invoice['id'])->update(['status' => 'received', 'updated_at' => Clock::timestamp()]);
            }
        });

        return $this->find($no);
    }

    /**
     * Sets the allowance held against one claim, by judgement: anything from nothing
     * (the donor is expected to pay after all) to the whole outstanding balance.
     * The ageing rates leave it alone from then on.
     */
    public function setAllowance(string $no, float $amount, string $reason, int $actorId): array
    {
        $invoice = $this->header($no);
        $current = $this->find($no);
        $amount = round($amount, 2);
        $reason = trim($reason);

        $error = match (true) {
            !self::canCarryAllowance($current) => $no . ' is ' . strtolower($current['status']) . ' — only an issued claim with a balance still to come in carries an allowance.',
            $amount < 0 => 'An allowance cannot be negative.',
            $amount > self::outstanding($current) + 0.005 => 'An allowance of ' . Prototype::fmt($amount) . ' exceeds the ' . Prototype::fmt(self::outstanding($current)) . ' outstanding on ' . $no . '.',
            abs($amount - $current['allowance']) < 0.005 => $no . ' already carries an allowance of ' . Prototype::fmt($amount) . '.',
            $reason === '' => $amount < $current['allowance']
                ? 'Say why less of the claim is now in doubt — the reason goes on the claim\'s record.'
                : 'Say why the claim is in doubt — an allowance needs its reason on record.',
            default => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }

        $this->transaction(fn () => $this->moveAllowance($invoice, $current, $amount - $current['allowance'], 'specific', $reason, $actorId, Clock::date()));

        return $this->find($no);
    }

    /**
     * Brings the allowance on every issued claim no one has judged by hand into line
     * with the ageing rates, one entry per claim that moves.
     *
     * @return array{done: list<array>, raised: float, released: float}
     */
    public function applyAgeingRates(int $actorId): array
    {
        $changes = [];
        foreach ($this->all() as $i) {
            if (!self::canCarryAllowance($i) || $i['allowanceBasis'] === self::BASIS_LABELS['specific']) {
                continue;
            }
            $delta = round($this->ageingAllowance($i) - $i['allowance'], 2);
            if (abs($delta) >= 0.005) {
                $changes[] = [$i, $delta];
            }
        }
        if ($changes === []) {
            throw new RuleViolation('The allowance already matches the ageing rates — there is nothing to post.');
        }

        $rates = $this->rates();
        $this->transaction(function () use ($changes, $rates, $actorId) {
            foreach ($changes as [$i, $delta]) {
                $bucket = self::bucket($i);
                $this->moveAllowance($this->header($i['no']), $i, $delta, 'ageing',
                    'Ageing rate for ' . ($bucket === 'Current' ? 'claims not yet due' : $bucket . ' overdue') . ': ' . self::pctText($rates[$bucket] ?? 0) . ' of ' . Prototype::fmt(self::outstanding($i)), $actorId, Clock::date());
            }
        });

        return [
            'done'     => array_map(fn ($c) => $this->find($c[0]['no']), $changes),
            'raised'   => self::num(array_sum(array_map(static fn ($c) => max(0, $c[1]), $changes))),
            'released' => self::num(array_sum(array_map(static fn ($c) => max(0, -$c[1]), $changes))),
        ];
    }

    /**
     * Replaces the ageing rates. A claim later past its due date is no more likely
     * to be paid, so a rate may not fall as the buckets age.
     *
     * @param array<string, float|string> $rates bucket → %
     */
    public function saveRates(array $rates, int $actorId): array
    {
        $clean = [];
        $previous = 0.0;
        foreach (self::BUCKETS as $bucket) {
            $raw = trim((string) ($rates[$bucket] ?? ''));
            if ($raw === '' || !is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                throw new RuleViolation('Enter a rate between 0 and 100% for ' . ($bucket === 'Current' ? 'claims not yet due' : $bucket . ' overdue') . '.');
            }
            $pct = round((float) $raw, 3);
            if ($pct < $previous) {
                throw new RuleViolation('The rate for ' . $bucket . ' cannot be lower than for younger balances — an older debt is no more likely to be paid.');
            }
            $clean[$bucket] = $previous = $pct;
        }

        $before = $this->rates();
        $changed = array_filter(self::BUCKETS, static fn ($b) => abs(($before[$b] ?? 0) - $clean[$b]) >= 0.0005);
        if ($changed === []) {
            throw new RuleViolation('The ageing rates are unchanged.');
        }

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($clean, $before, $changed, $actorId, $who) {
            foreach ($changed as $bucket) {
                $this->db->table('allowance_rates')->where('bucket', $bucket)->update(['pct' => $clean[$bucket], 'updated_at' => Clock::timestamp()]);
            }
            $this->audit('allowance_rates', null, 'Allowance rates', 'Ageing rates for doubtful debts changed by ' . $who . ': '
                . implode('; ', array_map(static fn ($b) => $b . ' ' . self::pctText($before[$b] ?? 0) . ' → ' . self::pctText($clean[$b]), $changed)), $actorId, 'history', $this->lookups->entityId());
        });

        return $this->rates();
    }

    /**
     * Brings into the ledger claims that are recorded as written off but have no
     * write-off entry behind them — written off before this system kept the books. Each is
     * provided for in full and the allowance then used, as the write-off would be
     * now. An entry goes in the month the claim was written off while that month is
     * open, and otherwise in the current one, saying so.
     *
     * @return list<string> the invoices booked
     */
    public function bookRecordedWriteOffs(int $actorId): array
    {
        $unbooked = $this->rows(
            "SELECT i.* FROM {invoices} i WHERE i.status = 'written_off'
             AND NOT EXISTS (SELECT 1 FROM {journals} j WHERE j.source_type = 'invoice' AND j.source_id = i.id AND j.narration LIKE 'Write-off of %')
             ORDER BY i.id"
        );

        $booked = [];
        foreach ($unbooked as $invoice) {
            $current = $this->find($invoice['reference']);
            if (self::outstanding($current) <= 0) {
                continue;
            }
            $recorded = substr((string) ($this->value(
                "SELECT occurred_at FROM {audit_events} WHERE object_type = 'invoice' AND object_id = ? AND summary LIKE 'Written off%' ORDER BY occurred_at DESC, id DESC LIMIT 1",
                [$invoice['id']]
            ) ?? $invoice['updated_at'] ?? Clock::date()), 0, 10);
            $period = array_values(array_filter($this->lookups->periods(), static fn ($p) => $p['starts_on'] <= $recorded && $recorded <= $p['ends_on']))[0] ?? null;
            $date = $period !== null && $period['status'] === 'open' ? $recorded : Clock::date();
            $note = $date === $recorded ? '' : ' (written off ' . self::dmy($recorded) . '; ' . ($period['name'] ?? 'that month') . ' is closed)';
            $reason = (string) $invoice['written_off_reason'];

            $this->transaction(function () use ($invoice, $current, $reason, $actorId, $date, $note) {
                $raised = $this->moveAllowance($invoice, $current, self::outstanding($current), 'specific', 'Recorded as written off: ' . $reason, $actorId, $date, $note);
                [$journal] = $this->postWriteOff($invoice, ['allowance' => self::outstanding($current)] + $current, $reason, $actorId, $date, $note);
                $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                    'Write-off brought into the ledger' . $note . ' — ' . Prototype::fmt(self::outstanding($current)) . ' provided for as ' . $raised . ' and used as ' . $journal, $actorId, 'history', (int) $invoice['entity_id']);
            });
            $booked[] = $invoice['reference'];
        }

        return $booked;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A receipt lands in an active bank or mobile-money account, under a reference not already used there. */
    private function checkReceiptBank(?array $bank, string $accountCode, string $bankRef): void
    {
        if ($bank === null || $bank['kind'] === 'petty_cash' || $bank['status'] !== 'active') {
            throw new RuleViolation($accountCode . ' is not a bank or mobile-money account a receipt can land in.');
        }
        if ($bankRef !== '' && $this->value('SELECT id FROM {receipts} WHERE bank_account_id = ? AND reference = ?', [$bank['id'], $bankRef]) !== null) {
            throw new RuleViolation('A receipt with reference ' . $bankRef . ' is already recorded on ' . $bank['name'] . '.');
        }
    }

    /**
     * Records a receipt against a claim and posts it: the bank debited, grants
     * receivable credited. Without a bank reference the receipt takes the next
     * receipt number. Call inside a transaction.
     *
     * @return array{0: string, 1: string} journal, receipt reference
     */
    private function postReceipt(array $invoice, array $current, float $amount, array $bank, string $bankRef, string $note, int $actorId, string $date): array
    {
        $ref = $bankRef !== '' ? $bankRef : $this->nextReceiptReference($date);
        $receiptId = $this->insert('receipts', [
            'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'bank_account_id' => $bank['id'],
            'received_on' => $date, 'reference' => $ref, 'currency' => $invoice['currency'], 'fx_rate' => $invoice['fx_rate'],
            'amount_fc' => round($amount / (float) $invoice['fx_rate'], 2), 'amount' => $amount,
            'note' => mb_substr($note, 0, 255), 'created_by' => $actorId, 'created_at' => Clock::timestamp(),
        ]);

        $journal = (new JournalRepository())->postFromSource([
            'date' => $date, 'sourceType' => 'receipt', 'sourceId' => $receiptId, 'docRef' => $ref, 'series' => 'RC',
            'narration' => 'Receipt from ' . $current['donor'] . ' against ' . $invoice['reference'],
            'memo' => 'Donor receipt applied against the claim.',
        ], [
            $this->posting($bank['code'], $invoice, 'Receipt banked — ' . $bank['short_name'] . ' · ' . $ref, $amount, 0),
            $this->posting(PostingAccounts::of('receivables'), $invoice, 'Grants receivable settled — ' . $invoice['reference'], 0, $amount),
        ], $actorId, null, 'Raised by Receivables on receipt ' . $ref . ' by ' . $this->lookups->shortName($actorId));

        $this->db->table('receipts')->where('id', $receiptId)->update(['journal_id' => $this->journalId($journal)]);

        return [$journal, $ref];
    }

    /**
     * Posts the write-off entry: receivable cleared, allowance used first, the rest
     * charged to bad and doubtful debts. Anything the allowance held beyond what is
     * written off is released in the same entry. Call inside a transaction.
     *
     * @return array{0: string, 1: float, 2: float} journal, charged to 5370, met from the allowance
     */
    private function postWriteOff(array $invoice, array $current, string $reason, int $actorId, string $date, string $note): array
    {
        $out = self::outstanding($current);
        $held = round($current['allowance'], 2);
        $used = min($held, $out);
        $charged = round($out - $held, 2);
        $who = $this->lookups->shortName($actorId);

        $journal = (new JournalRepository())->postFromSource([
            'date' => $date, 'sourceType' => 'invoice', 'sourceId' => (int) $invoice['id'], 'docRef' => $invoice['reference'], 'series' => 'JV',
            'narration' => 'Write-off of ' . $invoice['reference'] . ' — ' . $current['donor'] . $note,
            'memo' => mb_substr('Irrecoverable claim written off: ' . $reason, 0, 255),
        ], [
            $this->posting(PostingAccounts::of('badDebts'), $invoice, 'Bad debt — ' . $current['donor'] . ' · ' . $invoice['reference'], max(0, $charged), max(0, -$charged)),
            $this->posting(PostingAccounts::of('allowance'), $invoice, 'Allowance used on write-off — ' . $invoice['reference'], $held, 0),
            $this->posting(PostingAccounts::of('receivables'), $invoice, 'Receivable derecognised — ' . $invoice['reference'], 0, $out),
        ], $actorId, null, 'Raised by Receivables on write-off of ' . $invoice['reference'] . ' by ' . $who);

        if ($held > 0) {
            $this->insert('receivable_allowances', [
                'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'basis' => 'write_off', 'amount' => -$held,
                'reason' => $reason, 'journal_id' => $this->journalId($journal), 'created_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
        }

        return [$journal, $charged, $used];
    }

    /**
     * Raises (+) or releases (−) the allowance on one claim: bad and doubtful debts
     * against 1215, on the claim's own fund, programme and grant. Call inside a
     * transaction.
     */
    private function moveAllowance(array $invoice, array $current, float $delta, string $basis, string $reason, int $actorId, string $date, string $note = ''): string
    {
        $delta = round($delta, 2);
        $raise = $delta > 0;
        $size = abs($delta);
        $who = $this->lookups->shortName($actorId);

        $journal = (new JournalRepository())->postFromSource([
            'date' => $date, 'sourceType' => 'invoice', 'sourceId' => (int) $invoice['id'], 'docRef' => $invoice['reference'], 'series' => 'JV',
            'narration' => ($raise ? 'Allowance for doubtful debt raised on ' : 'Allowance for doubtful debt released on ') . $invoice['reference'] . ' — ' . $current['donor'] . $note,
            'memo' => mb_substr($reason, 0, 255),
        ], [
            $this->posting(PostingAccounts::of('badDebts'), $invoice, ($raise ? 'Doubtful debt provided for — ' : 'Doubtful debt provision released — ') . $invoice['reference'], $raise ? $size : 0, $raise ? 0 : $size),
            $this->posting(PostingAccounts::of('allowance'), $invoice, 'Allowance for doubtful debts — ' . $current['donor'] . ' · ' . $invoice['reference'], $raise ? 0 : $size, $raise ? $size : 0),
        ], $actorId, null, 'Raised by Receivables on the allowance for ' . $invoice['reference'] . ' by ' . $who);

        $this->insert('receivable_allowances', [
            'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'basis' => $basis, 'amount' => $delta,
            'reason' => $reason, 'journal_id' => $this->journalId($journal), 'created_by' => $actorId, 'created_at' => Clock::timestamp(),
        ]);
        $after = round($current['allowance'] + $delta, 2);
        $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
            'Allowance ' . ($raise ? 'raised' : 'released') . ' by ' . Prototype::fmt($size) . ' to ' . Prototype::fmt($after) . ' as ' . $journal
            . ($basis === 'ageing' ? ' by the ageing rates' : ($basis === 'receipt' ? ' on receipt' : ' by ' . $who)) . ': ' . $reason, $actorId, 'history', (int) $invoice['entity_id']);

        return $journal;
    }

    private function journalId(string $reference): int
    {
        return (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$reference]);
    }

    private static function writeOffSplit(float $charged, float $used): string
    {
        $parts = [];
        if ($used > 0) {
            $parts[] = Prototype::fmt($used) . ' met from the allowance (' . PostingAccounts::of('allowance') . ')';
        }
        if ($charged > 0 || $used <= 0) {
            $parts[] = Prototype::fmt(max(0, $charged)) . ' charged to ' . PostingAccounts::of('badDebts');
        }

        return implode(' and ', $parts);
    }

    private static function pctText(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 3, '.', ''), '0'), '.') . '%';
    }

    private function header(string $no): array
    {
        return $this->row('SELECT * FROM {invoices} WHERE reference = ?', [$no]) ?? throw new RuleViolation($no . ' was not found in receivables.');
    }

    /**
     * Splits the invoices named into those an action applies to and those it skips,
     * with why. When none qualifies the action is refused: for one invoice with its
     * reason, for a selection with `$noneInSelection`.
     *
     * @return array{0: list<array>, 1: list<array{no: string, reason: string}>}
     */
    private function eligible(array $nos, string $noneInSelection, callable $refusal): array
    {
        $nos = array_values(array_unique(array_filter(array_map('strval', $nos))));
        if ($nos === []) {
            throw new RuleViolation('Select at least one invoice.');
        }

        $invoices = $skipped = [];
        foreach ($nos as $no) {
            $i = $this->row(
                'SELECT i.*, COALESCE(fu.name, i.bill_to) AS customer, g.award_ref FROM {invoices} i LEFT JOIN {funders} fu ON fu.id = i.funder_id
                 LEFT JOIN {grants} g ON g.id = i.grant_id WHERE i.reference = ?',
                [$no]
            );
            $reason = $i === null ? $no . ' was not found in receivables.' : $refusal($i);
            if ($reason === null) {
                $invoices[] = $i;
            } else {
                $skipped[] = ['no' => $no, 'reason' => $reason];
            }
        }
        if ($invoices === []) {
            throw new RuleViolation(count($nos) === 1 ? $skipped[0]['reason'] : $noneInSelection);
        }

        return [$invoices, $skipped];
    }

    private function posting(string $code, array $invoice, string $desc, float $dr, float $cr): array
    {
        return [
            'code' => $code, 'fund_id' => (int) $invoice['fund_id'], 'programme_id' => (int) $invoice['programme_id'],
            'grant_id' => $invoice['grant_id'] === null ? null : (int) $invoice['grant_id'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
    }

    private function grantFund(int $grantId): int
    {
        $grant = $this->lookups->grants()[$grantId];
        if ($grant['fund_id'] !== null) {
            return (int) $grant['fund_id'];
        }

        return $this->lookups->resolveFund('Grant Fund', $grantId, $this->lookups->programmeName($this->grantProgramme($grantId)));
    }

    private function grantProgramme(int $grantId): int
    {
        $own = $this->lookups->grants()[$grantId]['programme_id'];

        return $own !== null ? (int) $own : $this->lookups->programmeId('Shared services');
    }

    private function generalFund(): int
    {
        foreach ($this->lookups->funds() as $id => $f) {
            if ($f['ledger_group'] === 'general') {
                return (int) $id;
            }
        }

        throw new RuleViolation('There is no general fund to code other income to.');
    }

    /** INV-26-0046: one more than the highest invoice number issued in the year. */
    private function nextReference(string $date): string
    {
        $stem = 'INV-' . substr($date, 2, 2) . '-';
        $max = 0;
        foreach ($this->rows('SELECT reference FROM {invoices} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** RV-26-0001: the next receipt voucher number, for a receipt recorded without a bank reference. */
    private function nextReceiptReference(string $date): string
    {
        $stem = 'RV-' . substr($date, 2, 2) . '-';
        $max = 0;
        foreach ($this->rows('SELECT reference FROM {receipts} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
