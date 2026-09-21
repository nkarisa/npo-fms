<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * VAT and withholding tax rates, as the Finance Act sets them and Settings → Taxes
 * records them. Each rate is dated: a bill takes the rates in force on its invoice
 * date, and a change is a new row from the day it takes effect, so a bill already
 * captured keeps the VAT and withholding it was entered with.
 */
final class TaxRepository extends Repository
{
    public const TAXES = ['vat' => 'VAT', 'wht' => 'Withholding tax'];

    /** The VAT rate in force on a date, in %. */
    public function vat(string $on): float
    {
        $rates = $this->inForce('vat', $on);

        return $rates === []
            ? throw new RuleViolation('There is no VAT rate in force on ' . self::dmy($on) . '. Set one in Settings → Taxes before capturing a bill dated then.')
            : $rates[0];
    }

    /**
     * The withholding rates a bill dated then may carry, in %, lowest first. Nil
     * is always one: a supplier exempt, or a payment outside withholding.
     *
     * @return list<int|float>
     */
    public function wht(string $on): array
    {
        return [0, ...array_map(self::num(...), $this->inForce('wht', $on))];
    }

    /**
     * What Settings → Taxes shows: the rates in force today, a change already set
     * for a later date, and every rate that has applied.
     */
    public function schedule(): array
    {
        $today = Clock::date();
        $rows = $this->rows('SELECT * FROM {tax_rates} ORDER BY tax DESC, effective_from DESC, rate_pct');
        $since = fn (string $tax) => $this->value(
            'SELECT MAX(effective_from) FROM {tax_rates} WHERE tax = ? AND effective_from <= ?', [$tax, $today]
        );
        $next = function (string $tax) use ($today) {
            $from = $this->value('SELECT MIN(effective_from) FROM {tax_rates} WHERE tax = ? AND effective_from > ?', [$tax, $today]);

            return $from === null ? null : ['from' => self::dmy($from), 'rates' => $this->labelled($tax, $from)];
        };

        return [
            'vat'      => $this->labelled('vat', $today)[0] ?? null,
            'wht'      => $this->labelled('wht', $today),
            'since'    => ['vat' => self::dmy($since('vat')), 'wht' => self::dmy($since('wht'))],
            'next'     => ['vat' => $next('vat'), 'wht' => $next('wht')],
            'today'    => $today,
            'history'  => array_map(static fn ($r) => [
                'tax' => self::TAXES[$r['tax']], 'rate' => self::num($r['rate_pct']), 'label' => $r['label'],
                'from' => self::dmy($r['effective_from']), 'to' => self::dmy($r['effective_to'], ''),
            ], $rows),
        ];
    }

    /**
     * Every rate with its dates, for the bill form to take the ones in force on the
     * invoice date as it is typed.
     *
     * @return list<array{tax: string, rate: int|float, label: string, from: string, to: ?string}>
     */
    public function forForm(): array
    {
        return array_map(static fn ($r) => [
            'tax' => $r['tax'], 'rate' => self::num($r['rate_pct']), 'label' => $r['label'], 'from' => $r['effective_from'], 'to' => $r['effective_to'],
        ], $this->rows('SELECT * FROM {tax_rates} ORDER BY tax, effective_from, rate_pct'));
    }

    /**
     * Replaces the rates of one tax from a date on: what was in force then ends the
     * day before, and anything set for that date or later is superseded. Callers
     * check the change and run it inside their own transaction.
     *
     * @param list<array{rate: int|float, label: string}> $rates
     */
    public function change(string $tax, array $rates, string $from): void
    {
        $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
        $now = Clock::timestamp();

        $this->db->query($this->sql('DELETE FROM {tax_rates} WHERE tax = ? AND effective_from >= ?'), [$tax, $from]);
        $this->db->query(
            $this->sql('UPDATE {tax_rates} SET effective_to = ?, updated_at = ? WHERE tax = ? AND (effective_to IS NULL OR effective_to >= ?)'),
            [$dayBefore, $now, $tax, $from]
        );
        foreach ($rates as $r) {
            $this->insert('tax_rates', ['tax' => $tax, 'rate_pct' => $r['rate'], 'label' => $r['label'], 'effective_from' => $from, 'created_at' => $now]);
        }
    }

    /**
     * The rates of one tax in force on a date with what each applies to.
     *
     * @return list<array{rate: int|float, label: string}>
     */
    public function labelled(string $tax, string $on): array
    {
        return array_map(static fn ($r) => ['rate' => self::num($r['rate_pct']), 'label' => $r['label']], $this->rows(
            'SELECT rate_pct, label FROM {tax_rates} WHERE tax = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY rate_pct',
            [$tax, $on, $on]
        ));
    }

    /**
     * The rates of one tax in force on a date, lowest first; empty when none is.
     *
     * @return list<float>
     */
    public function inForce(string $tax, string $on): array
    {
        return $this->cached("{$tax}:{$on}", fn () => array_map('floatval', array_column($this->rows(
            'SELECT rate_pct FROM {tax_rates} WHERE tax = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY rate_pct',
            [$tax, $on, $on]
        ), 'rate_pct')));
    }
}
