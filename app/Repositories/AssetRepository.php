<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * The fixed asset register, its monthly depreciation run, disposals, and the open
 * verification count.
 *
 * Accumulated depreciation is what was brought forward plus every posted
 * depreciation entry; "Fully depreciated" follows from it. The register is the
 * subsidiary record behind 1310, 1320 and 1390, so it holds only capitalised
 * assets. The count covers every asset still held, capitalised or not.
 *
 * - Depreciation is straight line over the useful life to the residual value,
 *   charged from the month after acquisition. One run a period posts the charge
 *   to 5350 and 1390 on each asset's fund, programme and grant.
 * - A disposal is proposed with its board minute (and the donor's written consent
 *   where title reverts to the donor), and reaches the ledger only when a second
 *   person approves it: the cost and the depreciation to date come off the
 *   register, proceeds are banked, and the difference is a gain (4250) or a loss
 *   (5360). Until then the asset stays on the register and keeps depreciating.
 */
final class AssetRepository extends Repository
{
    public const RESULT_LABELS = ['sighted' => 'Sighted', 'not_found' => 'Not found', 'condition_issue' => 'Condition issue'];

    /** Disposal method as the screen names it → [method, sale channel]. */
    public const DISPOSAL_METHODS = [
        'Public auction'            => ['sale', 'public_auction'],
        'Direct sale'               => ['sale', 'direct_sale'],
        'Trade-in'                  => ['trade_in', null],
        'Donation to a partner'     => ['donation', null],
        'Write-off — beyond repair' => ['write_off', null],
    ];





    /** Where disposal proceeds are banked. */

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** Every asset loaded, capitalised or not. */
    public function all(): array
    {
        return $this->cached('all', function () {
            $trails = $this->trails('asset', 'd M Y');

            return array_map(function ($a) use ($trails) {
                $accum    = (float) $a['opening_accumulated_depreciation'] + (float) $a['depreciation'];
                $disposed = $a['status'] === 'disposed';
                $grant    = $a['grant_id'] === null ? null : $this->lookups->grants()[$a['grant_id']];

                $asset = [
                    'tag'         => $a['tag'],
                    'name'        => $a['description'],
                    'cls'         => $a['class_name'],
                    'acquired'    => self::dmy($a['acquired_on']),
                    'acquiredOn'  => $a['acquired_on'],
                    'life'        => (int) $a['useful_life_years'],
                    'cost'        => self::num($a['cost']),
                    'residual'    => self::num($a['residual_value']),
                    'accum'       => self::num($accum),
                    'costAcct'    => $a['cost_account'],
                    'fund'        => self::FUND_GROUPS[$this->lookups->funds()[$a['fund_id']]['ledger_group']],
                    'funder'      => $grant['funder_name'] ?? '—',
                    'grant'       => $grant['short_name'] ?? 'Unassigned',
                    'program'     => $this->lookups->programmeName($a['programme_id'] === null ? null : (int) $a['programme_id']),
                    'title'       => $a['title_condition'] ?? '',
                    'custodian'   => $a['custodian'] ?? '—',
                    'location'    => $disposed ? 'Disposed' : ($a['location_name'] ?? '—'),
                    'status'      => match (true) {
                        $disposed                                                   => 'Disposed',
                        $accum >= (float) $a['cost'] - (float) $a['residual_value'] => 'Fully depreciated',
                        default                                                     => 'In use',
                    },
                    'capitalised' => (bool) $a['capitalised'],
                    'doc'         => $a['document_ref'] ?? '',
                    'trail'       => $trails[(int) $a['id']] ?? [],
                    'id'          => (int) $a['id'],
                    'fundId'      => (int) $a['fund_id'],
                    'programmeId' => $a['programme_id'] === null ? null : (int) $a['programme_id'],
                    'grantId'     => $a['grant_id'] === null ? null : (int) $a['grant_id'],
                ];

                return $a['disposed_on'] === null || $a['disposal_status'] === 'pending_approval'
                    ? $asset
                    : $asset + ['proceeds' => self::num($a['proceeds']), 'disposed' => self::dmy($a['disposed_on'])];
            }, $this->rows(
                'SELECT a.*, c.name AS class_name, ca.code AS cost_account, l.name AS location_name,
                        d.disposed_on, d.proceeds, d.status AS disposal_status,
                        (SELECT COALESCE(SUM(e.amount), 0) FROM {depreciation_entries} e JOIN {depreciation_runs} r ON r.id = e.depreciation_run_id
                         WHERE e.asset_id = a.id AND r.status = \'posted\') AS depreciation
                 FROM {assets} a JOIN {asset_classes} c ON c.id = a.asset_class_id JOIN {accounts} ca ON ca.id = c.cost_account_id
                 LEFT JOIN {locations} l ON l.id = a.location_id LEFT JOIN {asset_disposals} d ON d.asset_id = a.id
                 ORDER BY a.id'
            ));
        });
    }

    /** The register: capitalised assets, the subsidiary record behind the ledger. */
    public function register(): array
    {
        return $this->cached('register', fn () => array_values(array_filter($this->all(), static fn ($a) => $a['capitalised'])));
    }

    public function find(string $tag): ?array
    {
        foreach ($this->register() as $a) {
            if ($a['tag'] === $tag) {
                return $a;
            }
        }

        return null;
    }

    // ---- Depreciation ----

    /** The period the books are working in, which the depreciation run is for. */
    public function period(): ?array
    {
        return (new PeriodRepository())->current();
    }

    /** The posted run for a period, or null. */
    public function run(?array $period): ?array
    {
        if ($period === null) {
            return null;
        }

        return $this->cached('run:' . $period['id'], function () use ($period) {
            $run = $this->row("SELECT r.*, j.reference AS journal_ref FROM {depreciation_runs} r LEFT JOIN {journals} j ON j.id = r.journal_id
                               WHERE r.entity_id = ? AND r.period_id = ? AND r.status = 'posted'", [$this->lookups->entityId(), $period['id']]);
            if ($run === null) {
                return null;
            }
            $run['entries'] = array_column($this->rows(
                'SELECT a.tag, e.amount FROM {depreciation_entries} e JOIN {assets} a ON a.id = e.asset_id WHERE e.depreciation_run_id = ?',
                [$run['id']]
            ), 'amount', 'tag');

            return $run;
        });
    }

    /** Straight line: cost less residual over the useful life, a month. */
    public static function monthlyBase(array $a): float
    {
        return round(($a['cost'] - ($a['residual'] ?? 0)) / ($a['life'] * 12));
    }

    /**
     * What a run for the period charges an asset: nothing once it is disposed of or
     * written down, nothing in the month it was bought, and never more than is left.
     * Once the period's run has posted, what it charged.
     */
    public function charge(array $a, ?array $period): float
    {
        if ($period === null) {
            return 0.0;
        }
        $run = $this->run($period);
        if ($run !== null) {
            return (float) ($run['entries'][$a['tag']] ?? 0);
        }
        if ($a['status'] !== 'In use' || substr($a['acquiredOn'], 0, 7) >= substr($period['starts_on'], 0, 7)) {
            return 0.0;
        }

        return max(0.0, min(self::monthlyBase($a), $a['cost'] - $a['residual'] - $a['accum']));
    }

    /** The whole register's charge for the period. */
    public function chargeFor(?array $period): float
    {
        return array_sum(array_map(fn ($a) => $this->charge($a, $period), $this->register()));
    }

    /**
     * Posts the period's depreciation: debit 5350 and credit 1390 on each fund,
     * programme and grant the assets are coded to. One run a period.
     *
     * @return array{journal: string, total: float, assets: int, period: string}
     */
    public function runDepreciation(int $actorId): array
    {
        $period = $this->period() ?? throw new RuleViolation('There is no open period to charge depreciation to.');
        if ($this->run($period) !== null) {
            throw new RuleViolation('Depreciation for ' . $period['name'] . ' has already been posted.');
        }

        $charges = [];
        foreach ($this->register() as $a) {
            $amount = $this->charge($a, $period);
            if ($amount > 0) {
                $charges[] = [$a, $amount];
            }
        }
        if ($charges === []) {
            throw new RuleViolation('Nothing to depreciate — every asset in use is already fully written down.');
        }

        $groups = [];
        foreach ($charges as [$a, $amount]) {
            $key = $a['fundId'] . '|' . $a['programmeId'] . '|' . $a['grantId'];
            $groups[$key] ??= ['fund_id' => $a['fundId'], 'programme_id' => $a['programmeId'], 'grant_id' => $a['grantId'], 'program' => $a['program'], 'amount' => 0.0];
            $groups[$key]['amount'] += $amount;
        }
        $total = round(array_sum(array_column($groups, 'amount')), 2);
        $who = $this->lookups->shortName($actorId);

        return $this->transaction(function () use ($period, $charges, $groups, $total, $actorId, $who) {
            $now = Clock::timestamp();
            $runId = $this->insert('depreciation_runs', [
                'entity_id' => $this->lookups->entityId(), 'period_id' => $period['id'], 'status' => 'draft', 'total' => $total,
                'run_by' => $actorId, 'created_at' => $now,
            ]);
            foreach ($charges as [$a, $amount]) {
                $this->insert('depreciation_entries', ['depreciation_run_id' => $runId, 'asset_id' => $a['id'], 'amount' => $amount]);
            }

            $line = static fn (array $g, string $code, string $desc, float $dr, float $cr) => [
                'code' => $code, 'fund_id' => $g['fund_id'], 'programme_id' => $g['programme_id'], 'grant_id' => $g['grant_id'], 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
            ];
            $lines = array_merge(
                array_map(static fn ($g) => $line($g, PostingAccounts::of('depreciation'), 'Depreciation charge — ' . $g['program'], $g['amount'], 0), array_values($groups)),
                array_map(static fn ($g) => $line($g, PostingAccounts::of('accumulated'), 'Accumulated depreciation — ' . $g['program'], 0, $g['amount']), array_values($groups)),
            );
            $journal = (new JournalRepository())->postFromSource([
                'date' => $period['ends_on'], 'sourceType' => 'depreciation_run', 'sourceId' => $runId, 'docRef' => 'Depreciation run ' . $period['name'], 'series' => 'JV',
                'narration' => 'Monthly depreciation charge — ' . $period['name'],
                'memo' => 'Straight-line charge for ' . $period['name'] . ' across ' . count($charges) . ' assets in use.',
            ], $lines, $actorId, null, 'Raised by the asset register on the depreciation run for ' . $period['name'] . ' by ' . $who);

            $this->db->table('depreciation_runs')->where('id', $runId)->update([
                'status' => 'posted', 'posted_at' => $now, 'journal_id' => $this->journalId($journal), 'updated_at' => $now,
            ]);
            foreach ($charges as [$a, $amount]) {
                $this->audit('asset', $a['id'], $a['tag'], 'Depreciation of ' . Prototype::fmt($amount) . ' for ' . $period['name'] . ' posted as ' . $journal . ' by ' . $who, $actorId);
            }

            return ['journal' => $journal, 'total' => $total, 'assets' => count($charges), 'period' => $period['name']];
        });
    }

    /**
     * Year-by-year depreciation from acquisition, at the straight-line rate: the
     * year it was bought carries the months after the month of purchase.
     *
     * @return list<array{year: string, opening: float, charge: float, closing: float, current: bool}>
     */
    public function schedule(array $a, ?array $disposal = null): array
    {
        $monthly = self::monthlyBase($a);
        $depreciable = $a['cost'] - $a['residual'];
        [$year, $month] = array_map('intval', explode('-', substr($a['acquiredOn'], 0, 7)));
        $current = (int) substr(($this->period() ?? ['starts_on' => Clock::date()])['starts_on'], 0, 4);
        $disposedYear = $disposal === null ? null : (int) substr($disposal['disposed_on'], 0, 4);

        $rows = [];
        $cum = 0.0;
        for ($y = $year; $y <= $year + $a['life'] + 1 && $cum < $depreciable && count($rows) < 8; $y++) {
            $months = $y === $year ? 12 - $month : 12;
            $charge = min($monthly * $months, $depreciable - $cum);
            if ($charge <= 0 && $y !== $year) {
                break;
            }
            $rows[] = [
                'year'    => (string) $y . ($y === $disposedYear ? ' — disposed ' . date('d M', strtotime($disposal['disposed_on'])) : ''),
                'opening' => $a['cost'] - $cum, 'charge' => $charge, 'closing' => $a['cost'] - $cum - $charge, 'current' => $y === $current,
            ];
            $cum += $charge;
            if ($y === $disposedYear) {
                break;
            }
        }

        return $rows;
    }

    // ---- Disposals ----

    /** Disposals by tag, with the method named as the screen names it. */
    public function disposals(): array
    {
        return $this->cached('disposals', function () {
            $out = [];
            foreach ($this->rows(
                'SELECT d.*, a.tag, j.reference AS journal_ref FROM {asset_disposals} d JOIN {assets} a ON a.id = d.asset_id
                 LEFT JOIN {journals} j ON j.id = d.journal_id ORDER BY d.id'
            ) as $d) {
                $d['methodLabel'] = self::methodLabel($d['method'], $d['sale_channel']);
                $out[$d['tag']] = $d;
            }

            return $out;
        });
    }

    public static function methodLabel(string $method, ?string $channel): string
    {
        foreach (self::DISPOSAL_METHODS as $label => [$m, $c]) {
            if ($m === $method && ($c === null || $c === ($channel ?? 'direct_sale'))) {
                return $label;
            }
        }

        return ucfirst(str_replace('_', ' ', $method));
    }

    /** Title passes to the donor, so the donor must consent in writing before the asset leaves. */
    public static function titleReverts(array $a): bool
    {
        return stripos($a['title'], 'revert') !== false;
    }

    /**
     * Why a disposal cannot be proposed as it stands, or null. The same rules the
     * screen shows before the proposal is sent.
     *
     * @param array{method: string, date: string, proceeds: float, buyer: string, minute: string, consent: string, reason: string} $f
     */
    public function disposalBlock(array $a, array $f): ?string
    {
        $writeOff = $f['method'] === 'Write-off — beyond repair';
        $period = $this->periodOf($f['date']);

        return match (true) {
            !isset(self::DISPOSAL_METHODS[$f['method']])                                   => 'Choose how the asset is being disposed of.',
            trim($f['minute']) === ''                                                      => 'Every disposal needs the board minute that approved it.',
            self::titleReverts($a) && trim($f['consent']) === ''                           => 'Title on this asset reverts to ' . $a['funder'] . '. Written donor consent must be referenced before it can leave the register.',
            $f['proceeds'] < 0                                                             => 'Proceeds cannot be negative.',
            $writeOff && $f['proceeds'] > 0                                                => 'A write-off cannot raise proceeds — record a sale instead.',
            !$writeOff && $f['proceeds'] == 0 && $f['method'] !== 'Donation to a partner'  => 'Enter the proceeds, or change the method to a write-off or a donation.',
            $period === null                                                               => 'Enter the disposal date as a date in the financial year.',
            $f['date'] < $a['acquiredOn']                                                  => 'The disposal cannot be dated before the asset was acquired on ' . $a['acquired'] . '.',
            $period['status'] === 'closed'                                                 => $period['name'] . ' is closed to posting. Reopen it or date the disposal in an open period.',
            trim($f['reason']) === ''                                                      => 'Record why the asset is being disposed of and the condition it is in.',
            default                                                                        => null,
        };
    }

    /** Proposes a disposal for approval. Nothing leaves the register until it is approved. */
    public function proposeDisposal(string $tag, array $f, int $actorId): array
    {
        $a = $this->find($tag) ?? throw new RuleViolation($tag . ' is not on the asset register.');
        if ($a['status'] === 'Disposed') {
            throw new RuleViolation($tag . ' has already been disposed of.');
        }
        if (isset($this->disposals()[$tag])) {
            throw new RuleViolation('A disposal of ' . $tag . ' is already waiting for approval.');
        }
        $block = $this->disposalBlock($a, $f);
        if ($block !== null) {
            throw new RuleViolation($block);
        }

        [$method, $channel] = self::DISPOSAL_METHODS[$f['method']];
        $proceeds = round($f['proceeds'], 2);

        $this->transaction(function () use ($a, $f, $method, $channel, $proceeds, $actorId) {
            $this->insert('asset_disposals', [
                'asset_id' => $a['id'], 'disposed_on' => $f['date'], 'method' => $method, 'sale_channel' => $channel, 'proceeds' => $proceeds,
                'accumulated_depreciation' => $a['accum'], 'carrying_amount' => $a['cost'] - $a['accum'],
                'buyer' => trim($f['buyer']) !== '' ? trim($f['buyer']) : null, 'board_minute' => trim($f['minute']),
                'donor_consent_ref' => trim($f['consent']) !== '' ? trim($f['consent']) : null, 'reason' => trim($f['reason']),
                'status' => 'pending_approval', 'requested_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
            $this->audit('asset', $a['id'], $a['tag'], 'Disposal by ' . strtolower($f['method']) . ' on ' . self::dmy($f['date']) . ' proposed by '
                . $this->lookups->shortName($actorId) . ', board minute ' . trim($f['minute']) . ' — waiting for approval', $actorId);
        });

        return $this->disposals()[$tag];
    }

    /** Takes back a proposal that has not been approved. */
    public function withdrawDisposal(string $tag, int $actorId): void
    {
        $d = $this->disposals()[$tag] ?? null;
        if ($d === null || $d['status'] !== 'pending_approval') {
            throw new RuleViolation('There is no disposal of ' . $tag . ' waiting for approval.');
        }

        $this->transaction(function () use ($d, $tag, $actorId) {
            $this->db->table('asset_disposals')->where('id', $d['id'])->delete();
            $this->audit('asset', (int) $d['asset_id'], $tag, 'Disposal proposal withdrawn by ' . $this->lookups->shortName($actorId), $actorId);
        });
    }

    /**
     * Approves a proposed disposal and posts it: proceeds banked, depreciation to
     * date released, cost derecognised, and the gain or loss taken to the surplus.
     * The person who proposed it cannot approve it.
     *
     * @return array{journal: string, cost: float, accum: float, proceeds: float, result: float}
     */
    public function approveDisposal(string $tag, int $actorId): array
    {
        $d = $this->disposals()[$tag] ?? null;
        if ($d === null || $d['status'] !== 'pending_approval') {
            throw new RuleViolation('There is no disposal of ' . $tag . ' waiting for approval.');
        }
        if ((int) $d['requested_by'] === $actorId) {
            throw new RuleViolation('The person who proposed a disposal cannot approve it. It needs a second approver.');
        }
        $a = $this->find($tag);
        $period = $this->periodOf($d['disposed_on']);
        if ($period === null || $period['status'] === 'closed') {
            throw new RuleViolation(($period['name'] ?? self::dmy($d['disposed_on'])) . ' is closed to posting. Withdraw the proposal and date the disposal in an open period.');
        }

        // Depreciation posted since the proposal comes off with the cost.
        $accum = (float) $a['accum'];
        $proceeds = (float) $d['proceeds'];
        $result = round($proceeds - ($a['cost'] - $accum), 2);
        $capital = (int) $this->value("SELECT id FROM {funds} WHERE ledger_group = 'capital' ORDER BY code LIMIT 1");
        $shared = $this->lookups->programmeId('Shared services');
        $line = static fn (string $code, string $desc, float $dr, float $cr) => [
            'code' => $code, 'fund_id' => $capital, 'programme_id' => $shared, 'grant_id' => null, 'desc' => $desc, 'dr' => $dr, 'cr' => $cr,
        ];
        $who = $this->lookups->shortName($actorId);

        return $this->transaction(function () use ($d, $a, $tag, $accum, $proceeds, $result, $line, $who, $actorId) {
            $journal = (new JournalRepository())->postFromSource([
                'date' => $d['disposed_on'], 'sourceType' => 'asset_disposal', 'sourceId' => (int) $d['id'], 'docRef' => $a['doc'] !== '' ? $a['doc'] : $tag, 'series' => 'JV',
                'narration' => 'Disposal of ' . $a['name'] . ' (' . $tag . ')',
                'memo' => 'Disposal by ' . strtolower($d['methodLabel']) . ', board minute ' . $d['board_minute'] . '.',
            ], [
                $line(PostingAccounts::of('disposalProceeds'), 'Disposal proceeds banked — ' . ($d['buyer'] ?? $tag), $proceeds, 0),
                $line(PostingAccounts::of('accumulated'), 'Accumulated depreciation released on disposal', $accum, 0),
                $line($a['costAcct'], 'Asset cost derecognised — ' . $tag, 0, $a['cost']),
                $result >= 0
                    ? $line(PostingAccounts::of('disposalGain'), 'Gain on disposal of ' . $tag, 0, $result)
                    : $line(PostingAccounts::of('disposalLoss'), 'Loss on disposal of ' . $tag, -$result, 0),
            ], (int) $d['requested_by'], $actorId, 'Raised by the asset register on the disposal of ' . $tag);

            $now = Clock::timestamp();
            $this->db->table('asset_disposals')->where('id', $d['id'])->update([
                'status' => 'posted', 'approved_by' => $actorId, 'approved_at' => $now, 'journal_id' => $this->journalId($journal),
                'accumulated_depreciation' => $accum, 'carrying_amount' => $a['cost'] - $accum, 'updated_at' => $now,
            ]);
            $this->db->table('assets')->where('id', $a['id'])->update(['status' => 'disposed', 'updated_at' => $now]);
            $this->audit('asset', $a['id'], $tag, 'Disposal approved by ' . $who . ' and posted as ' . $journal . ' — derecognised, '
                . ($result >= 0 ? 'gain of ' . Prototype::fmt($result) . ' to ' . PostingAccounts::of('disposalGain') : 'loss of ' . Prototype::fmt(-$result) . ' to ' . PostingAccounts::of('disposalLoss')), $actorId);

            return ['journal' => $journal, 'cost' => (float) $a['cost'], 'accum' => $accum, 'proceeds' => $proceeds, 'result' => $result];
        });
    }

    // ---- The register against the ledger ----

    /**
     * Cost and depreciation of the assets held, per the register and per 1310, 1320
     * and 1390.
     *
     * @return array{costAccounts: list<string>, registerCost: float, ledgerCost: float, registerAccum: float, ledgerAccum: float}
     */
    public function tie(): array
    {
        $held = array_filter($this->register(), static fn ($a) => $a['status'] !== 'Disposed');
        $costAccounts = array_values(array_unique(array_column($this->rows(
            'SELECT a.code FROM {asset_classes} c JOIN {accounts} a ON a.id = c.cost_account_id'
        ), 'code')));
        sort($costAccounts);

        return [
            'costAccounts'  => $costAccounts,
            'registerCost'  => round(array_sum(array_column($held, 'cost')), 2),
            'ledgerCost'    => round(array_sum(array_map(fn ($c) => $this->lookups->balance($c), $costAccounts)), 2),
            // A contra account reads negative on its asset side.
            'registerAccum' => round(array_sum(array_column($held, 'accum')), 2),
            'ledgerAccum'   => round(-$this->lookups->balance(PostingAccounts::of('accumulated')), 2),
        ];
    }

    /** @return array|null the accounting period a date falls in */
    public function periodOf(string $date): ?array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $date && $date <= $p['ends_on']) {
                return $p;
            }
        }

        return null;
    }

    private function journalId(string $ref): int
    {
        return (int) $this->value('SELECT id FROM {journals} WHERE reference = ?', [$ref]);
    }

    // ---- Verification ----

    /** The open count round, or the latest one. */
    public function round(): ?array
    {
        return $this->cached('round', fn () => $this->row("SELECT * FROM {verification_rounds} ORDER BY status = 'closed', opened_on DESC LIMIT 1"));
    }

    /** Assets on the count, capitalised or not, with carrying amount, in the shape the count sheet uses. */
    public function countSheet(): array
    {
        return $this->cached('count-sheet', fn () => array_map(fn ($a) => [
            'tag'       => $a['tag'],
            'desc'      => $a['name'],
            'cls'       => $a['cls'],
            'location'  => $a['location'],
            'custodian' => $a['custodian'],
            'nbv'       => self::num($a['cost'] - $a['accum']),
            'expected'  => 'Sighted',
        ], array_values(array_filter($this->all(), static fn ($a) => $a['status'] !== 'Disposed'))));
    }

    /** @return array<string, array{result: string, note: string}> results recorded so far, by tag */
    public function results(): array
    {
        $round = $this->round();
        if ($round === null) {
            return [];
        }

        return $this->cached('results', function () use ($round) {
            $out = [];
            $documents = (new AttachmentRepository())->byObject('verification_result');
            foreach ($this->rows(
                'SELECT r.id, a.tag, r.result, r.note FROM {verification_results} r JOIN {assets} a ON a.id = r.asset_id WHERE r.verification_round_id = ?',
                [$round['id']]
            ) as $r) {
                $out[$r['tag']] = ['result' => self::RESULT_LABELS[$r['result']], 'note' => $r['note'] ?? '', 'id' => (int) $r['id'], 'documents' => $documents[(int) $r['id']] ?? []];
            }

            return $out;
        });
    }

    /** Records (or re-records) the count result for one asset. */
    public function record(string $tag, string $result, string $note, int $actorId, mixed $documentIds = []): array
    {
        $round = $this->round();
        $asset = $this->row('SELECT id FROM {assets} WHERE tag = ?', [$tag]);
        if ($round === null || $round['status'] === 'closed') {
            throw new RuleViolation('There is no open verification round to record against.');
        }
        if ($asset === null) {
            throw new RuleViolation($tag . ' is not on the verification list for ' . $round['reference'] . '.');
        }

        $row = [
            'result' => array_search($result, self::RESULT_LABELS, true), 'note' => $note !== '' ? $note : null,
            'counted_by' => $actorId, 'counted_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
        ];

        // Recommended: a photo of the asset and its tag, or of the damage found.
        $attachments = new AttachmentRepository($this->db);
        $documents = $attachments->pending($documentIds, $actorId);

        $this->transaction(function () use ($round, $asset, $row, $tag, $result, $actorId, $attachments, $documents) {
            $existing = $this->value('SELECT id FROM {verification_results} WHERE verification_round_id = ? AND asset_id = ?', [$round['id'], $asset['id']]);
            $existing === null
                ? $existing = $this->insert('verification_results', $row + ['verification_round_id' => $round['id'], 'asset_id' => $asset['id'], 'created_at' => Clock::timestamp()])
                : $this->db->table('verification_results')->where('id', $existing)->update($row);
            $attachments->claim($documents, 'verification_result', (int) $existing);
            $this->audit('asset', (int) $asset['id'], $tag, 'Counted in ' . $round['reference'] . ': ' . $result . ' (' . $this->lookups->shortName($actorId) . ')', $actorId);
        });

        return $this->results()[$tag];
    }
}
