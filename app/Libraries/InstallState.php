<?php

namespace App\Libraries;

use CodeIgniter\HTTP\RequestInterface;
use Throwable;

/**
 * Whether this instance still needs installing, who is allowed to install it, and
 * what they have answered so far.
 *
 * Three things are kept apart here because they answer different questions:
 *
 * - **The lock.** A file, not a row, because the question "does this instance need
 *   installing?" has to be answerable when there is no database to ask — which is
 *   exactly the state the installer exists for. App\Filters\Installed reads it on
 *   every request.
 * - **The token.** A secret written into writable/ the first time an uninstalled
 *   instance is asked for, which the installer will not proceed without. A newly
 *   deployed instance on a public address is otherwise there for whoever finds it
 *   first, and what they would find is a form that writes the database credentials
 *   and makes the first user. Whoever deployed it can read the file; a passer-by
 *   cannot. Skipped when the request comes from this machine, so local development
 *   is not made tedious by it.
 * - **The answers.** Held in the session, which is kept in files
 *   (Config\Session::$driver) and so needs no database either. Deliberately not in
 *   a file of our own: the answers include the database password, and a session
 *   that expires takes it with it rather than leaving it on disk.
 *
 * Deleting writable/installed.lock offers the installer again. That is the way back
 * for an instance whose database was dropped, and the reason the lock says so in
 * its own text.
 */
final class InstallState
{
    /** The session key the answers are held under. */
    public const SESSION = 'install_answers';

    /** The session key holding how far the wizard has got. */
    public const STEP = 'install_step';

    public const LOCK_FILE  = 'installed.lock';
    public const TOKEN_FILE = 'install.token';

    /** The steps, in order. The client draws the one the server says it is on. */
    public const STEPS = ['server', 'database', 'data', 'organisation', 'entities', 'user', 'review', 'done'];

    /** Where the lock and the token are kept, when it is not writable/. */
    private static ?string $directory = null;

    /**
     * Keeps the lock and the token in another directory, and null back in writable/.
     *
     * Only the tests use this, so walking the wizard to the end does not write a
     * lock or remove a token belonging to the instance they run in.
     */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory === null ? null : rtrim($directory, '/') . '/';
    }

    private static function directory(): string
    {
        return self::$directory ?? WRITEPATH;
    }

    // ------------------------------------------------------------------
    // The lock
    // ------------------------------------------------------------------

    public static function lockPath(): string
    {
        return self::directory() . self::LOCK_FILE;
    }

    /**
     * Whether this instance is installed.
     *
     * The lock answers it without a database. When there is no lock — an instance
     * installed from the command line before this wizard existed, or one deployed
     * with its database already in place — the database is asked instead, and the
     * lock written from the answer, so it is asked only once.
     */
    public static function installed(): bool
    {
        // Under test the answer comes from the database every time and no lock is
        // written: the suite builds and drops its schema for each test, so a file
        // left behind would answer for the test after it, and a test that seeds
        // nothing would find itself sent to the installer.
        if (ENVIRONMENT === 'testing') {
            return self::databaseHasOrganisation();
        }

        if (is_file(self::lockPath())) {
            return true;
        }

        if (!self::databaseHasOrganisation()) {
            return false;
        }

        // Installed, but nothing had said so yet.
        self::lock(['note' => 'Found already installed; this lock was written when the installer was first asked for.']);

        return true;
    }

    /**
     * Writes the lock, which closes the installer.
     *
     * @param array<string, string> $done what was installed, for whoever reads it later
     */
    public static function lock(array $done = []): void
    {
        $lines = [
            'This instance is installed. While this file exists, /install is closed.',
            '',
            'Installed at  ' . date('Y-m-d H:i:s'),
        ];
        foreach ($done as $label => $value) {
            $lines[] = str_pad(ucfirst($label), 14) . $value;
        }
        $lines[] = '';
        $lines[] = 'Delete this file to offer the installer again — for an instance whose database';
        $lines[] = 'has been dropped and is being stood up afresh. It is not a way to reinstall over';
        $lines[] = 'books that already exist: the installer refuses a database that holds an';
        $lines[] = 'organisation (App\Libraries\Installer::refusal).';

        @file_put_contents(self::lockPath(), implode("\n", $lines) . "\n");
        @chmod(self::lockPath(), 0640);
    }

    /** Whether the database already holds an organisation. False when there is no database to ask. */
    public static function databaseHasOrganisation(): bool
    {
        try {
            $db = db_connect();
            if (!in_array(strtolower($db->prefixTable('entities')), array_map('strtolower', $db->listTables()), true)) {
                return false;
            }

            return $db->table('entities')->countAllResults() > 0;
        } catch (Throwable $e) {
            // No database, no credentials, or no schema: nothing says it is installed.
            return false;
        }
    }

    // ------------------------------------------------------------------
    // The token
    // ------------------------------------------------------------------

    public static function tokenPath(): string
    {
        return self::directory() . self::TOKEN_FILE;
    }

    /** The token for this instance, written the first time it is asked for. */
    public static function token(): string
    {
        $held = is_file(self::tokenPath()) ? trim((string) @file_get_contents(self::tokenPath())) : '';
        if ($held !== '') {
            return $held;
        }

        $token = bin2hex(random_bytes(16));
        @file_put_contents(self::tokenPath(), $token . "\n");
        @chmod(self::tokenPath(), 0600);

        return $token;
    }

    public static function forgetToken(): void
    {
        @unlink(self::tokenPath());
    }

    /**
     * Whether the installer should ask for the token: yes, unless the request came
     * from this machine, where whoever is asking is already on the server.
     */
    public static function tokenRequired(?RequestInterface $request = null): bool
    {
        $request ??= service('request');
        $ip = method_exists($request, 'getIPAddress') ? (string) $request->getIPAddress() : '';

        return !in_array($ip, ['127.0.0.1', '::1'], true) && !str_starts_with($ip, '127.');
    }

    /** Whether what was typed is the token, compared so the comparison itself says nothing. */
    public static function accepts(string $given): bool
    {
        return hash_equals(self::token(), trim($given));
    }

    /** Whether this request may use the installer at all. */
    public static function authorised(?RequestInterface $request = null): bool
    {
        if (!self::tokenRequired($request)) {
            return true;
        }

        return (bool) session(self::SESSION . '_authorised');
    }

    /**
     * Whether this session is the one writing the install: unlocked, and at the step
     * the four writing calls are made from. Between them the database comes to hold
     * an organisation, and App\Filters\Installed must not close the installer on the
     * session that is still finishing it.
     */
    public static function writing(): bool
    {
        return session(self::STEP) === 'review' && self::authorised();
    }

    public static function authorise(): void
    {
        session()->set(self::SESSION . '_authorised', true);
    }

    // ------------------------------------------------------------------
    // The answers
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $held = session(self::SESSION);

        return is_array($held) ? $held : [];
    }

    /** @param array<string, mixed> $values */
    public static function put(array $values): void
    {
        session()->set(self::SESSION, $values + self::all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** The step the wizard is on, which the client draws. */
    public static function step(): string
    {
        $held = (string) (session(self::STEP) ?? '');

        return in_array($held, self::STEPS, true) ? $held : self::STEPS[0];
    }

    public static function setStep(string $step): void
    {
        if (in_array($step, self::STEPS, true)) {
            session()->set(self::STEP, $step);
        }
    }

    /**
     * Drops everything the wizard was holding — the database password included —
     * once it has been written where it belongs.
     */
    public static function forget(): void
    {
        session()->remove([self::SESSION, self::STEP, self::SESSION . '_authorised']);
    }
}
