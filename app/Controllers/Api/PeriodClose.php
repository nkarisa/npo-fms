<?php

namespace App\Controllers\Api;

use App\Repositories\PeriodCloseRepository;
use App\Repositories\PeriodRepository;
use App\Repositories\RuleViolation;

/** The period close checklist, closing and reopening a month, and the close pack. */
class PeriodClose extends BaseApiController
{
    /** ?period=2026-08 (defaults to the earliest open period) &year=2026 (defaults to the period's year). */
    public function index()
    {
        $repo   = new PeriodCloseRepository();
        $period = $this->periodOr404($repo, $this->request->getGet('period') ?: (new PeriodRepository())->currentName());
        if (!is_array($period)) {
            return $period;
        }
        $year = (int) ($this->request->getGet('year') ?: substr($period['starts_on'], 0, 4));

        return $this->json($repo->overview($period, $year, $this->actor()));
    }

    public function pack($code)
    {
        $repo   = new PeriodCloseRepository();
        $period = $this->periodOr404($repo, (string) $code);

        return is_array($period) ? $this->json($repo->pack($period)) : $period;
    }

    /** Body: {"step": "review", "done": true} */
    public function confirm($code)
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->write((string) $code, fn ($repo, $period) => $repo->confirm($period, (string) ($body['step'] ?? ''), (bool) ($body['done'] ?? false), $this->actor(), $this->actorId()));
    }

    public function close($code)
    {
        return $this->write((string) $code, fn ($repo, $period) => $repo->close($period, $this->actor(), $this->actorId()), fn ($p) => $p['name'] . ' closed. Posting into it is now blocked and the balances are carried forward.');
    }

    /** Body: {"reason": "…"} (optional) */
    public function reopen($code)
    {
        $body = $this->request->getJSON(true) ?? [];

        return $this->write((string) $code, fn ($repo, $period) => $repo->reopen($period, $this->actor(), $this->actorId(), trim((string) ($body['reason'] ?? ''))),
            fn ($p) => $p['name'] . ' reopened. The Executive Director authorisation has been withdrawn and the close must be retaken.');
    }

    private function write(string $code, callable $action, ?callable $message = null)
    {
        $repo   = new PeriodCloseRepository();
        $period = $this->periodOr404($repo, $code);
        if (!is_array($period)) {
            return $period;
        }

        try {
            $action($repo, $period);
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        $period = $repo->period($code);

        return $this->json(['message' => $message === null ? '' : $message($period)] + $repo->overview($period, (int) substr($period['starts_on'], 0, 4), $this->actor()));
    }

    private function periodOr404(PeriodCloseRepository $repo, string $key)
    {
        return $repo->period($key) ?? $this->response->setStatusCode(404)->setJSON(['error' => $key . ' is not an accounting period.']);
    }
}
