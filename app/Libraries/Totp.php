<?php

namespace App\Libraries;

/**
 * Time-based one-time passwords (RFC 6238), the codes an authenticator app shows:
 * Google Authenticator, Microsoft Authenticator, Okta Verify, 1Password and the rest
 * all read the same otpauth:// address and compute the same six digits.
 *
 * SHA-1, six digits, thirty-second steps — the parameters every one of those apps
 * supports. A code is accepted one step either side of now, so a phone clock a
 * little out or a code typed as it turns over still works, and each step can be
 * used once: verify() returns the step it matched, which the caller keeps and
 * passes back so the same code cannot be replayed.
 */
final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new shared secret: 160 random bits, Base32 as the apps expect it. */
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a secret at a time step (defaults to now). */
    public static function code(string $secret, ?int $step = null): string
    {
        $step ??= self::step();
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code matches, or null. A step at or before $lastStep has been used
     * already and never matches again.
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $at = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1 || $secret === '') {
            return null;
        }

        $now = self::step($at);
        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if (($lastStep === null || $step > $lastStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function step(?int $at = null): int
    {
        return intdiv($at ?? time(), self::PERIOD);
    }

    /** The otpauth:// address an app reads from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** "JBSW Y3DP EHPK 3PXP …" — the secret as someone types it into an app by hand. */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $i = strpos(self::ALPHABET, $char);
            if ($i === false) {
                return '';
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
