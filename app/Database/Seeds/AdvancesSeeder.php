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
    private const METHODS = ['M-Pesa' => 'mpesa', 'Bank' => 'bank', 'Cash' => 'cash'];

    /** The run the prototype's scheduled recoveries start from, as PayrollSeeder deducts them. */
    private const RECOVERY_FROM = 'Jun 2026';

    /** How a chase escalates, matching the wording the prototype's trail uses. */
    private const REMINDER_LEVELS = [
        '/^First surrender reminder/'  => 1,
        '/^Second reminder/'           => 2,
        '/^Reminder (\d+)/'            => 3,
    ];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $entity = $ctx->entityId();

        foreach ($ctx->data('ADVANCES') as $a) {
            $grant     = $ctx->grantId($a['grant']);
            $method    = self::METHODS[$a['method']] ?? null;
            $requested = $ctx->trailEntry($a['trail'], '/^Requested by/');
            $approved  = $ctx->trailEntry($a['trail'], '/^Approved by/');
            $rejected  = $ctx->trailEntry($a['trail'], '/^Rejected by/');

            $id = $ctx->insert('advances', [
                'entity_id' => $entity, 'reference' => $a['ref'], 'holder_kind' => strtolower($a['kind']),
                'staff_id' => $a['kind'] === 'Staff' ? $ctx->lookup('staff', mb_strtolower($a['holder'])) : null,
                'holder_name' => $a['holder'], 'holder_role' => $a['role'], 'purpose' => $a['purpose'],
                'programme_id' => $ctx->programmeId($a['program']), 'fund_id' => $ctx->fundId($a['fund'], $grant, $a['program']),
                'grant_id' => $grant, 'amount' => $a['amount'], 'requested_on' => $ctx->date($a['reqDate']), 'due_on' => $ctx->date($a['dueDate']),
                'status' => strtolower($a['status']), 'payment_method' => $method,
                'bank_account_id' => $method === null ? null : $ctx->require('bank_accounts', $method === 'mpesa' ? '1130' : '1110'),
                'issued_on' => $ctx->date($a['issueDate']), 'requested_by' => $ctx->userId($requested['who'] ?? null),
                'approved_by' => $ctx->userId($approved['who'] ?? null), 'approved_at' => $approved['when'] ?? null,
                // A rejection is a decision by its own person, not an approval.
                'rejected_by' => $ctx->userId($rejected['who'] ?? null), 'rejected_at' => $rejected['when'] ?? null,
                'rejected_reason' => $rejected === null ? null : trim(explode('—', $rejected['what'], 2)[1] ?? ''),
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

            // A payroll recovery is agreed before it happens: the prototype's
            // "recovery scheduled over three runs" is the decision, and the
            // deduction on the holder's pay is what takes it.
            if ($a['recovered'] > 0) {
                $scheduled = $ctx->trailEntry($a['trail'], '/^Recovery scheduled/');
                $ctx->insert('advance_recoveries', [
                    'advance_id' => $id, 'method' => 'payroll', 'amount' => $a['recovered'],
                    'recovered_on' => substr($scheduled['when'] ?? $ctx->date($a['dueDate']), 0, 10), 'reference' => $scheduled['what'] ?? null,
                    'status' => 'scheduled', 'period_id' => $ctx->lookup('period_names', self::RECOVERY_FROM),
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

            // Chasing escalates, so each reminder is a row of its own rather than
            // a line the screen would have to read back out of the narrative.
            foreach ($a['trail'] as $t) {
                foreach (self::REMINDER_LEVELS as $pattern => $level) {
                    if (preg_match($pattern, $t['what'], $m) !== 1) {
                        continue;
                    }
                    $level = isset($m[1]) ? (int) $m[1] : $level;
                    $ctx->insert('advance_reminders', [
                        'advance_id' => $id, 'level' => $level, 'sent_on' => $ctx->date($t['when']),
                        'sent_to' => $level === 1 ? $a['holder'] : (str_contains($t['what'], 'Executive Director') ? 'the Executive Director' : 'the programme director'),
                        'note' => $t['what'], 'sent_by' => $ctx->systemUserId(), 'created_at' => $now,
                    ]);
                    break;
                }
            }

            $ctx->writeTrail('advance', $id, $a['ref'], $a['trail'], $entity);
        }
    }
}
