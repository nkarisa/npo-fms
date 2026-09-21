<?php

namespace App\Controllers\Api;

use App\Libraries\Prototype;
use App\Repositories\GrantRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Grants and awards (v5): the award portfolio with burn read against elapsed
 * time, one award's budget, disbursements, reporting calendar and conditions, the
 * portfolio's reporting calendar, recording an award from its signed agreement,
 * and converting a pipeline award on signature.
 */
class Grants extends BaseApiController
{
    private const STATUSES = ['All', 'Active', 'Closing', 'Pipeline', 'Suspended', 'Closed'];

    public static function burn(array $g): int
    {
        return $g['value'] > 0 ? (int) min(100, round($g['spent'] / $g['value'] * 100)) : 0;
    }

    /** Burn colour: rust when spending runs ahead of time, amber when it lags well behind. */
    public static function burnColour(int $burn, int $elapsed): string
    {
        return $burn > $elapsed + 8 ? '#A45B3E' : ($burn < $elapsed - 15 ? '#B98A3C' : '#1FA37E');
    }

    public function index()
    {
        $repo   = new GrantRepository();
        $all    = $repo->all();
        $status = in_array($this->request->getGet('status'), self::STATUSES, true) ? $this->request->getGet('status') : 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($g) use ($status, $q) {
            if ($status !== 'All' && $g['status'] !== $status) {
                return false;
            }

            return $q === '' || str_contains(strtolower($g['ref'] . ' ' . $g['title'] . ' ' . $g['funder'] . ' ' . $g['program'] . ' ' . implode(' ', $g['alsoPrograms'])), $q);
        }));

        $rows = array_map(fn ($g) => [
            'ref' => $g['ref'], 'title' => $g['title'], 'funder' => $g['funder'], 'program' => $g['program'],
            'fund' => $g['fund'], 'period' => $g['period'], 'status' => $g['status'],
            'value' => Prototype::fmt($g['value']), 'received' => Prototype::fmt($g['received']), 'spent' => Prototype::fmt($g['spent']),
            'burnPct' => self::burn($g), 'elapsed' => $g['elapsed'], 'burnColour' => self::burnColour(self::burn($g), $g['elapsed']),
            'reportDue' => $g['reportDays'] <= SettingsRepository::day('reportWarningDays'),
            'nextReport' => $g['nextReport'],
        ], $filtered);

        $live = array_values(array_filter($all, fn ($g) => in_array($g['status'], ['Active', 'Closing'], true)));
        $reportsSoon = array_values(array_filter($all, fn ($g) => $g['reportDays'] <= SettingsRepository::day('reportWarningDays') && !in_array($g['status'], ['Closed', 'Pipeline'], true)));
        $sum = static fn (array $grants, callable $of) => array_sum(array_map($of, $grants));
        $liveValue = $sum($live, fn ($g) => $g['value']);

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($s) => [
                'key' => $s, 'label' => $s . ' (' . ($s === 'All' ? count($all) : count(array_filter($all, fn ($g) => $g['status'] === $s))) . ')',
            ], self::STATUSES),
            'stats' => [
                ['label' => 'Live portfolio', 'value' => Prototype::fmt($liveValue), 'note' => count($live) . ' active and closing awards'],
                ['label' => 'Received to date', 'value' => Prototype::fmt($sum($live, fn ($g) => $g['received'])), 'note' => Prototype::fmt($sum($live, fn ($g) => $g['value'] - $g['received'])) . ' still receivable'],
                ['label' => 'Spent to date', 'value' => Prototype::fmt($sum($live, fn ($g) => $g['spent'])), 'note' => 'against elapsed time'],
                ['label' => 'Unspent commitment', 'value' => Prototype::fmt($sum($live, fn ($g) => $g['value'] - $g['spent'])), 'note' => 'to deliver before close'],
                ['label' => 'Reports due', 'value' => (string) count($reportsSoon), 'note' => 'within ' . SettingsRepository::day('reportWarningDays') . ' days'],
            ],
            'footer' => count($rows) . ' of ' . count($all) . ' awards · ' . count($live) . ' live, portfolio burn '
                . (int) round($sum($live, fn ($g) => $g['spent']) / max(1, $liveValue) * 100) . '%',
            'canRecord' => $this->canManage(),
        ]);
    }

    /** One award for the drawer. The reference arrives split at its slashes. */
    public function show(string ...$ref)
    {
        $ref = implode('/', $ref);
        $g = (new GrantRepository())->find($ref);
        if ($g === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $ref . ' is not an award.']);
        }

        $burn = self::burn($g);
        $unspent = $g['value'] - $g['spent'];
        $overdue = array_values(array_filter($g['reports'], fn ($r) => $r['state'] === 'Overdue'));
        $budgetTotal = array_sum(array_column($g['budget'], 'budget'));
        $actualTotal = array_sum(array_column($g['budget'], 'actual'));

        return $this->json([
            'ref' => $g['ref'], 'title' => $g['title'], 'funder' => $g['funder'], 'manager' => $g['manager'], 'status' => $g['status'],
            'program' => $g['alsoPrograms'] ? $g['program'] . ' and ' . implode(', ', $g['alsoPrograms']) : $g['program'],
            'fund' => $g['fund'], 'fundCode' => $g['fundCode'], 'period' => $g['start'] . ' – ' . $g['end'],
            'value' => Prototype::fmt($g['value']), 'received' => Prototype::fmt($g['received']), 'unspent' => Prototype::fmt($unspent),
            'currencyNote' => $g['currency'] !== 'KES' ? $g['currency'] . ' ' . Prototype::fmt($g['valueFc']) . ' at the agreement rate of '
                . number_format($g['value'] / max(0.0001, $g['valueFc']), 2) . ' to the shilling' : '',
            'indirectCap' => $g['indirectCap'] === null ? '' : rtrim(rtrim(number_format($g['indirectCap'], 2), '0'), '.') . '% of the award, '
                . Prototype::fmt(round($g['value'] * $g['indirectCap'] / 100)),
            'retention' => $g['retention'] . ' years after close',
            'burnPct' => $burn, 'elapsed' => $g['elapsed'], 'burnColour' => self::burnColour($burn, $g['elapsed']),
            'burnNote' => match (true) {
                $g['status'] === 'Pipeline'        => 'No spending until the agreement is signed.',
                $burn > $g['elapsed'] + 8          => 'Spending is running ahead of the period by ' . ($burn - $g['elapsed']) . ' points — check that costs are eligible and budget lines still have room.',
                $burn < $g['elapsed'] - 15         => 'Spending lags the period by ' . ($g['elapsed'] - $burn) . ' points. At this rate ' . Prototype::fmt($unspent) . ' would be unspent at close.',
                default                            => 'Spending is broadly in line with elapsed time.',
            },
            'budget' => array_map(static fn ($b) => [
                'code' => $b['code'], 'name' => $b['name'], 'budget' => Prototype::fmt($b['budget']), 'actual' => Prototype::fmt($b['actual']),
                'variance' => Prototype::fmt($b['budget'] - $b['actual']), 'over' => $b['actual'] > $b['budget'],
            ], $g['budget']),
            'budgetTotal' => Prototype::fmt($budgetTotal), 'actualTotal' => Prototype::fmt($actualTotal), 'varianceTotal' => Prototype::fmt($budgetTotal - $actualTotal),
            'tranches' => array_map(static fn ($t) => ['no' => $t['no'], 'date' => $t['date'], 'amount' => Prototype::fmt($t['amount']), 'status' => $t['status']], $g['tranches']),
            'reports' => array_map(static fn ($r) => ['ref' => $r['ref'], 'name' => $r['name'], 'period' => $r['period'], 'due' => $r['due'], 'state' => $r['state']], $g['reports']),
            'conditions' => $g['conditions'],
            'documents'  => $g['documents'],
            'ledgerAccount' => $g['budget'][0]['code'] ?? null,
            'canReceipt' => in_array($g['status'], ['Active', 'Closing'], true),
            'canAttach'  => $this->canManage(),
            'requireAgreement' => config(\Config\Documents::class)->requireGrantAgreement,
            'canActivate' => $g['status'] === 'Pipeline' && $this->canManage(),
            'reportAction' => match ($g['status']) {
                'Pipeline' => 'Convert to award',
                'Closed'   => 'Open close-out file',
                default    => 'Prepare donor report',
            },
            'alert' => match (true) {
                $g['status'] === 'Suspended' => 'Disbursements are suspended.'
                    . ($overdue ? ' The ' . $overdue[0]['name'] . ' is ' . abs($overdue[0]['days']) . ' days overdue,' : '')
                    . ' and no new commitments may be made against this award.',
                $g['status'] === 'Closing' => 'Award closes on ' . $g['end'] . '. ' . Prototype::fmt($unspent) . ' is uncommitted and would be returnable unless a no-cost extension is agreed.',
                $g['reportDays'] >= 0 && $g['reportDays'] <= SettingsRepository::day('reportWarningDays') && $g['status'] !== 'Closed'
                    => 'Next donor report is due in ' . $g['reportDays'] . ($g['reportDays'] === 1 ? ' day.' : ' days.'),
                default => '',
            },
        ]);
    }

    /** Every report still owed to a donor, soonest first. */
    public function calendar()
    {
        $reports = (new GrantRepository())->calendar();

        return $this->json([
            'reports' => $reports,
            'warningDays' => SettingsRepository::day('reportWarningDays'),
            'summary' => count(array_filter($reports, static fn ($r) => $r['state'] === 'Overdue')) . ' overdue · '
                . count(array_filter($reports, static fn ($r) => $r['state'] === 'Due')) . ' due within ' . SettingsRepository::day('reportWarningDays') . ' days · '
                . count($reports) . ' outstanding in all',
        ]);
    }

    /** What the award form chooses from. */
    public function form()
    {
        return $this->json((new GrantRepository())->formOptions() + [
            'nextFundCode' => (new GrantRepository())->nextFundCode(),
            'requireAgreement' => config(\Config\Documents::class)->requireGrantAgreement,
            'reportWarningDays' => SettingsRepository::day('reportWarningDays'),
        ]);
    }

    /**
     * Records an award from its signed agreement. Finance Manager only: it opens a
     * fund, and sets the budget and reporting calendar the award is held to.
     */
    public function create()
    {
        if (!$this->canManage()) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => 'The ' . $this->actor()['role'] . ' cannot record an award. The Finance Manager does — recording one opens a fund and sets the budget it is held to.',
            ]);
        }

        try {
            $done = (new GrantRepository())->record($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'message' => $done['ref'] . ' recorded' . ($done['fundOpened'] ? ' and ' . $done['fund'] . ' opened as ' . $done['fundCode'] : ', held in ' . $done['fund']) . '.',
            'ref'     => $done['ref'],
        ]);
    }

    /** Converts a pipeline award to active on signature. */
    public function activate(string ...$ref)
    {
        $ref = implode('/', $ref);
        if (!$this->canManage()) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => 'The ' . $this->actor()['role'] . ' cannot convert an award. The Finance Manager does, on signature of the agreement.',
            ]);
        }

        try {
            $g = (new GrantRepository())->activate($ref, $this->actorId(), ($this->request->getJSON(true) ?? [])['documents'] ?? []);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => $g['ref'] . ' is active. Its budget may be committed against from today.', 'ref' => $g['ref']]);
    }

    private function canManage(): bool
    {
        // Recording an award opens a fund, the first coding every posting carries: the ledger's to set up.
        return in_array('settings.ledger', $this->actor()['permissions'] ?? [], true);
    }
}
