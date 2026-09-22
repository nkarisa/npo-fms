<?php

namespace App\Database\Seeds;

use App\Database\Seeds\Support\SeedContext;
use App\Libraries\Navigation;
use CodeIgniter\Database\Seeder;

/**
 * In-app notifications and the settings change log.
 *
 * Sources: NOTIFS and NOTIF_READ (addressed to the Finance Manager, the user the
 * prototype signs in as), ST_AUDIT.
 */
class NotificationAndSettingsAuditSeeder extends Seeder
{
    private const RECIPIENT = 'w.kamau@elog.or.ke';

    public function run(): void
    {
        $ctx = SeedContext::get();
        $read = $ctx->data('NOTIF_READ');
        $recipient = $ctx->db()->table('users')->where('email', self::RECIPIENT)->get()->getRow('id');

        foreach ($ctx->data('NOTIFS') as $n) {
            $sentAt = $ctx->datetime($n['day'] === 'Today' ? "Today, {$n['when']}" : $n['when']);
            $ctx->insert('notifications', [
                'user_id' => $recipient, 'entity_id' => $ctx->entityId(), 'kind' => $n['kind'], 'tone' => $n['tone'],
                'title' => $n['title'], 'body' => $n['body'], 'link' => Navigation::url($n['page']),
                'created_at' => $sentAt, 'read_at' => !empty($read[$n['id']]) ? $sentAt : null,
            ]);
        }

        foreach ($ctx->data('ST_AUDIT') as $e) {
            $ctx->insert('audit_events', [
                'entity_id' => $ctx->entityId(), 'occurred_at' => $ctx->datetime($e['when']), 'actor_user_id' => $ctx->userId($e['who']),
                'action' => 'settings.changed', 'object_type' => 'settings:' . strtolower($e['area']), 'summary' => $e['what'],
            ]);
        }
    }
}
