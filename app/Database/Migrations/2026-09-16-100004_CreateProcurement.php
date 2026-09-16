<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Requisition → quotation → purchase order → goods received.
 *
 * An open purchase order is a budget commitment (rule 9): committed is not actual,
 * but both reduce what is available. See v_budget_availability.
 */
class CreateProcurement extends SchemaMigration
{
    public function up(): void
    {
        // "Expiring" and "Lapsed" are derived from prequalified_until.
        $this->table('suppliers', [
            'id'                 => $this->id(),
            'name'               => $this->string(120),
            'kra_pin'            => $this->string(11, true),
            'category'           => $this->string(60),
            'prequalified_until' => $this->date(true),
            'rating'             => $this->string(1, true),
            'wht_rate_pct'       => $this->pct(true),
            'wht_basis'          => $this->string(40, true),
            'payment_details'    => $this->text(),
            'status'             => $this->string(16, false, 'not_prequalified'),
        ] + $this->timestamps(), [
            'unique' => ['name', 'kra_pin'],
            'checks' => [
                'rating' => 'rating IS NULL OR ' . $this->in('rating', ['A', 'B', 'C']),
                'wht'    => 'wht_rate_pct IS NULL OR (wht_rate_pct >= 0 AND wht_rate_pct <= 100)',
                'status' => $this->in('status', ['prequalified', 'not_prequalified', 'blocked']),
            ],
        ]);

        $this->table('requisitions', [
            'id'               => $this->id(),
            'entity_id'        => $this->fk(),
            'reference'        => $this->string(20),
            'title'            => $this->string(255),
            'requested_by'     => $this->fk(),
            'programme_id'     => $this->fk(),
            'fund_id'          => $this->fk(),
            'grant_id'         => $this->fk(true),
            'account_id'       => $this->fk(),
            'raised_on'        => $this->date(),
            'needed_by'        => $this->date(true),
            'estimated_amount' => $this->money(),
            'status'           => $this->string(16, false, 'draft'),
            'approved_by'      => $this->fk(true),
            'approved_at'      => $this->datetime(),
            'rejected_reason'  => $this->text(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => ['status', 'programme_id', 'fund_id', 'account_id'],
            'fks'    => ['entity_id' => 'entities', 'requested_by' => 'users', 'programme_id' => 'programmes',
                'fund_id' => 'funds', 'grant_id' => 'grants', 'account_id' => 'accounts', 'approved_by' => 'users'],
            'checks' => [
                'status' => $this->in('status', ['draft', 'pending_approval', 'approved', 'rfq_issued', 'po_raised',
                    'goods_received', 'closed', 'rejected']),
                'sod'    => 'approved_by IS NULL OR approved_by <> requested_by',
                'amount' => 'estimated_amount >= 0',
            ],
        ]);

        $this->table('requisition_lines', [
            'id'             => $this->id(),
            'requisition_id' => $this->fk(),
            'line_no'        => $this->int(false, null, 'SMALLINT'),
            'description'    => $this->string(255),
            'quantity'       => $this->quantity(),
            'unit_cost'      => $this->money(),
            'amount'         => $this->money(),
        ], [
            'unique' => [['requisition_id', 'line_no']],
            'fks'    => ['requisition_id' => ['requisitions', 'CASCADE']],
            'checks' => ['values' => 'quantity > 0 AND unit_cost >= 0 AND amount >= 0'],
        ]);

        $this->table('quotations', [
            'id'             => $this->id(),
            'requisition_id' => $this->fk(),
            'supplier_id'    => $this->fk(),
            'amount'         => $this->money(),
            'received_on'    => $this->date(true),
            'note'           => $this->text(),
            'is_selected'    => $this->bool(),
        ] + $this->timestamps(), [
            'unique' => [['requisition_id', 'supplier_id']],
            'keys'   => ['supplier_id'],
            'fks'    => ['requisition_id' => ['requisitions', 'CASCADE'], 'supplier_id' => 'suppliers'],
            'checks' => ['amount' => 'amount >= 0'],
        ]);

        $this->table('purchase_orders', [
            'id'             => $this->id(),
            'entity_id'      => $this->fk(),
            'reference'      => $this->string(20),
            'requisition_id' => $this->fk(),
            'supplier_id'    => $this->fk(),
            'quotation_id'   => $this->fk(true),
            'issued_on'      => $this->date(),
            'expected_on'    => $this->date(true),
            'amount'         => $this->money(),
            'status'         => $this->string(14, false, 'open'),
            'prepared_by'    => $this->fk(),
            'approved_by'    => $this->fk(true),
            'approved_at'    => $this->datetime(),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => ['requisition_id', 'supplier_id', 'status'],
            'fks'    => ['entity_id' => 'entities', 'requisition_id' => 'requisitions', 'supplier_id' => 'suppliers',
                'quotation_id' => 'quotations', 'prepared_by' => 'users', 'approved_by' => 'users'],
            'checks' => [
                'status' => $this->in('status', ['open', 'part_received', 'received', 'closed', 'cancelled']),
                'sod'    => 'approved_by IS NULL OR approved_by <> prepared_by',
                'amount' => 'amount >= 0',
            ],
        ]);

        // Each PO line carries full coding so the commitment lands on the right budget line.
        $this->table('purchase_order_lines', [
            'id'                  => $this->id(),
            'purchase_order_id'   => $this->fk(),
            'requisition_line_id' => $this->fk(true),
            'line_no'             => $this->int(false, null, 'SMALLINT'),
            'account_id'          => $this->fk(),
            'fund_id'             => $this->fk(),
            'programme_id'        => $this->fk(),
            'grant_id'            => $this->fk(true),
            'description'         => $this->string(255),
            'quantity'            => $this->quantity(),
            'unit_cost'           => $this->money(),
            'amount'              => $this->money(),
        ], [
            'unique' => [['purchase_order_id', 'line_no']],
            'keys'   => [['account_id', 'fund_id', 'programme_id']],
            'fks'    => ['purchase_order_id' => ['purchase_orders', 'CASCADE'], 'requisition_line_id' => 'requisition_lines',
                'account_id' => 'accounts', 'fund_id' => 'funds', 'programme_id' => 'programmes', 'grant_id' => 'grants'],
            'checks' => ['values' => 'quantity > 0 AND unit_cost >= 0 AND amount >= 0'],
        ]);

        $this->table('goods_received_notes', [
            'id'                => $this->id(),
            'entity_id'         => $this->fk(),
            'reference'         => $this->string(20),
            'purchase_order_id' => $this->fk(),
            'received_on'       => $this->date(),
            'received_by'       => $this->fk(),
            'note'              => $this->text(),
            'journal_id'        => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'reference']],
            'keys'   => ['purchase_order_id'],
            'fks'    => ['entity_id' => 'entities', 'purchase_order_id' => 'purchase_orders', 'received_by' => 'users',
                'journal_id' => 'journals'],
        ]);

        // Partial receipt is a line quantity below the ordered quantity.
        $this->table('goods_received_lines', [
            'id'                     => $this->id(),
            'goods_received_note_id' => $this->fk(),
            'purchase_order_line_id' => $this->fk(),
            'quantity'               => $this->quantity(),
        ], [
            'unique' => [['goods_received_note_id', 'purchase_order_line_id']],
            'keys'   => ['purchase_order_line_id'],
            'fks'    => ['goods_received_note_id' => ['goods_received_notes', 'CASCADE'],
                'purchase_order_line_id' => 'purchase_order_lines'],
            'checks' => ['quantity' => 'quantity > 0'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['goods_received_lines', 'goods_received_notes', 'purchase_order_lines', 'purchase_orders',
            'quotations', 'requisition_lines', 'requisitions', 'suppliers']);
    }
}
