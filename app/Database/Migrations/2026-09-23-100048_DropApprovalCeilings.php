<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * A role's ceiling (`approval_limits`) is dropped: what a role may sign is
 * entirely its ladder's own bands now (App\Repositories\ApprovalPolicy,
 * docs/approvals.md). The blanket, whatever-the-document-type cap it used to add
 * on top only fought the ladder it was meant to back up — freeing a step of its
 * band, in Settings → Approvals, did not free the role signing it, because the
 * ceiling still stood behind it unannounced. One control, not two.
 */
class DropApprovalCeilings extends SchemaMigration
{
    public function up(): void
    {
        $this->dropTables(['approval_limits']);
    }

    public function down(): void
    {
        $documentTypes = ['journal', 'bill', 'payment_run', 'subgrant', 'transfer', 'budget_revision',
            'requisition', 'purchase_order', 'payroll_run', 'advance', 'asset_disposal', 'period_reopen'];

        $this->table('approval_limits', [
            'id'            => $this->id(),
            'role_id'       => $this->fk(),
            'document_type' => $this->string(20, true),
            'ceiling'       => $this->money(true),
        ] + $this->timestamps(), [
            'unique' => [['role_id', 'document_type']],
            'fks'    => ['role_id' => ['roles', 'CASCADE']],
            'checks' => [
                'type'    => 'document_type IS NULL OR ' . $this->in('document_type', $documentTypes),
                'ceiling' => 'ceiling IS NULL OR ceiling >= 0',
            ],
        ]);
    }
}
