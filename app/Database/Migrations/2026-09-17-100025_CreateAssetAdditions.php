<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * How assets come onto the register.
 *
 * - An asset bought through the ledger is capitalised from the purchase: the posted
 *   journal line that charged 1310 or 1320. The purchase already carries the entry,
 *   so capitalising posts nothing; assets.acquisition_journal_line_id records which
 *   line it came from, and one line can become several assets (checked by the
 *   service layer: a column added to an existing table cannot carry a foreign key
 *   on SQLite without rebuilding it).
 * - An asset with no purchase behind it — donated in kind, or found in a count but
 *   never recorded — is an asset_addition. It is proposed with its value, source
 *   and reason, and posts on approval by a second person: Dr the class cost
 *   account, Cr 4260 donated assets (income), or for an asset that should have
 *   been recorded before, Cr 3100 unrestricted fund balance (a correction of the
 *   earlier omission, not this year's income).
 * - 4260 Donated assets (in kind), under 4200 other income.
 */
class CreateAssetAdditions extends SchemaMigration
{
    public const BASES = ['donation', 'found'];

    public function up(): void
    {
        $this->forge->addColumn('assets', ['acquisition_journal_line_id' => $this->fk(true)]);

        $this->table('asset_additions', [
            'id'                => $this->id(),
            'entity_id'         => $this->fk(),
            'asset_id'          => $this->fk(),
            'basis'             => $this->string(10),
            'amount'            => $this->money(),
            'recognised_on'     => $this->date(),
            // The donor, or the count that found the asset.
            'source'            => $this->string(120),
            // Deed of gift, donor letter or count reference.
            'reference'         => $this->string(60, true),
            'reason'            => $this->text(false),
            'credit_account_id' => $this->fk(),
            'status'            => $this->string(16, false, 'pending_approval'),
            'requested_by'      => $this->fk(),
            'approved_by'       => $this->fk(true),
            'approved_at'       => $this->datetime(),
            'journal_id'        => $this->fk(true),
        ] + $this->timestamps(), [
            'unique' => ['asset_id'],
            'keys'   => ['entity_id', 'status'],
            'fks'    => ['entity_id' => 'entities', 'asset_id' => 'assets', 'credit_account_id' => 'accounts',
                'requested_by' => 'users', 'approved_by' => 'users', 'journal_id' => 'journals'],
            'checks' => [
                'basis'  => $this->in('basis', self::BASES),
                'status' => $this->in('status', ['pending_approval', 'posted']),
                'amount' => 'amount > 0',
                'sod'    => 'approved_by IS NULL OR approved_by <> requested_by',
            ],
        ]);

        $heading = $this->db->table('accounts')->where('code', '4200')->get()->getRowArray();
        $sibling = $this->db->table('accounts')->where('code', '4250')->get()->getRowArray();
        if ($heading !== null && $sibling !== null && $this->db->table('accounts')->where('code', '4260')->countAllResults() === 0) {
            $this->db->table('accounts')->insert([
                'code' => '4260', 'name' => 'Donated assets (in kind)', 'type' => 'income', 'parent_id' => $heading['id'], 'level' => 2,
                'is_leaf' => 1, 'restriction' => 'unrestricted', 'default_fund_id' => $sibling['default_fund_id'],
                'default_programme_id' => $sibling['default_programme_id'], 'status' => 'active', 'currency' => 'KES', 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->dropTables(['asset_additions']);
        $this->db->query($this->sql('ALTER TABLE {assets} DROP COLUMN acquisition_journal_line_id'));

        // 4260 stays if anything has posted to it: posted journals cannot change.
        $id = $this->db->table('accounts')->select('id')->where('code', '4260')->get()->getRowArray()['id'] ?? null;
        if ($id !== null && $this->db->table('journal_lines')->where('account_id', $id)->countAllResults() === 0) {
            $this->db->table('accounts')->where('id', $id)->delete();
        }
    }
}
