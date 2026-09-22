<?php

namespace App\Libraries;

use Config\Auth;

/**
 * Where a browser is in signing in, as the session holds it.
 *
 * Signing in is two steps. The password moves the session to `mfa` (a second
 * factor to give) or `enrol` (a second factor to set up first); the second factor
 * moves it to `done`. Only a `done` session reaches the application — the others
 * reach the sign-in endpoints and nothing else. A user with no second factor who
 * is not required to have one goes straight to `done`. Whoever would reach `done`
 * with a password older than the policy allows (App\Libraries\PasswordPolicy)
 * stops at `renew` instead, and chooses a new one first.
 *
 * The session id is regenerated at every step, so an id seen before sign-in is
 * worthless after it, and the session ends after Config\Auth::$idleMinutes without
 * a request.
 */
final class SignIn
{
    public const USER  = 'auth_user';
    public const STAGE = 'auth_stage';
    public const SEEN  = 'auth_seen';
    /** Wrong codes given in this sign-in, whatever the method. */
    public const TRIES = 'auth_tries';
    /** The secret being set up, until the first code from it is confirmed. */
    public const PENDING_SECRET = 'auth_pending_secret';
    public const PENDING_METHOD = 'auth_pending_method';

    public const MFA   = 'mfa';
    public const ENROL = 'enrol';
    public const RENEW = 'renew';
    public const DONE  = 'done';
    /** How the sign-in was completed, held while an expired password is replaced, for the audit log. */
    public const HOW = 'auth_how';

    /** The session values for a signed-in user, e.g. to start a test signed in. */
    public static function values(int $userId, string $stage = self::DONE): array
    {
        return [self::USER => $userId, self::STAGE => $stage, self::SEEN => time(), self::TRIES => 0];
    }

    public static function begin(int $userId, string $stage): void
    {
        $session = session();
        $session->regenerate(true);
        $session->remove([self::PENDING_SECRET, self::PENDING_METHOD]);
        $session->set(self::values($userId, $stage));
    }

    public static function advance(string $stage): void
    {
        $session = session();
        $session->regenerate(true);
        $session->set([self::STAGE => $stage, self::SEEN => time(), self::TRIES => 0]);
        $session->remove([self::PENDING_SECRET, self::PENDING_METHOD]);
    }

    public static function end(): void
    {
        $session = session();
        $session->remove([self::USER, self::STAGE, self::SEEN, self::TRIES, self::PENDING_SECRET, self::PENDING_METHOD, self::HOW]);
        $session->regenerate(true);
    }

    /** The user part-way or fully signed in, or null. */
    public static function userId(): ?int
    {
        $id = session(self::USER);

        return $id === null ? null : (int) $id;
    }

    public static function stage(): ?string
    {
        return self::userId() === null ? null : (string) session(self::STAGE);
    }

    public static function signedIn(): bool
    {
        return self::stage() === self::DONE;
    }

    /**
     * Whether the session has sat idle past the limit. An expired session is ended,
     * so the next request starts from the password again.
     */
    public static function expired(): bool
    {
        $seen = (int) session(self::SEEN);
        if (self::userId() === null || $seen === 0) {
            return false;
        }
        if (time() - $seen > config(Auth::class)->idleMinutes * 60) {
            self::end();

            return true;
        }

        return false;
    }

    public static function touch(): void
    {
        session()->set(self::SEEN, time());
    }

    /** Counts a wrong code; true once the sign-in has had too many and has been ended. */
    public static function wrongCode(): bool
    {
        $tries = (int) session(self::TRIES) + 1;
        if ($tries >= config(Auth::class)->maxCodeAttempts) {
            self::end();

            return true;
        }
        session()->set(self::TRIES, $tries);

        return false;
    }
}
