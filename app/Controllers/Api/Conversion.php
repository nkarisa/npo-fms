<?php

namespace App\Controllers\Api;

use App\Repositories\ConversionRepository;
use App\Repositories\RuleViolation;

/**
 * Carrying opening balances from a legacy system (Settings → Opening balances).
 *
 * The same endpoint previews and loads: `preview` answers every check and changes
 * nothing, `load` does the same and, if every check passed, writes the balances as
 * a draft journal. Nothing here posts to the ledger — the draft is submitted and
 * approved on the Journals screen, so the conversion goes through the same
 * segregation of duties and approval limits as every other entry.
 *
 * Only a user holding settings.manage (the Finance Manager) can load or discard a
 * conversion; everyone else can look at what was carried.
 */
class Conversion extends BaseApiController
{
    public function index()
    {
        return $this->json($this->options());
    }

    /** What the screen offers, with whether this user may act on it. */
    private function options(): array
    {
        return ['canManage' => in_array('settings.manage', $this->actor()['permissions'] ?? [], true),
            'role' => $this->actor()['role']] + (new ConversionRepository())->options();
    }

    /** Multipart, with the trial balance in `file`; body: period, source, decimal. */
    public function preview()
    {
        return $this->read(false);
    }

    public function load()
    {
        return $this->read(true);
    }

    public function discard()
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        try {
            $result = (new ConversionRepository())->discard($this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($result + $this->options());
    }

    private function read(bool $commit)
    {
        if ($refusal = $this->cannotManage()) {
            return $refusal;
        }

        $file = $this->request->getFile('file');
        try {
            if ($file === null || !$file->isValid()) {
                throw new RuleViolation($file === null || $file->getError() === UPLOAD_ERR_NO_FILE
                    ? 'Choose the trial balance exported from the old system.'
                    : $file->getClientName() . ' did not upload: ' . $file->getErrorString());
            }

            $result = (new ConversionRepository())->run(
                (string) $this->request->getPost('period'),
                ['path' => $file->getTempName(), 'name' => $file->getClientName(), 'size' => (int) $file->getSize(), 'mime' => (string) $file->getMimeType()],
                ['source' => (string) $this->request->getPost('source'), 'decimal' => (string) $this->request->getPost('decimal')],
                $this->actorId(),
                $commit
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json($result + $this->options());
    }

    /** Refuses a change by anyone without settings.manage, as the Settings screen does. */
    private function cannotManage()
    {
        $actor = $this->actor();
        if (in_array('settings.manage', $actor['permissions'] ?? [], true)) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON([
            'error' => $actor['role'] . ' cannot carry opening balances onto the ledger. Only the Finance Manager can — the load is recorded in the audit log.',
        ]);
    }
}
