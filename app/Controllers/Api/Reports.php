<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Repositories\Lookups;
use App\Repositories\PeriodRepository;
use App\Repositories\ReportRepository;

/**
 * Reports (v5): the statement of financial position, of activities, of cash flows
 * and the trial balance, for a period, against the prior year or the approved
 * budget, from ReportRepository. Each states its basis and whether its months are
 * closed; each can be exported.
 */
class Reports extends BaseApiController
{
    private const TABS = [
        'Statement of financial position' => 'Financial position',
        'Statement of activities'         => 'Activities',
        'Statement of cash flows'         => 'Cash flows',
        'Trial balance'                   => 'Trial balance',
    ];

    private const BLURBS = [
        'Statement of financial position' => 'Assets, liabilities and fund balances at the reporting date, drawn straight from the chart of accounts.',
        'Statement of activities'         => 'Income and expenditure for the period, split between unrestricted and restricted funds so donor money is never mixed with core.',
        'Statement of cash flows'         => 'Movement in cash for the period, reconciled from surplus to closing bank and M-Pesa balances.',
        'Trial balance'                   => 'Every active postable account with its debit or credit balance — the check that the ledger holds together.',
    ];

    public function index()
    {
        $view = $this->view();

        return $this->json($view);
    }

    /** The statement on screen, as a spreadsheet: every figure unformatted, the notes beneath. */
    public function export()
    {
        $view = $this->view();
        if (isset($view['empty'])) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $view['empty']]);
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, [$view['heading']]);
        fputcsv($out, [$view['sub']]);
        fputcsv($out, [$view['basis']]);
        fputcsv($out, []);
        fputcsv($out, ['Code', $view['labelColumn'] ?: 'Line', ...$view['columns']]);
        foreach ($view['sections'] as $section) {
            if ($section['heading'] !== null) {
                fputcsv($out, ['', $section['heading']]);
            }
            foreach ($section['rows'] as $row) {
                fputcsv($out, [$row['code'] ?? '', $row['label'], ...array_map(static fn ($c) => $c['v'] === null ? '' : $c['v'], $row['cells'])]);
            }
        }
        fputcsv($out, []);
        foreach ($view['notes'] as $note) {
            fputcsv($out, ['', $note]);
        }
        rewind($out);
        // UTF-8 BOM so spreadsheet apps read "—" correctly.
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . Brand::current()['name'] . ' ' . mb_strtolower($view['report']) . ' ' . $view['period'] . '.csv"')
            ->setBody($csv);
    }

    private function view(): array
    {
        $repo = new ReportRepository();
        $periods = $repo->periods();
        $report = array_key_exists((string) $this->request->getGet('report'), self::TABS) ? (string) $this->request->getGet('report') : array_key_first(self::TABS);
        $tabs = array_map(static fn ($k) => ['key' => $k, 'label' => self::TABS[$k]], array_keys(self::TABS));
        if ($periods === []) {
            return ['empty' => 'There are no accounting periods yet, so there is nothing to report on. Set up the financial year in Settings first.',
                'report' => $report, 'tabs' => $tabs, 'title' => $report, 'blurb' => self::BLURBS[$report]];
        }

        $range = current(array_filter($periods, fn ($p) => $p['label'] === $this->request->getGet('period'))) ?: $periods[0];
        $split = $this->request->getGet('split') !== '0';
        $s = $repo->statement($report, $range, (string) ($this->request->getGet('compare') ?: 'Prior year'), $split);

        $activities = $report === 'Statement of activities';
        $asAt = in_array($report, ['Statement of financial position', 'Trial balance'], true);
        $currency = $this->currency();
        $held = $s['source'] !== null;
        $comparative = $s['comparative'] === null ? ''
            : ' · comparative: ' . $s['comparative']['label'] . ($s['comparative']['source'] === 'legacy' ? ' (legacy system)' : ($s['comparative']['held'] ? '' : ', not held'));
        $budget = $s['budget'] === null ? '' : ' · comparative: approved budget' . ($s['budget']['held'] ? '' : ', none approved');
        $open = $s['openPeriods'];

        $notes = $s['notes'];
        if (!$held) {
            array_unshift($notes, 'Nothing is held for ' . $range['label'] . ' — neither postings on this ledger nor figures carried from a previous system.');
        }
        if ($s['comparative'] !== null && !$s['comparative']['held']) {
            $notes[] = 'No figures are held for ' . $s['comparative']['label'] . ', so the comparative column is blank.';
        } elseif (($s['comparative']['source'] ?? null) === 'legacy') {
            $notes[] = 'Comparatives for ' . $s['comparative']['label'] . ' are the figures kept in the previous system, carried across when the ledger started; they close on the balances this ledger brought forward.';
        }
        if ($s['source'] === 'legacy') {
            $notes[] = $range['label'] . ' was kept in the previous system: these are the figures carried across when the ledger started, and there are no postings to open.';
        }

        return [
            'report'   => $report,
            'tabs'     => $tabs,
            'title'    => $report,
            'blurb'    => self::BLURBS[$report],
            'heading'  => (new Lookups())->organisationNames()['short'] . ' — ' . $report,
            'sub'      => ($asAt ? 'As at ' . date('j M Y', strtotime($range['to'])) : 'For the period ' . date('j M', strtotime($range['from'])) . ' – ' . date('j M Y', strtotime($range['to'])))
                . $comparative . $budget . ' · all figures in ' . $currency,
            'periods'  => array_column($periods, 'label'),
            'period'   => $range['label'],
            'compares' => $activities ? ReportRepository::COMPARATIVES : ['Prior year', 'None'],
            'compare'  => $s['compare'],
            'showSplit' => $activities,
            'split'    => $split,
            'columns'  => $s['columns'],
            'labelColumn' => $s['labelColumn'],
            'sections' => $s['sections'],
            'notes'    => $notes,
            'balanced' => $s['balanced'] ?? true,
            'source'   => $s['source'],
            'closed'   => $s['closed'],
            'drillPeriod' => $s['source'] === 'ledger' ? $this->ledgerPeriod($range) : null,
            'hint'     => $this->hint($report, $s),
            'footer'   => $report . ' · ' . $range['label'] . ' · ' . $s['footer'],
            'basis'    => 'Prepared under IFRS · ' . $currency . ' functional currency · '
                . ($s['source'] === 'legacy' ? 'figures carried from the previous system'
                    : ($s['closed'] ? 'period closed' : 'unaudited management figures — ' . implode(', ', $open) . (count($open) === 1 ? ' is' : ' are') . ' open')),
        ];
    }

    private function hint(string $report, array $s): string
    {
        if ($s['source'] === 'legacy') {
            return 'Figures from the previous system';
        }
        if ($s['source'] === null) {
            return 'Nothing held for this period';
        }

        return match ($report) {
            'Trial balance'                   => $s['balanced'] ? 'Ledger in balance' : 'Ledger out of balance',
            'Statement of financial position' => $s['balanced'] ? 'Statement balances' : 'Statement does not balance',
            'Statement of cash flows'         => $s['balanced'] ? 'Click any line to open its postings' : 'Closing cash does not agree',
            default                           => $s['split'] ? 'Restricted and unrestricted shown separately' : 'Click any line to open its postings',
        };
    }

    /** The general ledger's name for the same months, so a line opens its postings for the period it reports. */
    private function ledgerPeriod(array $range): ?string
    {
        foreach ((new PeriodRepository())->ledgerRanges() as $label => [$from, $to]) {
            if ($from === $range['from'] && $to === $range['to']) {
                return $label;
            }
        }

        return null;
    }

    private function currency(): string
    {
        $lookups = new Lookups();
        $row = db_connect()->table('entities')->select('functional_currency')->where('id', $lookups->entityId())->get()->getRowArray();

        return $row['functional_currency'] ?? 'KES';
    }
}
