<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Supplier bills from capture to payment, and the withholding tax they hold for KRA.
 *
 * A bill moves Awaiting approval → Approved → Scheduled → Paid, or is Rejected.
 * Nothing reaches the ledger at capture. When a second person approves the bill
 * its cost is posted: the coded lines are debited with the taxable amount and
 * the irrecoverable VAT, and trade payables (2110) and withholding tax payable
 * (2240) are credited. Releasing a payment run clears 2110 against the bank or
 * M-Pesa account the bills are paid from. Remitting withholding tax clears 2240.
 * A bill's preparer can neither approve it nor release its payment, and the
 * approval rules in Settings decide which role approves a bill or releases a
 * payment run of a given value (see ApprovalPolicy).
 */
final class PayablesRepository extends Repository
{
    public const VAT_RATE = 0.16;

    /** Withholding rates a bill may carry, in %. */
    public const WHT_RATES = [0, 3, 5, 10];

    public const TERMS = [14, 30, 45, 60];

    private const STATUS_LABELS = ['pending_approval' => 'Awaiting approval'];

    private const PAYABLE = '2110';
    private const WHT_PAYABLE = '2240';
    private const ACCRUED = '2120';

    /** Payment run numbers continue from the prototype's last run, PR-26-0087. */
    private const RUN_FLOOR = 87;

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
            foreach ($this->rows(
                'SELECT l.*, a.code, a.name AS account_name, f.ledger_group, g.short_name AS grant_short
                 FROM {bill_lines} l JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
                 LEFT JOIN {grants} g ON g.id = l.grant_id ORDER BY l.bill_id, l.line_no'
            ) as $l) {
                $lines[(int) $l['bill_id']][] = $l;
            }

            $budgets = $this->budgetPositions();
            $banks   = array_column($this->lookups->bankAccounts(), null, 'id');
            $trails  = $this->trails('bill');

            return array_map(function ($b) use ($lines, $budgets, $banks, $trails) {
                $billLines = $lines[(int) $b['id']] ?? [];
                $first     = $billLines[0] ?? null;
                $position  = $first === null ? null : ($budgets[$first['account_id'] . ':' . $first['fund_id'] . ':' . $first['programme_id']] ?? null);
                $bank      = $banks[$b['pay_from_bank_account_id']] ?? null;
                $wht       = (float) $b['wht_amount'];

                return [
                    'no'        => $b['reference'],
                    'supplier'  => $b['supplier_name'],
                    'pin'       => $b['kra_pin'] ?? '—',
                    'category'  => $b['category'],
                    'invoiceNo' => $b['supplier_invoice_no'] ?? '',
                    'invDate'   => self::dm($b['invoice_date']),
                    'dueDate'   => self::dm($b['due_date']),
                    'invFull'   => self::dmy($b['invoice_date']),
                    'dueFull'   => self::dmy($b['due_date']),
                    'dueIn'     => Clock::daysUntil($b['due_date']),
                    'terms'     => $b['terms_days'] . ' days',
                    'fund'      => $first === null ? 'General Fund' : self::FUND_GROUPS[$first['ledger_group']],
                    'program'   => $first === null ? 'Shared services' : $this->lookups->programmeName((int) $first['programme_id']),
                    'grant'     => $first['grant_short'] ?? 'Unassigned',
                    'budget'    => $position === null ? '—' : 'KES ' . Prototype::fmt($position['actual'] + $position['committed']) . ' of ' . Prototype::fmt($position['budget']),
                    'taxable'   => self::num($b['subtotal']),
                    'vat'       => self::num($b['vat']),
                    'gross'     => self::num($b['total']),
                    'wht'       => self::num($wht),
                    'net'       => self::num((float) $b['total'] - $wht),
                    'whtRate'   => self::num($b['wht_rate_pct']),
                    'status'    => self::label($b['status'], self::STATUS_LABELS),
                    'method'    => $bank === null ? '—' : self::methodLabel($bank, $b['payment_method']),
                    'preparedBy' => (int) $b['prepared_by'],
                    'preparer'  => $this->lookups->shortName((int) $b['prepared_by']),
                    'rejectedReason' => $b['rejected_reason'] ?? '',
                    'journal'   => $b['journal_ref'],
                    'run'       => $b['run_ref'] === null ? null : ['ref' => $b['run_ref'], 'date' => self::dm($b['run_date']), 'status' => self::label($b['run_status'])],
                    'whtRemittance' => $b['remittance_ref'],
                    'lines'     => array_map(static fn ($l) => [
                        'code' => $l['code'], 'name' => $l['account_name'], 'desc' => $l['description'], 'amount' => self::num($l['amount']),
                    ], $billLines),
                    'trail'     => $trails[(int) $b['id']] ?? [],
                ];
            }, $this->rows(
                'SELECT b.*, s.name AS supplier_name, s.kra_pin, s.category, j.reference AS journal_ref,
                        r.reference AS run_ref, r.run_date, r.status AS run_status, w.reference AS remittance_ref
                 FROM {bills} b JOIN {suppliers} s ON s.id = b.supplier_id
                 LEFT JOIN {journals} j ON j.id = b.journal_id LEFT JOIN {payment_runs} r ON r.id = b.payment_run_id
                 LEFT JOIN {wht_remittances} w ON w.id = b.wht_remittance_id
                 ORDER BY b.invoice_date, b.reference'
            ));
        });
    }

    public function find(string $no): ?array
    {
        foreach ($this->all() as $b) {
            if ($b['no'] === $no) {
                return $b;
            }
        }

        return null;
    }

    /** Payment methods by label: local bank transfer, mobile money, cheque, then foreign-currency accounts. */
    public function methods(): array
    {
        return array_column($this->methodOptions(), 'label');
    }

    /**
     * Each way a bill can be paid, and the account the money leaves from. A cheque
     * is drawn on the main shilling account.
     *
     * @return list<array{label: string, bankId: int, method: string}>
     */
    public function methodOptions(): array
    {
        $rank = static fn (array $b) => match (true) {
            $b['kind'] === 'mobile_money' => 1,
            $b['currency'] !== 'KES'      => 3,
            default                       => 0,
        };
        $accounts = array_values(array_filter($this->lookups->bankAccounts(), static fn ($b) => $b['status'] === 'active' && $b['kind'] !== 'petty_cash'));
        usort($accounts, static fn ($a, $b) => [$rank($a), $a['code']] <=> [$rank($b), $b['code']]);

        $options = array_map(static fn ($b) => [
            'label' => Lookups::paymentMethod($b), 'bankId' => (int) $b['id'], 'method' => $b['kind'] === 'mobile_money' ? 'mpesa' : 'eft',
        ], $accounts);

        $chequeBank = array_values(array_filter($accounts, static fn ($b) => $rank($b) === 0))[0] ?? null;
        if ($chequeBank !== null) {
            $foreign = count(array_filter($accounts, static fn ($b) => $rank($b) === 3));
            array_splice($options, count($options) - $foreign, 0, [['label' => 'Cheque', 'bankId' => (int) $chequeBank['id'], 'method' => 'cheque']]);
        }

        return $options;
    }

    /** The shilling account withholding tax is paid to KRA from. */
    private function mainBank(): array
    {
        $cheque = array_values(array_filter($this->methodOptions(), static fn ($o) => $o['method'] === 'cheque'))[0]
            ?? throw new RuleViolation('There is no active shilling bank account to pay from.');

        return array_column($this->lookups->bankAccounts(), null, 'id')[$cheque['bankId']];
    }

    /**
     * Approved budget, actual and committed per account, fund and programme, summed
     * across grants, from the approved budget version.
     *
     * @return array<string, array{budget: float, actual: float, committed: float}>
     */
    public function budgetPositions(): array
    {
        return $this->cached('budget-positions', function () {
            $out = [];
            foreach ($this->rows(
                "SELECT v.account_id, v.fund_id, v.programme_id, SUM(v.budget) AS budget, SUM(v.actual) AS actual, SUM(v.committed) AS committed
                 FROM {v_budget_availability} v JOIN {budget_versions} bv ON bv.id = v.budget_version_id
                 WHERE bv.status = 'approved' GROUP BY v.account_id, v.fund_id, v.programme_id"
            ) as $r) {
                $out[$r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id']] = [
                    'budget' => (float) $r['budget'], 'actual' => (float) $r['actual'], 'committed' => (float) $r['committed'],
                ];
            }

            return $out;
        });
    }

    /**
     * Budget lines a bill can be coded to, with what is left on each: the approved
     * budget less actual spend and less bills not yet in the ledger.
     *
     * @return list<array{id: int, code: string, name: string, fund: string, program: string, grant: string, annual: float, actual: float, onBills: float, remaining: float}>
     */
    public function budgetLines(): array
    {
        return $this->cached('budget-lines', function () {
            $onBills = [];
            foreach ($this->rows(
                "SELECT l.account_id, l.fund_id, l.programme_id, l.grant_id, SUM(l.amount) AS amount
                 FROM {bill_lines} l JOIN {bills} b ON b.id = l.bill_id
                 WHERE b.status IN ('pending_approval', 'approved', 'scheduled') AND b.journal_id IS NULL
                 GROUP BY l.account_id, l.fund_id, l.programme_id, l.grant_id"
            ) as $r) {
                $onBills[$r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id'] . ':' . $r['grant_id']] = (float) $r['amount'];
            }

            return array_map(function ($l) use ($onBills) {
                $billed = $onBills[$l['account_id'] . ':' . $l['fund_id'] . ':' . $l['programme_id'] . ':' . $l['grant_id']] ?? 0.0;

                return [
                    'id'        => (int) $l['budget_line_id'],
                    'code'      => $l['code'],
                    'name'      => $l['account_name'],
                    'fund'      => self::FUND_GROUPS[$l['ledger_group']],
                    'program'   => $this->lookups->programmeName((int) $l['programme_id']),
                    'grant'     => $l['grant_short'] ?? 'Unassigned',
                    'annual'    => self::num($l['budget']),
                    'actual'    => self::num($l['actual']),
                    'onBills'   => self::num($billed),
                    'remaining' => self::num((float) $l['budget'] - (float) $l['actual'] - $billed),
                    'accountId' => (int) $l['account_id'], 'fundId' => (int) $l['fund_id'],
                    'programmeId' => (int) $l['programme_id'], 'grantId' => $l['grant_id'] === null ? null : (int) $l['grant_id'],
                ];
            }, $this->rows(
                "SELECT v.*, a.code, a.name AS account_name, f.ledger_group, g.short_name AS grant_short
                 FROM {v_budget_availability} v JOIN {budget_versions} bv ON bv.id = v.budget_version_id
                 JOIN {accounts} a ON a.id = v.account_id JOIN {funds} f ON f.id = v.fund_id LEFT JOIN {grants} g ON g.id = v.grant_id
                 WHERE bv.status = 'approved' ORDER BY a.code, v.budget_line_id"
            ));
        });
    }

    /** @return list<array{name: string, wht: float}> spend categories and their policy withholding rate */
    public function categories(): array
    {
        return $this->cached('categories', fn () => array_map(
            static fn ($c) => ['name' => $c['name'], 'wht' => self::num($c['wht_rate_pct'])],
            $this->rows('SELECT name, wht_rate_pct FROM {spend_categories} ORDER BY id')
        ));
    }

    /** @return list<array{name: string, pin: string, category: string}> suppliers that can be billed */
    public function suppliers(): array
    {
        return array_map(
            static fn ($s) => ['name' => $s['name'], 'pin' => $s['kra_pin'] ?? '', 'category' => $s['category']],
            $this->rows("SELECT name, kra_pin, category FROM {suppliers} WHERE status <> 'blocked' ORDER BY name")
        );
    }

    /**
     * Withholding tax in the ledger and not yet paid over to KRA: what approved bills
     * have credited to 2240. Tax on bills still awaiting approval is not yet held.
     */
    public function whtHeld(): array
    {
        $held = $pending = 0.0;
        foreach ($this->rows("SELECT status, wht_amount FROM {bills} WHERE wht_amount > 0 AND wht_remittance_id IS NULL AND status <> 'rejected'") as $b) {
            if ($b['status'] === 'pending_approval') {
                $pending += (float) $b['wht_amount'];
            } else {
                $held += (float) $b['wht_amount'];
            }
        }
        $month = $this->currentPeriod();
        $remitted = $month === null ? null : $this->value('SELECT reference FROM {wht_remittances} WHERE period_id = ?', [$month['id']]);

        return ['held' => self::num($held), 'pending' => self::num($pending), 'remittedThisMonth' => $remitted,
            'dueBy' => Clock::today()->modify('first day of next month')->format('20 M')];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Captures a supplier invoice, coded to one budget line, for approval. A supplier
     * not yet on the register is added to it. Nothing reaches the ledger until the bill
     * is approved.
     *
     * @param array{supplier: string, pin: string, category: string, invoiceNo: string, invoiceDate: string, terms: int|string,
     *              budgetLine: int|string, method: string, wht: string, whtReason: string, overReason: string,
     *              lines: list<array{desc: string, amount: float|string}>} $f
     */
    public function capture(array $f, int $actorId): array
    {
        $name = trim((string) $f['supplier']);
        $pin  = strtoupper(trim((string) $f['pin']));
        $invoiceNo = trim((string) $f['invoiceNo']);
        $category = null;
        foreach ($this->categories() as $c) {
            if ($c['name'] === $f['category']) {
                $category = $c;
            }
        }
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $f['invoiceDate']);
        $terms = (int) $f['terms'];
        $line  = null;
        foreach ($this->budgetLines() as $l) {
            if ($l['id'] === (int) $f['budgetLine']) {
                $line = $l;
            }
        }
        $method = null;
        foreach ($this->methodOptions() as $o) {
            if ($o['label'] === $f['method']) {
                $method = $o;
            }
        }

        $parsed = array_map(static fn ($l) => [
            'desc'   => trim((string) ($l['desc'] ?? '')),
            'amount' => round((float) preg_replace('/[^0-9.]/', '', (string) ($l['amount'] ?? '')), 2),
        ], (array) ($f['lines'] ?? []));
        $coded   = array_values(array_filter($parsed, static fn ($l) => $l['amount'] > 0));
        $taxable = array_sum(array_column($coded, 'amount'));

        $whtDefault = $category['wht'] ?? 0;
        $whtRate = ($f['wht'] ?? 'auto') === 'auto' ? $whtDefault : (float) $f['wht'];
        $whtOverridden = ($f['wht'] ?? 'auto') !== 'auto' && $whtRate != $whtDefault;
        $overBudget = $line !== null && $taxable > $line['remaining'];

        $error = match (true) {
            $name === ''                                   => 'Name the supplier as it appears on the invoice.',
            preg_match('/^P0\d{8}[A-Z]$/', $pin) !== 1     => 'The KRA PIN looks wrong. It runs P0 then eight digits and a letter, e.g. P051182934C — without it the VAT and WHT cannot be filed.',
            $category === null                             => 'Choose the spend category. It sets the withholding tax policy.',
            $invoiceNo === ''                              => "Enter the supplier's invoice number. It is the duplicate-payment check.",
            $date === false                                => 'Enter the invoice date.',
            !in_array($terms, self::TERMS, true)           => 'Payment terms must be 14, 30, 45 or 60 days.',
            $line === null                                 => 'Choose the budget line the bill is coded to.',
            $method === null                               => 'Choose how the supplier will be paid.',
            $coded === []                                  => 'Code at least one line with an amount.',
            array_filter($coded, static fn ($l) => $l['desc'] === '') !== [] => 'Every coded line needs a description of what was supplied.',
            !in_array((int) $whtRate, self::WHT_RATES, true) || $whtRate != (int) $whtRate => 'Withholding tax must be 0%, 3%, 5% or 10%.',
            $whtOverridden && trim((string) $f['whtReason']) === '' => 'Overriding the withholding rate needs a reason — the tax file has to explain it.',
            $overBudget && trim((string) $f['overReason']) === ''   => 'This bill takes the line over budget. Say why before it goes for approval, or split the coding.',
            default                                        => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }

        $supplier = $this->row('SELECT * FROM {suppliers} WHERE LOWER(name) = ?', [strtolower($name)]);
        if ($supplier !== null && $supplier['status'] === 'blocked') {
            throw new RuleViolation($supplier['name'] . ' is blocked on the supplier register and cannot be billed.');
        }
        if ($supplier !== null && $supplier['kra_pin'] !== null && $supplier['kra_pin'] !== $pin) {
            throw new RuleViolation($supplier['name'] . ' is on the supplier register with KRA PIN ' . $supplier['kra_pin'] . '. Check the PIN on the invoice.');
        }
        $pinHolder = $this->row('SELECT name FROM {suppliers} WHERE kra_pin = ? AND (? IS NULL OR id <> ?)', [$pin, $supplier['id'] ?? null, $supplier['id'] ?? null]);
        if ($pinHolder !== null) {
            throw new RuleViolation('KRA PIN ' . $pin . ' belongs to ' . $pinHolder['name'] . ' on the supplier register.');
        }
        if ($supplier !== null) {
            $dupe = $this->value('SELECT reference FROM {bills} WHERE supplier_id = ? AND supplier_invoice_no = ?', [$supplier['id'], $invoiceNo]);
            if ($dupe !== null) {
                throw new RuleViolation('Invoice ' . $invoiceNo . ' from ' . $supplier['name'] . ' is already on file as ' . $dupe . '. It cannot be captured twice.');
            }
        }

        $vat   = round($taxable * self::VAT_RATE);
        $wht   = round($taxable * $whtRate / 100);
        $who   = $this->lookups->shortName($actorId);
        $entity = $this->lookups->entityId();

        $no = $this->transaction(function () use ($f, $name, $pin, $category, $invoiceNo, $date, $terms, $line, $method, $coded, $taxable, $vat, $wht, $whtRate, $whtDefault, $whtOverridden, $overBudget, $supplier, $actorId, $who, $entity) {
            $now = Clock::timestamp();
            $supplierId = $supplier['id'] ?? null;
            if ($supplier === null) {
                $supplierId = $this->insert('suppliers', [
                    'name' => $name, 'kra_pin' => $pin, 'category' => $category['name'], 'status' => 'not_prequalified', 'created_at' => $now,
                ]);
            } elseif ($supplier['kra_pin'] === null) {
                $this->db->table('suppliers')->where('id', $supplierId)->update(['kra_pin' => $pin, 'updated_at' => $now]);
            }

            $no = $this->nextBillReference();
            $id = $this->insert('bills', [
                'entity_id' => $entity, 'reference' => $no, 'supplier_id' => $supplierId, 'supplier_invoice_no' => $invoiceNo,
                'invoice_date' => $date->format('Y-m-d'), 'due_date' => $date->modify('+' . $terms . ' days')->format('Y-m-d'), 'terms_days' => $terms,
                'subtotal' => $taxable, 'vat' => $vat, 'wht_rate_pct' => $whtRate, 'wht_amount' => $wht, 'total' => $taxable + $vat,
                'status' => 'pending_approval', 'pay_from_bank_account_id' => $method['bankId'], 'payment_method' => $method['method'],
                'prepared_by' => $actorId,
                'wht_override_reason' => $whtOverridden ? trim((string) $f['whtReason']) : null,
                'over_budget_reason' => $overBudget ? trim((string) $f['overReason']) : null,
                'created_at' => $now,
            ]);
            foreach ($coded as $i => $l) {
                $this->insert('bill_lines', [
                    'bill_id' => $id, 'line_no' => $i + 1, 'account_id' => $line['accountId'], 'fund_id' => $line['fundId'],
                    'programme_id' => $line['programmeId'], 'grant_id' => $line['grantId'], 'description' => mb_substr($l['desc'], 0, 255), 'amount' => $l['amount'],
                ]);
            }

            $this->audit('bill', $id, $no, 'Captured and coded by ' . $who . ' — supplier invoice ' . $invoiceNo, $actorId, 'history', $entity);
            if ($whtOverridden) {
                $this->audit('bill', $id, $no, 'Withholding tax set to ' . self::num($whtRate) . '% against the ' . self::num($whtDefault) . '% default — ' . trim((string) $f['whtReason']), $actorId, 'history', $entity);
            }
            if ($overBudget) {
                $this->audit('bill', $id, $no, 'Coded over the remaining budget on ' . $line['code'] . ' — ' . trim((string) $f['overReason']), $actorId, 'history', $entity);
            }

            return $no;
        });

        return $this->find($no);
    }

    /**
     * Raises the supplier bill for goods received against a purchase order: the
     * three-way match of order, goods received note and the supplier's invoice. The
     * bill carries the order's lines and coding, VAT at 16%, the supplier's
     * withholding rate (or its spend category's), and waits for approval like any
     * other. Call it inside the caller's transaction.
     */
    public function captureFromReceipt(int $grnId, string $invoiceNo, int $actorId): array
    {
        $grn = $this->row(
            'SELECT g.*, po.id AS po_id, po.reference AS po_ref, po.supplier_id, s.name AS supplier, s.kra_pin, s.category, s.wht_rate_pct
             FROM {goods_received_notes} g JOIN {purchase_orders} po ON po.id = g.purchase_order_id JOIN {suppliers} s ON s.id = po.supplier_id WHERE g.id = ?',
            [$grnId]
        ) ?? throw new RuleViolation('Goods received note not found.');
        $invoiceNo = trim($invoiceNo);
        if ($invoiceNo === '') {
            throw new RuleViolation("Enter the supplier's invoice number. It completes the three-way match and is the duplicate-payment check.");
        }
        if ($grn['kra_pin'] === null) {
            throw new RuleViolation($grn['supplier'] . ' has no KRA PIN on the register — the VAT and WHT on its invoice cannot be filed.');
        }
        $dupe = $this->value('SELECT reference FROM {bills} WHERE supplier_id = ? AND supplier_invoice_no = ?', [$grn['supplier_id'], $invoiceNo]);
        if ($dupe !== null) {
            throw new RuleViolation('Invoice ' . $invoiceNo . ' from ' . $grn['supplier'] . ' is already on file as ' . $dupe . '. It cannot be captured twice.');
        }

        $lines = $this->rows('SELECT * FROM {purchase_order_lines} WHERE purchase_order_id = ? ORDER BY line_no', [$grn['po_id']]);
        $taxable = array_sum(array_map(static fn ($l) => (float) $l['amount'], $lines));
        $category = current(array_filter($this->categories(), static fn ($c) => $c['name'] === $grn['category'])) ?: null;
        $whtRate = (float) ($grn['wht_rate_pct'] ?? $category['wht'] ?? 0);
        $vat = round($taxable * self::VAT_RATE);
        $wht = round($taxable * $whtRate / 100);
        $method = $this->methodOptions()[0] ?? throw new RuleViolation('There is no active bank account to pay from.');
        $who = $this->lookups->shortName($actorId);

        $no = $this->transaction(function () use ($grn, $invoiceNo, $lines, $taxable, $whtRate, $vat, $wht, $method, $actorId, $who) {
            $now = Clock::timestamp();
            $today = Clock::date();
            $no = $this->nextBillReference();
            $id = $this->insert('bills', [
                'entity_id' => $grn['entity_id'], 'reference' => $no, 'supplier_id' => $grn['supplier_id'], 'supplier_invoice_no' => $invoiceNo,
                'invoice_date' => $today, 'due_date' => date('Y-m-d', strtotime($today . ' +30 days')), 'terms_days' => 30,
                'subtotal' => $taxable, 'vat' => $vat, 'wht_rate_pct' => $whtRate, 'wht_amount' => $wht, 'total' => $taxable + $vat,
                'status' => 'pending_approval', 'pay_from_bank_account_id' => $method['bankId'], 'payment_method' => $method['method'],
                'purchase_order_id' => $grn['po_id'], 'goods_received_note_id' => $grn['id'], 'prepared_by' => $actorId, 'created_at' => $now,
            ]);
            foreach ($lines as $l) {
                $this->insert('bill_lines', [
                    'bill_id' => $id, 'line_no' => $l['line_no'], 'account_id' => $l['account_id'], 'fund_id' => $l['fund_id'], 'programme_id' => $l['programme_id'],
                    'grant_id' => $l['grant_id'], 'purchase_order_line_id' => $l['id'], 'description' => $l['description'], 'amount' => $l['amount'],
                ]);
            }
            $this->audit('bill', $id, $no, 'Raised from ' . $grn['po_ref'] . ' on three-way match (PO, GRN ' . $grn['reference'] . ', invoice ' . $invoiceNo . ') by ' . $who, $actorId, 'history', (int) $grn['entity_id']);

            return $no;
        });

        return $this->find($no);
    }

    /**
     * Approves bills for payment and posts each one's cost to the ledger. Bills that
     * are not awaiting approval, or that the approver captured, are left as they are.
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>}
     */
    public function approve(array $nos, int $actorId): array
    {
        $who = $this->lookups->shortName($actorId);
        $policy = new ApprovalPolicy();
        [$bills, $skipped] = $this->eligible($nos, 'Nothing in the selection is awaiting approval by you.', function ($b) use ($actorId, $who, $policy) {
            if ($b['status'] !== 'pending_approval') {
                return $b['reference'] . ' is ' . strtolower(self::label($b['status'], self::STATUS_LABELS)) . ', not awaiting approval.';
            }
            if ((int) $b['prepared_by'] === $actorId) {
                return $who . ' captured ' . $b['reference'] . ' and cannot also approve it. It needs a second approver.';
            }

            return $policy->refusal('bill', (float) $b['total'], $actorId, $b['reference'])['message'] ?? null;
        });

        $this->transaction(function () use ($bills, $actorId, $who) {
            $journals = new JournalRepository();
            foreach ($bills as $b) {
                $now = Clock::timestamp();
                $lines = $this->rows('SELECT * FROM {bill_lines} WHERE bill_id = ? ORDER BY line_no', [$b['id']]);
                $date = $this->openDate($b['invoice_date']);

                // VAT is irrecoverable: it is part of the cost of each line. A line whose
                // goods were received and accrued clears the accrual instead of charging
                // the cost again; only its VAT is new cost.
                $accrued = $b['goods_received_note_id'] !== null
                    && $this->value('SELECT journal_id FROM {goods_received_notes} WHERE id = ?', [$b['goods_received_note_id']]) !== null;
                $posting = [];
                $vatLeft = (float) $b['vat'];
                foreach ($lines as $i => $l) {
                    $vat = $i === count($lines) - 1 ? $vatLeft : round((float) $l['amount'] * (float) $b['vat'] / max(1, (float) $b['subtotal']));
                    $vatLeft -= $vat;
                    $code = $this->accountCode((int) $l['account_id']);
                    if ($accrued && $l['purchase_order_line_id'] !== null) {
                        $posting[] = $this->posting(self::ACCRUED, $l, 'Accrual cleared — ' . $l['description'], (float) $l['amount'], 0);
                        $posting[] = $this->posting($code, $l, 'VAT on ' . $l['description'], $vat, 0);
                    } else {
                        $posting[] = $this->posting($code, $l, $l['description'], (float) $l['amount'] + $vat, 0);
                    }
                }
                $first = $lines[0];
                $posting[] = $this->posting(self::PAYABLE, $first, 'Payable to ' . $b['supplier_name'] . ' · ' . $b['reference'], 0, (float) $b['total'] - (float) $b['wht_amount']);
                $posting[] = $this->posting(self::WHT_PAYABLE, $first, 'Withholding tax at ' . self::num($b['wht_rate_pct']) . '% held on ' . $b['supplier_name'], 0, (float) $b['wht_amount']);

                $ref = $journals->postFromSource([
                    'date' => $date, 'sourceType' => 'bill', 'sourceId' => (int) $b['id'], 'docRef' => $b['reference'], 'series' => 'JV',
                    'narration' => $b['supplier_name'] . ' — ' . strtolower($b['category']),
                    'memo' => 'Supplier invoice ' . ($b['supplier_invoice_no'] ?? $b['reference']) . ' approved for payment. VAT is irrecoverable and absorbed into the coded cost.',
                ], $posting, (int) $b['prepared_by'], $actorId, 'Raised by Payables from ' . $b['reference']);

                $this->db->table('bills')->where('id', $b['id'])->update([
                    'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => $now, 'journal_id' => $this->journalId($ref), 'updated_at' => $now,
                ]);
                $this->audit('bill', (int) $b['id'], $b['reference'], 'Approved by ' . $who . ' and posted as ' . $ref, $actorId, 'history', (int) $b['entity_id']);
            }
        });

        return ['done' => array_map(fn ($b) => $this->find($b['reference']), $bills), 'skipped' => $skipped];
    }

    /** Returns a bill awaiting approval to its preparer, with the reason on record. */
    public function reject(string $no, string $reason, int $actorId): array
    {
        $b = $this->header($no);
        if ($b['status'] !== 'pending_approval') {
            throw new RuleViolation('Only bills awaiting approval can be rejected. ' . $no . ' is ' . strtolower(self::label($b['status'], self::STATUS_LABELS)) . '.');
        }
        if (trim($reason) === '') {
            throw new RuleViolation('Say why the bill is rejected — the preparer needs to know what to correct.');
        }

        $this->transaction(function () use ($b, $reason, $actorId) {
            $this->db->table('bills')->where('id', $b['id'])->update([
                'status' => 'rejected', 'rejected_reason' => trim($reason), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('bill', (int) $b['id'], $b['reference'], 'Rejected by ' . $this->lookups->shortName($actorId) . ' — ' . trim($reason), $actorId, 'history', (int) $b['entity_id']);
        });

        return $this->find($no);
    }

    /**
     * Adds approved bills to the next payment run: the earliest draft run from today
     * on, or a new run on the coming Friday.
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>, run: array{ref: string, date: string}}
     */
    public function schedule(array $nos, int $actorId): array
    {
        [$bills, $skipped] = $this->eligible($nos, 'Only approved bills can be scheduled, and nothing in the selection is approved.', static fn ($b) => $b['status'] === 'approved'
            ? null
            : 'Only approved bills can be scheduled. ' . $b['reference'] . ' is ' . strtolower(self::label($b['status'], self::STATUS_LABELS)) . '.');

        $run = $this->transaction(function () use ($bills, $actorId) {
            $now = Clock::timestamp();
            $run = $this->row("SELECT * FROM {payment_runs} WHERE entity_id = ? AND status = 'draft' AND run_date >= ? ORDER BY run_date LIMIT 1", [$this->lookups->entityId(), Clock::date()]);
            if ($run === null) {
                $date = Clock::today()->modify('next friday')->format('Y-m-d');
                $id = $this->insert('payment_runs', [
                    'entity_id' => $this->lookups->entityId(), 'reference' => 'RUN-' . $date, 'run_date' => $date,
                    'bank_account_id' => $this->mainBank()['id'], 'total' => 0, 'status' => 'draft', 'prepared_by' => $actorId, 'created_at' => $now,
                ]);
                $run = $this->row('SELECT * FROM {payment_runs} WHERE id = ?', [$id]);
            }

            $label = 'Scheduled in ' . self::dm($run['run_date']) . ' payment run by ' . $this->lookups->shortName($actorId);
            foreach ($bills as $b) {
                $this->db->table('bills')->where('id', $b['id'])->update(['status' => 'scheduled', 'payment_run_id' => $run['id'], 'updated_at' => $now]);
                $this->audit('bill', (int) $b['id'], $b['reference'], $label, $actorId, 'history', (int) $b['entity_id']);
            }
            $this->retotal((int) $run['id']);

            return $run;
        });

        return ['done' => array_map(fn ($b) => $this->find($b['reference']), $bills), 'skipped' => $skipped,
            'run' => ['ref' => $run['reference'], 'date' => self::dm($run['run_date'])]];
    }

    /**
     * Releases payment of approved or scheduled bills, net of withholding tax, as one
     * payment run per account the money leaves from. Each run posts one entry that
     * clears trade payables against that account. A bill's preparer cannot release
     * its payment. The approval rules decide who may release a run of its value; a run
     * that escalates to an authority outside the system needs `$authorityRef`.
     *
     * @param list<string> $nos
     * @return array{done: list<array>, skipped: list<array{no: string, reason: string}>, runs: list<array{ref: string, journal: string, total: float, count: int, account: string}>}
     */
    public function pay(array $nos, int $actorId, ?string $authorityRef = null): array
    {
        $who = $this->lookups->shortName($actorId);
        [$bills, $skipped] = $this->eligible($nos, 'Bills must be approved before they can be paid, and nothing in the selection is ready for you to release.', function ($b) use ($actorId, $who) {
            if (!in_array($b['status'], ['approved', 'scheduled'], true)) {
                return 'Bills must be approved before they can be paid. ' . $b['reference'] . ' is ' . strtolower(self::label($b['status'], self::STATUS_LABELS)) . '.';
            }

            return (int) $b['prepared_by'] === $actorId ? $who . ' captured ' . $b['reference'] . ' and cannot also release its payment.' : null;
        });

        $byBank = [];
        foreach ($bills as $b) {
            $byBank[(int) $b['pay_from_bank_account_id']][] = $b;
        }
        $banks = array_column($this->lookups->bankAccounts(), null, 'id');
        $policy = new ApprovalPolicy();
        $authorityRef = trim((string) $authorityRef) ?: null;
        foreach ($byBank as $bankId => $group) {
            $total = array_sum(array_map(static fn ($b) => (float) $b['total'] - (float) $b['wht_amount'], $group));
            $policy->check('payment_run', $total, $actorId, count($byBank) === 1 ? 'This payment run' : 'The ' . $banks[$bankId]['short_name'] . ' run', $authorityRef);
        }

        $runs = $this->transaction(function () use ($byBank, $banks, $actorId, $who, $policy, $authorityRef) {
            $journals = new JournalRepository();
            $today = Clock::date();
            $emptied = [];
            $runs = [];

            foreach ($byBank as $bankId => $group) {
                $now = Clock::timestamp();
                $bank = $banks[$bankId];
                $total = array_sum(array_map(static fn ($b) => (float) $b['total'] - (float) $b['wht_amount'], $group));
                $ref = $this->nextRunReference($today);
                $runId = $this->insert('payment_runs', [
                    'entity_id' => $this->lookups->entityId(), 'reference' => $ref, 'run_date' => $today, 'bank_account_id' => $bankId,
                    'total' => $total, 'status' => 'paid', 'prepared_by' => $actorId, 'paid_at' => $now, 'created_at' => $now,
                    'authority_ref' => $policy->needsAuthority('payment_run', $total) ? $authorityRef : null,
                ]);

                $posting = [];
                foreach ($group as $b) {
                    $first = $this->row('SELECT * FROM {bill_lines} WHERE bill_id = ? ORDER BY line_no LIMIT 1', [$b['id']]);
                    $net = (float) $b['total'] - (float) $b['wht_amount'];
                    $posting[] = $this->posting(self::PAYABLE, $first, 'Paid ' . $b['supplier_name'] . ' · ' . $b['reference'], $net, 0);
                    $posting[] = $this->posting($bank['code'], $first, $bank['short_name'] . ' — ' . $ref . ' · ' . $b['reference'], 0, $net);
                }
                $journal = $journals->postFromSource([
                    'date' => $today, 'sourceType' => 'payment_run', 'sourceId' => $runId, 'docRef' => $ref, 'series' => 'PV',
                    'narration' => 'Payment run ' . $ref . ' — ' . count($group) . (count($group) === 1 ? ' supplier' : ' suppliers'),
                    'memo' => 'Payment run ' . $ref . ' covering ' . implode(', ', array_column($group, 'reference')) . '.',
                ], $posting, $actorId, null, 'Raised by Payables on release of payment run ' . $ref . ' by ' . $who
                    . ($policy->needsAuthority('payment_run', $total) ? ' on authority ' . $authorityRef : ''));
                $journalId = $this->journalId($journal);
                $this->db->table('payment_runs')->where('id', $runId)->update(['journal_id' => $journalId]);

                foreach ($group as $b) {
                    if ($b['payment_run_id'] !== null) {
                        $emptied[(int) $b['payment_run_id']] = true;
                    }
                    $this->insert('payments', [
                        'entity_id' => $b['entity_id'], 'payment_run_id' => $runId, 'bill_id' => $b['id'], 'bank_account_id' => $bankId,
                        'paid_on' => $today, 'method' => $b['payment_method'] ?? ($bank['kind'] === 'mobile_money' ? 'mpesa' : 'eft'),
                        'amount' => (float) $b['total'] - (float) $b['wht_amount'], 'wht_amount' => $b['wht_amount'],
                        'reference' => $ref, 'journal_id' => $journalId, 'created_by' => $actorId, 'created_at' => $now,
                    ]);
                    $this->db->table('bills')->where('id', $b['id'])->update(['status' => 'paid', 'payment_run_id' => $runId, 'updated_at' => $now]);
                    $this->audit('bill', (int) $b['id'], $b['reference'], 'Paid by ' . self::methodLabel($bank, $b['payment_method']) . ' in payment run ' . $ref . ', released by ' . $who
                        . ($policy->needsAuthority('payment_run', $total) ? ' on authority ' . $authorityRef : ''), $actorId, 'history', (int) $b['entity_id']);
                }

                $runs[] = ['ref' => $ref, 'journal' => $journal, 'total' => self::num($total), 'count' => count($group), 'account' => $bank['short_name']];
            }

            // A scheduled run the bills were taken out of keeps only what is left in it.
            foreach (array_keys($emptied) as $runId) {
                $this->retotal($runId);
            }

            return $runs;
        });

        return ['done' => array_map(fn ($b) => $this->find($b['reference']), $bills), 'skipped' => $skipped, 'runs' => $runs];
    }

    /** Changes how an unpaid bill will be paid. */
    public function setMethod(string $no, string $label, int $actorId): array
    {
        $b = $this->header($no);
        if (in_array($b['status'], ['paid', 'rejected'], true)) {
            throw new RuleViolation($no . ' is ' . strtolower(self::label($b['status'])) . '; its payment method can no longer change.');
        }
        $method = array_values(array_filter($this->methodOptions(), static fn ($o) => $o['label'] === $label))[0]
            ?? throw new RuleViolation($label . ' is not a payment method.');

        $this->transaction(function () use ($b, $method, $actorId) {
            $this->db->table('bills')->where('id', $b['id'])->update([
                'pay_from_bank_account_id' => $method['bankId'], 'payment_method' => $method['method'], 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('bill', (int) $b['id'], $b['reference'], 'Payment method changed to ' . $method['label'] . ' by ' . $this->lookups->shortName($actorId), $actorId, 'history', (int) $b['entity_id']);
        });

        return $this->find($no);
    }

    /**
     * Pays the withholding tax held on approved bills over to KRA from the main
     * shilling account, once a month. The entry clears 2240 in each fund and
     * programme the tax was held in.
     *
     * @return array{ref: string, journal: string, amount: float, bills: int, period: string}
     */
    public function remitWht(int $actorId, ?string $authorityRef = null): array
    {
        $period = $this->currentPeriod() ?? throw new RuleViolation('No accounting period covers today, so withholding tax cannot be remitted.');
        $done = $this->value('SELECT reference FROM {wht_remittances} WHERE period_id = ?', [$period['id']]);
        if ($done !== null) {
            throw new RuleViolation('Withholding tax for ' . $period['name'] . ' has already been remitted to KRA (' . $done . ').');
        }

        $bills = $this->rows(
            "SELECT b.id, b.reference, b.entity_id, b.wht_amount, l.fund_id, l.programme_id, l.grant_id
             FROM {bills} b JOIN {bill_lines} l ON l.bill_id = b.id AND l.line_no = (SELECT MIN(line_no) FROM {bill_lines} x WHERE x.bill_id = b.id)
             WHERE b.wht_amount > 0 AND b.wht_remittance_id IS NULL AND b.status IN ('approved', 'scheduled', 'paid') ORDER BY b.reference"
        );
        $amount = array_sum(array_map(static fn ($b) => (float) $b['wht_amount'], $bills));
        if ($amount <= 0) {
            throw new RuleViolation('Nothing is held on ' . self::WHT_PAYABLE . ' — there is no withholding tax to remit.');
        }

        $bank = $this->mainBank();
        $who = $this->lookups->shortName($actorId);
        $policy = new ApprovalPolicy();
        $authorityRef = trim((string) $authorityRef) ?: null;
        $policy->check('payment_run', $amount, $actorId, 'The withholding tax remittance', $authorityRef);

        return $this->transaction(function () use ($period, $bills, $amount, $bank, $actorId, $who, $policy, $authorityRef) {
            $now = Clock::timestamp();
            $today = Clock::date();
            $ref = 'WHT-' . substr($period['starts_on'], 0, 7);
            $id = $this->insert('wht_remittances', [
                'entity_id' => $this->lookups->entityId(), 'reference' => $ref, 'period_id' => $period['id'], 'remitted_on' => $today,
                'bank_account_id' => $bank['id'], 'amount' => $amount, 'remitted_by' => $actorId, 'created_at' => $now,
                'authority_ref' => $policy->needsAuthority('payment_run', $amount) ? $authorityRef : null,
            ]);

            $groups = [];
            foreach ($bills as $b) {
                $key = $b['fund_id'] . ':' . $b['programme_id'] . ':' . $b['grant_id'];
                $groups[$key] ??= ['segments' => $b, 'amount' => 0.0];
                $groups[$key]['amount'] += (float) $b['wht_amount'];
            }
            $posting = [];
            foreach ($groups as $g) {
                $posting[] = $this->posting(self::WHT_PAYABLE, $g['segments'], 'WHT remitted to KRA iTax', $g['amount'], 0);
                $posting[] = $this->posting($bank['code'], $g['segments'], 'Paid from ' . $bank['short_name'], 0, $g['amount']);
            }

            $journal = (new JournalRepository())->postFromSource([
                'date' => $today, 'sourceType' => 'wht_remittance', 'sourceId' => $id, 'docRef' => $ref, 'series' => 'PV',
                'narration' => 'Withholding tax remitted to KRA — ' . $period['name'],
                'memo' => 'Withholding tax held on ' . count($bills) . ' supplier ' . (count($bills) === 1 ? 'invoice' : 'invoices') . ' remitted to KRA by the 20th.',
            ], $posting, $actorId, null, 'Raised by Payables on remittance ' . $ref . ' by ' . $who);

            $this->db->table('wht_remittances')->where('id', $id)->update(['journal_id' => $this->journalId($journal)]);
            foreach ($bills as $b) {
                $this->db->table('bills')->where('id', $b['id'])->update(['wht_remittance_id' => $id]);
                $this->audit('bill', (int) $b['id'], $b['reference'], 'Withholding tax of ' . Prototype::fmt((float) $b['wht_amount']) . ' remitted to KRA in ' . $ref, $actorId, 'history', (int) $b['entity_id']);
            }

            return ['ref' => $ref, 'journal' => $journal, 'amount' => self::num($amount), 'bills' => count($bills), 'period' => $period['name']];
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** "EFT — KCB Current (KES)", "M-Pesa B2B paybill", "Cheque", "SWIFT — Equity account". */
    private static function methodLabel(array $bank, ?string $method): string
    {
        return $method === 'cheque' ? 'Cheque' : Lookups::paymentMethod($bank);
    }

    private function header(string $no): array
    {
        return $this->row('SELECT * FROM {bills} WHERE reference = ?', [$no]) ?? throw new RuleViolation('Bill ' . $no . ' not found.');
    }

    /**
     * Splits the bills named into those an action applies to and those it skips, with
     * why. When none qualifies the action is refused: for one bill with its reason, for
     * a selection with `$noneInSelection`.
     *
     * @param callable(array): ?string $refusal
     * @return array{0: list<array>, 1: list<array{no: string, reason: string}>}
     */
    private function eligible(array $nos, string $noneInSelection, callable $refusal): array
    {
        $nos = array_values(array_unique(array_filter(array_map('strval', $nos))));
        if ($nos === []) {
            throw new RuleViolation('Select at least one bill.');
        }

        $bills = $skipped = [];
        foreach ($nos as $no) {
            $b = $this->row(
                'SELECT b.*, s.name AS supplier_name, s.category FROM {bills} b JOIN {suppliers} s ON s.id = b.supplier_id WHERE b.reference = ?',
                [$no]
            );
            $reason = $b === null ? 'Bill ' . $no . ' not found.' : $refusal($b);
            if ($reason === null) {
                $bills[] = $b;
            } else {
                $skipped[] = ['no' => $no, 'reason' => $reason];
            }
        }
        if ($bills === []) {
            throw new RuleViolation(count($nos) === 1 ? $skipped[0]['reason'] : $noneInSelection);
        }

        return [$bills, $skipped];
    }

    private function posting(string $code, array $segments, string $desc, float $dr, float $cr): array
    {
        return [
            'code' => $code, 'fund_id' => (int) $segments['fund_id'], 'programme_id' => (int) $segments['programme_id'],
            'grant_id' => $segments['grant_id'] === null ? null : (int) $segments['grant_id'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
    }

    private function accountCode(int $accountId): string
    {
        return $this->lookups->accountById($accountId)['code'];
    }

    private function journalId(string $ref): int
    {
        return (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$ref]);
    }

    /** The period that holds today. */
    private function currentPeriod(): ?array
    {
        $today = Clock::date();
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $today && $today <= $p['ends_on']) {
                return $p;
            }
        }

        return null;
    }

    /** A bill's cost posts on its invoice date where that month is still open, otherwise today. */
    private function openDate(string $invoiceDate): string
    {
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $invoiceDate && $invoiceDate <= $p['ends_on']) {
                return $p['status'] === 'open' ? $invoiceDate : Clock::date();
            }
        }

        return Clock::date();
    }

    /** BILL-0453: one more than the highest bill number issued. */
    private function nextBillReference(): string
    {
        $max = 0;
        foreach ($this->rows("SELECT reference FROM {bills} WHERE reference LIKE 'BILL-%'") as $r) {
            $max = max($max, (int) substr($r['reference'], 5));
        }

        return 'BILL-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** PR-26-0088: payment runs released, numbered through the year. */
    private function nextRunReference(string $date): string
    {
        $stem = 'PR-' . substr($date, 2, 2) . '-';
        $max = self::RUN_FLOOR;
        foreach ($this->rows('SELECT reference FROM {payment_runs} WHERE reference LIKE ?', [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** A draft run's total is what its scheduled bills will pay; an emptied draft run is removed. */
    private function retotal(int $runId): void
    {
        $run = $this->row('SELECT * FROM {payment_runs} WHERE id = ?', [$runId]);
        if ($run === null || $run['status'] !== 'draft') {
            return;
        }
        $total = (float) $this->value("SELECT COALESCE(SUM(total - wht_amount), 0) FROM {bills} WHERE payment_run_id = ? AND status = 'scheduled'", [$runId]);
        if ($total <= 0) {
            $this->db->table('payment_runs')->where('id', $runId)->delete();

            return;
        }
        $this->db->table('payment_runs')->where('id', $runId)->update(['total' => $total, 'updated_at' => Clock::timestamp()]);
    }
}
