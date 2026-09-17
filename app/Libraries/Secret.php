<?php

namespace App\Libraries;

use Throwable;

/**
 * Credentials held in the finance database — today the Safaricom Daraja keys the
 * M-Pesa integration signs its requests with.
 *
 * They are encrypted with the application key (encryption.key in .env) so that a
 * copy of the database, a backup or a support dump does not carry usable
 * credentials with it, and they are never served to the browser: the settings
 * screen is told whether each one is set and its last four characters, which is
 * enough to tell one key from another without disclosing either.
 *
 * Losing or rotating the application key does not corrupt anything — what it
 * encrypted simply stops being readable, and the screen asks for the credential
 * again rather than failing silently.
 */
final class Secret
{
    /** Whether the application has a key to encrypt with at all. */
    public static function configured(): bool
    {
        return trim((string) config('Encryption')->key) !== '';
    }

    /** Encrypts a value for storage. Caller checks configured() first. */
    public static function seal(string $value): string
    {
        return base64_encode(service('encrypter')->encrypt($value));
    }

    /** The stored value, or '' when there is none or it cannot be read with the current key. */
    public static function open(?string $sealed): string
    {
        if ($sealed === null || $sealed === '') {
            return '';
        }

        try {
            $raw = base64_decode($sealed, true);

            return $raw === false ? '' : (string) service('encrypter')->decrypt($raw);
        } catch (Throwable) {
            return '';
        }
    }

    public static function isSet(?string $sealed): bool
    {
        return $sealed !== null && $sealed !== '';
    }

    /** Whether a stored credential can still be read — false once the application key has changed. */
    public static function readable(?string $sealed): bool
    {
        return !self::isSet($sealed) || self::open($sealed) !== '';
    }

    /** How a set credential is shown: its last four characters, the rest masked. */
    public static function hint(?string $sealed): string
    {
        $value = self::open($sealed);

        return match (true) {
            !self::isSet($sealed) => '',
            $value === ''         => 'unreadable — re-enter it',
            mb_strlen($value) <= 4 => '••••',
            default               => '•••• ' . mb_substr($value, -4),
        };
    }
}
