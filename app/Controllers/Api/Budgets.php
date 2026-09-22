<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\EntityScope;
use App\Libraries\Prototype;
use App\Repositories\BudgetRepository;
use App\Repositories\GrantRepository;
use App\Repositories\Lookups;
use App\Repositories\RuleViolation;

/**
 * Budgets: the approved budget against actual, phased month by month, with the
 * versions a year's budget goes through.
 *
 * Variance is read against the phasing to date rather than the annual figure, so
 * a seasonal programme is not flagged for spending in its season. A line is Over
 * once actual passes the annual budget, on Watch when it runs more than 10% ahead
 * of its phasing, and Underspent when it is below 70% of it.
 *
 * Changing a budget is preparing a document (journal.prepare): a revision is
 * applied to the working version and approved under the budget revision rule; a
 * new year's budget is derived and approved by whoever authorises the year's books
 * (period.authorise). See App\Repositories\BudgetRepository.
 */
class Budgets extends BaseApiController
{
    private const GROUPS = ['Account group' => 'group', 'Programme' => 'program', 'Fund' => 'fund'];

    public function index()
    {
        $repo    = new BudgetRepository();
        $version = $this->chosenVersion($repo);
        if ($version === null) {
            return $this->json(['empty' => true, 'versions' => [], 'canPrepare' => $this->canPrepare(),
                'message' => 'No budget has been recorded yet. Record an award, or raise a budget for the year.']);
        }

        $group = array_key_exists((string) $this->request->getGet('group'), self::GROUPS) ? (string) $this->request->getGet('group') : 'Account group';
        $q     = strtolower(trim((string) $this->request->getGet('q')));
        [$elapsed, $lastMonth] = $repo->elapsed($version);
        $fullYear = $this->request->getGet('basis') === 'full';

        $all = array_map(static fn ($l) => BudgetRepository::measure($l, $elapsed, $fullYear), $repo->linesOf($version));
        $filtered = array_values(array_filter($all, static fn ($l) => $q === ''
            || str_contains(strtolower($l['code'] . ' ' . $l['name'] . ' ' . $l['program'] . ' ' . $l['fund']), $q)));

        $groups = [];
        foreach ($filtered as $l) {
            $groups[$l[self::GROUPS[$group]]][] = $l;
        }

        $over  = count(array_filter($all, static fn ($l) => $l['status'] === 'Over'));
        $watch = count(array_filter($all, static fn ($l) => $l['status'] === 'Watch'));
        $sum   = static fn (array $ls, string $f) => array_sum(array_column($ls, $f));
        $totalAnnual = $sum($all, 'annual');
        $month = $lastMonth === null ? null : substr($lastMonth, 0, 3);
        $state = $this->versionState($repo, $version);
        $moves = $state['moves'];

        return $this->json([
            'versions'     => array_map(static fn ($v) => ['key' => $v['key'], 'label' => $v['label']], $repo->versions()),
            'version'      => $version['key'],
            'versionLabel' => $version['label'],
            'state'        => $state,
            'group'        => $group,
            'groupOptions' => array_keys(self::GROUPS),
            'basis'        => $fullYear ? 'full' : 'phased',
            'basisOptions' => [
                ['key' => 'phased', 'label' => $lastMonth === null ? 'Phasing to date' : 'Phasing to ' . $lastMonth],
                ['key' => 'full', 'label' => 'Full-year budget'],
            ],
            'basisShort'   => $fullYear ? 'Full-year budget' : ($month === null ? 'Phased to date' : 'Phased to ' . $month),
            'q'            => $q,
            'stats'        => [
                ['label' => 'Annual budget', 'value' => Prototype::fmt($totalAnnual), 'note' => $version['label']],
                ['label' => $month === null ? 'Phased to date' : 'Phased to ' . $month, 'value' => Prototype::fmt($sum($all, 'phased')), 'note' => $elapsed . ' of 12 months'],
                ['label' => 'Actual to date', 'value' => Prototype::fmt($sum($all, 'actual')), 'note' => ($totalAnnual > 0 ? round($sum($all, 'actual') / $totalAnnual * 100) : 0) . '% of annual'],
                ['label' => 'Variance to phasing', 'value' => Prototype::fmt($sum($all, 'phased') - $sum($all, 'actual')), 'note' => 'favourable if positive'],
                ['label' => 'Lines needing attention', 'value' => (string) ($over + $watch), 'note' => $over . ' over, ' . $watch . ' on watch'],
            ],
            'groups'       => array_map(static fn ($label, $ls) => [
                'label'    => $label,
                'annual'   => Prototype::fmt($sum($ls, 'annual')),
                'basis'    => Prototype::fmt($sum($ls, 'basisAmt')),
                'actual'   => Prototype::fmt($sum($ls, 'actual')),
                'variance' => Prototype::fmt($sum($ls, 'basisAmt') - $sum($ls, 'actual')),
                'pct'      => ($sum($ls, 'annual') > 0 ? (int) round($sum($ls, 'actual') / $sum($ls, 'annual') * 100) : 0) . '%',
                'lines'    => array_map(static fn ($l) => self::row($l), $ls),
            ], array_keys($groups), array_values($groups)),
            'empty'        => $filtered === [],
            'totals'       => [
                'annual'   => Prototype::fmt($sum($filtered, 'annual')),
                'basis'    => Prototype::fmt($sum($filtered, 'basisAmt')),
                'actual'   => Prototype::fmt($sum($filtered, 'actual')),
                'variance' => Prototype::fmt($sum($filtered, 'basisAmt') - $sum($filtered, 'actual')),
                'pct'      => ($sum($filtered, 'annual') > 0 ? (int) round($sum($filtered, 'actual') / $sum($filtered, 'annual') * 100) : 0) . '%',
            ],
            'hint'         => $version['isRevision'] && $version['status'] !== 'approved' && $version['status'] !== 'superseded'
                ? ($moves ? count(array_unique(array_merge(array_column($moves, 'from'), array_column($moves, 'to')))) . ' lines revised, pending approval' : 'No revisions proposed yet')
                : ($over ? $over . ($over === 1 ? ' line over budget' : ' lines over budget') : 'No lines over budget'),
            'footer'       => count($filtered) . ' budget lines · ' . $version['label'] . ' · ' . $watch . ' on watch, ' . $over . ' over',
            'footerRule'   => 'Revisions must net to zero within a fund · donor-funded lines need funder consent above 10%',
            'revision'     => $this->revisionForm($repo),
            'newYear'      => ['years' => $this->canRollForward($repo) ? $repo->nextYears() : []],
            'canPrepare'   => $this->canPrepare(),
        ]);
    }

    /** One line: its monthly phasing against actual, what each version held, and the rules for revising it. */
    public function line()
    {
        $repo    = new BudgetRepository();
        $version = $this->chosenVersion($repo);
        $key     = (string) $this->request->getGet('key');
        [$elapsed, $lastMonth] = $version === null ? [0, null] : $repo->elapsed($version);
        $found   = $version === null ? null : current(array_filter($repo->linesOf($version), static fn ($l) => $l['key'] === $key));
        if (!$found) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'That budget line is not on this version.']);
        }

        $l = BudgetRepository::measure($found, $elapsed, false);
        $month = $lastMonth === null ? 'date' : date('F', strtotime('1 ' . $lastMonth));
        $runRate = $elapsed > 0 ? $l['actual'] / $elapsed : 0;

        return $this->json([
            'key' => $l['key'], 'code' => $l['code'], 'name' => $l['name'], 'fund' => $l['fund'], 'program' => $l['program'], 'grant' => $l['grant'],
            'status' => $l['status'], 'version' => $version['label'],
            'facts' => [
                ['label' => 'Annual', 'value' => Prototype::fmt($l['annual'])],
                ['label' => 'Phased', 'value' => Prototype::fmt($l['phased'])],
                ['label' => 'Actual', 'value' => Prototype::fmt($l['actual'])],
                ['label' => 'Remaining', 'value' => Prototype::fmt($l['remaining']), 'lead' => true],
            ],
            'months' => array_map(static fn ($m) => [
                'label'  => date('M', strtotime($version['fyStarts'] . " +{$m} months")),
                'budget' => round($l['months'][$m]),
                'actual' => $m < $elapsed ? round($l['actualMonths'][$m]) : null,
            ], range(0, 11)),
            'phasingNote' => match ($l['status']) {
                'Over'       => 'Actual has passed the annual budget. No further commitments can be coded here until a revision is approved.',
                'Watch'      => 'Spend is running ' . Prototype::fmt($l['actual'] - $l['phased']) . ' ahead of the phasing to ' . $month . '.',
                'Underspent' => 'Only ' . $l['pct'] . '% consumed against ' . $l['basisPct'] . '% phased. ' . Prototype::fmt($l['remaining']) . ' remains to be delivered.',
                default      => $elapsed === 0 ? 'The year has not started; nothing is phased to date.'
                    : 'Spend tracks the phasing closely — ' . $l['pct'] . '% consumed against ' . $l['basisPct'] . '% phased.',
            },
            'alert' => match ($l['status']) {
                'Over'  => 'This line is over budget by ' . Prototype::fmt($l['actual'] - $l['annual']) . '. A revision or funder consent is required before further spend.',
                'Watch' => 'Consumption is ahead of phasing. At this rate the line exhausts around month '
                    . min(12, max(1, (int) round($l['annual'] / max(1, $runRate)))) . '.',
                default => null,
            },
            'derivation' => $l['derivation'],
            'history' => $repo->history($version, $key),
            'rules' => $repo->rules()[$l['fund']] ?? $repo->rules()['General Fund'] ?? [],
            'canRevise' => $this->canPrepare() && $repo->revisionBase() !== null
                && in_array($l['key'], array_column($repo->linesOf($repo->revisionBase()['base']), 'key'), true),
        ]);
    }

    /** The variance report for the version, grouping and basis on screen, as CSV. */
    public function export()
    {
        $repo    = new BudgetRepository();
        $version = $this->chosenVersion($repo);
        if ($version === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'There is no budget to export.']);
        }
        $group = array_key_exists((string) $this->request->getGet('group'), self::GROUPS) ? (string) $this->request->getGet('group') : 'Account group';
        $fullYear = $this->request->getGet('basis') === 'full';
        [$elapsed, $lastMonth] = $repo->elapsed($version);
        $lines = array_map(static fn ($l) => BudgetRepository::measure($l, $elapsed, $fullYear), $repo->linesOf($version));
        usort($lines, static fn ($a, $b) => [$a[self::GROUPS[$group]], $a['code']] <=> [$b[self::GROUPS[$group]], $b['code']]);

        $basisLabel = $fullYear ? 'Full-year budget' : ($lastMonth === null ? 'Phased to date' : 'Phased to ' . $lastMonth);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, [$version['label'] . ' · variance against ' . strtolower($basisLabel)]);
        fputcsv($out, [$group, 'Code', 'Budget line', 'Fund', 'Programme', 'Grant', 'Annual budget', $basisLabel, 'Actual to date', 'Variance', 'Consumed %', 'Status']);
        foreach ($lines as $l) {
            fputcsv($out, [$l[self::GROUPS[$group]], $l['code'], $l['name'], $l['fund'], $l['program'], $l['grant'],
                round($l['annual'], 2), round($l['basisAmt'], 2), round($l['actual'], 2), round($l['variance'], 2), $l['pct'], $l['status']]);
        }
        $sum = static fn (string $f) => round(array_sum(array_column($lines, $f)), 2);
        fputcsv($out, ['', '', 'Total expenditure budget', '', '', '', $sum('annual'), $sum('basisAmt'), $sum('actual'), $sum('variance'), '', '']);
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' budget variance ' . $version['key'] . '.csv"')
            ->setBody($csv);
    }

    /** What a new year's budget would be on the assumptions given, before anything is written. */
    public function deriveYear()
    {
        $repo = new BudgetRepository();
        if (!$this->canRollForward($repo)) {
            // A new instance has nothing to roll forward yet; that is a state, not an error.
            return $this->json(['unavailable' => 'There is no approved budget for the working year to roll forward.', 'rows' => []]);
        }
        try {
            $d = $repo->derive(
                (int) ($this->request->getGet('year') ?: $repo->nextYears()[0]),
                (int) $this->request->getGet('uplift'),
                $this->request->getGet('keep') !== '0'
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $year = 'FY' . $d['year'];
        $fundingGap = $d['grantTotal'] - $d['awardIncome'];
        $coreGap    = $d['coreTotal'] - $d['coreIncome'];
        $awardRows  = array_filter($d['rows'], static fn ($r) => $r['basisKind'] === 'Award');

        return $this->json([
            'year' => $d['year'], 'yearLabel' => $year, 'priorLabel' => $d['source']['fyCode'],
            'intro' => $year . ' is built from what is already committed. Grant-funded lines are derived from each award\'s remaining ceiling, '
                . 'apportioned by the months of the award period that fall inside the year. Core lines roll forward from the '
                . $d['source']['label'] . ' budget.',
            'summary' => [
                ['label' => 'Grant-funded', 'value' => Prototype::fmt($d['grantTotal']), 'note' => count($awardRows) . ' lines derived from awards'],
                ['label' => 'Core', 'value' => Prototype::fmt($d['coreTotal']), 'note' => (count($d['rows']) - count($awardRows)) . ' lines rolled forward'],
                ['label' => 'Total budget', 'value' => Prototype::fmt($d['grantTotal'] + $d['coreTotal']), 'note' => 'against ' . Prototype::fmt($d['priorTotal']) . ' in ' . $d['source']['fyCode']],
                ['label' => 'Lines with nothing left', 'value' => (string) $d['dropped'], 'note' => $d['keepEmpty'] ? 'kept at zero for comparison' : 'excluded from the draft'],
            ],
            'checks' => [
                ['ok' => $d['breaches'] === [], 'tone' => 'bad', 'text' => $d['breaches']
                    ? count($d['breaches']) . (count($d['breaches']) === 1 ? ' line exceeds' : ' lines exceed') . ' its award ceiling and must be reduced before approval'
                    : 'Every grant-funded line sits within its award ceiling'],
                ['ok' => $fundingGap <= 0, 'tone' => 'warn', 'text' => 'Restricted spend of ' . Prototype::fmt($d['grantTotal'])
                    . ($fundingGap > 0 ? ' exceeds the ' . Prototype::fmt($d['awardIncome']) . ' the awards still owe by ' . Prototype::fmt($fundingGap)
                        : ' is within the ' . Prototype::fmt($d['awardIncome']) . ' the awards still owe')],
                ['ok' => $coreGap <= 0, 'tone' => 'warn', 'text' => $coreGap > 0
                    ? 'Core spend of ' . Prototype::fmt($d['coreTotal']) . ' exceeds unrestricted income of ' . Prototype::fmt($d['coreIncome']) . ' — a '
                        . Prototype::fmt($coreGap) . ($d['reserves'] > 0
                            ? ' draw on the ' . Prototype::fmt($d['reserves']) . ' of unrestricted reserves, which the board must agree'
                            : ' gap with no unrestricted reserves to draw on, which the board must close before approval')
                    : 'Core spend of ' . Prototype::fmt($d['coreTotal']) . ' is covered by unrestricted income of ' . Prototype::fmt($d['coreIncome'])],
            ],
            'rows' => array_map(static fn ($r) => [
                'code' => $r['code'], 'program' => $r['program'], 'grant' => $r['grant'], 'note' => $r['note'], 'basis' => $r['basisKind'],
                'prior' => Prototype::fmt($r['prior']), 'amount' => Prototype::fmt($r['amount']),
            ], $d['rows']),
            'existing' => $d['existing'] > 0
                ? 'A draft already exists for ' . $year . ' — raising another replaces nothing; both stay in the version list.' : null,
            'commitLabel' => 'Raise ' . $year . ' Original (draft)',
            'footerNote' => 'The draft cannot be used for variance reporting until the Executive Director approves it.',
        ]);
    }

    public function raiseYear()
    {
        if ($denied = $this->mustPrepare()) {
            return $denied;
        }
        $body = $this->request->getJSON(true) ?? [];

        return $this->write(function (BudgetRepository $repo) use ($body) {
            $done = $repo->raiseYear((int) ($body['year'] ?? 0), (int) ($body['uplift'] ?? 0), (bool) ($body['keepEmpty'] ?? true), $this->actorId());

            return ['version' => $done['version'], 'message' => $done['label'] . ' raised from ' . $done['lines']
                . ' lines — it cannot be used for variance reporting until the Executive Director approves it.'];
        });
    }

    public function reallocate()
    {
        if ($denied = $this->mustPrepare()) {
            return $denied;
        }
        $body = $this->request->getJSON(true) ?? [];

        return $this->write(function (BudgetRepository $repo) use ($body) {
            $amount = (float) preg_replace('/[^0-9.]/', '', (string) ($body['amount'] ?? ''));
            $done = $repo->reallocate((string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''), $amount,
                (string) ($body['ref'] ?? ''), (string) ($body['reason'] ?? ''), $this->actorId());

            return ['version' => $done['version'], 'message' => Prototype::fmt($amount) . ' moved from ' . $done['from'] . ' to ' . $done['to'] . ' in the working version.'];
        });
    }

    public function submit()
    {
        if ($denied = $this->mustPrepare()) {
            return $denied;
        }

        return $this->write(function (BudgetRepository $repo) {
            $v = $repo->submit($this->bodyVersion(), $this->actorId());

            return ['version' => $v['key'], 'message' => $v['fyCode'] . ' ' . $v['name'] . ' submitted for approval. Nothing changes in the approved budget until it is approved.'];
        });
    }

    public function approve()
    {
        return $this->write(function (BudgetRepository $repo) {
            $body = $this->request->getJSON(true) ?? [];
            $v = $repo->approve($this->bodyVersion(), $this->actorId(), $this->can('period.authorise'), $body['authorityRef'] ?? null);

            return ['version' => $v['key'], 'message' => $v['fyCode'] . ' ' . $v['name'] . ' approved. It is now the budget '
                . ($v['isRevision'] ? 'the procurement budget check and variance reporting read.' : 'for ' . $v['fyCode'] . '.')];
        });
    }

    public function sendBack()
    {
        return $this->write(function (BudgetRepository $repo) {
            $body = $this->request->getJSON(true) ?? [];
            $v = $repo->sendBack($this->bodyVersion(), $this->actorId(), $this->can('period.authorise'), (string) ($body['note'] ?? ''));

            return ['version' => $v['key'], 'message' => $v['fyCode'] . ' ' . $v['name'] . ' sent back to its preparer as a draft.'];
        });
    }

    public function discard()
    {
        if ($denied = $this->mustPrepare()) {
            return $denied;
        }

        return $this->write(function (BudgetRepository $repo) {
            $label = $repo->discard($this->bodyVersion(), $this->actorId());

            return ['version' => $repo->defaultVersion()['key'] ?? null, 'message' => $label . ' discarded. The approved budget is unchanged.'];
        });
    }

    // ------------------------------------------------------------------

    private static function row(array $l): array
    {
        return [
            'key' => $l['key'], 'code' => $l['code'], 'name' => $l['name'], 'fund' => $l['fund'], 'program' => $l['program'],
            'annual' => Prototype::fmt($l['annual']), 'basis' => Prototype::fmt($l['basisAmt']), 'actual' => Prototype::fmt($l['actual']),
            'variance' => Prototype::fmt($l['variance']), 'adverse' => $l['variance'] < 0,
            'pct' => $l['pct'] . '%', 'pctNum' => $l['pct'], 'basisPct' => $l['basisPct'], 'status' => $l['status'],
        ];
    }

    /** The version asked for, or the approved version of the working year. */
    private function chosenVersion(BudgetRepository $repo): ?array
    {
        $key = (string) $this->request->getGet('version');

        return ($key !== '' ? $repo->version($key) : null) ?? $repo->defaultVersion();
    }

    /**
     * Where the version stands and what the actor may do with it: submit a draft,
     * approve or send back a submitted one, or discard a draft.
     */
    private function versionState(BudgetRepository $repo, array $version): array
    {
        $row = $version['rows'][0];
        $single = !EntityScope::consolidated();
        $prepare = $this->canPrepare();
        $pending = $version['status'] === 'pending_approval';
        $draft = $version['status'] === 'draft';
        $moves = $version['isRevision'] ? $repo->reallocations($version) : [];
        $refusal = $pending && $single ? $repo->approvalRefusal($version, $this->actorId(), $this->can('period.authorise')) : null;
        $who = static fn ($id) => $id === null ? null : (new Lookups())->shortName((int) $id);

        $note = match (true) {
            $draft && $version['isRevision']  => count($moves) . (count($moves) === 1 ? ' movement' : ' movements') . ' applied, totalling '
                . Prototype::fmt($repo->moved($version)) . '. Submit it for approval to replace the approved budget.',
            $draft                            => 'A draft raised by ' . $who($row['prepared_by']) . '. It cannot be used for variance reporting until the Executive Director approves it.',
            $pending                          => 'Submitted by ' . ($who($row['submitted_by']) ?? '—') . ($version['isRevision']
                ? ' · ' . Prototype::fmt($repo->moved($version)) . ' moved across ' . count($moves) . (count($moves) === 1 ? ' movement' : ' movements') : '')
                . '. Awaiting approval.',
            $version['status'] === 'approved' => 'The approved budget' . ($row['approved_by'] ? ', approved by ' . $who($row['approved_by']) . ' on ' . date('d M Y', strtotime($row['approved_at'])) : '') . '.',
            default                           => 'Superseded by a later version. Shown for comparison only.',
        };

        return [
            'status' => $version['status'], 'isRevision' => $version['isRevision'], 'note' => $note,
            'returnedNote' => $draft ? $row['returned_note'] : null,
            'moves' => array_map(static fn ($m) => ['from' => $m['from'], 'to' => $m['to'], 'amount' => Prototype::fmt($m['amount']),
                'ref' => $m['ref'], 'reason' => $m['reason'], 'by' => $m['by']], $moves),
            'canSubmit' => $single && $prepare && $draft && (!$version['isRevision'] || $moves !== []),
            'canDiscard' => $single && $prepare && $draft,
            'canApprove' => $single && $pending && $refusal === null,
            'canSendBack' => $single && $pending && ($refusal === null || $refusal['needsAuthority']),
            'needsAuthority' => $pending && $refusal !== null && $refusal['needsAuthority'],
            'approvalNote' => $pending && $refusal !== null ? $refusal['message'] : null,
        ];
    }

    /** The lines a revision can move budget between, with what the modal checks before sending. */
    private function revisionForm(BudgetRepository $repo): ?array
    {
        $r = $repo->revisionBase();
        if ($r === null) {
            return null;
        }
        [$elapsed] = $repo->elapsed($r['base']);
        $approved = array_column($repo->linesOf($r['approved']), 'annual', 'key');
        $movedOut = [];
        foreach ($r['working'] === null ? [] : $repo->reallocations($r['working']) as $m) {
            $movedOut[$m['fromKey']] = ($movedOut[$m['fromKey']] ?? 0) + $m['amount'];
        }

        return [
            'base' => $r['base']['label'],
            'locked' => $r['working'] !== null && $r['working']['status'] === 'pending_approval'
                ? $r['working']['label'] . ' is awaiting approval. It has to be approved or sent back before another movement is applied.' : null,
            'consentShare' => BudgetRepository::FUNDER_CONSENT_SHARE,
            'indirectPrefix' => GrantRepository::INDIRECT_PREFIX,
            'lines' => array_map(static function ($l) use ($elapsed, $approved, $movedOut) {
                $m = BudgetRepository::measure($l, $elapsed, false);

                return [
                    'key' => $l['key'], 'code' => $l['code'], 'name' => $l['name'], 'fund' => $l['fund'], 'fundGroup' => $l['fundGroup'],
                    'grant' => $l['grant'], 'annual' => $l['annual'], 'available' => round($m['available'], 2),
                    'approved' => $approved[$l['key']] ?? 0, 'movedOut' => $movedOut[$l['key']] ?? 0,
                    'label' => $l['code'] . ' · ' . $l['name'] . ' · ' . $l['program'] . ' (' . $l['fund'] . ($l['grant'] !== 'Unassigned' ? ', ' . $l['grant'] : '') . ')',
                ];
            }, $repo->linesOf($r['base'])),
        ];
    }

    /** Whether there is an approved budget of the working year for next year's to be derived from. */
    private function canRollForward(BudgetRepository $repo): bool
    {
        $source = $repo->defaultVersion();

        return $source !== null && $source['status'] === 'approved' && $repo->nextYears() !== [];
    }

    private function bodyVersion(): string
    {
        return (string) (($this->request->getJSON(true) ?? [])['version'] ?? '');
    }

    private function canPrepare(): bool
    {
        return $this->can('journal.prepare') && !EntityScope::consolidated();
    }

    private function mustPrepare()
    {
        return $this->can('journal.prepare') ? null
            : $this->denied('Changing a budget is preparing a document, which your role does not allow.');
    }

    private function write(callable $do)
    {
        try {
            return $this->json($do(new BudgetRepository()));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }
}
