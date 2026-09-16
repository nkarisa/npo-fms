<?php

namespace App\Controllers\Api;

use App\Libraries\Navigation;
use App\Libraries\Prototype;

/**
 * The top-bar bell. In-app only — email and SMS delivery are a known gap in the
 * handoff README, so nothing here pretends to have sent anything.
 */
class Notifications extends BaseApiController
{
    public function index()
    {
        $unreadOnly = filter_var($this->request->getGet('unread') ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $read       = Prototype::load('NOTIF_READ');
        $all        = Prototype::load('NOTIFS');

        $unread = count(array_filter($all, static fn ($n) => empty($read[$n['id']])));
        $shown  = $unreadOnly ? array_filter($all, static fn ($n) => empty($read[$n['id']])) : $all;

        $rows = array_values(array_map(static fn ($n) => [
            'id'     => $n['id'],
            'kind'   => $n['kind'],
            'tone'   => $n['tone'],
            'title'  => $n['title'],
            'body'   => $n['body'],
            'when'   => $n['when'],
            'day'    => $n['day'],
            'href'   => Navigation::url($n['page']),
            'unread' => empty($read[$n['id']]),
        ], $shown));

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
        $all  = Prototype::load('NOTIFS');
        $read = Prototype::load('NOTIF_READ');

        if ($id === '') {
            foreach ($all as $n) {
                $read[$n['id']] = true;
            }
        } else {
            if (!in_array($id, array_column($all, 'id'), true)) {
                return $this->response->setStatusCode(404)->setJSON(['error' => $id . ' is not a notification.']);
            }
            $read[$id] = true;
        }

        Prototype::save('NOTIF_READ', $read);

        $unread = count(array_filter($all, static fn ($n) => empty($read[$n['id']])));

        return $this->json(['unread' => $unread]);
    }
}
