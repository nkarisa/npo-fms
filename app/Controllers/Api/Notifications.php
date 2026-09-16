<?php

namespace App\Controllers\Api;

use App\Repositories\NotificationRepository;

/**
 * The top-bar bell. In-app only — email and SMS delivery are a known gap in the
 * handoff README, so nothing here pretends to have sent anything.
 */
class Notifications extends BaseApiController
{
    public function index()
    {
        $unreadOnly = filter_var($this->request->getGet('unread') ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $all        = (new NotificationRepository())->forUser($this->actorId());

        $unread = count(array_filter($all, static fn ($n) => $n['unread']));
        $rows   = array_values($unreadOnly ? array_filter($all, static fn ($n) => $n['unread']) : $all);

        return $this->json([
            'rows'    => $rows,
            'unread'  => $unread,
            'total'   => count($all),
            'summary' => $unread > 0 ? $unread . ' unread' : 'All read',
        ]);
    }

    /** Marks one notification read, or every notification when no id is given. */
    public function read()
    {
        $body = $this->request->getJSON(true) ?? [];
        $id   = trim((string) ($body['id'] ?? ''));
        $repo = new NotificationRepository();

        if (!$repo->markRead($this->actorId(), $id === '' ? null : $id)) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $id . ' is not a notification.']);
        }

        $unread = count(array_filter($repo->forUser($this->actorId()), static fn ($n) => $n['unread']));

        return $this->json(['unread' => $unread]);
    }
}
