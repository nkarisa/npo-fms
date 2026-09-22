<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * settings.manage becomes a permission for each part of Settings (App\Libraries\SettingsAccess),
 * so that the duties an auditor asks about can be held apart: approval bands, the
 * M-Pesa and mail credentials and payroll each have their own. settings.view shows
 * the everyday sections read only, and audit.view the settings audit log.
 *
 * Nobody loses what they could do the day before:
 * - every role holding settings.manage gets all of the new ones;
 * - every role holding ledger.view gets settings.view, since anyone could look before;
 * - every role holding journal.approve gets settings.banking, since bank statement
 *   formats were an approver's to change;
 * - audit.view goes to the roles that held settings.manage, to those that authorise
 *   a period close (the Executive Director) and to read-only roles (the auditor).
 *   It is the one thing others lose sight of, which is the point.
 *
 * settings.manage is then removed. A database with no permissions yet gets the new
 * ones from BaselineSeeder.
 */
class SplitSettingsPermissions extends SchemaMigration
{
    /** Held as they were when this migration was written, whatever the seeder says later. */
    private const PERMISSIONS = [
        'settings.view'         => 'See the organisation, ledger, approval and bank statement settings, read only',
        'settings.organisation' => 'Change the organisation, its entities, the appearance and the language settings',
        'settings.ledger'       => 'Change the ledger, segments, currencies, taxes and terms; open funds, record awards and carry opening balances',
        'settings.approvals'    => 'Change approval bands, approvers and the procurement threshold',
        'settings.banking'      => 'Change bank statement formats and the cash accounts they are read into',
        'settings.integrations' => 'Change the M-Pesa integration and the mail server, credentials included',
        'settings.payroll'      => 'Change payroll benefits, grades and the accounts payroll pays from',
        'audit.view'            => 'Read the settings audit log',
    ];

    private const OLD = 'settings.manage';

    public function up(): void
    {
        if ($this->db->table('permissions')->countAllResults() === 0 || $this->permissionId(self::OLD) === null) {
            return;
        }

        foreach (self::PERMISSIONS as $key => $description) {
            if ($this->permissionId($key) === null) {
                $this->db->table('permissions')->insert(['key' => $key, 'description' => $description]);
            }
        }

        foreach (array_keys(self::PERMISSIONS) as $key) {
            $this->grantTo($key, "p.key = 'settings.manage'");
        }
        $this->grantTo('settings.view', "p.key = 'ledger.view'");
        $this->grantTo('settings.banking', "p.key = 'journal.approve'");
        $this->grantTo('audit.view', "p.key = 'period.authorise'");
        $audit = $this->permissionId('audit.view');
        $this->db->query($this->sql(
            'INSERT INTO {role_permissions} (role_id, permission_id)
             SELECT r.id, ? FROM {roles} r WHERE r.is_read_only = 1
             AND NOT EXISTS (SELECT 1 FROM {role_permissions} x WHERE x.role_id = r.id AND x.permission_id = ?)'
        ), [$audit, $audit]);

        $this->remove(self::OLD);
    }

    public function down(): void
    {
        if ($this->db->table('permissions')->countAllResults() === 0 || $this->permissionId('settings.organisation') === null) {
            return;
        }

        if ($this->permissionId(self::OLD) === null) {
            $this->db->table('permissions')->insert(['key' => self::OLD, 'description' => 'Change organisation, ledger and approval settings']);
        }
        $this->grantTo(self::OLD, "p.key = 'settings.organisation'");

        foreach (array_keys(self::PERMISSIONS) as $key) {
            $this->remove($key);
        }
    }

    /** Gives $key to every role holding a permission that matches $holding, once. */
    private function grantTo(string $key, string $holding): void
    {
        $id = $this->permissionId($key);
        $this->db->query($this->sql(
            "INSERT INTO {role_permissions} (role_id, permission_id)
             SELECT DISTINCT rp.role_id, ? FROM {role_permissions} rp JOIN {permissions} p ON p.id = rp.permission_id
             WHERE {$holding} AND NOT EXISTS (SELECT 1 FROM {role_permissions} x WHERE x.role_id = rp.role_id AND x.permission_id = ?)"
        ), [$id, $id]);
    }

    private function remove(string $key): void
    {
        $id = $this->permissionId($key);
        if ($id === null) {
            return;
        }
        $this->db->table('role_permissions')->where('permission_id', $id)->delete();
        $this->db->table('permissions')->where('id', $id)->delete();
    }

    private function permissionId(string $key): ?int
    {
        $row = $this->db->table('permissions')->select('id')->where('key', $key)->get()->getRow();

        return $row === null ? null : (int) $row->id;
    }
}
