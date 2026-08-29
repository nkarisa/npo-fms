<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class Dashboard extends BaseApiController
{
    public function index()
    {
        $seed  = Prototype::load('SEED');
        $bills = Prototype::load('BILLS');
        $journals = Prototype::load('JOURNALS');
        $grants = Prototype::load('GRANTS');
        $funds  = Prototype::load('FUNDS');
        $dreports = Prototype::load('DREPORTS');

        $leaves = array_values(array_filter($seed, fn ($a) => $a['level'] === 2));
        $bal = fn ($code) => array_sum(array_map(fn ($a) => $a['balance'], array_filter($seed, fn ($a) => $a['code'] === $code)));
        $cash = $bal('1110') + $bal('1120') + $bal('1130') + $bal('1140');
        $income = array_sum(array_map(fn ($a) => $a['balance'], array_filter($leaves, fn ($a) => $a['type'] === 'Income')));
        $expense = array_sum(array_map(fn ($a) => $a['balance'], array_filter($leaves, fn ($a) => $a['type'] === 'Expense')));
        $restricted = array_sum(array_map(fn ($a) => $a['balance'], array_filter($leaves, fn ($a) => $a['restriction'] === 'Restricted')));

        $totals = fn ($b) => Payables::totals($b);
        $outstanding = array_values(array_filter($bills, fn ($b) => !in_array($b['status'], ['Paid', 'Rejected'], true)));
        $overdue = array_values(array_filter($outstanding, fn ($b) => $b['dueIn'] < 0));
        $awaiting = array_values(array_filter($bills, fn ($b) => $b['status'] === 'Awaiting approval'));
        $payable = array_sum(array_map(fn ($b) => $totals($b)['net'], $outstanding));

        $pendingJournals = count(array_filter($journals, fn ($j) => $j['status'] === 'Pending approval'));
        $unbalancedDrafts = count(array_filter($journals, function ($j) {
            if ($j['status'] !== 'Draft') return false;
            $dr = array_sum(array_map(fn ($l) => $l['dr'] ?? 0, $j['lines']));
            $cr = array_sum(array_map(fn ($l) => $l['cr'] ?? 0, $j['lines']));
            return $dr !== $cr;
        }));

        $untied = array_values(array_filter($dreports, function ($r) {
            $cum = array_sum(array_map(fn ($l) => $l['cumulative'], $r['lines']));
            return ($cum + $r['reportedAdj']) !== $cum;
        }));

        $live = array_values(array_filter($grants, fn ($g) => in_array($g['status'], ['Active', 'Closing'], true)));

        $queue = array_filter([
            $pendingJournals > 0 ? ['title' => 'Journals waiting for your approval', 'detail' => 'Nothing posts to the ledger until you sign these off', 'value' => $pendingJournals . ' journals', 'tone' => 'warn', 'href' => '/journals?status=Pending+approval'] : null,
            count($awaiting) > 0 ? ['title' => 'Supplier bills waiting for sign-off', 'detail' => count($awaiting) . ' bills above the delegated limit', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => $totals($b)['net'], $awaiting))), 'tone' => 'warn', 'href' => '/payables?status=Awaiting+approval'] : null,
            count($overdue) > 0 ? ['title' => 'Suppliers already past due', 'detail' => 'Late payment risks the field logistics contracts', 'value' => Prototype::fmt(array_sum(array_map(fn ($b) => $totals($b)['net'], $overdue))), 'tone' => 'urgent', 'href' => '/payables?status=Overdue'] : null,
            count($untied) > 0 ? ['title' => 'Donor reports that do not tie to the ledger', 'detail' => 'These cannot be submitted until resolved', 'value' => count($untied) . ' reports', 'tone' => 'urgent', 'href' => '/donor-reports'] : null,
            $unbalancedDrafts > 0 ? ['title' => 'Draft journals out of balance', 'detail' => 'Debits and credits do not agree', 'value' => $unbalancedDrafts . ' drafts', 'tone' => 'urgent', 'href' => '/journals?status=Draft'] : null,
        ]);

        $dashGrants = array_slice($live, 0, 4);

        return $this->json([
            'date' => 'Thursday, 27 August 2026 · August open',
            'stats' => [
                ['label' => 'Cash and bank', 'value' => Prototype::fmt($cash), 'note' => Prototype::fmt($bal('1120')) . ' of it restricted'],
                ['label' => 'Restricted funds held', 'value' => Prototype::fmt($restricted), 'note' => 'donor purposes'],
                ['label' => 'Unspent commitment', 'value' => Prototype::fmt(array_sum(array_map(fn ($g) => $g['value'] - $g['spent'], $live))), 'note' => count($live) . ' live awards'],
                ['label' => 'Owed to suppliers', 'value' => Prototype::fmt($payable), 'note' => count($overdue) . ' bills past due'],
                ['label' => 'Surplus year to date', 'value' => Prototype::fmt($income - $expense), 'note' => Prototype::fmt($income) . ' income, ' . Prototype::fmt($expense) . ' spent'],
            ],
            'queue' => array_values($queue),
            'grants' => array_map(fn ($g) => [
                'funder' => $g['funder'], 'program' => $g['program'],
                'money' => Prototype::fmt($g['spent']) . ' of ' . Prototype::fmt($g['value']),
                'burnPct' => $g['value'] > 0 ? (int) round($g['spent'] / $g['value'] * 100) : 0,
                'elapsed' => $g['elapsed'], 'ref' => $g['ref'],
            ], $dashGrants),
            'funds' => array_map(fn ($f) => [
                'name' => $f['name'],
                'value' => Prototype::fmt($f['opening'] + $f['income'] - $f['spend'] + $f['transfers']),
            ], array_slice($funds, 0, 5)),
            'activity' => array_slice(Prototype::load('ST_AUDIT'), 0, 4),
        ]);
    }
}
