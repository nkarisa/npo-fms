<?php

namespace App\Controllers\Api;

use App\Repositories\MpesaRepository;
use App\Repositories\RuleViolation;

/**
 * Settings → Integrations → M-Pesa: the Safaricom short code the organisation
 * collects and pays through.
 *
 * This section saves as changes are made rather than into the settings draft,
 * because a credential is written once and never read back — it cannot sit in a
 * draft in the browser. Anyone may look at how the integration is set up; only a
 * user holding settings.manage (the Finance Manager) can change it or run a
 * connection check, and every change is in the audit log.
 *
 * No credential is ever served: the screen is told which are set and their last
 * four characters.
 */
class Mpesa extends BaseApiController
{
    public function index()
    {
        $repo = new MpesaRepository();

        return $this->json([
            'mpesa'     => $repo->integration(),
            'options'   => $repo->options(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Body: any of environment, shortcode, shortcodeKind, accountReference, account,
     * callbackBase, initiatorName, ceiling, collections, disbursements, autoMatch; a
     * credential only when it has been typed; `clear` names credentials to remove.
     */
    public function save()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        try {
            $changes = (new MpesaRepository())->save($this->request->getJSON(true) ?? [], $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $n = count($changes);

        return $this->json([
            'message' => $n === 0 ? 'No changes to save.' : 'M-Pesa settings saved. ' . ($n === 1 ? 'The change is' : 'All ' . $n . ' changes are') . ' in the audit log.',
            'changes' => $changes,
        ] + $this->payload());
    }

    /** Asks Safaricom for an access token with the credentials held. No money moves. */
    public function check()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $result = (new MpesaRepository())->check($this->actorId());

        return $this->json(['message' => $result['note'], 'ok' => $result['ok']] + $this->payload());
    }

    // ------------------------------------------------------------------

    private function payload(): array
    {
        $repo = new MpesaRepository();

        return ['mpesa' => $repo->integration(), 'options' => $repo->options(), 'canManage' => $this->canManage()];
    }

    private function canManage(): bool
    {
        return in_array('settings.manage', $this->actor()['permissions'] ?? [], true);
    }

    private function cannotManage()
    {
        if ($this->canManage()) {
            return null;
        }
        $actor = $this->actor();

        return $this->response->setStatusCode(403)->setJSON([
            'error' => $actor['role'] . ' cannot change the M-Pesa integration. Only the Finance Manager can — it moves money out of the organisation.',
        ]);
    }
}
