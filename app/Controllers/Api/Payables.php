<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Payables extends BaseApiController
{
    public static function totals(array $b): array
    {
        $vat   = round($b['taxable'] * 0.16);
        $gross = $b['taxable'] + $vat;
        $wht   = round($b['taxable'] * ($b['whtRate'] / 100));

        return ['vat' => $vat, 'gross' => $gross, 'wht' => $wht, 'net' => $gross - $wht];
    }

    private static function bucket(array $b): string
    {
        $d = $b['dueIn'];
        if ($d > 0) return 'Current';
        if ($d >= -30) return '1–30 days';
        if ($d >= -60) return '31–60 days';
        if ($d >= -90) return '61–90 days';
        return 'Over 90 days';
    }

    private static function isOpen(array $b): bool
    {
        return !in_array($b['status'], ['Paid', 'Rejected'], true);
    }

    public function index()
    {
        $all    = Prototype::load('BILLS');
        $status = $this->request->getGet('status') ?: 'All';
        $age    = $this->request->getGet('age') ?: 'All';
        $fund   = $this->request->getGet('fund') ?: 'All funds';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($b) use ($status, $age, $fund, $q) {
            if ($status === 'Overdue') {
                if (!(self::isOpen($b) && $b['dueIn'] < 0)) {
                    return false;
                }
            } elseif ($status !== 'All' && $b['status'] !== $status) {
                return false;
            }
            if ($age !== 'All' && (!self::isOpen($b) || self::bucket($b) !== $age)) {
                return false;
            }
            if ($fund !== 'All funds' && $b['fund'] !== $fund) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($b['no'] . ' ' . $b['supplier'] . ' ' . $b['pin'] . ' ' . $b['category']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(function ($b) {
            $t = self::totals($b);
            return array_merge($b, [
                'vat' => $t['vat'], 'gross' => $t['gross'], 'wht' => $t['wht'], 'net' => $t['net'],
                'grossFmt' => Prototype::fmt($t['gross']), 'whtFmt' => $t['wht'] ? Prototype::fmt($t['wht']) : '—', 'netFmt' => Prototype::fmt($t['net']),
                'overdue' => self::isOpen($b) && $b['dueIn'] < 0,
                'bucket'  => self::bucket($b),
            ]);
        }, $filtered);

        $outstanding = array_values(array_filter($all, fn ($b) => self::isOpen($b)));
        $overdue     = array_values(array_filter($outstanding, fn ($b) => $b['dueIn'] < 0));
        $dueWeek     = array_values(array_filter($outstanding, fn ($b) => $b['dueIn'] >= 0 && $b['dueIn'] <= 7));
        $awaiting    = array_values(array_filter($all, fn ($b) => $b['status'] === 'Awaiting approval'));
        $whtHeld     = array_sum(array_map(fn ($b) => self::totals($b)['wht'], $outstanding));

        $buckets = array_map(function ($k) use ($outstanding) {
            $in = array_values(array_filter($outstanding, fn ($b) => self::bucket($b) === $k));
            return [
                'label' => $k,
                'value' => Prototype::fmt(array_sum(array_map(fn ($b) => self::totals($b)['net'], $in))),
                'count' => count($in),
            ];
        }, ['Current', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days']);

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'fundOptions' => ['All funds', 'General Fund', 'Grant Fund', 'Capital Fund'],
            'methodOptions' => Prototype::load('METHODS'),
            'aging' => $buckets,
            'tabs'  => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : ($s === 'Overdue' ? count($overdue) : count(array_filter($all, fn ($b) => $b['status'] === $s)))], ['All', 'Awaiting approval', 'Approved', 'Scheduled', 'Paid', 'Overdue']),
            'stats' => [
                ['label' => 'Total outstanding', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => self::totals($b)['net'], $outstanding))), 'note' => count($outstanding) . ' open bills'],
                ['label' => 'Overdue', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => self::totals($b)['net'], $overdue))), 'note' => count($overdue) . ' suppliers waiting'],
                ['label' => 'Due within 7 days', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => self::totals($b)['net'], $dueWeek))), 'note' => count($dueWeek) . ' bills'],
                ['label' => 'Awaiting approval', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => self::totals($b)['net'], $awaiting))), 'note' => count($awaiting) . ' need sign-off'],
                ['label' => 'WHT to remit', 'value' => Prototype::fmt($whtHeld), 'note' => 'due to KRA by 20 Sep'],
            ],
        ]);
    }

    public function show($no)
    {
        foreach (Prototype::load('BILLS') as $b) {
            if ($b['no'] === $no) {
                $b['totals'] = self::totals($b);
                return $this->json($b);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
    }
}
