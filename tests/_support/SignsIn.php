<?php

namespace Tests\Support;

use App\Libraries\SignIn;
use App\Repositories\Lookups;
use App\Repositories\Repository;

/**
 * Starts a feature test signed in, as the first active user who can change
 * settings — who the tests have always acted as by default — and sending the
 * header the application's own pages send with every request.
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

    /** Signs in as a user by email, or as the settings manager. */
    protected function signIn(?string $email = null): void
    {
        Repository::forget();
        $lookups = new Lookups();
        $id = $email === null ? $lookups->settingsManagerId() : $lookups->userId($email);

        $this->withSession($id === null ? [] : SignIn::values($id));
    }

    protected function signOut(): void
    {
        $this->withSession([]);
    }
}
