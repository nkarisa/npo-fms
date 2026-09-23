<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Maintenance mode: closing the application while it is worked on, and the
 * periods that closure is planned for in advance.
 *
 * Whether the application is closed right now is one setting on the head office
 * (key `maintenance`, App\Libraries\Maintenance), like the password policy — it is
 * switched, not scheduled. A row here is the other way round: a period agreed
 * ahead of time, which everyone is told about when it is booked, warned about as
 * it approaches, and which closes the application by itself while it runs. A
 * window is never deleted, only cancelled, so what was announced and what
 * actually happened both stay on the record.
 *
 * The permission comes with the table. On an instance that is already running,
 * every role that manages users gets it, so nobody is left with an application
 * they cannot close and no way to give themselves the right to. A database with
 * no permissions yet is a fresh one, and takes it from BaselineSeeder instead.
 */
class CreateMaintenance extends SchemaMigration
{
    private const KEY = 'settings.maintenance';

    /** Held as it was when this migration was written, whatever the seeder says later. */
    private const DESCRIPTION = 'Close the application for maintenance, and schedule maintenance periods';

    public function up(): void
    {
        $this->table('maintenance_windows', [
            'id'           => $this->id(),
            'starts_at'    => $this->datetime(false),
            'ends_at'      => $this->datetime(false),
            // What the maintenance is for, in the words everyone is shown.
            'reason'       => $this->string(240),
            'created_by'   => $this->fk(),
            'cancelled_by' => $this->fk(true),
            'cancelled_at' => $this->datetime(),
            'created_at'   => $this->datetime(false),
            'updated_at'   => $this->datetime(),
        ], [
            'keys'   => ['starts_at'],
            'fks'    => ['created_by' => 'users', 'cancelled_by' => 'users'],
            'checks' => ['order' => 'ends_at > starts_at'],
        ]);

        if ($this->db->table('permissions')->countAllResults() === 0) {
            return;
        }
        if ($this->permissionId(self::KEY) === null) {
            $this->db->table('permissions')->insert(['key' => self::KEY, 'description' => self::DESCRIPTION]);
        }

        $id = $this->permissionId(self::KEY);
        $this->db->query($this->sql(
            "INSERT INTO {role_permissions} (role_id, permission_id)
             SELECT DISTINCT rp.role_id, ? FROM {role_permissions} rp JOIN {permissions} p ON p.id = rp.permission_id
             WHERE p.key = 'users.manage' AND NOT EXISTS (SELECT 1 FROM {role_permissions} x WHERE x.role_id = rp.role_id AND x.permission_id = ?)"
        ), [$id, $id]);
    }

    public function down(): void
    {
        $id = $this->permissionId(self::KEY);
        if ($id !== null) {
            $this->db->table('role_permissions')->where('permission_id', $id)->delete();
            $this->db->table('permissions')->where('id', $id)->delete();
        }

        $this->dropTables(['maintenance_windows']);
    }

    private function permissionId(string $key): ?int
    {
        $row = $this->db->table('permissions')->select('id')->where('key', $key)->get()->getRow();

        return $row === null ? null : (int) $row->id;
    }
}
