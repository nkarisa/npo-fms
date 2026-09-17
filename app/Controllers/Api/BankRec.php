<?php

namespace App\Controllers\Api;

use App\Repositories\BankRepository;
use App\Repositories\RuleViolation;

/**
 * Bank reconciliation (v5): one cash account's statement for a period against its
 * cash book, with matching, auto-matching on amount, journalising the bank's own
 * entries, and signing off or reopening. Every write answers with the refreshed
 * reconciliation.
 */
class BankRec extends BaseApiController
{
    public function index()
    {
        try {
            return $this->json($this->view((new BankRepository())->reconciliation(...$this->target($this->request->getGet()))));
        } catch (RuleViolation $e) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /** Body: {account, period, statement: [ids], book: [ids]}. */
    public function match()
    {
        return $this->write('match', function (BankRepository $repo, string $code, string $period, array $body) {
            $n = $repo->match($code, $period, (array) ($body['statement'] ?? []), (array) ($body['book'] ?? []), $this->actorId());

            return $n['statement'] . ($n['statement'] === 1 ? ' statement line' : ' statement lines') . ' matched to ' . $n['book'] . ($n['book'] === 1 ? ' posting.' : ' postings.');
        });
    }

    /** Body: {account, period, line}. */
    public function unmatch()
    {
        return $this->write('match', function (BankRepository $repo, string $code, string $period, array $body) {
            $repo->unmatch($code, $period, (int) ($body['line'] ?? 0), $this->actorId());

            return 'Match undone. The lines are open again.';
        });
    }

    /** Body: {account, period}. */
    public function auto()
    {
        return $this->write('match', function (BankRepository $repo, string $code, string $period) {
            $n = $repo->autoMatch($code, $period, $this->actorId());

            return $n === 0
                ? 'Nothing left to match on amount alone — what remains needs a judgement call.'
                : $n . ($n === 1 ? ' line matched' : ' lines matched') . ' on exact amount. Review the dates before signing off.';
        });
    }

    /** Body: {account, period, line}. */
    public function journalise()
    {
        return $this->write('journalise', function (BankRepository $repo, string $code, string $period, array $body) {
            $journal = $repo->journalise($code, $period, (int) ($body['line'] ?? 0), $this->actorId());
            $amount = array_sum(array_column($journal['lines'], 'dr'));

            return $journal['ref'] . ' raised for ' . number_format($amount) . ' and sent for approval. The statement line stays unmatched until the entry is approved in Journals.';
        });
    }

    /** Body: {account, period}. */
    public function complete()
    {
        return $this->write('signoff', function (BankRepository $repo, string $code, string $period) {
            $r = $repo->complete($code, $period, $this->actorId());

            return $r['short'] . ' reconciled for ' . $period . '. The period-close check has been satisfied.';
        });
    }

    /** Body: {account, period}. */
    public function reopen()
    {
        return $this->write('signoff', function (BankRepository $repo, string $code, string $period) {
            $r = $repo->reopen($code, $period, $this->actorId());

            return $r['short'] . ' reopened for rework. The period-close check has been withdrawn until it is signed off again.';
        });
    }

    // ------------------------------------------------------------------

    /** @return array{0: string, 1: string|null} account code and period */
    private function target(array $in): array
    {
        $accounts = (new BankRepository())->accounts();
        $code = (string) ($in['account'] ?? '');
        if (!in_array($code, array_column($accounts, 'code'), true)) {
            $code = $accounts[0]['code'] ?? throw new RuleViolation('No bank statement has been loaded yet.');
        }

        return [$code, isset($in['period']) && $in['period'] !== '' ? (string) $in['period'] : null];
    }

    /**
     * Runs an action for the acting user, if their role allows it, and answers with
     * the message and the reconciliation as it now stands.
     *
     * @param 'match'|'journalise'|'signoff' $right
     */
    private function write(string $right, callable $action)
    {
        $actor = $this->actor();
        $allowed = match ($right) {
            'match'      => $actor['canPrepare'] || $actor['canApprove'],
            'journalise' => $actor['canPrepare'],
            'signoff'    => $actor['canApprove'],
        };
        if (!$allowed) {
            return $this->response->setStatusCode(403)->setJSON(['error' => match ($right) {
                'match'      => $actor['role'] . ' cannot change a bank reconciliation.',
                'journalise' => $actor['role'] . ' cannot raise entries. Switch to a preparer to journalise this line.',
                'signoff'    => $actor['role'] . ' cannot sign off a bank reconciliation.',
            }]);
        }

        $body = $this->request->getJSON(true) ?? [];
        try {
            [$code, $period] = $this->target($body);
            if ($period === null) {
                throw new RuleViolation('Name the statement period.');
            }
            $repo = new BankRepository();
            $message = $action($repo, $code, $period, $body);

            return $this->json(['message' => $message] + $this->view((new BankRepository())->reconciliation($code, $period)));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /** The reconciliation as the screen draws it. */
    private function view(array $r): array
    {
        $fmt = [BankRepository::class, 'money'];
        $sum = static fn (array $lines) => array_sum(array_column($lines, 'amt'));
        $actor = $this->actor();
        $signedOff = $r['status'] === 'completed';
        $locked = $signedOff || !$r['periodOpen'];
        $gap = $r['difference'];
        $pay = $r['unclearedPayments'];
        $rec = $r['unclearedReceipts'];
        $open = $r['unmatchedStatement'];
        $uncleared = count($pay) + count($rec);
        $plural = static fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
        $bookByMatch = [];
        foreach ($r['book'] as $b) {
            if ($b['match'] !== null) {
                $bookByMatch[$b['match']][] = $b['ref'];
            }
        }
        $raised = count(array_filter($r['book'], static fn ($b) => $b['raised']));

        return [
            'accountOptions' => array_map(static fn ($a) => ['code' => $a['code'], 'label' => $a['code'] . ' · ' . $a['name']], (new BankRepository())->accounts()),
            'account'        => $r['code'],
            'periodOptions'  => $r['periods'],
            'period'         => $r['period'],
            'statementRef'   => $r['statementRef'],
            'kicker'         => $signedOff ? 'Reconciled and signed off' : ($gap == 0 && $open === [] ? 'Agreed · awaiting sign-off' : $plural(count($open), 'statement line', 'statement lines') . ' to explain'),
            'stats' => [
                ['label' => 'Balance per statement', 'value' => $fmt($r['statementClose']), 'note' => 'as at ' . $r['periodEnd']],
                ['label' => 'Balance per cash book', 'value' => $fmt($r['bookClose']), 'note' => $plural(count($r['book']), 'posting', 'postings') . ' in the period'],
                ['label' => 'Payments not yet presented', 'value' => $fmt(-$sum($pay)),
                    'note' => $rec !== [] ? count($pay) . ' out, ' . $fmt($sum($rec)) . ' in transit' : $plural(count($pay), 'cheque or transfer', 'cheques and transfers')],
                ['label' => 'Unmatched on statement', 'value' => (string) count($open), 'note' => $open !== [] ? $fmt($sum($open)) . ' unexplained' : 'nothing left to explain'],
                ['label' => 'Difference', 'value' => $fmt($gap), 'note' => $gap == 0 ? 'reconciled' : 'must be nil to complete'],
            ],
            'signedOff'   => $signedOff,
            'locked'      => $locked,
            'periodOpen'  => $r['periodOpen'],
            'signedOffBy' => $signedOff ? 'Signed off by ' . $r['reviewedBy'] . ' on ' . $r['completedAt'] : '',
            'can' => [
                'match'      => !$locked && ($actor['canPrepare'] || $actor['canApprove']),
                'journalise' => !$locked && $actor['canPrepare'],
                'complete'   => !$locked && $actor['canApprove'] && $gap == 0 && $open === [],
                'reopen'     => $signedOff && $r['periodOpen'] && $actor['canApprove'],
            ],
            'completeLabel' => $signedOff ? 'Reconciled ✓' : ($gap == 0 && $open === [] ? 'Complete reconciliation' : 'Cannot complete · ' . $fmt($gap)),
            'statement' => [
                'closing' => $fmt($r['statementClose']),
                'hint'    => $open !== [] ? count($open) . ' of ' . count($r['statement']) . ' unmatched' : 'all ' . count($r['statement']) . ' matched',
                'footer'  => 'Lines the bank has processed · a line with no posting behind it is either a timing difference or something the bank did that the book does not know about',
                'rows'    => array_map(static function ($l) use ($fmt, $bookByMatch, $r) {
                    $entry = BankRepository::BANK_ENTRIES[$l['entry'] ?? ''] ?? null;
                    $live = $l['raised'] !== null && in_array($l['raised']['status'], ['draft', 'pending_approval'], true);

                    return [
                        'id' => $l['id'], 'date' => $l['date'], 'ref' => $l['ref'], 'desc' => $l['desc'], 'amt' => $l['amt'], 'amount' => $fmt($l['amt']),
                        'matched' => $l['match'] !== null, 'matchRef' => implode(', ', $bookByMatch[$l['match']] ?? []),
                        'journalLabel' => $entry !== null && $l['match'] === null && !$live ? ($r['kind'] === 'mobile_money' ? ($entry['mobile'] ?? $entry['label']) : $entry['label']) : '',
                        'pendingRef' => $live && $l['match'] === null ? $l['raised']['ref'] : '',
                    ];
                }, $r['statement']),
            ],
            'book' => [
                'closing' => $fmt($r['bookClose']),
                'hint'    => $uncleared ? $uncleared . ' uncleared' : 'all cleared',
                'footer'  => 'Postings against ' . $r['code'] . ' · ' . $plural($raised, 'journal', 'journals') . ' raised from this statement',
                'rows'    => array_map(static fn ($l) => [
                    'id' => $l['id'], 'date' => $l['date'], 'ref' => $l['ref'], 'desc' => $l['desc'], 'amt' => $l['amt'], 'amount' => $fmt($l['amt']),
                    'matched' => $l['match'] !== null, 'raised' => $l['raised'],
                ], $r['book']),
            ],
            'recon' => [
                ['label' => 'Balance per cash book', 'note' => $r['code'] . ' · closing', 'value' => $fmt($r['bookClose']), 'kind' => 'plain'],
                ['label' => 'Add: payments issued but not yet presented', 'note' => $plural(count($pay), 'item', 'items'), 'value' => $fmt(-$sum($pay)), 'kind' => 'indent'],
                ['label' => 'Less: receipts banked but not yet credited', 'note' => $plural(count($rec), 'item', 'items'), 'value' => $fmt(-$sum($rec)), 'kind' => 'indent'],
                ['label' => 'Adjusted balance per cash book', 'note' => '', 'value' => $fmt($r['adjustedBook']), 'kind' => 'total'],
                ['label' => 'Balance per bank statement', 'note' => $r['short'], 'value' => $fmt($r['statementClose']), 'kind' => 'plain'],
                ['label' => 'Unexplained difference', 'note' => $open !== [] ? $plural(count($open), 'statement line unmatched', 'statement lines unmatched') : 'nil', 'value' => $fmt($gap), 'kind' => 'final'],
            ],
            'reconHint'  => $signedOff ? 'Signed off · locked' : ($r['periodOpen'] ? $r['period'] . ' · ' . $r['short'] : $r['period'] . ' · period closed'),
            'reconciled' => $gap == 0 && $open === [],
            'gapNote'    => $gap != 0
                ? $fmt($gap) . ' on the statement is not yet in the cash book — '
                    . (array_filter($open, static fn ($l) => $l['entry'] !== null) ? 'journalise the bank\'s own entries and match the rest' : 'match the remaining statement lines to their postings')
                : $plural(count($open), 'statement line nets', 'statement lines net') . ' to nil but still needs matching before sign-off',
        ];
    }
}
