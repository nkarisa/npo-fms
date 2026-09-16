<?php

namespace App\Repositories;

use App\Libraries\Clock;

/**
 * The fixed asset register and the open verification count.
 *
 * Accumulated depreciation is what was brought forward plus every posted
 * depreciation entry; "Fully depreciated" follows from it. The count covers every
 * asset still held.
 */
final class AssetRepository extends Repository
{
    public const RESULT_LABELS = ['sighted' => 'Sighted', 'not_found' => 'Not found', 'condition_issue' => 'Condition issue'];

    private Lookups $lookups;

    public function __construct()
    {
        parent::__construct();
        $this->lookups = new Lookups();
    }

    public function register(): array
    {
        return $this->cached('register', function () {
            $trails = $this->trails('asset', 'd M Y');

            return array_map(function ($a) use ($trails) {
                $accum    = (float) $a['opening_accumulated_depreciation'] + (float) $a['depreciation'];
                $disposed = $a['status'] === 'disposed';
                $grant    = $a['grant_id'] === null ? null : $this->lookups->grants()[$a['grant_id']];

                $asset = [
                    'tag'       => $a['tag'],
                    'name'      => $a['description'],
                    'cls'       => $a['class_name'],
                    'acquired'  => self::dmy($a['acquired_on']),
                    'life'      => (int) $a['useful_life_years'],
                    'cost'      => self::num($a['cost']),
                    'accum'     => self::num($accum),
                    'costAcct'  => $a['cost_account'],
                    'fund'      => self::FUND_GROUPS[$this->lookups->funds()[$a['fund_id']]['ledger_group']],
                    'funder'    => $grant['funder_name'] ?? '—',
                    'grant'     => $grant['short_name'] ?? 'Unassigned',
                    'program'   => $this->lookups->programmeName($a['programme_id'] === null ? null : (int) $a['programme_id']),
                    'title'     => $a['title_condition'] ?? '',
                    'custodian' => $a['custodian'] ?? '—',
                    'location'  => $disposed ? 'Disposed' : ($a['location_name'] ?? '—'),
                    'status'    => match (true) {
                        $disposed                                               => 'Disposed',
                        $accum >= (float) $a['cost'] - (float) $a['residual_value'] => 'Fully depreciated',
                        default                                                 => 'In use',
                    },
                    'doc'       => $a['document_ref'] ?? '',
                    'trail'     => $trails[(int) $a['id']] ?? [],
                ];

                return $a['disposed_on'] === null
                    ? $asset
                    : $asset + ['proceeds' => self::num($a['proceeds']), 'disposed' => self::dmy($a['disposed_on'])];
            }, $this->rows(
                'SELECT a.*, c.name AS class_name, ca.code AS cost_account, l.name AS location_name,
                        d.disposed_on, d.proceeds,
                        (SELECT COALESCE(SUM(e.amount), 0) FROM {depreciation_entries} e JOIN {depreciation_runs} r ON r.id = e.depreciation_run_id
                         WHERE e.asset_id = a.id AND r.status = \'posted\') AS depreciation
                 FROM {assets} a JOIN {asset_classes} c ON c.id = a.asset_class_id JOIN {accounts} ca ON ca.id = c.cost_account_id
                 LEFT JOIN {locations} l ON l.id = a.location_id LEFT JOIN {asset_disposals} d ON d.asset_id = a.id
                 ORDER BY a.id'
            ));
        });
    }

    public function find(string $tag): ?array
    {
        foreach ($this->register() as $a) {
            if ($a['tag'] === $tag) {
                return $a;
            }
        }

        return null;
    }

    /** The open count round, or the latest one. */
    public function round(): ?array
    {
        return $this->cached('round', fn () => $this->row("SELECT * FROM {verification_rounds} ORDER BY status = 'closed', opened_on DESC LIMIT 1"));
    }

    /** Assets on the count, with carrying amount, in the shape the count sheet uses. */
    public function countSheet(): array
    {
        return $this->cached('count-sheet', fn () => array_map(fn ($a) => [
            'tag'       => $a['tag'],
            'desc'      => $a['name'],
            'cls'       => $a['cls'],
            'location'  => $a['location'],
            'custodian' => $a['custodian'],
            'nbv'       => self::num($a['cost'] - $a['accum']),
            'expected'  => 'Sighted',
        ], array_values(array_filter($this->register(), static fn ($a) => $a['status'] !== 'Disposed'))));
    }

    /** @return array<string, array{result: string, note: string}> results recorded so far, by tag */
    public function results(): array
    {
        $round = $this->round();
        if ($round === null) {
            return [];
        }

        return $this->cached('results', function () use ($round) {
            $out = [];
            foreach ($this->rows(
                'SELECT a.tag, r.result, r.note FROM {verification_results} r JOIN {assets} a ON a.id = r.asset_id WHERE r.verification_round_id = ?',
                [$round['id']]
            ) as $r) {
                $out[$r['tag']] = ['result' => self::RESULT_LABELS[$r['result']], 'note' => $r['note'] ?? ''];
            }

            return $out;
        });
    }

    /** Records (or re-records) the count result for one asset. */
    public function record(string $tag, string $result, string $note, int $actorId): array
    {
        $round = $this->round();
        $asset = $this->row('SELECT id FROM {assets} WHERE tag = ?', [$tag]);
        if ($round === null || $round['status'] === 'closed') {
            throw new RuleViolation('There is no open verification round to record against.');
        }
        if ($asset === null) {
            throw new RuleViolation($tag . ' is not on the verification list for ' . $round['reference'] . '.');
        }

        $row = [
            'result' => array_search($result, self::RESULT_LABELS, true), 'note' => $note !== '' ? $note : null,
            'counted_by' => $actorId, 'counted_at' => Clock::timestamp(), 'updated_at' => Clock::timestamp(),
        ];

        $this->transaction(function () use ($round, $asset, $row, $tag, $result, $actorId) {
            $existing = $this->value('SELECT id FROM {verification_results} WHERE verification_round_id = ? AND asset_id = ?', [$round['id'], $asset['id']]);
            $existing === null
                ? $this->insert('verification_results', $row + ['verification_round_id' => $round['id'], 'asset_id' => $asset['id'], 'created_at' => Clock::timestamp()])
                : $this->db->table('verification_results')->where('id', $existing)->update($row);
            $this->audit('asset', (int) $asset['id'], $tag, 'Counted in ' . $round['reference'] . ': ' . $result . ' (' . $this->lookups->shortName($actorId) . ')', $actorId);
        });

        return $this->results()[$tag];
    }
}
