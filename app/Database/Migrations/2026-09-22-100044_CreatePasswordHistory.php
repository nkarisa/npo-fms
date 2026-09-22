<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * The passwords each person has used before, so the password policy (Settings →
 * Users) can refuse one of the last few again. Only the hashes are kept — the
 * same bcrypt hashes users.password_hash held — and only as many as the policy
 * can ever ask about (App\Libraries\PasswordPolicy::HISTORY_KEPT).
 */
class CreatePasswordHistory extends SchemaMigration
{
    public function up(): void
    {
        $this->table('password_history', [
            'id'            => $this->id(),
            'user_id'       => $this->fk(),
            'password_hash' => $this->string(255),
            'created_at'    => $this->datetime(),
        ], [
            'keys' => ['user_id'],
            'fks'  => ['user_id' => ['users', 'CASCADE']],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['password_history']);
    }
}
