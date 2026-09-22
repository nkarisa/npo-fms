<?php

namespace App\Libraries;

use App\Repositories\RuleViolation;

/**
 * The .env file, as the installer writes it.
 *
 * The browser installer is given the database it is to use (App\Controllers\Api\Install)
 * and has to keep those credentials somewhere the next request will read them. That
 * is this file, the same one a hand installation edits, so an instance stood up in
 * the browser and one stood up from the shell are configured identically.
 *
 * A setting already in the file is changed where it stands, whether it was active
 * or commented out, so the file keeps the shape and the comments CodeIgniter ships
 * it with. Anything new is added at the end under a line saying who wrote it.
 * Nothing else is touched.
 *
 * It is written whole, to a temporary file in the same directory which then
 * replaces the original, so a failure half way through leaves the old file intact
 * rather than a truncated one the application cannot boot from. The result is
 * readable by its owner only: it holds the database password.
 *
 * Values are quoted as CodeIgniter's own reader expects (system/Config/DotEnv):
 * anything with a space in it has to be quoted or the reader refuses the file, and
 * an unquoted value is cut short at " #". A value holding "${" is refused outright
 * — the reader substitutes that for another setting's value, so it would not read
 * back as it was typed, and silently storing something else is worse than saying so.
 */
final class EnvFile
{
    /** Marks the block the installer appends, so it is obvious where it came from. */
    public const HEADER = '# Written by the installer';

    /** The file being worked on, when it is not the instance's own. */
    private static ?string $path = null;

    public static function path(): string
    {
        return self::$path ?? ROOTPATH . '.env';
    }

    /**
     * Points the library at another file, and null back at the instance's own.
     *
     * Only the tests use this, so they can write and read a file of their own: a
     * test that wrote the instance's .env could take the instance down.
     */
    public static function useFile(?string $path): void
    {
        self::$path = $path;
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** Whether the file can be written, or created when it is not there yet. */
    public static function writable(): bool
    {
        return self::exists() ? is_writable(self::path()) : is_writable(dirname(self::path()));
    }

    /**
     * The settings the file states, by name. Commented-out lines are not settings
     * and are not returned.
     *
     * @return array<string, string>
     */
    public static function read(): array
    {
        $values = [];
        foreach (self::lines() as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.\-]*)\s*=(.*)$/', $line, $m) === 1) {
                $values[$m[1]] = self::unquote(trim($m[2]));
            }
        }

        return $values;
    }

    public static function value(string $key, string $default = ''): string
    {
        return self::read()[$key] ?? $default;
    }

    /**
     * Writes the settings given, leaving everything else in the file as it stands.
     *
     * @param array<string, string> $values
     */
    public static function set(array $values): void
    {
        if (!self::writable()) {
            throw new RuleViolation(
                self::path() . ' cannot be written, so the database credentials have nowhere to go. '
                . 'Give the web server write access to it and try again.'
            );
        }

        foreach ($values as $key => $value) {
            self::assertStorable($key, (string) $value);
        }

        $lines   = self::lines();
        $pending = $values;

        // A setting already in the file is changed where it stands; one that is
        // commented out is uncommented in place, so it stays with its own comment.
        foreach ($lines as $i => $line) {
            foreach ($pending as $key => $value) {
                $quoted = preg_quote($key, '/');
                if (preg_match('/^\s*' . $quoted . '\s*=/', $line) === 1
                    || preg_match('/^\s*#\s*' . $quoted . '\s*=/', $line) === 1) {
                    $lines[$i] = $key . ' = ' . self::quote((string) $value);
                    unset($pending[$key]);
                    break;
                }
            }
        }

        if ($pending !== []) {
            if ($lines !== [] && trim(end($lines)) !== '') {
                $lines[] = '';
            }
            // One heading, however many times the installer adds to the file.
            $written = false;
            foreach ($lines as $line) {
                $written = $written || str_starts_with(trim($line), self::HEADER);
            }
            if (!$written) {
                $lines[] = self::HEADER . ' ' . date('Y-m-d H:i');
            }
            foreach ($pending as $key => $value) {
                $lines[] = $key . ' = ' . self::quote((string) $value);
            }
        }

        self::put(implode("\n", $lines) . "\n");
    }

    /**
     * Gives the instance an encryption key if it has none, and says whether it wrote
     * one. Without a key the authenticator secrets cannot be encrypted, so the
     * second sign-in step has nowhere to keep its secret and enrolment refuses.
     */
    public static function ensureEncryptionKey(): bool
    {
        if (self::value('encryption.key') !== '') {
            return false;
        }

        self::set(['encryption.key' => 'hex2bin:' . bin2hex(random_bytes(32))]);

        return true;
    }

    // ------------------------------------------------------------------

    /** @return list<string> */
    private static function lines(): array
    {
        if (!self::exists()) {
            return [];
        }

        return explode("\n", str_replace("\r\n", "\n", (string) file_get_contents(self::path())));
    }

    /**
     * Writes the whole file through a temporary one in the same directory: the
     * replacement is a rename, which either happened or did not, so the application
     * is never left booting from half a file.
     */
    private static function put(string $contents): void
    {
        $path = self::path();
        $temp = $path . '.installing-' . bin2hex(random_bytes(4));

        if (file_put_contents($temp, $contents, LOCK_EX) === false) {
            @unlink($temp);

            throw new RuleViolation('The configuration could not be written to ' . $path . '.');
        }
        // It holds the database password: its owner only, before it is in place.
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) {
            @unlink($temp);

            throw new RuleViolation('The configuration could not be written to ' . $path . '.');
        }
    }

    /** Why this value cannot be kept in the file as it stands, or nothing. */
    private static function assertStorable(string $key, string $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $key) !== 1) {
            throw new RuleViolation($key . ' is not a setting name this file can hold.');
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuleViolation('A setting cannot run over more than one line, and ' . $key . ' does.');
        }
        if (str_contains($value, '${')) {
            throw new RuleViolation(
                'The value given for ' . $key . ' contains "${", which the configuration reader replaces with '
                . 'another setting. It would not be read back as it was typed. Use a value without it.'
            );
        }
    }

    /** Quoted as the reader expects: always, so a space or a "#" cannot change the value. */
    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** The value a line states, with the quoting the reader would undo undone. */
    private static function unquote(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if ($value[0] === '"' || $value[0] === "'") {
            $quote = $value[0];
            $end   = strrpos($value, $quote);
            $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);

            return str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $value);
        }

        // Unquoted: the reader stops at " #".
        return trim(explode(' #', $value, 2)[0]);
    }
}
