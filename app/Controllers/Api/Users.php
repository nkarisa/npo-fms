<?php

namespace App\Controllers\Api;

use App\Libraries\SettingsAccess;
use App\Repositories\AuthRepository;
use App\Repositories\RoleRepository;
use App\Repositories\RuleViolation;
use App\Repositories\SettingsRepository;

/**
 * Settings → Users: who holds which roles, and the account itself — suspending,
 * reinstating, resending an invitation, and resetting a lost second factor.
 *
 * Needs users.manage. Each change saves as it is made and is in the audit log.
 * Inviting someone new is Api\Settings::invite.
 */
class Users extends BaseApiController
{
    /** Body: {access: [{role, entities: 'all' | [entity codes]}]}. Replaces the user's roles. */
    public function access(int $id)
    {
        return $this->change(function () use ($id) {
            (new RoleRepository())->setAccess($id, (array) ($this->request->getJSON(true)['access'] ?? []), $this->actorId());

            return 'Access saved. It applies from their next request.';
        });
    }

    public function suspend(int $id)
    {
        return $this->change(function () use ($id) {
            (new RoleRepository())->setStatus($id, true, $this->actorId());

            return 'Suspended. They are signed out and cannot sign in until reinstated.';
        });
    }

    public function reinstate(int $id)
    {
        return $this->change(function () use ($id) {
            (new RoleRepository())->setStatus($id, false, $this->actorId());

            return 'Reinstated. They can sign in again.';
        });
    }

    /** Sends a new invitation link, replacing any earlier one. */
    public function invite(int $id)
    {
        return $this->change(fn () => (new AuthRepository())->invite($id, $this->actorId())
            ? 'A link to choose a password is on its way. Any earlier link no longer works.'
            : $this->unsent());
    }

    /** Removes a second factor someone has lost; they set up a new one at their next sign-in. */
    public function resetMfa(int $id)
    {
        return $this->change(function () use ($id) {
            (new AuthRepository())->removeFactor($id, $this->actorId());

            return 'Second sign-in step reset. They will set up a new one when they next sign in.';
        });
    }

    // ------------------------------------------------------------------

    private function change(callable $do)
    {
        if (!$this->can('users.manage')) {
            return $this->denied($this->actor()['role'] . ' cannot manage users. That needs a role with users.manage — every change is recorded in the audit log.');
        }
        try {
            $message = $do();
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $settings = new SettingsRepository();

        return $this->json(['message' => $message, 'users' => $settings->users(), 'roles' => $settings->roles(), 'roleDetail' => (new RoleRepository())->rolesFor($this->actorId())]
            + (SettingsAccess::of($this->actor())->canSee('Audit log') ? ['audit' => $settings->auditLog()] : []));
    }

    private function unsent(): string
    {
        return ENVIRONMENT === 'production'
            ? 'The invitation could not be emailed. Check the mail settings (email.* in .env) and try again.'
            : 'The invitation could not be emailed (no mail server is set up). The link is in the application log, writable/logs.';
    }
}
