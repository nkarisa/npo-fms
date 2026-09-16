<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\PayrollRepository;
use App\Repositories\PeriodRepository;

class Payroll extends BaseApiController
{
    private PayrollRepository $repo;

    private function repo(): PayrollRepository
    {
        return $this->repo ??= new PayrollRepository();
    }

    private function gross(array $s): float
    {
        return $s['basic'] + $s['house'] + $s['transport'] + ($s['acting'] ?? 0);
    }

    /** Applies banded rates in force: each band's percentage on the slice of pay within it. */
    private function banded(float $pay, string $scheme): float
    {
        $tax = 0.0;
        foreach ($this->repo()->rates($scheme) as $band) {
            if ($pay <= $band['lower']) {
                break;
            }
            $slice = ($band['upper'] === null ? $pay : min($pay, $band['upper'])) - $band['lower'];
            $tax  += $band['fixed'] ?? $slice * $band['pct'] / 100;
        }

        return $tax;
    }

    private function relief(): float
    {
        return (float) ($this->repo()->rates('personal_relief')[0]['fixed'] ?? 0);
    }

    private function paye(array $s): float
    {
        return max(0, round($this->banded($this->gross($s), 'paye') - $this->relief()));
    }

    private function nssf(array $s): float
    {
        return round($this->banded($this->gross($s), 'nssf'));
    }

    private function shif(array $s): float
    {
        // A fixed minimum band followed by a percentage: the charge is whichever band pay falls in.
        $gross = $this->gross($s);
        foreach (array_reverse($this->repo()->rates('shif')) as $band) {
            if ($gross >= $band['lower']) {
                return round($band['fixed'] ?? $gross * $band['pct'] / 100);
            }
        }

        return 0.0;
    }

    private function ahl(array $s): float
    {
        return round($this->banded($this->gross($s), 'housing_levy'));
    }

    private function nita(): float
    {
        return (float) ($this->repo()->rates('nita')[0]['fixed'] ?? 0);
    }

    private function net(array $s): float
    {
        return $this->gross($s) - $this->paye($s) - $this->nssf($s) - $this->shif($s) - $this->ahl($s) - ($s['sacco'] ?? 0) - ($s['advance'] ?? 0);
    }

    private function employerTotal(array $s): float
    {
        return $this->nssf($s) + $this->ahl($s) + $this->nita();
    }

    private function cost(array $s): float
    {
        return $this->gross($s) + $this->employerTotal($s);
    }

    public function index()
    {
        $staff  = $this->repo()->staff();
        // Payroll is personal data: every read of the register is logged against the reader.
        $this->repo()->logView($this->actorId(), (string) $this->request->getIPAddress(), (string) $this->request->getUserAgent());
        $period = (new PeriodRepository())->today();
        $names  = (new PeriodRepository())->names();
        $at     = array_search($period['name'] ?? '', $names, true);
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
            'period' => $period['name'] ?? '',
            'periodOptions' => $at === false ? [] : array_slice($names, max(0, $at - 2), min(3, $at + 1)),
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
