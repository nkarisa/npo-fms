<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;
use Config\Documents;

/**
 * Requisition → quotation → purchase order → goods received → bill.
 *
 * A requisition is coded to an approved budget line. Its budget position is read
 * from v_budget_availability: available = budget − actual − committed, where
 * committed is the unbilled value of open purchase orders on the line. Approval is
 * blocked when the requisition exceeds what is available, and the requester cannot
 * approve. A purchase order above the three-quote threshold needs three quotations
 * or a single-source justification, and a supplier whose pre-qualification is
 * current. Recording goods received releases the commitment and accrues the cost
 * (Dr the requisition's account, Cr 2120 accrued expenses); the supplier bill
 * raised from it clears the accrual when it is approved in Payables.
 */
final class ProcurementRepository extends Repository
{
    public const STATUS_LABELS = ['pending_approval' => 'Awaiting approval', 'rfq_issued' => 'RFQ issued', 'po_raised' => 'PO raised'];

    /** Above this value three quotations are required before a purchase order can be raised. */
    public const QUOTE_THRESHOLD = 500000;

    public const OPEN = ['Draft', 'Awaiting approval', 'Approved', 'RFQ issued', 'PO raised'];

    public const UNRESTRICTED = 'Unrestricted — own income';

    private const ACCRUED = '2120';

    /** Supplier register statuses, as stored → as the form offers them. */
    public const SUPPLIER_STATUSES = ['not_prequalified' => 'Not pre-qualified', 'prequalified' => 'Pre-qualified', 'blocked' => 'Blocked'];

    public const RATINGS = ['A', 'B', 'C'];

    /** A KRA PIN: P0, eight digits and a letter. */
    private const PIN_PATTERN = '/^P0\d{8}[A-Z]$/';

    /** Days before pre-qualification lapses that a supplier shows as expiring. */
    private const EXPIRY_WARNING_DAYS = 30;

    /** Where quotation documents are kept, under writable/uploads. */
    private const ATTACHMENT_DIR = 'quotations';

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function requisitions(): array
    {
        return $this->cached('requisitions', function () {
            $lines = [];
            foreach ($this->rows('SELECT * FROM {requisition_lines} ORDER BY requisition_id, line_no') as $l) {
                $lines[(int) $l['requisition_id']][] = [
                    'desc' => $l['description'], 'qty' => self::num($l['quantity']), 'unit' => self::num($l['unit_cost']), 'amount' => self::num($l['amount']),
                ];
            }

            $documents = [];
            foreach ($this->rows("SELECT id, object_id, filename, size_bytes FROM {attachments} WHERE object_type = 'quotation' ORDER BY id") as $a) {
                $documents[(int) $a['object_id']] = ['id' => (int) $a['id'], 'name' => $a['filename'], 'size' => self::size((int) $a['size_bytes'])];
            }
            $quotes = [];
            foreach ($this->rows('SELECT q.*, s.name AS supplier FROM {quotations} q JOIN {suppliers} s ON s.id = q.supplier_id ORDER BY q.requisition_id, q.id') as $q) {
                $doc = $documents[(int) $q['id']] ?? null;
                $quotes[(int) $q['requisition_id']][] = [
                    'id' => (int) $q['id'], 'supplier' => $q['supplier'], 'amount' => self::num($q['amount']), 'note' => $q['note'] ?? '',
                    'chosen' => (bool) $q['is_selected'], 'file' => $doc['name'] ?? '', 'document' => $doc,
                ];
            }

            $orders = array_column($this->rows(
                'SELECT po.*, s.name AS supplier, g.id AS grn_id, g.reference AS grn_ref, g.received_on, g.received_by, g.received_by_name, g.note AS grn_note,
                        gj.reference AS grn_journal, b.reference AS bill_ref, b.status AS bill_status
                 FROM {purchase_orders} po JOIN {suppliers} s ON s.id = po.supplier_id
                 LEFT JOIN {goods_received_notes} g ON g.purchase_order_id = po.id LEFT JOIN {journals} gj ON gj.id = g.journal_id
                 LEFT JOIN {bills} b ON b.purchase_order_id = po.id'
            ), null, 'requisition_id');

            $positions = (new PayablesRepository())->budgetPositions();
            $trails    = $this->trails('requisition');

            return array_map(function ($r) use ($lines, $quotes, $orders, $positions, $trails) {
                $id       = (int) $r['id'];
                $po       = $orders[$id] ?? null;
                $position = $positions[$r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id']] ?? ['budget' => 0.0, 'actual' => 0.0, 'committed' => 0.0];
                $chosen   = current(array_filter($quotes[$id] ?? [], static fn ($q) => $q['chosen'])) ?: null;

                $req = [
                    'no'          => $r['reference'],
                    'title'       => $r['title'],
                    'requestedBy' => (int) $r['requested_by'],
                    'requester'   => $this->requester($r, $trails[$id] ?? []),
                    'program'     => $this->lookups->programmeName((int) $r['programme_id']),
                    'fund'        => self::FUND_GROUPS[$this->lookups->funds()[$r['fund_id']]['ledger_group']],
                    'grant'       => $this->grantLabel($r),
                    'code'        => $r['account_code'],
                    'account'     => $r['account_name'],
                    'raised'      => self::dm($r['raised_on']),
                    'needBy'      => self::dm($r['needed_by']),
                    'amount'      => self::num($r['estimated_amount']),
                    'status'      => self::label($r['status'], self::STATUS_LABELS),
                    'budget'      => self::num($position['budget']),
                    // Everything committed on the budget line, this requisition's order included.
                    'committed'   => self::num($position['committed']),
                    'spent'       => self::num($position['actual']),
                    'justification' => $r['justification'] ?? '',
                    'singleSource' => $r['single_source_reason'] ?? '',
                    'rejectedReason' => $r['rejected_reason'] ?? '',
                    'supplier'    => $po['supplier'] ?? ($r['preferred_supplier'] ?? $chosen['supplier'] ?? ''),
                    'lines'       => $lines[$id] ?? [],
                    'quotes'      => $quotes[$id] ?? [],
                    'trail'       => $trails[$id] ?? [],
                ];

                if ($po !== null) {
                    $req += ['po' => $po['reference'], 'poDate' => self::dm($po['issued_on']), 'expected' => self::dm($po['expected_on']),
                        'poStatus' => $po['status']];
                }
                if ($po !== null && $po['grn_ref'] !== null) {
                    $req += [
                        'grn' => $po['grn_ref'], 'grnDate' => self::dm($po['received_on']),
                        'receivedBy' => $po['received_by_name'] ?? $this->lookups->shortName((int) $po['received_by']),
                        'grnNote' => $po['grn_note'] ?? '', 'grnJournal' => $po['grn_journal'],
                    ];
                }
                if ($po !== null && $po['bill_ref'] !== null) {
                    $req += ['bill' => $po['bill_ref'], 'billStatus' => self::label($po['bill_status'], ['pending_approval' => 'Awaiting approval'])];
                }

                return $req;
            }, $this->rows(
                'SELECT r.*, a.code AS account_code, a.name AS account_name FROM {requisitions} r JOIN {accounts} a ON a.id = r.account_id
                 ORDER BY r.raised_on DESC, r.reference DESC'
            ));
        });
    }

    public function find(string $no): ?array
    {
        foreach ($this->requisitions() as $r) {
            if ($r['no'] === $no) {
                return $r;
            }
        }

        return null;
    }

    /** Budget still spendable on the requisition's line: approved less actual less committed. */
    public static function available(array $p): float
    {
        return $p['budget'] - $p['spent'] - $p['committed'];
    }

    public static function isOverBudget(array $p): bool
    {
        return $p['amount'] > self::available($p);
    }

    public static function needsQuotes(array $p): bool
    {
        return $p['amount'] > self::QUOTE_THRESHOLD && self::quotesOnFile($p) < 3;
    }

    /**
     * The quotations that count towards the three: those with their document
     * attached, while Config\Documents::$requireQuotationDocuments holds.
     */
    public static function quotesOnFile(array $p): int
    {
        return config(Documents::class)->requireQuotationDocuments
            ? count(array_filter($p['quotes'], static fn ($q) => ($q['document'] ?? null) !== null))
            : count($p['quotes']);
    }

    /**
     * Whether a control can still stop the requisition. Once the order exists its
     * own amount sits inside `committed`, so testing it against what is left would
     * count that money twice.
     */
    public static function stillLive(array $p): bool
    {
        return empty($p['po']) && !in_array($p['status'], ['Rejected', 'Closed'], true);
    }

    /** "G. Wambui · Programme Officer"; a requester without an account is named from the history. */
    private function requester(array $r, array $trail): string
    {
        $user = $this->lookups->users()[$r['requested_by']] ?? null;
        if ($user !== null && $user['email'] !== 'data-migration@system.invalid') {
            return $user['short_name'] . ' · ' . $this->lookups->roleOf((int) $user['id']);
        }

        foreach ($trail as $entry) {
            if (preg_match('/raised by ([A-Z]\. [A-Za-z\'-]+)/', $entry['what'], $m) === 1) {
                return $m[1];
            }
        }

        return '—';
    }

    private function grantLabel(array $r): string
    {
        if ($r['grant_id'] !== null) {
            return $this->lookups->grants()[(int) $r['grant_id']]['short_name'];
        }

        return $this->lookups->funds()[$r['fund_id']]['ledger_group'] === 'general' ? self::UNRESTRICTED : 'Unassigned';
    }

    /** Every supplier, those with a pre-qualification record first, with their spend and open orders. */
    public function suppliers(): array
    {
        return $this->cached('suppliers', fn () => array_map(fn ($s) => [
            'id'           => (int) $s['id'],
            'name'         => $s['name'],
            'pin'          => $s['kra_pin'] ?? '—',
            'category'     => $s['category'],
            'prequalUntil' => self::dmy($s['prequalified_until']),
            'rating'       => $s['rating'] ?? '—',
            'wht'          => $s['wht_rate_pct'] === null ? '—' : self::num($s['wht_rate_pct']) . '% ' . ($s['wht_basis'] ?? ''),
            'spend'        => self::num($s['spend']),
            'openPos'      => (int) $s['open_pos'],
            'status'       => self::supplierStatus($s),
        ], $this->rows(
            "SELECT s.*, (SELECT COALESCE(SUM(b.subtotal), 0) FROM {bills} b WHERE b.supplier_id = s.id AND b.status IN ('approved', 'scheduled', 'paid')) AS spend,
                    (SELECT COUNT(*) FROM {purchase_orders} po WHERE po.supplier_id = s.id AND po.status IN ('open', 'part_received', 'received')) AS open_pos
             FROM {suppliers} s ORDER BY s.prequalified_until IS NULL, s.name"
        )));
    }

    /** Pre-qualified, Expiring, Lapsed, Not pre-qualified or Blocked, as of today. */
    public static function supplierStatus(array $s): string
    {
        if ($s['status'] !== 'prequalified') {
            return $s['status'] === 'blocked' ? 'Blocked' : 'Not pre-qualified';
        }

        $days = Clock::daysUntil($s['prequalified_until']);

        return match (true) {
            $days === null                     => 'Pre-qualified',
            $days < 0                          => 'Lapsed',
            $days <= self::EXPIRY_WARNING_DAYS => 'Expiring',
            default                            => 'Pre-qualified',
        };
    }

    /** One supplier as the register form edits it, or null when there is no such supplier. */
    public function supplier(int $id): ?array
    {
        $s = $this->row('SELECT * FROM {suppliers} WHERE id = ?', [$id]);
        if ($s === null) {
            return null;
        }

        return [
            'id'             => (int) $s['id'],
            'name'           => $s['name'],
            'pin'            => $s['kra_pin'] ?? '',
            'category'       => $s['category'],
            'status'         => $s['status'],
            'label'          => self::supplierStatus($s),
            'prequalUntil'   => $s['prequalified_until'] ?? '',
            'rating'         => $s['rating'] ?? '',
            'whtRate'        => $s['wht_rate_pct'] === null ? '' : (string) self::num($s['wht_rate_pct']),
            'whtBasis'       => $s['wht_basis'] ?? '',
            'paymentDetails' => $s['payment_details'] ?? '',
            'billed'         => (int) $this->value('SELECT COUNT(*) FROM {bills} WHERE supplier_id = ?', [$id]) > 0,
            'history'        => $this->trails('supplier', 'd M Y')[$id] ?? [],
        ];
    }

    /** What the supplier form offers: the categories already in use and the spend categories that set withholding. */
    public function supplierOptions(): array
    {
        $spend = (new PayablesRepository())->categories();
        $used  = array_column($this->rows('SELECT DISTINCT category FROM {suppliers} ORDER BY category'), 'category');

        return [
            'categories' => array_values(array_unique(array_merge(array_column($spend, 'name'), $used))),
            'withholding' => array_column($spend, 'wht', 'name'),
            'ratings'    => self::RATINGS,
            'statuses'   => self::SUPPLIER_STATUSES,
        ];
    }

    /**
     * What the requisition form offers: the approved budget lines a requisition can
     * be coded to, grouped by award and programme, with what is left on each, and the
     * people a requisition can be raised for.
     */
    public function formOptions(): array
    {
        $lines = array_map(function ($l) {
            $grant = $l['grant_id'] === null ? null : $this->lookups->grants()[(int) $l['grant_id']];

            return [
                'id' => (int) $l['budget_line_id'], 'code' => $l['code'], 'name' => $l['account_name'],
                'award' => $grant['short_name'] ?? ($l['ledger_group'] === 'general' ? self::UNRESTRICTED : 'Unassigned'),
                'funder' => $grant === null ? 'ELOG' : $this->lookups->funders()[(int) $grant['funder_id']]['name'],
                'fund' => self::FUND_GROUPS[$l['ledger_group']], 'program' => $this->lookups->programmeName((int) $l['programme_id']),
                'budget' => self::num($l['budget']), 'spent' => self::num($l['actual']), 'committed' => self::num($l['committed']),
                'available' => self::num((float) $l['budget'] - (float) $l['actual'] - (float) $l['committed']),
            ];
        }, $this->rows(
            "SELECT v.*, a.code, a.name AS account_name, f.ledger_group FROM {v_budget_availability} v
             JOIN {budget_versions} bv ON bv.id = v.budget_version_id JOIN {accounts} a ON a.id = v.account_id JOIN {funds} f ON f.id = v.fund_id
             WHERE bv.status = 'approved' ORDER BY a.code"
        ));

        $people = array_map(static fn ($u) => ['email' => $u['email'], 'label' => $u['short'] . ' · ' . $u['role']],
            array_values(array_filter((new UserRepository())->actors(), static fn ($u) => in_array('requisition.raise', $u['permissions'], true))));

        return ['budgetLines' => $lines, 'people' => $people, 'suppliers' => array_column($this->suppliers(), 'name'), 'threshold' => self::QUOTE_THRESHOLD];
    }

    /** A quotation document, for download. */
    public function document(string $no, int $attachmentId): ?array
    {
        $row = $this->row(
            "SELECT a.* FROM {attachments} a JOIN {quotations} q ON q.id = a.object_id JOIN {requisitions} r ON r.id = q.requisition_id
             WHERE a.object_type = 'quotation' AND a.id = ? AND r.reference = ?",
            [$attachmentId, $no]
        );

        return $row;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Raises a requisition as a draft, coded to an approved budget line, with its
     * lines and any quotations received. A quotation from a supplier not yet on the
     * register adds the supplier, not pre-qualified.
     *
     * @param array{title: string, budgetLine: int|string, requester?: string, needBy?: string, supplier?: string, justification?: string,
     *              lines: list<array{desc: string, qty: float|string, unit: float|string}>,
     *              quotes?: list<array{supplier: string, amount: float|string, note?: string, chosen?: bool, file?: int|string}>} $f
     * @param list<array{path: string, name: string, size: int, mime: string}> $files quotation documents, referenced by index from a quote's `file`
     */
    public function create(array $f, int $actorId, array $files = []): array
    {
        $num = static fn ($v) => (float) preg_replace('/[^0-9.]/', '', (string) $v);
        $title = trim((string) ($f['title'] ?? ''));
        $line = current(array_filter($this->formOptions()['budgetLines'], static fn ($l) => $l['id'] === (int) ($f['budgetLine'] ?? 0))) ?: null;
        $lines = array_values(array_filter(array_map(static fn ($l) => [
            'desc' => trim((string) ($l['desc'] ?? '')), 'qty' => $num($l['qty'] ?? 1) ?: 1.0, 'unit' => round($num($l['unit'] ?? 0), 2),
        ], (array) ($f['lines'] ?? [])), static fn ($l) => $l['desc'] !== '' && $l['unit'] > 0));
        $quotes = array_values(array_filter(array_map(static fn ($q) => [
            'supplier' => trim((string) ($q['supplier'] ?? '')), 'amount' => round($num($q['amount'] ?? 0), 2), 'note' => trim((string) ($q['note'] ?? '')),
            'chosen' => !empty($q['chosen']), 'file' => isset($q['file']) && $q['file'] !== '' && $q['file'] !== null ? (int) $q['file'] : null,
        ], (array) ($f['quotes'] ?? [])), static fn ($q) => $q['supplier'] !== ''));
        $requester = trim((string) ($f['requester'] ?? '')) === '' ? $actorId : $this->lookups->userId((string) $f['requester']);
        $needBy = trim((string) ($f['needBy'] ?? ''));

        $error = match (true) {
            $title === ''    => 'Give the requisition a description.',
            $line === null   => 'Choose the budget line the purchase is charged to.',
            $lines === []    => 'Add at least one requisition line with a quantity and unit price.',
            $requester === null => 'The requester must be someone with an account who can raise requisitions.',
            $needBy !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $needBy) === false => 'Enter the date the purchase is needed by.',
            array_filter($quotes, static fn ($q) => $q['amount'] <= 0) !== [] => 'Every quotation needs the amount quoted.',
            count(array_unique(array_map('strtolower', array_column($quotes, 'supplier')))) < count($quotes) => 'Record one quotation per supplier.',
            default          => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }
        if ($quotes !== [] && array_filter($quotes, static fn ($q) => $q['chosen']) === []) {
            $quotes[0]['chosen'] = true;
        }
        $budgetLine = $this->row('SELECT * FROM {budget_lines} WHERE id = ?', [$line['id']]);
        $who = $this->lookups->shortName($actorId);

        $stored = [];
        try {
            $no = $this->transaction(function () use ($f, $title, $budgetLine, $lines, $quotes, $requester, $needBy, $files, $actorId, $who, &$stored) {
                $now = Clock::timestamp();
                $today = Clock::date();
                $no = $this->nextReference('requisitions', 'REQ-' . Clock::today()->format('y') . '-');
                $id = $this->insert('requisitions', [
                    'entity_id' => $this->lookups->entityId(), 'reference' => $no, 'title' => mb_substr($title, 0, 255), 'requested_by' => $requester,
                    'programme_id' => $budgetLine['programme_id'], 'fund_id' => $budgetLine['fund_id'], 'grant_id' => $budgetLine['grant_id'],
                    'account_id' => $budgetLine['account_id'], 'raised_on' => $today, 'needed_by' => $needBy !== '' ? $needBy : null,
                    'estimated_amount' => array_sum(array_map(static fn ($l) => round($l['qty'] * $l['unit'], 2), $lines)), 'status' => 'draft',
                    'justification' => trim((string) ($f['justification'] ?? '')) ?: null,
                    'preferred_supplier' => mb_substr(trim((string) ($f['supplier'] ?? '')), 0, 120) ?: null, 'created_at' => $now,
                ]);
                foreach ($lines as $i => $l) {
                    $this->insert('requisition_lines', [
                        'requisition_id' => $id, 'line_no' => $i + 1, 'description' => mb_substr($l['desc'], 0, 255), 'quantity' => $l['qty'],
                        'unit_cost' => $l['unit'], 'amount' => round($l['qty'] * $l['unit'], 2),
                    ]);
                }
                foreach ($quotes as $q) {
                    $quoteId = $this->insert('quotations', [
                        'requisition_id' => $id, 'supplier_id' => $this->supplierId($q['supplier']), 'amount' => $q['amount'],
                        'received_on' => $today, 'note' => $q['note'] !== '' ? $q['note'] : null, 'is_selected' => (int) $q['chosen'], 'created_at' => $now,
                    ]);
                    if ($q['file'] !== null && isset($files[$q['file']])) {
                        $stored[] = $this->storeDocument($quoteId, $files[$q['file']], $actorId);
                    }
                }

                $this->audit('requisition', $id, $no, 'Draft raised by ' . $who . ($requester !== $actorId ? ' for ' . $this->lookups->shortName($requester) : ''), $actorId);
                if ($quotes !== []) {
                    $this->audit('requisition', $id, $no, count($quotes) . ' quotation' . (count($quotes) === 1 ? '' : 's') . ' attached', $actorId);
                }
                if (trim((string) ($f['justification'] ?? '')) !== '') {
                    $this->audit('requisition', $id, $no, mb_substr('Justification: ' . trim((string) $f['justification']), 0, 255), $actorId);
                }

                return $no;
            });
        } catch (\Throwable $e) {
            (new AttachmentRepository($this->db))->removeFiles($stored);

            throw $e;
        }

        return $this->find($no);
    }

    /** Sends a draft for approval. */
    public function submit(string $no, int $actorId): array
    {
        $req = $this->header($no);
        if ($req['status'] !== 'draft') {
            throw new RuleViolation('Only a draft can be submitted. ' . $no . ' is ' . strtolower(self::label($req['status'], self::STATUS_LABELS)) . '.');
        }

        $this->transaction(function () use ($req, $actorId) {
            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'pending_approval', 'updated_at' => Clock::timestamp()]);
            $approver = $this->lookups->holderOf('Finance Manager');
            $this->audit('requisition', (int) $req['id'], $req['reference'], 'Submitted for approval to ' . $this->lookups->shortName($approver, 'the Finance Manager')
                . ' by ' . $this->lookups->shortName($actorId), $actorId);
        });

        return $this->find($no);
    }

    /**
     * Approves a requisition awaiting approval. Blocked when the requisition exceeds
     * what is available on its budget line, and for the requester.
     */
    public function approve(string $no, int $actorId): array
    {
        $req = $this->header($no);
        $current = $this->find($no);
        if ($req['status'] !== 'pending_approval') {
            throw new RuleViolation('Only a requisition awaiting approval can be approved. ' . $no . ' is ' . $current['status'] . '.');
        }
        if ((int) $req['requested_by'] === $actorId) {
            throw new RuleViolation($this->lookups->shortName($actorId) . ' raised ' . $no . ' and cannot also approve it. It needs a second approver.');
        }
        if (self::isOverBudget($current)) {
            throw new RuleViolation('Blocked on budget: ' . $current['title'] . ' asks ' . Prototype::fmt($current['amount']) . ' against account ' . $current['code']
                . ' (' . $current['program'] . ' · ' . $current['grant'] . '), which has ' . Prototype::fmt(self::available($current)) . ' available. Short by '
                . Prototype::fmt($current['amount'] - self::available($current)) . '. Raise a budget revision or reallocate before approving.');
        }

        $this->transaction(function () use ($req, $actorId) {
            $this->db->table('requisitions')->where('id', $req['id'])->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('requisition', (int) $req['id'], $req['reference'], 'Approved by ' . $this->lookups->shortName($actorId) . ' within the available budget', $actorId);
        });

        return $this->find($no);
    }

    /** Returns a requisition awaiting approval to the requester, with the reason on record. */
    public function reject(string $no, string $reason, int $actorId): array
    {
        $req = $this->header($no);
        if ($req['status'] !== 'pending_approval') {
            throw new RuleViolation('Only a requisition awaiting approval can be rejected. ' . $no . ' is ' . strtolower(self::label($req['status'], self::STATUS_LABELS)) . '.');
        }
        if ((int) $req['requested_by'] === $actorId) {
            throw new RuleViolation($this->lookups->shortName($actorId) . ' raised ' . $no . ' and cannot also decide it.');
        }
        if (trim($reason) === '') {
            throw new RuleViolation('Say why the requisition is rejected — the requester needs to know what to change.');
        }

        $this->transaction(function () use ($req, $reason, $actorId) {
            $this->db->table('requisitions')->where('id', $req['id'])->update([
                'status' => 'rejected', 'approved_by' => $actorId, 'approved_at' => Clock::timestamp(), 'rejected_reason' => trim($reason), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('requisition', (int) $req['id'], $req['reference'], mb_substr('Rejected by ' . $this->lookups->shortName($actorId) . ' — ' . trim($reason), 0, 255), $actorId);
        });

        return $this->find($no);
    }

    /** Issues a request for quotations on an approved requisition. */
    public function issueRfq(string $no, int $actorId): array
    {
        $req = $this->header($no);
        if ($req['status'] !== 'approved') {
            throw new RuleViolation('An RFQ is issued against an approved requisition. ' . $no . ' is ' . strtolower(self::label($req['status'], self::STATUS_LABELS)) . '.');
        }
        $prequalified = count(array_filter($this->suppliers(), static fn ($s) => in_array($s['status'], ['Pre-qualified', 'Expiring'], true)));

        $this->transaction(function () use ($req, $actorId, $prequalified) {
            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'rfq_issued', 'updated_at' => Clock::timestamp()]);
            $this->audit('requisition', (int) $req['id'], $req['reference'], 'RFQ issued by ' . $this->lookups->shortName($actorId)
                . ' to the ' . $prequalified . ' pre-qualified suppliers on the register', $actorId);
        });

        return $this->find($no);
    }

    /**
     * Raises the purchase order with the selected quotation's supplier (or the named
     * supplier), committing the budget. Above the threshold fewer than three
     * quotations need a single-source justification; the supplier's
     * pre-qualification must be current.
     */
    public function raisePurchaseOrder(string $no, string $supplierName, string $waiver, ?string $expected, int $actorId): array
    {
        $req = $this->header($no);
        $current = $this->find($no);
        if (!in_array($req['status'], ['approved', 'rfq_issued'], true)) {
            throw new RuleViolation('A purchase order is raised against an approved requisition. ' . $no . ' is ' . $current['status'] . '.');
        }
        $chosen = current(array_filter($current['quotes'], static fn ($q) => $q['chosen'])) ?: null;
        $supplierName = trim($supplierName) !== '' ? trim($supplierName) : ($chosen['supplier'] ?? ($req['preferred_supplier'] ?? ''));
        $waiver = trim($waiver);
        if ($supplierName === '') {
            throw new RuleViolation('Select the quotation the order is placed against, or name the supplier.');
        }
        if (self::needsQuotes($current) && $waiver === '') {
            throw new RuleViolation($no . ' is ' . Prototype::fmt($current['amount']) . ', above the ' . Prototype::fmt(self::QUOTE_THRESHOLD)
                . ' threshold, and has ' . self::quotesOnFile($current) . ' of the 3 quotations required'
                . (count($current['quotes']) > self::quotesOnFile($current) ? ' with their documents attached — a quotation counts only with the supplier\'s document' : '')
                . '. Record the missing quotations or give a single-source justification.');
        }
        $supplier = $this->row('SELECT * FROM {suppliers} WHERE LOWER(name) = ?', [strtolower($supplierName)]);
        if ($supplier === null) {
            throw new RuleViolation($supplierName . ' is not on the supplier register. Add and pre-qualify the supplier before placing an order.');
        }
        $status = self::supplierStatus($supplier);
        if (!in_array($status, ['Pre-qualified', 'Expiring'], true)) {
            throw new RuleViolation($supplier['name'] . ' is ' . strtolower($status) . ' — pre-qualification must be current before an order is placed.');
        }

        $this->transaction(function () use ($req, $supplier, $waiver, $expected, $actorId, $current) {
            $now = Clock::timestamp();
            $ref = $this->nextReference('purchase_orders', 'PO-' . Clock::today()->format('y') . '-');
            $quote = $this->value('SELECT id FROM {quotations} WHERE requisition_id = ? AND supplier_id = ?', [$req['id'], $supplier['id']]);
            // The order commits what was approved.
            $amount = (float) $req['estimated_amount'];

            $po = $this->insert('purchase_orders', [
                'entity_id' => $req['entity_id'], 'reference' => $ref, 'requisition_id' => $req['id'], 'supplier_id' => $supplier['id'],
                'quotation_id' => $quote, 'issued_on' => Clock::date(),
                'expected_on' => $expected !== null && strtotime($expected) ? date('Y-m-d', strtotime($expected)) : $req['needed_by'],
                'amount' => $amount, 'status' => 'open', 'prepared_by' => $actorId,
                // The requisition's approval authorised the order; a second person must have given it.
                'approved_by' => (int) $req['approved_by'] !== $actorId ? $req['approved_by'] : null, 'approved_at' => $req['approved_at'],
                'created_at' => $now,
            ]);

            // Each line carries the requisition's coding, so the commitment lands on its budget line.
            foreach ($this->rows('SELECT * FROM {requisition_lines} WHERE requisition_id = ? ORDER BY line_no', [$req['id']]) as $l) {
                $this->insert('purchase_order_lines', [
                    'purchase_order_id' => $po, 'requisition_line_id' => $l['id'], 'line_no' => $l['line_no'], 'account_id' => $req['account_id'],
                    'fund_id' => $req['fund_id'], 'programme_id' => $req['programme_id'], 'grant_id' => $req['grant_id'],
                    'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_cost' => $l['unit_cost'], 'amount' => $l['amount'],
                ]);
            }

            if ($quote !== null) {
                $this->db->table('quotations')->where('requisition_id', $req['id'])->update(['is_selected' => 0]);
                $this->db->table('quotations')->where('id', $quote)->update(['is_selected' => 1]);
            }

            $this->db->table('requisitions')->where('id', $req['id'])->update([
                'status' => 'po_raised', 'single_source_reason' => self::needsQuotes($current) ? $waiver : null, 'updated_at' => $now,
            ]);
            $this->audit('requisition', (int) $req['id'], $req['reference'],
                mb_substr($ref . ' issued to ' . $supplier['name'] . ' for ' . Prototype::fmt($amount) . ' by ' . $this->lookups->shortName($actorId)
                    . '; budget committed' . (self::needsQuotes($current) ? ' · single-source: ' . $waiver : ''), 0, 255), $actorId);
        });

        return $this->find($no);
    }

    /**
     * Records the goods received note: the order is received in full, the commitment
     * released, and the cost accrued against the requisition's budget line until the
     * supplier's invoice arrives.
     */
    public function receive(string $no, string $receivedBy, string $note, int $actorId): array
    {
        $req = $this->header($no);
        $po  = $this->row("SELECT * FROM {purchase_orders} WHERE requisition_id = ? AND status IN ('open', 'part_received')", [$req['id']]);
        if ($req['status'] !== 'po_raised' || $po === null) {
            throw new RuleViolation('Goods can only be received against an open purchase order. ' . $no . ' has none.');
        }
        $receivedBy = trim($receivedBy);
        if ($receivedBy === '') {
            throw new RuleViolation('A goods received note must name who took delivery.');
        }

        $who = $this->lookups->shortName($actorId);
        $this->transaction(function () use ($req, $po, $receivedBy, $note, $actorId, $who) {
            $now = Clock::timestamp();
            $ref = $this->nextReference('goods_received_notes', 'GRN-');
            $supplier = $this->value('SELECT name FROM {suppliers} WHERE id = ?', [$po['supplier_id']]);

            $grn = $this->insert('goods_received_notes', [
                'entity_id' => $req['entity_id'], 'reference' => $ref, 'purchase_order_id' => $po['id'], 'received_on' => Clock::date(),
                'received_by' => $actorId, 'received_by_name' => mb_substr($receivedBy, 0, 120),
                'note' => $note !== '' ? $note : 'Received in full against the purchase order', 'created_at' => $now,
            ]);
            $posting = [];
            foreach ($this->rows('SELECT pol.*, a.code FROM {purchase_order_lines} pol JOIN {accounts} a ON a.id = pol.account_id WHERE pol.purchase_order_id = ? ORDER BY pol.line_no', [$po['id']]) as $l) {
                $this->insert('goods_received_lines', ['goods_received_note_id' => $grn, 'purchase_order_line_id' => $l['id'], 'quantity' => $l['quantity']]);
                $segments = ['fund_id' => (int) $l['fund_id'], 'programme_id' => (int) $l['programme_id'], 'grant_id' => $l['grant_id'] === null ? null : (int) $l['grant_id']];
                $posting[] = $segments + ['code' => $l['code'], 'desc' => mb_substr($l['description'], 0, 200), 'dr' => (float) $l['amount'], 'cr' => 0];
                $posting[] = $segments + ['code' => self::ACCRUED, 'desc' => 'Goods received not invoiced — ' . $supplier . ' · ' . $po['reference'], 'dr' => 0, 'cr' => (float) $l['amount']];
            }

            $journal = (new JournalRepository())->postFromSource([
                'date' => Clock::date(), 'sourceType' => 'goods_received_note', 'sourceId' => $grn, 'docRef' => $ref, 'series' => 'JV',
                'narration' => 'Goods received from ' . $supplier . ' against ' . $po['reference'],
                'memo' => 'Cost accrued on receipt; the supplier bill clears the accrual when it is approved.',
            ], $posting, $actorId, null, 'Raised by Procurement on goods received note ' . $ref . ' by ' . $who);
            $this->db->table('goods_received_notes')->where('id', $grn)->update(['journal_id' => $this->value('SELECT id FROM {journals} WHERE reference = ?', [$journal])]);

            $this->db->table('purchase_orders')->where('id', $po['id'])->update(['status' => 'received', 'updated_at' => $now]);
            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'goods_received', 'updated_at' => $now]);
            $this->audit('requisition', (int) $req['id'], $req['reference'],
                mb_substr('Goods received note ' . $ref . ' signed by ' . $receivedBy . ', recorded by ' . $who . '; commitment released and cost accrued as ' . $journal, 0, 255), $actorId);
        });

        return $this->find($no);
    }

    /**
     * Raises the supplier bill in Payables on the three-way match: the order, the goods
     * received note and the supplier's invoice. The requisition closes.
     */
    public function raiseBill(string $no, string $invoiceNo, int $actorId, mixed $documents = []): array
    {
        $req = $this->header($no);
        $grn = $this->row(
            "SELECT g.*, po.id AS po_id, po.reference AS po_ref FROM {goods_received_notes} g JOIN {purchase_orders} po ON po.id = g.purchase_order_id
             WHERE po.requisition_id = ? AND po.status = 'received'",
            [$req['id']]
        );
        if ($req['status'] !== 'goods_received' || $grn === null) {
            throw new RuleViolation('A supplier bill is raised once the goods are received. ' . $no . ' is ' . strtolower(self::label($req['status'], self::STATUS_LABELS)) . '.');
        }
        if ($this->value('SELECT reference FROM {bills} WHERE purchase_order_id = ?', [$grn['po_id']]) !== null) {
            throw new RuleViolation($grn['po_ref'] . ' has already been billed.');
        }

        $bill = $this->transaction(function () use ($req, $grn, $invoiceNo, $actorId, $documents) {
            $bill = (new PayablesRepository())->captureFromReceipt((int) $grn['id'], $invoiceNo, $actorId, $documents);
            $now = Clock::timestamp();
            $this->db->table('purchase_orders')->where('id', $grn['po_id'])->update(['status' => 'closed', 'updated_at' => $now]);
            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'closed', 'updated_at' => $now]);
            $this->audit('requisition', (int) $req['id'], $req['reference'],
                'Supplier bill ' . $bill['no'] . ' raised in payables on three-way match (' . $grn['po_ref'] . ', ' . $grn['reference'] . ', invoice ' . trim($invoiceNo) . ') and requisition closed', $actorId);

            return $bill;
        });

        return ['requisition' => $this->find($no), 'bill' => $bill];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function header(string $no): array
    {
        return $this->row('SELECT * FROM {requisitions} WHERE reference = ?', [$no])
            ?? throw new RuleViolation($no . ' was not found in procurement.');
    }

    private function nextReference(string $table, string $stem): string
    {
        $max = 0;
        foreach ($this->rows("SELECT reference FROM {{$table}} WHERE reference LIKE ?", [$stem . '%']) as $r) {
            $max = max($max, (int) substr($r['reference'], strlen($stem)));
        }

        return $stem . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** A supplier on the register by name; one not yet on it is added, not pre-qualified. */
    /**
     * Registers a supplier, or changes one already on the register. Registering and
     * editing the details is a preparer's job; pre-qualifying, blocking or restoring a
     * supplier changes who can be ordered from and paid, so it takes an approver
     * ($canApprove). A PIN already billed against is not changed — the withholding
     * certificates filed with KRA carry it.
     *
     * @param array{name: string, pin?: string, category: string, status?: string, prequalUntil?: string, rating?: string,
     *              whtRate?: float|string, whtBasis?: string, paymentDetails?: string} $f
     */
    public function saveSupplier(array $f, int $actorId, bool $canApprove, ?int $id = null): array
    {
        $current = $id === null ? null : $this->row('SELECT * FROM {suppliers} WHERE id = ?', [$id]);
        if ($id !== null && $current === null) {
            throw new RuleViolation('There is no such supplier on the register.');
        }

        $name     = preg_replace('/\s+/', ' ', trim((string) ($f['name'] ?? '')));
        $pin      = strtoupper(trim((string) ($f['pin'] ?? '')));
        $category = trim((string) ($f['category'] ?? ''));
        $status   = (string) ($f['status'] ?? ($current['status'] ?? 'not_prequalified'));
        $until    = trim((string) ($f['prequalUntil'] ?? ''));
        $untilAt  = $until === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $until);
        $rating   = strtoupper(trim((string) ($f['rating'] ?? '')));
        $whtRaw   = trim((string) ($f['whtRate'] ?? ''));
        $wht      = $whtRaw === '' ? null : (float) $whtRaw;
        $basis    = trim((string) ($f['whtBasis'] ?? ''));
        $payment  = trim((string) ($f['paymentDetails'] ?? ''));

        $excluding = $id === null ? '' : ' AND id <> ' . (int) $id;
        $nameTaken = $name === '' ? null : $this->value('SELECT name FROM {suppliers} WHERE LOWER(name) = ?' . $excluding, [mb_strtolower($name)]);
        $pinHolder = $pin === '' ? null : $this->value('SELECT name FROM {suppliers} WHERE kra_pin = ?' . $excluding, [$pin]);
        $billed    = $id !== null && (int) $this->value('SELECT COUNT(*) FROM {bills} WHERE supplier_id = ?', [$id]) > 0;
        $statusChanged = $status !== ($current['status'] ?? 'not_prequalified');
        // Renewing or re-rating a pre-qualification is as much an approval as granting it.
        $qualificationChanged = $statusChanged || ($status === 'prequalified'
            && ($until !== (string) ($current['prequalified_until'] ?? '') || $rating !== (string) ($current['rating'] ?? '')));

        $error = match (true) {
            $name === ''                                        => 'Name the supplier as it is registered.',
            mb_strlen($name) > 120                              => 'Keep the supplier name to 120 characters.',
            $nameTaken !== null                                 => $nameTaken . ' is already on the supplier register.',
            $pin !== '' && preg_match(self::PIN_PATTERN, $pin) !== 1 => 'The KRA PIN looks wrong. It runs P0 then eight digits and a letter, e.g. P051182934C.',
            $pinHolder !== null                                 => 'KRA PIN ' . $pin . ' belongs to ' . $pinHolder . ' on the supplier register.',
            $billed && $current['kra_pin'] !== null && $pin !== $current['kra_pin']
                                                                => $current['name'] . ' has been billed under KRA PIN ' . $current['kra_pin'] . '. The PIN cannot be changed once withholding has been filed against it.',
            $category === ''                                    => 'Choose the supplier\'s category.',
            mb_strlen($category) > 60                           => 'Keep the category to 60 characters.',
            !array_key_exists($status, self::SUPPLIER_STATUSES) => 'Choose whether the supplier is pre-qualified, not pre-qualified or blocked.',
            $qualificationChanged && !$canApprove               => 'Pre-qualifying, renewing, blocking or restoring a supplier needs an approver.',
            $until !== '' && $untilAt === false                 => 'Enter the pre-qualification end date as a date.',
            $status === 'prequalified' && $pin === ''           => 'A supplier is pre-qualified only with a KRA PIN on the register.',
            $status === 'prequalified' && $untilAt === null     => 'Give the date pre-qualification runs to.',
            $status === 'prequalified' && $qualificationChanged && $untilAt <= Clock::today()
                                                                => 'Pre-qualification must run to a date after today.',
            $status === 'prequalified' && $rating === ''        => 'Rate the supplier A, B or C when pre-qualifying it.',
            $rating !== '' && !in_array($rating, self::RATINGS, true) => 'The rating is A, B or C.',
            $wht !== null && ($wht < 0 || $wht > 100)           => 'The withholding rate is a percentage between 0 and 100.',
            $wht !== null && $basis === ''                      => 'Say what the withholding applies to, e.g. "on fees".',
            mb_strlen($basis) > 40                              => 'Keep the withholding basis to 40 characters.',
            default                                             => null,
        };
        if ($error !== null) {
            throw new RuleViolation($error);
        }

        $row = [
            'name' => $name, 'kra_pin' => $pin !== '' ? $pin : null, 'category' => $category, 'status' => $status,
            'prequalified_until' => $untilAt ? $untilAt->format('Y-m-d') : null, 'rating' => $rating !== '' ? $rating : null,
            'wht_rate_pct' => $wht, 'wht_basis' => $wht !== null ? $basis : null, 'payment_details' => $payment !== '' ? $payment : null,
        ];
        $who = $this->lookups->shortName($actorId);

        $id = $this->transaction(function () use ($id, $current, $row, $who, $actorId, $qualificationChanged) {
            $now = Clock::timestamp();
            if ($id === null) {
                $id = $this->insert('suppliers', $row + ['created_at' => $now]);
                $this->audit('supplier', $id, $row['name'], 'Registered by ' . $who . ' as ' . strtolower(self::SUPPLIER_STATUSES[$row['status']]), $actorId);

                return $id;
            }

            $changed = array_keys(array_filter($row, static fn ($v, $k) => $k === 'wht_rate_pct'
                ? ($v === null) !== ($current[$k] === null) || (float) $v !== (float) $current[$k]
                : (string) $v !== (string) ($current[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $id;
            }
            $this->db->table('suppliers')->where('id', $id)->update($row + ['updated_at' => $now]);
            if ($qualificationChanged) {
                $this->audit('supplier', $id, $row['name'], self::SUPPLIER_STATUSES[$row['status']] . ' by ' . $who
                    . ($row['status'] === 'prequalified' ? ' to ' . self::dmy($row['prequalified_until']) . ', rated ' . $row['rating'] : ''), $actorId);
            }
            $details = array_diff($changed, $qualificationChanged ? ['status', 'prequalified_until', 'rating'] : ['status']);
            if ($details !== []) {
                $labels = ['name' => 'name', 'kra_pin' => 'KRA PIN', 'category' => 'category', 'prequalified_until' => 'pre-qualification date',
                    'rating' => 'rating', 'wht_rate_pct' => 'withholding', 'wht_basis' => 'withholding', 'payment_details' => 'payment details'];
                $this->audit('supplier', $id, $row['name'], 'Updated by ' . $who . ': ' . implode(', ', array_unique(array_map(static fn ($k) => $labels[$k], $details))), $actorId);
            }

            return $id;
        });

        return $this->supplier($id);
    }

    private function supplierId(string $name): int
    {
        $id = $this->value('SELECT id FROM {suppliers} WHERE LOWER(name) = ?', [strtolower($name)]);

        return $id !== null ? (int) $id : $this->insert('suppliers', [
            'name' => mb_substr($name, 0, 120), 'category' => 'Not classified', 'status' => 'not_prequalified', 'created_at' => Clock::timestamp(),
        ]);
    }

    /** Keeps a quotation document under writable/uploads and records it. Returns the stored path. */
    private function storeDocument(int $quotationId, array $file, int $actorId): string
    {
        return (new AttachmentRepository($this->db))->store('quotation', $quotationId, $file, $actorId, self::ATTACHMENT_DIR)[1];
    }

    private static function size(int $bytes): string
    {
        return $bytes < 1024000 ? max(1, (int) round($bytes / 1024)) . ' KB' : number_format($bytes / 1048576, 1) . ' MB';
    }
}
