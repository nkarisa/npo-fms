<?php

namespace App\Commands;

use App\Repositories\AuthRepository;
use App\Repositories\RuleViolation;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Prints a one-time link for a user to choose a new password — for when the
 * install's link was lost, mail is not set up yet, or the only person who manages
 * users is locked out.
 *
 *     php spark user:link w.kamau@elog.or.ke
 *     php spark user:link w.kamau@elog.or.ke --reset-mfa   (also removes a lost second step)
 *
 * Whoever can run spark on the server can already read the database, so this
 * gives nothing away that the server does not; it is recorded in the audit log.
 */
class UserLink extends BaseCommand
{
    protected $group       = 'Setup';
    protected $name        = 'user:link';
    protected $description = 'Prints a one-time link for a user to choose a new password.';
    protected $usage       = 'user:link <email> [--reset-mfa]';
    protected $arguments   = ['email' => 'The user\'s email address.'];
    protected $options     = ['--reset-mfa' => 'Also removes their second sign-in step; they set up a new one when they next sign in.'];

    public function run(array $params)
    {
        $email = mb_strtolower(trim((string) ($params[0] ?? '')));
        $user = $email === '' ? null : db_connect()->table('users')->where('LOWER(email)', $email)->get()->getRowArray();
        if ($user === null) {
            CLI::error($email === '' ? 'Give the user\'s email: `php spark user:link someone@example.org`.' : 'There is no user ' . $email . '.');

            return EXIT_ERROR;
        }
        if ($user['status'] === 'suspended') {
            CLI::error($user['name'] . ' is suspended. Reinstate them in Settings → Users first.');

            return EXIT_ERROR;
        }

        $auth = new AuthRepository();
        try {
            if (array_key_exists('reset-mfa', $params) || CLI::getOption('reset-mfa')) {
                if ((bool) $user['mfa_enabled']) {
                    $auth->removeFactor((int) $user['id'], null);
                    CLI::write('Second sign-in step removed.', 'yellow');
                }
            }
            $link = $auth->setPasswordLink((int) $user['id']);
        } catch (RuleViolation $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write('One-time link for ' . $user['name'] . ' (works once, for seven days; any earlier link stops working):', 'green');
        CLI::write('  ' . $link);

        return EXIT_SUCCESS;
    }
}
