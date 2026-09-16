<?php

namespace App\Repositories;

/** The latest thirteen-week cashflow forecast. */
final class CashflowRepository extends Repository
{
    /** @return array{opening: float, restricted: float, weeks: list<array>} */
    public function latest(): array
    {
        return $this->cached('latest', function () {
            $forecast = $this->row('SELECT * FROM {cashflow_forecasts} ORDER BY starts_on DESC, id DESC LIMIT 1');
            if ($forecast === null) {
                return ['opening' => 0, 'restricted' => 0, 'weeks' => []];
            }

            return [
                'opening'    => self::num($forecast['opening_balance']),
                'restricted' => self::num($forecast['restricted_balance']),
                'weeks'      => array_map(static fn ($w) => [
                    'wc' => self::dm($w['week_commencing']), 'inflow' => self::num($w['inflow']), 'outflow' => self::num($w['outflow']),
                    'note' => $w['note'] ?? '', 'grant' => (bool) $w['is_grant_receipt'],
                ], $this->rows('SELECT * FROM {cashflow_forecast_weeks} WHERE cashflow_forecast_id = ? ORDER BY week_commencing', [$forecast['id']])),
            ];
        });
    }
}
