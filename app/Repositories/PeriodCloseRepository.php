<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Ledger;
use App\Libraries\Prototype;

/**
 * Period close: the checklist that has to settle before a month is locked, the
 * close and reopen themselves, and the close pack assembled as evidence.
 *
 * Ledger checks are read from the books every time; confirmations are ticked by
 * the people who did the work (period_close_steps). A closed period is settled
 * history: every item passed when it was locked, and the database refuses any
 * change to its confirmations until it is reopened.
 */
final class PeriodCloseRepository extends Repository
{
    public const ORGANISATION = 'Elections Observation Group';

    /** How many pills the year switcher shows before the rest move to "Earlier". */
    private const SHOWN_YEARS = 3;

    /** Confirmations withdrawn when a period is reopened, so the close must be retaken. */
    private const WITHDRAWN_ON_REOPEN = ['review', 'signoff'];

    /** Where each check's evidence is worked on: [link label, page]. */
    private const LINKS = [
        'journals'  => ['Open journals', '/journals'],
        'bills'     => ['Open payables', '/payables'],
        'bank'      => ['Open reconciliation', '/bank-rec'],
        'mpesa'     => ['Open reconciliation', '/bank-rec'],
        'payroll'   => ['Open payroll', '/payroll'],
        'disposals' => ['Open asset register', '/asset-register'],
        'depn'      => ['Open asset register', '/asset-register'],
        'segments'  => ['Open segments', '/settings'],
        'donor'     => ['Open donor reports', '/donor-reports'],
        'budget'    => ['Open budgets', '/budgets'],
        'tb'        => ['Open trial balance', '/reports'],
    ];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /** A period by code ("2026-08") or name ("Aug 2026"). */
    public function period(string $key): ?array
    {
        foreach ($this->lookups->periods() as $p) {
            if ($p['code'] === $key || $p['name'] === $key) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Fiscal years and the months of each that can be closed: every month of a past
     * year, and in the working year the months up to the one after today.
     *
     * @return list<array{code: string, year: int, periods: list<array>}>
     */
    public function years(): array
    {
        $horizon = date('Y-m-d', strtotime('last day of next month', strtotime(Clock::date())));
        $years = [];
        foreach ($this->rows('SELECT * FROM {fiscal_years} WHERE entity_id = ? ORDER BY starts_on', [$this->lookups->entityId()]) as $fy) {
            $periods = array_values(array_filter(
                $this->lookups->periods(),
                static fn ($p) => $p['fiscal_year_id'] === $fy['id'] && ($p['status'] === 'closed' || $p['starts_on'] <= $horizon)
            ));
            if ($periods !== []) {
                $years[] = ['code' => $fy['code'], 'year' => (int) substr($fy['starts_on'], 0, 4), 'periods' => $periods];
            }
        }

        return $years;
    }

    /** Everything the period close screen shows for one period, as seen by the actor. */
    public function overview(array $period, int $year, array $actor): array
    {
        $closed   = $period['status'] === 'closed';
        $tasks    = $this->checklist($period);
        $blockers = array_values(array_filter($tasks, static fn ($t) => !$t['settled']));
        $ledgerBlockers = array_values(array_filter($blockers, static fn ($t) => $t['kind'] === 'ledger'));
        $pct      = (int) round(count(array_filter($tasks, static fn ($t) => $t['settled'])) / max(1, count($tasks)) * 100);
        $totals   = $this->totals($period);

        return [
            'period'     => ['code' => $period['code'], 'name' => $period['name'], 'closed' => $closed],
            'kicker'     => 'Ledger · ' . ($closed ? 'closed' : 'open for posting'),
            'years'      => $this->yearSwitcher($year, $period),
            'tasks'      => array_map(static fn ($t) => $t + [
                'canTick' => $t['kind'] === 'confirmation' && !$closed && in_array($t['permission'], $actor['permissions'], true),
            ], $tasks),
            'checklistHint' => $closed ? 'All ' . count($tasks) . ' items settled at close'
                : ($blockers ? count($blockers) . ' of ' . count($tasks) . ' outstanding' : 'All ' . count($tasks) . ' items settled'),
            'footer'     => $closed
                ? $this->closeMeta($period) . ' · reopening is recorded in the audit log'
                : count($ledgerBlockers) . ' ledger checks failing · ' . (count($blockers) - count($ledgerBlockers)) . ' confirmations outstanding',
            'readiness'  => [
                'pct'  => $pct,
                'tone' => $pct === 100 ? 'done' : ($ledgerBlockers ? 'blocked' : 'confirming'),
                'note' => $closed
                    ? $period['name'] . ' is closed. No journal can be posted into it and the balances carried forward are fixed.'
                    : ($blockers === []
                        ? 'Everything is settled. Closing will lock ' . $period['name'] . ' against further posting and carry the balances forward.'
                        : ($ledgerBlockers
                            ? 'The ledger is not ready: ' . lcfirst($ledgerBlockers[0]['label']) . ' is still failing. Clear the ledger checks first, then take the confirmations.'
                            : 'The ledger checks pass. What remains are the confirmations from the people who did the work.')),
                'lock' => $closed ? 'Locked' : date('d M Y', strtotime($period['starts_on'])) . ' onwards',
            ],
            'close'      => [
                'blockers'   => count($blockers),
                'label'      => $blockers ? 'Close blocked · ' . count($blockers) . ' open' : 'Close ' . $period['name'],
                'canClose'   => in_array('period.close', $actor['permissions'], true),
                'canReopen'  => in_array('period.authorise', $actor['permissions'], true),
                'firstBlocker' => $blockers[0]['label'] ?? '',
            ],
            'totals'     => $totals,
            'history'    => $this->history(),
            'pack'       => $this->packContents($period, $tasks, $totals),
        ];
    }

    /**
     * The checklist for a period, in order.
     *
     * @return list<array{key: string, label: string, note: string, owner: string, kind: string, settled: bool, ticked: bool, locked: bool, permission: ?string, link: ?string, href: ?string}>
     */
    public function checklist(array $period): array
    {
        $closed = $period['status'] === 'closed';
        $ledger = $closed ? [] : $this->ledgerChecks($period);
        $confirmed = [];
        foreach ($this->rows(
            'SELECT s.check_id, s.completed_at, s.completed_by FROM {period_close_steps} s WHERE s.period_id = ?',
            [$period['id']]
        ) as $s) {
            $confirmed[(int) $s['check_id']] = $s;
        }

        return array_map(function ($c) use ($closed, $ledger, $confirmed, $period) {
            $isLedger = $c['kind'] === 'ledger';
            $step = $confirmed[(int) $c['id']] ?? null;
            [$ok, $note] = $isLedger ? ($ledger[$c['key']] ?? [true, '']) : [$step !== null, ''];
            $settled = $closed || $ok;
            if (!$isLedger) {
                $note = $step === null ? self::CONFIRMATION_NOTES[$c['key']] ?? '' : 'Confirmed by ' . $this->lookups->shortName((int) $step['completed_by']) . ' · ' . date('d M H:i', strtotime($step['completed_at']));
            }
            [$link, $href] = self::LINKS[$c['key']] ?? [null, null];

            return [
                'key'     => $c['key'],
                'label'   => $c['label'],
                'note'    => $closed ? $c['settled_note'] : $note,
                'owner'   => $c['owner_user_id'] === null ? $c['owner_title'] : $this->lookups->shortName((int) $c['owner_user_id']) . ' · ' . $c['owner_title'],
                'kind'    => $c['kind'],
                'settled' => $settled,
                'ticked'  => !$isLedger && $settled,
                'locked'  => $closed,
                'permission' => $c['permission'],
                'link'    => $settled ? null : $link,
                'href'    => $settled ? null : $href,
            ];
        }, $this->checks());
    }

    /** Last few closes and reopenings, newest first. */
    public function history(int $limit = 5): array
    {
        return array_map(fn ($e) => [
            'what' => $e['summary'],
            'meta' => date('d M H:i', strtotime($e['occurred_at'])) . ' · ' . $this->lookups->shortName($e['actor_user_id'] === null ? null : (int) $e['actor_user_id'])
                . ($e['approver_user_id'] === null ? ' · ' . $this->lookups->roleOf((int) $e['actor_user_id']) : ' · approved by ' . $this->lookups->shortName((int) $e['approver_user_id'])),
        ], $this->rows(
            "SELECT * FROM {audit_events} WHERE object_type = 'period' AND action IN ('period.closed', 'period.reopened') ORDER BY occurred_at DESC, id DESC LIMIT " . $limit
        ));
    }

    /** The printable close pack: every document behind the close, with its figures. */
    public function pack(array $period): array
    {
        $closed = $period['status'] === 'closed';
        $tasks  = $this->checklist($period);
        $totals = $this->totals($period);
        $money  = static fn (float $n) => $n < 0 ? '(' . number_format(abs(round($n))) . ')' : number_format(round($n));

        $tbDr = 0.0;
        $tbCr = 0.0;
        $tbRows = array_map(static function ($a) use (&$tbDr, &$tbCr, $money) {
            $onDebit = in_array($a['type'], ['Asset', 'Expense'], true) ? $a['balance'] >= 0 : $a['balance'] < 0;
            $v = abs($a['balance']);
            $onDebit ? $tbDr += $v : $tbCr += $v;

            return ['code' => $a['code'], 'name' => $a['name'], 'dr' => $onDebit ? $money($v) : '', 'cr' => $onDebit ? '' : $money($v)];
        }, Ledger::trialBalanceAccounts());

        $statement = static function (string $type) use ($money) {
            $leaves = array_values(array_filter(Ledger::statementLeaves($type), static fn ($a) => $a['balance'] != 0));

            return [
                'rows'  => array_map(static fn ($a) => ['code' => $a['code'], 'name' => $a['name'], 'amount' => $money($a['balance'])], $leaves),
                'total' => array_sum(array_column($leaves, 'balance')),
            ];
        };
        [$assets, $liabilities, $income, $expense] = array_map($statement, ['Asset', 'Liability', 'Income', 'Expense']);

        $bankStates = $this->reconciled($period);
        $accounts = $this->lookups->accounts();

        return [
            'org'      => self::ORGANISATION,
            'period'   => $period['name'],
            'stamp'    => $closed ? $this->closeMeta($period) : 'Draft pack · assembled ' . $period['name'] . ' · not yet closed',
            'contents' => array_map(static fn ($d) => ['no' => $d['no'], 'name' => $d['name'], 'status' => $d['ready'] ? 'Ready' : 'Outstanding'], $this->packContents($period, $tasks, $totals)['docs']),
            'trialBalance' => [
                'rows' => $tbRows, 'dr' => $money($tbDr), 'cr' => $money($tbCr),
                'note' => round($tbDr) == round($tbCr) ? 'Debits equal credits.' : 'Out of balance by ' . $money(abs($tbDr - $tbCr)) . '.',
            ],
            'position' => [
                'assets' => $assets['rows'], 'liabilities' => $liabilities['rows'],
                'assetTotal' => $money($assets['total']), 'liabilityTotal' => $money($liabilities['total']), 'fundTotal' => $money($assets['total'] - $liabilities['total']),
            ],
            'activities' => [
                'income' => $income['rows'], 'expenditure' => $expense['rows'],
                'incomeTotal' => $money($income['total']), 'expenditureTotal' => $money($expense['total']), 'surplus' => $money($income['total'] - $expense['total']),
            ],
            'funds' => array_map(static fn ($f) => [
                'name' => $f['name'], 'cls' => $f['cls'], 'opening' => $money($f['opening']), 'income' => $money($f['income']),
                'spend' => $money($f['spend']), 'transfers' => $money($f['transfers']), 'closing' => $money(Ledger::fundClose($f)),
            ], (new FundRepository())->all()),
            'banks' => array_map(static fn ($b) => [
                'code' => $b['code'], 'name' => $b['short_name'], 'state' => $closed || $b['reconciled'] ? 'Agreed to statement' : 'Not yet agreed',
            ], $bankStates),
            'journals' => $totals['postedList'],
            'journalNote' => $totals['postedList']
                ? count($totals['postedList']) . ' posted entries, each showing preparer and approver'
                : ($closed
                    ? 'Entry detail for ' . $period['name'] . ' is held in the closed-period archive · ' . $period['archived_journals'] . ' entries posted, ' . $money((float) $period['archived_value']) . ' in total'
                    : 'No entry has been posted into ' . $period['name'] . ' yet · ' . $totals['openCount'] . ($totals['openCount'] === 1 ? ' journal is still open' : ' journals are still open')),
            'budget' => array_map(static fn ($l) => [
                'name' => $accounts[$l['code']]['name'] ?? $l['code'], 'budget' => $money($l['phased']), 'actual' => $money($l['actual']),
                'variance' => $money($l['variance']), 'status' => $l['status'],
            ], array_slice(Ledger::budgetLines(), 0, 18)),
            'checklist' => array_map(static fn ($t) => ['label' => $t['label'], 'owner' => $t['owner'], 'state' => $t['settled'] ? 'Settled' : 'Outstanding'], $tasks),
        ];
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /** Ticks or clears a confirmation on an open period. */
    public function confirm(array $period, string $key, bool $done, array $actor, int $actorId): void
    {
        $check = current(array_filter($this->checks(), static fn ($c) => $c['key'] === $key)) ?: null;
        if ($check === null || $check['kind'] !== 'confirmation') {
            throw new RuleViolation('"' . $key . '" is not a confirmation on the close checklist. Ledger checks are answered from the books.');
        }
        if ($period['status'] === 'closed') {
            throw new RuleViolation($period['name'] . ' is closed. Reopen it before changing its confirmations.');
        }
        if (!in_array($check['permission'], $actor['permissions'], true)) {
            throw new RuleViolation($actor['role'] . ' cannot confirm "' . lcfirst($check['label']) . '". It is for ' . $check['owner_title'] . '.');
        }
        if ($key === 'signoff' && $done) {
            $pending = array_filter($this->checklist($period), static fn ($t) => !$t['settled'] && $t['key'] !== 'signoff');
            if ($pending) {
                throw new RuleViolation('Authorise the close last: ' . count($pending) . (count($pending) === 1 ? ' item is' : ' items are') . ' still outstanding — ' . lcfirst(current($pending)['label']) . '.');
            }
        }

        $this->transaction(function () use ($period, $check, $done, $actorId) {
            $this->db->table('period_close_steps')->where('period_id', $period['id'])->where('check_id', $check['id'])->delete();
            if ($done) {
                $this->insert('period_close_steps', [
                    'period_id' => $period['id'], 'check_id' => $check['id'], 'completed_by' => $actorId, 'completed_at' => Clock::timestamp(),
                ]);
            }
            $this->audit('period', (int) $period['id'], $period['name'], ($done ? 'Confirmed: ' : 'Confirmation withdrawn: ') . lcfirst($check['label']), $actorId, 'period.confirmation', (int) $period['entity_id']);
        });
    }

    /** Locks a period against posting, once every item is settled. */
    public function close(array $period, array $actor, int $actorId): void
    {
        if ($period['status'] === 'closed') {
            throw new RuleViolation($period['name'] . ' is already closed.');
        }
        if (!in_array('period.close', $actor['permissions'], true)) {
            throw new RuleViolation($actor['role'] . ' cannot close a period. The Finance Manager closes it once the Executive Director has authorised.');
        }
        $earlier = current(array_filter($this->lookups->periods(), static fn ($p) => $p['status'] === 'open' && $p['starts_on'] < $period['starts_on'])) ?: null;
        if ($earlier !== null) {
            throw new RuleViolation('Close ' . $earlier['name'] . ' first. Periods are closed in order.');
        }
        $blockers = array_values(array_filter($this->checklist($period), static fn ($t) => !$t['settled']));
        if ($blockers) {
            throw new RuleViolation(count($blockers) . (count($blockers) === 1 ? ' item is' : ' items are') . ' still outstanding — ' . lcfirst($blockers[0]['label']) . '.');
        }

        $approver = (int) $this->value(
            'SELECT s.completed_by FROM {period_close_steps} s JOIN {period_close_checks} c ON c.id = s.check_id WHERE s.period_id = ? AND c.`key` = ?',
            [$period['id'], 'signoff']
        );

        $this->transaction(function () use ($period, $actorId, $approver) {
            $now = Clock::timestamp();
            $this->db->table('periods')->where('id', $period['id'])->update([
                'status' => 'closed', 'closed_by' => $actorId, 'closed_at' => $now, 'close_approved_by' => $approver, 'updated_at' => $now,
            ]);
            $this->event($period, 'period.closed', $period['name'] . ' closed to further posting', $actorId, $approver);
        });
    }

    /** Reopens a closed period. The review and authorisation are withdrawn, so the close must be retaken. */
    public function reopen(array $period, array $actor, int $actorId, string $reason): void
    {
        if ($period['status'] !== 'closed') {
            throw new RuleViolation($period['name'] . ' is open.');
        }
        if (!in_array('period.authorise', $actor['permissions'], true)) {
            throw new RuleViolation($actor['role'] . ' cannot reopen a closed period. Reopening needs the Executive Director.');
        }
        $later = array_values(array_filter($this->lookups->periods(), static fn ($p) => $p['status'] === 'closed' && $p['starts_on'] > $period['starts_on']));
        if ($later !== []) {
            throw new RuleViolation('Reopen ' . end($later)['name'] . ' first. Periods are reopened from the latest back.');
        }

        $this->transaction(function () use ($period, $actorId, $reason) {
            $now = Clock::timestamp();
            $this->db->table('periods')->where('id', $period['id'])->update([
                'status' => 'open', 'closed_by' => null, 'closed_at' => null, 'close_approved_by' => null, 'updated_at' => $now,
            ]);
            $this->db->query($this->sql(
                'DELETE FROM {period_close_steps} WHERE period_id = ? AND check_id IN (SELECT id FROM {period_close_checks} WHERE `key` IN (' . implode(', ', array_fill(0, count(self::WITHDRAWN_ON_REOPEN), '?')) . '))'
            ), [$period['id'], ...self::WITHDRAWN_ON_REOPEN]);
            $this->event($period, 'period.reopened', $period['name'] . ' reopened — sign-off withdrawn and the close must be retaken' . ($reason !== '' ? ': ' . $reason : ''), $actorId, null, $reason);
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** What a confirmation asks for while it is still unticked. */
    private const CONFIRMATION_NOTES = [
        'accruals' => 'Goods and services received but not yet invoiced',
        'review'   => 'Management accounts read against budget and last month',
        'signoff'  => 'Required before any period is locked',
    ];

    private function checks(): array
    {
        return $this->cached('checks', fn () => $this->rows('SELECT * FROM {period_close_checks} ORDER BY sort_order'));
    }

    /**
     * The ledger checks for an open period: key => [passes, note].
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    private function ledgerChecks(array $period): array
    {
        $name = $period['name'];
        $open = count(array_filter((new JournalRepository())->all(), static fn ($j) => $j['period'] === $name && in_array($j['status'], ['Draft', 'Pending approval'], true)));
        $awaiting = count(Ledger::awaitingBills());
        $overdue = count(Ledger::overdueBills());

        $cash = $this->reconciled($period);
        $pending = static fn (string $kind) => array_values(array_filter($cash, static fn ($b) => $b['kind'] === $kind && !$b['reconciled']));
        $banks = array_column(array_filter($cash, static fn ($b) => $b['kind'] === 'bank'), 'short_name');
        $mpesa = current(array_filter($cash, static fn ($b) => $b['kind'] === 'mobile_money')) ?: null;

        $payroll = $this->row('SELECT * FROM {payroll_runs} WHERE period_id = ?', [$period['id']]);
        $payrollCost = $payroll === null ? 0.0 : (float) $payroll['gross'] + (float) $payroll['employer_cost'];
        $month = date('M', strtotime($period['starts_on']));

        $disposals = (int) $this->value("SELECT COUNT(*) FROM {asset_disposals} WHERE status = 'pending_approval'");
        $depreciation = $this->row("SELECT * FROM {depreciation_runs} WHERE period_id = ? AND status = 'posted'", [$period['id']]);
        $segments = Ledger::openSegments();
        $untied = count(Ledger::untiedReports());
        $over = count(Ledger::overBudgetLines());
        $tb = Ledger::trialBalanceBalanced();

        return [
            'journals'  => [$open === 0, $open ? $open . ($open === 1 ? ' journal is still draft or awaiting approval' : ' journals are still draft or awaiting approval') : 'No drafts and nothing awaiting approval'],
            'bills'     => [$awaiting + $overdue === 0, $awaiting + $overdue ? $awaiting . ' awaiting sign-off, ' . $overdue . ' past due' : 'All bills approved or paid'],
            'bank'      => [$pending('bank') === [], $pending('bank') === []
                ? implode(' and ', $banks) . ' both agree to statement'
                : 'Outstanding: ' . implode(' and ', array_column($pending('bank'), 'short_name'))],
            'mpesa'     => [$pending('mobile_money') === [], $pending('mobile_money') === []
                ? 'Settlement report agreed to the float account'
                : ($mpesa['account_number'] ?? 'The paybill') . ' settlement report not yet agreed'],
            'payroll'   => match (true) {
                $payroll !== null && $payroll['status'] === 'posted'   => [true, Prototype::fmt($payrollCost) . ' charged to 5210 and 5220, ' . Prototype::fmt((float) $payroll['total_deductions']) . ' raised as statutory liabilities'],
                $payroll !== null && $payroll['status'] === 'approved' => [false, 'Approved but not yet posted — ' . Prototype::fmt($payrollCost) . ' is still out of the ledger'],
                default => [false, 'The ' . $month . ' run has not been approved or posted'],
            },
            'disposals' => [$disposals === 0, $disposals ? $disposals . ($disposals === 1 ? ' proposed disposal awaiting' : ' proposed disposals awaiting') . ' the Executive Director' : 'No disposal is sitting unapproved'],
            'depn'      => [$depreciation !== null, $depreciation !== null ? Prototype::fmt((float) $depreciation['total']) . ' posted from the asset register to 5350' : 'The monthly run has not been posted from the asset register'],
            'segments'  => [$segments === [], $segments
                ? 'The ' . implode(' and ', array_map(static fn ($s) => strtolower($s['name']), $segments)) . ' segment is still optional — postings may not be traceable'
                : 'All donor segments are mandatory on posting'],
            'donor'     => [$untied === 0, $untied ? $untied . ($untied === 1 ? ' report does not tie to its postings' : ' reports do not tie to their postings') : 'Every report ties to the postings behind it'],
            'budget'    => [$over === 0, $over ? $over . ($over === 1 ? ' line is over its approved amount' : ' lines are over their approved amount') : 'No line is over its approved amount'],
            'tb'        => [$tb, $tb ? 'Debits equal credits across every active account' : 'The ledger is out of balance'],
        ];
    }

    /**
     * The cash accounts reconciled at the period end: banks and the M-Pesa float.
     *
     * @return list<array{code: string, short_name: string, kind: string, account_number: ?string, reconciled: bool}>
     */
    private function reconciled(array $period): array
    {
        return array_map(static fn ($b) => $b + ['reconciled' => (int) $b['completed'] > 0], $this->rows(
            "SELECT a.code, b.short_name, b.kind, b.account_number,
                    (SELECT COUNT(*) FROM {reconciliations} r WHERE r.bank_account_id = b.id AND r.period_id = ? AND r.status = 'completed') AS completed
             FROM {bank_accounts} b JOIN {accounts} a ON a.id = b.account_id
             WHERE b.kind IN ('bank', 'mobile_money') AND b.status = 'active' ORDER BY a.code",
            [$period['id']]
        ));
    }

    /**
     * What is in the period: live postings, plus the archive summary for a month
     * locked before the ledger was migrated.
     */
    private function totals(array $period): array
    {
        $journals = array_values(array_filter((new JournalRepository())->all(), static fn ($j) => $j['period'] === $period['name']));
        $posted = array_values(array_filter($journals, static fn ($j) => in_array($j['status'], ['Posted', 'Reversed'], true)));
        $open = array_values(array_filter($journals, static fn ($j) => in_array($j['status'], ['Draft', 'Pending approval'], true)));
        $sum = static fn (array $js, string $side) => array_sum(array_map(static fn ($j) => array_sum(array_column($j['lines'], $side)), $js));
        $dr = $sum($posted, 'dr');
        $cr = $sum($posted, 'cr');
        $archived = (int) $period['archived_journals'];
        $archivedValue = (float) $period['archived_value'];
        $closed = $period['status'] === 'closed';
        $fmt = static fn (float $n) => $n == 0 ? '0' : Prototype::fmt($n);

        return [
            'rows' => [
                ['label' => 'Journals posted', 'value' => (string) ($archived + count($posted))],
                ['label' => 'Journals still open', 'value' => (string) count($open)],
                ['label' => 'Total debits', 'value' => $fmt($archivedValue + $dr)],
                ['label' => 'Total credits', 'value' => $fmt($archivedValue + $cr)],
                ['label' => 'Difference', 'value' => $fmt($dr - $cr)],
                ['label' => 'Value in open journals', 'value' => $fmt($sum($open, 'dr'))],
            ],
            'state' => ($archived + count($posted)) === 0 ? 'empty' : (round($dr) == round($cr) ? 'balanced' : 'unbalanced'),
            'note'  => ($archived + count($posted)) === 0
                ? ($closed ? $period['name'] . ' is locked — no journal was left open at close'
                    : 'Nothing posted into ' . $period['name'] . ' yet · ' . count($open) . (count($open) === 1 ? ' journal still open' : ' journals still open'))
                : '',
            'postedCount' => $archived + count($posted),
            'postedValue' => $archivedValue + $dr,
            'openCount'   => count($open),
            'postedList'  => array_map(static fn ($j) => [
                'ref' => $j['ref'], 'memo' => $j['memo'] !== '' ? $j['memo'] : $j['narration'], 'preparer' => $j['preparer'],
                'approver' => self::approverIn($j['trail']), 'amount' => number_format(array_sum(array_column($j['lines'], 'dr'))),
            ], $posted),
        ];
    }

    /** The eight documents the close pack assembles, and whether each is ready. */
    private function packContents(array $period, array $tasks, array $totals): array
    {
        $closed = $period['status'] === 'closed';
        $settled = static fn (string $key) => current(array_filter($tasks, static fn ($t) => $t['key'] === $key))['settled'] ?? false;
        $blockers = count(array_filter($tasks, static fn ($t) => !$t['settled']));
        $cash = $this->reconciled($period);
        $agreed = $closed ? count($cash) : count(array_filter($cash, static fn ($b) => $b['reconciled']));
        $over = count(Ledger::overBudgetLines());
        $tb = $closed || Ledger::trialBalanceBalanced();
        $funds = array_sum(array_map([Ledger::class, 'fundClose'], (new FundRepository())->all()));

        $docs = [
            ['Trial balance', $closed ? 'Every active account at the close date, debits against credits' : 'Every active account, debits against credits',
                $totals['postedValue'] == 0 ? '' : Prototype::fmt($totals['postedValue']), $tb, '/reports'],
            ['Statement of financial position', 'Assets, liabilities and funds carried forward', '', $tb, '/reports'],
            ['Statement of income and expenditure', 'Income and spend for the period, against budget', '', true, '/reports'],
            ['Fund movement schedule', 'Opening, income, spend and closing balance for each fund', Prototype::fmt($funds), true, '/funds'],
            ['Bank and M-Pesa reconciliations', $agreed === count($cash) ? 'All ' . count($cash) . ' accounts agreed to statement' : (count($cash) - $agreed) . ' of ' . count($cash) . ' accounts not yet agreed',
                $agreed . ' of ' . count($cash), $agreed === count($cash), '/bank-rec'],
            ['Journal listing', 'Every entry in the period with its preparer and approver', (string) $totals['postedCount'], $closed || $settled('journals'), '/journals'],
            ['Budget against actual', $closed || $over === 0 ? 'No line over its approved amount' : $over . ($over === 1 ? ' line over its approved amount' : ' lines over their approved amount'), '', $closed || $over === 0, '/budgets'],
            ['Signed close checklist', $closed ? 'All ' . count($tasks) . ' items settled, with who confirmed each' : $blockers . ($blockers === 1 ? ' item still outstanding' : ' items still outstanding'), '', $blockers === 0, null],
        ];
        $notReady = count(array_filter($docs, static fn ($d) => !$d[3]));

        return [
            'title' => 'Close pack — ' . $period['name'],
            'meta'  => ($closed ? 'Assembled at close · ' : 'Draft · figures as at ' . $period['name'] . ' · ') . self::ORGANISATION . ' · KES',
            'intro' => $closed
                ? 'The evidence bundle assembled when ' . $period['name'] . ' was locked. Figures are frozen as at the close date.'
                : 'The evidence bundle for ' . $period['name'] . '. Documents marked outstanding will be incomplete until the checklist item behind them is settled.',
            'count' => (count($docs) - $notReady) . ' of ' . count($docs) . ' ready',
            'readyNote' => $notReady
                ? $notReady . ($notReady === 1 ? ' document is not ready' : ' documents are not ready') . ' — the pack can be assembled but should not be circulated'
                : 'All ' . count($docs) . ' documents are ready to circulate',
            'docs' => array_map(static fn ($d, $i) => [
                'no' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'name' => $d[0], 'detail' => $d[1], 'figure' => $d[2], 'ready' => $d[3], 'href' => $d[4],
            ], $docs, array_keys($docs)),
        ];
    }

    /** The year pills: the latest three years, with an earlier pick swapped in for the oldest. */
    private function yearSwitcher(int $selected, array $period): array
    {
        $years = $this->years();
        $recent = array_slice(array_column($years, 'year'), -self::SHOWN_YEARS);
        $shown = in_array($selected, $recent, true) ? $recent : array_merge([$selected], array_slice($recent, 1));
        $byYear = array_column($years, null, 'year');
        $openIn = static fn (array $y) => array_values(array_filter($y['periods'], static fn ($p) => $p['status'] === 'open'));
        $current = $byYear[$selected] ?? end($years);
        $open = $openIn($current);
        $count = count($current['periods']);

        return [
            'selected' => $current['year'],
            'shown'    => array_map(static fn ($y) => ['year' => $y, 'label' => 'FY' . $y, 'note' => $openIn($byYear[$y]) ? count($openIn($byYear[$y])) . ' open' : ''], $shown),
            'earlier'  => array_values(array_map(static fn ($y) => ['year' => $y['year'], 'label' => 'FY' . $y['year'], 'note' => count($y['periods']) . ' months locked'],
                array_reverse(array_filter($years, static fn ($y) => !in_array($y['year'], $shown, true))))),
            'note'     => $open
                ? ($count - count($open)) . ' of ' . $count . ' months locked · earliest open is ' . $open[0]['name']
                : 'All ' . $count . ' months locked to further posting',
            'offYear'  => (int) substr($period['starts_on'], 0, 4) !== $current['year'] ? 'Showing FY' . $current['year'] . ' · the checklist below is still ' . $period['name'] : '',
            'periods'  => array_map(static fn ($p) => [
                'code' => $p['code'], 'label' => date('M', strtotime($p['starts_on'])), 'state' => $p['status'],
            ], $current['periods']),
        ];
    }

    /** "Closed 19 Aug 2026 · M. Otieno · approved by D. Kiptoo" */
    private function closeMeta(array $period): string
    {
        if ($period['closed_at'] === null) {
            return $period['name'] . ' is locked';
        }

        return 'Closed ' . date('d M Y', strtotime($period['closed_at'])) . ' · ' . $this->lookups->shortName((int) $period['closed_by'])
            . ($period['close_approved_by'] === null ? '' : ' · approved by ' . $this->lookups->shortName((int) $period['close_approved_by']));
    }

    private function event(array $period, string $action, string $summary, int $actorId, ?int $approverId, string $reason = ''): void
    {
        $this->insert('audit_events', [
            'entity_id' => $period['entity_id'], 'occurred_at' => Clock::timestamp(), 'actor_user_id' => $actorId, 'approver_user_id' => $approverId ?: null,
            'action' => $action, 'object_type' => 'period', 'object_id' => $period['id'], 'object_ref' => $period['name'],
            'summary' => mb_substr($summary, 0, 255), 'reason' => $reason !== '' ? $reason : null,
        ]);
    }

    /** Who approved a journal, from its history ("Approved and posted by W. Kamau"). */
    private static function approverIn(array $trail): string
    {
        foreach ($trail as $t) {
            if (preg_match('/(?:approved|posted)[^,]* by ([^,:]+)/i', $t['what'], $m) === 1) {
                return trim($m[1]);
            }
        }

        return '—';
    }
}
