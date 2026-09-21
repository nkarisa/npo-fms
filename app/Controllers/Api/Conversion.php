<?php

namespace App\Controllers\Api;

use App\Libraries\Brand;
use App\Libraries\SettingsAccess;
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
 * Only a user holding settings.ledger (the Finance Manager, out of the box) can
 * load or discard a conversion; anyone who can see the section can look at what
 * was carried (App\Libraries\SettingsAccess).
 */
class Conversion extends BaseApiController
{
    public function index()
    {
        if ($refusal = $this->cannotSee()) {
            return $refusal;
        }

        return $this->json($this->options());
    }

    /** What the screen offers, with whether this user may act on it. */
    private function options(): array
    {
        return ['canManage' => SettingsAccess::of($this->actor())->canEdit('Opening balances'),
            'role' => $this->actor()['role']] + (new ConversionRepository())->options();
    }

    /**
     * The trial balance to fill in, as a CSV download.
     *
     * Served rather than described: the columns are the ones the reader looks for, so
     * a file built from this loads back with no mapping, and the organisation's own
     * accounts are already in it with the coding they default to.
     */
    public function template()
    {
        if ($refusal = $this->cannotSee()) {
            return $refusal;
        }
        $template = (new ConversionRepository())->template((string) $this->request->getGet('period'));
        $name = trim(Brand::current()['name'] . ' ' . $template['filename']);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $name) . '"')
            ->setBody($template['csv']);
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

    private function cannotSee()
    {
        return SettingsAccess::of($this->actor())->canSee('Opening balances') ? null
            : $this->denied($this->actor()['role'] . ' cannot see the opening balances. That needs a role with settings.view.');
    }

    /** Refuses a change by anyone who cannot change the section, as the Settings screen does. */
    private function cannotManage()
    {
        $actor = $this->actor();
        if (SettingsAccess::of($actor)->canEdit('Opening balances')) {
            return null;
        }

        return $this->response->setStatusCode(403)->setJSON([
            'error' => $actor['role'] . ' cannot carry opening balances onto the ledger. That needs a role with settings.ledger — the load is recorded in the audit log.',
        ]);
    }
}
