<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Entities, users, roles and the approval policy.
 *
 * Approval thresholds and limits are data, not code (README, Security): a role's
 * ceiling and the approver for each document type are rows an administrator edits.
 */
class CreateOrganisationAndAccess extends SchemaMigration
{
    public function up(): void
    {
        $this->table('locales', [
            'id'          => $this->id(),
            'code'        => $this->string(10),
            'label'       => $this->string(60),
            'native_name' => $this->string(60),
            'direction'   => $this->string(3, false, 'ltr'),
            'is_source'   => $this->bool(),
            'status'      => $this->string(12, false, 'draft'),
            'reviewer'    => $this->string(120, true),
        ] + $this->timestamps(), [
            'unique' => ['code'],
            'checks' => [
                'direction' => $this->in('direction', ['ltr', 'rtl']),
                'status'    => $this->in('status', ['source', 'draft', 'in_review', 'published']),
            ],
        ]);

        $this->table('entities', [
            'id'                  => $this->id(),
            'parent_id'           => $this->fk(true),
            'code'                => $this->string(20),
            'name'                => $this->string(120),
            'type'                => $this->string(40),
            'functional_currency' => $this->currency(),
            'status'              => $this->string(10, false, 'live'),
        ] + $this->timestamps(), [
            'unique' => ['code'],
            'fks'    => ['parent_id' => 'entities'],
            'checks' => ['status' => $this->in('status', ['live', 'dormant'])],
        ]);

        $this->table('roles', [
            'id'           => $this->id(),
            'name'         => $this->string(60),
            'description'  => $this->text(),
            'is_read_only' => $this->bool(),
        ] + $this->timestamps(), [
            'unique' => ['name'],
        ]);

        $this->table('permissions', [
            'id'          => $this->id(),
            'key'         => $this->string(80),
            'description' => $this->string(255),
        ], [
            'unique' => ['key'],
        ]);

        $this->table('role_permissions', [
            'role_id'       => $this->fk(),
            'permission_id' => $this->fk(),
        ], [
            'primary' => ['role_id', 'permission_id'],
            'keys'    => ['permission_id'],
            'fks'     => ['role_id' => ['roles', 'CASCADE'], 'permission_id' => ['permissions', 'CASCADE']],
        ]);

        $this->table('users', [
            'id'              => $this->id(),
            'email'           => $this->string(190),
            'name'            => $this->string(120),
            'short_name'      => $this->string(60),
            'initials'        => $this->string(4),
            'password_hash'   => $this->string(255, true),
            // MFA is mandatory for anyone who can approve or post; the secret is
            // encrypted by the application before it is stored.
            'mfa_secret'      => $this->text(),
            'mfa_enabled'     => $this->bool(),
            'locale_id'       => $this->fk(true),
            'status'          => $this->string(10, false, 'invited'),
            'invited_at'      => $this->datetime(),
            'last_sign_in_at' => $this->datetime(),
            'last_sign_in_from' => $this->string(80, true),
        ] + $this->timestamps(), [
            'unique' => ['email'],
            'fks'    => ['locale_id' => 'locales'],
            'checks' => ['status' => $this->in('status', ['invited', 'active', 'suspended'])],
        ]);

        // A user's role is held per entity, so the same person can approve at the
        // secretariat and only view a related trust.
        $this->table('user_entity_roles', [
            'id'        => $this->id(),
            'user_id'   => $this->fk(),
            'entity_id' => $this->fk(),
            'role_id'   => $this->fk(),
        ] + $this->timestamps(), [
            'unique' => [['user_id', 'entity_id', 'role_id']],
            'keys'   => ['entity_id', 'role_id'],
            'fks'    => ['user_id' => ['users', 'CASCADE'], 'entity_id' => 'entities', 'role_id' => 'roles'],
        ]);

        $documentTypes = ['journal', 'bill', 'payment_run', 'subgrant', 'transfer', 'budget_revision',
            'requisition', 'purchase_order', 'payroll_run', 'advance', 'asset_disposal', 'period_reopen'];

        // Who approves a document above what amount, and who it escalates to.
        $this->table('approval_rules', [
            'id'                 => $this->id(),
            'entity_id'          => $this->fk(),
            'document_type'      => $this->string(20),
            'label'              => $this->string(60),
            'threshold'          => $this->money(),
            'approver_role_id'   => $this->fk(),
            'escalation_role_id' => $this->fk(true),
            // Escalations that are not a system role ("Board minute required").
            'escalation_note'    => $this->string(120, true),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'document_type']],
            'fks'    => ['entity_id' => 'entities', 'approver_role_id' => 'roles', 'escalation_role_id' => 'roles'],
            'checks' => [
                'type'      => $this->in('document_type', $documentTypes),
                'threshold' => 'threshold >= 0',
            ],
        ]);

        // The most a role may approve in one transaction; a null document type
        // applies to every type without its own row, and a null ceiling means no
        // ceiling. A role with no row cannot approve.
        $this->table('approval_limits', [
            'id'            => $this->id(),
            'role_id'       => $this->fk(),
            'document_type' => $this->string(20, true),
            'ceiling'       => $this->money(true),
        ] + $this->timestamps(), [
            'unique' => [['role_id', 'document_type']],
            'fks'    => ['role_id' => ['roles', 'CASCADE']],
            'checks' => [
                'type'    => 'document_type IS NULL OR ' . $this->in('document_type', $documentTypes),
                'ceiling' => 'ceiling IS NULL OR ceiling >= 0',
            ],
        ]);

        // Control switches (reject unbalanced journals, two-person rule, …).
        $this->table('settings', [
            'id'        => $this->id(),
            'entity_id' => $this->fk(),
            'key'       => $this->string(60),
            'label'     => $this->string(120),
            'note'      => $this->text(),
            'value'     => $this->string(255),
        ] + $this->timestamps(), [
            'unique' => [['entity_id', 'key']],
            'fks'    => ['entity_id' => 'entities'],
        ]);

        // The coding dimensions every posting carries, and whether each is required.
        $this->table('segments', [
            'id'          => $this->id(),
            'key'         => $this->string(30),
            'name'        => $this->string(60),
            'example'     => $this->string(255, true),
            'is_required' => $this->bool(),
            'is_reported' => $this->bool(),
            'applies_to'  => $this->string(60),
        ] + $this->timestamps(), [
            'unique' => ['key'],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['segments', 'settings', 'approval_limits', 'approval_rules', 'user_entity_roles',
            'users', 'role_permissions', 'permissions', 'roles', 'entities', 'locales']);
    }
}
