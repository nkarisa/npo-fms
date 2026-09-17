<?php

namespace App\Controllers\Api;

use App\Repositories\UserRepository;

/**
 * Who is signed in, for the user menu.
 *
 * There is no authentication yet, so "Act as" stands in for it the way the
 * prototype uses it: it lets one person walk an entry through preparation and
 * approval as different people, which is the only way to exercise segregation of
 * duties without real accounts. It is a testing aid and says so — nothing here is
 * an access control.
 */
class Me extends BaseApiController
{
    private const COOKIE = 'elog_actor';

    public function index()
    {
        $actors  = (new UserRepository())->actors();
        $current = $this->actor();

        return $this->json([
            'me'     => $current,
            'actors' => array_map(static fn ($a) => $a + ['current' => $a['email'] === $current['email']], $actors),
            'menu'   => [
                ['icon' => '◍', 'label' => 'My profile'],
                ['icon' => '⚙', 'label' => 'Preferences', 'href' => '/settings'],
                ['icon' => '⌘', 'label' => 'Switch entity'],
                ['icon' => '?', 'label' => 'User manual', 'href' => '/user-manual'],
            ],
            'note'   => 'Walk an entry through preparation and approval as different people.',
        ]);
    }

    /** Switches the acting user. Testing aid only — see the class docblock. */
    public function actAs()
    {
        $body  = $this->request->getJSON(true) ?? [];
        $email = (string) ($body['email'] ?? '');

        foreach ((new UserRepository())->actors() as $a) {
            if ($a['email'] === $email) {
                $this->response->setCookie(self::COOKIE, $email, 60 * 60 * 24 * 30, '', '/');

                return $this->json(['me' => $a]);
            }
        }

        return $this->response->setStatusCode(404)->setJSON(['error' => $email . ' is not a known user.']);
    }
}
