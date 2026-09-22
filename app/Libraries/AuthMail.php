<?php

namespace App\Libraries;

use App\Repositories\MailRepository;
use Config\Auth;

/**
 * The messages signing in sends: an invitation, a password-reset link and a
 * six-digit sign-in code.
 *
 * They go through CodeIgniter's email service, sent through the mail server set in
 * Settings → Integrations → Email, or .env's `email.*` when none is set there — and
 * always .env's outside production when it names a host, which is how development
 * sends to Mailpit (MailRepository::transport()). Links are built on
 * app.baseURL rather than on the address the request came in on, so a request with
 * a forged Host header cannot have a reset link point somewhere else.
 *
 * When a message cannot be sent, an instance that is not in production writes the
 * link or code to the log instead, so a developer without a mail server can still
 * sign in. Production never logs them; the person is told it could not be sent.
 */
final class AuthMail
{
    public static function invitation(array $user, string $token, string $invitedBy): bool
    {
        $app = Brand::current()['name'];
        $link = site_url('accept-invite') . '?token=' . rawurlencode($token);
        $hours = config(Auth::class)->inviteHours;

        return self::send($user['email'], 'Your ' . $app . ' account', [
            'Hello ' . $user['name'] . ',',
            $invitedBy . ' has given you an account on ' . $app . '. Choose a password to start:',
            $link,
            'The link works once and expires in ' . self::duration($hours) . '. You will be asked to set up a second sign-in step — an authenticator app such as Google Authenticator or Okta Verify, or a code by email.',
            'If you were not expecting this, you can ignore it.',
        ], 'invitation link for ' . $user['email'] . ': ' . $link);
    }

    public static function passwordReset(array $user, string $token): bool
    {
        $app = Brand::current()['name'];
        $link = site_url('reset-password') . '?token=' . rawurlencode($token);

        return self::send($user['email'], 'Reset your ' . $app . ' password', [
            'Hello ' . $user['name'] . ',',
            'Someone asked to reset the password for this account. If it was you, choose a new one here:',
            $link,
            'The link works once and expires in ' . self::duration(config(Auth::class)->resetHours) . '. Your second sign-in step is not changed.',
            'If it was not you, ignore this message — your password stays as it is.',
        ], 'password reset link for ' . $user['email'] . ': ' . $link);
    }

    public static function signInCode(array $user, string $code): bool
    {
        $app = Brand::current()['name'];
        $minutes = config(Auth::class)->emailCodeMinutes;

        return self::send($user['email'], $code . ' is your ' . $app . ' sign-in code', [
            'Hello ' . $user['name'] . ',',
            'Your sign-in code is:',
            $code,
            'It works once and expires in ' . $minutes . ' minutes. Nobody from ' . $app . ' will ever ask you for it.',
            'If you did not just sign in, someone has your password: change it from My account.',
        ], 'sign-in code for ' . $user['email'] . ': ' . $code);
    }

    /** Settings → Integrations → Email: proves the server takes mail, to the person asking. */
    public static function test(array $user): bool
    {
        $app = Brand::current()['name'];

        return self::send($user['email'], 'Test message from ' . $app, [
            'Hello ' . $user['name'] . ',',
            'This is the test message you sent from Settings → Integrations → Email. ' . $app . ' can send invitations, password resets and sign-in codes.',
        ], 'test message for ' . $user['email']);
    }

    /** @param list<string> $paragraphs */
    private static function send(string $to, string $subject, array $paragraphs, string $fallback): bool
    {
        // The server set in Settings → Integrations → Email, or .env's (MailRepository::transport()).
        $config = (new MailRepository())->transport()['config'];
        $email = service('email');
        $email->initialize($config);
        $email->setFrom($config['fromEmail'] !== '' ? $config['fromEmail'] : 'no-reply@' . (parse_url(site_url(), PHP_URL_HOST) ?: 'localhost'),
            $config['fromName'] !== '' ? $config['fromName'] : Brand::current()['name']);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMailType('text');
        $email->setMessage(implode("\n\n", $paragraphs) . "\n");

        $sent = false;
        try {
            $sent = (bool) $email->send();
        } catch (\Throwable $e) {
            log_message('error', 'Sign-in email to {to} failed: {error}', ['to' => $to, 'error' => $e->getMessage()]);
        }

        if (!$sent && ENVIRONMENT !== 'production') {
            log_message('notice', 'Email could not be sent; ' . $fallback);
        }

        return $sent;
    }

    private static function duration(int $hours): string
    {
        return match (true) {
            $hours % 24 === 0 => ($hours / 24) . ($hours === 24 ? ' day' : ' days'),
            default           => $hours . ($hours === 1 ? ' hour' : ' hours'),
        };
    }
}
