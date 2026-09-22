<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\Clock;
use App\Libraries\EntityScope;
use App\Libraries\I18n as I18nLib;
use App\Libraries\Prototype;
use App\Repositories\DonorReportRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Donor reports (v5): the register, one report's figures against the ledger, and
 * its path to the funder. Preparing (new reports, figures, review, queries,
 * acceptance) needs journal.prepare; submitting to the funder, or returning a
 * report to its preparer, needs journal.approve and is never done by whoever
 * prepared it.
 */
class DonorReports extends BaseApiController
{
    private const TABS = ['All', 'Draft', 'In review', 'Submitted', 'Queried', 'Overdue', 'Accepted'];

    private const PAGE_SIZE = 10;

    /** Statuses still with the organisation. */
    private const OPEN = ['Draft', 'In review', 'Overdue'];

    public function index()
    {
        $repo   = new DonorReportRepository();
        $all    = $repo->all();
        $status = in_array($this->request->getGet('status'), self::TABS, true) ? (string) $this->request->getGet('status') : 'All';
        $q      = mb_strtolower(trim((string) $this->request->getGet('q')));
        $warn   = SettingsRepository::day('reportWarningDays');

        $filtered = array_values(array_filter($all, static function ($r) use ($status, $q) {
            if ($status !== 'All' && $r['status'] !== $status) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($r['ref'] . ' ' . $r['title'] . ' ' . $r['funder'] . ' ' . $r['grant'] . ' ' . $r['grantRef'] . ' ' . $r['period']), $q);
        }));

        // What still needs doing comes first, soonest due at the top; then what
        // has gone to funders, most recent first.
        $working = static fn ($r) => in_array($r['status'], self::OPEN, true) || $r['status'] === 'Queried';
        usort($filtered, static fn ($a, $b) => [$working($b), $working($a) ? $a['dueOn'] : $b['dueOn'], $a['ref']]
            <=> [$working($a), $working($a) ? $b['dueOn'] : $a['dueOn'], $b['ref']]);

        $pages = max(1, (int) ceil(count($filtered) / self::PAGE_SIZE));
        $page  = min($pages, max(1, (int) ($this->request->getGet('page') ?: 1)));

        $open    = array_values(array_filter($all, static fn ($r) => in_array($r['status'], self::OPEN, true)));
        $dueSoon = array_values(array_filter($open, static fn ($r) => $r['dueDays'] <= $warn));
        $overdue = array_values(array_filter($all, static fn ($r) => $r['status'] === 'Overdue'));
        $untied  = array_values(array_filter($all, static fn ($r) => !$r['tied']));
        $year    = substr(Clock::date(), 0, 4);
        $reported = array_filter($all, static fn ($r) => $r['submittedOn'] !== null && str_starts_with($r['submittedOn'], $year));
        $upcoming = array_values(array_filter($dueSoon, static fn ($r) => $r['dueDays'] >= 0));
        usort($upcoming, static fn ($a, $b) => $a['dueDays'] <=> $b['dueDays']);

        $canPrepare = $this->canPrepare();

        return $this->json([
            'rows'     => array_map(fn ($r) => $this->row($r, $warn), array_slice($filtered, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE)),
            'status'   => $status,
            'total'    => count($all),
            'filtered' => count($filtered),
            'page'     => $page,
            'pages'    => $pages,
            'pageSize' => self::PAGE_SIZE,
            'tabs'     => array_map(static fn ($s) => [
                'key' => $s, 'label' => $s . ' (' . ($s === 'All' ? count($all) : count(array_filter($all, static fn ($r) => $r['status'] === $s))) . ')',
            ], self::TABS),
            'stats'    => [
                ['label' => 'Open reports', 'value' => (string) count($open), 'note' => 'draft, in review or overdue'],
                ['label' => 'Due within ' . $warn . ' days', 'value' => (string) count($dueSoon),
                    'note' => $upcoming === [] ? ($dueSoon === [] ? 'nothing imminent' : 'all past their date') : 'earliest ' . date('d M', strtotime($upcoming[0]['dueOn']))],
                ['label' => 'Overdue', 'value' => (string) count($overdue), 'note' => $overdue === [] ? 'none past their date' : 'blocking disbursement'],
                ['label' => 'Reported this year', 'value' => Prototype::fmt(array_sum(array_column($reported, 'reported'))), 'note' => 'submitted to funders in ' . $year],
                ['label' => 'Not reconciled', 'value' => (string) count($untied), 'note' => 'cannot be submitted'],
            ],
            'hint'     => $untied === [] ? 'All reports tie to the ledger' : count($untied) . (count($untied) === 1 ? ' report does not tie' : ' reports do not tie'),
            'footer'   => count($filtered) . ' of ' . count($all) . ' reports · ' . count($open) . ' open, ' . count($dueSoon) . ' due within ' . $warn . ' days',
            'calendar' => array_map(fn ($r) => $this->row($r, $warn), array_values(array_filter(
                array_reverse($all),
                static fn ($r) => in_array($r['status'], self::OPEN, true) || $r['status'] === 'Queried'
            ))),
            'warningDays' => $warn,
            'canPrepare'  => $canPrepare,
            'newReport'   => $canPrepare ? [
                'awards' => $repo->reportableAwards(),
                'types'  => array_map(static fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(DonorReportRepository::TYPES), DonorReportRepository::TYPES),
            ] : null,
        ]);
    }

    /** One report. Its reference carries slashes (DANIDA/CE-2025/27/R4), so it arrives in segments. */
    public function show(string ...$ref)
    {
        $r = (new DonorReportRepository())->find(rawurldecode(implode('/', $ref)));
        if ($r === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
        }

        return $this->json($this->detail($r));
    }

    /** The report pack: the funder-facing schedule, the reconciliation and the compliance record. */
    public function export()
    {
        $r = (new DonorReportRepository())->find((string) $this->request->getGet('ref'));
        if ($r === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'There is no such report to export.']);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, [$r['ref'] . ' · ' . $r['title']]);
        fputcsv($out, [$r['funder'] . ' · ' . $r['grant'] . ' (' . $r['grantRef'] . ') · ' . $r['period'] . ' · ' . $r['type'] . ' · due ' . $r['due'] . ' · ' . $r['status']]);
        fputcsv($out, ['Figures taken from the ledger' . ($r['figuresTaken'] ? ' on ' . $r['figuresTaken'] : '') . '; KES']);
        fputcsv($out, []);
        fputcsv($out, ['Code', 'Budget line', 'Budget', 'This period', 'Cumulative', 'Used %']);
        foreach ($r['lines'] as $l) {
            fputcsv($out, [$l['code'], $l['name'], $l['budget'], $l['period'], $l['cumulative'], self::used($l['cumulative'], $l['budget'])]);
        }
        $budget = array_sum(array_column($r['lines'], 'budget'));
        fputcsv($out, ['', 'Total expenditure', $budget, array_sum(array_column($r['lines'], 'period')), $r['cumulative'], self::used($r['cumulative'], $budget)]);
        fputcsv($out, []);
        fputcsv($out, ['Reconciliation to the general ledger']);
        foreach ($this->reconciliation($r) as $c) {
            fputcsv($out, [$c['label'], $c['note'], $c['amount']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Compliance']);
        foreach ($r['compliance'] as $c) {
            fputcsv($out, [$c]);
        }
        foreach ($r['queries'] as $q) {
            fputcsv($out, ['Query ' . $q['ref'] . ' (' . $q['when'] . ')', $q['text'], $q['response']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Supporting documents']);
        foreach ($r['attachments'] as $d) {
            fputcsv($out, [$d['name'] ?? '']);
        }
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' donor report ' . str_replace('/', '-', $r['ref']) . '.csv"')
            ->setBody($csv);
    }

    // ---- Writes ----

    public function create()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->prepare(fn (DonorReportRepository $repo) => $repo->create($body, $this->actorId()));
    }

    public function refresh()
    {
        return $this->prepare(fn (DonorReportRepository $repo) => $repo->refresh($this->ref(), $this->actorId()));
    }

    public function removeAdjustment()
    {
        return $this->prepare(fn (DonorReportRepository $repo) => $repo->removeAdjustment($this->ref(), $this->actorId()));
    }

    public function review()
    {
        return $this->prepare(fn (DonorReportRepository $repo) => $repo->sendForReview($this->ref(), $this->actorId()));
    }

    public function raiseQuery()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->prepare(fn (DonorReportRepository $repo) => $repo->recordQuery($this->ref(), (string) ($body['text'] ?? ''), $body['queryRef'] ?? null, $this->actorId()));
    }

    public function answer()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->prepare(fn (DonorReportRepository $repo) => $repo->respond($this->ref(), (array) ($body['responses'] ?? []), $this->actorId()));
    }

    public function accept()
    {
        return $this->prepare(fn (DonorReportRepository $repo) => $repo->accept($this->ref(), $this->actorId()));
    }

    public function note()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->prepare(fn (DonorReportRepository $repo) => $repo->saveNote($this->ref(), (string) ($body['note'] ?? '')));
    }

    public function submit()
    {
        return $this->approve(fn (DonorReportRepository $repo) => $repo->submit($this->ref(), $this->actorId()));
    }

    public function sendBack()
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->approve(fn (DonorReportRepository $repo) => $repo->returnToPreparer($this->ref(), (string) ($body['note'] ?? ''), $this->actorId()));
    }

    // ---- Shapes ----

    private function row(array $r, int $warn): array
    {
        $diff = $r['reported'] - $r['cumulative'];

        return [
            'ref' => $r['ref'], 'title' => $r['title'], 'funder' => $r['funder'], 'grant' => $r['grant'], 'grantRef' => $r['grantRef'],
            'period' => $r['period'], 'type' => $r['type'], 'status' => $r['status'],
            'reported' => Prototype::fmt($r['reported']), 'actual' => Prototype::fmt($r['cumulative']),
            'hasFigures' => $r['lines'] !== [],
            'tied' => $r['tied'], 'diff' => ($diff > 0 ? '+' : '') . Prototype::fmt($diff),
            'due' => date('d M', strtotime($r['dueOn'])), 'dueFull' => $r['due'], 'dueDays' => $r['dueDays'],
            'dueSoon' => in_array($r['status'], self::OPEN, true) && $r['dueDays'] <= $warn,
        ];
    }

    private function detail(array $r): array
    {
        $budget = array_sum(array_column($r['lines'], 'budget'));
        $period = array_sum(array_column($r['lines'], 'period'));
        $status = $r['statusKey'];
        $prepare = $this->canPrepare();
        $approve = $this->can('journal.approve') && !EntityScope::consolidated();
        $isPreparer = $this->actorId() === $r['preparerId'];
        $open = array_values(array_filter($r['queries'], static fn ($q) => !$q['answered']));

        return [
            'ref' => $r['ref'], 'title' => $r['title'], 'funder' => $r['funder'], 'grant' => $r['grant'], 'grantRef' => $r['grantRef'],
            'period' => $r['period'], 'type' => $r['type'], 'status' => $r['status'], 'due' => $r['due'],
            'preparer' => $r['preparer'], 'reviewer' => $r['reviewer'], 'note' => $r['note'],
            'figuresNote' => $r['figuresTaken'] === null ? 'No figures taken from the ledger yet' : 'Figures taken from posted ledger actuals on ' . $r['figuresTaken'],
            'lines' => array_map(static fn ($l) => [
                'code' => $l['code'], 'name' => $l['name'],
                'budget' => Prototype::fmt($l['budget']), 'period' => Prototype::fmt($l['period']), 'cumulative' => Prototype::fmt($l['cumulative']),
                'used' => self::used($l['cumulative'], $l['budget']) . '%', 'hot' => self::used($l['cumulative'], $l['budget']) >= 85,
            ], $r['lines']),
            'budgetTotal' => Prototype::fmt($budget), 'periodTotal' => Prototype::fmt($period), 'cumulativeTotal' => Prototype::fmt($r['cumulative']),
            'usedTotal' => self::used($r['cumulative'], $budget) . '%',
            'received' => Prototype::fmt($r['received']), 'unspent' => Prototype::fmt($r['received'] - $r['cumulative']),
            'tied' => $r['tied'], 'diff' => Prototype::fmt(abs($r['reported'] - $r['cumulative'])), 'adjustment' => $r['reportedAdj'],
            'recon' => array_map(static fn ($c) => ['label' => $c['label'], 'note' => $c['note'], 'value' => $c['value'], 'fail' => $c['fail']], $this->reconciliation($r)),
            'compliance' => $r['compliance'],
            'queries' => $r['queries'],
            'trail' => $r['trail'],
            'attachments' => $r['attachments'],
            'alert' => $this->alert($r, $open),
            'glUrl' => '/gl?' . http_build_query(['account' => $r['lines'][0]['code'] ?? '', 'grant' => $r['grant']]),
            'can' => [
                'refresh' => $prepare && $status === 'draft',
                'removeAdjustment' => $prepare && $status === 'draft' && !$r['tied'],
                'review' => $prepare && $status === 'draft',
                'submit' => $approve && $status === 'in_review' && !$isPreparer,
                'sendBack' => $approve && $status === 'in_review' && !$isPreparer,
                'query' => $prepare && $status === 'submitted',
                'respond' => $prepare && $status === 'queried',
                'accept' => $prepare && $status === 'submitted',
                'note' => $prepare && $status !== 'accepted',
                'attach' => $prepare,
            ],
            'submitNote' => $status !== 'in_review' ? null
                : ($isPreparer ? 'You prepared this report. Someone else reviews it and submits it to ' . $r['funder'] . '.'
                    : (!$approve ? 'Submitting to the funder needs someone who approves documents.' : null)),
        ];
    }

    /** @return list<array{label: string, note: string, value: string, amount: float, fail: bool}> */
    private function reconciliation(array $r): array
    {
        $diff = round($r['reported'] - $r['cumulative'], 2);
        $rows = [
            ['label' => 'Reported expenditure', 'note' => 'Total on the funder-facing schedule', 'amount' => $r['reported'], 'fail' => false],
            ['label' => 'Posted ledger actuals', 'note' => 'Sum of postings coded to ' . $r['grant'], 'amount' => $r['cumulative'], 'fail' => false],
            ['label' => 'Difference', 'note' => $r['tied'] ? 'No difference — the report ties' : 'Manual adjustment not supported by a posted journal', 'amount' => $diff, 'fail' => !$r['tied']],
            ['label' => 'Funds received', 'note' => 'Disbursements banked against this grant', 'amount' => $r['received'], 'fail' => false],
            ['label' => 'Unspent balance held', 'note' => 'Received less expenditure to date', 'amount' => $r['received'] - $r['cumulative'], 'fail' => false],
        ];

        return array_map(static fn ($c) => $c + ['value' => $c['label'] === 'Difference' && $r['tied'] ? 'nil' : Prototype::fmt($c['amount'])], $rows);
    }

    private function alert(array $r, array $openQueries): string
    {
        if (!$r['tied']) {
            return 'This report does not tie to the ledger — reported expenditure ' . ($r['reported'] > $r['cumulative'] ? 'exceeds' : 'falls short of')
                . ' posted actuals by ' . Prototype::fmt(abs($r['reported'] - $r['cumulative'])) . '. It cannot be submitted until the difference is posted or removed.';
        }
        $last = end($r['trail']);
        if ($r['statusKey'] === 'draft' && $last !== false && str_starts_with($last['what'], 'Returned to ')) {
            return $last['what'] . '.';
        }
        if ($r['status'] === 'Overdue') {
            return 'Report is ' . abs($r['dueDays']) . ' days overdue and is blocking the next disbursement.';
        }
        if ($r['status'] === 'Queried') {
            return count($openQueries) . (count($openQueries) === 1 ? ' query is' : ' queries are') . ' outstanding from ' . $r['funder'] . '.';
        }
        if ($r['lines'] === [] && in_array($r['statusKey'], ['draft', 'in_review'], true) && $r['typeKey'] !== 'narrative') {
            return 'No figures have been taken from the ledger for this report yet.';
        }
        if (in_array($r['statusKey'], ['draft', 'in_review'], true) && $r['dueDays'] >= 0 && $r['dueDays'] <= 30) {
            return 'Due in ' . $r['dueDays'] . ($r['dueDays'] === 1 ? ' day.' : ' days.');
        }

        return '';
    }

    private static function used(float $spent, float $budget): int
    {
        return $budget > 0 ? (int) round($spent / $budget * 100) : 0;
    }

    private function ref(): string
    {
        return trim((string) (($this->request->getJSON(true) ?? [])['ref'] ?? ''));
    }

    private function canPrepare(): bool
    {
        return $this->can('journal.prepare') && !EntityScope::consolidated();
    }

    private function prepare(callable $do)
    {
        if (!$this->can('journal.prepare')) {
            return $this->denied('Preparing a donor report is preparing a document, which your role does not allow.');
        }

        return $this->write($do);
    }

    private function approve(callable $do)
    {
        if (!$this->can('journal.approve')) {
            return $this->denied('Submitting a report to a funder needs someone who approves documents.');
        }

        return $this->write($do);
    }

    private function write(callable $do)
    {
        if (EntityScope::consolidated()) {
            return $this->denied('The consolidated view is read-only. Choose the entity the report belongs to.');
        }

        try {
            return $this->json($do(new DonorReportRepository()));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }

    /**
     * Delivery language by funder, plus the cover page as that funder will receive it.
     *
     * Each agreement names the language the pack is delivered in, so this is a
     * property of the funder rather than of whoever runs the report. Headings and
     * narrative translate; the figures, dates and currency do not — they are lifted
     * from the posted ledger and stay in the reporting locale so the pack still
     * reconciles line-for-line against the accounts.
     */
    public function languages()
    {
        $funders  = (new DonorReportRepository())->funderLanguages();
        $selected = $this->request->getGet('funder') ?: ($funders[0]['funder'] ?? '');

        $rows = array_map(static fn ($d) => [
            'funder'   => $d['funder'],
            'ref'      => $d['ref'],
            'note'     => $d['note'],
            'locale'   => $d['locale'],
            'native'   => I18nLib::find($d['locale'])['native'] ?? $d['locale'],
            'selected' => $d['funder'] === $selected,
        ], $funders);

        return $this->json([
            'canChange' => $this->can('journal.prepare'),
            'rows'    => $rows,
            'choices' => array_map(static fn ($l) => [
                'code'     => $l['code'],
                'native'   => $l['native'],
                'short'    => $l['code'] === I18nLib::SOURCE_LOCALE ? 'EN' : strtoupper($l['code']),
                'coverage' => $l['coverage'],
                'reviewer' => $l['reviewer'],
            ], I18nLib::locales()),
            'preview' => $this->coverPreview($selected),
            'note'    => 'Each funder receives its report in its own agreed language. The narrative and headings are translated; the figures are not — they are lifted from the posted ledger and stay in the reporting locale so the report still reconciles line-for-line.',
        ]);
    }

    /** Sets the delivery language an agreement requires for a funder's pack. */
    public function setLanguage()
    {
        if (!$this->can('journal.prepare')) {
            return $this->denied('Choosing the language a funder is reported to in is preparing its reports, which your role does not allow.');
        }

        $body   = $this->request->getJSON(true) ?? [];
        $funder = trim((string) ($body['funder'] ?? ''));
        $code   = (string) ($body['locale'] ?? '');

        if (!I18nLib::isKnown($code)) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '"' . $code . '" is not a language the system publishes.']);
        }

        $repo = new DonorReportRepository();
        if ($repo->setLanguage($funder, $code)) {
            foreach ($repo->funderLanguages() as $d) {
                if ($d['funder'] === $funder) {
                    return $this->json([
                        'funder'  => $d,
                        'preview' => $this->coverPreview($funder),
                    ]);
                }
            }
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $funder . ' is not a funder on the reporting calendar.']);
    }

    /**
     * The cover page as the funder sees it: translated wording around figures that
     * never move. Where a heading has no approved translation it stays in English
     * and is named to the reviewer, rather than being machine-translated into a
     * document a funder will hold the organisation to.
     *
     * Null while there is no funder on the reporting calendar to preview.
     */
    private function coverPreview(string $funder): ?array
    {
        $repo    = new DonorReportRepository();
        $funders = $repo->funderLanguages();
        if ($funders === []) {
            return null;
        }
        $fd      = null;
        foreach ($funders as $d) {
            if ($d['funder'] === $funder) {
                $fd = $d;
                break;
            }
        }
        $fd ??= $funders[0];

        $code   = $fd['locale'];
        $locale = I18nLib::find($code) ?? I18nLib::locales()[0];
        $cover  = $repo->cover($locale['code']);

        $project     = $fd['project'][$code] ?? $fd['project'][I18nLib::SOURCE_LOCALE];
        $projectBack = !isset($fd['project'][$code]) && $code !== I18nLib::SOURCE_LOCALE;

        return [
            'dir'      => $locale['dir'],
            'lang'     => $locale['code'],
            'org'      => $cover['org'],
            'title'    => $cover['prefix'] . ' — ' . $project,
            'subtitle' => $cover['period'] . ' · ' . $cover['awardWord'] . ' ' . $fd['ref'],
            'lines'    => array_map(
                static fn ($label, $i) => ['label' => $label, 'value' => I18nLib::REPORTING_CURRENCY . ' ' . $fd['figures'][$i]],
                $cover['labels'],
                array_keys($cover['labels'])
            ),
            'footnote' => $cover['foot'],
            'status'   => $fd['funder'] . ' → ' . $locale['native'] . ' · ' . $locale['coverage'] . '% translated',
            'warning'  => $this->previewWarning($fd, $locale, $projectBack),
        ];
    }

    private function previewWarning(array $fd, array $locale, bool $projectBack): string
    {
        if ($locale['code'] === I18nLib::SOURCE_LOCALE) {
            return $fd['funder'] . ' receives the source language. Nothing is translated, and the report ties to the ledger as posted.';
        }

        $tail = $projectBack
            ? 'the project title has no approved ' . $locale['native'] . ' wording yet and stays in English, flagged to ' . $locale['reviewer'] . '.'
            : ($locale['coverage'] < 90
                ? $locale['native'] . ' is only ' . $locale['coverage'] . '% reviewed, so any unapproved heading will appear in English and is flagged to ' . $locale['reviewer'] . ' before submission.'
                : 'the wording is signed off by ' . $locale['reviewer'] . '.');

        return 'Headings and narrative for ' . $fd['funder'] . ' (' . $fd['ref'] . ') are translated into ' . $locale['native']
            . '. Figures, dates and the currency stay in the reporting locale (' . I18nLib::REPORTING_LOCALE . ' · ' . I18nLib::REPORTING_CURRENCY
            . ') so the report still ties to the ledger — ' . $tail;
    }
}
