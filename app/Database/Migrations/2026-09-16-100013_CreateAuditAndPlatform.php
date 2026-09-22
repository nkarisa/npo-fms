<?php

namespace App\Database\Migrations;

use App\Database\SchemaMigration;

/**
 * Cross-cutting tables: the audit trail, document attachments, in-app
 * notifications and idempotency keys.
 *
 * Every "trail" a record shows is a query of audit_events for that object, so the
 * history cannot drift from the log the auditor exports.
 */
class CreateAuditAndPlatform extends SchemaMigration
{
    public function up(): void
    {
        // Append-only (rule 10): update and delete are refused by trigger. Keep for
        // at least the longest donor retention period (typically seven years).
        $this->table('audit_events', [
            'id'               => $this->id(),
            'entity_id'        => $this->fk(true),
            'occurred_at'      => $this->datetime(false),
            'actor_user_id'    => $this->fk(true),
            'approver_user_id' => $this->fk(true),
            'action'           => $this->string(40),
            'object_type'      => $this->string(40),
            'object_id'        => $this->fk(true),
            'object_ref'       => $this->string(40, true),
            // The human-readable line a record's history shows ("Approved by W. Kamau").
            'summary'          => $this->string(255, true),
            'before_state'     => $this->json(),
            'after_state'      => $this->json(),
            'reason'           => $this->text(),
            'ip_address'       => $this->string(45, true),
            'request_id'       => $this->string(64, true),
        ], [
            'keys' => [['object_type', 'object_id', 'occurred_at'], ['entity_id', 'occurred_at'], 'actor_user_id', 'action'],
            'fks'  => ['entity_id' => 'entities', 'actor_user_id' => 'users', 'approver_user_id' => 'users'],
        ]);

        // Donor audits ask for the source document, not the entry.
        $this->table('attachments', [
            'id'          => $this->id(),
            'entity_id'   => $this->fk(),
            'object_type' => $this->string(40),
            'object_id'   => $this->fk(),
            'filename'    => $this->string(255),
            'mime_type'   => $this->string(100),
            'size_bytes'  => $this->int(false, null, 'BIGINT'),
            'storage_key' => $this->string(255),
            'sha256'      => $this->string(64),
            'uploaded_by' => $this->fk(),
            'uploaded_at' => $this->datetime(false),
        ], [
            'unique' => ['storage_key'],
            'keys'   => [['object_type', 'object_id'], 'sha256'],
            'fks'    => ['entity_id' => 'entities', 'uploaded_by' => 'users'],
            'checks' => ['size' => 'size_bytes >= 0'],
        ]);

        $this->table('notifications', [
            'id'          => $this->id(),
            'user_id'     => $this->fk(),
            'entity_id'   => $this->fk(true),
            'kind'        => $this->string(30),
            'tone'        => $this->string(6, false, 'info'),
            'title'       => $this->string(255),
            'body'        => $this->text(),
            'object_type' => $this->string(40, true),
            'object_id'   => $this->fk(true),
            'link'        => $this->string(255, true),
            'created_at'  => $this->datetime(false),
            'read_at'     => $this->datetime(),
        ], [
            'keys'   => [['user_id', 'read_at', 'created_at']],
            'fks'    => ['user_id' => ['users', 'CASCADE'], 'entity_id' => 'entities'],
            'checks' => ['tone' => $this->in('tone', ['action', 'alert', 'info'])],
        ]);

        // Posting actions are idempotent: a retried submission with the same key
        // replays the stored response instead of posting twice.
        $this->table('idempotency_keys', [
            'id'              => $this->id(),
            'user_id'         => $this->fk(),
            'idempotency_key' => $this->string(64),
            'method'          => $this->string(6),
            'path'            => $this->string(255),
            'request_hash'    => $this->string(64),
            'response_status' => $this->int(true, null, 'SMALLINT'),
            'response_body'   => $this->json(),
            'created_at'      => $this->datetime(false),
            'completed_at'    => $this->datetime(),
        ], [
            'unique' => [['user_id', 'idempotency_key']],
            'keys'   => ['created_at'],
            'fks'    => ['user_id' => ['users', 'CASCADE']],
        ]);
    }

    public function down(): void
    {
        $this->dropTables(['idempotency_keys', 'notifications', 'attachments', 'audit_events']);
    }
}
