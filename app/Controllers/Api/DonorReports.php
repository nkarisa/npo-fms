<?php

namespace App\Controllers\Api;

use App\Libraries\I18n as I18nLib;
use App\Libraries\Prototype;
use App\Repositories\DonorReportRepository;

class DonorReports extends BaseApiController
{
    private static function cumOf(array $r): float
    {
        return array_sum(array_map(fn ($l) => $l['cumulative'], $r['lines']));
    }

    private static function reportedOf(array $r): float
    {
        return self::cumOf($r) + $r['reportedAdj'];
    }

    public function index()
    {
        $all    = (new DonorReportRepository())->all();
        $status = $this->request->getGet('status') ?: 'All';
        $q      = strtolower(trim($this->request->getGet('q') ?? ''));

        $filtered = array_values(array_filter($all, function ($r) use ($status, $q) {
            if ($status !== 'All' && $r['status'] !== $status) {
                return false;
            }
            if ($q !== '' && !str_contains(strtolower($r['ref'] . ' ' . $r['title'] . ' ' . $r['funder'] . ' ' . $r['grant'] . ' ' . $r['period']), $q)) {
                return false;
            }
            return true;
        }));

        $rows = array_map(function ($r) {
            $rep = self::reportedOf($r);
            $act = self::cumOf($r);
            return [
                'ref' => $r['ref'], 'title' => $r['title'], 'funder' => $r['funder'], 'grant' => $r['grant'],
                'period' => $r['period'], 'type' => $r['type'], 'status' => $r['status'],
                'reported' => Prototype::fmt($rep), 'actual' => Prototype::fmt($act), 'tied' => $rep === $act,
                'due' => substr($r['due'], 0, 6), 'dueSoon' => $r['dueDays'] <= 30,
            ];
        }, $filtered);

        $untied = array_values(array_filter($all, fn ($r) => self::reportedOf($r) !== self::cumOf($r)));
        $open   = array_values(array_filter($all, fn ($r) => in_array($r['status'], ['Draft', 'In review', 'Overdue'], true)));
        $due30  = array_values(array_filter($all, fn ($r) => $r['dueDays'] <= 30 && in_array($r['status'], ['Draft', 'In review', 'Overdue'], true)));

        return $this->json([
            'rows'  => $rows,
            'total' => count($all),
            'tabs'  => array_map(fn ($s) => ['label' => $s, 'count' => $s === 'All' ? count($all) : count(array_filter($all, fn ($r) => $r['status'] === $s))], ['All', 'Draft', 'In review', 'Submitted', 'Queried', 'Overdue', 'Accepted']),
            'stats' => [
                ['label' => 'Open reports', 'value' => (string) count($open), 'note' => 'draft, in review or overdue'],
                ['label' => 'Due within 30 days', 'value' => (string) count($due30), 'note' => count($due30) ? 'action needed' : 'nothing imminent'],
                ['label' => 'Overdue', 'value' => (string) count(array_filter($all, fn ($r) => $r['status'] === 'Overdue')), 'note' => 'blocking disbursement'],
                ['label' => 'Not reconciled', 'value' => (string) count($untied), 'note' => 'cannot be submitted'],
            ],
        ]);
    }

    public function show($ref)
    {
        foreach ((new DonorReportRepository())->all() as $r) {
            if ($r['ref'] === $ref) {
                $r['cumulative'] = self::cumOf($r);
                $r['reported']   = self::reportedOf($r);
                return $this->json($r);
            }
        }
        return $this->response->setStatusCode(404)->setJSON(['error' => 'not found']);
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
     * document a funder will hold ELOG to.
     */
    private function coverPreview(string $funder): array
    {
        $repo    = new DonorReportRepository();
        $funders = $repo->funderLanguages();
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
