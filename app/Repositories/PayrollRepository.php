<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The payroll register and the statutory rates in force.
 *
 * This is personal data (Kenya Data Protection Act 2019): every read of the
 * register is logged against the reader, and identifiers (KRA PIN, NSSF, bank
 * account) are never read from their encrypted columns here.
 */
final class PayrollRepository extends Repository
{
    private const COMPONENT_FIELDS = ['basic_salary' => 'basic', 'house_allowance' => 'house', 'transport_allowance' => 'transport',
        'acting_allowance' => 'acting', 'sacco' => 'sacco', 'advance_recovery' => 'advance'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** Staff on the register with the pay and allocation in force today. */
    public function staff(): array
    {
        return $this->cached('staff', function () {
            $today = Clock::date();
            $pay = [];
            foreach ($this->rows(
                'SELECT i.staff_id, c.key, i.amount, i.effective_from FROM {staff_pay_items} i JOIN {pay_components} c ON c.id = i.pay_component_id
                 WHERE i.effective_from <= ? AND (i.effective_to IS NULL OR i.effective_to >= ?)',
                [$today, $today]
            ) as $i) {
                $pay[(int) $i['staff_id']][self::COMPONENT_FIELDS[$i['key']] ?? $i['key']] = ['amount' => self::num($i['amount']), 'from' => $i['effective_from']];
            }

            $alloc = [];
            foreach ($this->rows(
                'SELECT a.*, f.ledger_group, g.award_ref FROM {staff_allocations} a JOIN {funds} f ON f.id = a.fund_id LEFT JOIN {grants} g ON g.id = a.grant_id
                 WHERE a.effective_from <= ? AND (a.effective_to IS NULL OR a.effective_to >= ?) ORDER BY a.id',
                [$today, $today]
            ) as $a) {
                $alloc[(int) $a['staff_id']][] = [
                    'fund' => self::FUND_GROUPS[$a['ledger_group']], 'grant' => $a['award_ref'] ?? 'Unassigned',
                    'program' => $this->lookups->programmeName((int) $a['programme_id']), 'pct' => self::num($a['allocation_pct']),
                ];
            }

            $periodStart = (new PeriodRepository())->today()['starts_on'] ?? substr($today, 0, 8) . '01';

            return array_map(function ($s) use ($pay, $alloc, $periodStart) {
                $id = (int) $s['id'];
                $row = ['no' => $s['staff_no'], 'name' => $s['name'], 'role' => $s['job_title'], 'grade' => $s['grade']];
                foreach (self::COMPONENT_FIELDS as $field) {
                    $row[$field] = $pay[$id][$field]['amount'] ?? 0;
                }
                if (isset($pay[$id]['acting'])) {
                    $row['actingFrom'] = date('M Y', strtotime($pay[$id]['acting']['from']));
                }
                $row['joined'] = self::dmy($s['joined_on']);
                $row['alloc']  = $alloc[$id] ?? [];

                if ($s['joined_on'] >= $periodStart) {
                    $row['isNew'] = true;
                }
                if ($s['left_on'] !== null) {
                    $row['leaves'] = self::dmy($s['left_on']);
                    $row['days'] = (int) date('j', strtotime($s['left_on']));
                }

                return $row;
            }, $this->rows('SELECT * FROM {staff} ORDER BY staff_no'));
        });
    }

    /** Records that a user viewed the payroll register. */
    public function logView(int $userId, string $ip, string $userAgent): void
    {
        $this->insert('personal_data_access_log', [
            'user_id' => $userId, 'action' => 'view', 'object_type' => 'staff', 'object_id' => null,
            'ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($userAgent, 0, 255), 'accessed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Rates in force today for one scheme, lowest band first.
     *
     * @return list<array{lower: float, upper: ?float, pct: float, fixed: ?float}>
     */
    public function rates(string $scheme): array
    {
        return $this->cached("rates:{$scheme}", fn () => array_map(static fn ($r) => [
            'lower' => (float) $r['lower_bound'], 'upper' => $r['upper_bound'] === null ? null : (float) $r['upper_bound'],
            'pct' => (float) $r['rate_pct'], 'fixed' => $r['fixed_amount'] === null ? null : (float) $r['fixed_amount'],
        ], $this->rows(
            'SELECT * FROM {statutory_rates} WHERE scheme = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY band_order',
            [$scheme, Clock::date(), Clock::date()]
        )));
    }
}
