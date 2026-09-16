<?php

namespace App\Repositories;

/**
 * Budget lines of the approved version, each with its original amount and its
 * actual from the ledger, and the reallocation rules per fund group.
 */
final class BudgetRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function lines(): array
    {
        return $this->cached('lines', function () {
            $approved = $this->row("SELECT * FROM {budget_versions} WHERE status = 'approved' ORDER BY id DESC LIMIT 1");
            if ($approved === null) {
                return [];
            }

            $original = [];
            if ($approved['based_on_version_id'] !== null) {
                foreach ($this->rows('SELECT * FROM {budget_lines} WHERE budget_version_id = ?', [$approved['based_on_version_id']]) as $o) {
                    $original[$o['account_id'] . ':' . $o['fund_id'] . ':' . $o['programme_id'] . ':' . $o['grant_id']] = (float) $o['annual_amount'];
                }
            }

            return array_map(function ($l) use ($original) {
                $key = $l['account_id'] . ':' . $l['fund_id'] . ':' . $l['programme_id'] . ':' . $l['grant_id'];

                return [
                    'code'    => $l['code'],
                    'group'   => $l['cost_group'],
                    'fund'    => self::FUND_GROUPS[$l['ledger_group']],
                    'program' => $this->lookups->programmeName((int) $l['programme_id']),
                    'grant'   => $l['grant_short'] ?? 'Unassigned',
                    'orig'    => self::num($original[$key] ?? $l['budget']),
                    'annual'  => self::num($l['budget']),
                    'actual'  => self::num($l['actual']),
                    'profile' => $l['profile'] ?? 'even',
                ];
            }, $this->rows(
                'SELECT v.*, a.code, f.ledger_group, g.short_name AS grant_short, pp.key AS profile
                 FROM {v_budget_availability} v JOIN {budget_lines} bl ON bl.id = v.budget_line_id
                 JOIN {accounts} a ON a.id = v.account_id JOIN {funds} f ON f.id = v.fund_id
                 LEFT JOIN {grants} g ON g.id = v.grant_id LEFT JOIN {phasing_profiles} pp ON pp.id = bl.phasing_profile_id
                 WHERE v.budget_version_id = ? ORDER BY bl.id',
                [$approved['id']]
            ));
        });
    }

    /** @return array<string, list<string>> rules by fund group name */
    public function rules(): array
    {
        $out = [];
        foreach ($this->rows('SELECT * FROM {budget_rules} ORDER BY id') as $r) {
            $out[self::FUND_GROUPS[$r['ledger_group']]][] = $r['text'];
        }

        return $out;
    }
}
