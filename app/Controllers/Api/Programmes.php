<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\ProgrammeRepository;
use App\Repositories\RuleViolation;

/**
 * The programme register.
 *
 * Programme is a mandatory coding dimension on every posting line, alongside
 * account and fund. That has one consequence worth stating: a programme with
 * history can never be deleted, only deactivated — deleting it would orphan the
 * postings that carry it and break the third dimension of the ledger.
 *
 * The shared-cost pool is the exception that shapes the screen. It holds costs
 * that belong to no single programme and are allocated out each month, so it has
 * no share of the allocation base itself, and the shares of the programmes that
 * do have to total exactly 100% — which is why opening a programme is also a
 * re-basing of every other one.
 */
class Programmes extends BaseApiController
{
    /** The three ways an existing allocation makes room for a new programme. */
    private const MODE_NOTES = [
        'prorate' => 'Every existing programme is reduced proportionally to make room. Shared costs stay fully recovered from the moment the programme opens — the usual choice.',
        'defer'   => 'The new programme carries none of the shared costs and existing allocations are untouched. Correct only where the award funds its own support costs directly, or the programme has no activity yet.',
        'manual'  => 'Set each share by hand. Use this when the agreement fixes a specific recovery rate and the others must absorb the difference unevenly.',
    ];

    public function index()
    {
        $repo   = new ProgrammeRepository();
        $all    = $repo->all();
        $filter = $this->request->getGet('filter') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, static function ($p) use ($filter, $q) {
            if ($filter !== 'All' && $p['status'] !== $filter) {
                return false;
            }

            return $q === '' || str_contains(strtolower($p['code'] . ' ' . $p['name'] . ' ' . $p['manager']), $q);
        }));

        $active = array_values(array_filter($all, static fn ($p) => $p['status'] === 'Active'));
        $total  = $repo->shareTotal();

        return $this->json([
            'rows'  => array_map(static fn ($p) => self::row($p), $filtered),
            'total' => count($all),
            'tabs'  => array_map(static fn ($k) => [
                'label' => $k,
                'count' => $k === 'All' ? count($all) : count(array_filter($all, static fn ($p) => $p['status'] === $k)),
            ], ['All', 'Active', 'Pipeline', 'Inactive']),
            'stats' => [
                ['label' => 'Active programmes', 'value' => (string) count($active), 'note' => count($all) . ' on the register including closed'],
                ['label' => 'Budget FY2026', 'value' => Prototype::fmt(array_sum(array_column($active, 'budget'))), 'note' => Prototype::fmt(array_sum(array_column($active, 'actual'))) . ' spent to date'],
                ['label' => 'Committed', 'value' => Prototype::fmt(array_sum(array_column($active, 'committed'))), 'note' => 'On purchase orders not yet received'],
                ['label' => 'Shared-cost base', 'value' => ProgrammeRepository::pctText($total), 'note' => $repo->baseBalances() ? 'Allocation balances' : 'Allocation does not balance'],
                ['label' => 'Staff allocated', 'value' => (string) array_sum(array_column($all, 'staff')), 'note' => 'Across all programmes'],
            ],
            // Only shown when it is wrong: a base that balances needs no commentary,
            // and one that does not is an accounting fault, not a status.
            'shareWarning' => $repo->baseBalances() ? null
                : 'Shared-cost allocation totals ' . ProgrammeRepository::pctText($total) . ', not 100%. Until this balances, support costs are either under-recovered or charged twice — check the monthly allocation journal.',
            'footer' => count($filtered) . ' of ' . count($all) . ' programmes · programme is a mandatory coding dimension on every posting line, so a programme with history is deactivated, never deleted',
            'form'   => $this->form($repo),
        ]);
    }

    public function show($code)
    {
        $repo = new ProgrammeRepository();
        $p    = $repo->find($code);

        if ($p === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $code . ' was not found in the programme register.']);
        }

        $blockers = $repo->blockers($p);
        $inactive = $p['status'] === 'Inactive';

        return $this->json([
            'code'          => $p['code'],
            'name'          => $p['name'],
            'status'        => $p['status'],
            'purpose'       => $p['purpose'],
            'shareText'     => $this->shareText($p),
            'hasShare'      => $p['share'] !== null && $p['share'] > 0,
            'facts'         => [
                ['label' => 'Code', 'value' => $p['code']],
                ['label' => 'Programme manager', 'value' => $p['manager']],
                ['label' => 'Active since', 'value' => $p['since']],
                ['label' => 'Budget FY2026', 'value' => Prototype::fmt($p['budget'])],
                ['label' => 'Actual to date', 'value' => Prototype::fmt($p['actual'])],
                ['label' => 'Committed', 'value' => Prototype::fmt($p['committed'])],
                ['label' => 'Remaining', 'value' => Prototype::fmt(ProgrammeRepository::remaining($p))],
                ['label' => 'Awards coded', 'value' => $p['grants'] === 0 ? 'None' : $p['grants'] . ' (' . $p['activeGrants'] . ' live)'],
                ['label' => 'Staff allocated', 'value' => $p['staff'] === 0 ? 'None' : (string) $p['staff']],
            ],
            'funds'         => $p['funds'],
            'renames'       => $p['renames'],
            // A closed programme still carrying spend has history, so it can only
            // ever have been deactivated — deleting it would orphan those postings.
            'hasHistory'    => $p['actual'] > 0,
            'blockers'      => $inactive ? [] : $blockers,
            'isInactive'    => $inactive,
            'canDeactivate' => $repo->canDeactivate($p),
        ]);
    }

    public function create()
    {
        return $this->write(function (ProgrammeRepository $repo) {
            $body = $this->request->getJSON(true) ?? [];
            $made = $repo->create(
                $body,
                (string) ($body['mode'] ?? 'prorate'),
                $this->shares($body['shares'] ?? []),
                $this->actorId()
            );

            return [
                'code'    => $made['code'],
                'message' => $made['name'] . ' opened as ' . $made['code'] . ' with ' . ProgrammeRepository::pctText($made['share'])
                    . ' of shared costs. It carries no budget until an award or a budget revision is posted.',
            ];
        });
    }

    public function rename($code)
    {
        return $this->write(function (ProgrammeRepository $repo) use ($code) {
            $done = $repo->rename($code, (string) ($this->request->getJSON(true)['name'] ?? ''), $this->actorId());

            return [
                'code'    => $code,
                'message' => 'Renamed to ' . $done['to'] . '. Journals already posted keep ' . $done['from']
                    . ' as recorded — a rename changes the label going forward, not history.',
            ];
        });
    }

    public function zeroShare($code)
    {
        return $this->write(function (ProgrammeRepository $repo) use ($code) {
            $repo->zeroShare($code, $this->actorId());

            return [
                'code'    => $code,
                'message' => $repo->find($code)['name'] . ' no longer carries shared costs. The remaining programmes were re-based to 100%.',
            ];
        });
    }

    public function deactivate($code)
    {
        return $this->write(function (ProgrammeRepository $repo) use ($code) {
            $p = $repo->deactivate($code, $this->actorId());

            return [
                'code'    => $code,
                'message' => $p['name'] . ' deactivated. Its history stays fully reportable; it can no longer be selected on a journal, requisition or budget line.',
            ];
        });
    }

    public function reactivate($code)
    {
        return $this->write(function (ProgrammeRepository $repo) use ($code) {
            $p = $repo->reactivate($code, $this->actorId());

            return [
                'code'    => $code,
                'message' => $p['name'] . ' reopened as pipeline with no shared-cost share. Set a share and a budget before anything is coded to it.',
            ];
        });
    }

    // ------------------------------------------------------------------

    private static function row(array $p): array
    {
        $remaining = ProgrammeRepository::remaining($p);
        $burn      = ProgrammeRepository::burnPct($p);

        return [
            'code'         => $p['code'],
            'name'         => $p['name'],
            'manager'      => $p['manager'],
            'status'       => $p['status'],
            'since'        => $p['since'],
            'budget'       => Prototype::fmt($p['budget']),
            'actual'       => Prototype::fmt($p['actual']),
            'committed'    => Prototype::fmt($p['committed']),
            'remaining'    => Prototype::fmt($remaining),
            'overspent'    => $remaining < 0,
            'burnPct'      => $burn,
            'burnLabel'    => $p['budget'] > 0 ? $burn . '%' : '—',
            'burnHeavy'    => $burn > 92,
            'share'        => $p['share'] === null ? '—' : ProgrammeRepository::pctText($p['share']),
            'allocatesOut' => $p['allocatesOut'],
        ];
    }

    /** What the drawer says about this programme's place in the shared-cost base. */
    private function shareText(array $p): string
    {
        if ($p['allocatesOut']) {
            return 'Not allocated — this is the shared-cost pool that is charged out to the others';
        }
        if ($p['status'] === 'Inactive') {
            return 'No shared-cost share — programme is closed';
        }

        return ProgrammeRepository::pctText($p['share'] ?? 0) . ' of shared costs';
    }

    /** What the new-programme form needs to draw itself and preview the re-basing. */
    private function form(ProgrammeRepository $repo): array
    {
        return [
            'managers'  => $repo->managers(),
            // Every name on the register, not just the ones on screen, so the form
            // can refuse a duplicate the current filter is hiding.
            'names'     => array_column($repo->all(), 'name'),
            'allocable' => array_map(static fn ($p) => [
                'code'  => $p['code'],
                'name'  => $p['name'],
                'share' => $p['share'],
                'was'   => ProgrammeRepository::pctText($p['share']),
            ], $repo->allocable()),
            'modes' => array_map(static fn ($m) => ['key' => $m, 'label' => [
                'prorate' => 'Pro-rate existing', 'defer' => 'Leave unchanged', 'manual' => 'Set by hand',
            ][$m], 'note' => self::MODE_NOTES[$m]], ProgrammeRepository::MODES),
        ];
    }

    /** Manual shares arrive as a list of {code, pct}; the repository wants code => pct. */
    private function shares(array $given): array
    {
        $out = [];
        foreach ($given as $row) {
            if (isset($row['code'])) {
                $out[(string) $row['code']] = (float) ($row['pct'] ?? 0);
            }
        }

        return $out;
    }

    private function write(callable $do)
    {
        try {
            return $this->json($do(new ProgrammeRepository()));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }
}
