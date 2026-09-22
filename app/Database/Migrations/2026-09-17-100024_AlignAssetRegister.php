<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * What the v5 asset register records that the first schema had no place for.
 *
 * - assets.capitalised: whether the asset is carried in the ledger. An item found
 *   in a physical count that was never capitalised can be counted, but it is not on
 *   the register, is not depreciated, and is left out of the tie to 1310, 1320 and
 *   1390 until it is capitalised.
 * - asset_disposals: the buyer or recipient, the board minute that approved the
 *   disposal, how a sale was made (a public auction or a direct sale, both method
 *   'sale'), and the depreciation released with the cost when it posts.
 *
 * Disposal methods and sale channels are checked by the service layer: adding a
 * CHECK to an existing table means SQLite rebuilding it, which the schema's
 * triggers and views would not survive.
 */
class AlignAssetRegister extends SchemaMigration
{
    public const SALE_CHANNELS = ['public_auction', 'direct_sale'];

    public function up(): void
    {
        $this->forge->addColumn('assets', ['capitalised' => $this->bool(true)]);
        $this->forge->addColumn('asset_disposals', [
            'sale_channel'             => $this->string(16, true),
            'buyer'                    => $this->string(120, true),
            'board_minute'             => $this->string(40, true),
            'accumulated_depreciation' => $this->money(),
        ]);
    }

    public function down(): void
    {
        // Dropped in place: Forge rebuilds the table on SQLite.
        foreach (['sale_channel', 'buyer', 'board_minute', 'accumulated_depreciation'] as $column) {
            $this->db->query($this->sql("ALTER TABLE {asset_disposals} DROP COLUMN {$column}"));
        }
        $this->db->query($this->sql('ALTER TABLE {assets} DROP COLUMN capitalised'));
    }
}
