<?php

namespace App\Repositories;

use App\Libraries\Clock;
use IntlDateFormatter;

/**
 * Donor reports, and the delivery language each funder's agreement requires.
 *
 * A draft past its due date is overdue. The figures on a report are the snapshot
 * taken for it; the figures on a cover preview come from the funder's awards and
 * the ledger today.
 */
final class DonorReportRepository extends Repository
{
    private const TYPE_LABELS = ['close_out' => 'Close-out'];

    /** The word between the two dates of a reporting period, by language. */
    private const PERIOD_CONNECTORS = ['en-GB' => 'to', 'fr' => 'au', 'es' => 'al', 'ar' => 'إلى', 'sw' => 'hadi'];

    /** Cover page wording in the source language; translations come from the catalogue. */
    private const COVER = [
        'prefix'    => 'Expenditure report',
        'awardWord' => 'Award',
        'labels'    => ['Award ceiling', 'Expenditure this period', 'Cumulative expenditure', 'Balance on the award'],
        'foot'      => 'Prepared from posted ledger actuals. Figures in KES.',
        'org'       => 'ELOG Kenya · Elections Observation Group',
    ];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function all(): array
    {
        return $this->cached('all', function () {
            $children = fn (string $sql) => array_reduce($this->rows($sql), static function ($out, $r) {
                $out[(int) $r['donor_report_id']][] = $r;

                return $out;
            }, []);
            $lines   = $children('SELECT l.*, a.code FROM {donor_report_lines} l JOIN {accounts} a ON a.id = l.account_id ORDER BY l.id');
            $checks  = $children('SELECT * FROM {donor_report_checks} ORDER BY sort_order');
            $queries = $children('SELECT * FROM {donor_report_queries} ORDER BY raised_on');
            $trails  = $this->trails('donor_report');

            $documents = (new AttachmentRepository())->byObject('donor_report');

            return array_map(function ($r) use ($lines, $checks, $queries, $trails, $documents) {
                $id = (int) $r['id'];

                return [
                    'ref'         => $r['reference'],
                    'title'       => $r['title'],
                    'funder'      => $r['funder_name'],
                    'grant'       => $r['short_name'],
                    'grantRef'    => $r['award_ref'],
                    'period'      => self::periodLabel($r['period_starts_on'], $r['period_ends_on']),
                    'type'        => self::label($r['type'], self::TYPE_LABELS),
                    'due'         => self::dmy($r['due_on']),
                    'dueDays'     => Clock::daysUntil($r['due_on']),
                    'status'      => self::statusLabel($r),
                    'preparer'    => $this->lookups->shortName((int) $r['prepared_by']),
                    'note'        => $r['note'] ?? '',
                    'received'    => self::num($r['funds_received']),
                    'reportedAdj' => self::num($r['reported_adjustment']),
                    'lines'       => array_map(static fn ($l) => [
                        'code' => $l['code'], 'budget' => self::num($l['budget']), 'period' => self::num($l['period_amount']), 'cumulative' => self::num($l['cumulative_amount']),
                    ], $lines[$id] ?? []),
                    'compliance'  => array_column($checks[$id] ?? [], 'text'),
                    // Recommended: the report as submitted, and the donor's acknowledgement.
                    'attachments' => $documents[$id] ?? [],
                    'queries'     => array_map(static fn ($q) => [
                        'ref' => $q['reference'], 'when' => self::dmy($q['raised_on']), 'text' => $q['text'], 'response' => $q['response'] ?? '',
                    ], $queries[$id] ?? []),
                    'trail'       => $trails[$id] ?? [],
                ];
            }, $this->rows(
                'SELECT r.*, g.award_ref, g.short_name, f.name AS funder_name FROM {donor_reports} r
                 JOIN {grants} g ON g.id = r.grant_id JOIN {funders} f ON f.id = g.funder_id ORDER BY r.due_on DESC, r.reference'
            ));
        });
    }

    public function find(string $ref): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['ref'] === $ref) {
                return $r;
            }
        }

        return null;
    }

    public static function statusLabel(array $r): string
    {
        if ($r['status'] === 'draft' && Clock::daysUntil($r['due_on']) < 0) {
            return 'Overdue';
        }

        return self::label($r['status']);
    }

    /** "Apr – Jun 26", "Jul 24 – Sep 26", "Jan – Dec 25". */
    public static function periodLabel(?string $start, ?string $end): string
    {
        if (!$start || !$end) {
            return 'Full period';
        }

        $sameYear = substr($start, 0, 4) === substr($end, 0, 4);

        return date($sameYear ? 'M' : 'M y', strtotime($start)) . ' – ' . date('M y', strtotime($end));
    }

    // ---- Delivery language ----

    /** Funders whose agreements name a delivery language, with their project titles and award figures. */
    public function funderLanguages(): array
    {
        return $this->cached('languages', function () {
            $titles = [];
            foreach ($this->rows(
                "SELECT c.object_id, c.text, l.code FROM {content_translations} c JOIN {locales} l ON l.id = c.locale_id
                 WHERE c.object_type = 'funder' AND c.field = 'project_title'"
            ) as $t) {
                $titles[(int) $t['object_id']][$t['code']] = $t['text'];
            }

            $grants = (new GrantRepository())->all();

            return array_map(function ($f) use ($titles, $grants) {
                $awards  = array_filter($grants, static fn ($g) => $g['funder'] === $f['name']);
                $ceiling = array_sum(array_column($awards, 'value'));
                $spent   = array_sum(array_column($awards, 'spent'));
                $money   = static fn (float $v) => number_format($v, 2);

                return [
                    'funder'  => $f['name'],
                    'ref'     => preg_match('/Award (\S+)/', (string) $f['report_note'], $m) === 1 ? $m[1] : (array_values($awards)[0]['ref'] ?? '—'),
                    'locale'  => $f['locale'],
                    'note'    => $f['report_note'] ?? '',
                    'project' => $titles[(int) $f['id']] ?? [],
                    'figures' => [$money($ceiling), $money($spent), $money($spent), $money($ceiling - $spent)],
                ];
            }, $this->rows(
                'SELECT f.*, l.code AS locale FROM {funders} f JOIN {locales} l ON l.id = f.report_locale_id ORDER BY f.id'
            ));
        });
    }

    public function setLanguage(string $funder, string $locale): bool
    {
        $id = $this->lookups->funderId($funder);
        $localeId = $this->value('SELECT id FROM {locales} WHERE code = ?', [$locale]);
        if ($id === null || $this->value('SELECT report_locale_id FROM {funders} WHERE id = ?', [$id]) === null || $localeId === null) {
            return false;
        }

        $this->transaction(fn () => $this->db->table('funders')->where('id', $id)->update(['report_locale_id' => $localeId, 'updated_at' => Clock::timestamp()]));

        return true;
    }

    /**
     * The cover page wording in one language. The period is the financial year to
     * the end of the last closed month, with its dates in that language.
     */
    public function cover(string $locale): array
    {
        $translations = new TranslationRepository();
        $t = static fn (string $s) => $translations->translation($locale, $s) ?? $s;

        $closed = (new PeriodRepository())->closed();
        $periods = (new Lookups())->yearPeriods();
        $start = $periods[0]['starts_on'] ?? Clock::date();
        $end = $start;
        foreach ($periods as $p) {
            if (in_array($p['name'], $closed, true)) {
                $end = $p['ends_on'];
            }
        }

        $format = static function (string $date) use ($locale) {
            $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Africa/Nairobi', null, 'd MMM y');

            return $formatter->format(strtotime($date));
        };

        return [
            'prefix'    => $t(self::COVER['prefix']),
            'awardWord' => $t(self::COVER['awardWord']),
            'period'    => $format($start) . ' ' . (self::PERIOD_CONNECTORS[$locale] ?? 'to') . ' ' . $format($end),
            'labels'    => array_map($t, self::COVER['labels']),
            'foot'      => $t(self::COVER['foot']),
            'org'       => $t(self::COVER['org']),
        ];
    }
}
