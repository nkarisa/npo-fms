<?php

namespace App\Libraries;

use App\Repositories\TranslationRepository;
use NumberFormatter;
use IntlDateFormatter;

/**
 * Interface localisation, as the v5 prototype specifies it.
 *
 * The rule the whole design turns on: language changes what people *read*, never
 * what the ledger *holds*. Account codes, references, amounts and dates stay in
 * the organisation's reporting locale (en-KE · KES) so a report still reconciles
 * line-for-line whichever language it is read in. Everything here therefore
 * touches display strings only — never a figure, a code or a date.
 *
 * A string is translatable only if it is in the language catalogue (translation_strings).
 * Anything else reaching an endpoint is data — a supplier name, an account
 * description — and is passed through untouched rather than marked or guessed at.
 */
class I18n
{
    public const SOURCE_LOCALE = 'en-GB';

    /** The locale amounts, dates and percentages are always rendered in. */
    public const REPORTING_LOCALE = 'en-KE';

    public const REPORTING_CURRENCY = 'KES';

    /** How an untranslated string is presented. Mirrors the prototype's lgFallbacks. */
    public const FALLBACKS = [
        [
            'key'   => 'silent',
            'label' => 'Show the English (UK) source silently',
            'note'  => 'Cleanest to read, but a user cannot tell which wording has been reviewed in their language.',
        ],
        [
            'key'   => 'mark',
            'label' => 'Show the English source, marked as untranslated',
            'note'  => 'Recommended. The reader knows the wording is not yet approved in their language and can raise it with the reviewer.',
        ],
        [
            'key'   => 'key',
            'label' => 'Show the string key',
            'note'  => 'For translators working through the catalogue. Not for live users.',
        ],
    ];

    public const DEFAULT_FALLBACK = 'mark';

    /** The setting on the head office that holds the fallback the organisation reads in. */
    public const FALLBACK_KEY = 'i18nFallback';

    public const FALLBACK_KIND = 'language';

    public const FALLBACK_LABEL = 'What a reader sees when a string has no approved translation';

    public const FALLBACK_NOTE = 'Recommended: the English source, marked as untranslated. The reader knows the wording is not yet approved in their language and can raise it with the reviewer.';

    /** Appended to an untranslated string under the "mark" fallback. */
    public const UNTRANSLATED_MARK = 'EN';

    /** Response keys that carry UI chrome rather than ledger data. */
    private const DISPLAY_KEYS = ['label', 'heading', 'title', 'note', 'hint', 'detail', 'cta'];

    protected string $locale;

    protected string $fallback;

    public function __construct(?string $locale = null, ?string $fallback = null)
    {
        $this->locale   = self::isKnown($locale) ? $locale : self::SOURCE_LOCALE;
        $this->fallback = self::isFallback($fallback) ? $fallback : self::DEFAULT_FALLBACK;
    }

    /**
     * The language a request is read in, the same for the page shell and the API.
     *
     * An explicit ?locale= wins, then the X-Locale header, then the language chosen
     * in the top bar (the elog_locale cookie), then the browser's Accept-Language.
     * Browser languages are taken in the order listed, and any English variant
     * ("en-US", "en") is the English source — so an English browser that also
     * lists French reads English. Anything unrecognised falls back to the source
     * rather than erroring: a bad locale should never cost someone their ledger.
     *
     * The language is the reader's; how an untranslated string is presented is not
     * — that is the organisation's setting, the same for everybody (see fallback()).
     */
    public static function forRequest(\CodeIgniter\HTTP\RequestInterface $request): self
    {
        $get = $request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request->getGet('locale') : null;
        $cookie = $request instanceof \CodeIgniter\HTTP\IncomingRequest ? $request->getCookie('elog_locale') : null;

        foreach ([$get, $request->getHeaderLine('X-Locale') ?: null, $cookie] as $candidate) {
            if (is_string($candidate) && self::isKnown($candidate)) {
                return new self($candidate, self::fallback());
            }
        }

        return new self(self::negotiate($request->getHeaderLine('Accept-Language')) ?? self::SOURCE_LOCALE, self::fallback());
    }

    /** The first browser language we publish, in the order the browser lists them. */
    public static function negotiate(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $base = strtolower(explode('-', $tag)[0]);
            // English in any variant is the source language.
            if ($base === strtolower(explode('-', self::SOURCE_LOCALE)[0])) {
                return self::SOURCE_LOCALE;
            }
            if (self::isKnown($tag)) {
                return $tag;
            }
            // "fr-CH" and "ar-EG" land on the language we publish.
            if (self::isKnown($base)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * How untranslated wording is presented, as the organisation has set it in
     * Settings → Language and translation.
     *
     * It is one setting for everybody rather than a preference each browser keeps:
     * whether a reader can tell that a label has not yet been approved in their
     * language is a decision about the organisation's wording, so it is made once,
     * by whoever may change the settings, and recorded in the audit log like any
     * other. An instance that has not been installed reads the default.
     */
    public static function fallback(): string
    {
        return (new \App\Repositories\SettingsRepository())->fallbackMode();
    }

    public static function isFallback(?string $mode): bool
    {
        return $mode !== null && in_array($mode, array_column(self::FALLBACKS, 'key'), true);
    }

    // ---- Locales ----

    public static function locales(): array
    {
        return (new TranslationRepository())->locales();
    }

    public static function isKnown(?string $code): bool
    {
        return $code !== null && self::find($code) !== null;
    }

    public static function find(string $code): ?array
    {
        foreach (self::locales() as $l) {
            if ($l['code'] === $code) {
                return $l;
            }
        }

        return null;
    }

    /** Non-source locales — the ones that actually have a reviewer of record. */
    public static function targets(): array
    {
        return array_values(array_filter(self::locales(), static fn ($l) => empty($l['source'])));
    }

    public function code(): string
    {
        return $this->locale;
    }

    public function locale(): array
    {
        return self::find($this->locale) ?? self::locales()[0];
    }

    public function dir(): string
    {
        return $this->locale()['dir'] ?? 'ltr';
    }

    public function isSource(): bool
    {
        return $this->locale === self::SOURCE_LOCALE;
    }

    public function fallbackMode(): string
    {
        return $this->fallback;
    }

    // ---- Catalogue ----

    /** Every string the UI treats as translatable. */
    public static function catalogue(): array
    {
        return (new TranslationRepository())->catalogue();
    }

    public static function isTranslatable(string $s): bool
    {
        return in_array($s, self::catalogue(), true);
    }

    public static function strings(string $code): array
    {
        return (new TranslationRepository())->strings($code);
    }

    /** Has this locale an approved translation for the string? */
    public static function hasIn(string $code, string $s): bool
    {
        if ($code === self::SOURCE_LOCALE) {
            return true;
        }

        return isset(self::strings($code)[$s]) && self::strings($code)[$s] !== '';
    }

    /**
     * Translate a UI string into the active locale.
     *
     * Falls back per the configured mode when the locale has no approved wording,
     * and returns anything outside the catalogue unchanged — that is ledger data,
     * not a label.
     */
    public function t(string $s): string
    {
        if ($this->isSource() || $s === '' || !self::isTranslatable($s)) {
            return $s;
        }

        $translated = self::strings($this->locale)[$s] ?? null;
        if ($translated !== null && $translated !== '') {
            return $translated;
        }

        return match ($this->fallback) {
            'key'   => '[' . $s . ']',
            'mark'  => $s . ' ' . self::UNTRANSLATED_MARK,
            default => $s,
        };
    }

    /**
     * Walks a response and translates the display strings in it, leaving figures,
     * codes, references and names alone. Keys not in DISPLAY_KEYS are still
     * descended into, so nested rows and sections are reached, but only the
     * display keys themselves are ever rewritten.
     */
    public function translateResponse(array $data): array
    {
        if ($this->isSource()) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->translateResponse($value);
                continue;
            }
            if (is_string($value) && in_array((string) $key, self::DISPLAY_KEYS, true)) {
                $data[$key] = $this->t($value);
            }
        }

        return $data;
    }

    // ---- Coverage ----

    public function coverage(): int
    {
        return (int) ($this->locale()['coverage'] ?? 100);
    }

    public static function coverageAreas(string $code): array
    {
        $areas = (new TranslationRepository())->areas();
        if (isset($areas[$code])) {
            return $areas[$code];
        }

        // The source language is complete by definition.
        return array_map(
            static fn ($a) => ['area' => $a['area'], 'coverage' => 100, 'note' => 'Source language'],
            $areas['fr'] ?? []
        );
    }

    /** Catalogue strings this locale has not had approved yet. */
    public static function missing(string $code): array
    {
        if ($code === self::SOURCE_LOCALE) {
            return [];
        }

        return array_values(array_filter(self::catalogue(), static fn ($s) => !self::hasIn($code, $s)));
    }

    // ---- Locked terminology ----

    /**
     * Regulated terms carry one approved translation each. A translator cannot
     * edit these in the catalogue — changing one is a named unlock request,
     * versioned with the financial statements.
     */
    public static function lockedTerms(): array
    {
        return (new TranslationRepository())->locked();
    }

    public static function lockOn(string $s): ?array
    {
        foreach (self::lockedTerms() as $t) {
            if ($t['term'] === $s) {
                return $t;
            }
        }

        return null;
    }

    // ---- Number, date and currency formats ----

    public static function formats(string $code): array
    {
        $locale = self::isKnown($code) ? $code : self::SOURCE_LOCALE;

        $amount = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $amount->setAttribute(NumberFormatter::FRACTION_DIGITS, 2);
        $pct = new NumberFormatter($locale, NumberFormatter::PERCENT);
        $pct->setAttribute(NumberFormatter::FRACTION_DIGITS, 1);
        $date = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Africa/Nairobi', null, 'd MMM y');

        return [
            'amount' => $amount->format(12480000),
            'date'   => $date->format(strtotime('2026-09-30 12:00')),
            'pct'    => $pct->format(0.084),
        ];
    }

    /**
     * Whether figures are held in the reporting locale regardless of interface
     * language. Recommended on: the same posted amount rendering in two notations
     * is a reconciliation risk on any figure a funder queries.
     */
    public static function formatsLocked(): bool
    {
        return (new \App\Repositories\SettingsRepository())->formatsLocked();
    }
}
