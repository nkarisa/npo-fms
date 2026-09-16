<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * Grants and awards. Received is the tranches received; spent is the
 * expenditure posted to the grant; elapsed is the share of the agreement period
 * gone by today. A scheduled tranche expected within 30 days shows as due.
 */
final class GrantRepository extends Repository
{
    private const TRANCHE_DUE_DAYS = 30;

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $spent = array_column($this->rows(
                "SELECT l.grant_id, SUM(l.debit - l.credit) AS spent FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 JOIN {accounts} a ON a.id = l.account_id WHERE j.status IN ('posted', 'reversed') AND a.type = 'expense' AND l.grant_id IS NOT NULL
                 GROUP BY l.grant_id"
            ), 'spent', 'grant_id');

            $lineActuals = [];
            foreach ($this->rows(
                "SELECT l.grant_id, a.code, SUM(l.debit - l.credit) AS actual FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 JOIN {accounts} a ON a.id = l.account_id WHERE j.status IN ('posted', 'reversed') AND l.grant_id IS NOT NULL GROUP BY l.grant_id, a.code"
            ) as $r) {
                $lineActuals[$r['grant_id'] . ':' . $r['code']] = (float) $r['actual'];
            }

            $children = fn (string $sql) => array_reduce($this->rows($sql), static function ($out, $r) {
                $out[(int) $r['grant_id']][] = $r;

                return $out;
            }, []);
            $budgets    = $children('SELECT b.*, a.code FROM {grant_budget_lines} b JOIN {accounts} a ON a.id = b.account_id ORDER BY b.id');
            $tranches   = $children('SELECT * FROM {grant_tranches} ORDER BY id');
            $conditions = $children('SELECT * FROM {grant_conditions} ORDER BY sort_order');
            $reports    = $children('SELECT * FROM {donor_reports} ORDER BY due_on');

            return array_map(function ($g) use ($spent, $lineActuals, $budgets, $tranches, $conditions, $reports) {
                $id    = (int) $g['id'];
                $next  = current(array_filter($reports[$id] ?? [], static fn ($r) => in_array($r['status'], ['draft', 'in_review'], true))) ?: null;
                $days  = $next === null ? null : Clock::daysUntil($next['due_on']);
                $grantTranches = $tranches[$id] ?? [];

                return [
                    'ref'        => $g['award_ref'],
                    'title'      => $g['title'],
                    'funder'     => $g['funder_name'],
                    'program'    => $this->lookups->programmeName($g['programme_id'] === null ? null : (int) $g['programme_id']),
                    'fund'       => $g['fund_id'] === null ? 'Not yet assigned' : $this->lookups->funds()[$g['fund_id']]['name'],
                    'manager'    => $this->lookups->shortName($g['manager_user_id'] === null ? null : (int) $g['manager_user_id']),
                    'period'     => $g['starts_on'] ? date('M y', strtotime($g['starts_on'])) . ' – ' . date('M y', strtotime($g['ends_on'])) : 'Proposal',
                    'start'      => self::dmy($g['starts_on']),
                    'end'        => self::dmy($g['ends_on']),
                    'elapsed'    => self::elapsed($g['starts_on'], $g['ends_on']),
                    'status'     => ucfirst($g['status']),
                    'value'      => self::num($g['value']),
                    'received'   => self::num(array_sum(array_map(static fn ($t) => $t['status'] === 'received' ? (float) $t['amount'] : 0, $grantTranches))),
                    'spent'      => self::num($spent[$id] ?? 0),
                    'nextReport' => $next === null ? '—' : ($days < 0 ? 'overdue' : self::dm($next['due_on'])),
                    'reportDays' => $days ?? 9999,
                    'budget'     => array_map(static fn ($b) => [
                        'code' => $b['code'], 'name' => $b['name'], 'budget' => self::num($b['amount']), 'actual' => self::num($lineActuals[$id . ':' . $b['code']] ?? 0),
                    ], $budgets[$id] ?? []),
                    'tranches'   => array_map(static fn ($t) => [
                        'no'     => $t['label'],
                        'date'   => self::dmy($t['expected_on'], 'on hold'),
                        'amount' => self::num($t['amount']),
                        'status' => match (true) {
                            $t['status'] === 'received' => 'Received',
                            $t['status'] === 'cancelled' => 'Cancelled',
                            $t['expected_on'] !== null && Clock::daysUntil($t['expected_on']) <= self::TRANCHE_DUE_DAYS => 'Due',
                            default => 'Scheduled',
                        },
                    ], $grantTranches),
                    'reports'    => array_map(static fn ($r) => [
                        'name'   => $r['title'],
                        'period' => DonorReportRepository::periodLabel($r['period_starts_on'], $r['period_ends_on']),
                        'due'    => self::dmy($r['due_on']),
                        'status' => DonorReportRepository::statusLabel($r),
                    ], $reports[$id] ?? []),
                    'conditions' => array_column($conditions[$id] ?? [], 'text'),
                ];
            }, $this->rows('SELECT g.*, f.name AS funder_name FROM {grants} g JOIN {funders} f ON f.id = g.funder_id ORDER BY g.id'));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $g) {
            if ($g['ref'] === $ref) {
                return $g;
            }
        }

        return null;
    }

    /** Percentage of the agreement period elapsed today, 0–100. */
    public static function elapsed(?string $start, ?string $end): int
    {
        if (!$start || !$end) {
            return 0;
        }

        $total = max(1, strtotime($end) - strtotime($start));
        $gone  = Clock::today()->getTimestamp() - strtotime($start);

        return (int) max(0, min(100, round($gone / $total * 100)));
    }
}
