<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * The programme register, with each programme's approved budget, posted
 * expenditure and open commitments, the awards and staff charged to it, and the
 * funds it draws on.
 *
 * Two rules shape every write here.
 *
 * Programme is a mandatory coding dimension on every posting line, so a
 * programme that carries history is deactivated, never deleted — and a rename
 * changes the label from now on while the postings already made keep reading
 * under the name they were recorded under, which is why each rename is kept as a
 * dated event rather than simply overwriting the name.
 *
 * Support costs that belong to no single programme are collected on one
 * programme (`allocates_out`) and apportioned across the others by
 * `cost_share_pct`. Those percentages have to total exactly 100 or the monthly
 * allocation either under-recovers or charges twice, so every write that touches
 * a share re-bases the rest in the same transaction.
 */
final class ProgrammeRepository extends Repository
{
    /** Shares are held to three decimals; anything inside this is the same number. */
    private const TOLERANCE = 0.05;

    /** How a new programme's share is made room for. */
    public const MODES = ['prorate', 'defer', 'manual'];

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
            $renames = [];
            foreach ($this->rows('SELECT * FROM {programme_renames} ORDER BY renamed_on, id') as $r) {
                $renames[(int) $r['programme_id']][] = [
                    'on'   => self::dmy($r['renamed_on']),
                    'from' => $r['from_name'],
                    'to'   => $r['to_name'],
                    'by'   => $this->lookups->shortName($r['renamed_by'] === null ? null : (int) $r['renamed_by']),
                ];
            }

            return array_map(fn ($p) => [
                'id'           => (int) $p['id'],
                'code'         => $p['code'],
                'name'         => $p['name'],
                'manager'      => $this->lookups->shortName($p['manager_user_id'] === null ? null : (int) $p['manager_user_id']),
                'status'       => ucfirst($p['status']),
                'since'        => self::dmy($p['started_on']),
                'purpose'      => $p['purpose'] ?? '',
                'share'        => $p['cost_share_pct'] === null ? null : self::num($p['cost_share_pct']),
                // The shared-cost pool holds no share of its own. Without this the
                // register cannot tell it from a closed programme that never had one,
                // which is the difference between a base that is complete and one
                // that is short.
                'allocatesOut' => (bool) $p['allocates_out'],
                'budget'       => self::num($budget[$p['id']]['budget'] ?? 0),
                'actual'       => self::num($actual[$p['id']] ?? 0),
                'committed'    => self::num($budget[$p['id']]['committed'] ?? 0),
                'grants'       => (int) ($grants[$p['id']]['total'] ?? 0),
                'activeGrants' => (int) ($grants[$p['id']]['live'] ?? 0),
                'staff'        => (int) ($staff[$p['id']] ?? 0),
                'funds'        => $funds[(int) $p['id']] ?? [],
                'renames'      => $renames[(int) $p['id']] ?? [],
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

    /** What is left of a programme's budget once spend and open commitments are taken off. */
    public static function remaining(array $p): float
    {
        return (float) $p['budget'] - $p['actual'] - $p['committed'];
    }

    public static function burnPct(array $p): int
    {
        return $p['budget'] > 0 ? (int) min(100, round($p['actual'] / $p['budget'] * 100)) : 0;
    }

    /**
     * The programmes that carry a share of the shared costs. A closed programme
     * is out of the base, and so is the programme that allocates its own costs out.
     */
    public function allocable(): array
    {
        return array_values(array_filter($this->all(), static fn ($p) => $p['share'] !== null && $p['status'] !== 'Inactive'));
    }

    /** The shared-cost allocation base. Anything but 100% is a live accounting problem. */
    public function shareTotal(): float
    {
        return round(array_sum(array_column($this->allocable(), 'share')), 3);
    }

    public function baseBalances(): bool
    {
        return abs($this->shareTotal() - 100) <= self::TOLERANCE;
    }

    /** Who a programme may be handed to. */
    public function managers(): array
    {
        return array_values(array_unique(array_map(
            fn ($p) => $p['manager'],
            array_filter($this->all(), static fn ($p) => $p['manager'] !== '—')
        )));
    }

    // ------------------------------------------------------------------
    // Closing a programme
    // ------------------------------------------------------------------

    /**
     * What stands in the way of closing a programme.
     *
     * Deactivation is only safe once nothing further can be coded here and
     * nothing already coded is still moving. Each entry says what is in the way
     * and what clears it, because "cannot deactivate" on its own is useless.
     *
     * @return list<array{what: string, detail: string}>
     */
    public function blockers(array $p): array
    {
        $out = [];

        if ($p['allocatesOut']) {
            $out[] = [
                'what'   => 'Collects the shared costs',
                'detail' => 'Support costs that belong to no single programme are gathered here and apportioned out each month. Another programme has to take that role before this one can close.',
            ];
        }
        if ($p['committed'] > 0) {
            $out[] = [
                'what'   => 'Open purchase commitments',
                'detail' => self::money($p['committed']) . ' sits on purchase orders not yet received. Receive or cancel them first.',
            ];
        }
        if ($p['activeGrants'] > 0) {
            $out[] = [
                'what'   => $p['activeGrants'] . ($p['activeGrants'] === 1 ? ' live award' : ' live awards'),
                'detail' => 'Donor agreements are still coded to this programme. Close them or recode to another programme.',
            ];
        }
        if ($p['staff'] > 0) {
            $out[] = [
                'what'   => $p['staff'] . ' staff ' . ($p['staff'] === 1 ? 'allocation' : 'allocations'),
                'detail' => 'Payroll splits cost to this programme. Re-base the allocation lines before deactivating.',
            ];
        }
        if ($p['share'] !== null && $p['share'] > 0) {
            $out[] = [
                'what'   => 'Receives ' . self::pctText($p['share']) . ' of shared costs',
                'detail' => 'Set the shared-cost share to zero so the remaining programmes re-base to 100%.',
            ];
        }
        if (self::remaining($p) > 0) {
            $out[] = [
                'what'   => 'Unspent budget of ' . self::money(self::remaining($p)),
                'detail' => 'Close the budget lines or move the balance in a budget revision.',
            ];
        }

        return $out;
    }

    public function canDeactivate(array $p): bool
    {
        return $p['status'] !== 'Inactive' && $this->blockers($p) === [];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Opens a programme and re-bases the shared-cost allocation in the same
     * transaction, so the base is never left away from 100%.
     *
     * `$mode` is how the existing programmes make room: `prorate` reduces each of
     * them proportionally, `defer` leaves them alone and gives the new programme
     * no share at all, and `manual` takes the shares given in `$shares`
     * (code => percentage).
     */
    public function create(array $form, string $mode, array $shares, ?int $actorId): array
    {
        $name    = trim((string) ($form['name'] ?? ''));
        $purpose = trim((string) ($form['purpose'] ?? ''));
        $share   = round((float) ($form['share'] ?? 0), 3);
        $mode    = in_array($mode, self::MODES, true) ? $mode : 'prorate';

        if ($name === '') {
            throw new RuleViolation('Give the programme a name.');
        }
        foreach ($this->all() as $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                throw new RuleViolation($name . ' already exists.');
            }
        }
        if ($purpose === '') {
            throw new RuleViolation('State what the programme covers — it is what makes coding decisions consistent between people.');
        }
        if ($share < 0 || $share >= 100) {
            throw new RuleViolation('The share of shared costs must be between 0 and 99%.');
        }

        // Defer means the programme carries none of the shared costs, so its own
        // share is zero however the form was filled in — otherwise the base would
        // be left at 100% plus whatever was typed.
        if ($mode === 'defer') {
            $share = 0.0;
        }

        $rebased = $this->rebase($mode, $share, $shares);
        $code    = $this->codeFor($form['code'] ?? null);
        $since   = $this->dateOf($form['since'] ?? null);

        $id = $this->transaction(function () use ($code, $name, $form, $purpose, $share, $since, $rebased, $actorId) {
            foreach ($rebased as $programmeId => $pct) {
                $this->db->table('programmes')->where('id', $programmeId)->update(['cost_share_pct' => $pct, 'updated_at' => Clock::timestamp()]);
            }

            $id = $this->insert('programmes', [
                'code'            => $code,
                'name'            => $name,
                'manager_user_id' => $this->lookups->userId($form['manager'] ?? null),
                // A programme opens with no budget: nothing can be spent against it
                // until an award is recorded or a budget revision adds lines, which
                // is what stops one existing on paper and quietly absorbing costs.
                'status'          => 'pipeline',
                'started_on'      => $since,
                'purpose'         => $purpose,
                'cost_share_pct'  => $share,
                'allocates_out'   => 0,
                'created_at'      => Clock::timestamp(),
            ]);

            $this->audit('programme', $id, $code, $name . ' opened as pipeline with ' . self::pctText($share) . ' of shared costs', $actorId, 'create');

            return $id;
        });

        return ['id' => $id, 'code' => $code, 'name' => $name, 'share' => $share];
    }

    /**
     * Changes the label from now on. What is already posted keeps the name it was
     * recorded under, which is what an auditor expects to see, so the rename is
     * kept as a dated event rather than silently overwriting history.
     */
    public function rename(string $code, string $name, ?int $actorId): array
    {
        $p    = $this->require($code);
        $name = trim($name);

        if ($name === '') {
            throw new RuleViolation('A programme needs a name.');
        }
        if ($name === $p['name']) {
            throw new RuleViolation($p['name'] . ' already reads that way.');
        }
        foreach ($this->all() as $other) {
            if ($other['id'] !== $p['id'] && mb_strtolower($other['name']) === mb_strtolower($name)) {
                throw new RuleViolation($name . ' is already in use by another programme.');
            }
        }

        $this->transaction(function () use ($p, $name, $actorId) {
            $this->insert('programme_renames', [
                'programme_id' => $p['id'],
                'from_name'    => $p['name'],
                'to_name'      => $name,
                'renamed_on'   => Clock::date(),
                'renamed_by'   => $actorId,
                'created_at'   => Clock::timestamp(),
            ]);
            $this->db->table('programmes')->where('id', $p['id'])->update(['name' => $name, 'updated_at' => Clock::timestamp()]);
            $this->audit('programme', $p['id'], $p['code'], 'Renamed from ' . $p['name'] . ' to ' . $name, $actorId, 'rename');
        });

        return ['from' => $p['name'], 'to' => $name];
    }

    /**
     * Takes a programme out of the shared-cost base and re-bases the rest to
     * 100%, which is the step that has to come before deactivating it.
     */
    public function zeroShare(string $code, ?int $actorId): float
    {
        $p = $this->require($code);

        if ($p['share'] === null || $p['share'] <= 0) {
            throw new RuleViolation($p['name'] . ' carries no shared costs already.');
        }

        $others = array_values(array_filter($this->allocable(), static fn ($x) => $x['id'] !== $p['id']));
        if ($others === []) {
            throw new RuleViolation('No other programme is left to carry the shared costs. Open one before taking this share to zero.');
        }

        $was    = (float) $p['share'];
        $shares = self::spread(array_column($others, 'share'), 100.0);

        $this->transaction(function () use ($p, $others, $shares, $was, $actorId) {
            $this->db->table('programmes')->where('id', $p['id'])->update(['cost_share_pct' => 0, 'updated_at' => Clock::timestamp()]);
            foreach ($others as $i => $other) {
                $this->db->table('programmes')->where('id', $other['id'])->update(['cost_share_pct' => $shares[$i], 'updated_at' => Clock::timestamp()]);
            }
            $this->audit('programme', $p['id'], $p['code'], self::pctText($was) . ' share released; the remaining programmes re-based to 100%', $actorId, 'update');
        });

        return $was;
    }

    /** Closes a programme to new coding. Its history stays fully reportable. */
    public function deactivate(string $code, ?int $actorId): array
    {
        $p = $this->require($code);

        if ($p['status'] === 'Inactive') {
            throw new RuleViolation($p['name'] . ' is already closed.');
        }
        $blockers = $this->blockers($p);
        if ($blockers !== []) {
            throw new RuleViolation($p['name'] . ' cannot be deactivated yet — ' . lcfirst($blockers[0]['what']) . '. ' . $blockers[0]['detail']);
        }

        $this->transaction(function () use ($p, $actorId) {
            // The share goes to NULL rather than 0: a closed programme is out of the
            // base entirely, not in it for nothing.
            $this->db->table('programmes')->where('id', $p['id'])->update(['status' => 'inactive', 'cost_share_pct' => null, 'updated_at' => Clock::timestamp()]);
            $this->audit('programme', $p['id'], $p['code'], $p['name'] . ' deactivated; history stays reportable and it can no longer be coded to', $actorId, 'update');
        });

        return $p;
    }

    /** Reopens a closed programme, with no share and no budget until one is set. */
    public function reactivate(string $code, ?int $actorId): array
    {
        $p = $this->require($code);

        if ($p['status'] !== 'Inactive') {
            throw new RuleViolation($p['name'] . ' is already open.');
        }

        $this->transaction(function () use ($p, $actorId) {
            $this->db->table('programmes')->where('id', $p['id'])->update(['status' => 'pipeline', 'cost_share_pct' => 0, 'updated_at' => Clock::timestamp()]);
            $this->audit('programme', $p['id'], $p['code'], $p['name'] . ' reopened as pipeline with no shared-cost share', $actorId, 'update');
        });

        return $p;
    }

    // ------------------------------------------------------------------
    // Shares
    // ------------------------------------------------------------------

    /**
     * The shares the existing programmes are left on once a new one takes
     * `$share`, keyed by programme id.
     *
     * @return array<int, float>
     */
    private function rebase(string $mode, float $share, array $shares): array
    {
        $allocable = $this->allocable();

        if ($mode === 'defer') {
            // Nothing moves, so nothing is written. The base is only valid if it
            // already balances.
            $this->assertBase(array_sum(array_column($allocable, 'share')), 0.0);

            return [];
        }

        if ($allocable === []) {
            $this->assertBase(0.0, $share);

            return [];
        }

        if ($mode === 'prorate') {
            $room = self::spread(array_column($allocable, 'share'), 100 - $share);
        } else {
            $room = [];
            foreach ($allocable as $p) {
                $given = $shares[$p['code']] ?? $shares[$p['name']] ?? null;
                if ($given === null) {
                    throw new RuleViolation('Set a share by hand for every programme, including ' . $p['name'] . '.');
                }
                $room[] = round((float) $given, 3);
            }
        }

        $this->assertBase(array_sum($room), $share);

        return array_combine(array_column($allocable, 'id'), $room);
    }

    private function assertBase(float $others, float $share): void
    {
        $total = round($others + $share, 3);

        if (abs($total - 100) > self::TOLERANCE) {
            throw new RuleViolation(
                'The shared-cost allocation totals ' . self::pctText($total)
                . '. It has to be exactly 100% or support costs are under- or over-recovered.'
            );
        }
    }

    /**
     * Scales a set of shares to a new total, keeping their proportions. The last
     * share takes the rounding remainder so the set adds up exactly.
     *
     * @param list<float> $shares
     * @return list<float>
     */
    private static function spread(array $shares, float $total): array
    {
        $base  = array_sum($shares);
        $count = count($shares);
        $out   = [];
        $run   = 0.0;

        foreach ($shares as $i => $share) {
            if ($i === $count - 1) {
                $out[] = round($total - $run, 3);
                break;
            }
            $value = round($base > 0 ? $share / $base * $total : $total / $count, 3);
            $out[] = $value;
            $run   = round($run + $value, 3);
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function require(string $code): array
    {
        return $this->find($code) ?? throw new RuleViolation($code . ' was not found in the programme register.');
    }

    /** The code given, or the next one in the register's own sequence. */
    private function codeFor(?string $given): string
    {
        $given = strtoupper(trim((string) $given));

        if ($given !== '') {
            foreach ($this->all() as $p) {
                if (strtoupper($p['code']) === $given) {
                    throw new RuleViolation($given . ' is already the code of ' . $p['name'] . '.');
                }
            }

            return $given;
        }

        $numbers = array_map(static fn ($p) => (int) preg_replace('/\D/', '', $p['code']), $this->all());

        return 'PRG-' . (max([10, ...$numbers]) + 10);
    }

    private function dateOf(?string $given): string
    {
        $given = trim((string) $given);
        $at    = $given === '' ? false : strtotime($given);

        return $at === false ? Clock::date() : date('Y-m-d', $at);
    }

    /** "44" not "44.000"; "12.5" where the share really carries a decimal. */
    public static function pctText(float|int|null $pct): string
    {
        return $pct === null ? '—' : rtrim(rtrim(number_format((float) $pct, 3, '.', ''), '0'), '.') . '%';
    }

    private static function money(float|int $n): string
    {
        return Prototype::fmt($n);
    }
}
