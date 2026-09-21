<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Signing in, with a second factor.
 *
 * - users: which second factor a person uses (an authenticator app or an emailed
 *   code), the last authenticator step accepted so a code cannot be replayed, and
 *   the count of wrong passwords that locks the account for a while. The shared
 *   secret itself already has a column (mfa_secret), encrypted with the
 *   application key before it is stored.
 * - auth_tokens: the single-use things sent by email — an invitation link, a
 *   password-reset link, a six-digit sign-in code. Only a hash is kept, so a copy
 *   of the database cannot be used to sign in.
 * - user_recovery_codes: the one-time codes that get someone in when their phone
 *   is lost. Hashed likewise.
 * - users.manage: inviting people, assigning their roles and defining roles. It
 *   goes to every role that already holds settings.manage, so nobody who managed
 *   users before this migration loses the ability. A database with no permissions
 *   yet gets it from BaselineSeeder.
 */
class CreateAuthentication extends SchemaMigration
{
    public function up(): void
    {
        // Added without constraints: altering users on SQLite would rebuild it, which
        // the schema's triggers and views would not survive. The service layer keeps
        // mfa_method to one of the methods in Config\Auth.
        $this->forge->addColumn('users', [
            'mfa_method'          => $this->string(10, true),
            'mfa_last_step'       => $this->int(true, null, 'BIGINT'),
            'failed_sign_ins'     => $this->int(false, 0),
            'locked_until'        => $this->datetime(),
            'password_changed_at' => $this->datetime(),
        ]);

        $this->table('auth_tokens', [
            'id'         => $this->id(),
            'user_id'    => $this->fk(),
            'purpose'    => $this->string(12),
            // sha256 of a link token; password_hash of a six-digit code.
            'token_hash' => $this->string(255),
            'expires_at' => $this->datetime(false),
            'attempts'   => $this->int(false, 0),
            'used_at'    => $this->datetime(),
            'created_at' => $this->datetime(),
        ], [
            'keys'   => [['user_id', 'purpose'], 'token_hash'],
            'fks'    => ['user_id' => ['users', 'CASCADE']],
            'checks' => ['purpose' => $this->in('purpose', ['invite', 'reset', 'email_code'])],
        ]);

        $this->table('user_recovery_codes', [
            'id'         => $this->id(),
            'user_id'    => $this->fk(),
            'code_hash'  => $this->string(255),
            'used_at'    => $this->datetime(),
            'created_at' => $this->datetime(),
        ], [
            'keys' => ['user_id'],
            'fks'  => ['user_id' => ['users', 'CASCADE']],
        ]);

        $this->grantUsersManage();
    }

    private function grantUsersManage(): void
    {
        $permissions = $this->db->table('permissions');
        if ($permissions->countAllResults() === 0 || $this->db->table('permissions')->where('key', 'users.manage')->countAllResults() > 0) {
            return;
        }

        $this->db->table('permissions')->insert(['key' => 'users.manage', 'description' => 'Invite users, assign their roles and define roles']);
        $id = (int) $this->db->insertID();

        $this->db->query($this->sql(
            "INSERT INTO {role_permissions} (role_id, permission_id)
             SELECT rp.role_id, ? FROM {role_permissions} rp JOIN {permissions} p ON p.id = rp.permission_id WHERE p.key = 'settings.manage'"
        ), [$id]);
    }

    public function down(): void
    {
        $this->db->query($this->sql("DELETE FROM {permissions} WHERE {$this->db->escapeIdentifiers('key')} = 'users.manage'"));
        $this->dropTables(['user_recovery_codes', 'auth_tokens']);
        foreach (['mfa_method', 'mfa_last_step', 'failed_sign_ins', 'locked_until', 'password_changed_at'] as $column) {
            // Dropped in place: Forge rebuilds the table on SQLite.
            $this->db->query($this->sql('ALTER TABLE {users} DROP COLUMN ' . $column));
        }
    }
}
