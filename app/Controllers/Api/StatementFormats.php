<?php

namespace App\Controllers\Api;

use App\Libraries\SettingsAccess;
use App\Repositories\RuleViolation;
use App\Repositories\StatementFormatRepository;

/**
 * Settings → Bank statements: the CSV format of each bank's statements and which
 * format each cash account uses. Anyone who can see the section can look and try a
 * sample file; changing a format, or which one an account uses, needs a role with
 * settings.banking (App\Libraries\SettingsAccess).
 */
class StatementFormats extends BaseApiController
{
    public function index()
    {
        if ($refusal = $this->cannotSee()) {
            return $refusal;
        }
        $repo = new StatementFormatRepository();

        return $this->json([
            'formats'  => $repo->formats(),
            'accounts' => $repo->cashAccounts(),
            'candidates' => $repo->cashCandidates(),
            'kinds'    => StatementFormatRepository::KINDS,
            'options'  => StatementFormatRepository::options(),
            'canManage' => SettingsAccess::of($this->actor())->canEdit('Bank statements'),
        ]);
    }

    /** Body: the format. */
    public function create()
    {
        return $this->write(fn (StatementFormatRepository $repo, array $body) => [
            'message' => 'Statement format ' . ($f = $repo->save(null, $body, $this->actorId()))['name'] . ' saved.', 'format' => $f,
        ]);
    }

    /** Body: the format. */
    public function update($id)
    {
        return $this->write(fn (StatementFormatRepository $repo, array $body) => [
            'message' => 'Statement format ' . ($f = $repo->save((int) $id, $body, $this->actorId()))['name'] . ' saved.', 'format' => $f,
        ]);
    }

    public function delete($id)
    {
        return $this->write(function (StatementFormatRepository $repo) use ($id) {
            $name = ($repo->find((int) $id) ?? throw new RuleViolation('That statement format no longer exists.'))['name'];
            $repo->delete((int) $id, $this->actorId());

            return ['message' => 'Statement format ' . $name . ' deleted.'];
        });
    }

    /** Body: {code, name, shortName, kind, bankName, accountNumber, currency}. */
    public function createAccount()
    {
        return $this->write(fn (StatementFormatRepository $repo, array $body) => [
            'message' => ($a = $repo->createAccount($body, $this->actorId()))['name'] . ' opened on ' . $a['code']
                . '. Assign it a statement format before loading a statement.',
            'account' => $a,
        ]);
    }

    /**
     * Corrects a cash account while nothing refers to it yet. Body: any of {code,
     * name, shortName, kind, bankName, accountNumber, currency}.
     */
    public function updateAccount(string $code)
    {
        return $this->write(function (StatementFormatRepository $repo, array $body) use ($code) {
            $a = $repo->updateAccount($code, $body, $this->actorId());

            return ['message' => $a['changes'] === [] ? 'No changes to save.' : $a['name'] . ' saved: ' . implode('; ', $a['changes']) . '.', 'account' => $a];
        });
    }

    /** Body: {account, format: id|null}. */
    public function assign()
    {
        return $this->write(function (StatementFormatRepository $repo, array $body) {
            $format = isset($body['format']) && $body['format'] !== '' && $body['format'] !== null ? (int) $body['format'] : null;
            $repo->assign((string) ($body['account'] ?? ''), $format, $this->actorId());
            $account = current(array_filter($repo->cashAccounts(), static fn ($a) => $a['code'] === (string) $body['account']));

            return ['message' => $account['short'] . ($format === null ? ' has no statement format now.' : ' statements will be read as ' . $account['format'] . '.')];
        });
    }

    /** Multipart: `file` (a sample statement) and `payload` (the format as edited so far). */
    public function sample()
    {
        if ($refusal = $this->cannotSee()) {
            return $refusal;
        }
        $file = $this->request->getFile('file');
        if ($file === null || !$file->isValid()) {
            return $this->refused(new RuleViolation('Choose a sample statement file.'));
        }
        if ($file->getSize() > \App\Repositories\StatementImportRepository::MAX_BYTES) {
            return $this->refused(new RuleViolation($file->getClientName() . ' is larger than 5 MB.'));
        }

        $format = json_decode((string) $this->request->getPost('payload'), true) ?? [];

        return $this->json(['sample' => (new StatementFormatRepository())->sample((string) file_get_contents($file->getTempName()), $format)]);
    }

    // ------------------------------------------------------------------

    private function cannotSee()
    {
        return SettingsAccess::of($this->actor())->canSee('Bank statements') ? null
            : $this->denied($this->actor()['role'] . ' cannot see the bank statement formats. That needs a role with settings.view.');
    }

    /** Runs a change for someone who may make it, and answers with its message and the formats as they now stand. */
    private function write(callable $action)
    {
        $actor = $this->actor();
        if (!SettingsAccess::of($actor)->canEdit('Bank statements')) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot change statement formats. That needs a role with settings.banking.']);
        }

        try {
            $repo = new StatementFormatRepository();
            $out = $action($repo, $this->request->getJSON(true) ?? []);
            $repo = new StatementFormatRepository();

            return $this->json($out + ['formats' => $repo->formats(), 'accounts' => $repo->cashAccounts(), 'candidates' => $repo->cashCandidates()]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }
}
