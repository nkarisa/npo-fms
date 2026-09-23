<?php

namespace Tests\Support;

use App\Libraries\Installer;
use App\Libraries\SignIn;
use App\Repositories\Lookups;
use App\Repositories\Repository;

/**
 * Starts a feature test signed in, as the finance manager — who the tests have
 * always acted as by default — and sending the header the application's own pages
 * send with every request.
 *
 * Named by role rather than by email so it holds in both worlds: it is the
 * demonstration data's W. Kamau, and it is the role a fresh install gives its
 * first user (Installer::FIRST_ROLE). It is deliberately not the administrator the
 * seed now opens with, which holds every permission but approves nothing — a test
 * that prepares work and then has it approved wants to be somebody the approval
 * rules name.
 *
 * CIUnitTestCase calls setUpSignsIn() once the database is migrated and seeded.
 * A test that has to begin signed out calls signOut(); one that installs a fresh
 * instance calls signIn() once there is someone to be.
 */
trait SignsIn
{
    protected function setUpSignsIn(): void
    {
        $this->withHeaders(['X-Requested-With' => 'phpunit']);
        $this->signIn();
    }

    /** Signs in as a user by email, or as the finance manager. */
    protected function signIn(?string $email = null): void
    {
        Repository::forget();
        $lookups = new Lookups();
        $id = $email !== null
            ? $lookups->userId($email)
            : ($lookups->holderOf(Installer::FIRST_ROLE) ?? $lookups->settingsManagerId());

        $this->withSession($id === null ? [] : SignIn::values($id));
    }

    protected function signOut(): void
    {
        $this->withSession([]);
    }
}
