<?php

namespace App\Libraries;

use Throwable;

/**
 * The Safaricom Daraja endpoints the M-Pesa integration talks to.
 *
 * Only the OAuth call is made from here: it is what "Check connection" on
 * Settings → Integrations runs, and it is the one request that proves the short
 * code, the environment and the credentials belong together without moving any
 * money. Collections and disbursements themselves are raised against the same
 * host and the same token.
 *
 * Nothing here logs or returns a credential — a failure is reported as what
 * Safaricom answered, never as what was sent.
 */
final class Daraja
{
    public const HOSTS = [
        'sandbox'    => 'https://sandbox.safaricom.co.ke',
        'production' => 'https://api.safaricom.co.ke',
    ];

    /** Safaricom's own per-transaction ceiling on M-Pesa. */
    public const TRANSACTION_CEILING = 250000.0;

    private const TIMEOUT = 8;

    public static function host(string $environment): string
    {
        return self::HOSTS[$environment] ?? self::HOSTS['sandbox'];
    }

    /**
     * Asks Safaricom for an access token with the credentials given.
     *
     * @return array{ok: bool, note: string} the note is what is shown on the screen
     *         and written to the audit log, in Safaricom's own terms
     */
    public static function token(string $environment, string $key, string $secret): array
    {
        if ($key === '' || $secret === '') {
            return ['ok' => false, 'note' => 'The consumer key and secret are not both set.'];
        }

        try {
            $response = service('curlrequest', [
                'baseURI'     => self::host($environment) . '/',
                'timeout'     => self::TIMEOUT,
                'http_errors' => false,
            ])->get('oauth/v1/generate?grant_type=client_credentials', [
                'headers' => ['Authorization' => 'Basic ' . base64_encode($key . ':' . $secret), 'Accept' => 'application/json'],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'note' => 'Safaricom could not be reached: ' . self::tidy($e->getMessage())];
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        if ($status === 200 && is_array($body) && ($body['access_token'] ?? '') !== '') {
            $minutes = (int) round(((int) ($body['expires_in'] ?? 3599)) / 60);

            return ['ok' => true, 'note' => 'Token issued by Safaricom, valid ' . $minutes . ' minutes.'];
        }

        if (in_array($status, [400, 401, 403], true)) {
            return ['ok' => false, 'note' => 'Safaricom refused the credentials (' . $status . '). Check the consumer key and secret belong to the ' . $environment . ' app.'];
        }

        return ['ok' => false, 'note' => 'Safaricom answered ' . $status . ($body === null ? '' : ': ' . self::tidy(json_encode($body))) . '.'];
    }

    /** A remote message fit for the screen and the audit log: one line, short. */
    private static function tidy(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, 120);
    }
}
