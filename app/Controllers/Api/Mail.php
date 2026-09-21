<?php

namespace App\Controllers\Api;

use App\Libraries\AuthMail;
use App\Libraries\SettingsAccess;
use App\Repositories\MailRepository;
use App\Repositories\RuleViolation;

/**
 * Settings → Integrations → Email: the SMTP server invitations, password resets
 * and sign-in codes go out through (App\Repositories\MailRepository).
 *
 * Like M-Pesa, it saves as changes are made rather than into the settings draft,
 * because the password is written once and never read back. It is shown only to
 * a user who can change it — a role with settings.integrations — who can also send
 * a test message. Every change is in the audit log.
 */
class Mail extends BaseApiController
{
    public function index()
    {
        if (!$this->canManage()) {
            return $this->denied($this->actor()['role'] . ' cannot see the mail server. That needs a role with settings.integrations.');
        }

        return $this->json($this->payload());
    }

    /** Body: any of host, port, crypto, username, fromEmail, fromName; password when typed; clearPassword. */
    public function save()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        try {
            $changes = (new MailRepository())->save($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $n = count($changes);

        return $this->json([
            'message' => $n === 0 ? 'No changes to save.' : 'Mail server saved. ' . ($n === 1 ? 'The change is' : 'All ' . $n . ' changes are') . ' in the audit log.',
            'changes' => $changes,
        ] + $this->payload());
    }

    /** Sends a test message to the person asking, through the server mail would use now. */
    public function test()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $actor = $this->actor();
        $user = ['email' => (string) $actor['email'], 'name' => (string) $actor['name']];
        $sent = AuthMail::test($user);
        (new MailRepository())->logTest($user['email'], $sent, $this->actorId());

        return $this->json([
            'ok'      => $sent,
            'message' => $sent
                ? 'Test message sent to ' . $user['email'] . '. If it does not arrive, check the spam folder and the sender address.'
                : 'The mail server did not take the message. Check the server, port, encryption and credentials'
                    . (ENVIRONMENT === 'production' ? '.' : ' — the reason is in writable/logs.'),
        ] + $this->payload());
    }

    // ------------------------------------------------------------------

    private function payload(): array
    {
        $repo = new MailRepository();

        return ['mail' => $repo->server(), 'options' => $repo->options(), 'canManage' => $this->canManage()];
    }

    private function canManage(): bool
    {
        return SettingsAccess::of($this->actor())->canEdit('Integrations');
    }

    private function cannotManage()
    {
        if ($this->canManage()) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON([
            'error' => $this->actor()['role'] . ' cannot change the mail server. That needs a role with settings.integrations — every change is recorded in the audit log.',
        ]);
    }
}
