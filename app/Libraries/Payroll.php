<?php

namespace App\Libraries;

/**
 * What one post costs for one month.
 *
 * The rates and the benefits are data — statutory_rates as the Finance Act
 * leaves them, pay_components as Settings defines them — so nothing here is a
 * rate. The order is the order the law works in: earnings make gross, the three
 * statutory deductions come off gross to leave taxable pay, PAYE is charged on
 * what is left, and the employer's own contributions sit outside pay altogether.
 *
 * A joiner or a leaver is paid for the days worked, so every earning is
 * pro-rated before anything is charged on it.
 */
final class Payroll
{
    /**
     * @param array<string, list<array{lower: float, upper: ?float, pct: float, fixed: ?float}>> $rates  by scheme
     * @param list<array{key: string, name: string, taxable: bool}>                              $benefits in payslip order
     */
    public function __construct(private array $rates, private array $benefits)
    {
    }

    /**
     * One payslip, in full.
     *
     * $s carries basic, acting, ben (by benefit key), sacco and advance for the
     * month, and days/monthDays where the month is not worked in full.
     *
     * @return array{basic: float, acting: float, benefits: list<array{key: string, name: string, taxable: bool, amount: float}>,
     *     gross: float, taxable: float, paye: float, nssf: float, shif: float, housingLevy: float, sacco: float,
     *     advance: float, deductions: float, net: float, employer: array{nssf: float, housingLevy: float, nita: float},
     *     employerCost: float, cost: float}
     */
    public function payslip(array $s): array
    {
        $portion = ($s['days'] ?? 0) > 0 ? $s['days'] / ($s['monthDays'] ?? 31) : 1.0;
        $part    = static fn ($amount) => round((float) $amount * $portion);

        $basic    = $part($s['basic'] ?? 0);
        $acting   = $part($s['acting'] ?? 0);
        $benefits = array_map(static fn ($b) => $b + ['amount' => $part($s['ben'][$b['key']] ?? 0)], $this->benefits);

        $gross   = $basic + $acting + array_sum(array_column($benefits, 'amount'));
        $untaxed = array_sum(array_map(static fn ($b) => $b['taxable'] ? 0 : $b['amount'], $benefits));

        $nssf    = round($this->banded($gross, 'nssf'));
        $shif    = $this->floored($gross, 'shif');
        $housing = round($this->banded($gross, 'housing_levy'));

        // Only the statutory three are allowable against pay before PAYE; the
        // sacco and an advance recovery are the employee spending their own net.
        $taxable = max(0, $gross - $untaxed - $nssf - $shif - $housing);
        $paye    = max(0, round($this->banded($taxable, 'paye')) - $this->fixed('personal_relief'));

        $sacco   = (float) ($s['sacco'] ?? 0);
        $advance = (float) ($s['advance'] ?? 0);
        $deducted = $paye + $nssf + $shif + $housing + $sacco + $advance;

        $employer = ['nssf' => $nssf, 'housingLevy' => $housing, 'nita' => $this->fixed('nita')];

        return [
            'basic' => $basic, 'acting' => $acting, 'benefits' => $benefits,
            'gross' => $gross, 'taxable' => $taxable,
            'paye' => $paye, 'nssf' => $nssf, 'shif' => $shif, 'housingLevy' => $housing,
            'sacco' => $sacco, 'advance' => $advance, 'deductions' => $deducted,
            'net' => $gross - $deducted,
            'employer' => $employer, 'employerCost' => array_sum($employer),
            'cost' => $gross + array_sum($employer),
        ];
    }

    /** Totals across a roster, on the same keys one payslip carries. */
    public function totals(array $payslips): array
    {
        $sum = static fn (string $key) => array_sum(array_column($payslips, $key));

        return [
            'gross' => $sum('gross'), 'paye' => $sum('paye'), 'nssf' => $sum('nssf'), 'shif' => $sum('shif'),
            'housingLevy' => $sum('housingLevy'), 'sacco' => $sum('sacco'), 'advance' => $sum('advance'),
            'deductions' => $sum('deductions'), 'net' => $sum('net'), 'employerCost' => $sum('employerCost'),
            'cost' => $sum('cost'), 'nita' => array_sum(array_map(static fn ($p) => $p['employer']['nita'], $payslips)),
            'staff' => count($payslips),
        ];
    }

    /**
     * Splits an amount over percentages without losing a shilling: each share is
     * rounded and the last one takes what is left.
     *
     * @param  list<array{pct: float}> $split
     * @return list<float>
     */
    public static function apportion(float $amount, array $split): array
    {
        $left = $amount;
        $out  = [];
        foreach ($split as $i => $a) {
            $share = $i === count($split) - 1 ? $left : round($amount * $a['pct'] / 100);
            $left -= $share;
            $out[] = $share;
        }

        return $out;
    }

    /** Each band's percentage on the slice of pay that falls within it. */
    private function banded(float $pay, string $scheme): float
    {
        $charge = 0.0;
        foreach ($this->rates[$scheme] ?? [] as $band) {
            if ($pay <= $band['lower']) {
                break;
            }
            $slice   = ($band['upper'] === null ? $pay : min($pay, $band['upper'])) - $band['lower'];
            $charge += $band['fixed'] ?? $slice * $band['pct'] / 100;
        }

        return $charge;
    }

    /** A percentage of pay with a floor below which a flat minimum is charged instead. */
    private function floored(float $pay, string $scheme): float
    {
        foreach (array_reverse($this->rates[$scheme] ?? []) as $band) {
            if ($pay >= $band['lower']) {
                return $band['fixed'] ?? round($pay * $band['pct'] / 100);
            }
        }

        return 0.0;
    }

    private function fixed(string $scheme): float
    {
        return (float) ($this->rates[$scheme][0]['fixed'] ?? 0);
    }
}
