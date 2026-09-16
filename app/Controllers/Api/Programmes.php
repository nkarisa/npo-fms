<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ProgrammeRepository;

/**
 * The programme register.
 *
 * Programme is a mandatory coding dimension on every posting line, alongside
 * account and fund. That has one consequence worth stating: a programme with
 * history can never be deleted, only deactivated — deleting it would orphan the
 * postings that carry it and break the third dimension of the ledger.
 *
 * Shared services is the exception that shapes the screen: it holds costs that
 * belong to no single programme and are allocated out each month, so it has no
 * share of the allocation base itself.
 */
class Programmes extends BaseApiController
{
    public static function available(array $p): float
    {
        return $p['budget'] - $p['actual'] - $p['committed'];
    }

    public static function burnPct(array $p): int
    {
        return $p['budget'] > 0 ? (int) min(100, round($p['actual'] / $p['budget'] * 100)) : 0;
    }

    public function index()
    {
        $all    = (new ProgrammeRepository())->all();
        $filter = $this->request->getGet('filter') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($p) use ($filter, $q) {
            if ($filter !== 'All' && $p['status'] !== $filter) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($p['code'] . ' ' . $p['name'] . ' ' . $p['manager'] . ' ' . $p['purpose']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(static fn ($p) => [
            'code'         => $p['code'],
            'name'         => $p['name'],
            'manager'      => $p['manager'],
            'status'       => $p['status'],
            'since'        => $p['since'],
            'purpose'      => $p['purpose'],
            // Shared services carries no share of its own — it allocates out.
            'share'        => $p['share'] === null ? '—' : $p['share'] . '%',
            'allocatesOut' => $p['share'] === null,
            'budget'       => Prototype::fmt($p['budget']),
            'actual'       => Prototype::fmt($p['actual']),
            'committed'    => Prototype::fmt($p['committed']),
            'available'    => Prototype::fmt(self::available($p)),
            'burnPct'      => self::burnPct($p),
            'overspent'    => $p['actual'] > $p['budget'] && $p['budget'] > 0,
            // A closed programme still carrying spend has history, so it cannot be deleted.
            'hasHistory'   => $p['actual'] > 0,
            'grants'       => $p['grants'],
            'activeGrants' => $p['activeGrants'],
            'staff'        => $p['staff'],
            'funds'        => $p['funds'],
        ], $filtered);

        $active     = array_values(array_filter($all, static fn ($p) => $p['status'] === 'Active'));
        $shareTotal = array_sum(array_map(static fn ($p) => $p['share'] ?? 0, $all));

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(static fn ($k) => [
                'label' => $k,
                'count' => $k === 'All' ? count($all) : count(array_filter($all, static fn ($p) => $p['status'] === $k)),
            ], ['All', 'Active', 'Pipeline', 'Inactive']),
            'stats' => [
                ['label' => 'Active programmes', 'value' => (string) count($active), 'note' => count($all) . ' on the register including closed'],
                ['label' => 'Budget FY2026', 'value' => Prototype::fmt(array_sum(array_map(static fn ($p) => $p['budget'], $active))), 'note' => Prototype::fmt(array_sum(array_map(static fn ($p) => $p['actual'], $active))) . ' spent to date'],
                ['label' => 'Committed', 'value' => Prototype::fmt(array_sum(array_map(static fn ($p) => $p['committed'], $active))), 'note' => 'on purchase orders not yet received'],
                ['label' => 'Shared-cost base', 'value' => $shareTotal . '%', 'note' => $shareTotal === 100 ? 'allocation balances' : 'allocation does not balance'],
                ['label' => 'Staff allocated', 'value' => (string) array_sum(array_map(static fn ($p) => $p['staff'], $all)), 'note' => 'across all programmes'],
            ],
            'allocation' => [
                'total'    => $shareTotal . '%',
                'balanced' => $shareTotal === 100,
                'note'     => $shareTotal === 100
                    ? 'The shared-cost allocation base totals 100%. Support costs apportion cleanly across programmes.'
                    : 'The shared-cost allocation base totals ' . $shareTotal . '%, not 100%. Support costs will be over- or under-apportioned until it balances.',
            ],
            'hint' => 'Programme is a mandatory coding dimension on every posting line, so a programme with history is deactivated, never deleted.',
        ]);
    }

    public function show($code)
    {
        foreach ((new ProgrammeRepository())->all() as $p) {
            if ($p['code'] !== $code) {
                continue;
            }

            $p['available']  = self::available($p);
            $p['burnPct']    = self::burnPct($p);
            $p['hasHistory'] = $p['actual'] > 0;
            $p['blockers']   = $this->deactivationBlockers($p);
            $p['canDeactivate'] = $p['status'] !== 'Inactive' && $p['blockers'] === [];

            return $this->json($p);
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $code . ' was not found in the programme register.']);
    }

    /**
     * What stands in the way of closing a programme. Deactivation is safe only
     * when nothing further can be coded to it — open awards, staff allocations and
     * uncleared commitments all keep it live.
     */
    private function deactivationBlockers(array $p): array
    {
        $blockers = [];

        if ($p['activeGrants'] > 0) {
            $blockers[] = $p['activeGrants'] . ' active ' . ($p['activeGrants'] === 1 ? 'award is' : 'awards are')
                . ' still charged to this programme';
        }
        if ($p['staff'] > 0) {
            $blockers[] = $p['staff'] . ' staff ' . ($p['staff'] === 1 ? 'member has' : 'members have')
                . ' payroll allocated to this programme';
        }
        if ($p['committed'] > 0) {
            $blockers[] = Prototype::fmt($p['committed']) . ' committed on purchase orders not yet received';
        }
        if ($p['share'] !== null && $p['share'] > 0) {
            $blockers[] = 'It carries ' . $p['share'] . '% of the shared-cost allocation base, which must be redistributed first';
        }

        return $blockers;
    }
}
