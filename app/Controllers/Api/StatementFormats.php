<?php

namespace App\Controllers\Api;

use App\Repositories\RuleViolation;
use App\Repositories\StatementFormatRepository;

/**
 * Settings → Bank statements: the CSV format of each bank's statements and which
 * format each cash account uses. Anyone can look and try a sample file; changing a
 * format or an account's format is for an approver (the Finance Manager).
 */
class StatementFormats extends BaseApiController
{
    public function index()
    {
        $repo = new StatementFormatRepository();

        return $this->json([
            'formats'  => $repo->formats(),
            'accounts' => $repo->accounts(),
            'options'  => StatementFormatRepository::options(),
            'canManage' => $this->actor()['canApprove'],
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

    /** Body: {account, format: id|null}. */
    public function assign()
    {
        return $this->write(function (StatementFormatRepository $repo, array $body) {
            $format = isset($body['format']) && $body['format'] !== '' && $body['format'] !== null ? (int) $body['format'] : null;
            $repo->assign((string) ($body['account'] ?? ''), $format, $this->actorId());
            $account = current(array_filter($repo->accounts(), static fn ($a) => $a['code'] === (string) $body['account']));

            return ['message' => $account['short'] . ($format === null ? ' has no statement format now.' : ' statements will be read as ' . $account['format'] . '.')];
        });
    }

    /** Multipart: `file` (a sample statement) and `payload` (the format as edited so far). */
    public function sample()
    {
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

    /** Runs a change for an approver and answers with its message and the formats as they now stand. */
    private function write(callable $action)
    {
        $actor = $this->actor();
        if (!$actor['canApprove']) {
            return $this->response->setStatusCode(403)->setJSON(['error' => $actor['role'] . ' cannot change statement formats. Ask the Finance Manager.']);
        }

        try {
            $repo = new StatementFormatRepository();
            $out = $action($repo, $this->request->getJSON(true) ?? []);
            $repo = new StatementFormatRepository();

            return $this->json($out + ['formats' => $repo->formats(), 'accounts' => $repo->accounts()]);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }
    }
}
