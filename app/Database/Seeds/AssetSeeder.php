<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use CodeIgniter\Database\Seeder;

/**
 * Asset classes, locations, the fixed asset register, the one disposal, and the
 * half-year verification count.
 *
 * Sources: ASSETS (the register), AV_ASSETS and AV_INITIAL (the count).
 *
 * The count lists twelve assets the register does not. They are added to the
 * register at their carrying amount as deemed cost, dated at the start of FY2026
 * and coded to the General Fund, because the prototype gives no original cost,
 * acquisition date or funding for them. Their useful life is their class default.
 */
class AssetSeeder extends Seeder
{
    /** [tag prefix, default useful life in years, cost account]. Furniture and plant do not appear in the register; their lives are defaults. */
    private const CLASSES = [
        'Motor vehicles'     => ['VEH', 5, '1310'],
        'Computer equipment' => ['IT', 3, '1320'],
        'Office equipment'   => ['EQP', 5, '1320'],
        'Furniture'          => ['FF', 5, '1320'],
        'Plant'              => ['GN', 10, '1320'],
    ];

    /** Class names the count uses for register classes. */
    private const CLASS_ALIASES = ['IT equipment' => 'Computer equipment'];

    /** The office each count location belongs to. */
    private const LOCATION_ENTITIES = [
        'Secretariat — Nairobi'     => 'ELOG-NS',
        'Rift Valley office'        => 'ELOG-RV',
        'Western office — Kakamega' => 'ELOG-WST',
        'Coast office — Mombasa'    => 'ELOG-CST',
    ];

    private const DEEMED_COST_DATE = '2026-01-01';

    private const COUNT_OPENED = '2026-08-28';

    private const ROUND = 'AV-2026-02';

    public function run(): void
    {
        $ctx = SeedContext::get();
        $now = $ctx->now();

        foreach (self::CLASSES as $name => [$prefix, $life, $costAccount]) {
            $ctx->remember('asset_classes', $name, $ctx->insert('asset_classes', [
                'name' => $name, 'tag_prefix' => $prefix, 'useful_life_years' => $life, 'cost_account_id' => $ctx->accountId($costAccount),
                'accumulated_depreciation_account_id' => $ctx->accountId('1390'), 'depreciation_expense_account_id' => $ctx->accountId('5350'),
                'created_at' => $now,
            ]));
        }

        foreach ($ctx->data('ASSETS') as $a) {
            $this->seedRegisterAsset($ctx, $a, $now);
        }

        foreach ($ctx->data('AV_ASSETS') as $a) {
            $class = self::CLASS_ALIASES[$a['cls']] ?? $a['cls'];
            $ctx->remember('assets', $a['tag'], $ctx->insert('assets', [
                'entity_id' => $ctx->entityId(self::LOCATION_ENTITIES[$a['location']]), 'tag' => $a['tag'], 'description' => $a['desc'],
                'asset_class_id' => $ctx->require('asset_classes', $class), 'acquired_on' => self::DEEMED_COST_DATE, 'cost' => $a['nbv'],
                'useful_life_years' => self::CLASSES[$class][1], 'fund_id' => $ctx->fundId('General Fund'),
                'programme_id' => $ctx->programmeId('Shared services'), 'location_id' => $this->location($ctx, $a['location'], $now),
                'custodian' => $a['custodian'], 'custodian_user_id' => $ctx->userId($a['custodian']), 'status' => 'in_use', 'created_at' => $now,
            ]));
        }

        $round = $ctx->insert('verification_rounds', [
            'entity_id' => $ctx->entityId(), 'reference' => self::ROUND, 'name' => 'Half-year asset count',
            'opened_on' => self::COUNT_OPENED, 'status' => 'counting', 'opened_by' => $ctx->systemUserId(), 'created_at' => $now,
        ]);

        foreach ($ctx->data('AV_INITIAL') as $tag => $result) {
            $ctx->insert('verification_results', [
                'verification_round_id' => $round, 'asset_id' => $ctx->require('assets', $tag),
                'result' => str_replace(' ', '_', strtolower($result['result'])), 'note' => $result['note'] !== '' ? $result['note'] : null,
                'counted_by' => $ctx->systemUserId(), 'counted_at' => self::COUNT_OPENED . ' 00:00:00', 'created_at' => $now,
            ]);
        }
    }

    private function seedRegisterAsset(SeedContext $ctx, array $a, string $now): void
    {
        $grant    = $ctx->grantId($a['grant']);
        $disposed = $a['status'] === 'Disposed';

        $id = $ctx->insert('assets', [
            'entity_id' => $ctx->entityId(), 'tag' => $a['tag'], 'description' => $a['name'],
            'asset_class_id' => $ctx->require('asset_classes', $a['cls']), 'acquired_on' => $ctx->date($a['acquired']), 'cost' => $a['cost'],
            'useful_life_years' => $a['life'], 'opening_accumulated_depreciation' => $a['accum'],
            'fund_id' => $ctx->fundId($a['fund'], $grant, $a['program'], $a['costAcct']), 'programme_id' => $ctx->programmeId($a['program']),
            'grant_id' => $grant, 'title_condition' => $a['title'],
            'location_id' => $disposed ? null : $this->location($ctx, $a['location'], $now),
            'custodian' => $a['custodian'] === '—' ? null : $a['custodian'], 'custodian_user_id' => $ctx->userId($a['custodian']),
            // "Fully depreciated" is derived from accumulated depreciation.
            'status' => $disposed ? 'disposed' : 'in_use', 'document_ref' => $a['doc'], 'created_at' => $now,
        ]);
        $ctx->remember('assets', $a['tag'], $id);

        if ($disposed) {
            // Approved by the board, which has no system account; no disposal journal was posted.
            $approval = $ctx->trailEntry($a['trail'], '/approved disposal/i');
            $ctx->insert('asset_disposals', [
                'asset_id' => $id, 'disposed_on' => $ctx->date($a['disposed']), 'method' => 'sale', 'proceeds' => $a['proceeds'],
                'carrying_amount' => $a['cost'] - $a['accum'], 'reason' => $approval['what'] ?? $a['title'], 'status' => 'approved',
                'requested_by' => $ctx->systemUserId(), 'approved_at' => $approval['when'] ?? null, 'created_at' => $now,
            ]);
        }

        $ctx->writeTrail('asset', $id, $a['tag'], $a['trail'], $ctx->entityId());
    }

    private function location(SeedContext $ctx, string $name, string $now): int
    {
        $entity = $ctx->entityId(self::LOCATION_ENTITIES[$name] ?? 'ELOG-NS');

        return $ctx->lookup('locations', "{$entity}:{$name}") ?? (function () use ($ctx, $entity, $name, $now) {
            $id = $ctx->insert('locations', ['entity_id' => $entity, 'name' => $name, 'created_at' => $now]);
            $ctx->remember('locations', "{$entity}:{$name}", $id);

            return $id;
        })();
    }
}
