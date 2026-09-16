<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\I18n;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Common JSON response helper for the prototype-data API endpoints.
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

        $this->i18n = new I18n($this->requestedLocale(), $this->requestedFallback());
    }

    /**
     * Explicit ?locale= wins, then the X-Locale header the shell sends, then the
     * saved preference cookie, then the browser's Accept-Language. Anything
     * unrecognised falls back to the source language rather than erroring — a
     * bad locale should never cost someone their ledger.
     */
    private function requestedLocale(): string
    {
        $candidates = [
            $this->request->getGet('locale'),
            $this->request->getHeaderLine('X-Locale') ?: null,
            $this->request->getCookie('elog_locale'),
        ];

        foreach ($candidates as $c) {
            if (I18n::isKnown($c)) {
                return $c;
            }
        }

        return $this->negotiated() ?? I18n::SOURCE_LOCALE;
    }

    /** First Accept-Language entry we actually publish, ignoring q-weights ordering nuance. */
    private function negotiated(): ?string
    {
        $header = $this->request->getHeaderLine('Accept-Language');
        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            if (I18n::isKnown($tag)) {
                return $tag;
            }
            // "fr-CH" and "ar-EG" should both land on the language we publish.
            $base = explode('-', $tag)[0];
            if (I18n::isKnown($base)) {
                return $base;
            }
        }

        return null;
    }

    private function requestedFallback(): string
    {
        $mode = $this->request->getGet('fallback') ?: $this->request->getCookie('elog_i18n_fallback');

        return is_string($mode) ? $mode : I18n::DEFAULT_FALLBACK;
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
