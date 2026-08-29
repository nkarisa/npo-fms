<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;

class BankRec extends BaseApiController
{
    public function index()
    {
        $accounts = Prototype::load('BR_ACCOUNTS');
        $code = $this->request->getGet('account') ?: ($accounts[0]['code'] ?? null);

        $acct = null;
        foreach ($accounts as $a) {
            if ($a['code'] === $code) {
                $acct = $a;
                break;
            }
        }
        $acct ??= $accounts[0];

        $sum = fn ($rows) => array_sum(array_map(fn ($r) => $r['amt'], $rows));
        $stmtClose = $acct['opening'] + $sum($acct['stmt']);
        $bookClose = $acct['opening'] + $sum($acct['book']);
        $matchedS  = array_unique(array_merge(...array_map(fn ($m) => $m['s'], $acct['matches'] ?? [])));
        $matchedB  = array_unique(array_merge(...array_map(fn ($m) => $m['b'], $acct['matches'] ?? [])));
        $unclearedBook = array_values(array_filter($acct['book'], fn ($l) => !in_array($l['id'], $matchedB, true)));
        $openStmt      = array_values(array_filter($acct['stmt'], fn ($l) => !in_array($l['id'], $matchedS, true)));
        $gap = $stmtClose - ($bookClose - $sum($unclearedBook));

        return $this->json([
            'accountOptions' => array_map(fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], $accounts),
            'account'   => $acct,
            'stmtRows'  => $acct['stmt'],
            'bookRows'  => $acct['book'],
            'stats' => [
                ['label' => 'Balance per statement', 'value' => Prototype::fmt($stmtClose), 'note' => 'per bank statement'],
                ['label' => 'Balance per cash book', 'value' => Prototype::fmt($bookClose), 'note' => count($acct['book']) . ' postings in the period'],
                ['label' => 'Unmatched on statement', 'value' => (string) count($openStmt), 'note' => count($openStmt) ? 'unexplained' : 'nothing left to explain'],
                ['label' => 'Uncleared in book', 'value' => (string) count($unclearedBook), 'note' => 'not yet presented'],
                ['label' => 'Difference', 'value' => Prototype::fmt($gap), 'note' => $gap == 0 ? 'reconciled' : 'must be nil to complete'],
            ],
        ]);
    }
}
