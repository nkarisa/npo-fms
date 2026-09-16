<?php

namespace App\Repositories;

use App\Libraries\Clock;

/** Accounting periods: which are closed, which is current, and the ranges screens filter by. */
final class PeriodRepository extends Repository
{
    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    /** @return list<string> e.g. "Aug 2026" */
    public function names(): array
    {
        return array_column($this->lookups->periods(), 'name');
    }

    /** @return list<string> */
    public function closed(): array
    {
        return array_values(array_column(array_filter($this->lookups->periods(), static fn ($p) => $p['status'] === 'closed'), 'name'));
    }

    /** The earliest open period — the one the books are working in. */
    public function current(): ?array
    {
        foreach ($this->lookups->periods() as $p) {
            if ($p['status'] === 'open') {
                return $p;
            }
        }

        return null;
    }

    public function currentName(): string
    {
        return $this->current()['name'] ?? 'none';
    }

    /** The period that contains today, falling back to the current open period. */
    public function today(): ?array
    {
        $today = Clock::date();
        foreach ($this->lookups->periods() as $p) {
            if ($p['starts_on'] <= $today && $today <= $p['ends_on']) {
                return $p;
            }
        }

        return $this->current();
    }

    /** Months of the financial year up to and including the current period. */
    public function monthsElapsed(): int
    {
        $current = $this->current();
        if ($current === null) {
            return count($this->lookups->periods());
        }

        $n = 0;
        foreach ($this->lookups->periods() as $p) {
            if ($p['fiscal_year_id'] === $current['fiscal_year_id'] && $p['starts_on'] <= $current['starts_on']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The ranges the general ledger filters by: the year to date, each completed
     * quarter, the last closed month and the current month.
     *
     * @return array<string, array{0: string, 1: string}> label → [first day, last day]
     */
    public function ledgerRanges(): array
    {
        $periods = $this->lookups->periods();
        $current = $this->current() ?? end($periods);
        $year    = substr($current['starts_on'], 0, 4);
        $inYear  = array_values(array_filter($periods, static fn ($p) => substr($p['starts_on'], 0, 4) === $year && $p['starts_on'] <= $current['starts_on']));
        $month   = static fn (array $p) => date('M', strtotime($p['starts_on']));

        $ranges = ['FY' . $year . ' · ' . $month($inYear[0]) . ' – ' . $month($current) => [$inYear[0]['starts_on'], $current['ends_on']]];

        foreach (array_chunk($inYear, 3) as $q => $quarter) {
            if (count($quarter) === 3 && end($quarter)['ends_on'] < $current['starts_on']) {
                $ranges['Q' . ($q + 1) . ' ' . $year . ' · ' . $month($quarter[0]) . ' – ' . $month($quarter[2])] = [$quarter[0]['starts_on'], $quarter[2]['ends_on']];
            }
        }

        $previous = count($inYear) > 1 ? $inYear[count($inYear) - 2] : null;
        if ($previous !== null) {
            $ranges[$previous['name']] = [$previous['starts_on'], $previous['ends_on']];
        }
        $ranges[$current['name']] = [$current['starts_on'], $current['ends_on']];

        return $ranges;
    }
}
