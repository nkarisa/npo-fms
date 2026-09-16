<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * The payroll register: staff, their recurring pay and deductions, and how their
 * cost is allocated across funds, grants and programmes.
 *
 * Source: PSTAFF. Personal identifiers (KRA PIN, NSSF number) are encrypted with
 * the application key (encryption.key in .env) before they are stored. Bank
 * account numbers are masked in the prototype, so only the visible digits are kept.
 *
 * Payroll runs and payslips are not seeded: the prototype computes the current
 * month on screen and holds no run history (its July payroll journal covers 34
 * staff against 15 on the register).
 */
class PayrollSeeder extends Seeder
{
    /** PSTAFF field → pay component. */
    private const PAY_ITEMS = ['basic' => 'basic_salary', 'house' => 'house_allowance', 'transport' => 'transport_allowance',
        'acting' => 'acting_allowance', 'sacco' => 'sacco', 'advance' => 'advance_recovery'];

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();
        $encrypter = service('encrypter');
        $encrypt = static fn (?string $value) => $value === null || $value === '' ? null : base64_encode($encrypter->encrypt($value));

        foreach ($ctx->data('PSTAFF') as $s) {
            [$bankName, $masked] = array_map('trim', explode('·', $s['bank'], 2)) + [1 => ''];
            $joined = $ctx->date($s['joined']);

            $id = $ctx->insert('staff', [
                'entity_id' => $ctx->entityId(), 'staff_no' => $s['no'], 'user_id' => $ctx->lookup('users', mb_strtolower($s['name'])),
                'name' => $s['name'], 'job_title' => $s['role'], 'grade' => $s['grade'], 'joined_on' => $joined,
                'left_on' => $ctx->date($s['leaves'] ?? null), 'kra_pin_encrypted' => $encrypt($s['kra']),
                'nssf_no_encrypted' => $encrypt($s['nssfNo']), 'bank_name' => $bankName,
                'bank_account_last4' => preg_match('/(\d{1,4})$/', $masked, $m) === 1 ? $m[1] : null, 'created_at' => $now,
            ]);
            $ctx->remember('staff', mb_strtolower($s['name']), $id);

            foreach (self::PAY_ITEMS as $field => $component) {
                if (($s[$field] ?? 0) <= 0) {
                    continue;
                }
                $from = $field === 'acting' && isset($s['actingFrom']) ? $ctx->monthBounds($s['actingFrom'])[0] : $joined;
                $ctx->insert('staff_pay_items', [
                    'staff_id' => $id, 'pay_component_id' => $ctx->require('pay_components', $component), 'amount' => $s[$field],
                    'effective_from' => $from, 'created_at' => $now,
                ]);
            }

            foreach ($s['alloc'] as $a) {
                $grant = $ctx->grantId($a['grant']);
                $ctx->insert('staff_allocations', [
                    'staff_id' => $id, 'fund_id' => $ctx->fundId($a['fund'], $grant, $a['program']), 'programme_id' => $ctx->programmeId($a['program']),
                    'grant_id' => $grant, 'allocation_pct' => $a['pct'], 'effective_from' => $joined, 'created_at' => $now,
                ]);
            }
        }
    }
}
