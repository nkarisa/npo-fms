<?php

namespace App\Controllers\Api;

use App\Repositories\UserRepository;
use Config\Auth;

/**
 * Who is signed in, for the user menu.
 *
 * On a training or demonstration instance (auth.actAs in .env), the menu also
 * offers "Act as": it lets one person walk an entry through preparation and
 * approval as different people, which is the only way to exercise segregation of
 * duties with one pair of hands. What is done is recorded against the person
 * acted as. Everywhere else the list is not served and switching is refused.
 */
class Me extends BaseApiController
{
    private const COOKIE = 'elog_actor';

    public function index()
    {
        $actAs   = config(Auth::class)->actAs;
        $current = $this->actor();
        $self    = $this->signedInUser();

        return $this->json([
            'me'     => $current,
            'signedInAs' => $self === null || $self['email'] === $current['email'] ? null : $self['name'],
            'actAs'  => $actAs,
            'actors' => $actAs ? array_map(static fn ($a) => $a + ['current' => $a['email'] === $current['email']], (new UserRepository())->actors()) : [],
            'menu'   => [
                ['icon' => '◍', 'label' => 'My account', 'href' => '/account'],
                ['icon' => '⚙', 'label' => 'Preferences', 'href' => '/settings'],
                ['icon' => '⌘', 'label' => 'Switch entity'],
                ['icon' => '?', 'label' => 'User manual', 'href' => '/user-manual'],
            ],
            'note'   => 'Training instance: walk an entry through preparation and approval as different people.',
        ]);
    }

    /** Switches the acting user, on a training instance only. */
    public function actAs()
    {
        if (!config(Auth::class)->actAs) {
            return $this->denied('Acting as someone else is only available on a training instance. Sign in as that person instead.');
        }

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
