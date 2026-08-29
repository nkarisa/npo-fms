<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Payroll extends BaseApiController
{
    private const PR_NITA = 50;

    private function gross(array $s): float
    {
        return $s['basic'] + $s['house'] + $s['transport'] + ($s['acting'] ?? 0);
    }

    private function paye(array $s): float
    {
        $taxable = max(0, $this->gross($s) - 0);
        $bands = [[24000, 0.10], [8333, 0.25], [467667, 0.30], [300000, 0.325], [PHP_INT_MAX, 0.35]];
        $left = $taxable;
        $tax = 0;
        foreach ($bands as [$width, $rate]) {
            $amt = min($left, $width);
            $tax += $amt * $rate;
            $left -= $amt;
            if ($left <= 0) break;
        }
        return max(0, round($tax - 2400));
    }

    private function nssf(array $s): float
    {
        return round(min($this->gross($s), 72000) * 0.06);
    }

    private function shif(array $s): float
    {
        return max(300, round($this->gross($s) * 0.0275));
    }

    private function ahl(array $s): float
    {
        return round($this->gross($s) * 0.015);
    }

    private function net(array $s): float
    {
        return $this->gross($s) - $this->paye($s) - $this->nssf($s) - $this->shif($s) - $this->ahl($s) - ($s['sacco'] ?? 0) - ($s['advance'] ?? 0);
    }

    private function employerTotal(array $s): float
    {
        return $this->nssf($s) + $this->ahl($s) + self::PR_NITA;
    }

    private function cost(array $s): float
    {
        return $this->gross($s) + $this->employerTotal($s);
    }

    public function index()
    {
        $staff  = Prototype::load('PSTAFF');
        $filter = $this->request->getGet('filter') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($staff, function ($s) use ($filter, $q) {
            if ($q !== '' && !str_contains(strtolower($s['no'] . ' ' . $s['name'] . ' ' . $s['role'] . ' ' . $s['grade']), $q)) {
                return false;
            }
            $grantFunded = false;
            $coreFunded  = true;
            foreach ($s['alloc'] as $a) {
                if ($a['grant'] !== 'Unassigned') {
                    $grantFunded = true;
                    $coreFunded = false;
                }
            }
            if ($filter === 'Grant funded') return $grantFunded;
            if ($filter === 'Core funded') return $coreFunded;
            return true;
        }));

        $rows = array_map(fn ($s) => [
            'no' => $s['no'], 'name' => $s['name'], 'role' => $s['role'], 'grade' => $s['grade'],
            'gross' => Prototype::fmt($this->gross($s)), 'paye' => Prototype::fmt($this->paye($s)),
            'stat' => Prototype::fmt($this->nssf($s) + $this->shif($s) + $this->ahl($s)),
            'net' => Prototype::fmt($this->net($s)),
            'alloc' => implode(' · ', array_map(fn ($a) => ($a['grant'] === 'Unassigned' ? 'Core' : explode('/', $a['grant'])[0]) . ' ' . $a['pct'] . '%', $s['alloc'])),
        ], $filtered);

        $grossTotal = array_sum(array_map(fn ($s) => $this->gross($s), $staff));
        $payeTotal  = array_sum(array_map(fn ($s) => $this->paye($s), $staff));
        $statTotal  = array_sum(array_map(fn ($s) => $this->nssf($s) + $this->shif($s) + $this->ahl($s), $staff));
        $erTotal    = array_sum(array_map(fn ($s) => $this->employerTotal($s), $staff));
        $netTotal   = array_sum(array_map(fn ($s) => $this->net($s), $staff));
        $costTotal  = $grossTotal + $erTotal;

        return $this->json([
            'rows'  => $rows,
            'total' => count($staff),
            'period' => 'Aug 2026',
            'periodOptions' => ['Jun 2026', 'Jul 2026', 'Aug 2026'],
            'tabs' => ['All', 'Grant funded', 'Core funded', 'Changes this month'],
            'stats' => [
                ['label' => 'Gross pay', 'value' => Prototype::fmt($grossTotal), 'note' => count($staff) . ' staff on the run'],
                ['label' => 'Statutory deductions', 'value' => Prototype::fmt($payeTotal + $statTotal), 'note' => 'PAYE, NSSF, SHIF, housing levy'],
                ['label' => 'Employer contributions', 'value' => Prototype::fmt($erTotal), 'note' => 'NSSF match, levy and NITA'],
                ['label' => 'Net pay to staff', 'value' => Prototype::fmt($netTotal), 'note' => 'to be released from KCB Current'],
                ['label' => 'Total cost to ELOG', 'value' => Prototype::fmt($costTotal), 'note' => 'gross + employer contributions'],
            ],
        ]);
    }
}
