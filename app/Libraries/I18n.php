<?php

namespace App\Libraries;

/**
 * Interface localisation, as the v5 prototype specifies it.
 *
 * The rule the whole design turns on: language changes what people *read*, never
 * what the ledger *holds*. Account codes, references, amounts and dates stay in
 * the organisation's reporting locale (en-KE · KES) so a report still reconciles
 * line-for-line whichever language it is read in. Everything here therefore
 * touches display strings only — never a figure, a code or a date.
 *
 * A string is translatable only if it is in the source catalogue (I18N_SOURCE).
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

    /** Appended to an untranslated string under the "mark" fallback. */
    public const UNTRANSLATED_MARK = 'EN';

    /** Response keys that carry UI chrome rather than ledger data. */
    private const DISPLAY_KEYS = ['label', 'heading', 'title', 'note', 'hint', 'detail', 'cta'];

    protected string $locale;

    protected string $fallback;

    public function __construct(?string $locale = null, ?string $fallback = null)
    {
        $this->locale   = self::isKnown($locale) ? $locale : self::SOURCE_LOCALE;
        $this->fallback = in_array($fallback, array_column(self::FALLBACKS, 'key'), true) ? $fallback : self::DEFAULT_FALLBACK;
    }

    // ---- Locales ----

    public static function locales(): array
    {
        return Prototype::load('LOCALES');
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
        return Prototype::load('I18N_SOURCE');
    }

    public static function isTranslatable(string $s): bool
    {
        return in_array($s, self::catalogue(), true);
    }

    public static function strings(string $code): array
    {
        return Prototype::load('I18N')[$code] ?? [];
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
        $areas = Prototype::load('I18N_AREAS');
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
        return Prototype::load('I18N_LOCKED');
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
        $all = Prototype::load('I18N_FORMATS');

        return $all[$code] ?? $all[self::SOURCE_LOCALE];
    }

    /**
     * Whether figures are held in the reporting locale regardless of interface
     * language. Recommended on: the same posted amount rendering in two notations
     * is a reconciliation risk on any figure a funder queries.
     */
    public static function formatsLocked(): bool
    {
        return true;
    }
}
