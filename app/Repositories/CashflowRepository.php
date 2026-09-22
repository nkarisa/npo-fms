<?php

namespace App\Repositories;

/**
 * The latest thirteen-week cashflow forecast.
 *
 * Each entity prepares its own. In the consolidated view the latest forecast of
 * every entity in scope is added together, week by week, so the page answers for
 * the organisation rather than for whichever entity prepared one last.
 */
final class CashflowRepository extends Repository
{
    /**
     * @return array{opening: float, restricted: float, startsOn: ?string, accounts: list<string>, weeks: list<array>}
     */
    public function latest(): array
    {
        return $this->cached('latest', function () {
            $latest = [];
            foreach ($this->rows('SELECT * FROM {cashflow_forecasts} ORDER BY starts_on DESC, id DESC') as $f) {
                $latest[$f['entity_id']] ??= $f;
            }
            if ($latest === []) {
                return ['opening' => 0, 'restricted' => 0, 'startsOn' => null, 'accounts' => [], 'weeks' => []];
            }

            $ids = array_map(static fn ($f) => (int) $f['id'], array_values($latest));
            $weeks = [];
            foreach ($this->rows('SELECT * FROM {cashflow_forecast_weeks} WHERE cashflow_forecast_id IN (' . implode(', ', $ids) . ') ORDER BY week_commencing, id') as $w) {
                $on = $w['week_commencing'];
                $weeks[$on] ??= ['on' => $on, 'wc' => self::dm($on), 'inflow' => 0, 'outflow' => 0, 'notes' => [], 'grant' => false];
                $weeks[$on]['inflow'] += self::num($w['inflow']);
                $weeks[$on]['outflow'] += self::num($w['outflow']);
                $weeks[$on]['grant'] = $weeks[$on]['grant'] || (bool) $w['is_grant_receipt'];
                if (($w['note'] ?? '') !== '') {
                    $weeks[$on]['notes'][] = $w['note'];
                }
            }

            return [
                'opening'    => array_sum(array_map(static fn ($f) => self::num($f['opening_balance']), $latest)),
                'restricted' => array_sum(array_map(static fn ($f) => self::num($f['restricted_balance']), $latest)),
                'startsOn'   => min(array_column($latest, 'starts_on')),
                'accounts'   => $this->cashAccounts(),
                'weeks'      => array_map(static function ($w) {
                    $w['note'] = implode('; ', $w['notes']);
                    unset($w['notes']);

                    return $w;
                }, array_values($weeks)),
            ];
        });
    }

    /** The bank and mobile-money accounts the opening cash is held in; petty cash is too small to plan on. */
    private function cashAccounts(): array
    {
        return array_column($this->rows(
            "SELECT short_name FROM {bank_accounts} WHERE status = 'active' AND kind IN ('bank', 'mobile_money') ORDER BY account_id"
        ), 'short_name');
    }
}
