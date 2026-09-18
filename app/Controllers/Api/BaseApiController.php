<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\I18n;
use App\Repositories\Lookups;
use App\Repositories\AuthorityRequired;
use App\Repositories\RuleViolation;
use App\Repositories\UserRepository;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Common JSON response helper for the API endpoints.
 *
 * Every response carries the locale it was rendered in, and its display strings
 * are translated on the way out. Figures, account codes, references and dates are
 * deliberately left in the reporting locale (en-KE · KES) whichever language is
 * asked for — a report has to reconcile line-for-line to the ledger in every
 * language, so only the wording moves.
 */
abstract class BaseApiController extends BaseController
{
    protected I18n $i18n;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->i18n = I18n::forRequest($request);
    }

    /**
     * The acting user. There is no authentication yet; "Act as" in the user menu
     * sets this cookie so one person can play preparer and approver in turn.
     *
     * With no cookie the instance falls back to whoever can change settings — on a
     * freshly installed instance that is the only person there is.
     */
    protected function actor(): array
    {
        $users = new UserRepository();
        $email = $this->request->getCookie('elog_actor');

        return ($email ? $users->actor($email) : null) ?? $users->actor($this->defaultActor()) ?? $users->actors()[0];
    }

    /** The first active user who can change settings, whatever this organisation calls them. */
    private function defaultActor(): string
    {
        $lookups = new Lookups();
        $manager = $lookups->settingsManagerId();

        return $manager === null ? '' : (string) ($lookups->users()[$manager]['email'] ?? '');
    }

    /** The acting user's id, for recording who did what. */
    protected function actorId(): int
    {
        return (int) (new Lookups())->userId($this->actor()['email']);
    }

    /** A refused write, in the rule's own words. */
    protected function refused(RuleViolation $e)
    {
        return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()] + ($e instanceof AuthorityRequired ? ['needsAuthority' => true] : []));
    }

    protected function t(string $s): string
    {
        return $this->i18n->t($s);
    }

    protected function json(array $data)
    {
        // Translate first, then attach the envelope — the locale block describes
        // the response and is not itself subject to it.
        $data           = $this->i18n->translateResponse($data);
        $data['locale'] = $this->localeBlock();

        return $this->response->setJSON($data);
    }

    /**
     * What the shell needs to render the response: which language it is in, which
     * way the page runs, and how much of that language is actually reviewed.
     */
    protected function localeBlock(): array
    {
        $l = $this->i18n->locale();

        return [
            'code'      => $this->i18n->code(),
            'native'    => $l['native'],
            'label'     => $l['label'],
            'dir'       => $this->i18n->dir(),
            'coverage'  => $this->i18n->coverage(),
            'source'    => $this->i18n->isSource(),
            'fallback'  => $this->i18n->fallbackMode(),
            'reporting' => [
                'locale'   => I18n::REPORTING_LOCALE,
                'currency' => I18n::REPORTING_CURRENCY,
                'locked'   => I18n::formatsLocked(),
            ],
        ];
    }
}
