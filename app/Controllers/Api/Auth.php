<?php

namespace App\Controllers\Api;

use App\Libraries\SignIn;
use App\Repositories\AuthRepository;
use App\Repositories\RuleViolation;
use App\Repositories\UserRepository;

/**
 * Signing in, out, and getting an account to where it can sign in.
 *
 * The only API that answers without a completed sign-in (Config\Filters). The
 * sign-in screen follows `stage` in every response:
 *
 *   signedOut → POST login {email, password}
 *   mfa       → POST verify {code}         (an authenticator code, an emailed code, or a recovery code)
 *   enrol     → POST enrol/start {method}, then POST enrol/confirm {code}
 *   renew     → POST renew {password}      (the password has expired under the policy)
 *   done      → the application
 *
 * Invitations and password resets arrive as a link carrying a token; setting the
 * password from one proves the mailbox, so it signs in as far as the second factor.
 *
 * Each address gets a limited number of attempts a minute at the password, a code
 * or a reset, on top of the per-account lockout.
 */
class Auth extends BaseApiController
{
    /** Attempts per address per minute at anything that checks a secret. */
    private const PER_MINUTE = 10;

    public function status()
    {
        return $this->json($this->state());
    }

    /** Body: {email, password}. */
    public function login()
    {
        if ($refusal = $this->throttled('login')) {
            return $refusal;
        }
        $body = $this->body();
        $auth = new AuthRepository();

        try {
            $result = $auth->attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''), $this->from());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->afterPassword((int) $result['user']['id'], $result['stage'], $result['user']);
    }

    /** Body: {code}. The second factor, at the `mfa` stage. */
    public function verify()
    {
        if (SignIn::stage() !== SignIn::MFA) {
            return $this->outOfStep();
        }
        if ($refusal = $this->throttled('verify')) {
            return $refusal;
        }
        $userId = (int) SignIn::userId();
        $how = (new AuthRepository())->verifyFactor($userId, (string) ($this->body()['code'] ?? ''));

        if ($how === null) {
            if (SignIn::wrongCode()) {
                return $this->response->setStatusCode(422)->setJSON(['error' => 'Too many wrong codes. Start again with your password.'] + $this->state());
            }

            return $this->response->setStatusCode(422)->setJSON(['error' => 'That code is not right, or has expired. Try the latest one.'] + $this->state());
        }

        return $this->finish($userId, $how);
    }

    /** Sends a fresh emailed code — at sign-in for an email user, or while setting email up. */
    public function code()
    {
        $stage = SignIn::stage();
        $pendingEmail = $stage === SignIn::ENROL && session(SignIn::PENDING_METHOD) === 'email';
        $userId = (int) SignIn::userId();
        $user = $userId === 0 ? null : $this->userRow($userId);

        if (!($stage === SignIn::MFA && $user['mfa_method'] === 'email') && !$pendingEmail) {
            return $this->outOfStep();
        }

        try {
            $sent = (new AuthRepository())->sendEmailCode($userId);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json(['message' => $this->sentMessage($sent, 'A new code is on its way to ' . AuthRepository::masked($user['email']) . '.')] + $this->state());
    }

    /** Body: {method: totp|email}. Starts setting up a second factor at the `enrol` stage. */
    public function enrolStart()
    {
        if (SignIn::stage() !== SignIn::ENROL) {
            return $this->outOfStep();
        }

        try {
            $out = self::startEnrolment((int) SignIn::userId(), (string) ($this->body()['method'] ?? ''));
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($out + $this->state());
    }

    /** Body: {code}. Confirms the factor being set up; signs in and returns the recovery codes. */
    public function enrolConfirm()
    {
        if (SignIn::stage() !== SignIn::ENROL) {
            return $this->outOfStep();
        }
        if ($refusal = $this->throttled('verify')) {
            return $refusal;
        }
        $userId = (int) SignIn::userId();

        try {
            $codes = self::confirmEnrolment($userId, (string) ($this->body()['code'] ?? ''), $userId);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $this->finish($userId, 'a newly set-up second step');

        return $this->json(['recoveryCodes' => $codes, 'message' => 'Your second sign-in step is set up.'] + $this->state());
    }

    /** Body: {password}. A new password in place of an expired one, at the `renew` stage; then signed in. */
    public function renew()
    {
        if (SignIn::stage() !== SignIn::RENEW) {
            return $this->outOfStep();
        }
        $userId = (int) SignIn::userId();

        try {
            (new AuthRepository())->renewExpired($userId, (string) ($this->body()['password'] ?? ''), $this->from());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
        $how = (string) (session(SignIn::HOW) ?? 'password');
        session()->remove(SignIn::HOW);

        return $this->finish($userId, $how);
    }

    public function logout()
    {
        $userId = SignIn::userId();
        if ($userId !== null) {
            (new AuthRepository())->signedOut($userId, $this->from());
        }
        SignIn::end();
        $this->response->deleteCookie('elog_actor');

        return $this->json(['message' => 'Signed out.'] + $this->state());
    }

    /** Body: {email}. Always answers the same, whether or not there is such an account. */
    public function forgot()
    {
        if ($refusal = $this->throttled('forgot')) {
            return $refusal;
        }
        (new AuthRepository())->requestReset((string) ($this->body()['email'] ?? ''), $this->from());

        return $this->json(['message' => 'If that address has an account, a link to reset the password is on its way. It works for ' . $this->hours(config(\Config\Auth::class)->resetHours) . '.']);
    }

    /**
     * GET ?token= — who an invitation or reset link is for, so the screen can greet
     * them, and the password policy, so it can list what the new password has to be.
     */
    public function link(string $purpose)
    {
        try {
            $link = (new AuthRepository())->linkHolder((string) $this->request->getGet('token'), $purpose);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
        $rules = Account::passwordRules();
        // Someone invited has no earlier passwords for the history rule to be about.
        if ($purpose === 'invite') {
            $rules['passwordRules'] = array_values(array_filter($rules['passwordRules'], static fn ($r) => $r['key'] !== 'history'));
        }

        return $this->json(['link' => $link] + $rules);
    }

    /** Body: {token, password}. Sets the password from an invitation or reset link and signs in as far as the second factor. */
    public function redeem(string $purpose)
    {
        if ($refusal = $this->throttled('redeem')) {
            return $refusal;
        }
        $body = $this->body();
        $auth = new AuthRepository();

        try {
            $userId = $auth->redeem((string) ($body['token'] ?? ''), $purpose, (string) ($body['password'] ?? ''), $this->from());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $user = $this->userRow($userId);
        $stage = match (true) {
            (bool) $user['mfa_enabled']    => SignIn::MFA,
            $auth->mustHaveMfa($userId)    => SignIn::ENROL,
            default                        => SignIn::DONE,
        };

        return $this->afterPassword($userId, $stage, $user);
    }

    // ------------------------------------------------------------------
    // Setting up a second factor — shared with My account
    // ------------------------------------------------------------------

    /**
     * Starts setting up a method: an authenticator secret to scan, or an emailed
     * code. The secret is held in the session until confirmEnrolment().
     */
    public static function startEnrolment(int $userId, string $method): array
    {
        $auth = new AuthRepository();
        $offered = array_column(array_filter($auth->offeredMethods(), static fn ($m) => $m['available']), 'key');
        if (!in_array($method, $offered, true)) {
            throw new RuleViolation('Choose an authenticator app or a code by email.');
        }

        session()->set(SignIn::PENDING_METHOD, $method);
        if ($method === 'totp') {
            $totp = $auth->newTotp($userId);
            session()->set(SignIn::PENDING_SECRET, $totp['secret']);

            return ['totp' => $totp];
        }

        session()->remove(SignIn::PENDING_SECRET);
        $sent = $auth->sendEmailCode($userId);

        return ['message' => $sent ? 'We have emailed you a six-digit code.' : self::unsent()];
    }

    /** @return list<string> the recovery codes */
    public static function confirmEnrolment(int $userId, string $code, int $actorId): array
    {
        $method = (string) session(SignIn::PENDING_METHOD);
        if ($method === '') {
            throw new RuleViolation('Choose how you will give a second step first.');
        }
        $secret = session(SignIn::PENDING_SECRET);
        $codes = (new AuthRepository())->enrol($userId, $method, $secret === null ? null : (string) $secret, $code, $actorId);
        session()->remove([SignIn::PENDING_METHOD, SignIn::PENDING_SECRET]);

        return $codes;
    }

    // ------------------------------------------------------------------

    /** Moves the session on once the password (or a link) has been accepted. */
    private function afterPassword(int $userId, string $stage, array $user)
    {
        SignIn::begin($userId, $stage);

        if ($stage === SignIn::DONE) {
            return $this->finish($userId, 'password');
        }

        $message = null;
        if ($stage === SignIn::MFA && $user['mfa_method'] === 'email') {
            try {
                $message = $this->sentMessage((new AuthRepository())->sendEmailCode($userId), 'We have emailed a six-digit code to ' . AuthRepository::masked($user['email']) . '.');
            } catch (RuleViolation $e) {
                // A code went a moment ago and still works.
                $message = $e->getMessage();
            }
        }

        return $this->json(array_filter(['message' => $message]) + $this->state());
    }

    private function finish(int $userId, string $how)
    {
        // Past the password and the second step, but the password has expired: a new one first.
        if ((new AuthRepository())->passwordExpired($userId)) {
            SignIn::advance(SignIn::RENEW);
            session()->set(SignIn::HOW, $how);

            return $this->json(['message' => 'Your password has expired. Choose a new one to carry on.'] + $this->state());
        }
        SignIn::advance(SignIn::DONE);
        (new AuthRepository())->signedIn($userId, $this->from(), $how);
        UserRepository::forget();

        return $this->json($this->state());
    }

    /** Where the browser is in signing in, and what the screen needs to show next. */
    private function state(): array
    {
        $stage = SignIn::stage();
        $userId = SignIn::userId();
        $auth = new AuthRepository();

        $out = ['stage' => $stage ?? 'signedOut'] + Account::passwordRules();
        if ($userId !== null && $stage !== SignIn::DONE) {
            $out['user'] = $auth->factorState($userId);
            $out['pending'] = session(SignIn::PENDING_METHOD);
        }
        // Past both steps: the address in full, for the browser to file the new password under.
        if ($stage === SignIn::RENEW) {
            $out['user']['account'] = $this->userRow($userId)['email'];
        }
        if ($stage === SignIn::DONE) {
            $me = (new UserRepository())->actorById($userId);
            $out['user'] = $me === null ? null : ['name' => $me['name'], 'email' => $me['email']];
        }

        return $out;
    }

    private function outOfStep()
    {
        return $this->response->setStatusCode(409)->setJSON(['error' => 'That step is not where this sign-in is. Start again from the sign-in page.'] + $this->state());
    }

    private function throttled(string $what)
    {
        $key = 'auth-' . $what . '-' . md5($this->from());
        if (service('throttler')->check($key, self::PER_MINUTE, MINUTE) === false) {
            return $this->response->setStatusCode(429)->setJSON(['error' => 'Too many attempts from here. Wait a minute and try again.']);
        }

        return null;
    }

    private function sentMessage(bool $sent, string $message): string
    {
        return $sent ? $message : self::unsent();
    }

    private static function unsent(): string
    {
        return ENVIRONMENT === 'production'
            ? 'The email could not be sent. Try again shortly, or ask whoever manages users.'
            : 'The email could not be sent (no mail server is set up). The code is in the application log, writable/logs.';
    }

    private function body(): array
    {
        return $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
    }

    private function from(): string
    {
        return (string) $this->request->getIPAddress();
    }

    private function userRow(int $id): array
    {
        return db_connect()->table('users')->where('id', $id)->get()->getRowArray() ?? [];
    }

    private function hours(int $hours): string
    {
        return $hours === 1 ? 'an hour' : $hours . ' hours';
    }
}
