<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class PeriodClose extends BaseApiController
{
    public function index()
    {
        $period  = $this->request->getGet('period') ?: 'Aug 2026';
        $months  = ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026'];
        $closed  = ['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026'];

        $journals = Prototype::load('JOURNALS');
        $inPeriod = array_values(array_filter($journals, fn ($j) => $j['period'] === $period));
        $posted   = array_values(array_filter($inPeriod, fn ($j) => $j['status'] === 'Posted'));
        $open     = array_values(array_filter($inPeriod, fn ($j) => in_array($j['status'], ['Draft', 'Pending approval'], true)));

        $bills    = Prototype::load('BILLS');
        $awaiting = count(array_filter($bills, fn ($b) => $b['status'] === 'Awaiting approval'));
        $overdue  = count(array_filter($bills, fn ($b) => $b['dueIn'] < 0 && !in_array($b['status'], ['Paid', 'Rejected'], true)));

        $tasks = [
            ['label' => 'Every journal in the period is approved and posted', 'ok' => count($open) === 0, 'note' => count($open) ? count($open) . ' journals still open' : 'No drafts and nothing awaiting approval'],
            ['label' => 'Supplier bills for the period are captured and approved', 'ok' => ($awaiting + $overdue) === 0, 'note' => $awaiting . ' awaiting sign-off, ' . $overdue . ' past due'],
            ['label' => 'Bank accounts reconciled to statement', 'ok' => false, 'note' => 'Reconciliation pending sign-off'],
            ['label' => 'Payroll posted and statutory deductions accrued', 'ok' => true, 'note' => 'Posted for the period'],
            ['label' => 'Depreciation charged for the month', 'ok' => true, 'note' => 'Posted to 5350'],
            ['label' => 'Donor reports reconcile to the ledger', 'ok' => true, 'note' => 'All reports tie to postings'],
            ['label' => 'Trial balance in balance', 'ok' => true, 'note' => 'Debits equal credits'],
        ];
        $settled = count(array_filter($tasks, fn ($t) => $t['ok']));

        return $this->json([
            'period'  => $period,
            'periods' => array_map(fn ($m) => ['label' => $m, 'state' => in_array($m, $closed, true) ? 'closed' : 'open'], $months),
            'tasks'   => $tasks,
            'pct'     => (int) round($settled / max(1, count($tasks)) * 100),
            'totals' => [
                ['label' => 'Journals posted', 'value' => (string) count($posted)],
                ['label' => 'Journals still open', 'value' => (string) count($open)],
                ['label' => 'Total debits', 'value' => Prototype::fmt(array_sum(array_map(fn ($j) => array_sum(array_map(fn ($l) => $l['dr'] ?? 0, $j['lines'])), $posted)))],
                ['label' => 'Total credits', 'value' => Prototype::fmt(array_sum(array_map(fn ($j) => array_sum(array_map(fn ($l) => $l['cr'] ?? 0, $j['lines'])), $posted)))],
            ],
        ]);
    }
}
