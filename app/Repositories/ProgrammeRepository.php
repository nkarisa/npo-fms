<?php

namespace App\Repositories;

/**
 * The programme register, with each programme's approved budget, posted
 * expenditure and open commitments, the awards and staff charged to it, and the
 * funds it draws on.
 */
final class ProgrammeRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $budget = array_column($this->rows(
                "SELECT v.programme_id, SUM(v.budget) AS budget, SUM(v.committed) AS committed FROM {v_budget_availability} v
                 JOIN {budget_versions} bv ON bv.id = v.budget_version_id WHERE bv.status = 'approved' GROUP BY v.programme_id"
            ), null, 'programme_id');
            $actual = array_column($this->rows(
                "SELECT l.programme_id, SUM(l.debit - l.credit) AS actual FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
                 JOIN {accounts} a ON a.id = l.account_id WHERE j.status IN ('posted', 'reversed') AND a.type = 'expense' GROUP BY l.programme_id"
            ), 'actual', 'programme_id');
            $grants = array_column($this->rows(
                "SELECT programme_id, COUNT(*) AS total, SUM(CASE WHEN status IN ('active', 'closing') THEN 1 ELSE 0 END) AS live
                 FROM {grants} WHERE programme_id IS NOT NULL GROUP BY programme_id"
            ), null, 'programme_id');
            $staff = array_column($this->rows(
                'SELECT a.programme_id, COUNT(DISTINCT a.staff_id) AS staff FROM {staff_allocations} a JOIN {staff} s ON s.id = a.staff_id
                 WHERE a.effective_to IS NULL AND s.left_on IS NULL GROUP BY a.programme_id'
            ), 'staff', 'programme_id');
            $funds = [];
            foreach ($this->rows('SELECT fp.programme_id, f.name FROM {fund_programmes} fp JOIN {funds} f ON f.id = fp.fund_id ORDER BY f.code') as $r) {
                $funds[(int) $r['programme_id']][] = $r['name'];
            }

            return array_map(fn ($p) => [
                'code'         => $p['code'],
                'name'         => $p['name'],
                'manager'      => $this->lookups->shortName($p['manager_user_id'] === null ? null : (int) $p['manager_user_id']),
                'status'       => ucfirst($p['status']),
                'since'        => self::dmy($p['started_on']),
                'purpose'      => $p['purpose'] ?? '',
                'share'        => $p['cost_share_pct'] === null ? null : self::num($p['cost_share_pct']),
                'budget'       => self::num($budget[$p['id']]['budget'] ?? 0),
                'actual'       => self::num($actual[$p['id']] ?? 0),
                'committed'    => self::num($budget[$p['id']]['committed'] ?? 0),
                'grants'       => (int) ($grants[$p['id']]['total'] ?? 0),
                'activeGrants' => (int) ($grants[$p['id']]['live'] ?? 0),
                'staff'        => (int) ($staff[$p['id']] ?? 0),
                'funds'        => $funds[(int) $p['id']] ?? [],
            ], $this->rows('SELECT * FROM {programmes} ORDER BY code'));
        });
    }

    public function find(string $code): ?array
    {
        foreach ($this->all() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }

        return null;
    }
}
