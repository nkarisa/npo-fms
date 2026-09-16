<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Requisitions, quotations, purchase orders, goods received and suppliers.
 *
 * A requisition's budget position is read from the approved budget and the
 * ledger: budget and actual for its account, fund and programme, and as
 * `committed` the unbilled value of its own open purchase order — the money it
 * has reserved.
 */
final class ProcurementRepository extends Repository
{
    public const STATUS_LABELS = ['pending_approval' => 'Awaiting approval', 'rfq_issued' => 'RFQ issued', 'po_raised' => 'PO raised'];

    /** Days before pre-qualification lapses that a supplier shows as expiring. */
    private const EXPIRY_WARNING_DAYS = 30;

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function requisitions(): array
    {
        return $this->cached('requisitions', function () {
            $lines = [];
            foreach ($this->rows('SELECT * FROM {requisition_lines} ORDER BY requisition_id, line_no') as $l) {
                $lines[(int) $l['requisition_id']][] = [
                    'desc' => $l['description'], 'qty' => self::num($l['quantity']), 'unit' => self::num($l['unit_cost']), 'amount' => self::num($l['amount']),
                ];
            }

            $quotes = [];
            foreach ($this->rows('SELECT q.*, s.name AS supplier FROM {quotations} q JOIN {suppliers} s ON s.id = q.supplier_id ORDER BY q.requisition_id, q.id') as $q) {
                $quotes[(int) $q['requisition_id']][] = [
                    'supplier' => $q['supplier'], 'amount' => self::num($q['amount']), 'note' => $q['note'] ?? '', 'chosen' => (bool) $q['is_selected'], 'file' => '',
                ];
            }

            $orders = array_column($this->rows(
                'SELECT po.*, s.name AS supplier, g.reference AS grn_ref, g.received_on, g.received_by, g.note AS grn_note, b.reference AS bill_ref
                 FROM {purchase_orders} po JOIN {suppliers} s ON s.id = po.supplier_id
                 LEFT JOIN {goods_received_notes} g ON g.purchase_order_id = po.id
                 LEFT JOIN {bills} b ON b.purchase_order_id = po.id'
            ), null, 'requisition_id');

            $positions = (new PayablesRepository())->budgetPositions();
            $trails    = $this->trails('requisition');

            return array_map(function ($r) use ($lines, $quotes, $orders, $positions, $trails) {
                $id       = (int) $r['id'];
                $po       = $orders[$id] ?? null;
                $position = $positions[$r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id']] ?? ['budget' => 0.0, 'actual' => 0.0];

                $req = [
                    'no'        => $r['reference'],
                    'title'     => $r['title'],
                    'requester' => $this->requester($r, $trails[$id] ?? []),
                    'program'   => $this->lookups->programmeName((int) $r['programme_id']),
                    'fund'      => self::FUND_GROUPS[$this->lookups->funds()[$r['fund_id']]['ledger_group']],
                    'code'      => $r['account_code'],
                    'raised'    => self::dm($r['raised_on']),
                    'needBy'    => self::dm($r['needed_by']),
                    'amount'    => self::num($r['estimated_amount']),
                    'status'    => self::label($r['status'], self::STATUS_LABELS),
                    'budget'    => self::num($position['budget']),
                    'committed' => self::num($po !== null && in_array($po['status'], ['open', 'part_received'], true) ? $po['amount'] : 0),
                    'spent'     => self::num($position['actual']),
                    'lines'     => $lines[$id] ?? [],
                    'quotes'    => $quotes[$id] ?? [],
                    'trail'     => $trails[$id] ?? [],
                ];

                if ($po !== null) {
                    $req += ['supplier' => $po['supplier'], 'po' => $po['reference'], 'poDate' => self::dm($po['issued_on']), 'expected' => self::dm($po['expected_on'])];
                }
                if ($po !== null && $po['grn_ref'] !== null) {
                    $receivedBy = preg_match('/\(received by (.+)\)$/', (string) $po['grn_note'], $m) === 1 ? $m[1] : $this->lookups->shortName((int) $po['received_by']);
                    $req += [
                        'grn' => $po['grn_ref'], 'grnDate' => self::dm($po['received_on']), 'receivedBy' => $receivedBy,
                        'grnNote' => trim(preg_replace('/\s*\(received by .+\)$/', '', (string) $po['grn_note'])),
                    ];
                }
                if ($po !== null && $po['bill_ref'] !== null) {
                    $req['bill'] = $po['bill_ref'];
                }

                return $req;
            }, $this->rows(
                'SELECT r.*, a.code AS account_code FROM {requisitions} r JOIN {accounts} a ON a.id = r.account_id ORDER BY r.raised_on DESC, r.reference DESC'
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

    public function suppliers(): array
    {
        return $this->cached('suppliers', fn () => array_map(fn ($s) => [
            'name'         => $s['name'],
            'pin'          => $s['kra_pin'] ?? '—',
            'category'     => $s['category'],
            'prequalUntil' => self::dmy($s['prequalified_until']),
            'rating'       => $s['rating'] ?? '—',
            'wht'          => $s['wht_rate_pct'] === null ? '—' : self::num($s['wht_rate_pct']) . '% ' . ($s['wht_basis'] ?? ''),
            'spend'        => self::num($s['spend']),
            'status'       => self::supplierStatus($s),
        ], $this->rows(
            "SELECT s.*, (SELECT COALESCE(SUM(b.subtotal), 0) FROM {bills} b WHERE b.supplier_id = s.id AND b.status IN ('approved', 'scheduled', 'paid')) AS spend
             FROM {suppliers} s ORDER BY s.status = 'prequalified' DESC, s.name"
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

    /** Budget, actual and committed per account for the accounts requisitions draw on. */
    public function budgetLines(): array
    {
        $positions = (new PayablesRepository())->budgetPositions();
        $rows = [];

        foreach ($this->rows('SELECT DISTINCT a.id, a.code, a.name FROM {requisitions} r JOIN {accounts} a ON a.id = r.account_id ORDER BY a.code') as $a) {
            $budget = $spent = 0.0;
            foreach ($positions as $key => $p) {
                if (str_starts_with($key, $a['id'] . ':')) {
                    $budget += $p['budget'];
                    $spent  += $p['actual'];
                }
            }
            $rows[] = ['code' => $a['code'], 'name' => $a['name'], 'budget' => $budget, 'spent' => $spent];
        }

        return $rows;
    }

    public function approve(string $no, int $actorId): array
    {
        $req = $this->header($no);
        $this->transaction(function () use ($req, $actorId) {
            $this->db->table('requisitions')->where('id', $req['id'])->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('requisition', (int) $req['id'], $req['reference'], 'Approved by ' . $this->lookups->shortName($actorId) . ' within delegated limit', $actorId);
        });

        return $this->find($no);
    }

    /** Raises the purchase order, committing the budget. */
    public function raisePurchaseOrder(string $no, string $supplierName, string $waiver, ?string $expected, int $actorId): array
    {
        $req = $this->header($no);
        $supplier = $this->row('SELECT * FROM {suppliers} WHERE name = ?', [$supplierName]);
        if ($supplier === null) {
            throw new RuleViolation($supplierName . ' is not on the supplier register. Add and pre-qualify the supplier before placing an order.');
        }

        $this->transaction(function () use ($req, $supplier, $waiver, $expected, $actorId) {
            $now = Clock::timestamp();
            $ref = $this->nextReference('purchase_orders', 'PO-' . Clock::today()->format('y') . '-');
            $quote = $this->value('SELECT id FROM {quotations} WHERE requisition_id = ? AND supplier_id = ?', [$req['id'], $supplier['id']]);

            $po = $this->insert('purchase_orders', [
                'entity_id' => $req['entity_id'], 'reference' => $ref, 'requisition_id' => $req['id'], 'supplier_id' => $supplier['id'],
                'quotation_id' => $quote, 'issued_on' => Clock::date(),
                'expected_on' => $expected !== null && strtotime($expected) ? date('Y-m-d', strtotime($expected)) : $req['needed_by'],
                'amount' => $req['estimated_amount'], 'status' => 'open', 'prepared_by' => $actorId,
                // The requisition's approval authorised the order; a second person must have given it.
                'approved_by' => (int) $req['approved_by'] !== $actorId ? $req['approved_by'] : null, 'approved_at' => $req['approved_at'],
                'created_at' => $now,
            ]);

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

            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'po_raised', 'updated_at' => $now]);
            $this->audit('requisition', (int) $req['id'], $req['reference'],
                $ref . ' issued to ' . $supplier['name'] . ($waiver !== '' ? ' · single-source waiver: ' . $waiver : ''), $actorId);
        });

        return $this->find($no);
    }

    /** Posts the goods received note: the order is received in full and the commitment released. */
    public function receive(string $no, string $receivedBy, string $note, int $actorId): array
    {
        $req = $this->header($no);
        $po  = $this->row("SELECT * FROM {purchase_orders} WHERE requisition_id = ? AND status IN ('open', 'part_received')", [$req['id']]);
        if ($po === null) {
            throw new RuleViolation('Goods can only be received against an open purchase order. ' . $no . ' has none.');
        }

        $this->transaction(function () use ($req, $po, $receivedBy, $note, $actorId) {
            $now = Clock::timestamp();
            $ref = $this->nextReference('goods_received_notes', 'GRN-');

            $grn = $this->insert('goods_received_notes', [
                'entity_id' => $req['entity_id'], 'reference' => $ref, 'purchase_order_id' => $po['id'], 'received_on' => Clock::date(),
                'received_by' => $this->lookups->userId($receivedBy) ?? $actorId,
                'note' => ($note !== '' ? $note : 'Received in full') . ' (received by ' . $receivedBy . ')', 'created_at' => $now,
            ]);
            foreach ($this->rows('SELECT * FROM {purchase_order_lines} WHERE purchase_order_id = ?', [$po['id']]) as $l) {
                $this->insert('goods_received_lines', ['goods_received_note_id' => $grn, 'purchase_order_line_id' => $l['id'], 'quantity' => $l['quantity']]);
            }

            $this->db->table('purchase_orders')->where('id', $po['id'])->update(['status' => 'received', 'updated_at' => $now]);
            $this->db->table('requisitions')->where('id', $req['id'])->update(['status' => 'goods_received', 'updated_at' => $now]);
            $this->audit('requisition', (int) $req['id'], $req['reference'],
                'Goods received note ' . $ref . ' signed by ' . $receivedBy . ' · supplier payable recognised', $actorId);
        });

        return $this->find($no);
    }

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
}
