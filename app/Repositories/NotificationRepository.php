<?php

namespace App\Repositories;

use App\Libraries\Clock;

/** A user's in-app notifications. */
final class NotificationRepository extends Repository
{
    public function forUser(int $userId): array
    {
        return $this->cached("user:{$userId}", fn () => array_map(static function ($n) {
            $days = -(int) Clock::daysUntil($n['created_at']);

            return [
                'id'     => 'n' . $n['id'],
                'kind'   => $n['kind'],
                'tone'   => $n['tone'],
                'title'  => $n['title'],
                'body'   => $n['body'] ?? '',
                'when'   => match (true) {
                    $days === 0 => date('H:i', strtotime($n['created_at'])),
                    $days === 1 => 'Yesterday',
                    default     => date('d M', strtotime($n['created_at'])),
                },
                'day'    => $days === 0 ? 'Today' : 'Earlier',
                'href'   => $n['link'] ?? '/',
                'unread' => $n['read_at'] === null,
            ];
        }, $this->rows('SELECT * FROM {notifications} WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$userId])));
    }

    /** Marks one notification read ("n12"), or all of the user's when $id is null. Returns false for an unknown id. */
    public function markRead(int $userId, ?string $id): bool
    {
        $builder = $this->db->table('notifications')->where('user_id', $userId)->where('read_at', null);
        if ($id !== null) {
            $numeric = (int) ltrim($id, 'n');
            if ($this->value('SELECT id FROM {notifications} WHERE id = ? AND user_id = ?', [$numeric, $userId]) === null) {
                return false;
            }
            $builder->where('id', $numeric);
        }

        $this->transaction(fn () => $builder->update(['read_at' => Clock::timestamp()]));

        return true;
    }
}
