<?php

namespace App\Controllers\Api;

use App\Libraries\I18n as I18nLib;
use App\Repositories\RuleViolation;
use App\Repositories\TranslationRepository;

/**
 * Language and translation — the v5 addition.
 *
 * Serves three things the prototype introduces: the language switcher in the top
 * bar, the "Language and translation" settings section, and the raise-a-label
 * queue that carries wording from whoever spotted it to the reviewer of record.
 *
 * Publishing a translation changes what a funder reads, so a decision on a raised
 * item is an approval event with an actor against it, not a text edit.
 */
class I18n extends BaseApiController
{
    /** Reasons a reader can attach when reporting wording rather than suggesting it. */
    private const REPORT_REASONS = ['Wrong term', 'Too long for the layout', 'Not the finance meaning', 'Machine-sounding'];

    private const OPEN_STATUSES = ['Raised', 'With reviewer'];

    /** Everything the switcher and the settings panel need for the active locale. */
    public function index()
    {
        $code = $this->i18n->code();

        return $this->json([
            'locales'   => array_map(fn ($l) => $this->localeRow($l), I18nLib::locales()),
            'fallbacks' => $this->fallbacks(),
            'coverage'  => I18nLib::coverageAreas($code),
            'catalogue' => I18nLib::catalogue(),
            'missing'   => I18nLib::missing($code),
            'locked'    => $this->lockedTerms(),
            'formats'   => $this->formats(),
            'requests'  => $this->requestRows(),
            'note'      => 'Language changes what people read, never what the ledger holds. Account codes, references, currency and posted amounts are untouched, and every switch is recorded against the user in the audit log.',
            'hint'      => $this->coverageHint(),
        ]);
    }

    private function localeRow(array $l): array
    {
        $missing = I18nLib::missing($l['code']);

        return [
            'code'      => $l['code'],
            'label'     => $l['label'],
            'native'    => $l['native'],
            'dir'       => $l['dir'],
            'dirLabel'  => $l['dir'] === 'rtl' ? 'RTL ←' : 'LTR →',
            'source'    => (bool) ($l['source'] ?? false),
            'coverage'  => $l['coverage'],
            'reviewer'  => $l['reviewer'],
            'status'    => $l['status'],
            'untranslated' => count($missing),
            'current'   => $l['code'] === $this->i18n->code(),
        ];
    }

    /** How an untranslated string is presented, shown against a worked example. */
    private function fallbacks(): array
    {
        $sample = 'Asset verification';

        return array_map(function ($f) use ($sample) {
            $shown = match ($f['key']) {
                'key'   => '[' . $sample . ']',
                'mark'  => $sample . ' ' . I18nLib::UNTRANSLATED_MARK,
                default => $sample,
            };

            return $f + ['example' => $shown, 'on' => $f['key'] === $this->i18n->fallbackMode()];
        }, I18nLib::FALLBACKS);
    }

    private function coverageHint(): string
    {
        if ($this->i18n->isSource()) {
            return 'English (UK) is the source language. Every string here is the wording other languages are translated from.';
        }

        $l = $this->i18n->locale();

        return $l['native'] . ' is ' . $l['coverage'] . '% translated by ' . $l['reviewer']
            . '. Strings without an approved translation fall back to English.';
    }

    /**
     * The approved wording for each regulated term, in the active language. These
     * reconcile to the audited statements, so they are read-only here — changing
     * one goes through requestUnlock().
     */
    private function lockedTerms(): array
    {
        $code = $this->i18n->code();

        return array_map(function ($t) use ($code) {
            $translated = $this->i18n->isSource()
                ? count(array_filter(I18nLib::targets(), static fn ($l) => !empty($t['tr'][$l['code']]))) . ' of ' . count(I18nLib::targets()) . ' languages'
                : ($t['tr'][$code] ?? 'Awaiting approved wording');

            return [
                'term'       => $t['term'],
                'note'       => $t['reason'],
                'unlock'     => $t['unlock'],
                'translated' => $translated,
            ];
        }, I18nLib::lockedTerms());
    }

    /**
     * What a figure looks like in the reporting locale against the browsing one.
     * With formats held, the left column is what everybody sees.
     */
    private function formats(): array
    {
        $base = I18nLib::formats(I18nLib::SOURCE_LOCALE);
        $loc  = I18nLib::formats($this->i18n->code());
        $held = I18nLib::formatsLocked();

        $rows = [
            ['label' => 'Expenditure this period', 'reporting' => I18nLib::REPORTING_CURRENCY . ' ' . $base['amount'], 'localised' => I18nLib::REPORTING_CURRENCY . ' ' . $loc['amount']],
            ['label' => 'Report period end', 'reporting' => $base['date'], 'localised' => $loc['date']],
            ['label' => 'Variance against phasing', 'reporting' => $base['pct'], 'localised' => $loc['pct']],
        ];

        return [
            'locked' => $held,
            'rows'   => $rows,
            'note'   => $held
                ? 'Held. Amounts, dates and percentages render exactly as posted, in every language, on screen and in every export.'
                : 'Not held. The same posted amount will appear in different notations to different users — a reconciliation risk on any figure a funder queries.',
        ];
    }

    /**
     * One catalogue string, as each language currently renders it.
     *
     * What the raise popover needs when a reader Cmd-clicks a label anywhere in
     * the shell: the wording they are looking at, whether it is locked, and which
     * languages already have it — so somebody reading English can still raise a
     * French label against its reviewer of record.
     */
    public function string()
    {
        // Named by ?str= rather than a path segment so that wording containing a
        // slash stays addressable. Naming nothing is a read with nothing behind
        // it, and refuses with its reason like any other.
        $str = trim((string) $this->request->getGet('str'));
        if ($str === '') {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => 'Name the string whose translations you want, as ?str=. Only wording in the language catalogue can be raised.',
            ]);
        }

        $lock = I18nLib::lockOn($str);
        if (!I18nLib::isTranslatable($str) && $lock === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => '"' . $str . '" is not a string in the translation catalogue.']);
        }

        // Deliberately not through json(): every string below is the *subject* of
        // translation rather than chrome around it, so running the response
        // translator over it would rewrite the very wording being reviewed.
        return $this->response->setJSON([
            'str'     => $str,
            'locked'  => $lock === null ? null : ['note' => $lock['reason'], 'unlock' => $lock['unlock']],
            'reasons' => self::REPORT_REASONS,
            'current' => $this->i18n->code(),
            'source'  => I18nLib::SOURCE_LOCALE,
            'locales' => array_map(static function ($l) use ($str, $lock) {
                $text = $lock !== null
                    ? ($lock['tr'][$l['code']] ?? null)
                    : (new TranslationRepository())->translation($l['code'], $str);

                return [
                    'code'       => $l['code'],
                    'native'     => $l['native'],
                    'label'      => $l['label'],
                    'reviewer'   => $l['reviewer'],
                    'translated' => $text !== null && $text !== '',
                    'text'       => $text ?? $str,
                ];
            }, I18nLib::targets()),
        ]);
    }

    // ---- Raise a label ----

    /** The queue of wording raised against the catalogue, newest first. */
    public function requests()
    {
        return $this->json([
            'rows'    => $this->requestRows(),
            'reasons' => self::REPORT_REASONS,
            'note'    => 'Raised wording sits here until the reviewer of record decides. Approving publishes it immediately and writes to the audit log — a translation change alters what a funder reads.',
        ]);
    }

    private function requestRows(): array
    {
        $rows = array_map(static function ($q) {
            $l = I18nLib::find($q['locale']);

            return $q + [
                'lang' => $l['native'] ?? $q['locale'],
                'reviewer' => $l['reviewer'] ?? '',
                'open' => in_array($q['status'], self::OPEN_STATUSES, true),
            ];
        }, (new TranslationRepository())->requests());

        return $rows;
    }

    /**
     * Raises wording with the reviewer of record. The label itself does not change
     * — nothing a user reads moves until the reviewer approves it.
     */
    public function raise()
    {
        $body   = $this->request->getJSON(true) ?? [];
        $string = trim((string) ($body['str'] ?? ''));
        $target = (string) ($body['locale'] ?? '');
        $mode   = (string) ($body['mode'] ?? 'suggest');
        if (!in_array($mode, ['suggest', 'report', 'unlock'], true)) {
            $mode = 'suggest';
        }

        if ($string === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Name the string being raised.']);
        }
        if (!I18nLib::isTranslatable($string) && I18nLib::lockOn($string) === null) {
            return $this->response->setStatusCode(422)->setJSON(['error' => '"' . $string . '" is not a string in the translation catalogue.']);
        }
        if (!I18nLib::isKnown($target) || $target === I18nLib::SOURCE_LOCALE) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Raise wording against a translated language, not the source.']);
        }

        $lock = I18nLib::lockOn($string);
        if ($lock !== null && $mode !== 'unlock') {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => '"' . $string . '" is locked terminology. ' . $lock['reason'] . ' An unlock is a named request to ' . $lock['unlock'] . '.',
            ]);
        }

        $reasons = array_values(array_intersect(self::REPORT_REASONS, (array) ($body['reasons'] ?? [])));
        $text    = trim((string) ($body['text'] ?? ''));

        if ($mode === 'suggest' && $text === '') {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'A suggestion needs the wording your team actually uses.']);
        }
        if ($mode === 'report' && $reasons === []) {
            return $this->response->setStatusCode(422)->setJSON(['error' => 'Say what is wrong with the wording before reporting it.']);
        }

        try {
            $request = (new TranslationRepository())->raise(
                $string,
                $target,
                $mode === 'unlock' ? 'Unlock request' : ($mode === 'suggest' ? 'Suggestion' : 'Report'),
                $mode === 'report' ? implode(' · ', $reasons) : $text,
                trim((string) ($body['who'] ?? '')),
                $this->actorId()
            );
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->response->setStatusCode(201)->setJSON([
            'request' => $request,
            'note'    => $lock !== null
                ? 'The approved wording stays in place. The unlock request is with ' . $lock['unlock'] . ', and any change is versioned with the financial statements.'
                : 'Goes to ' . (I18nLib::find($target)['reviewer'] ?? 'the reviewer') . ', reviewer of record for '
                    . (I18nLib::find($target)['native'] ?? $target) . '. The label is unchanged until they approve.',
        ]);
    }

    public function approve(string $id)
    {
        return $this->decide($id, true);
    }

    public function decline(string $id)
    {
        return $this->decide($id, false);
    }

    /** Only an open item can be decided, and a decision is final in one direction. The acting user decides. */
    private function decide(string $id, bool $approved)
    {
        $repo = new TranslationRepository();
        if ($repo->findRequest($id) === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => $id . ' was not found in the translation queue.']);
        }

        try {
            $request = $repo->decide($id, $approved, $this->actorId());
        } catch (RuleViolation $e) {
            return $this->refused($e);
        }

        return $this->json([
            'request' => $request,
            'note'    => $approved
                ? 'Published and written to the audit log.'
                : 'Declined. The approved wording stays in place.',
        ]);
    }
}
