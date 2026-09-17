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
 * full or in part, clears 1210 against the bank it lands in. A claim the donor
 * will not pay is written off to bad debts (5370). Every step is on the claim's
 * audit trail.
 */
final class ReceivablesRepository extends Repository
{
    private const TYPE_LABELS = ['grant_claim' => 'Grant claim', 'cost_reimbursement' => 'Cost reimbursement', 'other_income' => 'Other income'];

    public const CURRENCIES = ['KES' => 1.0, 'USD' => 129.40, 'EUR' => 139.80];

    private const RECEIVABLE   = '1210';
    private const GRANT_INCOME = '4110';
    private const BAD_DEBTS    = '5370';

    /** A claim falls due this many days after it is issued. */
    private const TERMS_DAYS = 30;

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

            return array_map(function ($i) use ($lines, $receipts, $trails) {
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
                    'lines'      => $lines[$id] ?? [],
                    'receipts'   => $receipts[$id] ?? [],
                    'trail'      => $trails[$id] ?? [],
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
                && $a['status'] === 'active' && str_starts_with($a['code'], '42') && $a['code'] !== '4240' && $a['code'] !== '4250')
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

        if (!array_key_exists($ccy, self::CURRENCIES)) {
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
                $lines[] = ['code' => self::GRANT_INCOME, 'desc' => mb_substr($b['name'] . ' — ' . $period, 0, 255), 'amount' => $claim];
            }
            if ($lines === []) {
                throw new RuleViolation('Claim at least one line. The builder only lets you claim expenditure already in the ledger.');
            }
            $direct = array_sum(array_column($lines, 'amount'));
            if (!empty($f['indirect']) && $grant['indirect'] !== null) {
                $indirect = min(round($direct * $grant['indirect']['pct'] / 100), (float) $grant['indirect']['cap']);
                if ($indirect > 0) {
                    $lines[] = ['code' => self::GRANT_INCOME, 'desc' => 'Indirect cost recovery at ' . self::num($grant['indirect']['pct']) . '%', 'amount' => $indirect];
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

        $no = $this->transaction(function () use ($other, $f, $grant, $type, $period, $basis, $ccy, $fx, $lines, $total, $actorId, $who) {
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
                'issue_date' => $today, 'due_date' => date('Y-m-d', strtotime($today . ' +' . self::TERMS_DAYS . ' days')),
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
            $this->audit('invoice', $id, $no, 'Draft ' . ($other ? 'invoice raised' : 'claim built from the ' . $grant['ref'] . ' budget') . ' by ' . $who, $actorId, 'history', $this->lookups->entityId());

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
                $term = max(0, (int) ((strtotime($i['due_date']) - strtotime($i['issue_date'])) / 86400)) ?: self::TERMS_DAYS;
                $lines = $this->rows('SELECT l.*, a.code, a.name FROM {invoice_lines} l JOIN {accounts} a ON a.id = l.account_id WHERE l.invoice_id = ? ORDER BY l.line_no', [$i['id']]);
                $posting = [$this->posting(self::RECEIVABLE, $i, 'Receivable from ' . $i['customer'] . ' · ' . $i['reference'], (float) $i['amount'], 0)];
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
            in_array($invoice['status'], ['draft', 'written_off'], true) => $no . ' is ' . strtolower(self::label($invoice['status'])) . ' — it cannot take a receipt' . ($invoice['status'] === 'draft' ? ' until it is issued to the donor.' : '.'),
            $invoice['status'] === 'received' => $no . ' has already been received in full.',
            $amount <= 0 => 'Enter a receipt amount first.',
            $amount > self::outstanding($current) + 0.005 => 'Receipt of ' . Prototype::fmt($amount) . ' exceeds the ' . Prototype::fmt(self::outstanding($current)) . ' outstanding on ' . $no . '.',
            $bank === null || $bank['kind'] === 'petty_cash' || $bank['status'] !== 'active' => $accountCode . ' is not a bank or mobile-money account a receipt can land in.',
            default => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }
        $bankRef = trim($bankRef);
        if ($bankRef !== '' && $this->value('SELECT id FROM {receipts} WHERE bank_account_id = ? AND reference = ?', [$bank['id'], $bankRef]) !== null) {
            throw new RuleViolation('A receipt with reference ' . $bankRef . ' is already recorded on ' . $bank['name'] . '.');
        }

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($invoice, $current, $amount, $bank, $bankRef, $note, $actorId, $who) {
            $now = Clock::timestamp();
            $today = Clock::date();
            $ref = $bankRef !== '' ? $bankRef : $this->nextReceiptReference($today);
            $full = $amount >= self::outstanding($current) - 0.005;
            $receiptId = $this->insert('receipts', [
                'entity_id' => $invoice['entity_id'], 'invoice_id' => $invoice['id'], 'bank_account_id' => $bank['id'],
                'received_on' => $today, 'reference' => $ref, 'currency' => $invoice['currency'], 'fx_rate' => $invoice['fx_rate'],
                'amount_fc' => round($amount / (float) $invoice['fx_rate'], 2), 'amount' => $amount,
                'note' => mb_substr($note !== '' ? $note : ($full ? 'Settled in full' : 'Part receipt recorded'), 0, 255),
                'created_by' => $actorId, 'created_at' => $now,
            ]);

            $journal = (new JournalRepository())->postFromSource([
                'date' => $today, 'sourceType' => 'receipt', 'sourceId' => $receiptId, 'docRef' => $ref, 'series' => 'RC',
                'narration' => 'Receipt from ' . $current['donor'] . ' against ' . $invoice['reference'],
                'memo' => 'Donor receipt applied against the claim.',
            ], [
                $this->posting($bank['code'], $invoice, 'Receipt banked — ' . $bank['short_name'] . ' · ' . $ref, $amount, 0),
                $this->posting(self::RECEIVABLE, $invoice, 'Grants receivable settled — ' . $invoice['reference'], 0, $amount),
            ], $actorId, null, 'Raised by Receivables on receipt ' . $ref . ' by ' . $who);

            $this->db->table('receipts')->where('id', $receiptId)->update(['journal_id' => $this->value('SELECT id FROM {journals} WHERE reference = ?', [$journal])]);
            $this->db->table('invoices')->where('id', $invoice['id'])->update(['status' => $full ? 'received' : 'part_received', 'updated_at' => $now]);
            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                ($full ? 'Receipt in full' : 'Part receipt') . ' of ' . Prototype::fmt($amount) . ' posted to ' . $bank['code'] . ' as ' . $journal . ' by ' . $who, $actorId, 'history', (int) $invoice['entity_id']);
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
     * Writes off what is left on an overdue claim the donor will not pay: charged to
     * bad debts and cleared from grants receivable, with the reason on record.
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
            $out = self::outstanding($current);
            $journal = (new JournalRepository())->postFromSource([
                'date' => Clock::date(), 'sourceType' => 'invoice', 'sourceId' => (int) $invoice['id'], 'docRef' => $invoice['reference'], 'series' => 'JV',
                'narration' => 'Write-off of ' . $invoice['reference'] . ' — ' . $current['donor'],
                'memo' => mb_substr('Irrecoverable claim written off: ' . $reason, 0, 255),
            ], [
                $this->posting(self::BAD_DEBTS, $invoice, 'Bad debt — ' . $current['donor'] . ' · ' . $invoice['reference'], $out, 0),
                $this->posting(self::RECEIVABLE, $invoice, 'Receivable derecognised — ' . $invoice['reference'], 0, $out),
            ], $actorId, null, 'Raised by Receivables on write-off of ' . $invoice['reference'] . ' by ' . $who);

            $this->db->table('invoices')->where('id', $invoice['id'])->update([
                'status' => 'written_off', 'written_off_reason' => $reason, 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('invoice', (int) $invoice['id'], $invoice['reference'],
                'Written off by ' . $who . ' — ' . Prototype::fmt($out) . ' charged to ' . self::BAD_DEBTS . ' as ' . $journal . ': ' . $reason, $actorId, 'history', (int) $invoice['entity_id']);
        });

        return $this->find($no);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

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
