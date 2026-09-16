<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Suppliers, requisitions, quotations, purchase orders and goods received.
 *
 * Sources: SUPPLIERS, PQ, and the suppliers named on BILLS.
 *
 * The supplier register lists eight suppliers; bills and quotations name others.
 * Those are added from what the document says (name, KRA PIN and category where
 * given) and are not pre-qualified.
 */
class ProcurementSeeder extends Seeder
{
    private const STATUSES = ['Awaiting approval' => 'pending_approval', 'Approved' => 'approved', 'RFQ issued' => 'rfq_issued',
        'PO raised' => 'po_raised', 'Goods received' => 'goods_received', 'Draft' => 'draft', 'Closed' => 'closed', 'Rejected' => 'rejected'];

    private const PO_STATUSES = ['PO raised' => 'open', 'Goods received' => 'received', 'Closed' => 'closed'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        $this->seedSuppliers($ctx, $now);

        foreach ($ctx->data('PQ') as $r) {
            $fund     = $ctx->fundId($r['fund'], null, $r['program'], $r['code']);
            $grant    = $ctx->grantOfFund($fund);
            $decision = $ctx->trailEntry($r['trail'], '/^(Approved|Rejected) by/');

            $id = $ctx->insert('requisitions', [
                'entity_id' => $entity, 'reference' => $r['no'], 'title' => $r['title'], 'requested_by' => $ctx->userOrSystem($r['requester']),
                'programme_id' => $ctx->programmeId($r['program']), 'fund_id' => $fund, 'grant_id' => $grant,
                'account_id' => $ctx->accountId($r['code']), 'raised_on' => $ctx->date($r['raised']), 'needed_by' => $ctx->date($r['needBy']),
                'estimated_amount' => $r['amount'], 'status' => self::STATUSES[$r['status']],
                'approved_by' => $ctx->userId($decision['who'] ?? null), 'approved_at' => $decision['when'] ?? null,
                'rejected_reason' => $r['status'] === 'Rejected' ? trim(explode('—', $decision['what'], 2)[1] ?? '') : null,
                'created_at' => $now,
            ]);

            $lineIds = [];
            foreach ($r['lines'] as $i => $l) {
                $lineIds[] = $ctx->insert('requisition_lines', [
                    'requisition_id' => $id, 'line_no' => $i + 1, 'description' => $l['desc'], 'quantity' => $l['qty'],
                    'unit_cost' => $l['unit'], 'amount' => $l['amount'],
                ]);
            }

            $chosen = null;
            foreach ($r['quotes'] as $q) {
                $quoteId = $ctx->insert('quotations', [
                    'requisition_id' => $id, 'supplier_id' => $ctx->require('suppliers', $q['supplier']), 'amount' => $q['amount'],
                    'note' => $q['note'] !== '' ? $q['note'] : null, 'is_selected' => (int) $q['chosen'], 'created_at' => $now,
                ]);
                $chosen = $q['chosen'] ? $quoteId : $chosen;
            }

            if (isset($r['po'])) {
                $this->seedPurchaseOrder($ctx, $r, $id, $lineIds, $chosen, $fund, $grant, $decision, $now);
            }

            $ctx->writeTrail('requisition', $id, $r['no'], $r['trail'], $entity);
        }
    }

    private function seedSuppliers(SeedContext $ctx, string $now): void
    {
        foreach ($ctx->data('SUPPLIERS') as $s) {
            $wht = preg_match('/^(\d+(?:\.\d+)?)% (.+)$/', $s['wht'], $m) === 1 ? $m : null;
            $ctx->remember('suppliers', $s['name'], $ctx->insert('suppliers', [
                'name' => $s['name'], 'kra_pin' => $s['pin'], 'category' => $s['category'], 'prequalified_until' => $ctx->date($s['prequalUntil']),
                'rating' => $s['rating'] === '—' ? null : $s['rating'], 'wht_rate_pct' => $wht[1] ?? null, 'wht_basis' => $wht[2] ?? null,
                // Expiring and Lapsed are derived from prequalified_until.
                'status' => $s['status'] === 'Not pre-qualified' ? 'not_prequalified' : 'prequalified', 'created_at' => $now,
            ]));
        }

        $named = [];
        foreach ($ctx->data('BILLS') as $b) {
            $named[$b['supplier']] ??= ['pin' => $b['pin'], 'category' => $b['category']];
        }
        foreach ($ctx->data('PQ') as $r) {
            foreach ($r['quotes'] as $q) {
                $named[$q['supplier']] ??= ['pin' => null, 'category' => 'Not classified'];
            }
        }

        foreach ($named as $name => $s) {
            if ($ctx->lookup('suppliers', $name) === null) {
                $ctx->remember('suppliers', $name, $ctx->insert('suppliers', [
                    'name' => $name, 'kra_pin' => $s['pin'], 'category' => $s['category'], 'status' => 'not_prequalified', 'created_at' => $now,
                ]));
            }
        }
    }

    private function seedPurchaseOrder(SeedContext $ctx, array $r, int $requisition, array $lineIds, ?int $quotation, int $fund, ?int $grant, ?array $decision, string $now): void
    {
        $entity = $ctx->entityId();

        // The prototype does not say who issued the order; it was approved with the requisition.
        $po = $ctx->insert('purchase_orders', [
            'entity_id' => $entity, 'reference' => $r['po'], 'requisition_id' => $requisition,
            'supplier_id' => $ctx->require('suppliers', $r['supplier']), 'quotation_id' => $quotation,
            'issued_on' => $ctx->date($r['poDate']), 'expected_on' => $ctx->date($r['expected'] ?? null), 'amount' => $r['amount'],
            'status' => self::PO_STATUSES[$r['status']], 'prepared_by' => $ctx->systemUserId(),
            'approved_by' => $ctx->userId($decision['who'] ?? null), 'approved_at' => $decision['when'] ?? null, 'created_at' => $now,
        ]);
        $ctx->remember('purchase_orders', $r['po'], $po);

        $poLines = [];
        foreach ($r['lines'] as $i => $l) {
            $poLines[] = $ctx->insert('purchase_order_lines', [
                'purchase_order_id' => $po, 'requisition_line_id' => $lineIds[$i], 'line_no' => $i + 1,
                'account_id' => $ctx->accountId($r['code']), 'fund_id' => $fund, 'programme_id' => $ctx->programmeId($r['program']),
                'grant_id' => $grant, 'description' => $l['desc'], 'quantity' => $l['qty'], 'unit_cost' => $l['unit'], 'amount' => $l['amount'],
            ]);
        }

        if (isset($r['grn'])) {
            // Store staff sign GRNs without a system account; their name stays on the note.
            $grn = $ctx->insert('goods_received_notes', [
                'entity_id' => $entity, 'reference' => $r['grn'], 'purchase_order_id' => $po, 'received_on' => $ctx->date($r['grnDate']),
                'received_by' => $ctx->userOrSystem($r['receivedBy']),
                'note' => trim($r['grnNote'] . ' (received by ' . $r['receivedBy'] . ')'), 'created_at' => $now,
            ]);
            $ctx->remember('goods_received_notes', $r['grn'], $grn);

            foreach ($r['lines'] as $i => $l) {
                $ctx->insert('goods_received_lines', ['goods_received_note_id' => $grn, 'purchase_order_line_id' => $poLines[$i], 'quantity' => $l['qty']]);
            }
        }

        if (isset($r['bill'])) {
            $ctx->remember('bill_orders', $r['bill'], ['po' => $po, 'grn' => $ctx->lookup('goods_received_notes', $r['grn'] ?? null)]);
        }
    }
}
