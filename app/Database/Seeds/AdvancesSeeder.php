<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Staff and observer advances, the receipts surrendered against them, and
 * recoveries.
 *
 * Source: ADVANCES. A holder is linked to the payroll register when they are on
 * it; the requester is recorded only when they have a system account.
 */
class AdvancesSeeder extends Seeder
{
    private const METHODS = ['M-Pesa' => 'mpesa', 'Bank' => 'bank'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        foreach ($ctx->data('ADVANCES') as $a) {
            $grant     = $ctx->grantId($a['grant']);
            $method    = self::METHODS[$a['method']] ?? null;
            $requested = $ctx->trailEntry($a['trail'], '/^Requested by/');
            $decision  = $ctx->trailEntry($a['trail'], '/^(Approved|Rejected) by/');

            $id = $ctx->insert('advances', [
                'entity_id' => $entity, 'reference' => $a['ref'], 'holder_kind' => strtolower($a['kind']),
                'staff_id' => $a['kind'] === 'Staff' ? $ctx->lookup('staff', mb_strtolower($a['holder'])) : null,
                'holder_name' => $a['holder'], 'holder_role' => $a['role'], 'purpose' => $a['purpose'],
                'programme_id' => $ctx->programmeId($a['program']), 'fund_id' => $ctx->fundId($a['fund'], $grant, $a['program']),
                'grant_id' => $grant, 'amount' => $a['amount'], 'requested_on' => $ctx->date($a['reqDate']), 'due_on' => $ctx->date($a['dueDate']),
                'status' => strtolower($a['status']), 'payment_method' => $method,
                'bank_account_id' => $method === null ? null : $ctx->require('bank_accounts', $method === 'mpesa' ? '1130' : '1110'),
                'issued_on' => $ctx->date($a['issueDate']), 'requested_by' => $ctx->userId($requested['who'] ?? null),
                'approved_by' => $ctx->userId($decision['who'] ?? null), 'approved_at' => $decision['when'] ?? null,
                'rejected_reason' => $a['status'] === 'Rejected' ? trim(explode('—', $decision['what'], 2)[1] ?? '') : null,
                'created_at' => $now,
            ]);

            $surrendered = $ctx->trailEntry($a['trail'], '/^Surrendered/');
            foreach ($a['receipts'] as $r) {
                $ctx->insert('advance_surrenders', [
                    'advance_id' => $id, 'account_id' => $ctx->accountId($r['code']), 'description' => $r['desc'], 'amount' => $r['amount'],
                    'surrendered_on' => substr($surrendered['when'] ?? $ctx->date($a['dueDate']), 0, 10),
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            if ($a['recovered'] > 0) {
                $scheduled = $ctx->trailEntry($a['trail'], '/^Recovery scheduled/');
                $ctx->insert('advance_recoveries', [
                    'advance_id' => $id, 'method' => 'payroll', 'amount' => $a['recovered'],
                    'recovered_on' => substr($scheduled['when'] ?? $ctx->date($a['dueDate']), 0, 10), 'reference' => $scheduled['what'] ?? null,
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            $refund = $ctx->trailEntry($a['trail'], '/^Unspent [\d,]+ refunded/');
            if ($refund !== null && preg_match('/^Unspent ([\d,]+)/', $refund['what'], $m) === 1) {
                $ctx->insert('advance_recoveries', [
                    'advance_id' => $id, 'method' => 'bank', 'amount' => (float) str_replace(',', '', $m[1]),
                    'recovered_on' => substr($refund['when'], 0, 10), 'reference' => $refund['what'],
                    'created_by' => $ctx->systemUserId(), 'created_at' => $now,
                ]);
            }

            $ctx->writeTrail('advance', $id, $a['ref'], $a['trail'], $entity);
        }
    }
}
