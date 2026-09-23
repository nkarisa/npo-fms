<?php

namespace App\Repositories;

use App\Libraries\Clock;
use App\Libraries\Prototype;

/**
 * Budget versions, their lines, and the two ways a budget changes.
 *
 * A financial year's budget is a series of versions: the original, each revision
 * approved since, and at most one working revision. Only one version of a year is
 * approved at a time, and that is the one the procurement budget check, payables
 * and the programme register read (see workingYearApproved()).
 *
 * - A revision moves budget between two lines of the approved budget. The first
 *   movement opens a working revision, a copy of the approved version; each one is
 *   applied to it and recorded as a reallocation. The working revision is then
 *   submitted and approved by someone else, under the budget revision approval
 *   rule, and replaces the approved version it was copied from. A revision nets to
 *   zero within one fund group and one grant agreement, so a fund's total is never
 *   changed by it.
 * - A new financial year is derived rather than typed: a grant-funded line gets
 *   what its award's remaining ceiling allows for the months of the award that fall
 *   inside the year, and a core line rolls forward from the approved budget with an
 *   uplift. The result is a draft, which the Executive Director (whoever holds
 *   period.authorise) approves before it can be reported against.
 *
 * Lines are identified across versions by their coding — account, fund, programme
 * and grant — which is what a revision keeps and what the version history follows.
 * Phasing is the line's budget_phases where the year has its months, and otherwise
 * its profile's weights.
 */
final class BudgetRepository extends Repository
{
    /** Movements above this share of a grant-funded line need the funder's written consent. */
    public const FUNDER_CONSENT_SHARE = 0.10;

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /**
     * The condition that picks, for each entity, the approved version of the year its
     * books are working in — the earliest open period's year, or the latest year once
     * every period is closed. An approved budget for next year does not count yet.
     * For a query aliasing budget_versions as `bv`.
     */
    public static function workingYearApproved(string $bv = 'bv'): string
    {
        return "{$bv}.status = 'approved' AND {$bv}.fiscal_year_id = COALESCE("
            . "(SELECT wp.fiscal_year_id FROM {periods} wp WHERE wp.entity_id = {$bv}.entity_id AND wp.status = 'open' ORDER BY wp.starts_on LIMIT 1),"
            . "(SELECT wp.fiscal_year_id FROM {periods} wp WHERE wp.entity_id = {$bv}.entity_id ORDER BY wp.starts_on DESC LIMIT 1))";
    }

    // ------------------------------------------------------------------
    // Versions
    // ------------------------------------------------------------------

    /**
     * Every version in scope, newest year first and in the order they were made
     * within a year. In the consolidated view each entity has its own; versions of
     * the same year and name are read together under one key.
     *
     * @return list<array{key: string, label: string, name: string, fyCode: string, fyStarts: string, fyEnds: string, status: string, isRevision: bool, ids: list<int>, rows: list<array>}>
     */
    public function versions(): array
    {
        return $this->cached('versions', function () {
            $out = [];
            foreach ($this->rows(
                'SELECT bv.*, fy.code AS fy_code, fy.starts_on AS fy_starts, fy.ends_on AS fy_ends FROM {budget_versions} bv
                 JOIN {fiscal_years} fy ON fy.id = bv.fiscal_year_id ORDER BY fy.starts_on DESC, bv.id'
            ) as $v) {
                $key = $v['fy_code'] . ' ' . $v['name'];
                $out[$key] ??= [
                    'key' => $key, 'name' => $v['name'], 'fyCode' => $v['fy_code'], 'fyStarts' => $v['fy_starts'], 'fyEnds' => $v['fy_ends'],
                    'status' => $v['status'], 'isRevision' => $v['based_on_version_id'] !== null, 'ids' => [], 'rows' => [],
                ];
                $out[$key]['ids'][] = (int) $v['id'];
                $out[$key]['rows'][] = $v;
            }

            return array_values(array_map(static fn ($v) => $v + ['label' => self::versionLabel($v)], $out));
        });
    }

    public function version(string $key): ?array
    {
        foreach ($this->versions() as $v) {
            if ($v['key'] === $key) {
                return $v;
            }
        }

        return null;
    }

    /** The approved version of the working year, which the screen opens on. */
    public function defaultVersion(): ?array
    {
        $year = $this->workingYear();
        $approved = null;
        foreach ($this->versions() as $v) {
            if ($year !== null && $v['fyCode'] === $year['code'] && $v['status'] === 'approved') {
                $approved = $v;
            }
        }

        return $approved ?? ($this->versions()[0] ?? null);
    }

    /** "FY2026 Revision 2 (working)". */
    public static function versionLabel(array $v): string
    {
        $suffix = match ($v['status']) {
            'approved'         => ' (approved)',
            'pending_approval' => ' (pending approval)',
            'draft'            => $v['isRevision'] ? ' (working)' : ' (draft)',
            default            => '',
        };

        return $v['fyCode'] . ' ' . $v['name'] . $suffix;
    }

    /** The fiscal year the active entity's books are working in. */
    public function workingYear(): ?array
    {
        $periods = $this->lookups->yearPeriods();
        if ($periods === []) {
            return null;
        }

        return $this->cached('working-year', fn () => $this->row('SELECT * FROM {fiscal_years} WHERE id = ?', [$periods[0]['fiscal_year_id']]));
    }

    /**
     * How many months of a version's year have gone by, and the last of them: all
     * twelve of a past year, none of a future one, and in the working year the
     * months up to and including the current period.
     *
     * @return array{0: int, 1: string|null} [months, "Aug 2026"]
     */
    public function elapsed(array $version): array
    {
        $working = $this->workingYear();
        if ($working === null || $version['fyStarts'] < $working['starts_on']) {
            return [12, date('M Y', strtotime($version['fyEnds']))];
        }
        if ($version['fyStarts'] > $working['starts_on']) {
            return [0, null];
        }
        $n = (new PeriodRepository())->monthsElapsed();
        $current = (new PeriodRepository())->current();

        return [$n, $current === null ? date('M Y', strtotime($version['fyEnds'])) : $current['name']];
    }

    // ------------------------------------------------------------------
    // Lines
    // ------------------------------------------------------------------

    /**
     * A version's lines, one per coding (summed across entities in the consolidated
     * view), each with its twelve monthly budget amounts and actuals.
     *
     * @return list<array>
     */
    public function linesOf(array $version): array
    {
        return $this->cached('lines:' . $version['key'], function () use ($version) {
            $ids = $version['ids'];
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $rows = $this->rows(
                "SELECT v.*, bl.basis, bl.derivation, a.code, a.name AS account_name, f.ledger_group, g.short_name AS grant_short,
                        pp.key AS profile, pp.weights
                 FROM {v_budget_availability} v JOIN {budget_lines} bl ON bl.id = v.budget_line_id
                 JOIN {accounts} a ON a.id = v.account_id JOIN {funds} f ON f.id = v.fund_id
                 LEFT JOIN {grants} g ON g.id = v.grant_id LEFT JOIN {phasing_profiles} pp ON pp.id = bl.phasing_profile_id
                 WHERE v.budget_version_id IN ({$in}) ORDER BY bl.id",
                $ids
            );
            if ($rows === []) {
                return [];
            }

            $phases  = $this->phases(array_map(static fn ($r) => (int) $r['budget_line_id'], $rows), $version['fyStarts']);
            $actuals = $this->monthlyActuals($version);

            $lines = [];
            foreach ($rows as $r) {
                $key = self::lineKey($r);
                $months = $phases[(int) $r['budget_line_id']] ?? self::spread((float) $r['budget'], json_decode((string) $r['weights'], true) ?: array_fill(0, 12, 1));
                if (!isset($lines[$key])) {
                    $lines[$key] = [
                        'key'        => $key,
                        'ids'        => [],
                        'code'       => $r['code'],
                        'name'       => $r['account_name'],
                        'group'      => $r['cost_group'],
                        'fund'       => self::FUND_GROUPS[$r['ledger_group']],
                        'fundGroup'  => $r['ledger_group'],
                        'program'    => $this->lookups->programmeName((int) $r['programme_id']),
                        'grant'      => $r['grant_short'] ?? 'Unassigned',
                        'grantId'    => $r['grant_id'] === null ? null : (int) $r['grant_id'],
                        'accountId'  => (int) $r['account_id'],
                        'profile'    => $r['profile'] ?? 'even',
                        'basis'      => $r['basis'],
                        'derivation' => $r['derivation'],
                        'annual'     => 0.0, 'actual' => 0.0, 'committed' => 0.0,
                        'months'     => array_fill(0, 12, 0.0),
                        'actualMonths' => $actuals[$key] ?? array_fill(0, 12, 0.0),
                    ];
                }
                $lines[$key]['ids'][] = (int) $r['budget_line_id'];
                $lines[$key]['annual'] += (float) $r['budget'];
                $lines[$key]['actual'] += (float) $r['actual'];
                $lines[$key]['committed'] += (float) $r['committed'];
                foreach ($months as $m => $amount) {
                    $lines[$key]['months'][$m] += $amount;
                }
            }

            return array_values($lines);
        });
    }

    /**
     * A line against the phasing to date: phased, the basis it is compared to (the
     * phasing or the full year), variance, how much is consumed, and its status.
     */
    public static function measure(array $line, int $elapsed, bool $fullYear): array
    {
        $annual = $line['annual'];
        $phased = array_sum(array_slice($line['months'], 0, $elapsed));
        $basis  = $fullYear ? $annual : $phased;
        $status = $line['actual'] > $annual ? 'Over'
            : ($line['actual'] > $phased * 1.1 ? 'Watch'
            : ($line['actual'] < $phased * 0.7 ? 'Underspent' : 'On track'));

        return $line + [
            'phased'    => $phased,
            'basisAmt'  => $basis,
            'variance'  => $basis - $line['actual'],
            'remaining' => $annual - $line['actual'],
            'available' => $annual - $line['actual'] - $line['committed'],
            'pct'       => $annual > 0 ? (int) round($line['actual'] / $annual * 100) : 0,
            'basisPct'  => $annual > 0 ? (int) round($basis / $annual * 100) : 0,
            'status'    => $status,
        ];
    }

    /**
     * The approved budget of the working year in the shape the dashboard and the
     * period close have always read, each line measured against its phasing.
     *
     * @return list<array{code: string, group: string, fund: string, program: string, grant: string, orig: int|float, annual: int|float, actual: int|float, profile: string, phased: int|float, status: string}>
     */
    public function lines(): array
    {
        return $this->cached('lines', function () {
            $version = $this->defaultVersion();
            if ($version === null || $version['status'] !== 'approved') {
                return [];
            }
            [$elapsed] = $this->elapsed($version);
            $original = $this->originalAmounts($version);

            return array_map(static function ($l) use ($elapsed, $original) {
                $m = self::measure($l, $elapsed, false);

                return [
                    'code' => $l['code'], 'group' => $l['group'], 'fund' => $l['fund'], 'program' => $l['program'], 'grant' => $l['grant'],
                    'orig' => self::num($original[$l['key']] ?? $l['annual']), 'annual' => self::num($l['annual']), 'actual' => self::num($l['actual']),
                    'profile' => $l['profile'], 'phased' => self::num(round($m['phased'])), 'status' => $m['status'],
                ];
            }, $this->linesOf($version));
        });
    }

    /**
     * What each version of the line's year held for it, oldest first, each against
     * the one before.
     *
     * @return list<array{label: string, amount: string, note: string}>
     */
    public function history(array $version, string $lineKey): array
    {
        $out = [];
        $previous = null;
        foreach (array_filter($this->versions(), static fn ($v) => $v['fyCode'] === $version['fyCode']) as $v) {
            $line = current(array_filter($this->linesOf($v), static fn ($l) => $l['key'] === $lineKey)) ?: null;
            $amount = $line === null ? null : $line['annual'];
            $label = $v['fyCode'] . ' ' . $v['name'] . ($v['status'] === 'draft' || $v['status'] === 'pending_approval'
                ? ($v['isRevision'] ? ' (working)' : ' (draft)') : '');

            $note = match (true) {
                $amount === null                        => 'not on this version',
                in_array($v['status'], ['draft', 'pending_approval'], true) && $v['isRevision']
                                                        => $previous !== null && abs($amount - $previous) >= 0.005
                                                            ? 'pending approval' : 'no change proposed',
                $previous === null                      => $v['rows'][0]['approved_at'] ? 'approved ' . self::dmy($v['rows'][0]['approved_at']) : self::label($v['status']),
                abs($amount - $previous) < 0.005        => 'unchanged',
                default                                 => ($amount > $previous ? '+' : '') . Prototype::fmt($amount - $previous),
            };
            $out[] = ['label' => $label, 'amount' => $amount === null ? '—' : Prototype::fmt($amount), 'note' => $note];
            if ($amount !== null) {
                $previous = $amount;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>> rules by fund group name */
    public function rules(): array
    {
        return $this->cached('rules', function () {
            $out = [];
            foreach ($this->rows('SELECT * FROM {budget_rules} ORDER BY ledger_group, sort_order, id') as $r) {
                $out[self::FUND_GROUPS[$r['ledger_group']]][] = $r['text'];
            }

            return $out;
        });
    }

    /**
     * The reallocations applied to a working revision.
     *
     * @return list<array{from: string, to: string, amount: float, ref: string, reason: string, by: string, status: string}>
     */
    public function reallocations(array $version): array
    {
        $in = implode(',', array_fill(0, count($version['ids']), '?'));

        return array_map(fn ($r) => [
            'from' => $r['from_code'], 'to' => $r['to_code'], 'amount' => (float) $r['amount'], 'ref' => (string) $r['authority_ref'],
            'reason' => $r['reason'], 'by' => $this->lookups->shortName((int) $r['requested_by']), 'status' => self::label($r['status']),
            'fromKey' => self::lineKey(['account_id' => $r['f_account'], 'fund_id' => $r['f_fund'], 'programme_id' => $r['f_programme'], 'grant_id' => $r['f_grant']]),
            'toKey' => self::lineKey(['account_id' => $r['t_account'], 'fund_id' => $r['t_fund'], 'programme_id' => $r['t_programme'], 'grant_id' => $r['t_grant']]),
            'requestedBy' => (int) $r['requested_by'],
        ], $this->rows(
            "SELECT r.*, fa.code AS from_code, ta.code AS to_code,
                    fl.account_id AS f_account, fl.fund_id AS f_fund, fl.programme_id AS f_programme, fl.grant_id AS f_grant,
                    tl.account_id AS t_account, tl.fund_id AS t_fund, tl.programme_id AS t_programme, tl.grant_id AS t_grant
             FROM {budget_reallocations} r
             JOIN {budget_lines} fl ON fl.id = r.from_line_id JOIN {accounts} fa ON fa.id = fl.account_id
             JOIN {budget_lines} tl ON tl.id = r.to_line_id JOIN {accounts} ta ON ta.id = tl.account_id
             WHERE r.budget_version_id IN ({$in}) ORDER BY r.id",
            $version['ids']
        ));
    }

    // ------------------------------------------------------------------
    // Revisions
    // ------------------------------------------------------------------

    /**
     * What a revision is made against for the active entity: the working revision
     * where there is one, else the approved version of the working year; and the
     * approved version, which the funder-consent test measures against.
     *
     * @return array{base: array, approved: array, working: array|null}|null
     */
    public function revisionBase(): ?array
    {
        $year = $this->workingYear();
        if ($year === null) {
            return null;
        }
        $approved = $working = null;
        foreach ($this->versions() as $v) {
            if ($v['fyCode'] !== $year['code']) {
                continue;
            }
            if ($v['status'] === 'approved') {
                $approved = $v;
            }
            if ($v['isRevision'] && in_array($v['status'], ['draft', 'pending_approval'], true)) {
                $working = $v;
            }
        }

        return $approved === null ? null : ['base' => $working ?? $approved, 'approved' => $approved, 'working' => $working];
    }

    /**
     * Why a movement may not be applied, or null when it may. The screen shows the
     * same reasons before anything is sent.
     *
     * @param float $movedOut what earlier movements in the working revision have already taken off the line
     */
    public static function revisionBlock(?array $from, ?array $to, float $amount, float $approvedAnnual, float $movedOut): ?string
    {
        if ($from === null || $to === null) {
            return 'Choose the line to reduce and the line to increase.';
        }
        if ($from['key'] === $to['key']) {
            return 'Choose two different budget lines.';
        }
        if ($from['fund'] !== $to['fund']) {
            return 'A revision must net to zero within one fund. ' . $from['fund'] . ' and ' . $to['fund']
                . ' are different funds — process this as an inter-fund transfer instead.';
        }
        if ($from['grant'] !== $to['grant']) {
            return 'These lines sit under different agreements (' . $from['grant'] . ' and ' . $to['grant'] . '). Budget cannot move between grants.';
        }
        if ($to['fundGroup'] === 'grant' && str_starts_with($to['code'], GrantRepository::INDIRECT_PREFIX)) {
            return 'Indirect cost recovery cannot be increased by revision. ' . $to['code'] . ' ' . $to['name'] . ' is a support-cost line.';
        }
        if ($amount > round($from['available'], 2)) {
            return 'Only ' . Prototype::fmt($from['available']) . ' is uncommitted on ' . $from['code'] . ' ' . $from['name'] . '.';
        }
        if ($from['fundGroup'] === 'grant' && $movedOut + $amount > $approvedAnnual * self::FUNDER_CONSENT_SHARE + 0.005) {
            return 'This takes more than 10% of ' . $from['code'] . ' (' . Prototype::fmt(round($approvedAnnual * self::FUNDER_CONSENT_SHARE))
                . ') off the line' . ($movedOut > 0 ? ', counting the ' . Prototype::fmt($movedOut) . ' already moved in this revision' : '')
                . '. Written consent from the funder is required — attach the amendment before applying.';
        }

        return null;
    }

    /**
     * Applies a movement to the working revision, opening one from the approved
     * version if there is none.
     *
     * @return array{version: string, from: string, to: string}
     */
    public function reallocate(string $fromKey, string $toKey, float $amount, string $ref, string $reason, int $actorId): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new RuleViolation('Enter an amount to reallocate.');
        }
        if (trim($ref) === '') {
            throw new RuleViolation('An authority reference is required for a budget revision.');
        }
        if (trim($reason) === '') {
            throw new RuleViolation('Say why the reallocation is needed and how it stays within the funding agreement.');
        }
        $revision = $this->revisionBase() ?? throw new RuleViolation('There is no approved budget for the working year to revise.');
        if ($revision['working'] !== null && $revision['working']['status'] === 'pending_approval') {
            throw new RuleViolation($revision['working']['label'] . ' is awaiting approval. It has to be approved or sent back before another movement is applied.');
        }

        $entityId = $this->lookups->entityId();
        [$elapsed] = $this->elapsed($revision['base']);
        $lines = [];
        foreach ($this->linesOf($revision['base']) as $l) {
            $lines[$l['key']] = self::measure($l, $elapsed, false);
        }
        $approved = array_column($this->linesOf($revision['approved']), 'annual', 'key');
        $movedOut = $revision['working'] === null ? 0.0 : array_sum(array_map(
            static fn ($r) => $r['fromKey'] === $fromKey ? $r['amount'] : 0.0,
            $this->reallocations($revision['working'])
        ));

        $from = $lines[$fromKey] ?? null;
        $to   = $lines[$toKey] ?? null;
        if (($block = self::revisionBlock($from, $to, $amount, (float) ($approved[$fromKey] ?? 0), $movedOut)) !== null) {
            throw new RuleViolation($block);
        }

        return $this->transaction(function () use ($revision, $entityId, $from, $to, $amount, $ref, $reason, $actorId) {
            $versionId = $revision['working'] === null
                ? $this->openRevision($revision['approved'], $entityId, $actorId)
                : $revision['working']['ids'][0];
            $fromLine = $this->lineByKey($versionId, $from['key']);
            $toLine   = $this->lineByKey($versionId, $to['key']);

            $this->setAmount($fromLine, (float) $fromLine['annual_amount'] - $amount);
            $this->setAmount($toLine, (float) $toLine['annual_amount'] + $amount);
            $this->insert('budget_reallocations', [
                'budget_version_id' => $versionId, 'from_line_id' => $fromLine['id'], 'to_line_id' => $toLine['id'],
                'amount' => $amount, 'reason' => trim($reason), 'authority_ref' => trim($ref), 'status' => 'pending_approval',
                'requested_by' => $actorId, 'created_at' => Clock::timestamp(),
            ]);
            $name = $this->value('SELECT name FROM {budget_versions} WHERE id = ?', [$versionId]);
            $this->audit('budget_version', $versionId, $name, Prototype::fmt($amount) . ' moved from ' . $from['code'] . ' to ' . $to['code'] . ' under ' . trim($ref), $actorId, 'history', $entityId);

            return ['version' => $this->yearCode($versionId) . ' ' . $name, 'from' => $from['code'], 'to' => $to['code']];
        });
    }

    /** Sends a draft for approval. */
    public function submit(string $key, int $actorId): array
    {
        $v = $this->own($key);
        if ($v['status'] !== 'draft') {
            throw new RuleViolation($this->labelOf($v) . ' is not a draft, so there is nothing to submit.');
        }
        if ($v['based_on_version_id'] !== null && $this->reallocations($this->version($key)) === []) {
            throw new RuleViolation('Nothing has been moved in ' . $this->labelOf($v) . ' yet.');
        }

        $this->transaction(function () use ($v, $actorId) {
            $this->db->table('budget_versions')->where('id', $v['id'])->update([
                'status' => 'pending_approval', 'submitted_by' => $actorId, 'submitted_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
            ]);
            $this->audit('budget_version', (int) $v['id'], $v['name'], 'Submitted for approval', $actorId, 'history', (int) $v['entity_id']);
        });

        return $this->version($key) ?? [];
    }

    /**
     * Who may approve a version, as a refusal or null. A revision goes by the
     * budget revision approval rule on the total moved; a year's budget goes to
     * whoever authorises the year's books. Nobody approves what they prepared,
     * submitted or moved.
     */
    public function approvalRefusal(array $version, int $actorId, bool $mayAuthorise, ?string $authorityRef = null): ?array
    {
        $v = $version['rows'][0];
        $movers = array_column($this->reallocations($version), 'requestedBy');
        if (in_array($actorId, array_filter([(int) $v['prepared_by'], (int) $v['submitted_by'], ...$movers]), true)) {
            return ['message' => 'The person who prepared this cannot also approve it. It needs a second approver.', 'needsAuthority' => false];
        }
        if ($version['isRevision']) {
            return (new ApprovalPolicy())->refusal('budget_revision', $this->moved($version), $actorId, $version['label'],
                $authorityRef, 'budget_version', (int) $v['id']);
        }
        if (!$mayAuthorise) {
            return ['message' => "A year's budget is approved by the Executive Director, who authorises the year's books.", 'needsAuthority' => false];
        }

        return null;
    }

    /** The total moved by a revision's reallocations. */
    public function moved(array $version): float
    {
        return array_sum(array_column($this->reallocations($version), 'amount'));
    }

    /** Approves a version, which supersedes the approved version of its year. */
    public function approve(string $key, int $actorId, bool $mayAuthorise, ?string $authorityRef): array
    {
        $v = $this->own($key);
        if ($v['status'] !== 'pending_approval') {
            throw new RuleViolation($this->labelOf($v) . ' has not been submitted for approval.');
        }
        $version = $this->version($key);
        if (($refusal = $this->approvalRefusal($version, $actorId, $mayAuthorise, $authorityRef)) !== null) {
            throw $refusal['needsAuthority'] ? new AuthorityRequired($refusal['message']) : new RuleViolation($refusal['message']);
        }

        // A revision climbs the budget revision ladder; a year's budget is authorised
        // by whoever authorises the year's books, which is not a ladder at all.
        $policy = new ApprovalPolicy();
        $moved  = $version['isRevision'] ? $this->moved($version) : 0.0;

        $this->transaction(function () use ($v, $version, $actorId, $authorityRef, $policy, $moved) {
            $id = (int) $v['id'];
            if ($version['isRevision']) {
                $step = $policy->progress('budget_version', $id, 'budget_revision', $moved)['open'];
                if (!$policy->sign('budget_version', $id, $v['name'], 'budget_revision', $moved, $actorId, $authorityRef, '', (int) $v['entity_id'])) {
                    // Signed, but the ladder is not finished: the revision stays
                    // submitted and supersedes nothing until the last role signs.
                    $this->audit('budget_version', $id, $v['name'], ($step['label'] ?? ApprovalPolicy::DEFAULT_LABEL) . ' by '
                        . $this->lookups->shortName($actorId) . $policy->awaitingNote('budget_version', $id, 'budget_revision', $moved),
                        $actorId, 'history', (int) $v['entity_id']);

                    return;
                }
            }

            $now = Clock::timestamp();
            $this->db->table('budget_versions')
                ->where(['entity_id' => $v['entity_id'], 'fiscal_year_id' => $v['fiscal_year_id'], 'status' => 'approved'])
                ->update(['status' => 'superseded', 'updated_at' => $now]);
            $this->db->table('budget_versions')->where('id', $v['id'])->update([
                'status' => 'approved', 'approved_by' => $actorId, 'approved_at' => $now, 'returned_note' => null, 'updated_at' => $now,
            ]);
            $this->db->table('budget_reallocations')->where('budget_version_id', $v['id'])
                ->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => $now, 'updated_at' => $now]);
            $this->audit('budget_version', (int) $v['id'], $v['name'], 'Approved' . (trim((string) $authorityRef) !== '' ? ' under ' . trim($authorityRef) : ''), $actorId, 'history', (int) $v['entity_id']);
        });

        return $this->version($key) ?? [];
    }

    /** Sends a submitted version back to its preparer as a draft, with the reason. */
    public function sendBack(string $key, int $actorId, bool $mayAuthorise, string $note): array
    {
        $v = $this->own($key);
        if ($v['status'] !== 'pending_approval') {
            throw new RuleViolation($this->labelOf($v) . ' has not been submitted for approval.');
        }
        if (trim($note) === '') {
            throw new RuleViolation('Say why it is being sent back, so the preparer knows what to change.');
        }
        $version = $this->version($key);
        $refusal = $this->approvalRefusal($version, $actorId, $mayAuthorise, 'n/a');
        if ($refusal !== null) {
            throw new RuleViolation($refusal['message']);
        }

        $this->transaction(function () use ($v, $version, $actorId, $note) {
            $this->db->table('budget_versions')->where('id', $v['id'])->update([
                'status' => 'draft', 'returned_note' => trim($note), 'submitted_by' => null, 'submitted_at' => null, 'updated_at' => Clock::timestamp(),
            ]);
            if ($version['isRevision']) {
                (new ApprovalPolicy())->returnToPreparer('budget_version', (int) $v['id'], $v['name'], 'budget_revision',
                    $this->moved($version), $actorId, trim($note), (int) $v['entity_id']);
            }
            $this->audit('budget_version', (int) $v['id'], $v['name'], 'Sent back: ' . trim($note), $actorId, 'history', (int) $v['entity_id']);
        });

        return $this->version($key) ?? [];
    }

    /** Discards a draft and everything applied to it. Approved versions are never touched. */
    public function discard(string $key, int $actorId): string
    {
        $v = $this->own($key);
        if ($v['status'] !== 'draft') {
            throw new RuleViolation('Only a draft can be discarded. ' . $this->labelOf($v) . ' is ' . strtolower(self::label($v['status'])) . '.');
        }
        $label = $this->labelOf($v);

        $this->transaction(function () use ($v, $actorId) {
            $lines = array_column($this->rows('SELECT id FROM {budget_lines} WHERE budget_version_id = ?', [$v['id']]), 'id');
            $this->db->table('budget_reallocations')->where('budget_version_id', $v['id'])->delete();
            if ($lines !== []) {
                $this->db->table('budget_phases')->whereIn('budget_line_id', $lines)->delete();
            }
            $this->db->table('budget_lines')->where('budget_version_id', $v['id'])->delete();
            $this->db->table('budget_versions')->where('id', $v['id'])->delete();
            $this->audit('budget_version', (int) $v['id'], $v['name'], 'Discarded', $actorId, 'history', (int) $v['entity_id']);
        });

        return $label;
    }

    // ------------------------------------------------------------------
    // A new financial year
    // ------------------------------------------------------------------

    /** The years a budget can be raised for: the two after the working year. */
    public function nextYears(): array
    {
        $working = $this->workingYear();
        if ($working === null) {
            return [];
        }
        $end = (int) substr($working['ends_on'], 0, 4);

        return [$end + 1, $end + 2];
    }

    /**
     * The budget a year would get: grant-funded lines from what their award still
     * allows in the year, core lines from the approved budget plus the uplift. Two
     * separate funding tests follow, one per side of the budget — restricted and
     * unrestricted money are not interchangeable.
     */
    public function derive(int $year, int $upliftPct, bool $keepEmpty): array
    {
        if (!in_array($year, $this->nextYears(), true)) {
            throw new RuleViolation('A budget can be raised for FY' . implode(' or FY', $this->nextYears()) . '.');
        }
        $upliftPct = max(0, min(15, $upliftPct));
        $source = $this->defaultVersion();
        if ($source === null || $source['status'] !== 'approved') {
            throw new RuleViolation('There is no approved budget for the working year to roll forward.');
        }
        $working = $this->workingYear();
        $shift   = $year - (int) substr($working['ends_on'], 0, 4);
        $yearStart = date('Y-m-d', strtotime($working['starts_on'] . " +{$shift} years"));
        $yearEnd   = date('Y-m-d', strtotime($working['ends_on'] . " +{$shift} years"));
        $yearPeriods = $this->lookups->yearPeriods();
        $current   = (new PeriodRepository())->current() ?? end($yearPeriods);
        $from      = date('Y-m-01', strtotime($current['starts_on'] . ' +1 month'));

        $awards = $this->awards();
        $sourceLines = $this->linesOf($source);
        // Where two budget lines draw on the same award line, its ceiling is shared
        // between them in proportion to their current budgets.
        $claims = [];
        foreach ($sourceLines as $l) {
            if ($l['grantId'] !== null && isset($awards[$l['grantId']]['lines'][$l['code']])) {
                $claims[$l['grantId'] . ':' . $l['code']] = ($claims[$l['grantId'] . ':' . $l['code']] ?? 0) + $l['annual'];
            }
        }

        $rows = [];
        foreach ($sourceLines as $l) {
            $award = $l['grantId'] === null ? null : ($awards[$l['grantId']] ?? null);
            $ceilingLine = $award['lines'][$l['code']] ?? null;
            if ($award !== null && $ceilingLine !== null && $award['ends'] !== null) {
                $share = ($claims[$l['grantId'] . ':' . $l['code']] ?? 0) > 0 ? $l['annual'] / $claims[$l['grantId'] . ':' . $l['code']] : 1;
                $ceiling   = $ceilingLine['budget'] * $share;
                $spent     = $ceilingLine['actual'] * $share;
                $remaining = max(0, $ceiling - $spent);
                $monthsLeft = self::months($from, $award['ends']);
                $inYear = $award['ends'] < $yearStart ? 0 : self::months(max($yearStart, $from), min($award['ends'], $yearEnd));
                $amount = $monthsLeft > 0 ? round($remaining * min(1, $inYear / $monthsLeft), -3) : 0.0;
                $rows[] = $l + [
                    'prior' => $l['annual'], 'amount' => $amount, 'basisKind' => 'Award', 'source' => $award['ref'],
                    'ceiling' => $ceiling, 'spentToDate' => $spent, 'zero' => $amount == 0,
                    'note' => $amount == 0
                        ? ($award['ends'] < $yearStart ? 'Award closes ' . self::dmy($award['ends']) . ' — nothing falls in FY' . $year : 'No ceiling remains on this line')
                        : Prototype::fmt($remaining) . ' of ceiling remains · ' . $inYear . ' of ' . $monthsLeft . ' remaining months fall in FY' . $year,
                ];
                continue;
            }
            $amount = round($l['annual'] * (1 + $upliftPct / 100), -3);
            $rows[] = $l + [
                'prior' => $l['annual'], 'amount' => $amount, 'basisKind' => 'Core', 'source' => $source['label'],
                'ceiling' => 0.0, 'spentToDate' => 0.0, 'zero' => $amount == 0,
                'note' => $upliftPct === 0 ? 'Held at the ' . $source['fyCode'] . ' approved figure' : $source['fyCode'] . ' approved plus ' . $upliftPct . '%',
            ];
        }

        $kept = array_values(array_filter($rows, static fn ($r) => !$r['zero'] || $keepEmpty));
        $sum = static fn (array $rs, string $kind) => array_sum(array_map(static fn ($r) => $r['basisKind'] === $kind ? $r['amount'] : 0, $rs));
        $grantTotal = $sum($kept, 'Award');
        $coreTotal  = $sum($kept, 'Core');
        $awardIncome = array_sum(array_map(static fn ($a) => $a['status'] === 'closed' ? 0 : max(0, $a['value'] - $a['received']), $awards));
        [$coreIncome, $reserves] = $this->unrestricted((int) $working['id']);

        return [
            'year' => $year, 'uplift' => $upliftPct, 'keepEmpty' => $keepEmpty, 'source' => $source,
            'rows' => $kept, 'all' => $rows,
            'grantTotal' => $grantTotal, 'coreTotal' => $coreTotal, 'priorTotal' => array_sum(array_column($sourceLines, 'annual')),
            'dropped' => count(array_filter($rows, static fn ($r) => $r['zero'])),
            'breaches' => array_values(array_filter($kept, static fn ($r) => $r['basisKind'] === 'Award' && $r['spentToDate'] + $r['amount'] > $r['ceiling'] + 0.5)),
            'awardIncome' => $awardIncome, 'coreIncome' => $coreIncome, 'reserves' => $reserves,
            'existing' => count(array_filter($this->versions(), static fn ($v) => $v['fyCode'] === 'FY' . $year)),
            'starts' => $yearStart, 'ends' => $yearEnd,
        ];
    }

    /** Raises the derived budget as a draft for the year, opening the year if the entity has not got it. */
    public function raiseYear(int $year, int $upliftPct, bool $keepEmpty, int $actorId): array
    {
        $d = $this->derive($year, $upliftPct, $keepEmpty);
        if ($d['rows'] === []) {
            throw new RuleViolation('Nothing would be budgeted for FY' . $year . '. Keep the lines with nothing left, or record the awards first.');
        }
        $entityId = $this->lookups->entityId();

        return $this->transaction(function () use ($d, $year, $entityId, $actorId) {
            $now  = Clock::timestamp();
            $code = 'FY' . $year;
            $fyId = $this->value('SELECT id FROM {fiscal_years} WHERE entity_id = ? AND code = ?', [$entityId, $code])
                ?? $this->insert('fiscal_years', ['entity_id' => $entityId, 'code' => $code, 'starts_on' => $d['starts'], 'ends_on' => $d['ends'],
                    'status' => 'open', 'created_at' => $now]);
            $taken = array_column($this->rows('SELECT name FROM {budget_versions} WHERE entity_id = ? AND fiscal_year_id = ?', [$entityId, $fyId]), 'name');
            $name = 'Original';
            for ($n = 2; in_array($name, $taken, true); $n++) {
                $name = 'Original ' . $n;
            }

            $versionId = $this->insert('budget_versions', [
                'entity_id' => $entityId, 'fiscal_year_id' => $fyId, 'name' => $name, 'status' => 'draft', 'prepared_by' => $actorId,
                'assumptions' => json_encode(['upliftPct' => $d['uplift'], 'keepEmpty' => $d['keepEmpty'], 'from' => $d['source']['label']]),
                'created_at' => $now,
            ]);
            $profiles = array_column($this->rows('SELECT pp.id, pp.key FROM {phasing_profiles} pp'), 'id', 'key');
            foreach ($d['rows'] as $r) {
                [$accountId, $fundId, $programmeId, $grantId] = explode(':', $r['key']);
                $this->insert('budget_lines', [
                    'budget_version_id' => $versionId, 'account_id' => $accountId, 'fund_id' => $fundId, 'programme_id' => $programmeId,
                    'grant_id' => $grantId === '' ? null : $grantId, 'cost_group' => $r['group'], 'annual_amount' => $r['amount'],
                    'phasing_profile_id' => $profiles[$r['profile']] ?? null, 'basis' => strtolower($r['basisKind']),
                    'derivation' => mb_substr($r['note'], 0, 255), 'created_at' => $now,
                ]);
            }
            $this->audit('budget_version', $versionId, $name, 'FY' . $year . ' ' . $name . ' raised from ' . count($d['rows']) . ' lines · awaiting Executive Director approval', $actorId, 'history', $entityId);

            return ['version' => $code . ' ' . $name, 'label' => $code . ' ' . $name . ' (draft)', 'lines' => count($d['rows'])];
        });
    }

    // ------------------------------------------------------------------

    /** "account:fund:programme:grant" — how a line is known across versions. */
    public static function lineKey(array $r): string
    {
        return $r['account_id'] . ':' . $r['fund_id'] . ':' . $r['programme_id'] . ':' . ($r['grant_id'] ?? '');
    }

    /** Twelve monthly amounts in proportion to the weights; the last month takes the rounding. */
    public static function spread(float $annual, array $weights): array
    {
        $total = array_sum($weights) ?: 1;
        $out = [];
        $run = 0.0;
        foreach (array_values($weights) as $m => $w) {
            $out[$m] = $m === count($weights) - 1 ? round($annual - $run, 2) : round($annual * $w / $total, 2);
            $run += $out[$m];
        }

        return $out;
    }

    /** Months from the first date's month to the second's, both included. */
    private static function months(string $from, string $to): int
    {
        $a = (int) substr($from, 0, 4) * 12 + (int) substr($from, 5, 2);
        $b = (int) substr($to, 0, 4) * 12 + (int) substr($to, 5, 2);

        return max(0, $b - $a + 1);
    }

    /** @return array<int, list<float>> each line's twelve months, from budget_phases */
    private function phases(array $lineIds, string $yearStart): array
    {
        $out = [];
        $in = implode(',', array_fill(0, count($lineIds), '?'));
        foreach ($this->rows(
            "SELECT bp.budget_line_id, bp.amount, p.starts_on FROM {budget_phases} bp JOIN {periods} p ON p.id = bp.period_id
             WHERE bp.budget_line_id IN ({$in})",
            $lineIds
        ) as $r) {
            $m = self::months($yearStart, $r['starts_on']) - 1;
            if ($m >= 0 && $m < 12) {
                $out[(int) $r['budget_line_id']] ??= array_fill(0, 12, 0.0);
                $out[(int) $r['budget_line_id']][$m] += (float) $r['amount'];
            }
        }

        return $out;
    }

    /** @return array<string, list<float>> actual spend by line key and month of the version's year */
    private function monthlyActuals(array $version): array
    {
        $fys = array_map(static fn ($r) => (int) $r['fiscal_year_id'], $version['rows']);
        $in = implode(',', array_fill(0, count($fys), '?'));
        $out = [];
        foreach ($this->rows(
            "SELECT l.account_id, l.fund_id, l.programme_id, l.grant_id, p.starts_on, SUM(l.debit - l.credit) AS amount
             FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {periods} p ON p.id = j.period_id
             WHERE j.status IN ('posted', 'reversed') AND p.fiscal_year_id IN ({$in})
             GROUP BY l.account_id, l.fund_id, l.programme_id, l.grant_id, p.starts_on",
            $fys
        ) as $r) {
            $m = self::months($version['fyStarts'], $r['starts_on']) - 1;
            if ($m >= 0 && $m < 12) {
                $key = self::lineKey($r);
                $out[$key] ??= array_fill(0, 12, 0.0);
                $out[$key][$m] += (float) $r['amount'];
            }
        }

        return $out;
    }

    /** @return array<string, float> each line's amount in the version the year started from */
    private function originalAmounts(array $version): array
    {
        $original = current(array_filter($this->versions(), static fn ($v) => $v['fyCode'] === $version['fyCode'] && !$v['isRevision'])) ?: null;

        return $original === null ? [] : array_column($this->linesOf($original), 'annual', 'key');
    }

    /**
     * The awards a year's budget is derived from: each one's end, value, what has
     * been received, and its budget lines with what has been spent against each.
     *
     * @return array<int, array{ref: string, status: string, ends: string|null, value: float, received: float, lines: array<string, array{budget: float, actual: float}>}>
     */
    private function awards(): array
    {
        $ends = array_column($this->rows('SELECT id, ends_on, status FROM {grants}'), null, 'id');
        $out = [];
        foreach ((new GrantRepository())->all() as $g) {
            $lines = [];
            foreach ($g['budget'] as $b) {
                $lines[$b['code']] = ['budget' => (float) $b['budget'], 'actual' => (float) $b['actual']];
            }
            $out[$g['id']] = [
                'ref' => $g['ref'], 'status' => $ends[$g['id']]['status'] ?? strtolower($g['status']), 'ends' => $ends[$g['id']]['ends_on'] ?? null,
                'value' => (float) $g['value'], 'received' => (float) $g['received'], 'lines' => $lines,
            ];
        }

        return $out;
    }

    /**
     * Unrestricted income taken so far in the working year, and the unrestricted
     * reserves held — what core spend is met from.
     *
     * @return array{0: float, 1: float}
     */
    private function unrestricted(int $fiscalYearId): array
    {
        $income = (float) $this->value(
            "SELECT SUM(l.credit - l.debit) FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id JOIN {periods} p ON p.id = j.period_id
             JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
             WHERE j.status IN ('posted', 'reversed') AND a.type = 'income' AND f.restriction = 'unrestricted' AND p.fiscal_year_id = ?",
            [$fiscalYearId]
        );
        $reserves = (float) $this->value(
            "SELECT SUM(l.credit - l.debit) FROM {journal_lines} l JOIN {journals} j ON j.id = l.journal_id
             JOIN {accounts} a ON a.id = l.account_id JOIN {funds} f ON f.id = l.fund_id
             WHERE j.status IN ('posted', 'reversed') AND a.type IN ('equity', 'income', 'expense') AND f.restriction = 'unrestricted'"
        );

        return [$income, $reserves];
    }

    /** The active entity's own row of a version, which is what a change is made to. */
    private function own(string $key): array
    {
        $version = $this->version($key) ?? throw new RuleViolation($key . ' was not found.');
        $entityId = $this->lookups->entityId();
        foreach ($version['rows'] as $row) {
            if ((int) $row['entity_id'] === $entityId) {
                return $row;
            }
        }

        throw new RuleViolation($key . ' was not found.');
    }

    private function labelOf(array $row): string
    {
        return $this->version($this->yearCode((int) $row['id']) . ' ' . $row['name'])['label'] ?? $row['name'];
    }

    private function yearCode(int $versionId): string
    {
        return (string) $this->value('SELECT fy.code FROM {budget_versions} bv JOIN {fiscal_years} fy ON fy.id = bv.fiscal_year_id WHERE bv.id = ?', [$versionId]);
    }

    /** Copies the approved version into a new working revision, lines and phasing with it. */
    private function openRevision(array $approved, int $entityId, int $actorId): int
    {
        $source = current(array_filter($approved['rows'], static fn ($r) => (int) $r['entity_id'] === $entityId));
        $names  = array_column($this->rows('SELECT name FROM {budget_versions} WHERE entity_id = ? AND fiscal_year_id = ?', [$entityId, $source['fiscal_year_id']]), 'name');
        $n = 1 + count(array_filter($names, static fn ($name) => str_starts_with($name, 'Revision ')));
        while (in_array('Revision ' . $n, $names, true)) {
            $n++;
        }
        $now = Clock::timestamp();
        $id = $this->insert('budget_versions', [
            'entity_id' => $entityId, 'fiscal_year_id' => $source['fiscal_year_id'], 'name' => 'Revision ' . $n,
            'based_on_version_id' => $source['id'], 'status' => 'draft', 'prepared_by' => $actorId, 'created_at' => $now,
        ]);
        foreach ($this->rows('SELECT * FROM {budget_lines} WHERE budget_version_id = ? ORDER BY id', [$source['id']]) as $l) {
            $lineId = $this->insert('budget_lines', [
                'budget_version_id' => $id, 'account_id' => $l['account_id'], 'fund_id' => $l['fund_id'], 'programme_id' => $l['programme_id'],
                'grant_id' => $l['grant_id'], 'cost_group' => $l['cost_group'], 'annual_amount' => $l['annual_amount'],
                'phasing_profile_id' => $l['phasing_profile_id'], 'basis' => $l['basis'], 'derivation' => $l['derivation'], 'created_at' => $now,
            ]);
            foreach ($this->rows('SELECT period_id, amount FROM {budget_phases} WHERE budget_line_id = ?', [$l['id']]) as $p) {
                $this->insert('budget_phases', ['budget_line_id' => $lineId, 'period_id' => $p['period_id'], 'amount' => $p['amount']]);
            }
        }
        $this->audit('budget_version', $id, 'Revision ' . $n, 'Revision ' . $n . ' opened from ' . $source['name'], $actorId, 'history', $entityId);

        return $id;
    }

    private function lineByKey(int $versionId, string $key): array
    {
        [$account, $fund, $programme, $grant] = explode(':', $key);

        return $this->row(
            'SELECT * FROM {budget_lines} WHERE budget_version_id = ? AND account_id = ? AND fund_id = ? AND programme_id = ? AND '
            . ($grant === '' ? 'grant_id IS NULL' : 'grant_id = ?'),
            array_merge([$versionId, $account, $fund, $programme], $grant === '' ? [] : [$grant])
        ) ?? throw new RuleViolation('That budget line is not on the working revision.');
    }

    /** Sets a line's annual amount and re-phases it by its profile over the months its year has. */
    private function setAmount(array $line, float $amount): void
    {
        $now = Clock::timestamp();
        $this->db->table('budget_lines')->where('id', $line['id'])->update(['annual_amount' => round($amount, 2), 'updated_at' => $now]);

        $periods = $this->rows(
            'SELECT p.id FROM {periods} p JOIN {budget_versions} bv ON bv.fiscal_year_id = p.fiscal_year_id
             JOIN {budget_lines} bl ON bl.budget_version_id = bv.id WHERE bl.id = ? ORDER BY p.starts_on',
            [$line['id']]
        );
        if (count($periods) !== 12) {
            return;
        }
        $weights = json_decode((string) $this->value('SELECT weights FROM {phasing_profiles} WHERE id = ?', [$line['phasing_profile_id']]), true)
            ?: array_fill(0, 12, 1);
        $this->db->table('budget_phases')->where('budget_line_id', $line['id'])->delete();
        foreach (self::spread(round($amount, 2), $weights) as $m => $value) {
            $this->insert('budget_phases', ['budget_line_id' => $line['id'], 'period_id' => $periods[$m]['id'], 'amount' => $value]);
        }
    }
}
