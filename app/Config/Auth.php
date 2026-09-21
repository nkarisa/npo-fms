<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Signing in: passwords, the second factor, lockout and the session.
 *
 * Every value can be set from .env as `auth.<name>` (e.g. `auth.mfaRequired = privileged`).
 */
class Auth extends BaseConfig
{
    /**
     * Who must have a second factor before they can use the application:
     *
     * - `all`: everyone (the default — every user touches financial records).
     * - `privileged`: anyone holding one of $privileged below. Others may enrol.
     * - `optional`: nobody is made to; anyone may enrol from My account.
     *
     * A user who must and has not enrolled is taken straight to enrolment after
     * their password, and can do nothing else until it is done.
     */
    public string $mfaRequired = 'all';

    /** Permissions that make a second factor mandatory when $mfaRequired is `privileged`. */
    public array $privileged = ['journal.approve', 'journal.post', 'settings.organisation', 'settings.ledger', 'settings.approvals',
        'settings.banking', 'settings.integrations', 'settings.payroll', 'users.manage', 'period.authorise'];

    /** The second factors offered, in the order they are offered. */
    public array $mfaMethods = ['totp', 'email'];

    /** The name an authenticator app files the account under. Blank uses the application name. */
    public string $issuer = '';

    /** How long an emailed sign-in code works, in minutes. */
    public int $emailCodeMinutes = 10;

    /** Wrong codes allowed against one emailed code, or one sign-in, before starting again. */
    public int $maxCodeAttempts = 5;

    /** Consecutive wrong passwords before the account is locked, and for how long (minutes). */
    public int $maxFailedSignIns = 5;
    public int $lockMinutes = 15;

    /** Signed out after this many minutes without a request. */
    public int $idleMinutes = 30;

    /** How long an invitation and a password-reset link stay valid, in hours. */
    public int $inviteHours = 24 * 7;
    public int $resetHours = 1;

    public int $minPasswordLength = 12;

    /** One-time recovery codes issued when a second factor is set up. */
    public int $recoveryCodes = 10;

    /**
     * Lets a signed-in user switch who they act as from the account menu, so one
     * person can walk an entry through preparation and approval on a training or
     * demonstration instance. Everything done is recorded against the person acted
     * as. Never turn this on for an instance holding real books.
     */
    public bool $actAs = false;
}
