<?php

namespace App\Repositories;

use App\Libraries\AuthMail;
use App\Libraries\Clock;
use App\Libraries\PasswordPolicy;
use App\Libraries\Secret;
use App\Libraries\SignIn;
use App\Libraries\Totp;
use Config\Auth;

/**
 * Signing in: the password, the second factor, and the single-use links and codes
 * sent by email.
 *
 * - A wrong password counts against the account; as many in a row as the password
 *   policy allows (Settings → Users, `lockAttempts`) lock it for `lockMinutes`,
 *   and 0 attempts never locks it. The refusal is the same whether the email is
 *   unknown or the password is wrong, so the form cannot be used to find out who
 *   has an account.
 * - The second factor is an authenticator app (TOTP) or a six-digit code sent by
 *   email. Setting one up issues recovery codes, each good once, for when the
 *   phone or the mailbox is out of reach.
 * - Links and codes are stored only as hashes, and each works once.
 *
 * Every sign-in, refusal, lockout and change to a second factor is in the audit
 * log with the address it came from.
 */
final class AuthRepository extends Repository
{
    public const METHODS = ['totp' => 'Authenticator app', 'email' => 'Code by email'];

    /** Recovery codes avoid characters that are easily confused (0/o, 1/l/i). */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Cheaper than a password: these are short-lived or single-use, and checked in a loop. */
    private const CODE_COST = ['cost' => 8];

    /** A real hash of nothing anyone knows, checked when there is no account so the refusal takes as long. */
    private const DUMMY_HASH = '$2y$12$VE9REDUJLmDnVbf6kp1xcOn7gilyFUTQGCXzNjGQPFu5bdJV5hlPW';

    private Auth $config;

    /** @var array{attempts: int, minutes: int}|null the lockout rule, once read */
    private ?array $lockout = null;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->config = config(Auth::class);
    }

    // ------------------------------------------------------------------
    // The password
    // ------------------------------------------------------------------

    /**
     * Checks an email and password. Returns the user and the stage the sign-in
     * moves to: `mfa`, `enrol` or `done`. (An expired password is asked about only
     * once the second step is given too — Api\Auth::finish().)
     *
     * @return array{user: array, stage: string}
     */
    public function attempt(string $email, string $password, string $from = ''): array
    {
        // A browser that filled the password in may still hold the one it replaced.
        $refused = 'That email and password do not match an account. If your browser filled the password in, it may be an old one — type it instead.';
        $user = $this->row('SELECT * FROM {users} WHERE LOWER(email) = ?', [mb_strtolower(trim($email))]);

        if ($user === null || $user['password_hash'] === null || $user['password_hash'] === '') {
            // Spend the time a real check would, so a missing account is not faster to refuse.
            password_verify($password, self::DUMMY_HASH);
            if ($user !== null && $user['status'] === 'invited') {
                throw new RuleViolation('This account has not been set up yet. Use the link in your invitation email to choose a password.');
            }

            throw new RuleViolation($refused);
        }

        $id = (int) $user['id'];
        if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
            throw new RuleViolation('Too many wrong passwords. The account is locked — try again in ' . $this->minutesUntil($user['locked_until']) . ', or reset your password.');
        }

        if (!password_verify($password, $user['password_hash'])) {
            ['attempts' => $attempts, 'minutes' => $minutes] = $this->lockout();
            $failed = (int) $user['failed_sign_ins'] + 1;
            if ($attempts > 0 && $failed >= $attempts) {
                $until = date('Y-m-d H:i:s', time() + $minutes * 60);
                $this->db->table('users')->where('id', $id)->update(['failed_sign_ins' => 0, 'locked_until' => $until]);
                $this->log($id, 'auth.locked', 'Locked after ' . $failed . ' wrong passwords', $id, $from);

                throw new RuleViolation('Too many wrong passwords. The account is locked for ' . PasswordPolicy::lockFor($minutes) . ' — or reset your password to get in now.');
            }
            $this->db->table('users')->where('id', $id)->update(['failed_sign_ins' => $failed]);
            // Where nothing locks the account, the count is still kept and logged, so the
            // audit log shows someone guessing even though no door closed on them.
            $this->log($id, 'auth.refused', 'Wrong password' . ($attempts > 0 ? ' (' . $failed . ' of ' . $attempts . ')' : ' (' . $failed . ' in a row)'), null, $from);

            throw new RuleViolation($refused);
        }

        if ($user['status'] === 'suspended') {
            throw new RuleViolation('This account is suspended. Ask whoever manages users to reinstate it.');
        }
        if ($this->value('SELECT COUNT(*) FROM {user_entity_roles} WHERE user_id = ?', [$id]) == 0) {
            throw new RuleViolation('This account has no role yet, so there is nothing it can open. Ask whoever manages users to assign one.');
        }

        $update = ['failed_sign_ins' => 0, 'locked_until' => null];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->db->table('users')->where('id', $id)->update($update);

        $stage = match (true) {
            (bool) $user['mfa_enabled'] => SignIn::MFA,
            $this->mustHaveMfa($id)     => SignIn::ENROL,
            default                     => SignIn::DONE,
        };

        return ['user' => $user, 'stage' => $stage];
    }

    /** Stamps a completed sign-in. */
    public function signedIn(int $userId, string $from = '', string $how = ''): void
    {
        $this->db->table('users')->where('id', $userId)->update([
            // On the application clock, as the rest of the ledger is, so "Today, 09:12" reads right.
            'last_sign_in_at' => Clock::timestamp(), 'last_sign_in_from' => $from === '' ? null : mb_substr($from, 0, 80),
        ]);
        $this->log($userId, 'auth.signed_in', 'Signed in' . ($how !== '' ? ' with ' . $how : ''), $userId, $from);
        self::forget();
    }

    public function signedOut(int $userId, string $from = ''): void
    {
        $this->log($userId, 'auth.signed_out', 'Signed out', $userId, $from);
    }

    /** Whether Config\Auth makes this user have a second factor. */
    public function mustHaveMfa(int $userId): bool
    {
        return match ($this->config->mfaRequired) {
            'optional'   => false,
            'privileged' => $this->config->privileged !== [] && (int) $this->value(
                'SELECT COUNT(*) FROM {user_entity_roles} ur JOIN {role_permissions} rp ON rp.role_id = ur.role_id
                 JOIN {permissions} p ON p.id = rp.permission_id WHERE ur.user_id = ? AND p.key IN (' . implode(', ', array_fill(0, count($this->config->privileged), '?')) . ')',
                [$userId, ...$this->config->privileged]
            ) > 0,
            default      => true,
        };
    }

    public function changePassword(int $userId, string $current, string $new): void
    {
        $user = $this->user($userId);
        if (!password_verify($current, (string) $user['password_hash'])) {
            throw new RuleViolation('Your current password is not right.');
        }
        if (hash_equals($current, $new)) {
            throw new RuleViolation('Choose a password different from the one you have.');
        }
        $this->setPassword($user, $new);
        $this->log($userId, 'auth.password', 'Changed their password', $userId);
    }

    // ------------------------------------------------------------------
    // Links sent by email: invitations and password resets
    // ------------------------------------------------------------------

    /**
     * Sends an invitation link to a user who has no password yet, or a new one in
     * place of an earlier one. Returns whether the email went.
     */
    public function invite(int $userId, int $actorId): bool
    {
        $user = $this->user($userId);
        if ($user['status'] === 'suspended') {
            throw new RuleViolation($user['name'] . ' is suspended. Reinstate the account before inviting them again.');
        }
        if ($user['password_hash'] !== null && $user['password_hash'] !== '') {
            throw new RuleViolation($user['name'] . ' has already set a password. If they have forgotten it, they can reset it from the sign-in page.');
        }
        $token = $this->issueToken($userId, 'invite', $this->config->inviteHours);
        $this->log($userId, 'auth.invited', 'Invitation link sent to ' . $user['email'], $actorId);

        return AuthMail::invitation($user, $token, (new Lookups())->shortName($actorId, 'An administrator'));
    }

    /**
     * A one-time link to set a password, for the first user of a new instance or
     * someone locked out entirely. Given to whoever runs the command, not emailed.
     */
    public function setPasswordLink(int $userId): string
    {
        return site_url('accept-invite') . '?token=' . rawurlencode($this->issueToken($userId, 'invite', $this->config->inviteHours));
    }

    /**
     * Sends a reset link if the email belongs to an active account with a password.
     * Says nothing either way, so the form cannot be used to find out who has one.
     */
    public function requestReset(string $email, string $from = ''): void
    {
        $user = $this->row("SELECT * FROM {users} WHERE LOWER(email) = ? AND status = 'active'", [mb_strtolower(trim($email))]);
        if ($user === null || $user['password_hash'] === null) {
            return;
        }
        // One link a minute is plenty; more is someone filling a mailbox.
        if ($this->value("SELECT id FROM {auth_tokens} WHERE user_id = ? AND purpose = 'reset' AND used_at IS NULL AND created_at > ?",
            [(int) $user['id'], date('Y-m-d H:i:s', time() - 60)]) !== null) {
            return;
        }
        $token = $this->issueToken((int) $user['id'], 'reset', $this->config->resetHours);
        $this->log((int) $user['id'], 'auth.reset_requested', 'Asked for a password reset link', null, $from);
        AuthMail::passwordReset($user, $token);
    }

    /** Who a link is for, while it still works: {name, email, purpose}. */
    public function linkHolder(string $token, string $purpose): array
    {
        $row = $this->liveToken($token, $purpose);

        return ['name' => $row['name'], 'email' => $row['email'], 'purpose' => $purpose];
    }

    /** Sets a password from an invitation or reset link, and uses the link up. Returns the user id. */
    public function redeem(string $token, string $purpose, string $password, string $from = ''): int
    {
        $row = $this->liveToken($token, $purpose);
        $userId = (int) $row['user_id'];
        $user = $this->user($userId);

        $this->transaction(function () use ($row, $user, $password, $purpose, $userId, $from) {
            $this->setPassword($user, $password);
            $this->db->table('auth_tokens')->where('id', (int) $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);
            if ($user['status'] === 'invited') {
                $this->db->table('users')->where('id', $userId)->update(['status' => 'active']);
            }
            $this->log($userId, $purpose === 'invite' ? 'auth.accepted' : 'auth.reset', match (true) {
                $purpose === 'reset'           => 'Reset their password from an emailed link',
                $user['status'] === 'invited'  => 'Accepted the invitation and chose a password',
                default                        => 'Chose a new password from a one-time link',
            }, $userId, $from);
        });

        return $userId;
    }

    // ------------------------------------------------------------------
    // The second factor
    // ------------------------------------------------------------------

    /**
     * What the sign-in screen needs about the user part-way through: who they are,
     * how they give a second factor, and what they may set up.
     */
    public function factorState(int $userId): array
    {
        $user = $this->user($userId);

        return [
            'name'      => $user['name'],
            'email'     => self::masked($user['email']),
            'method'    => $user['mfa_enabled'] ? $user['mfa_method'] : null,
            'required'  => $this->mustHaveMfa($userId),
            'methods'   => $this->offeredMethods(),
            'recoveryLeft' => (int) $this->value('SELECT COUNT(*) FROM {user_recovery_codes} WHERE user_id = ? AND used_at IS NULL', [$userId]),
        ];
    }

    /** The methods Config\Auth offers that this instance can actually provide. */
    public function offeredMethods(): array
    {
        $out = [];
        foreach ($this->config->mfaMethods as $method) {
            if (isset(self::METHODS[$method])) {
                $out[] = [
                    'key' => $method, 'label' => self::METHODS[$method],
                    // The shared secret is encrypted with the application key; without one
                    // it cannot be stored, so the app option is there but says why it is off.
                    'available' => $method !== 'totp' || Secret::configured(),
                ];
            }
        }

        return $out;
    }

    /**
     * Checks a second-factor code: from the authenticator app, from the latest
     * emailed code, or one of the recovery codes. Returns how it was given
     * ("authenticator app", "emailed code", "a recovery code"), or null.
     */
    public function verifyFactor(int $userId, string $code): ?string
    {
        $user = $this->user($userId);
        $code = trim($code);

        if (self::looksLikeRecoveryCode($code)) {
            return $this->useRecoveryCode($userId, $code) ? 'a recovery code' : null;
        }

        return match ($user['mfa_method']) {
            'totp'  => $this->verifyTotp($user, Secret::open($user['mfa_secret']), $code) ? 'authenticator app' : null,
            'email' => $this->verifyEmailCode($userId, $code) ? 'emailed code' : null,
            default => null,
        };
    }

    /**
     * Sends a six-digit code by email. Earlier codes stop working. Returns whether
     * the email went.
     */
    public function sendEmailCode(int $userId): bool
    {
        $recent = $this->value("SELECT created_at FROM {auth_tokens} WHERE user_id = ? AND purpose = 'email_code' AND used_at IS NULL AND created_at > ? ORDER BY id DESC LIMIT 1",
            [$userId, date('Y-m-d H:i:s', time() - 30)]);
        if ($recent !== null) {
            throw new RuleViolation('A code was sent a moment ago. Give it half a minute to arrive before asking for another.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');
        $this->db->table('auth_tokens')->where(['user_id' => $userId, 'purpose' => 'email_code'])->where('used_at', null)->update(['used_at' => $now]);
        $this->insert('auth_tokens', [
            'user_id' => $userId, 'purpose' => 'email_code', 'token_hash' => password_hash($code, PASSWORD_DEFAULT, self::CODE_COST),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->config->emailCodeMinutes * 60), 'attempts' => 0, 'created_at' => $now,
        ]);

        return AuthMail::signInCode($this->user($userId), $code);
    }

    /**
     * A new authenticator secret, not yet saved: it is held in the session until the
     * first code from the app proves the app has it.
     *
     * @return array{secret: string, grouped: string, uri: string, issuer: string, account: string}
     */
    public function newTotp(int $userId): array
    {
        if (!Secret::configured()) {
            throw new RuleViolation('An authenticator app cannot be set up until the application has an encryption key (encryption.key in .env). Use a code by email for now.');
        }
        $user = $this->user($userId);
        $secret = Totp::secret();
        $issuer = $this->config->issuer !== '' ? $this->config->issuer : \App\Libraries\Brand::current()['name'];

        return ['secret' => $secret, 'grouped' => Totp::grouped($secret), 'uri' => Totp::uri($secret, $user['email'], $issuer), 'issuer' => $issuer, 'account' => $user['email']];
    }

    /**
     * Saves a second factor once a code from it has been given, and issues fresh
     * recovery codes. $secret is the authenticator secret, or null for email.
     *
     * @return list<string> the recovery codes, shown once
     */
    public function enrol(int $userId, string $method, ?string $secret, string $code, int $actorId): array
    {
        if (!in_array($method, array_column(array_filter($this->offeredMethods(), static fn ($m) => $m['available']), 'key'), true)) {
            throw new RuleViolation('That second step is not offered here.');
        }
        $user = $this->user($userId);

        if ($method === 'totp') {
            $step = $secret === null ? null : Totp::verify($secret, $code);
            if ($step === null) {
                throw new RuleViolation('That code does not match. Check the app shows ' . $user['email'] . ' and type the six digits it shows now.');
            }
            $row = ['mfa_method' => 'totp', 'mfa_secret' => Secret::seal($secret), 'mfa_last_step' => $step];
        } else {
            if (!$this->verifyEmailCode($userId, $code)) {
                throw new RuleViolation('That code does not match the one we emailed, or it has expired. Ask for a new one.');
            }
            $row = ['mfa_method' => 'email', 'mfa_secret' => null, 'mfa_last_step' => null];
        }

        return $this->transaction(function () use ($userId, $row, $method, $actorId) {
            $this->db->table('users')->where('id', $userId)->update($row + ['mfa_enabled' => 1, 'updated_at' => Clock::timestamp()]);
            $this->log($userId, 'auth.mfa_enrolled', 'Set up ' . lcfirst(self::METHODS[$method]) . ' as their second sign-in step', $actorId);

            return $this->issueRecoveryCodes($userId);
        });
    }

    /** Replaces the recovery codes. The old ones stop working. */
    public function regenerateRecoveryCodes(int $userId): array
    {
        if (!(bool) $this->user($userId)['mfa_enabled']) {
            throw new RuleViolation('Recovery codes go with a second sign-in step. Set one up first.');
        }

        return $this->transaction(function () use ($userId) {
            $this->log($userId, 'auth.recovery_codes', 'Issued new recovery codes', $userId);

            return $this->issueRecoveryCodes($userId);
        });
    }

    /**
     * Removes a user's second factor. They set one up again at their next sign-in
     * if they must have one. $actorId is whoever did it: the user themselves, an
     * administrator for someone who has lost their phone, or null for `spark user:link`
     * run on the server.
     */
    public function removeFactor(int $userId, ?int $actorId): void
    {
        $user = $this->user($userId);
        if (!(bool) $user['mfa_enabled']) {
            throw new RuleViolation($user['name'] . ' has no second sign-in step to remove.');
        }
        if ($actorId === $userId && $this->mustHaveMfa($userId)) {
            throw new RuleViolation('Your roles require a second sign-in step, so it cannot be turned off. You can switch to a different one instead.');
        }

        $this->transaction(function () use ($userId, $actorId, $user) {
            $this->db->table('users')->where('id', $userId)->update(['mfa_enabled' => 0, 'mfa_method' => null, 'mfa_secret' => null, 'mfa_last_step' => null, 'updated_at' => Clock::timestamp()]);
            $this->db->table('user_recovery_codes')->where('user_id', $userId)->delete();
            $this->log($userId, 'auth.mfa_removed', match ($actorId) {
                $userId => 'Turned off their second sign-in step',
                null    => 'Second sign-in step reset for ' . $user['name'] . ' from the server (spark user:link)',
                default => 'Second sign-in step reset for ' . $user['name'],
            }, $actorId);
        });
    }

    // ------------------------------------------------------------------

    private function verifyTotp(array $user, string $secret, string $code): bool
    {
        $step = Totp::verify($secret, $code, $user['mfa_last_step'] === null ? null : (int) $user['mfa_last_step']);
        if ($step === null) {
            return false;
        }
        $this->db->table('users')->where('id', (int) $user['id'])->update(['mfa_last_step' => $step]);

        return true;
    }

    private function verifyEmailCode(int $userId, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        $row = $this->row("SELECT * FROM {auth_tokens} WHERE user_id = ? AND purpose = 'email_code' AND used_at IS NULL ORDER BY id DESC LIMIT 1", [$userId]);
        if ($row === null || strtotime($row['expires_at']) < time() || (int) $row['attempts'] >= $this->config->maxCodeAttempts) {
            return false;
        }
        if (preg_match('/^\d{6}$/', $code) !== 1 || !password_verify($code, $row['token_hash'])) {
            $this->db->table('auth_tokens')->where('id', (int) $row['id'])->set('attempts', 'attempts + 1', false)->update();

            return false;
        }
        $this->db->table('auth_tokens')->where('id', (int) $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    private static function looksLikeRecoveryCode(string $code): bool
    {
        return preg_match('/^[a-z0-9]{5}-?[a-z0-9]{5}$/i', $code) === 1;
    }

    private function useRecoveryCode(int $userId, string $code): bool
    {
        $code = strtolower(str_replace('-', '', $code));
        foreach ($this->rows('SELECT id, code_hash FROM {user_recovery_codes} WHERE user_id = ? AND used_at IS NULL', [$userId]) as $row) {
            if (password_verify($code, $row['code_hash'])) {
                $this->db->table('user_recovery_codes')->where('id', (int) $row['id'])->update(['used_at' => date('Y-m-d H:i:s')]);
                $left = (int) $this->value('SELECT COUNT(*) FROM {user_recovery_codes} WHERE user_id = ? AND used_at IS NULL', [$userId]);
                $this->log($userId, 'auth.recovery_used', 'Signed in with a recovery code (' . $left . ' left)', $userId);

                return true;
            }
        }

        return false;
    }

    /** @return list<string> "abcde-fghjk" */
    private function issueRecoveryCodes(int $userId): array
    {
        $this->db->table('user_recovery_codes')->where('user_id', $userId)->delete();
        $codes = [];
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $this->config->recoveryCodes; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $this->insert('user_recovery_codes', ['user_id' => $userId, 'code_hash' => password_hash($raw, PASSWORD_DEFAULT, self::CODE_COST), 'created_at' => $now]);
        }

        return $codes;
    }

    private function issueToken(int $userId, string $purpose, int $hours): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = date('Y-m-d H:i:s');
        // A new link replaces any earlier one for the same purpose.
        $this->db->table('auth_tokens')->where(['user_id' => $userId, 'purpose' => $purpose])->where('used_at', null)->update(['used_at' => $now]);
        $this->insert('auth_tokens', [
            'user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $hours * 3600), 'attempts' => 0, 'created_at' => $now,
        ]);

        return $token;
    }

    private function liveToken(string $token, string $purpose): array
    {
        $row = $token === '' ? null : $this->row(
            'SELECT t.*, u.name, u.email, u.status FROM {auth_tokens} t JOIN {users} u ON u.id = t.user_id WHERE t.token_hash = ? AND t.purpose = ?',
            [hash('sha256', $token), $purpose]
        );
        $what = $purpose === 'invite' ? 'invitation' : 'reset link';

        return match (true) {
            $row === null                            => throw new RuleViolation('This ' . $what . ' is not valid. Check you used the whole link from the email.'),
            $row['used_at'] !== null                 => throw new RuleViolation('This ' . $what . ' has already been used or replaced by a newer one.' . ($purpose === 'invite' ? ' Sign in with the password you chose, or reset it.' : '')),
            strtotime($row['expires_at']) < time()   => throw new RuleViolation('This ' . $what . ' has expired. ' . ($purpose === 'invite' ? 'Ask whoever invited you to send a new one.' : 'Ask for a new one from the sign-in page.')),
            $row['status'] === 'suspended'           => throw new RuleViolation('This account is suspended. Ask whoever manages users to reinstate it.'),
            default                                  => $row,
        };
    }

    /**
     * Sets a password under the policy: its matrix, and not one of the person's last
     * few. The one it replaces joins their history, trimmed to what the policy can
     * ever ask about.
     */
    private function setPassword(array $user, string $password): void
    {
        $this->assertStrong($password, $user);
        $this->assertNotReused($user, $password);
        $id = (int) $user['id'];
        $now = date('Y-m-d H:i:s');

        $this->db->table('users')->where('id', $id)->update([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => $now,
            'failed_sign_ins' => 0, 'locked_until' => null, 'updated_at' => Clock::timestamp(),
        ]);
        if ((string) $user['password_hash'] !== '') {
            $this->insert('password_history', ['user_id' => $id, 'password_hash' => $user['password_hash'], 'created_at' => $now]);
            $stale = array_column($this->rows('SELECT id FROM {password_history} WHERE user_id = ? ORDER BY id DESC', [$id]), 'id');
            $stale = array_slice($stale, PasswordPolicy::HISTORY_KEPT);
            if ($stale !== []) {
                $this->db->table('password_history')->whereIn('id', $stale)->delete();
            }
        }
    }

    /**
     * Refuses one of the last `history` passwords: the one held now counts as the
     * first, then the ones it replaced, newest first.
     */
    private function assertNotReused(array $user, string $password): void
    {
        $count = PasswordPolicy::current($this->db)['history'];
        if ($count <= 0) {
            return;
        }
        $hashes = array_filter([(string) $user['password_hash']]);
        if ($count > count($hashes)) {
            $earlier = $this->rows('SELECT password_hash FROM {password_history} WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) ($count - count($hashes)), [(int) $user['id']]);
            $hashes = [...$hashes, ...array_column($earlier, 'password_hash')];
        }
        foreach ($hashes as $hash) {
            if (password_verify($password, $hash)) {
                throw new RuleViolation($count === 1
                    ? 'Choose a password different from the one you have.'
                    : 'You have used that password before. Choose one that is not among your last ' . $count . '.');
            }
        }
    }

    /** Whether the user's password is older than the policy allows, so they must choose a new one to go on. */
    public function passwordExpired(int $userId): bool
    {
        $at = PasswordPolicy::expiresAt(PasswordPolicy::current($this->db), $this->user($userId)['password_changed_at']);

        return $at !== null && $at <= time();
    }

    /** When the user's password expires, as a Unix time, or null when it does not. */
    public function passwordExpiresAt(int $userId): ?int
    {
        return PasswordPolicy::expiresAt(PasswordPolicy::current($this->db), $this->user($userId)['password_changed_at']);
    }

    /**
     * Replaces an expired password at sign-in. The person has already given the old
     * one (and their second step), so only the new one is asked for.
     */
    public function renewExpired(int $userId, string $password, string $from = ''): void
    {
        $user = $this->user($userId);
        if (password_verify($password, (string) $user['password_hash'])) {
            throw new RuleViolation('Choose a password different from the one that expired.');
        }
        $this->transaction(function () use ($user, $password, $userId, $from) {
            $this->setPassword($user, $password);
            $this->log($userId, 'auth.password', 'Chose a new password when the old one expired', $userId, $from);
        });
    }

    /** The password policy of Settings → Users (App\Libraries\PasswordPolicy), in words the person can act on. */
    public function assertStrong(string $password, array $user): void
    {
        PasswordPolicy::check(PasswordPolicy::current($this->db), $password, $user);
    }

    private function user(int $userId): array
    {
        return $this->row('SELECT * FROM {users} WHERE id = ?', [$userId]) ?? throw new RuleViolation('That user does not exist.');
    }

    private function log(int $userId, string $action, string $summary, ?int $actorId, string $from = ''): void
    {
        $this->insert('audit_events', [
            'occurred_at' => Clock::timestamp(), 'actor_user_id' => $actorId, 'action' => $action,
            'object_type' => 'user', 'object_id' => $userId, 'summary' => mb_substr($summary, 0, 255),
            'ip_address' => $from === '' ? null : mb_substr($from, 0, 45),
        ]);
    }

    /**
     * How many wrong passwords in a row lock an account and for how long, from the
     * password policy. Read once per request: a sign-in checks it at most twice.
     *
     * @return array{attempts: int, minutes: int}
     */
    private function lockout(): array
    {
        return $this->lockout ??= PasswordPolicy::lockout(PasswordPolicy::current($this->db));
    }

    private function minutesUntil(string $at): string
    {
        $minutes = max(1, (int) ceil((strtotime($at) - time()) / 60));

        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }

    /** "w•••••@elog.or.ke" — enough to recognise, not enough to harvest. */
    public static function masked(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2) + [1 => ''];

        return mb_substr($local, 0, 1) . str_repeat('•', max(2, mb_strlen($local) - 1)) . '@' . $domain;
    }
}
